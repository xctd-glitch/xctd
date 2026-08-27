<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

final class TransactionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function save(
        ReceiptData $receipt,
        int $teamMemberId,
        string $senderAlias,
        string $team,
        string $adjustedAmount,
        string $imageHash
    ): int {
        if ($teamMemberId <= 0) {
            throw new \InvalidArgumentException('Invalid sender record.');
        }
        $senderAlias = trim($senderAlias);
        if ($senderAlias === '') {
            throw new \InvalidArgumentException('Sender SUBID is required.');
        }

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO payment_transactions (
                    image_sha256,
                    reference_no,
                    receipt_date,
                    receipt_time,
                    sender_name,
                    sender_alias,
                    team_member_id,
                    source_account_last4,
                    team,
                    original_amount,
                    adjusted_amount
                ) VALUES (
                    :image_sha256,
                    :reference_no,
                    :receipt_date,
                    :receipt_time,
                    :sender_name,
                    :sender_alias,
                    :team_member_id,
                    :source_account_last4,
                    :team,
                    :original_amount,
                    :adjusted_amount
                )'
            );

            $statement->execute([
                'image_sha256' => $imageHash,
                'reference_no' => $receipt->referenceNo,
                'receipt_date' => $receipt->receiptDate,
                'receipt_time' => $receipt->receiptTime,
                'sender_name' => $receipt->senderName,
                'sender_alias' => $senderAlias,
                'team_member_id' => $teamMemberId,
                'source_account_last4' => $receipt->sourceAccountLast4,
                'team' => $team,
                'original_amount' => $receipt->originalAmount,
                'adjusted_amount' => $adjustedAmount,
            ]);

            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();

            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /** @return array<string, int|string|null>|null */
    public function findByImageSha256(string $imageHash): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $imageHash) !== 1) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT pt.id, pt.reference_no, pt.receipt_date, pt.receipt_time, pt.sender_name,
                    COALESCE(tm.alias, pt.sender_alias) AS sender_alias, pt.team_member_id,
                    pt.source_account_last4, pt.team, pt.original_amount, pt.adjusted_amount, pt.created_at
             FROM payment_transactions pt
             LEFT JOIN team_members tm ON tm.id = pt.team_member_id
             WHERE pt.image_sha256 = :image_sha256
             LIMIT 1'
        );
        $statement->execute(['image_sha256' => $imageHash]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Existing transaction holding this reference number for this sender.
     *
     * Used to tell the two duplicate-key causes apart after an insert fails.
     * uq_payment_transactions_member_reference is scoped per sender, so the
     * lookup has to be scoped the same way - a bare reference_no search would
     * report a collision that the constraint does not actually forbid.
     *
     * @return array<string, int|string|null>|null
     */
    public function findByMemberReference(int $teamMemberId, ?string $referenceNo): ?array
    {
        if ($teamMemberId <= 0 || $referenceNo === null || $referenceNo === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT pt.id, pt.reference_no, pt.receipt_date, pt.receipt_time, pt.sender_name,
                    COALESCE(tm.alias, pt.sender_alias) AS sender_alias, pt.team_member_id,
                    pt.source_account_last4, pt.team, pt.original_amount, pt.adjusted_amount, pt.created_at
             FROM payment_transactions pt
             LEFT JOIN team_members tm ON tm.id = pt.team_member_id
             WHERE pt.team_member_id = :team_member_id AND pt.reference_no = :reference_no
             LIMIT 1'
        );
        $statement->bindValue(':team_member_id', $teamMemberId, PDO::PARAM_INT);
        $statement->bindValue(':reference_no', $referenceNo);
        $statement->execute();
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * A transaction that already settled a weekly obligation is referenced by
     * weekly_payment_obligations.payment_transaction_id (FK ON DELETE RESTRICT, so the
     * delete below fails outright otherwise). Releasing that link and dropping the
     * obligation back to 'pending' first lets the next WeeklyObligationService::sync()
     * either reallocate another unallocated transaction to it (FIFO, unchanged logic)
     * or age it back to 'unpaid' if the week has already closed - both existing paths.
     */
    public function delete(int $id): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid transaction record.');
        }

        $this->pdo->beginTransaction();

        try {
            $release = $this->pdo->prepare(
                "UPDATE weekly_payment_obligations
                 SET status = 'pending', payment_transaction_id = NULL, paid_at = NULL
                 WHERE payment_transaction_id = :id"
            );
            $release->bindValue(':id', $id, PDO::PARAM_INT);
            $release->execute();

            $delete = $this->pdo->prepare('DELETE FROM payment_transactions WHERE id = :id');
            $delete->bindValue(':id', $id, PDO::PARAM_INT);
            $delete->execute();
            if ($delete->rowCount() !== 1) {
                throw new \RuntimeException('Transaction record was not found.');
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public static function isDuplicateKeyException(Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }

        return isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062;
    }

    /** @return array<string, int|string|null>|null */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT pt.id, pt.reference_no, pt.receipt_date, pt.receipt_time, pt.sender_name,
                    COALESCE(tm.alias, pt.sender_alias) AS sender_alias, pt.team_member_id,
                    pt.source_account_last4, pt.team, pt.original_amount, pt.adjusted_amount, pt.created_at
             FROM payment_transactions pt
             LEFT JOIN team_members tm ON tm.id = pt.team_member_id
             WHERE pt.id = :id
             LIMIT 1'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, int|string|null>> */
    public function findAfterId(int $afterId, int $limit = 100): array
    {
        $afterId = max(0, $afterId);
        $limit = max(1, min($limit, 200));
        $statement = $this->pdo->prepare(
            'SELECT pt.id, pt.reference_no, pt.receipt_date, pt.receipt_time, pt.sender_name,
                    COALESCE(tm.alias, pt.sender_alias) AS sender_alias, pt.team_member_id,
                    pt.source_account_last4, pt.team, pt.original_amount, pt.adjusted_amount, pt.created_at
             FROM payment_transactions pt
             LEFT JOIN team_members tm ON tm.id = pt.team_member_id
             WHERE pt.id > :after_id
             ORDER BY pt.id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @return list<array<string, int|string|null>> */
    public function findRecent(int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));
        $statement = $this->pdo->prepare(
            'SELECT pt.id, pt.reference_no, pt.receipt_date, pt.receipt_time, pt.sender_name,
                    COALESCE(tm.alias, pt.sender_alias) AS sender_alias, pt.team_member_id,
                    pt.source_account_last4, pt.team, pt.original_amount, pt.adjusted_amount, pt.created_at
             FROM payment_transactions pt
             LEFT JOIN team_members tm ON tm.id = pt.team_member_id
             ORDER BY pt.id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }
}
