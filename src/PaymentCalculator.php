<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

final class PaymentCalculator
{
    /**
     * Return normalized whole-rupiah final amount as a decimal-compatible string.
     * The database column remains DECIMAL for backward compatibility, but new values are integral.
     */
    public function adjustedAmount(int $originalAmount, string $team): string
    {
        if ($originalAmount <= 0) {
            throw new InvalidArgumentException('Invalid original amount.');
        }

        if ($team === 'XCTD') {
            $whole = intdiv($originalAmount, 3);
            $remainder = $originalAmount % 3;
            // Half-up rounding. For division by 3 only remainder 2 rounds upward.
            if ($remainder >= 2) {
                $whole++;
            }

            return (string) $whole;
        }

        if ($team === 'MNX') {
            return (string) $originalAmount;
        }

        throw new InvalidArgumentException('Invalid team.');
    }
}
