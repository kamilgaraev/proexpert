<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\LandingResponse;
use App\Services\Legal\LegalDocumentService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLegalReadiness
{
    public function __construct(private readonly LegalDocumentService $documents) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }
        $commercial = $request->is('api/v1/landing/auth/register', 'api/v1/landing/user-management/invitation/*/accept');
        $privacy = $request->is('api/public/contact', 'api/v1/landing/billing/commercial/enterprise-inquiries');
        if (($commercial && ! $this->documents->commercialReady()) || ($privacy && ! $this->documents->privacyReady())) {
            return LandingResponse::error(trans_message('legal.unavailable'), 503);
        }

        return $next($request);
    }
}
