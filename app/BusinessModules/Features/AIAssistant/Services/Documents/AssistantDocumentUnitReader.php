<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\SearchAssistantDocumentsTool;
use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagSearchResult;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final readonly class AssistantDocumentUnitReader
{
    private const EXCERPT_CHARACTERS = 340;

    public function __construct(private AssistantDataAccessPolicy $policy, private RagPromptContextBuilder $prompts) {}

    public function read(User $actor, int $organizationId, array $arguments, ?callable $checkpoint = null): array
    {
        $checkpoint?->__invoke();
        return $this->policy->withCurrentChecks($actor, $organizationId,
            fn (): array => $this->readCurrent($actor, $organizationId, $arguments, $checkpoint), true, $checkpoint);
    }

    private function readCurrent(User $actor, int $organizationId, array $arguments, ?callable $checkpoint): array
    {
        if (! $this->policy->belongsToOrganization($actor, $organizationId)
            || (isset($arguments['source_types']) && ! in_array('file_document', $arguments['source_types'], true))) {
            throw new AuthorizationException;
        }
        $scope = $this->scope($actor, $organizationId, $arguments);
        $cursor = isset($arguments['cursor']) ? $this->decodeCursor($arguments['cursor'], $scope) : null;
        if ($cursor !== null && (($arguments['document_id'] ?? $cursor['document_id']) !== $cursor['document_id']
            || ($arguments['unit_id'] ?? $cursor['unit_id']) !== $cursor['unit_id']
            || ($arguments['text_offset'] ?? $cursor['offset']) !== $cursor['offset'])) { $this->invalid(); }
        $documentId = $cursor['document_id'] ?? $arguments['document_id'] ?? null;
        if (! is_int($documentId) || $documentId < 1) { $this->invalid(); }
        $checkpoint?->__invoke();
        $document = $this->policy->accessibleDocuments($actor, $organizationId)->whereKey($documentId)->first();
        if (! $document instanceof AIAssistantDocument || ! $this->policy->canReadSource($actor, $organizationId,
            ['source_type' => 'file_document', 'entity_type' => 'assistant_document', 'entity_id' => $documentId, 'project_id' => $document->project_id])) {
            throw new AuthorizationException;
        }
        if (isset($arguments['project_id']) && $document->project_id !== null && $document->project_id !== $arguments['project_id']) {
            throw new AuthorizationException;
        }
        if ($document->status !== AIAssistantDocument::STATUS_READY || ! is_string($document->checksum)
            || $document->processed_at === null || $document->updated_at === null) {
            return SearchAssistantDocumentsTool::responseForIncompleteCorpus();
        }
        $version = $document->updated_at->toAtomString();
        if ($cursor !== null && ($cursor['checksum'] !== $document->checksum || $cursor['version'] !== $version)) { $this->invalid(); }
        $unitId = $cursor['unit_id'] ?? $arguments['unit_id'] ?? null;
        $offset = $cursor['offset'] ?? $arguments['text_offset'] ?? 0;
        if (! is_int($offset) || $offset < 0 || $offset > 2_000_000) { $this->invalid(); }
        $units = AIAssistantDocumentUnit::query()->where('document_id', $documentId)->whereRaw("BTRIM(text) <> ''");
        $start = $unitId === null ? (clone $units)->orderBy('unit_index')->orderBy('id')->first(['id', 'unit_index', 'checksum'])
            : (clone $units)->whereKey($unitId)->first(['id', 'unit_index', 'checksum']);
        if (! $start instanceof AIAssistantDocumentUnit) { throw new AuthorizationException; }
        if ($cursor !== null && $cursor['unit_checksum'] !== $start->checksum) { $this->invalid(); }
        $limit = max(1, min((int) ($arguments['limit'] ?? 8), (int) config('ai-assistant.rag.max_chunks', 8), 20));
        $remaining = $limit * self::EXCERPT_CHARACTERS;
        $results = [];
        $next = null;
        $current = $start;
        for ($index = 0; $index < $limit; $index++) {
            $checkpoint?->__invoke();
            $unit = $this->readUnitFragment($units, (int) $current->id, $offset, min($remaining, self::EXCERPT_CHARACTERS));
            if (! $unit instanceof AIAssistantDocumentUnit || $offset > (int) $unit->getAttribute('total_characters')
                || $unit->checksum !== $unit->getAttribute('current_checksum')) { $this->invalid(); }
            $text = (string) $unit->getAttribute('excerpt');
            $length = mb_strlen($text);
            $documentTruncated = ! in_array($document->coverage_status, ['full', 'ocr'], true)
                || ($document->metadata['partial'] ?? false) !== false || ($document->metadata['truncated'] ?? false) !== false;
            $results[] = new RagSearchResult('file_document', 'assistant_document', (string) $documentId, $document->project_id,
                $document->filename, $text, 1.0, ['document_id' => $documentId, 'file_id' => $document->file_id,
                    'checksum' => $document->checksum, 'unit_id' => (int) $unit->id, 'unit_checksum' => $unit->checksum,
                    'unit_index' => $unit->unit_index, 'unit_type' => $unit->unit_type, 'provenance' => $unit->provenance,
                    'text_offset' => $offset, 'text_length' => $length, 'coverage_status' => $document->coverage_status,
                    'document_truncated' => $documentTruncated], $document->updated_at);
            $remaining -= $length;
            if ($offset + $length < (int) $unit->getAttribute('total_characters')) {
                $next = ['unit_id' => (int) $unit->id, 'unit_checksum' => $unit->checksum, 'offset' => $offset + $length];
                if ($remaining > 0 && $index + 1 < $limit) {
                    $current = $unit;
                    $offset += $length;
                    continue;
                }
                break;
            }
            $nextUnit = (clone $units)->where(static fn (Builder $query) => $query->where('unit_index', '>', $unit->unit_index)
                ->orWhere(static fn (Builder $tie) => $tie->where('unit_index', $unit->unit_index)->where('id', '>', $unit->id)))
                ->orderBy('unit_index')->orderBy('id')->first(['id', 'unit_index', 'checksum']);
            if (! $nextUnit instanceof AIAssistantDocumentUnit) { $next = null; break; }
            $next = ['unit_id' => (int) $nextUnit->id, 'unit_checksum' => $nextUnit->checksum, 'offset' => 0];
            if ($remaining === 0) { break; }
            $current = $nextUnit;
            $offset = 0;
        }
        $checkpoint?->__invoke();
        $response = SearchAssistantDocumentsTool::responseForSearch($this->prompts->build('', $results),
            ['status' => 'available', 'semantic_available' => false, 'lexical_used' => false]);
        $response['read_scope'] = 'document_units';
        $response['search_scope'] = 'document_units';
        $response['truncated'] = $next !== null;
        $response['document_truncated'] = $documentTruncated;
        $response['next_cursor'] = $next === null ? null : $this->encodeCursor($next + ['document_id' => $documentId,
            'checksum' => $document->checksum, 'version' => $version, 'expires_at' => now()->addMinutes(5)->getTimestamp()], $scope);
        return $response;
    }

    private function readUnitFragment(Builder $units, int $unitId, int $offset, int $length): ?AIAssistantDocumentUnit
    {
        return (clone $units)->whereKey($unitId)->select(['id', 'document_id', 'unit_index', 'unit_type', 'checksum', 'provenance'])
            ->selectRaw('CHAR_LENGTH(text) AS total_characters')
            ->selectRaw("encode(sha256(convert_to(text, 'UTF8')), 'hex') AS current_checksum")
            ->selectRaw('SUBSTRING(text, CAST(? AS integer), CAST(? AS integer)) AS excerpt', [$offset + 1, $length])->first();
    }

    public function encodeCursor(array $position, array $scope): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['position' => $position, 'scope' => $scope], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        return $payload.'.'.hash_hmac('sha256', $payload, $this->key());
    }

    public function decodeCursor(string $cursor, array $scope): array
    {
        $parts = explode('.', $cursor);
        if (count($parts) !== 2 || strlen($cursor) > 4096 || ! hash_equals(hash_hmac('sha256', $parts[0], $this->key()), $parts[1])) { $this->invalid(); }
        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $value = is_string($decoded) ? json_decode($decoded, true) : null;
        $position = is_array($value) ? ($value['position'] ?? null) : null;
        if (! is_array($position) || ($value['scope'] ?? null) !== $scope
            || ! is_int($position['expires_at'] ?? null) || $position['expires_at'] < now()->getTimestamp()
            || $position['expires_at'] > now()->addMinutes(5)->getTimestamp()) { $this->invalid(); }
        foreach (['document_id', 'unit_id', 'offset'] as $field) {
            if (! is_int($position[$field] ?? null) || $position[$field] < ($field === 'offset' ? 0 : 1)) { $this->invalid(); }
        }
        foreach (['checksum', 'unit_checksum', 'version'] as $field) {
            if (! is_string($position[$field] ?? null) || strlen($position[$field]) > 128) { $this->invalid(); }
        }
        return $position;
    }

    private function scope(User $actor, int $organizationId, array $arguments): array
    {
        $types = $arguments['source_types'] ?? null;
        if (is_array($types)) { sort($types); }
        return ['actor_id' => (int) $actor->id, 'organization_id' => $organizationId, 'project_id' => $arguments['project_id'] ?? null,
            'source_types' => $types, 'surface' => $this->policy->trustedSurface()?->value];
    }

    private function key(): string
    {
        $key = config('app.key');
        if (! is_string($key) || strlen($key) < 16) { $this->invalid(); }
        return $key;
    }

    private function invalid(): never
    {
        throw new RuntimeException(trans_message('ai_assistant.action_arguments_invalid'));
    }
}
