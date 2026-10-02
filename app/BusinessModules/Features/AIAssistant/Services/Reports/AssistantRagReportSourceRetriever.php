<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagSearchResult;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

final readonly class AssistantRagReportSourceRetriever implements AssistantReportSourceRetrieverInterface
{
    public function __construct(
        private RagRetriever $ragRetriever,
        private AssistantDocumentCoverageService $coverageService
    ) {}

    /**
     * @param array<string, mixed> $requestContext
     * @return array<int, RagSearchResult>
     */
    public function search(string $query, int $organizationId, User $user, array $requestContext = []): array
    {
        $execution = app()->bound(AssistantRequestExecutionContext::class)
            ? app(AssistantRequestExecutionContext::class) : null;
        $coverage = fn (): array => $this->coverageService->coverage($organizationId, $user,
            $execution === null ? null : $execution->assertCanContinue(...),
            $execution === null ? null : fn (): int => $execution->remainingMilliseconds());

        try {
            $coverageResult = $execution === null ? $coverage() : $execution->withDatabaseStatementTimeout(
                fn (): array => $execution->withOperationBudget($coverage, 30_000, 'pgsql'));
        } catch (AssistantRequestCancelled|AssistantRequestDeadlineExceeded|AuthorizationException|AccessDeniedHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            return [];
        }

        if (! AssistantDocumentCoverageService::isCompleteForAnswer($coverageResult)) {
            return [];
        }

        try {
            $search = fn (): array => $this->ragRetriever->searchWithDiagnostics($query, $organizationId, $user, $requestContext,
                $execution === null ? null : $execution->assertCanContinue(...),
                $execution === null ? null : fn (callable $read): mixed => $execution->withOperationBudget($read, 30_000, 'pgsql'),
                $execution === null ? null : fn (): int => $execution->remainingMilliseconds());
            $searchResult = $execution === null ? $search() : $execution->withDatabaseStatementTimeout($search);
        } catch (AssistantRequestCancelled|AssistantRequestDeadlineExceeded|AuthorizationException|AccessDeniedHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            return [];
        }

        return ($searchResult['diagnostics']['status'] ?? null) === 'available'
            ? $searchResult['results'] : [];
    }
}
