<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\Tenders\Models\Tender;
use App\BusinessModules\Features\Tenders\Models\TenderFile;
use App\Models\File;
use App\Models\Project;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantDocumentTypeDiscoveryTest extends TestCase
{
    public function beginDatabaseTransaction(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-z0-9]+_testing$/i', DB::connection()->getDatabaseName());
    }

    private function fixture(): AssistantRealAuthorizationFixture
    {
        Queue::fake();
        config(['cache.default' => 'array']);

        return Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
    }

    private function file(AssistantRealAuthorizationFixture $fixture, Project $project, string $type = Project::class): File
    {
        return File::withoutEvents(fn () => File::query()->create([
            'organization_id' => $fixture->organization->id, 'user_id' => $fixture->owner->id,
            'fileable_type' => $type, 'fileable_id' => $project->id,
            'name' => 'coverage.txt', 'original_name' => 'coverage.txt',
            'path' => 'coverage-discovery/'.bin2hex(random_bytes(12)).'.txt', 'disk' => 's3', 'mime_type' => 'text/plain', 'size' => 7,
        ]));
    }

    private function read(AssistantRealAuthorizationFixture $fixture, User $actor, array $scope = [], string $mode = 'READ COMMITTED READ WRITE'): array
    {
        return DB::transaction(function () use ($fixture, $actor, $scope, $mode): array {
            if (! in_array($mode, ['READ COMMITTED READ WRITE', 'REPEATABLE READ READ WRITE', 'REPEATABLE READ READ ONLY', 'SERIALIZABLE READ ONLY'], true)) {
                throw new \InvalidArgumentException;
            }
            DB::statement('SET TRANSACTION ISOLATION LEVEL '.$mode);

            return app(AssistantDocumentCoverageService::class)->coverage((int) $fixture->organization->id, $actor, scope: $scope, countersOnly: true);
        });
    }

    private function watchTypeQueries(int &$heavyQueries): void
    {
        DB::listen(static function ($event) use (&$heavyQueries): void {
            if (preg_match('/select\s+distinct\s+"files"\."fileable_type"/i', $event->sql) === 1 && str_contains($event->sql, 'fileable_id')) {
                $heavyQueries++;
            }
        });
    }

    public function test_read_only_snapshot_reuses_discovery_and_preserves_populated_project_and_alias_scope(): void
    {
        $fixture = $this->fixture();
        $projects = Project::withoutEvents(fn () => Project::factory()->count(2)->create(['organization_id' => $fixture->organization->id]));
        $originalMap = Relation::morphMap();
        Relation::morphMap(['coverage_project_alias' => Project::class, 'coverage_unused_alias' => Project::class], false);
        try {
            $readyFile = $this->file($fixture, $projects[0]);
            $this->file($fixture, $projects[0], 'coverage_project_alias');
            $this->file($fixture, $projects[0], 'coverage_unused_alias');
            $this->file($fixture, $projects[1]);
            $this->file($fixture, $projects[0], 'Unknown\\CoverageParent');
            AIAssistantDocument::withoutEvents(fn () => AIAssistantDocument::query()->create([
                'organization_id' => $fixture->organization->id, 'project_id' => $projects[0]->id, 'file_id' => $readyFile->id,
                'parent_entity_type' => 'project', 'parent_entity_id' => (string) $projects[0]->id,
                'storage_path' => $readyFile->path, 'filename' => $readyFile->name, 'mime_type' => $readyFile->mime_type,
                'checksum' => hash('sha256', $readyFile->path), 'size_bytes' => 7, 'status' => 'ready', 'extracted_text' => 'Prepared text',
            ]));
            $policy = app(AssistantDataAccessPolicy::class);
            $publicQuery = $policy->accessibleFiles($fixture->owner, (int) $fixture->organization->id, false);
            $candidates = $policy->accessibleFilesWithTypes($fixture->owner, (int) $fixture->organization->id, false);
            self::assertSame($publicQuery->toSql(), $candidates['query']->toSql());
            self::assertSame($publicQuery->getBindings(), $candidates['query']->getBindings());
            self::assertContains('coverage_unused_alias', $candidates['types']);
            self::assertContains('Unknown\\CoverageParent', $candidates['types']);
            $heavyQueries = 0;
            $this->watchTypeQueries($heavyQueries);
            $baseline = $this->read($fixture, $fixture->owner);
            self::assertGreaterThan(0, $heavyQueries);
            self::assertSame(3, $baseline['document_coverage']['total']);
            self::assertSame(1, $baseline['document_coverage']['ready']);
            $heavyQueries = 0;
            self::assertSame($baseline, $this->read($fixture, $fixture->owner, mode: 'REPEATABLE READ READ ONLY'));
            self::assertSame(0, $heavyQueries);
            $scope = ['project_id' => (int) $projects[0]->id];
            $scoped = $this->read($fixture, $fixture->owner, $scope);
            self::assertSame(2, $scoped['document_coverage']['total']);
            self::assertSame(1, $scoped['document_coverage']['ready']);
            $scopedDiscoveryQueries = $heavyQueries;
            self::assertGreaterThanOrEqual(2, $scopedDiscoveryQueries);
            $heavyQueries = 0;
            self::assertSame($scoped, $this->read($fixture, $fixture->owner, $scope, 'SERIALIZABLE READ ONLY'));
            self::assertSame($scopedDiscoveryQueries - 1, $heavyQueries);
        } finally {
            Relation::morphMap($originalMap, false);
        }
    }

    public function test_mutable_snapshots_and_null_or_empty_candidates_keep_the_original_discovery(): void
    {
        $fixture = $this->fixture();
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $this->file($fixture, $project);
        $baseline = $this->read($fixture, $fixture->owner);
        self::assertSame(1, $baseline['document_coverage']['total']);
        $heavyQueries = 0;
        $this->watchTypeQueries($heavyQueries);
        self::assertSame($baseline, $this->read($fixture, $fixture->owner, mode: 'REPEATABLE READ READ WRITE'));
        self::assertGreaterThan(0, $heavyQueries);
        DB::transaction(function (): void {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $method = new \ReflectionMethod(AssistantDocumentCoverageService::class, 'canReuseFileTypes');
            self::assertFalse($method->invoke(app(AssistantDocumentCoverageService::class), File::query(), [null]));
        });
        $candidate = $this->file($fixture, $project, '');
        try {
            $heavyQueries = 0;
            self::assertSame($baseline, $this->read($fixture, $fixture->owner, mode: 'REPEATABLE READ READ ONLY'));
            self::assertGreaterThan(0, $heavyQueries);
        } finally {
            $candidate->deleteQuietly();
        }
    }

    public function test_empty_and_denied_scopes_keep_identical_counts(): void
    {
        $fixture = $this->fixture();
        $empty = $this->read($fixture, $fixture->owner);
        self::assertSame(0, $empty['document_coverage']['total']);
        self::assertSame($empty, $this->read($fixture, $fixture->owner, mode: 'REPEATABLE READ READ ONLY'));
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $this->file($fixture, $project);
        $denied = $this->read($fixture, $fixture->member);
        self::assertSame(0, $denied['document_coverage']['total']);
        self::assertSame($denied, $this->read($fixture, $fixture->member, mode: 'REPEATABLE READ READ ONLY'));
        $fixture->organization->users()->updateExistingPivot($fixture->owner->id, ['is_active' => false]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $this->read($fixture, $fixture->owner, mode: 'REPEATABLE READ READ ONLY');
    }

    public function test_native_only_files_preserve_current_counters(): void
    {
        $fixture = $this->fixture();
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        Model::withoutEvents(function () use ($fixture, $project): void {
            $tender = Tender::query()->create(['organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'number' => 'TYPE-DISCOVERY', 'title' => 'Tender']);
            TenderFile::query()->create(['tender_id' => $tender->id, 'category' => 'general', 'original_name' => 'native.pdf',
                'stored_path' => 'coverage-discovery/native.pdf', 'mime_type' => 'application/pdf', 'size' => 7, 'uploaded_at' => now()]);
        });
        $baseline = $this->read($fixture, $fixture->owner);
        self::assertSame(1, $baseline['document_coverage']['total']);
        self::assertSame($baseline, $this->read($fixture, $fixture->owner, mode: 'REPEATABLE READ READ ONLY'));
    }

    public function test_type_reuse_rejects_a_different_connection_even_in_a_read_only_snapshot(): void
    {
        $name = 'coverage_discovery_guard';
        $original = config('database.connections.'.$name);
        config(['database.connections.'.$name => DB::connection()->getConfig()]);
        try {
            $other = DB::connection($name);
            self::assertSame(DB::connection()->getDatabaseName(), $other->getDatabaseName());
            $other->transaction(function () use ($name, $other): void {
                $other->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                $files = (new File)->setConnection($name)->newQuery();
                $method = new \ReflectionMethod(AssistantDocumentCoverageService::class, 'canReuseFileTypes');
                self::assertFalse($method->invoke(app(AssistantDocumentCoverageService::class), $files, [Project::class]));
            });
        } finally {
            DB::purge($name);
            config(['database.connections.'.$name => $original]);
        }
    }
}
