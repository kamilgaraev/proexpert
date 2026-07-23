<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Enums\Contract\ContractStatusEnum;
use App\Http\Resources\Api\V1\Admin\Contract\ContractResource;
use App\Models\Contract;
use App\Services\Contract\ContractSideResolverService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

final class ContractResourceLegalDocumentTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    public function test_it_exposes_the_linked_legal_document_identifier(): void
    {
        $resolver = Mockery::mock(ContractSideResolverService::class);
        $resolver->shouldReceive('resolveCustomerAlias')->once()->andReturn(null);
        $resolver->shouldReceive('resolve')->once()->andReturn([]);
        $this->app->instance(ContractSideResolverService::class, $resolver);

        $contract = new class extends Contract {
            public function usesEventSourcing(): bool
            {
                return false;
            }
        };
        $contract->setRawAttributes([
            'id' => 253,
            'organization_id' => 75,
            'number' => 'QA-LEGAL-20260721-08',
            'status' => ContractStatusEnum::DRAFT->value,
            'base_amount' => 1000,
            'total_amount' => 1000,
            'is_fixed_amount' => true,
            'legal_archive_document_id' => 941,
            'created_at' => '2026-07-21 08:00:00',
            'updated_at' => '2026-07-21 08:00:00',
        ], true);

        $payload = (new ContractResource($contract))->toArray(Request::create('/'));

        self::assertSame(941, $payload['legal_document_id']);
    }
}
