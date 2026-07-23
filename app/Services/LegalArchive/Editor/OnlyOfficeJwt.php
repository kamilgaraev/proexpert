<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Editor;

use DomainException;
use JsonException;

final class OnlyOfficeJwt
{
    public static function encode(array $claims, string $secret): string
    {
        $header = self::base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::base64Url(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $signature = self::base64Url(hash_hmac('sha256', $header.'.'.$payload, $secret, true));

        return $header.'.'.$payload.'.'.$signature;
    }

    public static function decode(string $token, string $secret): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new DomainException('legal_document_editor_callback_unauthenticated');
        }
        [$header, $payload, $signature] = $parts;
        if (! hash_equals(self::base64Url(hash_hmac('sha256', $header.'.'.$payload, $secret, true)), $signature)) {
            throw new DomainException('legal_document_editor_callback_unauthenticated');
        }
        try {
            $decodedHeader = json_decode(self::decodeBase64($header), true, 16, JSON_THROW_ON_ERROR);
            $claims = json_decode(self::decodeBase64($payload), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('legal_document_editor_callback_unauthenticated');
        }
        if (! is_array($decodedHeader) || ($decodedHeader['alg'] ?? null) !== 'HS256' || ! is_array($claims)) {
            throw new DomainException('legal_document_editor_callback_unauthenticated');
        }

        return $claims;
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decodeBase64(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (! is_string($decoded)) {
            throw new DomainException('legal_document_editor_callback_unauthenticated');
        }

        return $decoded;
    }
}
