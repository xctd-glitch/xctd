<?php

declare(strict_types=1);

namespace App;

final class ReceiptData
{
    public function __construct(
        public readonly string $senderName,
        public readonly string $sourceAccountLast4,
        public readonly int $originalAmount,
        public readonly ?string $referenceNo,
        public readonly ?string $receiptDate,
        public readonly ?string $receiptTime
    ) {
    }
}
