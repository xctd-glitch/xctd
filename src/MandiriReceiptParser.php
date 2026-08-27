<?php

declare(strict_types=1);

namespace App;

/**
 * Backward-compatible wrapper. New code should use BankReceiptParser.
 */
final class MandiriReceiptParser
{
    public function parse(string $rawText): ReceiptData
    {
        return (new BankReceiptParser())->parse($rawText);
    }
}
