<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalExport;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalVersion;
use App\BusinessModules\Features\CommercialProposals\Http\Resources\CommercialProposalExportResource;
use App\BusinessModules\Features\CommercialProposals\Services\CommercialProposalAmountAccess;
use App\BusinessModules\Features\CommercialProposals\Services\CommercialProposalExportService;
use App\BusinessModules\Features\CommercialProposals\Services\CommercialProposalService;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\AuthSessionStatus;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use App\Services\Modules\PackageCatalogService;
use App\Services\Storage\FileService;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use Smalot\PdfParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class CommercialProposalExportVisibilitySecurityTest extends TestCase
{
    public function test_detail_does_not_sign_legacy_exports_for_an_actor_without_amount_permission(): void
    {
        [$fixture, $actor, $proposal, $version] = $this->fixture();
        $export = $this->storedExport($proposal, $version);
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('temporaryUrl');
        $this->app->instance(FileService::class, $files);

        self::assertTrue(app(AuthorizationService::class)->canCurrent($actor, 'commercial_proposals.view', ['organization_id' => $fixture->organization->id]));
        self::assertFalse(app(AuthorizationService::class)->canCurrent($actor, 'commercial_proposals.amounts.view', ['organization_id' => $fixture->organization->id]));
        $this->getJson('/api/v1/admin/commercial-proposals/'.$proposal->id, $this->headers($actor))
            ->assertOk()
            ->assertJsonPath('data.amounts_visible', false)
            ->assertJsonPath('data.exports.0.id', $export->id)
            ->assertJsonPath('data.exports.0.download_url', null);
    }

    public function test_detail_and_status_sign_only_ready_trusted_hidden_documents_without_amount_permission(): void
    {
        [, $actor, $proposal, $version] = $this->fixture();
        $hiddenExport = $this->storedExport($proposal, $version);
        $hiddenExport->forceFill(['amounts_visible' => false])->save();
        $priced = $this->storedExport($proposal, $version);
        $priced->forceFill(['amounts_visible' => true])->save();
        $legacy = $this->storedExport($proposal, $version);
        $processing = $this->storedExport($proposal, $version);
        $processing->forceFill(['amounts_visible' => false, 'status' => 'processing'])->save();
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('temporaryUrl')->twice()->with($hiddenExport->storage_path, 60, Mockery::any())->andReturn('https://storage.example.test/hidden');
        $this->app->instance(FileService::class, $files);
        $headers = $this->headers($actor);
        $response = $this->getJson('/api/v1/admin/commercial-proposals/'.$proposal->id, $headers)->assertOk();
        $urls = collect($response->json('data.exports'))->pluck('download_url', 'id');
        self::assertSame('https://storage.example.test/hidden', $urls[$hiddenExport->id]);
        foreach ([$priced, $legacy, $processing] as $export) {
            self::assertNull($urls[$export->id]);
            $this->getJson('/api/v1/admin/commercial-proposals/'.$proposal->id.'/exports/'.$export->id, $headers)
                ->assertOk()->assertJsonPath('data.download_url', null);
        }
        $this->getJson('/api/v1/admin/commercial-proposals/'.$proposal->id.'/exports/'.$hiddenExport->id, $headers)
            ->assertOk()->assertJsonPath('data.download_url', 'https://storage.example.test/hidden');
    }

    public function test_amount_viewer_can_download_priced_and_legacy_exports_and_view_json_amounts(): void
    {
        [$fixture, $actor, $proposal, $version] = $this->fixture(true);
        $legacy = $this->storedExport($proposal, $version);
        $priced = $this->storedExport($proposal, $version);
        $priced->forceFill(['amounts_visible' => true])->save();
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('temporaryUrl')->twice()->andReturn('https://storage.example.test/priced');
        $this->app->instance(FileService::class, $files);
        self::assertTrue(app(AuthorizationService::class)->canCurrent($actor, 'commercial_proposals.amounts.view', ['organization_id' => $fixture->organization->id]));
        $response = $this->getJson('/api/v1/admin/commercial-proposals/'.$proposal->id, $this->headers($actor))
            ->assertOk()->assertJsonPath('data.amounts_visible', true)
            ->assertJsonPath('data.current_version.sections.0.line_items.0.unit_price', 7341.25);
        $urls = collect($response->json('data.exports'))->pluck('download_url', 'id');
        self::assertSame('https://storage.example.test/priced', $urls[$legacy->id]);
        self::assertSame('https://storage.example.test/priced', $urls[$priced->id]);
    }

    public static function amountViewers(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('amountViewers')]
    public function test_html_and_pdf_bytes_match_server_classification_despite_client_options(bool $amounts): void
    {
        [, $actor, $proposal] = $this->fixture($amounts, $amounts);
        $bytesByFormat = [];
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('putContent')->twice()->andReturnUsing(static function (string $bytes, string $directory, string $filename, string $visibility, $organization) use (&$bytesByFormat): string {
            self::assertSame('private', $visibility);
            $bytesByFormat[pathinfo($filename, PATHINFO_EXTENSION)] = $bytes;
            return 'org-'.$organization->id.'/'.$directory.'/'.$filename;
        });
        $files->shouldReceive('temporaryUrl')->andReturn('https://storage.example.test/generated');
        $this->app->instance(FileService::class, $files);
        $headers = $this->headers($actor);
        foreach (['html', 'pdf'] as $format) {
            $response = $this->postJson('/api/v1/admin/commercial-proposals/'.$proposal->id.'/exports', [
                'format' => $format, 'options' => ['amounts_visible' => ! $amounts],
            ], $headers)->assertCreated()->assertJsonPath('data.export.download_url', 'https://storage.example.test/generated');
            $export = CommercialProposalExport::query()->findOrFail($response->json('data.export.id'));
            self::assertSame($amounts, $export->amounts_visible);
            self::assertSame(! $amounts, $export->options['amounts_visible']);
        }
        self::assertStringStartsWith('%PDF-', $bytesByFormat['pdf']);
        $pdfText = (new Parser)->parseContent($bytesByFormat['pdf'])->getText();
        foreach ([$bytesByFormat['html'], $pdfText] as $content) {
            if ($amounts) {
                self::assertStringContainsString('7 341,25', $content);
                self::assertStringContainsString('14 682,50', $content);
            } else {
                self::assertStringNotContainsString('341', $content);
                self::assertStringNotContainsString('682', $content);
            }
        }
    }

    public function test_fresh_rights_are_checked_in_same_singleton_and_in_resource_after_revocation(): void
    {
        [$fixture, $actor, $proposal, $version] = $this->fixture(true);
        $export = $this->storedExport($proposal, $version);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('temporaryUrl')->once()->andReturn('https://storage.example.test/priced');
        $this->app->instance(FileService::class, $files);
        $this->actingAs($actor, 'api_admin');
        $service = app(CommercialProposalService::class);
        self::assertTrue($actor->can('commercial_proposals.amounts.view', ['organization_id' => $fixture->organization->id]));
        $signed = $service->exportStatus((int) $proposal->organization_id, $proposal->id, $export->id);
        self::assertNotNull($signed->getAttribute('download_url'));
        $assignment = UserRoleAssignment::query()->where('user_id', $actor->id)->firstOrFail();
        OrganizationCustomRole::query()->where('organization_id', $fixture->organization->id)->where('slug', $assignment->role_slug)
            ->update(['module_permissions' => json_encode(['commercial-proposals' => ['commercial_proposals.view', 'commercial_proposals.export']], JSON_THROW_ON_ERROR)]);
        self::assertFalse(app(AuthorizationService::class)->canCurrent($actor, 'commercial_proposals.amounts.view', ['organization_id' => $fixture->organization->id]));
        self::assertNull($service->exportStatus((int) $proposal->organization_id, $proposal->id, $export->id)->getAttribute('download_url'));
        $request = Request::create('/exports');
        $request->attributes->set('current_organization_id', $fixture->organization->id);
        $request->setUserResolver(static fn (): User => $actor);
        self::assertNull((new CommercialProposalExportResource($signed))->resolve($request)['download_url']);
        $preview = app(CommercialProposalExportService::class)->preview($proposal, null, true);
        self::assertFalse($preview['amounts_visible']);
        self::assertStringNotContainsString('341', $preview['html']);
        self::assertSame($service, app(CommercialProposalService::class));
    }

    public function test_membership_org_context_and_anonymous_access_do_not_sign_even_hidden_documents(): void
    {
        [$fixture, $actor, $proposal, $version] = $this->fixture(true);
        $export = $this->storedExport($proposal, $version);
        $export->forceFill(['amounts_visible' => false])->save();
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('temporaryUrl');
        $this->app->instance(FileService::class, $files);
        $this->actingAs($actor, 'api_admin');
        $service = app(CommercialProposalService::class);
        DB::table('organization_user')->where('user_id', $actor->id)->where('organization_id', $fixture->organization->id)->update(['is_active' => false]);
        self::assertNull($service->exportStatus((int) $proposal->organization_id, $proposal->id, $export->id)->getAttribute('download_url'));
        DB::table('organization_user')->where('user_id', $actor->id)->where('organization_id', $fixture->organization->id)->update(['is_active' => true]);
        DB::table('organizations')->where('id', $fixture->organization->id)->update(['is_active' => false]);
        self::assertNull($service->exportStatus((int) $proposal->organization_id, $proposal->id, $export->id)->getAttribute('download_url'));
        DB::table('organizations')->where('id', $fixture->organization->id)->update(['is_active' => true]);
        $actor->current_organization_id = $fixture->foreignOrganization->id;
        self::assertNull($service->exportStatus((int) $proposal->organization_id, $proposal->id, $export->id)->getAttribute('download_url'));
        auth('api_admin')->forgetUser();
        self::assertNull($service->exportStatus((int) $proposal->organization_id, $proposal->id, $export->id)->getAttribute('download_url'));
    }

    public function test_unknown_cache_is_regenerated_and_failed_regeneration_cannot_reclassify_old_bytes(): void
    {
        [, $actor, $proposal] = $this->fixture();
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('putContent')->times(3)->andReturn('org-hidden/first.html', false, 'org-hidden/retry.html');
        $this->app->instance(FileService::class, $files);
        $this->actingAs($actor, 'api_admin');
        $service = app(CommercialProposalExportService::class);
        $input = ['format' => 'html'];
        $export = $service->export($proposal, $input, true, $actor->id);
        self::assertFalse($export->amounts_visible);
        $export->forceFill(['amounts_visible' => null, 'storage_path' => 'legacy-priced.html'])->save();
        try {
            $service->export($proposal, $input, true, $actor->id);
            self::fail('Failed private write must reject export generation');
        } catch (\App\BusinessModules\Features\CommercialProposals\Exceptions\CommercialProposalWorkflowException) {
            $export->refresh();
            self::assertSame('failed', $export->status);
            self::assertNull($export->storage_path);
            self::assertNull($export->amounts_visible);
            self::assertFalse(app(CommercialProposalAmountAccess::class)->snapshot((int) $export->organization_id)->canDownload($export));
        }
        $retry = $service->export($proposal, $input, true, $actor->id);
        self::assertSame($export->id, $retry->id);
        self::assertFalse($retry->amounts_visible);
        self::assertSame('org-hidden/retry.html', $retry->storage_path);
        self::assertSame($retry->id, $service->export($proposal, $input, true, $actor->id)->id);
        $retry->forceFill(['status' => 'processing', 'storage_path' => null, 'amounts_visible' => null])->save();
        $inFlight = $service->export($proposal, $input, true, $actor->id);
        self::assertSame($retry->id, $inFlight->id);
        self::assertSame('processing', $inFlight->status);
        self::assertFalse(app(CommercialProposalAmountAccess::class)->snapshot((int) $inFlight->organization_id)->canDownload($inFlight));
    }

    public function test_amount_viewer_can_explicitly_generate_redacted_preview_and_export(): void
    {
        [, $actor, $proposal] = $this->fixture(true);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('putContent')->once()->andReturnUsing(static function (string $bytes): string {
            self::assertStringNotContainsString('341', $bytes);
            return 'explicit-hidden.html';
        });
        $this->app->instance(FileService::class, $files);
        $this->actingAs($actor, 'api_admin');
        $service = app(CommercialProposalExportService::class);
        self::assertFalse($service->preview($proposal, null, false)['amounts_visible']);
        self::assertFalse($service->export($proposal, ['format' => 'html'], false, $actor->id)->amounts_visible);
    }

    public function test_mutation_detail_does_not_sign_existing_priced_exports(): void
    {
        [, $actor, $proposal, $version] = $this->fixture();
        $this->storedExport($proposal, $version);
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('temporaryUrl');
        $this->app->instance(FileService::class, $files);
        $this->deleteJson('/api/v1/admin/commercial-proposals/'.$proposal->id, [], $this->headers($actor))
            ->assertOk()->assertJsonPath('data.exports.0.download_url', null);
    }

    public function test_list_query_count_stays_bounded_and_new_requests_recheck_amount_permission(): void
    {
        [$fixture, $actor, $proposal] = $this->fixture();
        $headers = $this->headers($actor);
        $uri = '/api/v1/admin/commercial-proposals?per_page=100';
        $one = $this->queryCount(fn () => $this->getJson($uri, $headers)->assertOk()->assertJsonCount(1, 'data'));
        for ($index = 1; $index < 20; $index++) {
            $copy = $proposal->replicate();
            $copy->forceFill(['number' => 'PERF-'.$index, 'current_version_id' => null])->save();
        }
        $many = $this->queryCount(fn () => $this->getJson($uri, $headers)->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.amounts_visible', false));
        self::assertLessThanOrEqual($one + 8, $many, 'Query count must not scale with each resource: '.$one.' -> '.$many);
        $assignment = UserRoleAssignment::query()->where('user_id', $actor->id)->firstOrFail();
        OrganizationCustomRole::query()->where('organization_id', $fixture->organization->id)->where('slug', $assignment->role_slug)
            ->update(['module_permissions' => json_encode(['commercial-proposals' => [
                'commercial_proposals.view', 'commercial_proposals.export', 'commercial_proposals.amounts.view',
            ]], JSON_THROW_ON_ERROR)]);
        $this->getJson($uri, $headers)->assertOk()->assertJsonPath('data.0.amounts_visible', true);
    }

    public function test_detail_query_count_does_not_scale_with_nested_versions_and_exports(): void
    {
        [, $actor, $proposal, $version] = $this->fixture(true);
        $this->storedExport($proposal, $version);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('temporaryUrl')->times(21)->andReturn('https://storage.example.test/priced');
        $this->app->instance(FileService::class, $files);
        $headers = $this->headers($actor);
        $uri = '/api/v1/admin/commercial-proposals/'.$proposal->id;
        $one = $this->queryCount(fn () => $this->getJson($uri, $headers)->assertOk()->assertJsonCount(1, 'data.exports'));
        for ($index = 2; $index <= 20; $index++) {
            $copy = $version->replicate();
            $copy->forceFill(['version_number' => $index])->save();
            $this->storedExport($proposal, $copy);
        }
        $many = $this->queryCount(fn () => $this->getJson($uri, $headers)->assertOk()
            ->assertJsonCount(20, 'data.versions')->assertJsonCount(20, 'data.exports'));
        self::assertLessThanOrEqual($one + 8, $many, 'Nested query count must stay bounded: '.$one.' -> '.$many);
    }

    private function queryCount(callable $request): int
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            $request();

            return count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }

    private function fixture(bool $amounts = false, bool $view = true): array
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $permissions = ['commercial_proposals.export', 'commercial_proposals.update', 'commercial_proposals.archive'];
        if ($view) {
            $permissions[] = 'commercial_proposals.view';
        }
        if ($amounts) {
            $permissions[] = 'commercial_proposals.amounts.view';
        }
        $actor = $fixture->addMember(['commercial-proposals' => $permissions]);
        $proposal = CommercialProposal::query()->create([
            'organization_id' => $fixture->organization->id,
            'number' => 'SEC-'.Str::uuid(),
            'title' => 'Предложение',
            'currency' => 'RUB',
        ]);
        $version = CommercialProposalVersion::query()->create([
            'organization_id' => $fixture->organization->id,
            'commercial_proposal_id' => $proposal->id,
            'version_number' => 1,
            'title' => 'Версия',
            'content_hash' => hash('sha256', $proposal->id),
            'sections_snapshot' => [['title' => 'Работы', 'line_items' => [
                ['title' => 'Монтаж', 'quantity' => 2, 'unit' => 'шт', 'unit_price' => 7341.25, 'total_amount' => 14682.50],
            ]]],
            'totals_snapshot' => ['total_amount' => 14682.50, 'currency' => 'RUB'],
        ]);
        $proposal->forceFill(['current_version_id' => $version->id])->save();

        return [$fixture, $actor, $proposal->fresh(), $version];
    }

    private function storedExport(CommercialProposal $proposal, CommercialProposalVersion $version): CommercialProposalExport
    {
        return CommercialProposalExport::query()->create([
            'organization_id' => $proposal->organization_id,
            'commercial_proposal_id' => $proposal->id,
            'commercial_proposal_version_id' => $version->id,
            'format' => 'html',
            'status' => 'ready',
            'content_hash' => hash('sha256', (string) Str::uuid()),
            'storage_path' => 'org-'.$proposal->organization_id.'/commercial-proposals/'.$proposal->id.'/priced.html',
            'generated_at' => now(),
            'options' => ['amounts_visible' => false],
        ]);
    }

    private function headers(User $actor): array
    {
        $sessionUuid = (string) Str::uuid();
        UserAuthSession::query()->create([
            'user_id' => $actor->id, 'organization_id' => $actor->current_organization_id,
            'session_uuid' => $sessionUuid, 'device_fingerprint' => hash('sha256', $sessionUuid),
            'device_name' => 'Security regression', 'ip_address' => '127.0.0.1',
            'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $tokens = app(WebAuthTokenService::class)->issue($actor, 'admin', $sessionUuid, (int) $actor->current_organization_id, false);

        return ['Authorization' => 'Bearer '.$tokens->accessToken, 'Accept' => 'application/json', 'Origin' => 'https://admin.1мост.рф'];
    }
}
