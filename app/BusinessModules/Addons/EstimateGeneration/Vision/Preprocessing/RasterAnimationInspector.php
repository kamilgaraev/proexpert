<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\RasterPreprocessingException;

final readonly class RasterAnimationInspector
{
    public function assertSingleFrame(string $bytes, string $mime): void
    {
        match ($mime) {
            'image/png' => $this->inspectPng($bytes),
            'image/webp' => $this->inspectWebp($bytes),
            'image/gif' => $this->inspectGif($bytes),
            default => null,
        };
    }

    private function inspectPng(string $bytes): void
    {
        if (! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw new RasterPreprocessingException('invalid_image_container');
        }
        $offset = 8;
        $chunkIndex = 0;
        $seenIend = false;
        $seenIhdr = false;
        $seenIdat = false;
        while ($offset < strlen($bytes)) {
            if ($offset + 12 > strlen($bytes)) {
                throw new RasterPreprocessingException('invalid_image_container');
            }
            $lengthData = unpack('Nlength', substr($bytes, $offset, 4));
            $length = is_array($lengthData) ? (int) $lengthData['length'] : -1;
            $type = substr($bytes, $offset + 4, 4);
            if ($length < 0 || $length > 50_000_000 || $offset + 12 + $length > strlen($bytes)
                || preg_match('/^[A-Za-z]{4}$/', $type) !== 1
                || ($chunkIndex === 0 && $type !== 'IHDR')) {
                throw new RasterPreprocessingException('invalid_image_container');
            }
            $data = substr($bytes, $offset + 8, $length);
            $crcData = unpack('Ncrc', substr($bytes, $offset + 8 + $length, 4));
            $expected = is_array($crcData) ? (int) $crcData['crc'] : -1;
            $actual = (int) sprintf('%u', crc32($type.$data));
            if ((int) sprintf('%u', $expected) !== $actual) {
                throw new RasterPreprocessingException('invalid_image_container');
            }
            if ($type === 'IHDR') {
                if ($seenIhdr || $length !== 13) {
                    throw new RasterPreprocessingException('invalid_image_container');
                }
                $seenIhdr = true;
            } elseif (! $seenIhdr) {
                throw new RasterPreprocessingException('invalid_image_container');
            }
            if (in_array($type, ['acTL', 'fcTL', 'fdAT'], true)) {
                throw new RasterPreprocessingException('animated_image');
            }
            if ($type === 'IDAT') {
                $seenIdat = true;
            }
            if ($type === 'IEND' && ($length !== 0 || ! $seenIdat)) {
                throw new RasterPreprocessingException('invalid_image_container');
            }
            $offset += 12 + $length;
            $chunkIndex++;
            if ($type === 'IEND') {
                $seenIend = true;
                break;
            }
        }
        if (! $seenIend || ! $seenIhdr || ! $seenIdat || $offset !== strlen($bytes)) {
            throw new RasterPreprocessingException('invalid_image_container');
        }
    }

    private function inspectWebp(string $bytes): void
    {
        if (strlen($bytes) < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            throw new RasterPreprocessingException('invalid_image_container');
        }
        $sizeData = unpack('Vsize', substr($bytes, 4, 4));
        if (! is_array($sizeData) || (int) $sizeData['size'] !== strlen($bytes) - 8) {
            throw new RasterPreprocessingException('invalid_image_container');
        }
        $offset = 12;
        while ($offset < strlen($bytes)) {
            if ($offset + 8 > strlen($bytes)) {
                throw new RasterPreprocessingException('invalid_image_container');
            }
            $type = substr($bytes, $offset, 4);
            $lengthData = unpack('Vlength', substr($bytes, $offset + 4, 4));
            $length = is_array($lengthData) ? (int) $lengthData['length'] : -1;
            $padded = $length + ($length % 2);
            if ($length < 0 || $length > 50_000_000 || $offset + 8 + $padded > strlen($bytes)) {
                throw new RasterPreprocessingException('invalid_image_container');
            }
            $data = substr($bytes, $offset + 8, $length);
            if (in_array($type, ['ANIM', 'ANMF'], true) || ($type === 'VP8X' && $length >= 1 && (ord($data[0]) & 0x02) !== 0)) {
                throw new RasterPreprocessingException('animated_image');
            }
            $offset += 8 + $padded;
        }
        if ($offset !== strlen($bytes)) {
            throw new RasterPreprocessingException('invalid_image_container');
        }
    }

    private function inspectGif(string $bytes): void
    {
        if (strlen($bytes) < 14 || ! in_array(substr($bytes, 0, 6), ['GIF87a', 'GIF89a'], true)) {
            throw new RasterPreprocessingException('invalid_image_container');
        }
        $offset = 13;
        $packed = ord($bytes[10]);
        if (($packed & 0x80) !== 0) {
            $offset += 3 * (2 ** (($packed & 0x07) + 1));
        }
        $frames = 0;
        $trailer = false;
        while ($offset < strlen($bytes)) {
            $marker = ord($bytes[$offset++]);
            if ($marker === 0x3B) {
                $trailer = true;
                break;
            }
            if ($marker === 0x21) {
                if ($offset >= strlen($bytes)) {
                    throw new RasterPreprocessingException('invalid_image_container');
                }
                $offset++;
                $offset = $this->skipGifSubBlocks($bytes, $offset);

                continue;
            }
            if ($marker !== 0x2C || $offset + 9 > strlen($bytes)) {
                throw new RasterPreprocessingException('invalid_image_container');
            }
            $frames++;
            $imagePacked = ord($bytes[$offset + 8]);
            $offset += 9;
            if (($imagePacked & 0x80) !== 0) {
                $offset += 3 * (2 ** (($imagePacked & 0x07) + 1));
            }
            if ($offset >= strlen($bytes)) {
                throw new RasterPreprocessingException('invalid_image_container');
            }
            $offset++;
            $offset = $this->skipGifSubBlocks($bytes, $offset);
        }
        if (! $trailer || $offset !== strlen($bytes)) {
            throw new RasterPreprocessingException('invalid_image_container');
        }
        if ($frames !== 1) {
            throw new RasterPreprocessingException($frames > 1 ? 'animated_image' : 'invalid_image_container');
        }
    }

    private function skipGifSubBlocks(string $bytes, int $offset): int
    {
        while (true) {
            if ($offset >= strlen($bytes)) {
                throw new RasterPreprocessingException('invalid_image_container');
            }
            $length = ord($bytes[$offset++]);
            if ($length === 0) {
                return $offset;
            }
            if ($offset + $length > strlen($bytes)) {
                throw new RasterPreprocessingException('invalid_image_container');
            }
            $offset += $length;
        }
    }
}
