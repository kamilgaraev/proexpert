<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Validation\ValidationException;

/** Read-only resolution of opaque references; never infers a document from a client URL. */
final readonly class GetEvidenceSources
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization) {}

    public function handle(User $actor, int $projectId, int $sessionId, array $references): array
    {
        if (! array_is_list($references) || count($references) < 1 || count($references) > 50
            || count(array_filter($references, static fn ($ref): bool => is_string($ref)
                && preg_match('/\A[A-Za-z0-9:._-]{1,191}\z/', $ref) === 1)) !== count($references)) {
            throw ValidationException::withMessages(['references' => trans_message('estimate_generation.evidence_sources.invalid')]);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $references): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.view');
            $ids = [];
            foreach ($references as $reference) {
                if (preg_match('/\Aevidence:([1-9]\d{0,17})\z/', $reference, $match) === 1) {
                    $ids[] = (int) $match[1];
                }
            }
            $evidence = $this->database->table('estimate_generation_evidence')->where('organization_id', $session->organization_id)
                ->where('project_id', $projectId)->where('session_id', $sessionId)->whereNull('invalidated_at')
                ->whereIn('id', array_unique($ids))->get(['id', 'source_type', 'source_ref', 'source_version', 'locator'])->keyBy('id');
            $documentIds = [];
            foreach ($evidence as $row) {
                if (($id = $this->documentId((string) $row->source_ref)) !== null) {
                    $documentIds[] = $id;
                }
            }
            $documents = EstimateGenerationDocument::query()->where('organization_id', $session->organization_id)
                ->where('project_id', $projectId)->where('session_id', $sessionId)->whereIn('id', array_unique($documentIds))
                ->get(['id', 'filename', 'checksum_sha256', 'status', 'page_count'])->keyBy('id');
            $sources = [];
            foreach (array_values(array_unique($references)) as $reference) {
                $row = preg_match('/\Aevidence:([1-9]\d{0,17})\z/', $reference, $match) === 1 ? $evidence->get((int) $match[1]) : null;
                $source = ['reference' => $reference, 'status' => 'unavailable',
                    'label' => trans_message('estimate_generation.evidence_sources.unavailable'), 'target' => null];
                if ($row?->source_type === 'user_input') {
                    $source['status'] = 'user_input';
                    $source['label'] = trans_message('estimate_generation.evidence_sources.user_input');
                } elseif ($row !== null && ($id = $this->documentId((string) $row->source_ref)) !== null) {
                    $document = $documents->get($id);
                    if ($document !== null && ! in_array($document->status, ['ignored', 'failed'], true)
                        && preg_match('/\A[a-f0-9]{64}\z/', strtolower(trim((string) $document->checksum_sha256))) === 1
                        && hash_equals(DocumentSourceVersion::fromDocument($document), (string) $row->source_version)) {
                        $locator = is_string($row->locator) ? json_decode($row->locator, true, 32) : $row->locator;
                        $target = $this->target($id, DocumentSourceVersion::fromDocument($document), is_array($locator) ? $locator : [], (int) $document->page_count, (string) $row->source_ref);
                        if ($target !== null) {
                            $source = ['reference' => $reference, 'status' => 'available', 'label' => $document->filename, 'target' => $target];
                        }
                    }
                }
                $sources[] = $source;
            }

            return ['sources' => $sources, 'state_version' => (int) $session->state_version, 'cost_units' => '0'];
        }, 3);
    }

    private function documentId(string $reference): ?int
    {
        return preg_match('/\A(?:document:)?([1-9]\d{0,17})(?:\/page:[1-9]\d{0,8}\/region:(?:[1-9]\d{0,17}|[a-f0-9]{64}))?\z/', $reference, $match) === 1 ? (int) $match[1] : null;
    }

    private function target(int $documentId, string $version, array $locator, int $pageCount, string $sourceReference): ?array
    {
        if (isset($locator['document_id']) && $locator['document_id'] !== $documentId) {
            return null;
        }
        $page = $locator['page'] ?? $locator['page_number'] ?? $locator['unit_index'] ?? null;
        if (preg_match('/\/page:([1-9]\d{0,8})\/region:/', $sourceReference, $match) === 1) {
            if ($page !== null && $page !== (int) $match[1]) {
                return null;
            }
            $page = (int) $match[1];
        }
        $page = is_int($page) && $page > 0 && ($pageCount < 1 || $page <= $pageCount) ? $page : null;
        $native = $locator['native_reference'] ?? $locator['handle'] ?? $locator['element_key'] ?? null;
        $native = is_string($native) && trim($native) !== '' && strlen($native) <= 500 ? $native : null;
        $sheet = $locator['sheet'] ?? null;
        if ($sheet === null && $native !== null && preg_match('/\Axlsx:sheet:(.+)![A-Z]+[1-9]\d*\z/', $native, $match) === 1) {
            $sheet = $match[1];
        }
        $sheet = is_string($sheet) && trim($sheet) !== '' && mb_strlen($sheet) <= 128 ? $sheet : null;
        if ($page === null && $native === null && $sheet === null) {
            return null;
        }
        $region = $locator['region'] ?? null;
        if ($region === null && is_array($locator['bbox'] ?? null) && array_is_list($locator['bbox']) && count($locator['bbox']) === 4
            && count(array_filter($locator['bbox'], static fn ($value): bool => (is_int($value) || is_float($value)) && is_finite((float) $value))) === 4) {
            $box = $locator['bbox'];
            $region = ['x' => $box[0], 'y' => $box[1], 'width' => $box[2] - $box[0], 'height' => $box[3] - $box[1]];
        }
        $region = is_array($region) && count($region) === 4 && array_diff(array_keys($region), ['x', 'y', 'width', 'height']) === []
            && count(array_filter($region, static fn ($value): bool => (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 1000000)) === 4
            && $region['width'] > 0 && $region['height'] > 0 ? $region : null;

        return ['artifact_id' => $documentId, 'source_version' => $version, 'representation_kind' => $sheet !== null ? 'sheet' : ($native !== null ? 'native' : 'page'),
            'page' => $page, 'sheet' => $sheet, 'region' => $region, 'native_reference' => $native];
    }
}
