<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Console\Commands;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use Illuminate\Console\Command;

class BackfillRagIndexCommand extends Command
{
    protected $signature = 'ai-assistant:rag-backfill {organization_id} {--project_id=} {--source_type=} {--sync}';

    protected $description = 'Backfill AI assistant RAG index for an organization.';

    public function handle(RagIndexer $indexer): int
    {
        $organizationId = (int) $this->argument('organization_id');
        $projectId = $this->nullableIntOption('project_id');
        $sourceType = $this->nullableStringOption('source_type');

        if ((bool) $this->option('sync')) {
            $indexed = $indexer->indexOrganization($organizationId, $projectId, $sourceType);
            $this->info("Indexed RAG chunks: {$indexed}");

            return self::SUCCESS;
        }

        dispatch(new IndexRagSourceJob($organizationId, $projectId, $sourceType));
        $this->info('Queued RAG indexing job.');

        return self::SUCCESS;
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
}
