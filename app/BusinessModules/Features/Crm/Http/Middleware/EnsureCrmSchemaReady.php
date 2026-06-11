<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Middleware;

use App\Http\Responses\AdminResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

use function trans_message;

final class EnsureCrmSchemaReady
{
    private const REQUIRED_TABLES = [
        'crm_sources',
        'crm_pipelines',
        'crm_pipeline_stages',
        'crm_companies',
        'crm_contacts',
        'crm_contact_points',
        'crm_contact_identities',
        'crm_leads',
        'crm_deals',
        'crm_activities',
        'crm_import_batches',
        'crm_import_rows',
        'crm_merge_events',
        'crm_timeline_events',
    ];

    private static bool $reportedMissingSchema = false;

    public function handle(Request $request, Closure $next): mixed
    {
        $missingTables = $this->missingTables();

        if ($missingTables === []) {
            return $next($request);
        }

        if (! self::$reportedMissingSchema) {
            Log::warning('crm.schema_not_ready', [
                'missing_tables' => $missingTables,
                'path' => $request->path(),
            ]);

            self::$reportedMissingSchema = true;
        }

        return AdminResponse::error(
            trans_message('crm.errors.schema_not_ready'),
            503,
            null,
            [
                'code' => 'CRM_SCHEMA_NOT_READY',
                'module' => 'crm',
                'status' => 'setup',
                'retryable' => true,
            ]
        );
    }

    private function missingTables(): array
    {
        $missingTables = [];

        foreach (self::REQUIRED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $missingTables[] = $table;
            }
        }

        return $missingTables;
    }
}
