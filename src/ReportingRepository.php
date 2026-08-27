<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class ReportingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function report(?DateTimeImmutable $now = null, string $timezone = 'Asia/Jakarta'): array
    {
        $zone = new DateTimeZone($timezone);
        $now = $now?->setTimezone($zone) ?? new DateTimeImmutable('now', $zone);

        $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
        $monthEnd = $monthStart->modify('+1 month');
        $weekStart = $now->modify('monday this week')->setTime(0, 0, 0);
        $weekEnd = $weekStart->modify('+7 days');
        $yearStart = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0, 0);
        $yearEnd = $yearStart->modify('+1 year');

        return [
            'generated_at' => $now->format(DATE_ATOM),
            'current_month' => $this->teamPeriod($monthStart, $monthEnd, $now->format('F Y')),
            'weekly_history' => $this->weeklyHistory($weekStart, 12),
            'changes' => [
                'week' => $this->periodChange($weekStart, $weekEnd, $weekStart->modify('-7 days'), $weekStart, 'Week'),
                'month' => $this->periodChange($monthStart, $monthEnd, $monthStart->modify('-1 month'), $monthStart, 'Month'),
                'year' => $this->periodChange($yearStart, $yearEnd, $yearStart->modify('-1 year'), $yearStart, 'Year'),
            ],
        ];
    }

    /** @return array{label:string,total:string,count:int,teams:array<string,array{total:string,count:int}>} */
    private function teamPeriod(DateTimeImmutable $start, DateTimeImmutable $end, string $label): array
    {
        $statement = $this->pdo->prepare(
            'SELECT team, COUNT(*) AS row_count, COALESCE(SUM(ROUND(adjusted_amount, 0)), 0) AS total
             FROM payment_transactions
             WHERE COALESCE(receipt_date, DATE(created_at)) >= :start_date
               AND COALESCE(receipt_date, DATE(created_at)) < :end_date
             GROUP BY team'
        );
        $statement->execute([
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ]);

        $teams = [
            'XCTD' => ['total' => '0', 'count' => 0],
            'MNX' => ['total' => '0', 'count' => 0],
        ];
        $total = '0';
        $count = 0;
        $rows = $statement->fetchAll();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $team = (string) ($row['team'] ?? '');
                if (!array_key_exists($team, $teams)) {
                    continue;
                }
                $amount = self::normalizeInteger((string) ($row['total'] ?? '0'));
                $rowCount = max(0, (int) ($row['row_count'] ?? 0));
                $teams[$team] = ['total' => $amount, 'count' => $rowCount];
                $total = self::addIntegers($total, $amount);
                $count += $rowCount;
            }
        }

        return ['label' => $label, 'total' => $total, 'count' => $count, 'teams' => $teams];
    }

    /** @return list<array{key:string,label:string,total:string,XCTD:string,MNX:string,count:int}> */
    private function weeklyHistory(DateTimeImmutable $currentWeekStart, int $weeks): array
    {
        $weeks = max(1, min($weeks, 26));
        $start = $currentWeekStart->modify('-' . ($weeks - 1) . ' weeks');
        $end = $currentWeekStart->modify('+1 week');
        $statement = $this->pdo->prepare(
            "SELECT DATE_ADD('1970-01-05', INTERVAL FLOOR(DATEDIFF(DATE(COALESCE(receipt_date, DATE(created_at))), '1970-01-05') / 7) * 7 DAY) AS week_start,
                    team,
                    COUNT(*) AS row_count,
                    COALESCE(SUM(ROUND(adjusted_amount, 0)), 0) AS total
             FROM payment_transactions
             WHERE COALESCE(receipt_date, DATE(created_at)) >= :start_date
               AND COALESCE(receipt_date, DATE(created_at)) < :end_date
             GROUP BY week_start, team
             ORDER BY week_start ASC"
        );
        $statement->execute([
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ]);

        $mapped = [];
        $rows = $statement->fetchAll();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = (string) ($row['week_start'] ?? '');
                $team = (string) ($row['team'] ?? '');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $key) !== 1 || !in_array($team, ['XCTD', 'MNX'], true)) {
                    continue;
                }
                $mapped[$key][$team] = self::normalizeInteger((string) ($row['total'] ?? '0'));
                $mapped[$key]['count'] = (int) ($mapped[$key]['count'] ?? 0) + max(0, (int) ($row['row_count'] ?? 0));
            }
        }

        $result = [];
        for ($index = 0; $index < $weeks; $index++) {
            $week = $start->modify('+' . $index . ' weeks');
            $key = $week->format('Y-m-d');
            $weekEndDay = $week->modify('+6 days');
            $xctd = (string) ($mapped[$key]['XCTD'] ?? '0');
            $mnx = (string) ($mapped[$key]['MNX'] ?? '0');
            $result[] = [
                'key' => $key,
                'label' => $week->format('M j') . '–' . $weekEndDay->format('M j'),
                'total' => self::addIntegers($xctd, $mnx),
                'XCTD' => $xctd,
                'MNX' => $mnx,
                'count' => (int) ($mapped[$key]['count'] ?? 0),
            ];
        }

        return $result;
    }

    /** @return array{label:string,current:string,previous:string,direction:string,percent:float|null} */
    private function periodChange(
        DateTimeImmutable $currentStart,
        DateTimeImmutable $currentEnd,
        DateTimeImmutable $previousStart,
        DateTimeImmutable $previousEnd,
        string $label
    ): array {
        $current = $this->periodTotal($currentStart, $currentEnd);
        $previous = $this->periodTotal($previousStart, $previousEnd);
        $currentFloat = (float) $current;
        $previousFloat = (float) $previous;
        $direction = 'flat';
        if (self::compareIntegers($current, $previous) > 0) {
            $direction = 'up';
        } elseif (self::compareIntegers($current, $previous) < 0) {
            $direction = 'down';
        }

        $percent = null;
        if ($previousFloat > 0.0) {
            $percent = round((($currentFloat - $previousFloat) / $previousFloat) * 100, 1);
        } elseif ($currentFloat > 0.0) {
            $percent = 100.0;
        } else {
            $percent = 0.0;
        }

        return [
            'label' => $label,
            'current' => $current,
            'previous' => $previous,
            'direction' => $direction,
            'percent' => $percent,
        ];
    }

    private function periodTotal(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(ROUND(adjusted_amount, 0)), 0) AS total
             FROM payment_transactions
             WHERE COALESCE(receipt_date, DATE(created_at)) >= :start_date
               AND COALESCE(receipt_date, DATE(created_at)) < :end_date'
        );
        $statement->execute([
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ]);
        $row = $statement->fetch();

        return self::normalizeInteger(is_array($row) ? (string) ($row['total'] ?? '0') : '0');
    }

    private static function normalizeInteger(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,30})(?:\.0+)?$/D', $value, $matches) !== 1) {
            return '0';
        }
        $digits = ltrim($matches[1], '0');
        return $digits === '' ? '0' : $digits;
    }

    private static function addIntegers(string $left, string $right): string
    {
        $left = self::normalizeInteger($left);
        $right = self::normalizeInteger($right);
        $length = max(strlen($left), strlen($right));
        $left = str_pad($left, $length, '0', STR_PAD_LEFT);
        $right = str_pad($right, $length, '0', STR_PAD_LEFT);
        $carry = 0;
        $result = '';
        for ($index = $length - 1; $index >= 0; $index--) {
            $sum = (int) $left[$index] + (int) $right[$index] + $carry;
            $result = (string) ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
        }
        if ($carry > 0) {
            $result = (string) $carry . $result;
        }
        $result = ltrim($result, '0');
        return $result === '' ? '0' : $result;
    }

    private static function compareIntegers(string $left, string $right): int
    {
        $left = self::normalizeInteger($left);
        $right = self::normalizeInteger($right);
        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }
        return strcmp($left, $right) <=> 0;
    }
}
