<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class SummaryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function dashboard(?DateTimeImmutable $now = null, string $timezone = 'Asia/Jakarta'): array
    {
        $zone = new DateTimeZone($timezone);
        $now = $now?->setTimezone($zone) ?? new DateTimeImmutable('now', $zone);

        $weekStart = $now->modify('monday this week')->setTime(0, 0, 0);
        $weekEnd = $weekStart->modify('+7 days');
        $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
        $monthEnd = $monthStart->modify('+1 month');
        $yearStart = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0, 0);
        $yearEnd = $yearStart->modify('+1 year');

        return [
            'generated_at' => $now->format(DATE_ATOM),
            'week' => $this->period(
                $weekStart->format('Y-m-d'),
                $weekEnd->format('Y-m-d'),
                sprintf('Week %s · %s–%s', $now->format('W'), $weekStart->format('d M'), $weekEnd->modify('-1 day')->format('d M Y'))
            ),
            'month' => $this->period($monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d'), $now->format('F Y')),
            'year' => $this->period($yearStart->format('Y-m-d'), $yearEnd->format('Y-m-d'), $now->format('Y')),
            'all' => $this->allTime(),
        ];
    }

    /** @return array{label:string,total:string,count:int,teams:array<string,string>} */
    private function period(string $start, string $end, string $label): array
    {
        $statement = $this->pdo->prepare(
            'SELECT team, COUNT(*) AS row_count, COALESCE(SUM(ROUND(adjusted_amount, 0)), 0) AS total
             FROM payment_transactions
             WHERE COALESCE(receipt_date, DATE(created_at)) >= :start_date
               AND COALESCE(receipt_date, DATE(created_at)) < :end_date
             GROUP BY team'
        );
        $statement->execute(['start_date' => $start, 'end_date' => $end]);

        return $this->normalizeRows($statement->fetchAll(), $label);
    }

    /** @return array{label:string,total:string,count:int,teams:array<string,string>} */
    private function allTime(): array
    {
        $statement = $this->pdo->query(
            'SELECT team, COUNT(*) AS row_count, COALESCE(SUM(ROUND(adjusted_amount, 0)), 0) AS total
             FROM payment_transactions
             GROUP BY team'
        );

        return $this->normalizeRows($statement->fetchAll(), 'All time');
    }

    /** @param mixed $rows @return array{label:string,total:string,count:int,teams:array<string,string>} */
    private function normalizeRows(mixed $rows, string $label): array
    {
        $teams = ['XCTD' => '0', 'MNX' => '0'];
        $total = '0';
        $count = 0;

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
                $teams[$team] = $amount;
                $total = self::addIntegers($total, $amount);
                $count += max(0, (int) ($row['row_count'] ?? 0));
            }
        }

        return [
            'label' => $label,
            'total' => $total,
            'count' => $count,
            'teams' => $teams,
        ];
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
}
