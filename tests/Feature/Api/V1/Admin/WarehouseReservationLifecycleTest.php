<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Exceptions\WarehouseOperationIdempotencyConflictException;
use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\ReservationLifecycleService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseReservationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_search_finds_an_m8_number_beyond_the_first_page(): void
    {
        [$context, $material, $warehouse] = $this->warehouseContext(0);
        $this->allowAdminAccess();
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Северный корпус',
        ]);

        $target = AssetReservation::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'quantity' => 1,
            'project_id' => $project->id,
            'reserved_by' => $context->user->id,
            'status' => AssetReservation::STATUS_ACTIVE,
            'reserved_at' => now()->subDays(2),
            'expires_at' => now()->addDay(),
            'reason' => 'Монтаж силовой линии',
            'metadata' => ['document_number' => 'М8-2026-041'],
        ]);
        $distractorMaterial = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Песок строительный',
            'code' => 'ПЕСОК',
            'measurement_unit_id' => $material->measurement_unit_id,
            'additional_properties' => ['asset_type' => Asset::TYPE_MATERIAL],
            'is_active' => true,
        ]);

        foreach (range(1, 20) as $offset) {
            AssetReservation::query()->create([
                'organization_id' => $context->organization->id,
                'warehouse_id' => $warehouse->id,
                'material_id' => $distractorMaterial->id,
                'quantity' => 1,
                'reserved_by' => $context->user->id,
                'status' => AssetReservation::STATUS_ACTIVE,
                'reserved_at' => now()->subMinutes($offset),
                'expires_at' => now()->addDay(),
                'metadata' => ['document_number' => "М8-ДРУГОЙ-{$offset}"],
            ]);
        }

        [$foreignContext, $foreignMaterial, $foreignWarehouse] = $this->warehouseContext(0);
        AssetReservation::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'warehouse_id' => $foreignWarehouse->id,
            'material_id' => $foreignMaterial->id,
            'quantity' => 1,
            'reserved_by' => $foreignContext->user->id,
            'status' => AssetReservation::STATUS_ACTIVE,
            'reserved_at' => now(),
            'expires_at' => now()->addDay(),
            'metadata' => ['document_number' => 'М8-2026-041'],
        ]);

        $firstPage = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/advanced-warehouse/reservations?status=active&warehouse_id={$warehouse->id}")
            ->assertOk();
        self::assertNotContains(
            $target->id,
            collect($firstPage->json('data.data'))->pluck('id')->all(),
        );

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/advanced-warehouse/reservations?status=active&warehouse_id={$warehouse->id}&search=".urlencode('м8-2026-041'))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $target->id)
            ->assertJsonPath('data.data.0.document_number', 'М8-2026-041');

        foreach (['каб-ввг', 'северный корпус', 'силовой линии'] as $search) {
            $this->withHeaders($context->authHeaders())
                ->getJson("/api/v1/admin/advanced-warehouse/reservations?status=active&warehouse_id={$warehouse->id}&search=".urlencode($search))
                ->assertOk()
                ->assertJsonPath('data.data.0.id', $target->id);
        }
    }

    public function test_reservation_creation_persists_and_returns_the_business_m8_number(): void
    {
        [$context, $material, $warehouse] = $this->warehouseContext(10);
        WarehouseBalance::query()->update([
            'available_quantity' => 10,
            'reserved_quantity' => 0,
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/advanced-warehouse/reservations', [
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'quantity' => 2,
                'expires_hours' => 24,
                'document_number' => '  М8-2026-041  ',
            ]);

        $response->assertCreated();
        $reservation = AssetReservation::query()->latest('id')->firstOrFail();
        self::assertSame('М8-2026-041', $reservation->metadata['document_number']);
        $response->assertJsonPath('data.document_number', 'М8-2026-041');
        self::assertMatchesRegularExpression(
            '/(?:Z|[+-]\d{2}:\d{2})$/',
            (string) $response->json('data.expires_at'),
        );
        self::assertMatchesRegularExpression(
            '/(?:Z|[+-]\d{2}:\d{2})$/',
            (string) $response->json('data.created_at'),
        );

        $listResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advanced-warehouse/reservations?status=active')
            ->assertOk();
        self::assertMatchesRegularExpression(
            '/(?:Z|[+-]\d{2}:\d{2})$/',
            (string) $listResponse->json('data.data.0.expires_at'),
        );
        self::assertMatchesRegularExpression(
            '/(?:Z|[+-]\d{2}:\d{2})$/',
            (string) $listResponse->json('data.data.0.created_at'),
        );

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/advanced-warehouse/reservations', [
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'quantity' => 1,
                'document_number' => str_repeat('Н', 101),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document_number']);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/advanced-warehouse/reservations', [
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'quantity' => 1,
                'document_number' => ['unexpected' => 'value'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document_number']);
    }

    public function test_consumption_is_idempotent_and_tracks_the_selected_reservation(): void
    {
        [$context, $material, $warehouse] = $this->warehouseContext(10);
        $service = app(ReservationLifecycleService::class);

        $reservation = AssetReservation::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'quantity' => 10,
            'project_id' => null,
            'reserved_by' => $context->user->id,
            'status' => AssetReservation::STATUS_ACTIVE,
            'reserved_at' => now(),
            'expires_at' => now()->addDay(),
            'reason' => 'Лимит на смену',
            'metadata' => ['document_number' => 'ЛЗК-001'],
        ]);

        $first = $service->consume(
            $context->organization->id,
            $reservation->id,
            4,
            [
                'user_id' => $context->user->id,
                'document_number' => 'ТРЕБ-001',
                'reason' => 'Выдача первой части',
                'idempotency_key' => 'consume-reservation-001',
            ],
        );
        $retry = $service->consume(
            $context->organization->id,
            $reservation->id,
            4,
            [
                'user_id' => $context->user->id,
                'document_number' => 'ТРЕБ-001',
                'reason' => 'Выдача первой части',
                'idempotency_key' => 'consume-reservation-001',
            ],
        );

        self::assertSame($first->id, $retry->id);
        self::assertSame(WarehouseMovement::TYPE_RESERVED_ISSUE, $first->movement_type);
        self::assertSame($reservation->id, $first->metadata['asset_reservation_id']);
        self::assertSame('ТРЕБ-001', $first->document_number);
        self::assertSame(1, WarehouseMovement::query()
            ->where('metadata->asset_reservation_id', $reservation->id)
            ->count());
        self::assertSame(6.0, (float) WarehouseBalance::query()->firstOrFail()->reserved_quantity);
        self::assertSame(AssetReservation::STATUS_ACTIVE, $reservation->fresh()->status);

        $service->consume(
            $context->organization->id,
            $reservation->id,
            6,
            [
                'user_id' => $context->user->id,
                'document_number' => 'ТРЕБ-002',
                'reason' => 'Выдача остатка',
                'idempotency_key' => 'consume-reservation-002',
            ],
        );

        self::assertSame(0.0, (float) WarehouseBalance::query()->firstOrFail()->reserved_quantity);
        self::assertSame(AssetReservation::STATUS_FULFILLED, $reservation->fresh()->status);
        self::assertNotNull($reservation->fresh()->fulfilled_at);

        $this->expectException(WarehouseOperationIdempotencyConflictException::class);
        $service->consume(
            $context->organization->id,
            $reservation->id,
            1,
            [
                'user_id' => $context->user->id,
                'document_number' => 'ДРУГОЙ',
                'reason' => 'Конфликт',
                'idempotency_key' => 'consume-reservation-001',
            ],
        );
    }

    public function test_partial_consumption_releases_only_the_unused_reservation_balance(): void
    {
        [$context, $material, $warehouse] = $this->warehouseContext(10);
        $reservation = AssetReservation::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'quantity' => 10,
            'reserved_by' => $context->user->id,
            'status' => AssetReservation::STATUS_ACTIVE,
            'reserved_at' => now(),
            'expires_at' => now()->addDay(),
            'metadata' => [],
        ]);
        $service = app(ReservationLifecycleService::class);
        $service->consume($context->organization->id, $reservation->id, 4, [
            'user_id' => $context->user->id,
            'idempotency_key' => 'consume-before-release',
        ]);

        app(\App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService::class)
            ->unreserveAssets($reservation->id);

        $balance = WarehouseBalance::query()->firstOrFail();
        self::assertSame(6.0, (float) $balance->available_quantity);
        self::assertSame(0.0, (float) $balance->reserved_quantity);
        self::assertSame(AssetReservation::STATUS_CANCELLED, $reservation->fresh()->status);
    }

    public function test_owner_can_consume_reservation_through_api_and_see_used_and_remaining_quantities(): void
    {
        [$context, $material, $warehouse] = $this->warehouseContext(10);
        $this->allowAdminAccess();
        $reservation = AssetReservation::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'quantity' => 10,
            'reserved_by' => $context->user->id,
            'status' => AssetReservation::STATUS_ACTIVE,
            'reserved_at' => now(),
            'expires_at' => now()->addDay(),
            'metadata' => [],
        ]);
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'idempotency_key' => $idempotencyKey,
            'quantity' => 3,
            'document_number' => 'ТРЕБ-API-001',
            'reason' => 'Выдача на монтаж',
        ];

        $firstResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/advanced-warehouse/reservations/{$reservation->id}/consume", $payload)
            ->assertOk()
            ->assertJsonPath('success', true);
        $secondResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/advanced-warehouse/reservations/{$reservation->id}/consume", $payload)
            ->assertOk();

        self::assertSame($firstResponse->json('data.id'), $secondResponse->json('data.id'));
        self::assertSame(1, WarehouseMovement::query()
            ->where('metadata->asset_reservation_id', $reservation->id)
            ->count());

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advanced-warehouse/reservations')
            ->assertOk()
            ->assertJsonPath('data.data.0.consumed_quantity', 3)
            ->assertJsonPath('data.data.0.remaining_quantity', 7);
    }

    public function test_expired_reservation_releases_only_unused_quantity_once(): void
    {
        [$context, $material, $warehouse] = $this->warehouseContext(10);
        $reservation = AssetReservation::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'quantity' => 10,
            'reserved_by' => $context->user->id,
            'status' => AssetReservation::STATUS_ACTIVE,
            'reserved_at' => now()->subHours(2),
            'expires_at' => now()->subHour(),
            'metadata' => [],
        ]);
        WarehouseMovement::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_RESERVED_ISSUE,
            'quantity' => 4,
            'price' => 100,
            'metadata' => ['asset_reservation_id' => $reservation->id],
            'movement_date' => now()->subMinutes(90),
        ]);
        WarehouseBalance::query()->firstOrFail()->update(['reserved_quantity' => 6]);
        $service = app(ReservationLifecycleService::class);

        self::assertSame(1, $service->expireDue(100));
        self::assertSame(0, $service->expireDue(100));

        $balance = WarehouseBalance::query()->firstOrFail();
        self::assertSame(6.0, (float) $balance->available_quantity);
        self::assertSame(0.0, (float) $balance->reserved_quantity);
        self::assertSame(AssetReservation::STATUS_EXPIRED, $reservation->fresh()->status);
        self::assertNull($reservation->fresh()->cancelled_at);
    }

    public function test_expiry_reconciles_a_legacy_shortfall_without_releasing_another_active_reservation(): void
    {
        [$context, $material, $warehouse] = $this->warehouseContext(10);
        $expiredReservation = AssetReservation::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'quantity' => 8,
            'reserved_by' => $context->user->id,
            'status' => AssetReservation::STATUS_ACTIVE,
            'reserved_at' => now()->subHours(2),
            'expires_at' => now()->subHour(),
            'metadata' => ['source' => 'legacy'],
        ]);
        $activeReservation = AssetReservation::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'quantity' => 8,
            'reserved_by' => $context->user->id,
            'status' => AssetReservation::STATUS_ACTIVE,
            'reserved_at' => now(),
            'expires_at' => now()->addDay(),
            'metadata' => [],
        ]);

        $service = app(ReservationLifecycleService::class);

        self::assertSame(1, $service->expireDue(100));

        $balance = WarehouseBalance::query()->firstOrFail();
        $expiredReservation->refresh();
        self::assertSame(8.0, (float) $balance->reserved_quantity);
        self::assertSame(2.0, (float) $balance->available_quantity);
        self::assertSame(AssetReservation::STATUS_EXPIRED, $expiredReservation->status);
        self::assertSame(AssetReservation::STATUS_ACTIVE, $activeReservation->fresh()->status);
        self::assertSame(6.0, (float) data_get(
            $expiredReservation->metadata,
            'release_reconciliation.shortfall_quantity',
        ));
        self::assertSame(2.0, (float) data_get(
            $expiredReservation->metadata,
            'release_reconciliation.released_quantity',
        ));
    }

    public function test_m8_contains_only_issues_linked_to_the_selected_reservation_and_foreign_id_is_hidden(): void
    {
        Storage::fake('s3');
        [$context, $material, $warehouse] = $this->warehouseContext(10);
        $this->allowAdminAccess();
        $reservation = AssetReservation::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'quantity' => 10,
            'reserved_by' => $context->user->id,
            'status' => AssetReservation::STATUS_ACTIVE,
            'reserved_at' => now()->subHour(),
            'expires_at' => now()->addDay(),
            'metadata' => ['document_number' => 'ЛЗК-001'],
        ]);
        WarehouseMovement::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_RESERVED_ISSUE,
            'quantity' => 2,
            'price' => 100,
            'document_number' => 'ТРЕБ-001',
            'metadata' => ['asset_reservation_id' => $reservation->id],
            'movement_date' => now(),
        ]);
        WarehouseMovement::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_WRITE_OFF,
            'quantity' => 7,
            'price' => 100,
            'document_number' => 'ЧУЖОЕ-СПИСАНИЕ',
            'metadata' => [],
            'movement_date' => now(),
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/advanced-warehouse/reservations/{$reservation->id}/export-m8")
            ->assertOk();
        $path = ltrim((string) parse_url((string) $response->json('data.url'), PHP_URL_PATH), '/');
        $temporaryPath = tempnam(sys_get_temp_dir(), 'm8_lifecycle_').'.xlsx';
        file_put_contents($temporaryPath, Storage::disk('s3')->get(rawurldecode($path)));
        $sheet = IOFactory::load($temporaryPath)->getActiveSheet();

        self::assertSame('ТРЕБ-001', $sheet->getCell('B16')->getValue());
        self::assertSame(2.0, (float) $sheet->getCell('E16')->getValue());
        self::assertSame(8.0, (float) $sheet->getCell('G16')->getValue());
        self::assertStringNotContainsString('ЧУЖОЕ-СПИСАНИЕ', json_encode($sheet->toArray(), JSON_THROW_ON_ERROR));
        @unlink($temporaryPath);

        [$foreignContext, $foreignMaterial, $foreignWarehouse] = $this->warehouseContext(1);
        $foreignReservation = AssetReservation::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'warehouse_id' => $foreignWarehouse->id,
            'material_id' => $foreignMaterial->id,
            'quantity' => 1,
            'reserved_by' => $foreignContext->user->id,
            'status' => AssetReservation::STATUS_ACTIVE,
            'reserved_at' => now(),
            'expires_at' => now()->addDay(),
            'metadata' => [],
        ]);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/advanced-warehouse/reservations/{$foreignReservation->id}/export-m8")
            ->assertNotFound();
    }

    private function warehouseContext(float $reservedQuantity): array
    {
        $context = AdminApiTestContext::create();
        $unit = MeasurementUnit::query()
            ->where('organization_id', $context->organization->id)
            ->firstOrFail();
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Кабель ВВГ',
            'code' => 'КАБ-ВВГ',
            'measurement_unit_id' => $unit->id,
            'additional_properties' => ['asset_type' => Asset::TYPE_MATERIAL],
            'is_active' => true,
        ]);
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Центральный склад',
            'code' => 'CENTRAL-'.bin2hex(random_bytes(3)),
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 0,
            'reserved_quantity' => $reservedQuantity,
            'average_price' => 100,
            'unit_price' => 100,
        ]);

        return [$context, $material, $warehouse];
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }
}
