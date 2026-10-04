<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Console\Commands;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagExpectedSourceProjection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class PruneRagProjectionsCommand extends Command
{
    protected $signature = 'ai-assistant:prune-rag-projections {--organization-id=} {--max-rows=100000}';

    protected $description = 'Удаляет устаревшие поколения RAG ограниченными пакетами, сохраняя активное поколение';

    public function handle(RagExpectedSourceProjection $projection): int
    {
        $maxRows = filter_var($this->option('max-rows'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        $organizationOption = $this->option('organization-id');
        $organizationId = $organizationOption === null ? null : filter_var($organizationOption, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($maxRows === false || $organizationId === false) {
            $this->error('Параметры organization-id и max-rows должны быть положительными числами; max-rows не больше 100000.');

            return self::INVALID;
        }

        $deadline = microtime(true) + 50;
        $deleted = 0;
        $skipped = 0;
        $processed = 0;
        if ($organizationId !== null) {
            $result = $projection->pruneOrganization($organizationId, $maxRows, $deadline);
            $deleted = $result['deleted'];
            $skipped = (int) $result['locked'];
            $processed = 1;
        } else {
            $cursorKey = 'ai-rag-projection-retention:organization-cursor';
            $cursor = (int) Cache::get($cursorKey, 0);
            while ($deleted < $maxRows && $processed < 1000 && microtime(true) < $deadline) {
                $organizations = DB::table('organizations')->where('id', '>', $cursor)->orderBy('id')->limit(100)->pluck('id');
                if ($organizations->isEmpty()) {
                    Cache::forever($cursorKey, 0);
                    break;
                }
                foreach ($organizations as $id) {
                    if ($deleted >= $maxRows || $processed >= 1000 || microtime(true) >= $deadline) {
                        break;
                    }
                    $result = $projection->pruneOrganization((int) $id, $maxRows - $deleted, $deadline);
                    $deleted += $result['deleted'];
                    $skipped += (int) $result['locked'];
                    $processed++;
                    $cursor = (int) $id;
                    Cache::forever($cursorKey, $cursor);
                }
            }
        }
        $this->line(json_encode(['deleted' => $deleted, 'skipped_locked' => $skipped, 'processed_organizations' => $processed], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
