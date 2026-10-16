<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\Models\PersonalFile;
use App\Models\ReportFile;
use App\Services\Storage\FileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AssistantReportArtifactLifecycle
{
    public function __construct(private readonly FileService $files) {}

    public function delete(string $path, int $organizationId, int $userId): bool
    {
        if ($organizationId < 1 || $userId < 1 || str_contains($path, '..')
            || ! str_starts_with($path, 'org-'.$organizationId.'/personal-files/user-'.$userId.'/')) {
            return false;
        }

        return DB::transaction(function () use ($path, $organizationId, $userId): bool {
            $personal = PersonalFile::query()->where('organization_id', $organizationId)->where('user_id', $userId)
                ->where('storage_key', $path)->where('is_folder', false)->lockForUpdate()->first();
            $grants = Schema::hasTable('ai_assistant_report_access') ? DB::table('ai_assistant_report_access')
                ->where('organization_id', $organizationId)->where('user_id', $userId)->where('storage_path', $path) : null;
            if ($personal?->directory !== 'reports/assistant' && ! ($grants?->exists() ?? false)) {
                return false;
            }
            $this->files->deleteCurrent($path);
            ReportFile::query()->where('organization_id', $organizationId)->where('user_id', $userId)
                ->where('path', $path)->where('type', 'reports')->delete();
            $grants?->delete();
            $personal?->delete();

            return true;
        });
    }
}
