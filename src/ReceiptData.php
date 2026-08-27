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
        public readonly ?string $receiptTime,
        // Populated only when $senderName is empty: the OCR text located a source
        // account line but no usable sender name (observed on myBCA's own "Transfer
        // Berhasil" receipt, which never prints the sender's own name). These let
        // TeamRepository fall back to matching a registered account number instead.
        public readonly ?string $sourceBankCode = null,
        public readonly ?string $sourceAccountMask = null
    ) {
    }
}
