<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class BankReceiptParser
{
    /** @var list<string> */
    private const SOURCE_LABEL_PATTERNS = [
        '/\bNomor\s+Rekening\s+Sumber\b/iu',
        '/\bRekening\s+Sumber\b/iu',
        '/\bSumber\s+Rekening\b/iu',
        '/\bRekening\s+Debit\b/iu',
        '/\bDebit\s+Account\b/iu',
        '/\bAccount\s+Source\b/iu',
        '/\bRek(?:ening)?\.?\s+Sumber\b/iu',
        '/\bDari\s+Rekening\b/iu',
        '/\bRekening\s+Asal\b/iu',
        '/\bSumber\s+Dana\b/iu',
        '/\bSource\s+Account\b/iu',
        '/\bFrom\s+Account\b/iu',
        '/^\s*Dari\b/iu',
        '/^\s*From\b/iu',
    ];

    /** @var list<string> */
    private const SENDER_LABEL_PATTERNS = [
        '/\bNama\s+Pengirim\b/iu',
        '/\bPengirim\b/iu',
        '/\bSender\b/iu',
        '/\bNama\s+Pemilik\b/iu',
        '/\bAccount\s+Name\b/iu',
    ];

    /** @var list<string> */
    private const DESTINATION_LABEL_PATTERNS = [
        '/\bRekening\s+Tujuan\b/iu',
        '/\bRek(?:ening)?\.?\s+Tujuan\b/iu',
        '/\bKe\s+Rekening\b/iu',
        '/\bPenerima\b/iu',
        '/\bBeneficiary\b/iu',
        '/\bDestination\s+Account\b/iu',
        '/\bTo\s+Account\b/iu',
        '/\bRekening\s+Kredit\b/iu',
        '/\bKepada\b/iu',
        '/^\s*To\b/iu',
        '/\bTujuan\s+Transfer\b/iu',
    ];

    /** @var list<array{pattern:string,priority:int}> */
    private const AMOUNT_LABELS = [
        ['pattern' => '/\bTotal\s+Transaksi\b/iu', 'priority' => 100],
        ['pattern' => '/\bTotal\s+Transfer\b/iu', 'priority' => 95],
        ['pattern' => '/\bTotal\s+Pembayaran\b/iu', 'priority' => 95],
        ['pattern' => '/\bJumlah\s+Transfer\b/iu', 'priority' => 90],
        ['pattern' => '/\bJumlah\s+Transaksi\b/iu', 'priority' => 90],
        ['pattern' => '/\bNilai\s+Transaksi\b/iu', 'priority' => 90],
        ['pattern' => '/\bTransaction\s+Amount\b/iu', 'priority' => 90],
        ['pattern' => '/\bTransfer\s+Amount\b/iu', 'priority' => 90],
        ['pattern' => '/\bNominal\s+Transfer\b/iu', 'priority' => 85],
        ['pattern' => '/\bNominal\b/iu', 'priority' => 75],
        ['pattern' => '/\bAmount\b/iu', 'priority' => 75],
        ['pattern' => '/\bTotal\b/iu', 'priority' => 70],
        ['pattern' => '/\bJumlah\b/iu', 'priority' => 65],
    ];

    public function parse(string $rawText): ReceiptData
    {
        $text = $this->normalizeText($rawText);
        $lines = preg_split('/\n/u', $text);
        if (!is_array($lines) || $lines === []) {
            throw new RuntimeException('Unable to read receipt text.');
        }

        [$amount, $amountIndex] = $this->extractTransactionAmount($lines);

        $source = $this->extractSourceFromLabeledBlock($lines);
        if ($source === null) {
            $source = $this->extractSourceFromSenderLabel($lines);
        }
        if ($source === null && preg_match('/\b(?:Mandiri|Livin)\b/iu', $text) === 1) {
            $source = $this->extractLegacySourcePair($lines, $amountIndex);
        }
        if ($source === null) {
            throw new RuntimeException('Sender name or source account was not detected.');
        }

        return new ReceiptData(
            $source['sender'],
            $source['last4'],
            $amount,
            $this->extractReferenceNo($text),
            $this->extractDate($text),
            $this->extractTime($text)
        );
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $rows = explode("\n", $text);
        $clean = [];

        foreach ($rows as $row) {
            $row = preg_replace('/[\t \x{00A0}]+/u', ' ', trim($row));
            if (is_string($row) && $row !== '') {
                $clean[] = $row;
            }
        }

        return implode("\n", $clean);
    }

    /**
     * @param list<string> $lines
     * @return array{0:int,1:int}
     */
    private function extractTransactionAmount(array $lines): array
    {
        $best = null;

        foreach ($lines as $index => $line) {
            if ($this->isFeeLine($line)) {
                continue;
            }

            foreach (self::AMOUNT_LABELS as $label) {
                if (preg_match($label['pattern'], $line) !== 1) {
                    continue;
                }

                $candidate = $this->extractAmountNearLine($lines, $index, $label['pattern']);
                if ($candidate === null) {
                    continue;
                }

                if ($best === null || $label['priority'] > $best['priority']) {
                    $best = [
                        'amount' => $candidate,
                        'index' => $index,
                        'priority' => $label['priority'],
                    ];
                }
            }
        }

        if ($best === null) {
            throw new RuntimeException('Transaction amount was not detected.');
        }

        return [$best['amount'], $best['index']];
    }

    private function isFeeLine(string $line): bool
    {
        return preg_match('/\b(?:Biaya|Admin|Fee|Charge|Saldo|Balance)\b/iu', $line) === 1;
    }

    /** @param list<string> $lines */
    private function extractAmountNearLine(array $lines, int $index, string $labelPattern): ?int
    {
        $line = $lines[$index] ?? '';
        $inline = preg_replace($labelPattern, ' ', $line, 1);
        if (is_string($inline)) {
            $amount = $this->parseMoneyFromText($inline, true);
            if ($amount !== null) {
                return $amount;
            }
        }

        $limit = min(count($lines) - 1, $index + 2);
        for ($cursor = $index + 1; $cursor <= $limit; $cursor++) {
            $candidateLine = $lines[$cursor] ?? '';
            if ($this->matchesAny($candidateLine, self::SOURCE_LABEL_PATTERNS)
                || $this->matchesAny($candidateLine, self::DESTINATION_LABEL_PATTERNS)) {
                break;
            }

            $amount = $this->parseMoneyFromText($candidateLine, false);
            if ($amount !== null) {
                return $amount;
            }
        }

        return null;
    }

    private function parseMoneyFromText(string $text, bool $allowPlainDigits): ?int
    {
        $match = null;
        if (preg_match('/(?:Rp|IDR)\s*([0-9][0-9.,\s]{0,24})/iu', $text, $currencyMatch) === 1) {
            $match = $currencyMatch[1];
        } elseif (preg_match('/([0-9]{1,3}(?:[.,][0-9]{3})+(?:[.,][0-9]{2})?)/u', $text, $groupedMatch) === 1) {
            $match = $groupedMatch[1];
        } elseif ($allowPlainDigits && preg_match('/\b([0-9]{3,15})\b/u', $text, $plainMatch) === 1) {
            $match = $plainMatch[1];
        }

        if (!is_string($match) || trim($match) === '') {
            return null;
        }

        return $this->parseRupiahInteger($match);
    }

    private function parseRupiahInteger(string $value): ?int
    {
        $value = preg_replace('/\s+/u', '', trim($value));
        if (!is_string($value) || $value === '') {
            return null;
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        $lastSeparator = max($lastComma === false ? -1 : $lastComma, $lastDot === false ? -1 : $lastDot);

        if ($lastSeparator >= 0) {
            $fraction = substr($value, $lastSeparator + 1);
            $prefix = substr($value, 0, $lastSeparator);
            if (preg_match('/^\d{1,2}$/D', $fraction) === 1) {
                $separator = $value[$lastSeparator];
                $sameSeparatorCount = substr_count($value, $separator);
                $otherSeparator = $separator === ',' ? '.' : ',';
                $looksDecimal = str_contains($prefix, $otherSeparator)
                    || $sameSeparatorCount === 1
                    || strlen($fraction) !== 3;

                if ($looksDecimal) {
                    if ((int) $fraction !== 0) {
                        throw new RuntimeException('Fractional source amount is not supported.');
                    }
                    $value = $prefix;
                }
            }
        }

        $digits = preg_replace('/\D+/', '', $value);
        if (!is_string($digits) || $digits === '' || strlen($digits) > 15) {
            return null;
        }

        $amount = filter_var($digits, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => PHP_INT_MAX,
            ],
        ]);

        return is_int($amount) ? $amount : null;
    }

    /**
     * @param list<string> $lines
     * @return array{sender:string,last4:string}|null
     */
    private function extractSourceFromLabeledBlock(array $lines): ?array
    {
        foreach ($lines as $index => $line) {
            if (!$this->matchesAny($line, self::SOURCE_LABEL_PATTERNS)) {
                continue;
            }

            $block = [];
            $inline = $this->removeFirstMatchingLabel($line, self::SOURCE_LABEL_PATTERNS);
            if ($inline !== '') {
                $block[] = $inline;
            }

            $limit = min(count($lines) - 1, $index + 8);
            for ($cursor = $index + 1; $cursor <= $limit; $cursor++) {
                $candidate = $lines[$cursor] ?? '';
                if ($this->matchesAny($candidate, self::DESTINATION_LABEL_PATTERNS)) {
                    break;
                }
                $block[] = $candidate;
            }

            $source = $this->extractSourceFromBlock($block);
            if ($source !== null) {
                return $source;
            }
        }

        return null;
    }

    /**
     * @param list<string> $lines
     * @return array{sender:string,last4:string}|null
     */
    private function extractSourceFromSenderLabel(array $lines): ?array
    {
        foreach ($lines as $index => $line) {
            if (!$this->matchesAny($line, self::SENDER_LABEL_PATTERNS)) {
                continue;
            }

            $sender = $this->cleanNameCandidate(
                $this->removeFirstMatchingLabel($line, self::SENDER_LABEL_PATTERNS)
            );

            if ($sender === null) {
                $next = $lines[$index + 1] ?? '';
                $sender = $this->cleanNameCandidate($next);
            }

            if ($sender === null) {
                continue;
            }

            $lower = max(0, $index - 2);
            $upper = min(count($lines) - 1, $index + 5);
            for ($cursor = $lower; $cursor <= $upper; $cursor++) {
                $last4 = $this->extractAccountLast4($lines[$cursor] ?? '');
                if ($last4 !== null) {
                    return ['sender' => $sender, 'last4' => $last4];
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $block
     * @return array{sender:string,last4:string}|null
     */
    private function extractSourceFromBlock(array $block): ?array
    {
        $sender = null;
        $last4 = null;

        foreach ($block as $line) {
            if ($last4 === null) {
                $last4 = $this->extractAccountLast4($line);
            }
            if ($sender === null) {
                $sender = $this->cleanNameCandidate($line);
            }
        }

        if ($sender !== null && $last4 !== null) {
            return ['sender' => $sender, 'last4' => $last4];
        }

        return null;
    }

    /**
     * @param list<string> $lines
     * @return array{sender:string,last4:string}|null
     */
    private function extractLegacySourcePair(array $lines, int $amountIndex): ?array
    {
        $ranges = [
            [$amountIndex + 1, min(count($lines) - 1, $amountIndex + 10)],
            [0, count($lines) - 1],
        ];

        foreach ($ranges as [$start, $end]) {
            for ($index = max(0, $start); $index <= $end; $index++) {
                $sender = $this->cleanNameCandidate($lines[$index] ?? '');
                if ($sender === null) {
                    continue;
                }

                $accountLimit = min($end, $index + 3);
                for ($cursor = $index + 1; $cursor <= $accountLimit; $cursor++) {
                    $accountLine = $lines[$cursor] ?? '';
                    if (!$this->looksLikeAccountLine($accountLine)) {
                        continue;
                    }

                    $last4 = $this->extractAccountLast4($accountLine);
                    if ($last4 !== null) {
                        return ['sender' => $sender, 'last4' => $last4];
                    }
                }
            }
        }

        return null;
    }

    private function looksLikeAccountLine(string $line): bool
    {
        if (preg_match('/[*xX•+]{2,}\s*\d{4}\b/u', $line) === 1) {
            return true;
        }

        if (preg_match('/\b(?:Bank\s+Mandiri|Mandiri|BCA|BRI|BNI|CIMB|Permata|Danamon|BSI|BTN|Maybank|OCBC|SeaBank|Jago|Panin)\b/iu', $line) === 1
            && preg_match('/\d{4}/u', $line) === 1) {
            return true;
        }

        return preg_match('/(?<!\d)\d{8,20}(?!\d)/u', preg_replace('/\s+/', '', $line) ?? '') === 1;
    }

    private function extractAccountLast4(string $line): ?string
    {
        if (preg_match('/(?:[*xX•+]{2,}|\.{2,}|-{2,})\s*(\d{4})\b/u', $line, $masked) === 1) {
            return $masked[1];
        }

        $normalized = preg_replace('/[\s-]+/u', '', $line);
        if (is_string($normalized) && preg_match('/(?<!\d)(\d{8,20})(?!\d)/u', $normalized, $full) === 1) {
            return substr($full[1], -4);
        }

        if ($this->looksLikeAccountLine($line) && preg_match('/(\d{4})\s*$/u', $line, $tail) === 1) {
            return $tail[1];
        }

        return null;
    }

    private function cleanNameCandidate(string $line): ?string
    {
        $line = trim($line);
        if ($line === '' || strlen($line) > 160) {
            return null;
        }

        if ($this->matchesAny($line, self::SOURCE_LABEL_PATTERNS)
            || $this->matchesAny($line, self::DESTINATION_LABEL_PATTERNS)) {
            return null;
        }

        $line = preg_replace('/(?:[*xX•+]{2,}|\.{2,}|-{2,})\s*\d{4}\b/u', ' ', $line);
        $line = preg_replace('/\b\d{6,20}\b/u', ' ', (string) $line);
        $line = preg_replace('/\b(?:Bank\s+Mandiri|Mandiri|BCA|BRI|BNI|CIMB|Permata|Danamon|BSI|BTN|Maybank|OCBC|SeaBank|Jago|Panin)\b/iu', ' ', (string) $line);
        $line = preg_replace('/^[\s:|\-–—]+|[\s:|\-–—]+$/u', '', (string) $line);
        $line = preg_replace('/\s+/u', ' ', trim((string) $line));

        if (!is_string($line) || $line === '' || strlen($line) > 100) {
            return null;
        }

        if (preg_match('/\d/u', $line) === 1) {
            return null;
        }

        if (preg_match('/\b(?:Transfer|Transaksi|Berhasil|Sukses|Success|Receipt|Bukti|Keterangan|Catatan|Metode|Nominal|Total|Jumlah|Biaya|Admin|Fee|Tanggal|Waktu|Reference|Referensi|No\.?\s*Ref|Nomor|Saldo|Balance|Sumber\s+Dana|Rekening|Account|Tahapan|Tabungan|Giro|Mobile|myBCA|BRImo|Wondr|Livin)\b/iu', $line) === 1) {
            return null;
        }

        if (preg_match('/^[\p{L}][\p{L}\s.\'&\-]{2,99}$/u', $line) !== 1) {
            return null;
        }

        return $this->normalizeName($line);
    }

    private function normalizeName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name));
        if (!is_string($name) || $name === '') {
            return '';
        }

        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($name, 'UTF-8');
        }

        return strtoupper($name);
    }

    /** @param list<string> $patterns */
    private function matchesAny(string $line, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $patterns */
    private function removeFirstMatchingLabel(string $line, array $patterns): string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) !== 1) {
                continue;
            }

            $cleaned = preg_replace($pattern, ' ', $line, 1);
            if (is_string($cleaned)) {
                $cleaned = preg_replace('/^[\s:|\-–—]+/u', '', trim($cleaned));
                return is_string($cleaned) ? trim($cleaned) : '';
            }
        }

        return trim($line);
    }

    private function extractReferenceNo(string $text): ?string
    {
        $patterns = [
            '/\bNo\.?\s*Ref(?:erensi)?\.?\s*[:#-]?\s*([A-Z0-9-]{8,50})\b/iu',
            '/\bNomor\s+Referensi\s*[:#-]?\s*([A-Z0-9-]{8,50})\b/iu',
            '/\bReference(?:\s+Number|\s+No\.?)?\s*[:#-]?\s*([A-Z0-9-]{8,50})\b/iu',
            '/\b(?:Transaction|Transaksi)\s+ID\s*[:#-]?\s*([A-Z0-9-]{8,50})\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) === 1) {
                return substr($match[1], 0, 40);
            }
        }

        return null;
    }

    private function extractDate(string $text): ?string
    {
        $months = [
            'JAN' => '01', 'JANUARI' => '01', 'JANUARY' => '01',
            'FEB' => '02', 'FEBRUARI' => '02', 'FEBRUARY' => '02',
            'MAR' => '03', 'MARET' => '03', 'MARCH' => '03',
            'APR' => '04', 'APRIL' => '04',
            'MEI' => '05', 'MAY' => '05',
            'JUN' => '06', 'JUNI' => '06', 'JUNE' => '06',
            'JUL' => '07', 'JULI' => '07', 'JULY' => '07',
            'AGU' => '08', 'AGUSTUS' => '08', 'AUG' => '08', 'AUGUST' => '08',
            'SEP' => '09', 'SEPT' => '09', 'SEPTEMBER' => '09',
            'OKT' => '10', 'OKTOBER' => '10', 'OCT' => '10', 'OCTOBER' => '10',
            'NOV' => '11', 'NOVEMBER' => '11',
            'DES' => '12', 'DESEMBER' => '12', 'DEC' => '12', 'DECEMBER' => '12',
        ];

        if (preg_match('/\b(\d{1,2})\s+([A-Za-z]{3,10})\s+(20\d{2})\b/u', $text, $wordDate) === 1) {
            $monthKey = strtoupper($wordDate[2]);
            $month = $months[$monthKey] ?? null;
            if ($month !== null) {
                return $this->validatedDate((int) $wordDate[3], (int) $month, (int) $wordDate[1]);
            }
        }

        if (preg_match('/\b(\d{1,2})[\/-](\d{1,2})[\/-](20\d{2})\b/u', $text, $numericDate) === 1) {
            return $this->validatedDate(
                (int) $numericDate[3],
                (int) $numericDate[2],
                (int) $numericDate[1]
            );
        }

        return null;
    }

    private function validatedDate(int $year, int $month, int $day): ?string
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function extractTime(string $text): ?string
    {
        if (preg_match('/\b([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?(?:\s*(?:WIB|WITA|WIT))?\b/iu', $text, $match) === 1) {
            $seconds = isset($match[3]) && $match[3] !== '' ? (int) $match[3] : 0;
            return sprintf('%02d:%02d:%02d', (int) $match[1], (int) $match[2], $seconds);
        }

        return null;
    }
}
