<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\ReportFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class ReportFileDownloadService
{
    public function __construct(private FileService $files, private AuthorizationService $authorization) {}

    public function resolveDownloadUrl(string $id, Organization $organization, User $actor): string
    {
        $file = ReportFile::query()->whereKey($id)->where('user_id', $actor->id)
            ->where(static function (Builder $query) use ($organization): void {
                $query->where('organization_id', $organization->id)->orWhereNull('organization_id');
            })->firstOrFail();
        if (! $this->authorization->canCurrent($actor, 'report_files.view', ['organization_id' => (int) $organization->id])) {
            throw new AccessDeniedHttpException;
        }
        $access = app(AssistantReportAccessService::class);
        if ($access->isAssistantReportPath((string) $file->path)) {
            return $access->urlForPath((string) $file->path, $actor);
        }
        $storage = $this->files->disk($organization);
        if (! $storage->exists($file->path)) {
            throw (new ModelNotFoundException)->setModel(ReportFile::class, [$id]);
        }

        return $storage->temporaryUrl($file->path, now()->addHours(1));
    }
}
