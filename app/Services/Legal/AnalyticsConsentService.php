<?php

declare(strict_types=1);

namespace App\Services\Legal;

use App\Models\LegalAcceptanceEvent;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AnalyticsConsentService
{
    public function __construct(private readonly LegalDocumentService $documents, private readonly LegalAcceptanceService $acceptances) {}

    public function active(array $data): bool
    {
        if (! $this->documents->manifest()['analytics_ready']) {
            return false;
        }
        $visitor = hash_hmac('sha256', $data['visitor_id'], (string) config('app.key'));
        $receipt = LegalAcceptanceEvent::query()->whereKey($data['receipt_id'])->where('source', 'analytics_choice')
            ->where('evidence->details->visitor_fingerprint', $visitor)->exists();
        if (! $receipt) {
            return false;
        }
        $latest = LegalAcceptanceEvent::query()->where('source', 'analytics_choice')
            ->where('evidence->details->visitor_fingerprint', $visitor)->latest('sequence')->first();

        return $latest !== null && $latest->action === 'consented'
            && $latest->recorded_at->greaterThan(now()->subDays(180))
            && hash_equals($this->documents->hash('cookies'), $latest->document_sha256);
    }

    public function record(array $data, Request $request): LegalAcceptanceEvent
    {
        $visitor = hash_hmac('sha256', $data['visitor_id'], (string) config('app.key'));
        if ($data['analytics']) {
            $this->documents->assertAccepted($data, ['cookies'], false);
            if (! $this->documents->manifest()['analytics_ready']) {
                throw ValidationException::withMessages(['analytics' => trans_message('legal.unavailable')]);
            }
        } else {
            $previous = LegalAcceptanceEvent::query()->find($data['receipt_id'] ?? '');
            $previousEvidence = $previous?->getAttribute('evidence');
            $details = is_array($previousEvidence) ? ($previousEvidence['details'] ?? []) : [];
            if ($previous === null || $previous->source !== 'analytics_choice'
                || ! is_array($details) || ! hash_equals((string) ($details['visitor_fingerprint'] ?? ''), $visitor)) {
                throw ValidationException::withMessages(['receipt_id' => trans_message('legal.stale')]);
            }
        }

        return $this->acceptances->record('cookies', 'analytics_choice', $data['event_id'], $request, details: [
            'visitor_fingerprint' => $visitor,
            'previous_receipt_id' => $data['receipt_id'] ?? null,
        ], action: $data['analytics'] ? 'consented' : 'revoked');
    }
}
