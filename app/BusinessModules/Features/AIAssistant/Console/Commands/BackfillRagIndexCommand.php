<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Console\Commands;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use Illuminate\Console\Command;

class BackfillRagIndexCommand extends Command
{
    protected $signature = 'ai-assistant:rag-backfill
        {organization_id? : Organization ID for a single-tenant run}
        {--all : Queue indexing for all organizations}
        {--include-inactive : Include inactive organizations with --all}
        {--limit= : Limit organization count for --all}
        {--project_id= : Scope indexing to one project}
        {--source_type= : Scope indexing to one RAG source type}
        {--sync : Run synchronously}
        {--force : Allow synchronous --all runs}';

    protected $description = 'Backfill AI assistant RAG index for an organization.';

    public function handle(RagIndexingCoordinator $coordinator): int
    {
        $organizationId = $this->nullableIntArgument('organization_id');
        $projectId = $this->nullableIntOption('project_id');
        $sourceType = $this->nullableStringOption('source_type');
        $all = (bool) $this->option('all');
        $sync = (bool) $this->option('sync');

        if ($organizationId === null && ! $all) {
            $this->error('Provide organization_id or use --all.');

            return self::FAILURE;
        }

        if ($organizationId !== null && $all) {
            $this->error('Use either organization_id or --all, not both.');

            return self::FAILURE;
        }

        if ($sync && $all && ! (bool) $this->option('force')) {
            $this->error('Synchronous --all indexing requires --force.');

            return self::FAILURE;
        }

        if ($all) {
            if ($sync) {
                return $this->syncAll($coordinator, $projectId, $sourceType);
            }

            $result = $coordinator->queueAllActiveOrganizations(
                (bool) $this->option('include-inactive'),
                $this->nullableIntOption('limit'),
                $projectId,
                $sourceType
            );

            $this->info("Queued RAG indexing jobs: {$result['queued']}");

            return self::SUCCESS;
        }

        if ($organizationId === null) {
            $this->error('Organization ID is missing.');

            return self::FAILURE;
        }

        if ($sync) {
            $run = $coordinator->indexOrganizationSync($organizationId, $projectId, $sourceType);
            $this->info("Indexed RAG chunks: {$run->indexed_chunks}");
            $this->info("RAG index run: {$run->id}");

            return self::SUCCESS;
        }

        $run = $coordinator->queueOrganization($organizationId, $projectId, $sourceType);
        $this->info('Queued RAG indexing job.');
        $this->info("RAG index run: {$run->id}");

        return self::SUCCESS;
    }

    private function nullableIntArgument(string $key): ?int
    {
        $value = $this->argument($key);

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableIntOption(string $key): ?int
    {
        $value = $this->option($key);

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableStringOption(string $key): ?string
    {
        $value = $this->option($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function syncAll(RagIndexingCoordinator $coordinator, ?int $projectId, ?string $sourceType): int
    {
        $result = $coordinator->indexAllActiveOrganizationsSync(
            (bool) $this->option('include-inactive'),
            $this->nullableIntOption('limit'),
            $projectId,
            $sourceType
        );

        $this->info("Synchronously indexed organizations: {$result['processed']}");

        return self::SUCCESS;
    }
}
