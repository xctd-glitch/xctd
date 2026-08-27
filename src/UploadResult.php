<?php

declare(strict_types=1);

namespace App;

final class UploadResult
{
    public function __construct(
        public readonly string $path,
        public readonly string $sha256,
        public readonly string $mimeType
    ) {
    }
}
