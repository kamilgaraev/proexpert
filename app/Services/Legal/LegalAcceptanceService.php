<?php

declare(strict_types=1);

namespace App\Services\Legal;

use App\Models\LegalAcceptanceEvent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LegalAcceptanceService
{
    public function __construct(private readonly LegalDocumentService $documents) {}

    public function record(string $key, string $source, string $reference, Request $request, ?User $user = null, ?Organization $organization = null, array $details = [], string $action = 'accepted'): LegalAcceptanceEvent
    {
        if ($organization !== null && ($user === null || ! $user->organizations()->whereKey($organization->id)->exists())) {
            throw ValidationException::withMessages(['legal_documents' => trans_message('legal.authority')]);
        }
        $eventKey = hash('sha256', implode(':', [$source, $reference, $key, $action, (string) $user?->id, (string) $organization?->id]));
        $snapshot = $this->documents->snapshot($key);
        $evidence = [
            'ip_fingerprint' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            'details' => $details,
        ];
        $data = [
            'id' => (string) Str::uuid(), 'event_key' => $eventKey,
            'user_id' => $user?->id, 'organization_id' => $organization?->id,
            'document_key' => $key, 'version' => $snapshot['version'],
            'document_sha256' => $this->documents->hash($key), 'snapshot' => $snapshot,
            'action' => $action, 'source' => $source, 'reference' => $reference,
            'recorded_at' => now()->utc()->toIso8601String(), 'evidence' => $evidence,
        ];
        $data['proof_hmac'] = hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), (string) config('app.key'));

        $event = LegalAcceptanceEvent::query()->firstOrCreate(['event_key' => $eventKey], $data);
        $storedEvidence = $event->getAttribute('evidence');
        if (! hash_equals((string) $event->document_sha256, $data['document_sha256'])
            || ! is_array($storedEvidence) || ($storedEvidence['details'] ?? []) !== $details) {
            throw ValidationException::withMessages(['legal_documents' => trans_message('legal.request_conflict')]);
        }

        return $event;
    }
}
