<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OnlyOfficeCallbackBodyLimit
{
    private const ABSOLUTE_MAX_BODY_BYTES = 65536;

    private const MAX_AUTHORIZATION_BYTES = 16384;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1/legal-document-editor/callback/*')) {
            return $next($request);
        }

        $maximum = min(
            self::ABSOLUTE_MAX_BODY_BYTES,
            max(1, (int) config('legal-document-editor.callback.max_body_bytes', self::ABSOLUTE_MAX_BODY_BYTES)),
        );
        $contentLength = $request->server->get('CONTENT_LENGTH');
        if ($contentLength !== null) {
            $declaredLength = is_int($contentLength) ? (string) $contentLength : $contentLength;
            if (! is_string($declaredLength)
                || preg_match('/^[0-9]+$/D', $declaredLength) !== 1
                || (int) $declaredLength > $maximum) {
                return $this->rejected();
            }
        }

        $authorization = (string) $request->headers->get('Authorization', '');
        if (strlen($authorization) > self::MAX_AUTHORIZATION_BYTES || ! $this->bodyFits($request, $maximum)) {
            return $this->rejected();
        }

        $session = (string) $request->route('session');
        $credentialFingerprint = $authorization === ''
            ? 'anonymous'
            : hash('sha256', $authorization);
        $request->attributes->set(
            'legal_editor_callback_rate_key',
            hash('sha256', $session.'|'.$credentialFingerprint),
        );

        return $next($request);
    }

    private function bodyFits(Request $request, int $maximum): bool
    {
        $stream = $request->getContent(true);
        if (! (bool) (stream_get_meta_data($stream)['seekable'] ?? false)) {
            return false;
        }
        $read = 0;

        while (! feof($stream)) {
            $chunk = fread($stream, min(8192, $maximum + 1 - $read));
            if ($chunk === false) {
                return false;
            }
            $read += strlen($chunk);
            if ($read > $maximum) {
                return false;
            }
        }

        rewind($stream);

        return true;
    }

    private function rejected(): JsonResponse
    {
        return new JsonResponse(['error' => 1], 413);
    }
}
