<?php

declare(strict_types=1);

namespace App;

final class SummaryPresenter
{
    /** @param array<string,mixed> $summary @return array<string,mixed> */
    public static function present(array $summary): array
    {
        $result = ['generated_at' => (string) ($summary['generated_at'] ?? '')];
        foreach (['week', 'month', 'year', 'all'] as $period) {
            $source = is_array($summary[$period] ?? null) ? $summary[$period] : [];
            $teams = is_array($source['teams'] ?? null) ? $source['teams'] : [];
            $result[$period] = [
                'label' => (string) ($source['label'] ?? ''),
                'total' => MoneyFormatter::formatIdr((string) ($source['total'] ?? '0')),
                'count' => max(0, (int) ($source['count'] ?? 0)),
                'teams' => [
                    'XCTD' => MoneyFormatter::formatIdr((string) ($teams['XCTD'] ?? '0')),
                    'MNX' => MoneyFormatter::formatIdr((string) ($teams['MNX'] ?? '0')),
                ],
            ];
        }

        return $result;
    }
}
