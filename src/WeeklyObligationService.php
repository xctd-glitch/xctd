<?php

declare(strict_types=1);

namespace App;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class WeeklyObligationService
{
    private readonly string $timezone;

    /** Prepared once and re-executed: sync() touches this per sender per tracked week. */
    private ?PDOStatement $ensureObligationStatement = null;

    public function __construct(private readonly PDO $pdo, string $timezone = 'Asia/Jakarta')
    {
        try {
            new DateTimeZone($timezone);
            $this->timezone = $timezone;
        } catch (Throwable $e) {
            $this->timezone = 'Asia/Jakarta';
        }
    }

    public static function weekStartForDate(DateTimeImmutable $date): DateTimeImmutable
    {
        $day = (int) $date->format('N');

        return $date->modify('-' . (string) ($day - 1) . ' days')->setTime(0, 0, 0);
    }

    public static function weekEndForStart(DateTimeImmutable $weekStart): DateTimeImmutable
    {
        return $weekStart->add(new DateInterval('P6D'));
    }

    public function sync(?DateTimeImmutable $now = null): void
    {
        $zone = new DateTimeZone($this->timezone);
        $now = $now?->setTimezone($zone) ?? new DateTimeImmutable('now', $zone);
        $currentWeek = self::weekStartForDate($now);
        $currentWeekString = $currentWeek->format('Y-m-d');

        $members = $this->activeMembers();
        $coverage = $this->obligationCoverage($currentWeekString);

        foreach ($members as $member) {
            $startRaw = (string) ($member['tracking_start_week'] ?? '');
            $trackingStart = DateTimeImmutable::createFromFormat('!Y-m-d', $startRaw, $zone);
            if (!$trackingStart instanceof DateTimeImmutable) {
                throw new RuntimeException('Weekly tracking start is invalid for a registered sender.');
            }
            $trackingStart = self::weekStartForDate($trackingStart);
            if ($trackingStart > $currentWeek) {
                continue;
            }

            // Every tracked week used to be re-INSERTed on each sync, so the write
            // cost grew without bound as weeks accumulated - and sync() runs on the
            // dashboard, on sender-options, and on the polling endpoint. Resume from
            // the last materialised week instead, but only when the stored rows are
            // provably contiguous from tracking_start. A gap (rows pruned when a
            // sender is disabled, or removed by hand) fails that check and falls back
            // to the original full sweep, so the self-healing property is preserved.
            $week = $this->resumePoint($member, $trackingStart, $coverage, $zone);

            $iterations = 0;
            while ($week <= $currentWeek) {
                $this->ensureObligation($member, $week);
                $week = $week->add(new DateInterval('P7D'));
                $iterations++;
                if ($iterations > 520) {
                    throw new RuntimeException('Weekly tracking range is unexpectedly large.');
                }
            }
        }

        $age = $this->pdo->prepare(
            "UPDATE weekly_payment_obligations
             SET status = 'unpaid'
             WHERE status = 'pending' AND week_start < :current_week"
        );
        $age->execute(['current_week' => $currentWeekString]);

        // allocatePayments() returns immediately for a sender with no unallocated
        // transaction, but only after spending two queries to discover that. One
        // query up front narrows the loop to the senders that can actually change.
        $pending = $this->membersWithUnallocatedPayments();
        foreach ($members as $member) {
            if (!isset($pending[(int) ($member['id'] ?? 0)])) {
                continue;
            }
            $this->allocatePayments($member);
        }
    }

