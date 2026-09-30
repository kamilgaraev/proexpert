<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantToolArgumentValidator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class SearchAssistantDocumentsTool implements AIToolInterface
{
    public function __construct(
        private RagRetriever $retriever,
        private RagPromptContextBuilder $prompts,
        private AssistantDataAccessPolicy $access,
        private AIPermissionChecker $permissions,
        private AssistantToolArgumentValidator $arguments,
    ) {}

    public function getName(): string
    {
        return 'search_assistant_documents';
    }

    public function getDescription(): string
    {
        return trans_message('ai_assistant.document_search_description');
    }

    public function getParametersSchema(): array
    {
        $properties = [
            'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000],
            'project_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'source_types' => ['type' => ['array', 'null'], 'maxItems' => 20,
                'items' => ['type' => 'string', 'maxLength' => 128, 'pattern' => '^[a-zA-Z][a-zA-Z0-9_]*$']],
            'limit' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 20],
        ];
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        $execution = app()->bound(AssistantRequestExecutionContext::class) ? app(AssistantRequestExecutionContext::class) : null;
        $execution?->assertCanContinue();
        $this->arguments->validate($arguments, $this->getParametersSchema());
        if ($user === null || !$this->permissions->canUseAssistant($user, (int) $organization->id)) {
            throw new AuthorizationException;
        }
        if (($arguments['project_id'] ?? null) !== null && !$this->access->withCurrentChecks($user, (int) $organization->id,
            fn (): bool => $this->access->canReadEntity($user, (int) $organization->id, 'project', $arguments['project_id']), true)) {
            throw new AuthorizationException;
        }
        $context = array_filter(array_intersect_key($arguments, array_flip(['project_id', 'source_types', 'limit'])),
            static fn (mixed $value): bool => $value !== null);
        $search = fn (): array => $this->retriever->searchWithDiagnostics($arguments['query'], (int) $organization->id, $user, $context,
            $execution === null ? null : $execution->assertCanContinue(...),
            $execution === null ? null : fn (callable $read): mixed => $execution->withOperationBudget($read, 30_000, 'pgsql'),
            $execution === null ? null : fn (): int => $execution->remainingMilliseconds());
        $searchResult = $execution === null ? $search() : $execution->withDatabaseStatementTimeout($search);
        $execution?->assertCanContinue();
        return self::responseForSearch($this->prompts->build('', $searchResult['results']), $searchResult['diagnostics']);
    }

    public static function responseForSearch(array $documentContext, array $diagnostics): array
    {
        $available = ($diagnostics['status'] ?? null) === 'available';
        $partial = ($diagnostics['status'] ?? null) === 'partial' && ($documentContext['metadata']['sources'] ?? []) !== [];
        $status = $available ? 'success' : ($partial ? 'partial' : 'unavailable');
        $safeDiagnostics = ['semantic_available' => ($diagnostics['semantic_available'] ?? false) === true,
            'lexical_used' => ($diagnostics['lexical_used'] ?? false) === true];
        if (!$available) {
            $safeDiagnostics['error_code'] = 'rag_query_embedding_unavailable';
        }
        unset($documentContext['metadata']['query']);
        if ($status === 'unavailable') {
            return ['status' => $status, 'reason' => 'rag_search_unavailable', 'error' => trans_message('ai_assistant.document_search_unavailable'),
                'search_diagnostics' => $safeDiagnostics, 'source_refs' => [], 'fetched_at' => now()->toISOString()];
        }

        return ['status' => $status, 'document_context' => $documentContext['prompt'],
            'search_diagnostics' => $safeDiagnostics, 'search_notice' => $partial ? trans_message('ai_assistant.document_search_partial') : null,
            'rag_context' => $documentContext['metadata'], 'source_refs' => array_map(
                static fn (array $source): array => array_diff_key($source, array_flip(['excerpt', 'score', 'content', 'metadata'])),
                $documentContext['metadata']['sources']),
            'fetched_at' => now()->toISOString()];
    }
}
