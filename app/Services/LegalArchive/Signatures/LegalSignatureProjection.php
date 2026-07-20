<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use Illuminate\Database\ConnectionInterface;

final readonly class LegalSignatureProjection
{
    public function __construct(private ConnectionInterface $connection) {}

    public function apply(LegalArchiveDocument $document): void
    {
        $requests = $this->connection->table('legal_signature_requests')
            ->where('organization_id', $document->organization_id)
            ->where('document_id', $document->id)
            ->where('document_version_id', $document->current_primary_version_id)
            ->orderBy('id')
            ->get(['status', 'method', 'required_signature_kinds']);
        $statuses = $requests->pluck('status')->map(static fn (mixed $status): string => (string) $status)->all();
        $latestVerificationIds = $this->connection->table('legal_signature_verifications')
            ->selectRaw('MAX(id)')
            ->groupBy('signature_id');
        $verificationStatuses = $this->connection->table('legal_signature_verifications as verification')
            ->join('legal_document_signatures as signature', 'signature.id', '=', 'verification.signature_id')
            ->whereIn('verification.id', $latestVerificationIds)
            ->where('signature.organization_id', $document->organization_id)
            ->where('signature.document_id', $document->id)
            ->where('signature.document_version_id', $document->current_primary_version_id)
            ->pluck('verification.status')
            ->map(static fn (mixed $status): string => (string) $status)
            ->all();
        $hasCompleted = in_array('completed', $statuses, true);
        $hasPending = in_array('pending', $statuses, true);
        $requiredKinds = $requests->flatMap(static function (object $request): array {
            $value = is_string($request->required_signature_kinds)
                ? json_decode($request->required_signature_kinds, true, flags: JSON_THROW_ON_ERROR)
                : $request->required_signature_kinds;

            return is_array($value) ? $value : [];
        })->unique()->values()->all();
        $completedKinds = $requests->filter(static fn (object $request): bool => $request->status === 'completed')
            ->map(static fn (object $request): string => $request->method === 'paper' ? 'paper_original' : (string) $request->method)
            ->unique()->values()->all();
        $requirementsSatisfied = array_diff($requiredKinds, $completedKinds) === [];
        if ($requests->isEmpty()) {
            [$signatureStatus, $lifecycle] = ['not_signed', 'draft'];
        } elseif (in_array('revoked', $statuses, true) || in_array('revoked', $verificationStatuses, true)) {
            [$signatureStatus, $lifecycle] = ['revoked', 'signature_failed'];
        } elseif (array_intersect($statuses, ['failed', 'expired']) !== [] || in_array('failed', $verificationStatuses, true)) {
            [$signatureStatus, $lifecycle] = ['verification_failed', 'signature_failed'];
        } elseif (($hasPending && $hasCompleted) || (! $hasPending && $hasCompleted && ! $requirementsSatisfied)) {
            [$signatureStatus, $lifecycle] = ['partially_signed', 'partially_signed'];
        } elseif ($hasPending) {
            [$signatureStatus, $lifecycle] = ['pending', 'signing'];
        } else {
            [$signatureStatus, $lifecycle] = ['signed', 'signed'];
        }
        $hasElectronic = $requests->contains(static fn (object $request): bool => $request->status === 'completed' && $request->method !== 'paper');
        $hasPaper = $requests->contains(static fn (object $request): bool => $request->status === 'completed' && $request->method === 'paper');
        $document->forceFill([
            'signature_status' => $signatureStatus,
            'lifecycle_status' => $lifecycle,
            'legal_significance_status' => $hasElectronic
                ? 'edo_original'
                : ($hasPaper ? 'paper_original' : ($document->legal_significance_status ?? 'not_confirmed')),
            'lock_version' => ((int) $document->lock_version) + 1,
        ])->save();
    }
}
