<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Exceptions\DesignDrawingException;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignDrawingStroke;
use App\Models\User;
use App\Modules\Core\AccessController;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DesignDrawingStrokeService
{
    public function __construct(
        private DesignAccessService $access,
        private DesignIssueContextResolver $context,
        private AccessController $modules,
    ) {}

    public function changes(User $actor, int $organizationId, int $versionId, int $afterRevision = 0): array
    {
        $version = $this->version($actor, $organizationId, $versionId);
        $revision = (int) $version->drawing_revision;
        if ($afterRevision < 0 || $afterRevision > $revision) {
            throw new DesignDrawingException('design_drawings.errors.invalid_cursor', 409);
        }
        $items = $this->query($version)->where('revision', '>', $afterRevision)
            ->where('revision', '<=', $revision)->orderBy('revision')->limit(201)->get();
        $hasMore = $items->count() > 200;
        $items = $items->take(200);
        $canDelete = $this->access->canAccessProject($actor, $organizationId, (int) $version->project_id, 'design-management.review', true);

        return [
            'items' => $items->map(fn (DesignDrawingStroke $stroke): array => $this->present($stroke, $actor, $canDelete))->values()->all(),
            'revision' => $hasMore ? (int) $items->last()->revision : $revision,
            'has_more' => $hasMore,
            'can_annotate' => $canDelete,
        ];
    }

    public function store(User $actor, int $organizationId, int $versionId, array $payload): array
    {
        $version = $this->version($actor, $organizationId, $versionId, true);
        if (! is_string($payload['client_id'] ?? null) || ! Str::isUuid($payload['client_id'])
            || ! is_array($payload['annotations'] ?? null)
            || ! is_int($payload['annotations']['page_number'] ?? null)
            || ! is_array($payload['annotations']['strokes'] ?? null)
            || count($payload['annotations']['strokes'] ?? []) !== 1) {
            throw new DesignDrawingException('design_drawings.errors.invalid_stroke');
        }
        try {
            $context = $this->context->resolve($actor, $organizationId, (int) $version->project_id, [
                'version_id' => $versionId, 'annotations' => $payload['annotations'],
            ]);
        } catch (DomainException) {
            throw new DesignDrawingException('design_drawings.errors.invalid_stroke');
        }
        $annotations = $context['annotations'];
        $page = (int) $annotations['page_number'];
        if ((int) $version->page_count > 0 && $page > (int) $version->page_count) {
            throw new DesignDrawingException('design_drawings.errors.invalid_stroke');
        }
        $clientId = strtolower((string) $payload['client_id']);
        $fingerprint = hash('sha256', json_encode($annotations, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $version, $clientId, $fingerprint, $annotations, $page): array {
            $locked = DesignArtifactVersion::query()->where('organization_id', $version->organization_id)
                ->where('project_id', $version->project_id)->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $this->access->assertVersion($locked, $actor, 'design-management.review', strictProjectScope: true);
            $this->access->assertVersion($locked, $actor, 'design-management.documents.view', strictProjectScope: true);
            $existing = $this->query($locked)->where('client_id', $clientId)->first();
            if ($existing !== null) {
                if ((int) $existing->author_id !== (int) $actor->id
                    || ($existing->fingerprint !== null && $existing->fingerprint !== $fingerprint)) {
                    throw new DesignDrawingException('design_drawings.errors.conflict', 409);
                }

                return $this->present($existing, $actor, true);
            }
            $own = $this->query($locked)->where('author_id', $actor->id)->where('page_number', $page)->whereNull('deleted_at')->get(['annotations']);
            $points = count($annotations['strokes'][0]['points']);
            foreach ($own as $stroke) {
                $points += count($stroke->annotations['strokes'][0]['points']);
            }
            if ($own->count() >= 100 || $points > 10000) {
                throw new DesignDrawingException('design_drawings.errors.page_limit');
            }
            $revision = $this->advanceRevision($locked);
            $stroke = DesignDrawingStroke::query()->create([
                'organization_id' => $locked->organization_id, 'project_id' => $locked->project_id,
                'version_id' => $locked->id, 'client_id' => $clientId,
                'author_id' => $actor->id, 'author_name' => $actor->name,
                'page_number' => $page, 'annotations' => $annotations,
                'fingerprint' => $fingerprint, 'revision' => $revision,
            ]);

            return $this->present($stroke, $actor, true);
        });
    }

    public function delete(User $actor, int $organizationId, int $versionId, string $clientId): array
    {
        $version = $this->version($actor, $organizationId, $versionId, true);
        if (! Str::isUuid($clientId)) {
            throw new DesignDrawingException('design_drawings.errors.invalid_stroke');
        }

        return DB::transaction(function () use ($actor, $version, $clientId): array {
            $locked = DesignArtifactVersion::query()->where('organization_id', $version->organization_id)
                ->where('project_id', $version->project_id)->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $this->access->assertVersion($locked, $actor, 'design-management.review', strictProjectScope: true);
            $this->access->assertVersion($locked, $actor, 'design-management.documents.view', strictProjectScope: true);
            $stroke = $this->query($locked)->where('client_id', strtolower($clientId))->first();
            if ($stroke === null) {
                $revision = $this->advanceRevision($locked);
                $stroke = DesignDrawingStroke::query()->create([
                    'organization_id' => $locked->organization_id, 'project_id' => $locked->project_id,
                    'version_id' => $locked->id, 'client_id' => strtolower($clientId),
                    'author_id' => $actor->id, 'author_name' => $actor->name,
                    'revision' => $revision, 'deleted_at' => now(),
                ]);
            }
            if ((int) $stroke->author_id !== (int) $actor->id) {
                throw new DesignDrawingException('design_drawings.errors.not_found', 404);
            }
            if ($stroke->deleted_at === null) {
                $revision = $this->advanceRevision($locked);
                $stroke->forceFill(['revision' => $revision, 'deleted_at' => now()])->save();
            }

            return $this->present($stroke, $actor, true);
        });
    }

    private function version(User $actor, int $organizationId, int $versionId, bool $write = false): DesignArtifactVersion
    {
        foreach (['design-management', 'project-management', 'file-management'] as $module) {
            if (! $this->modules->hasModuleAccess($organizationId, $module)) {
                throw new DesignDrawingException('design_management.errors.module_not_active', 403);
            }
        }
        $version = DesignArtifactVersion::query()->where('organization_id', $organizationId)->find($versionId);
        if ($version === null) {
            throw new DomainException(trans_message('design_management.errors.version_not_found'));
        }
        $this->access->assertVersion($version, $actor, 'design-management.documents.view', strictProjectScope: true);
        if ((! in_array($version->file_format, ['pdf', 'pdfa'], true)
                && ! in_array($version->source_mime_type, ['image/png', 'image/jpeg', 'image/webp'], true))
            || ! str_starts_with((string) $version->source_file_path, 'org-'.$organizationId.'/')
            || ($write && ! $this->access->canAccessProject($actor, $organizationId, (int) $version->project_id, 'design-management.review', true))) {
            throw new DomainException(trans_message('design_management.errors.version_not_found'));
        }

        return $version;
    }

    private function query(DesignArtifactVersion $version): Builder
    {
        return DesignDrawingStroke::query()->where('organization_id', $version->organization_id)
            ->where('project_id', $version->project_id)->where('version_id', $version->id);
    }

    private function advanceRevision(DesignArtifactVersion $version): int
    {
        $revision = (int) $version->drawing_revision + 1;
        DB::table($version->getTable())->where('id', $version->id)->update(['drawing_revision' => $revision]);
        $version->setAttribute('drawing_revision', $revision);

        return $revision;
    }

    private function present(DesignDrawingStroke $stroke, User $actor, bool $canDelete): array
    {
        return [
            'client_id' => $stroke->client_id, 'version_id' => (int) $stroke->version_id,
            'revision' => (int) $stroke->revision, 'page_number' => $stroke->page_number === null ? null : (int) $stroke->page_number,
            'annotations' => $stroke->deleted_at === null ? $stroke->annotations : null,
            'author' => ['id' => $stroke->author_id === null ? null : (int) $stroke->author_id, 'name' => $stroke->author_name],
            'created_at' => $stroke->created_at?->toIso8601String(), 'deleted_at' => $stroke->deleted_at?->toIso8601String(),
            'can_delete' => $canDelete && (int) $stroke->author_id === (int) $actor->id && $stroke->deleted_at === null,
        ];
    }
}
