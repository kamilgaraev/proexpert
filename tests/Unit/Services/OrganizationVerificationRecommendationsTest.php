<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Organization;
use App\Services\DaDataService;
use App\Services\Logging\LoggingService;
use App\Services\OrganizationVerificationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Mockery;

final class OrganizationVerificationRecommendationsTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_complete_fields_do_not_prove_verification_even_with_a_stored_verified_status(): void
    {
        $organization = $this->organization();
        $organization->verification_status = 'verified';
        $result = $this->service()->getVerificationRecommendations($organization);

        self::assertSame(100, $result['current_score']);
        self::assertSame('pending', $result['status']);
        self::assertTrue($result['needs_verification']);
        self::assertSame('warning', $this->service()->getUserFriendlyMessage($organization)['type']);
    }

    public function test_successful_results_override_a_historical_pending_status(): void
    {
        $organization = $this->organization();
        $organization->verification_status = 'pending';
        $organization->verification_data = [
            'score' => 100,
            'inn_verification' => ['success' => true],
            'address_verification' => ['success' => true],
            'errors' => [],
            'warnings' => [],
        ];
        $result = $this->service()->getVerificationRecommendations($organization);

        self::assertSame('verified', $result['status']);
        self::assertFalse($result['needs_verification']);
        self::assertSame([], $result['verification_issues']);
        $message = $this->service()->getUserFriendlyMessage($organization);
        self::assertSame('success', $message['type']);
        self::assertSame('Проверка организации завершена', $message['title']);
    }

    public function test_failed_address_check_cannot_be_hidden_by_a_stored_full_score(): void
    {
        $organization = $this->organization();
        $organization->verification_data = [
            'score' => 100,
            'inn_verification' => ['success' => true],
            'address_verification' => ['success' => false],
            'warnings' => ['Адрес не подтверждён'],
        ];
        $result = $this->service()->getVerificationRecommendations($organization);

        self::assertSame(70, $result['current_score']);
        self::assertSame('partially_verified', $result['status']);
        self::assertNotSame('success', $this->service()->getUserFriendlyMessage($organization)['type']);
    }

    public function test_verification_persists_success_and_canonical_results_once(): void
    {
        $organization = Mockery::mock(Organization::class)->makePartial();
        $organization->forceFill($this->organization()->getAttributes());
        $organization->shouldReceive('update')->once()->andReturnUsing(function (array $attributes) use ($organization): bool {
            self::assertTrue($attributes['is_verified']);
            self::assertSame('verified', $attributes['verification_status']);
            self::assertNotNull($attributes['verified_at']);
            self::assertIsArray($attributes['verification_data']);
            $organization->forceFill($attributes);

            return true;
        });
        $dadata = Mockery::mock(DaDataService::class);
        $dadata->shouldReceive('verifyOrganizationByInn')->once()->with('6658522310')->andReturn(['success' => true, 'data' => []]);
        $dadata->shouldReceive('cleanAddress')->once()->with($organization->address)->andReturn(['success' => true, 'data' => []]);
        $logging = Mockery::mock(LoggingService::class)->shouldIgnoreMissing();
        $service = new OrganizationVerificationService($dadata, $logging);
        $result = $service->verifyOrganization($organization);

        self::assertSame('verified', $result['overall_status']);
        self::assertSame(100, $result['verification_score']);
        self::assertIsArray(json_decode($organization->getAttributes()['verification_data'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('verified', $service->getVerificationRecommendations($organization)['status']);
    }

    public function test_company_resource_and_recommendations_expose_the_same_result(): void
    {
        $service = $this->service();
        $this->app->instance(OrganizationVerificationService::class, $service);
        $organization = $this->organization();
        $organization->forceFill(['verification_status' => 'verified', 'is_verified' => true, 'verified_at' => now()]);
        $resource = new \App\Http\Resources\Api\V1\Landing\Organization\OrganizationResource($organization);
        $response = $resource->toArray(\Illuminate\Http\Request::create('/'));
        $recommendations = $service->getVerificationRecommendations($organization);

        self::assertSame($recommendations['status'], $response['verification']['verification_status']);
        self::assertSame($recommendations['current_score'], $response['verification']['verification_score']);
        self::assertFalse($response['verification']['is_verified']);
        self::assertNull($response['verification']['verified_at']);
    }

    private function organization(): Organization
    {
        return new Organization([
            'tax_number' => '6658522310',
            'address' => 'Казань, улица Строителей, 1',
            'legal_name' => 'Тестовая организация',
            'registration_number' => '1196658003277',
            'city' => 'Казань',
            'postal_code' => '420021',
        ]);
    }

    private function service(): OrganizationVerificationService
    {
        return new OrganizationVerificationService(
            Mockery::mock(DaDataService::class),
            Mockery::mock(LoggingService::class),
        );
    }
}
