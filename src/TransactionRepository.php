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
