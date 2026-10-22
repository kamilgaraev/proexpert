<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateCustomReportTool;
use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantRequestUnderstanding;
use App\BusinessModules\Features\AIAssistant\Http\Controllers\AiReportsDownloadController;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportReference;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportRenderer;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSource;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportSourceHints;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantGeneratedReportStorage;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportFileService;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\BusinessModules\Features\Procurement\Http\Resources\PurchaseOrderResource;
use App\BusinessModules\Features\Procurement\Models\ExternalSupplierContact;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\SupplierParty;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use App\Services\Storage\PersonalFileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantPurchaseOrderReportsTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Queue::fake();
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->actingAs($this->fixture->owner);
        app(AssistantDataAccessPolicy::class)->setTrustedSurface(KnowledgeSurface::ADMIN);
    }

    public function test_source_exposes_supplier_name_for_purchase_order_control_with_real_authorization(): void
    {
        $fields = app(AssistantCustomReportSource::class)->resolve($this->fixture->owner, $this->fixture->organization->id, 'procurement', 'purchase_order', null)['fields'];
        self::assertArrayHasKey('supplier_name', $fields);
        self::assertSame('string', $fields['supplier_name']['type']);
        foreach (['id', 'order_number', 'status', 'order_date', 'delivery_date', 'total_amount', 'currency'] as $field) {
            self::assertArrayHasKey($field, $fields);
        }
        self::assertArrayNotHasKey('supplier_snapshot', $fields);
        self::assertArrayNotHasKey('notes', $fields);
    }

    public function test_two_control_sections_preserve_supplier_dates_amounts_and_all_organization_scope(): void
    {
        $supplier = $this->supplier('Текущий поставщик');
        $late = $this->order(['supplier_id' => $supplier->id, 'supplier_snapshot' => ['display_name' => ' Исторический поставщик ', 'email' => 'SECRET'],
            'delivery_date' => '2026-10-21', 'total_amount' => '1700.10']);
        $undated = $this->order(['supplier_party_id' => $this->party('Поставщик без срока')->id, 'total_amount' => '20.25', 'currency' => 'USD']);
        foreach (['draft', 'delivered', 'cancelled'] as $status) {
            $this->order(['status' => $status, 'delivery_date' => '2026-10-20']);
            $this->order(['status' => $status]);
        }
        $this->order(['delivery_date' => '2026-10-22']);
        $this->order(['organization_id' => $this->fixture->foreignOrganization->id, 'delivery_date' => '2026-10-20']);
        $common = array_map(static fn (string $status): array => ['field' => 'status', 'operator' => 'neq', 'value' => $status], ['draft', 'delivered', 'cancelled']);
        $report = app(AssistantCustomReportBuilder::class)->build(['title' => 'Контроль заказов', 'format' => 'xlsx', 'sections' => [
            $this->section(['filters' => [...$common, ['field' => 'delivery_date', 'operator' => 'lt', 'value' => '2026-10-22']]]),
            $this->section(['filters' => [...$common, ['field' => 'delivery_date', 'operator' => 'is_null', 'value' => null]]]),
        ]], $this->fixture->owner, $this->fixture->organization->id);
        self::assertSame([$late->id], array_column($report['sections'][0]['rows'], 0));
        self::assertSame([$undated->id], array_column($report['sections'][1]['rows'], 0));
        self::assertSame([$late->id, $late->order_number, 'Исторический поставщик', 'В доставке', '2026-10-01', '2026-10-21', '1700.10', 'RUB'], $report['sections'][0]['rows'][0]);
        self::assertSame([$undated->id, $undated->order_number, 'Поставщик без срока', 'В доставке', '2026-10-01', null, '20.25', 'USD'], $report['sections'][1]['rows'][0]);
        self::assertStringNotContainsString('SECRET', json_encode($report, JSON_THROW_ON_ERROR));
        foreach ($report['source_refs'] as $reference) {
            self::assertNull($reference['project_id']);
            self::assertTrue(app(AssistantCustomReportReference::class)->canReadReference($this->fixture->owner, $this->fixture->organization->id, $reference));
        }
    }

    public function test_snapshot_normalization_and_fallback_names_match_native_resource(): void
    {
        $party = $this->party(' Карточка поставщика ');
        $contact = $this->contact(' Внешний контакт ');
        $supplier = $this->supplier(' Справочник поставщиков ');
        $cases = [
            ['supplier_snapshot' => ['display_name' => "\t Снимок \n", 'name' => 'Второе имя']],
            ['supplier_snapshot' => ['display_name' => "\t \n", 'name' => ' Историческое имя ']],
            ['supplier_snapshot' => ['display_name' => 0]],
            ['supplier_snapshot' => ['display_name' => 1.0]],
            ['supplier_snapshot' => ['display_name' => 1.0e21]],
            ['supplier_snapshot' => ['display_name' => ['SECRET'], 'name' => false]],
            ['supplier_party_id' => null],
            ['supplier_party_id' => null, 'external_supplier_contact_id' => null],
            ['supplier_party_id' => null, 'external_supplier_contact_id' => null, 'supplier_id' => null],
        ];
        $expected = [];
        foreach ($cases as $overrides) {
            $order = $this->order(array_replace(['supplier_party_id' => $party->id, 'external_supplier_contact_id' => $contact->id, 'supplier_id' => $supplier->id], $overrides));
            $native = (new PurchaseOrderResource($order->load(['supplierParty', 'externalSupplierContact', 'supplier'])))->toArray(request());
            $expected[$order->id] = $native['supplier']['name'];
        }
        $report = $this->build($this->section(['columns' => ['id', 'supplier_name']]));
        foreach ($report['sections'][0]['rows'] as [$id, $name]) {
            self::assertSame($expected[$id], $name);
        }
        self::assertCount(count($cases), $report['sections'][0]['rows']);
    }

    public function test_snapshot_and_order_party_labels_remain_readable_without_private_supplier_catalog(): void
    {
        $supplier = $this->supplier('SECRET каталог');
        $snapshot = $this->order(['supplier_id' => $supplier->id, 'supplier_snapshot' => ['display_name' => 'Имя из заказа', 'tax_id' => 'SECRET']]);
        $party = $this->order(['supplier_party_id' => $this->party('Имя стороны заказа')->id]);
        $contact = $this->order(['external_supplier_contact_id' => $this->contact('Имя внешнего поставщика')->id]);
        $legacy = $this->order(['supplier_id' => $supplier->id]);
        $actor = $this->authorizedMember(finance: false, suppliers: false);
        $fields = app(AssistantCustomReportSource::class)->resolve($actor, $this->fixture->organization->id, 'procurement', 'purchase_order', null)['fields'];
        self::assertArrayNotHasKey('total_amount', $fields);
        $report = $this->build($this->section(['columns' => ['id', 'supplier_name']]), $actor);
        self::assertSame([[$snapshot->id, 'Имя из заказа'], [$party->id, 'Имя стороны заказа'], [$contact->id, 'Имя внешнего поставщика'], [$legacy->id, null]], $report['sections'][0]['rows']);
        self::assertStringNotContainsString('SECRET', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertTrue(app(AssistantCustomReportReference::class)->canReadReference($actor, $this->fixture->organization->id, $report['source_refs'][0]));
    }

    public function test_supplier_permission_revocation_invalidates_only_report_using_legacy_supplier_name(): void
    {
        $supplier = $this->supplier('Справочник');
        $legacy = $this->order(['supplier_id' => $supplier->id]);
        $snapshot = $this->order(['supplier_id' => $supplier->id, 'supplier_snapshot' => ['display_name' => 'Зафиксированное имя']]);
        $actor = $this->authorizedMember();
        $legacyRef = $this->build($this->section(['columns' => ['id', 'supplier_name'], 'filters' => [['field' => 'id', 'operator' => 'eq', 'value' => $legacy->id]]]), $actor)['source_refs'][0];
        $snapshotRef = $this->build($this->section(['columns' => ['id', 'supplier_name'], 'filters' => [['field' => 'id', 'operator' => 'eq', 'value' => $snapshot->id]]]), $actor)['source_refs'][0];
        $references = app(AssistantCustomReportReference::class);
        self::assertTrue($references->canReadReference($actor, $this->fixture->organization->id, $legacyRef));
        $permissions = $this->fixture->memberRole->fresh()->module_permissions;
        $permissions['catalog-management'] = [];
        $this->fixture->memberRole->update(['module_permissions' => $permissions]);
        self::assertFalse($references->canReadReference($actor, $this->fixture->organization->id, $legacyRef));
        self::assertTrue($references->canReadReference($actor, $this->fixture->organization->id, $snapshotRef));
    }

    public function test_foreign_supplier_links_keep_own_orders_without_revealing_names_or_private_links(): void
    {
        $foreign = $this->fixture->foreignOrganization->id;
        $this->order(['supplier_party_id' => $this->party('SECRET сторона', $foreign)->id]);
        $this->order(['external_supplier_contact_id' => $this->contact('SECRET контакт', $foreign)->id]);
        $this->order(['supplier_id' => $this->supplier('SECRET справочник', $foreign)->id]);
        $report = $this->build($this->section(['columns' => ['id', 'supplier_name']]));
        self::assertCount(3, $report['sections'][0]['rows']);
        self::assertSame([null, null, null], array_column($report['sections'][0]['rows'], 1));
        self::assertStringNotContainsString('SECRET', json_encode($report, JSON_THROW_ON_ERROR));
        foreach ($report['source_refs'][0]['_report_scope']['purchase_order_supplier_links'] as $link) {
            self::assertSame('unavailable', $link['kind']);
            self::assertNull($link['source_id']);
        }
    }

    public function test_deleted_or_relinked_supplier_sources_invalidate_existing_named_reference(): void
    {
        $contact = $this->contact('Внешний поставщик');
        $order = $this->order(['external_supplier_contact_id' => $contact->id]);
        $reference = $this->build($this->section(['columns' => ['id', 'supplier_name']]))['source_refs'][0];
        self::assertTrue(app(AssistantCustomReportReference::class)->canReadReference($this->fixture->owner, $this->fixture->organization->id, $reference));
        $contact->delete();
        self::assertFalse(app(AssistantCustomReportReference::class)->canReadReference($this->fixture->owner, $this->fixture->organization->id, $reference));
        $newContact = $this->contact('Другой поставщик');
        $order->update(['external_supplier_contact_id' => $newContact->id]);
        self::assertFalse(app(AssistantCustomReportReference::class)->canReadReference($this->fixture->owner, $this->fixture->organization->id, $reference));
    }

    public function test_signed_supplier_links_reject_malformed_proofs_and_preserve_legacy_id_only_report(): void
    {
        $this->order(['supplier_snapshot' => ['display_name' => 'Снимок']]);
        $reference = $this->build($this->section(['columns' => ['id', 'supplier_name']]))['source_refs'][0];
        $references = app(AssistantCustomReportReference::class);
        $scope = $reference['_report_scope'];
        $link = $scope['purchase_order_supplier_links'][0];
        foreach ([null, [], [$link, $link], [array_replace($link, ['order_id' => (string) $link['order_id']])],
            [array_replace($link, ['kind' => 'unknown'])], [array_replace($link, ['source_id' => 123])],
            [array_replace($link, ['order_id' => 999999999])]] as $links) {
            $badScope = array_replace($scope, ['purchase_order_supplier_links' => $links]);
            $bad = $references->create($this->fixture->owner, $this->fixture->organization->id, $badScope,
                $reference['checked_fields'], $reference['_report_ids'], $reference['source_version'], now()->toISOString());
            self::assertFalse($references->canReadReference($this->fixture->owner, $this->fixture->organization->id, $bad));
        }
        unset($scope['purchase_order_supplier_links']);
        $missing = $references->create($this->fixture->owner, $this->fixture->organization->id, $scope,
            $reference['checked_fields'], $reference['_report_ids'], $reference['source_version'], now()->toISOString());
        self::assertFalse($references->canReadReference($this->fixture->owner, $this->fixture->organization->id, $missing));
        $scope['plan']['columns'] = ['id'];
        $legacy = $references->create($this->fixture->owner, $this->fixture->organization->id, $scope,
            ['id'], $reference['_report_ids'], $reference['source_version'], now()->toISOString());
        self::assertTrue($references->canReadReference($this->fixture->owner, $this->fixture->organization->id, $legacy));
    }

    public function test_missing_order_permission_denies_source_despite_procurement_domain_access(): void
    {
        $actor = $this->authorizedMember();
        $permissions = $this->fixture->memberRole->fresh()->module_permissions;
        $permissions['procurement'] = ['procurement.view'];
        $this->fixture->memberRole->update(['module_permissions' => $permissions]);
        $this->expectException(AccessDeniedHttpException::class);
        app(AssistantCustomReportSource::class)->resolve($actor, $this->fixture->organization->id, 'procurement', 'purchase_order', null);
    }

    public function test_virtual_supplier_filter_and_currency_aggregation_use_batched_related_reads(): void
    {
        $party = $this->party('Группа поставщика');
        $first = $this->order(['supplier_party_id' => $party->id, 'total_amount' => '1.10']);
        $second = $this->order(['supplier_party_id' => $party->id, 'total_amount' => '2.20']);
        $third = $this->order(['supplier_party_id' => $party->id, 'total_amount' => '4.40', 'currency' => 'USD']);
        for ($index = 0; $index < 20; $index++) {
            $this->order(['supplier_party_id' => $party->id, 'supplier_snapshot' => ['display_name' => 'Другая группа']]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $report = $this->build($this->section(['columns' => ['supplier_name', 'currency'], 'group_by' => ['supplier_name', 'currency'],
                'filters' => [['field' => 'supplier_name', 'operator' => 'eq', 'value' => 'Группа поставщика']],
                'metrics' => [['field' => null, 'operation' => 'count', 'label' => 'Количество'], ['field' => 'total_amount', 'operation' => 'sum', 'label' => 'Сумма']],
                'order_by' => [['field' => 'currency', 'direction' => 'asc']]]));
            $partyQueries = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'from "supplier_parties"'));
            self::assertNotEmpty($partyQueries);
            self::assertLessThanOrEqual(2, count($partyQueries));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        self::assertSame(3, $report['sections'][0]['source_row_count']);
        self::assertSame([['Группа поставщика', 'RUB', 2, '3.30'], ['Группа поставщика', 'USD', 1, '4.40']], $report['sections'][0]['rows']);
        self::assertSame([(string) $first->id, (string) $second->id, (string) $third->id], $report['source_refs'][0]['_report_ids']);
    }

    public function test_purchase_order_source_hint_offers_supplier_without_false_request_fields_or_financial_access(): void
    {
        $understanding = AssistantRequestUnderstanding::fromArray(['primary_intent' => 'generate_report', 'output_format' => 'xlsx']);
        $query = 'Собери Excel для контроля заказов закупки: номер, поставщик, сумма и дата поставки.';
        $hints = app(AssistantCustomReportSourceHints::class)->forRequest($this->fixture->owner, $this->fixture->organization->id, $query, $understanding);
        $orderHints = array_values(array_filter($hints, static fn (array $hint): bool => $hint['entity_type'] === 'purchase_order'));
        self::assertCount(1, $orderHints);
        self::assertSame('string', $orderHints[0]['fields']['supplier_name']);
        self::assertSame('supplier_name', $orderHints[0]['preferred_fields']['supplier']);
        foreach (array_filter($hints, static fn (array $hint): bool => $hint['entity_type'] === 'purchase_request') as $requestHint) {
            self::assertArrayNotHasKey('supplier_name', $requestHint['fields']);
        }
        $actor = $this->authorizedMember(finance: false, suppliers: false);
        $restricted = app(AssistantCustomReportSourceHints::class)->forRequest($actor, $this->fixture->organization->id, $query, $understanding);
        $restrictedOrders = array_values(array_filter($restricted, static fn (array $hint): bool => $hint['entity_type'] === 'purchase_order'));
        self::assertCount(1, $restrictedOrders);
        self::assertArrayHasKey('supplier_name', $restrictedOrders[0]['fields']);
        self::assertArrayNotHasKey('total_amount', $restrictedOrders[0]['fields']);
        $permissions = $this->fixture->memberRole->fresh()->module_permissions;
        $permissions['procurement'] = [];
        $this->fixture->memberRole->update(['module_permissions' => $permissions]);
        $denied = app(AssistantCustomReportSourceHints::class)->forRequest($actor, $this->fixture->organization->id, $query, $understanding);
        self::assertSame([], array_values(array_filter($denied, static fn (array $hint): bool => $hint['entity_type'] === 'purchase_order')));
    }

    public function test_physical_filters_limit_loaded_orders_before_virtual_supplier_filter(): void
    {
        $party = $this->party('Проверенный поставщик');
        $wanted = $this->order(['supplier_party_id' => $party->id, 'delivery_date' => '2026-10-21']);
        $virtualMismatch = $this->order(['supplier_party_id' => $party->id, 'delivery_date' => '2026-10-21',
            'supplier_snapshot' => ['display_name' => 'Другой поставщик']]);
        for ($index = 0; $index < 25; $index++) {
            $this->order(['supplier_party_id' => $party->id, 'status' => 'delivered', 'delivery_date' => '2026-10-20']);
        }
        $this->order(['supplier_party_id' => $party->id, 'delivery_date' => '2026-10-22']);
        $retrieved = [];
        $dispatcher = Model::getEventDispatcher();
        Model::setEventDispatcher(clone $dispatcher);
        try {
            PurchaseOrder::retrieved(static function (PurchaseOrder $order) use (&$retrieved): void {
                $retrieved[] = $order->id;
            });
            $report = $this->build($this->section(['filters' => [
                ['field' => 'status', 'operator' => 'eq', 'value' => 'in_delivery'],
                ['field' => 'delivery_date', 'operator' => 'lt', 'value' => '2026-10-22'],
                ['field' => 'supplier_name', 'operator' => 'eq', 'value' => 'Проверенный поставщик'],
            ]]));
        } finally {
            Model::setEventDispatcher($dispatcher);
        }
        self::assertSame([$wanted->id], array_column($report['sections'][0]['rows'], 0));
        self::assertSame([$wanted->id, $virtualMismatch->id], $retrieved);
    }

    public function test_supplier_reference_survives_postgres_jsonb_storage_and_rechecks_catalog_access(): void
    {
        $actor = $this->authorizedMember();
        $this->order(['supplier_snapshot' => ['display_name' => 'Снимок поставщика']]);
        $this->order(['supplier_id' => $this->supplier('Справочник поставщиков')->id]);
        $reference = $this->build($this->section(['columns' => ['id', 'supplier_name']]), $actor)['source_refs'][0];
        $references = app(AssistantCustomReportReference::class);
        self::assertTrue($references->canReadReference($actor, $this->fixture->organization->id, $reference));
        $stored = DB::selectOne('SELECT CAST(? AS jsonb) AS payload', [json_encode([$reference], JSON_THROW_ON_ERROR)]);
        $persisted = json_decode($stored->payload, true, 512, JSON_THROW_ON_ERROR)[0];
        self::assertTrue($references->canReadReference($actor, $this->fixture->organization->id, $persisted));
        $permissions = $this->fixture->memberRole->fresh()->module_permissions;
        $permissions['catalog-management'] = [];
        $this->fixture->memberRole->update(['module_permissions' => $permissions]);
        self::assertFalse($references->canReadReference($actor, $this->fixture->organization->id, $persisted));
    }

    public function test_real_purchase_order_tool_publishes_two_sheet_artifact_and_rechecks_download_access(): void
    {
        $actor = $this->authorizedMember();
        $permissions = $this->fixture->memberRole->fresh()->module_permissions;
        $permissions['reports'] = ['reports.view'];
        $this->fixture->memberRole->update(['module_permissions' => $permissions]);
        $this->actingAs($actor);
        $organization = $this->fixture->organization;
        $this->order(['supplier_snapshot' => ['display_name' => 'Поставщик просроченного заказа'], 'delivery_date' => '2026-10-21']);
        $this->order(['supplier_party_id' => $this->party('Поставщик без срока')->id]);
        $bytes = '';
        $files = $this->createMock(FileService::class);
        $files->expects($this->once())->method('putPrivate')->willReturnCallback(static function (string $path, $stream, string $mime, string $hash) use (&$bytes): CurrentStoredFile {
            $bytes = stream_get_contents($stream);
            self::assertStringStartsWith('PK', $bytes);
            self::assertSame(hash('sha256', $bytes), $hash);

            return new CurrentStoredFile($path, 'etag', strlen($bytes), $hash, $mime);
        });
        $files->method('readCurrent')->willReturnCallback(static function () use (&$bytes) {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $bytes);
            rewind($stream);

            return $stream;
        });
        $common = array_map(static fn (string $status): array => ['field' => 'status', 'operator' => 'neq', 'value' => $status], ['draft', 'delivered', 'cancelled']);
        $arguments = ['title' => 'Контроль заказов закупки', 'format' => 'xlsx', 'sections' => [
            $this->section(['filters' => [...$common, ['field' => 'delivery_date', 'operator' => 'lt', 'value' => '2026-10-22']]]),
            $this->section(['filters' => [...$common, ['field' => 'delivery_date', 'operator' => 'is_null', 'value' => null]]]),
        ]];
        $tool = new GenerateCustomReportTool(app(AssistantCustomReportBuilder::class), new AssistantCustomReportRenderer,
            new AssistantGeneratedReportStorage(new PersonalFileService($files)), app(AIPermissionChecker::class));
        $result = $tool->execute($arguments, $actor, $organization);
        self::assertIsArray($result);
        self::assertSame('success', $result['status']);
        self::assertCount(2, $result['source_refs']);
        $artifacts = (new AssistantReportFileService($files))->artifactsFromToolResult('generate_custom_report', $result, $organization, $actor);
        self::assertCount(1, $artifacts);
        self::assertSame('Контроль заказов закупки.xlsx', $artifacts[0]['filename']);
        preg_match('#/reports/([^/]+)/download$#', $artifacts[0]['download_url'], $matches);
        $access = app(AssistantReportAccessService::class);
        $request = Request::create('/download');
        $request->setUserResolver(static fn () => $actor);
        $response = (new AiReportsDownloadController($files, $access))->download($request, $matches[1]);
        self::assertSame(AssistantCustomReportRenderer::MIME_TYPES['xlsx'], $response->headers->get('Content-Type'));
        ob_start();
        try {
            $response->sendContent();
            $downloaded = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertIsString($downloaded);
        self::assertSame(hash('sha256', $bytes), hash('sha256', $downloaded));
        $permissions = $this->fixture->memberRole->fresh()->module_permissions;
        $permissions['procurement'] = [];
        $this->fixture->memberRole->update(['module_permissions' => $permissions]);
        $this->expectException(AccessDeniedHttpException::class);
        $access->resolve($matches[1], $actor);
    }

    private function build(array $section, ?User $actor = null): array
    {
        return app(AssistantCustomReportBuilder::class)->build(['title' => 'Заказы закупки', 'format' => 'xlsx', 'sections' => [$section]],
            $actor ?? $this->fixture->owner, $this->fixture->organization->id);
    }

    private function section(array $overrides = []): array
    {
        return array_replace(['title' => 'Заказы', 'domain' => 'procurement', 'entity_type' => 'purchase_order', 'scope' => 'organization', 'project_id' => null,
            'columns' => ['id', 'order_number', 'supplier_name', 'status', 'order_date', 'delivery_date', 'total_amount', 'currency'],
            'filters' => [], 'group_by' => [], 'metrics' => [], 'order_by' => [['field' => 'id', 'direction' => 'asc']]], $overrides);
    }

    private function authorizedMember(bool $finance = true, bool $suppliers = true): User
    {
        $this->fixture->memberRole->update(['module_permissions' => [
            'ai-assistant' => ['ai_assistant.chat', 'ai_assistant.reports.generate', 'ai_assistant.analytics.view', 'ai_assistant.usage.view'],
            'procurement' => ['procurement.view', 'procurement.purchase_orders.view'],
            'payments' => $finance ? ['finance.view'] : [], 'catalog-management' => $suppliers ? ['suppliers.view'] : [],
        ], 'system_permissions' => ['projects.view']]);

        return $this->fixture->member;
    }

    private function order(array $overrides = []): PurchaseOrder
    {
        return Model::withoutEvents(fn () => PurchaseOrder::query()->create(array_replace([
            'organization_id' => $this->fixture->organization->id, 'order_number' => 'PO-'.bin2hex(random_bytes(8)), 'order_date' => '2026-10-01',
            'status' => 'in_delivery', 'delivery_date' => null, 'supplier_snapshot' => [], 'total_amount' => '10.00', 'currency' => 'RUB',
        ], $overrides)));
    }

    private function supplier(string $name, ?int $organizationId = null): Supplier
    {
        return Model::withoutEvents(fn () => Supplier::query()->create(['organization_id' => $organizationId ?? $this->fixture->organization->id,
            'name' => $name, 'code' => 'S-'.bin2hex(random_bytes(8)), 'email' => 'SECRET@example.test', 'is_active' => true]));
    }

    private function party(string $name, ?int $organizationId = null): SupplierParty
    {
        return Model::withoutEvents(fn () => SupplierParty::query()->create(['organization_id' => $organizationId ?? $this->fixture->organization->id,
            'type' => 'external', 'status' => 'draft', 'display_name' => $name, 'email' => 'SECRET@example.test']));
    }

    private function contact(string $name, ?int $organizationId = null): ExternalSupplierContact
    {
        return Model::withoutEvents(fn () => ExternalSupplierContact::query()->create(['organization_id' => $organizationId ?? $this->fixture->organization->id,
            'name' => $name, 'phone' => 'SECRET', 'email' => 'SECRET@example.test']));
    }
}
