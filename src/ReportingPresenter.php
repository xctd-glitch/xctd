<?php

declare(strict_types=1);

namespace App;

final class ReportingPresenter
{
    /** @param array<string,mixed> $report @return array<string,mixed> */
    public static function present(array $report): array
    {
        $currentMonth = is_array($report['current_month'] ?? null) ? $report['current_month'] : [];
        $teams = is_array($currentMonth['teams'] ?? null) ? $currentMonth['teams'] : [];
        $history = [];
        foreach (is_array($report['weekly_history'] ?? null) ? $report['weekly_history'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $history[] = [
                'key' => (string) ($row['key'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'total_raw' => (string) ($row['total'] ?? '0'),
                'XCTD_raw' => (string) ($row['XCTD'] ?? '0'),
                'MNX_raw' => (string) ($row['MNX'] ?? '0'),
                'total' => MoneyFormatter::formatIdr((string) ($row['total'] ?? '0')),
                'XCTD' => MoneyFormatter::formatIdr((string) ($row['XCTD'] ?? '0')),
                'MNX' => MoneyFormatter::formatIdr((string) ($row['MNX'] ?? '0')),
                'count' => max(0, (int) ($row['count'] ?? 0)),
            ];
        }

        $changes = [];
        foreach (['week', 'month', 'year'] as $period) {
            $item = is_array($report['changes'][$period] ?? null) ? $report['changes'][$period] : [];
            $changes[$period] = [
                'label' => (string) ($item['label'] ?? ucfirst($period)),
                'current' => MoneyFormatter::formatIdr((string) ($item['current'] ?? '0')),
                'previous' => MoneyFormatter::formatIdr((string) ($item['previous'] ?? '0')),
                'direction' => in_array(($item['direction'] ?? ''), ['up', 'down', 'flat'], true) ? $item['direction'] : 'flat',
                'percent' => is_numeric($item['percent'] ?? null) ? (float) $item['percent'] : null,
            ];
        }

        return [
            'generated_at' => (string) ($report['generated_at'] ?? ''),
            'current_month' => [
                'label' => (string) ($currentMonth['label'] ?? ''),
                'total' => MoneyFormatter::formatIdr((string) ($currentMonth['total'] ?? '0')),
                'count' => max(0, (int) ($currentMonth['count'] ?? 0)),
                'teams' => [
                    'XCTD' => [
                        'total_raw' => (string) (($teams['XCTD']['total'] ?? '0')),
                        'total' => MoneyFormatter::formatIdr((string) (($teams['XCTD']['total'] ?? '0'))),
                        'count' => max(0, (int) ($teams['XCTD']['count'] ?? 0)),
                    ],
                    'MNX' => [
                        'total_raw' => (string) (($teams['MNX']['total'] ?? '0')),
                        'total' => MoneyFormatter::formatIdr((string) (($teams['MNX']['total'] ?? '0'))),
                        'count' => max(0, (int) ($teams['MNX']['count'] ?? 0)),
                    ],
                ],
            ],
            'weekly_history' => $history,
            'changes' => $changes,
        ];
    }
}