    /**
     * Materialised obligation span per active sender, counted only inside that
     * sender's own tracked range so the totals are comparable with the range the
     * caller is about to walk.
     *
     * @return array<int, array{total:int,last_week:?string}>
     */
    private function obligationCoverage(string $currentWeek): array
    {
        $statement = $this->pdo->prepare(
            'SELECT tm.id,
                    COUNT(w.id) AS total,
                    MAX(w.week_start) AS last_week
             FROM team_members tm
             LEFT JOIN weekly_payment_obligations w
               ON w.team_member_id = tm.id
              AND w.week_start >= tm.tracking_start_week
              AND w.week_start <= :current_week
             WHERE tm.is_active = 1
             GROUP BY tm.id'
        );
        $statement->execute(['current_week' => $currentWeek]);
        $rows = $statement->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $coverage = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $lastWeek = $row['last_week'] ?? null;
            $coverage[$id] = [
                'total' => (int) ($row['total'] ?? 0),
                'last_week' => is_string($lastWeek) && $lastWeek !== '' ? $lastWeek : null,
            ];
        }

        return $coverage;
    }

    /**
     * First week that still needs an obligation row. Falls back to $trackingStart
     * whenever contiguity cannot be proven, which reproduces the original sweep.
     *
     * @param array<string,mixed> $member
     * @param array<int, array{total:int,last_week:?string}> $coverage
     */
    private function resumePoint(
        array $member,
        DateTimeImmutable $trackingStart,
        array $coverage,
        DateTimeZone $zone
    ): DateTimeImmutable {
        $state = $coverage[(int) ($member['id'] ?? 0)] ?? null;
        if ($state === null || $state['last_week'] === null || $state['total'] <= 0) {
            return $trackingStart;
        }

        $lastWeek = DateTimeImmutable::createFromFormat('!Y-m-d', $state['last_week'], $zone);
        if (!$lastWeek instanceof DateTimeImmutable) {
            return $trackingStart;
        }

        $lastWeek = self::weekStartForDate($lastWeek);
        if ($lastWeek < $trackingStart) {
            return $trackingStart;
        }

        // Contiguous means the row count equals the number of weeks the span covers.
        $spanWeeks = intdiv((int) $trackingStart->diff($lastWeek)->days, 7) + 1;
        if ($state['total'] !== $spanWeeks) {
            return $trackingStart;
        }

        return $lastWeek->add(new DateInterval('P7D'));
    }

    /**
     * Sender ids owning at least one transaction not yet tied to an obligation.
     *
     * @return array<int, true>
     */
    private function membersWithUnallocatedPayments(): array
    {
        $statement = $this->pdo->query(
            'SELECT DISTINCT pt.team_member_id
             FROM payment_transactions pt
             LEFT JOIN weekly_payment_obligations used
               ON used.payment_transaction_id = pt.id
             WHERE pt.team_member_id IS NOT NULL AND used.id IS NULL'
        );
        $rows = $statement->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['team_member_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /** @return array<string,mixed> */
    public function dashboard(?DateTimeImmutable $now = null): array
    {
        $zone = new DateTimeZone($this->timezone);
        $now = $now?->setTimezone($zone) ?? new DateTimeImmutable('now', $zone);
        $weekStart = self::weekStartForDate($now);
        $weekEnd = self::weekEndForStart($weekStart);
        $weekStartString = $weekStart->format('Y-m-d');

        $statement = $this->pdo->prepare(
            "SELECT tm.id, tm.display_name, tm.alias, tm.location, tm.team, tm.is_active,
                    CASE WHEN tm.is_active = 0 THEN 'disabled' ELSE COALESCE(cur.status, 'pending') END AS current_status,
                    COALESCE(overdue.outstanding_weeks, 0) AS outstanding_weeks,
                    overdue.oldest_week
             FROM team_members tm
             LEFT JOIN weekly_payment_obligations cur
               ON cur.team_member_id = tm.id AND cur.week_start = :current_week
             LEFT JOIN (
                 SELECT team_member_id, COUNT(*) AS outstanding_weeks, MIN(week_start) AS oldest_week
                 FROM weekly_payment_obligations
                 WHERE status = 'unpaid'
                 GROUP BY team_member_id
             ) overdue ON overdue.team_member_id = tm.id
             WHERE tm.is_active = 1 OR COALESCE(overdue.outstanding_weeks, 0) > 0
             ORDER BY COALESCE(overdue.outstanding_weeks, 0) DESC, tm.team ASC, tm.display_name ASC"
        );
        $statement->execute(['current_week' => $weekStartString]);
        $rows = $statement->fetchAll();
        if (!is_array($rows)) {
            $rows = [];
        }

        $resultRows = [];
        $paid = 0;
        $pending = 0;
        $outstandingWeeks = 0;
        $outstandingSenders = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $currentStatus = (string) ($row['current_status'] ?? 'pending');
            $outstanding = max(0, (int) ($row['outstanding_weeks'] ?? 0));
            if ($currentStatus === 'paid') {
                $paid++;
            } elseif ((int) ($row['is_active'] ?? 0) === 1) {
                $pending++;
            }
            if ($outstanding > 0) {
                $outstandingSenders++;
                $outstandingWeeks += $outstanding;
            }
            $resultRows[] = [
                'sender_id' => (int) ($row['id'] ?? 0),
                'sender_name' => (string) ($row['display_name'] ?? ''),
                'alias' => (string) ($row['alias'] ?? ''),
                'location' => (string) ($row['location'] ?? ''),
                'team' => (string) ($row['team'] ?? ''),
                'is_active' => (int) ($row['is_active'] ?? 0) === 1,
                'current_status' => in_array($currentStatus, ['paid', 'pending', 'unpaid', 'disabled'], true) ? $currentStatus : 'pending',
                'outstanding_weeks' => $outstanding,
                'oldest_week' => isset($row['oldest_week']) ? (string) $row['oldest_week'] : null,
            ];
        }

        return [
            'week_start' => $weekStartString,
            'week_end' => $weekEnd->format('Y-m-d'),
            'label' => $weekStart->format('d M') . '–' . $weekEnd->format('d M Y'),
            'paid' => $paid,
            'pending' => $pending,
            'outstanding_senders' => $outstandingSenders,
            'outstanding_weeks' => $outstandingWeeks,
            'rows' => $resultRows,
        ];
    }

    public function canAcceptPayment(int $teamMemberId, ?DateTimeImmutable $now = null): bool
    {
        if ($teamMemberId <= 0) {
            return false;
        }

        $dashboard = $this->dashboard($now);
        $rows = is_array($dashboard['rows'] ?? null) ? $dashboard['rows'] : [];
        foreach ($rows as $row) {
            if (!is_array($row) || (int) ($row['sender_id'] ?? 0) !== $teamMemberId) {
                continue;
            }

            $status = (string) ($row['current_status'] ?? 'pending');
            $carry = max(0, (int) ($row['outstanding_weeks'] ?? 0));

            return $status !== 'paid' || $carry > 0;
        }

        return false;
    }

    /** @return array<int,array{current_status:string,outstanding_weeks:int}> */
    public function paymentEligibility(?DateTimeImmutable $now = null): array
    {
        $dashboard = $this->dashboard($now);
        $rows = is_array($dashboard['rows'] ?? null) ? $dashboard['rows'] : [];
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['sender_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $result[$id] = [
                'current_status' => (string) ($row['current_status'] ?? 'pending'),
                'outstanding_weeks' => max(0, (int) ($row['outstanding_weeks'] ?? 0)),
            ];
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function activeMembers(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, display_name, team, tracking_start_week
             FROM team_members
             WHERE is_active = 1
             ORDER BY id ASC'
        );
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @param array<string,mixed> $member */
    private function ensureObligation(array $member, DateTimeImmutable $weekStart): void
    {
        $statement = $this->ensureObligationStatement ??= $this->pdo->prepare(
            "INSERT IGNORE INTO weekly_payment_obligations (
                team_member_id, sender_name, team, week_start, week_end, status
             ) VALUES (
                :team_member_id, :sender_name, :team, :week_start, :week_end, 'pending'
             )"
        );
        $statement->execute([
            'team_member_id' => (int) ($member['id'] ?? 0),
            'sender_name' => (string) ($member['display_name'] ?? ''),
            'team' => (string) ($member['team'] ?? ''),
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => self::weekEndForStart($weekStart)->format('Y-m-d'),
        ]);
    }

    /** @param array<string,mixed> $member */
    private function allocatePayments(array $member): void
    {
        $memberId = (int) ($member['id'] ?? 0);
        $senderName = (string) ($member['display_name'] ?? '');
        if ($memberId <= 0 || $senderName === '') {
            return;
        }

        $obligationStatement = $this->pdo->prepare(
            "SELECT id, week_start
             FROM weekly_payment_obligations
             WHERE team_member_id = :team_member_id AND status IN ('unpaid', 'pending')
             ORDER BY week_start ASC, id ASC"
        );
        $obligationStatement->bindValue(':team_member_id', $memberId, PDO::PARAM_INT);
        $obligationStatement->execute();
        $obligations = $obligationStatement->fetchAll();
        if (!is_array($obligations) || $obligations === []) {
            return;
        }

        $transactionStatement = $this->pdo->prepare(
            "SELECT pt.id, COALESCE(pt.receipt_date, DATE(pt.created_at)) AS effective_date
             FROM payment_transactions pt
             LEFT JOIN weekly_payment_obligations used ON used.payment_transaction_id = pt.id
             WHERE pt.team_member_id = :team_member_id
               AND used.id IS NULL
             ORDER BY COALESCE(pt.receipt_date, DATE(pt.created_at)) ASC, pt.id ASC"
        );
        $transactionStatement->bindValue(':team_member_id', $memberId, PDO::PARAM_INT);
        $transactionStatement->execute();
        $transactions = $transactionStatement->fetchAll();
        if (!is_array($transactions) || $transactions === []) {
            return;
        }

        $available = [];
        foreach ($transactions as $transaction) {
            if (!is_array($transaction)) {
                continue;
            }
            $id = (int) ($transaction['id'] ?? 0);
            $date = (string) ($transaction['effective_date'] ?? '');
            if ($id > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1) {
                $available[] = ['id' => $id, 'date' => $date];
            }
        }

        foreach ($obligations as $obligation) {
            if (!is_array($obligation)) {
                continue;
            }
            $obligationId = (int) ($obligation['id'] ?? 0);
            $weekStart = (string) ($obligation['week_start'] ?? '');
            if ($obligationId <= 0 || $weekStart === '') {
                continue;
            }

            $selectedIndex = null;
            foreach ($available as $index => $transaction) {
                if ($transaction['date'] >= $weekStart) {
                    $selectedIndex = $index;
                    break;
                }
            }
            if ($selectedIndex === null) {
                continue;
            }

            $transaction = $available[$selectedIndex];
            $update = $this->pdo->prepare(
                "UPDATE IGNORE weekly_payment_obligations
                 SET status = 'paid', payment_transaction_id = :transaction_id, paid_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND status IN ('unpaid', 'pending') AND payment_transaction_id IS NULL"
            );
            $update->bindValue(':transaction_id', (int) $transaction['id'], PDO::PARAM_INT);
            $update->bindValue(':id', $obligationId, PDO::PARAM_INT);
            $update->execute();
            if ($update->rowCount() === 1) {
                array_splice($available, $selectedIndex, 1);
                continue;
            }

            // Another device may have allocated this transaction concurrently.
            $used = $this->pdo->prepare(
                'SELECT id FROM weekly_payment_obligations WHERE payment_transaction_id = :transaction_id LIMIT 1'
            );
            $used->bindValue(':transaction_id', (int) $transaction['id'], PDO::PARAM_INT);
            $used->execute();
            if (is_array($used->fetch())) {
                array_splice($available, $selectedIndex, 1);
            }
        }
    }
}
