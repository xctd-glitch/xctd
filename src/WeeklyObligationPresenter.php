<?php

declare(strict_types=1);

namespace App;

final class WeeklyObligationPresenter
{
    /** @param array<string,mixed> $data @return array<string,mixed> */
    public static function present(array $data): array
    {
        $rows = [];
        $sourceRows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
        foreach ($sourceRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'sender_id' => max(0, (int) ($row['sender_id'] ?? 0)),
                'sender_name' => (string) ($row['sender_name'] ?? ''),
                'subid' => (string) ($row['alias'] ?? ''),
                'alias' => (string) ($row['alias'] ?? ''),
                'location' => (string) ($row['location'] ?? ''),
                'team' => (string) ($row['team'] ?? ''),
                'is_active' => (bool) ($row['is_active'] ?? false),
                'current_status' => (string) ($row['current_status'] ?? 'pending'),
                'outstanding_weeks' => max(0, (int) ($row['outstanding_weeks'] ?? 0)),
                'oldest_week' => isset($row['oldest_week']) && is_string($row['oldest_week']) ? $row['oldest_week'] : null,
            ];
        }

        return [
            'label' => (string) ($data['label'] ?? ''),
            'week_start' => (string) ($data['week_start'] ?? ''),
            'week_end' => (string) ($data['week_end'] ?? ''),
            'paid' => max(0, (int) ($data['paid'] ?? 0)),
            'pending' => max(0, (int) ($data['pending'] ?? 0)),
            'outstanding_senders' => max(0, (int) ($data['outstanding_senders'] ?? 0)),
            'outstanding_weeks' => max(0, (int) ($data['outstanding_weeks'] ?? 0)),
            'rows' => $rows,
        ];
    }
}
