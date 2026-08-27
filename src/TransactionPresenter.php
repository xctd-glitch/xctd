<?php

declare(strict_types=1);

namespace App;

final class TransactionPresenter
{
    /** @param array<string, int|string|null> $row @return array{id:int,sender_name:string,team:string,subid:string,alias:string,adjusted_amount:string,receipt_date:string} */
    public static function present(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        $senderName = self::limitedString($row['sender_name'] ?? null, 100);
        $alias = self::limitedString($row['sender_alias'] ?? null, 100);
        $team = self::limitedString($row['team'] ?? null, 8);
        if (!in_array($team, ['XCTD', 'MNX'], true)) {
            $team = '';
        }

        $adjustedAmount = self::limitedString($row['adjusted_amount'] ?? null, 24);
        $receiptDate = self::limitedString(($row['receipt_date'] ?? null) ?: ($row['created_at'] ?? null), 32);

        return [
            'id' => max(0, $id),
            'sender_name' => $senderName,
            'team' => $team,
            'subid' => $alias,
            'alias' => $alias,
            'adjusted_amount' => MoneyFormatter::formatIdr($adjustedAmount),
            'receipt_date' => $receiptDate,
        ];
    }

    private static function limitedString(mixed $value, int $maxLength): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return substr($value, 0, $maxLength);
    }
}
