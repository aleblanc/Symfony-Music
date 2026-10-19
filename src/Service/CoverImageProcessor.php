<?php

namespace App\Service;

/**
 * Square-crops and re-encodes cover art with GD: WebP for the database (the
 * legacy schema normalises art to WebP), JPEG for embedding in audio tags
 * (the most widely supported APIC format).
 *
 * Without ext-gd the bytes are passed through unchanged when already in a
 * usable format, so enrichment still works on a minimal PHP build.
 */
class CoverImageProcessor
{
    public function toWebp(string $imageData, int $targetWidth = 640, int $quality = 82): ?string
    {
        return $this->process($imageData, 'webp', $targetWidth, $quality) ?? ($this->isImage($imageData) ? $imageData : null);
    }

    public function toJpeg(string $imageData, int $targetWidth = 640, int $quality = 82): ?string
    {
        return $this->process($imageData, 'jpeg', $targetWidth, $quality)
            ?? (str_starts_with($imageData, "\xFF\xD8\xFF") ? $imageData : null);
    }

    private function process(string $imageData, string $format, int $targetWidth, int $quality): ?string
    {
        if (!\function_exists('imagecreatefromstring')) {
            return null;
        }

        $source = @imagecreatefromstring($imageData);
        if ($source === false) {
            return null;
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);
        $minDim = min($srcW, $srcH);
        $srcX = (int) (($srcW - $minDim) / 2);
        $srcY = (int) (($srcH - $minDim) / 2);
        $finalSize = min($minDim, $targetWidth);

        $resized = imagecreatetruecolor(max(1, $finalSize), max(1, $finalSize));
        if ($format === 'webp') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }
        imagecopyresampled($resized, $source, 0, 0, $srcX, $srcY, $finalSize, $finalSize, $minDim, $minDim);

        ob_start();
        if ($format === 'webp') {
            imagewebp($resized, null, $quality);
        } else {
            imagejpeg($resized, null, $quality);
        }
        $data = ob_get_clean();

        imagedestroy($source);
        imagedestroy($resized);

        return $data !== false && $data !== '' ? $data : null;
    }

    private function isImage(string $bytes): bool
    {
        return str_starts_with($bytes, "\xFF\xD8\xFF")
            || str_starts_with($bytes, "\x89PNG")
            || (str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 0, 16), 'WEBP'));
    }
}
