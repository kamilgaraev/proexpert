<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class ConfirmEstimateGenerationDocumentCost
{
    public function __construct(
        private AuthorizationService $authorization,
        private DocumentGenerationReadinessService $readiness,
        private DispatchDocumentProcessingUnits $dispatcher,
    ) {}

    public function handle(
        EstimateGenerationSession $session,
        EstimateGenerationDocument $document,
        User $actor,
        int $expectedVersion,
        string $expectedSourceVersion,
        string $idempotencyKey,
    ): DocumentActionResult {
        $keyHash = hash('sha256', $idempotencyKey);
        [$lockedSession, $lockedDocument, $disposition, $attemptId] = DB::transaction(function () use (
            $session, $document, $actor, $expectedVersion, $expectedSourceVersion, $keyHash,
        ): array {
            $lockedSession = EstimateGenerationSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $lockedDocument = EstimateGenerationDocument::query()
                ->where('organization_id', $lockedSession->organization_id)
                ->where('project_id', $lockedSession->project_id)
                ->where('session_id', $lockedSession->id)
                ->lockForUpdate()
                ->findOrFail($document->getKey());
            if ((int) $lockedSession->state_version !== $expectedVersion) {
                throw new DocumentProcessingControlConflict('stale_session');
            }
            if ((int) $actor->current_organization_id !== (int) $lockedDocument->organization_id
                || ! $this->authorization->can($actor, 'estimate_generation.review', [
                    'organization_id' => (int) $lockedDocument->organization_id,
                    'project_id' => (int) $lockedDocument->project_id,
                ])) {
                throw new DocumentProcessingControlConflict('forbidden');
            }
            if (! hash_equals((string) $lockedDocument->source_version, $expectedSourceVersion)) {
                throw new DocumentProcessingControlConflict('stale_source');
            }
            $meta = is_array($lockedDocument->meta) ? $lockedDocument->meta : [];
            $current = is_array($meta['processing_cost_confirmation'] ?? null)
                ? $meta['processing_cost_confirmation'] : [];
            $history = is_array($meta['processing_cost_confirmations'] ?? null)
                ? array_values(array_filter(
                    $meta['processing_cost_confirmations'],
                    static fn (mixed $entry): bool => is_array($entry),
                ))
                : [];
            $attemptId = is_string($meta['processing_attempt_id'] ?? null)
                ? $meta['processing_attempt_id'] : null;
            if (($current['idempotency_hash'] ?? null) === $keyHash
                || collect($history)->contains(
                    static fn (array $entry): bool => ($entry['idempotency_hash'] ?? null) === $keyHash,
                )) {
                return [$lockedSession, $lockedDocument, 'replayed', $attemptId];
            }
            if ((string) $lockedDocument->processing_control_status !== 'paused'
                || ! in_array((string) $lockedDocument->processing_control_reason, [
                    'cost_limit_reached',
                    'session_cost_limit_reached',
                ], true)) {
                throw new DocumentProcessingControlConflict('confirmation_not_required');
            }
            $documentConfirmation = (string) $lockedDocument->processing_control_reason === 'cost_limit_reached';
            $sessionConfirmation = (string) $lockedDocument->processing_control_reason === 'session_cost_limit_reached';
            $increment = (string) config(
                'estimate-generation.generation.document_cost_confirmation_increment_rub',
                '300.00',
            );
            $currentLimit = $lockedDocument->processing_cost_limit === null
                ? (string) config('estimate-generation.generation.document_cost_limit_rub', '600.00')
                : (string) $lockedDocument->processing_cost_limit;
            $now = now();
            $analysis = is_array($lockedSession->analysis_payload) ? $lockedSession->analysis_payload : [];
            $sessionGuard = is_array($analysis['internal_cost_guard'] ?? null)
                ? $analysis['internal_cost_guard']
                : [];
            $currentSessionConfirmationVersion = max(0, (int) ($sessionGuard['confirmation_version'] ?? 0));
            $pausedSessionConfirmationVersion = max(
                0,
                (int) ($meta['session_cost_guard_confirmation_version'] ?? $currentSessionConfirmationVersion),
            );
            $sessionConfirmationAdvances = $sessionConfirmation
                && $currentSessionConfirmationVersion <= $pausedSessionConfirmationVersion;
            $documentConfirmationVersion = (int) $lockedDocument->processing_cost_confirmation_version
                + ($documentConfirmation ? 1 : 0);
            $sessionConfirmationVersion = $currentSessionConfirmationVersion
                + ($sessionConfirmationAdvances ? 1 : 0);
            $confirmation = [
                'idempotency_hash' => $keyHash,
                'source_version' => $expectedSourceVersion,
                'attempt_id' => $attemptId,
                'confirmed_at' => $now->toISOString(),
                'version' => $documentConfirmation ? $documentConfirmationVersion : $sessionConfirmationVersion,
                'guard' => $documentConfirmation ? 'document' : 'session',
            ];
            $lockedDocument->forceFill([
                'status' => 'processing',
                'processing_stage' => 'preflight',
                'processing_control_status' => 'active',
                'processing_control_reason' => null,
                'processing_control_at' => null,
                'processing_cost_limit' => $documentConfirmation
                    ? $this->addMoney($currentLimit, $increment)
                    : $lockedDocument->processing_cost_limit,
                'processing_cost_confirmed_at' => $now,
                'processing_cost_confirmation_version' => $documentConfirmationVersion,
                'meta' => [
                    ...$meta,
                    'processing_cost_confirmation' => $confirmation,
                    'processing_cost_confirmations' => [...$history, $confirmation],
                ],
            ])->save();
            if ($sessionConfirmationAdvances) {
                $lockedSession->forceFill([
                    'analysis_payload' => [
                        ...$analysis,
                        'internal_cost_guard' => [
                            ...$sessionGuard,
                            'confirmation_version' => $sessionConfirmationVersion,
                            'confirmed_at' => $now->toISOString(),
                        ],
                    ],
                ])->save();
            }

            return [$lockedSession, $lockedDocument, 'accepted', $attemptId];
        }, 3);

        if ($disposition === 'accepted') {
            $this->dispatcher->forDocument((int) $lockedDocument->id, $expectedSourceVersion, true);
        }
        $freshSession = $lockedSession->fresh(['documents.processingUnits']) ?? $lockedSession;

        return new DocumentActionResult(
            $lockedDocument->fresh() ?? $lockedDocument,
            $this->readiness->evaluate($freshSession)['summary'],
            'estimate_generation.document_cost_confirmed',
            $disposition,
            $attemptId,
        );
    }

    private function addMoney(string $left, string $right): string
    {
        $leftMinor = $this->minorUnits($left);
        $rightMinor = $this->minorUnits($right);
        $carry = 0;
        $sum = '';
        $leftIndex = strlen($leftMinor) - 1;
        $rightIndex = strlen($rightMinor) - 1;
        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $digit = $carry;
            if ($leftIndex >= 0) {
                $digit += (int) $leftMinor[$leftIndex--];
            }
            if ($rightIndex >= 0) {
                $digit += (int) $rightMinor[$rightIndex--];
            }
            $sum = (string) ($digit % 10).$sum;
            $carry = intdiv($digit, 10);
        }
        $sum = str_pad($sum, 9, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($sum, 0, -8), '0');

        return ($whole === '' ? '0' : $whole).'.'.substr($sum, -8);
    }

    private function minorUnits(string $value): string
    {
        if (preg_match('/^(0|[1-9][0-9]{0,17})(?:\.([0-9]{1,8}))?$/', $value, $matches) !== 1) {
            throw new DocumentProcessingControlConflict('cost_limit_invalid');
        }

        return ltrim($matches[1].str_pad($matches[2] ?? '', 8, '0'), '0') ?: '0';
    }
}
