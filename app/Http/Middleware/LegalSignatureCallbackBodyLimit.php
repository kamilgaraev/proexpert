<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LegalSignatureCallbackBodyLimit
{
    private const MAX_BODY_BYTES = 131072;

    public function handle(Request $request, Closure $next): Response
    {
        $contentLength = $request->server->get('CONTENT_LENGTH');
        if ($contentLength !== null
            && (! is_numeric($contentLength) || (int) $contentLength > self::MAX_BODY_BYTES)) {
            return new JsonResponse(['accepted' => false], 413);
        }
        $content = $request->getContent();
        if (! is_string($content) || strlen($content) > self::MAX_BODY_BYTES) {
            return new JsonResponse(['accepted' => false], 413);
        }

        return $next($request);
    }
}
