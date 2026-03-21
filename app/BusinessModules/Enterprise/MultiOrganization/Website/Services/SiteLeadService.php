<?php

declare(strict_types=1);

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Services;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSiteLead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class SiteLeadService
{
    public function submitLead(HoldingSite $site, array $data, Request $request): HoldingSiteLead
    {
        return $site->leads()->create([
            'block_key' => $data['block_key'] ?? null,
            'contact_name' => $data['name'] ?? null,
            'company_name' => $data['company'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'message' => $data['message'] ?? null,
            'form_payload' => $data['form_payload'] ?? [],
            'metadata' => array_merge($data['metadata'] ?? [], [
                'referrer' => $request->headers->get('referer'),
            ]),
            'utm_params' => $this->extractUtmParams($request, $data),
            'source_page' => $data['source_page'] ?? null,
            'source_url' => $data['source_url'] ?? $request->fullUrl(),
            'status' => !empty($data['website']) ? 'spam' : 'new',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'submitted_at' => now(),
        ]);
    }

    public function leadsForSite(HoldingSite $site, array $filters = []): array
    {
        $query = $site->leads()->orderByDesc('submitted_at');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get()->map(fn (HoldingSiteLead $lead) => $this->serializeLead($lead))->values()->all();
    }

    public function getSummary(HoldingSite $site): array
    {
        $baseQuery = $site->leads();

        return [
            'total' => (clone $baseQuery)->count(),
            'new' => (clone $baseQuery)->where('status', 'new')->count(),
            'spam' => (clone $baseQuery)->where('status', 'spam')->count(),
            'today' => (clone $baseQuery)->whereDate('submitted_at', now()->toDateString())->count(),
            'week' => (clone $baseQuery)->where('submitted_at', '>=', now()->subDays(7))->count(),
            'latest' => optional((clone $baseQuery)->latest('submitted_at')->first()?->submitted_at)?->toISOString(),
        ];
    }

    public function serializeLead(HoldingSiteLead $lead): array
    {
        return [
            'id' => $lead->id,
            'block_key' => $lead->block_key,
            'name' => $lead->contact_name,
            'company' => $lead->company_name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'message' => $lead->message,
            'form_payload' => $lead->form_payload ?? [],
            'metadata' => $lead->metadata ?? [],
            'utm_params' => $lead->utm_params ?? [],
            'source_page' => $lead->source_page,
            'source_url' => $lead->source_url,
            'status' => $lead->status,
            'submitted_at' => optional($lead->submitted_at)?->toISOString(),
        ];
    }

    public function ensureRateLimit(HoldingSite $site, Request $request): bool
    {
        $key = $this->rateLimitKey($site, $request);

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return false;
        }

        RateLimiter::hit($key, 3600);

        return true;
    }

    private function rateLimitKey(HoldingSite $site, Request $request): string
    {
        return sprintf('holding-site-lead:%d:%s', $site->id, $request->ip() ?? 'guest');
    }

    private function extractUtmParams(Request $request, array $data): array
    {
        return array_filter([
            'utm_source' => $data['utm_source'] ?? $request->input('utm_source'),
            'utm_medium' => $data['utm_medium'] ?? $request->input('utm_medium'),
            'utm_campaign' => $data['utm_campaign'] ?? $request->input('utm_campaign'),
            'utm_content' => $data['utm_content'] ?? $request->input('utm_content'),
            'utm_term' => $data['utm_term'] ?? $request->input('utm_term'),
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
