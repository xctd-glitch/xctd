<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class UploadService
{
    /** @var array<string, string> */
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly string $storageDir,
        private readonly int $maxBytes,
        private readonly int $maxPixels
    ) {
    }

    /** @param array<string, mixed> $file */
    public function receive(array $file): UploadResult
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!is_int($error) || $error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Image upload failed.');
        }

        $size = $file['size'] ?? null;
        $tmpName = $file['tmp_name'] ?? null;
        if (!is_int($size) || $size <= 0 || $size > $this->maxBytes || !is_string($tmpName)) {
            throw new RuntimeException('Invalid image upload.');
        }

        if (!is_uploaded_file($tmpName)) {
            throw new RuntimeException('Invalid upload source.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpName);
        if (!is_string($mime) || !isset(self::ALLOWED_MIME[$mime])) {
            throw new RuntimeException('Only JPEG, PNG, or WebP images are accepted.');
        }

        $imageInfo = @getimagesize($tmpName);
        if (!is_array($imageInfo) || !isset($imageInfo[0], $imageInfo[1])) {
            throw new RuntimeException('Uploaded file is not a valid image.');
        }

        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];
        if ($width < 200 || $height < 200 || ($width * $height) > $this->maxPixels) {
            throw new RuntimeException('Image dimensions are outside the allowed range.');
        }

        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0700, true) && !is_dir($this->storageDir)) {
            throw new RuntimeException('Upload storage is unavailable.');
        }

        $filename = bin2hex(random_bytes(24)) . '.' . self::ALLOWED_MIME[$mime];
        $destination = rtrim($this->storageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($tmpName, $destination)) {
            throw new RuntimeException('Unable to store uploaded image.');
        }

        @chmod($destination, 0600);
        $hash = hash_file('sha256', $destination);
        if (!is_string($hash) || strlen($hash) !== 64) {
            @unlink($destination);
            throw new RuntimeException('Unable to fingerprint uploaded image.');
        }

        return new UploadResult($destination, $hash, $mime);
    }
}
