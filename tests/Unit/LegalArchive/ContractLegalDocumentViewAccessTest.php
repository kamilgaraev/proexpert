<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\User;
use App\Services\Contract\ContractAccessService;
use App\Services\LegalArchive\Access\ContractLegalDocumentViewAccess;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\ContractLegalDocumentContext;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Mockery;
use PHPUnit\Framework\TestCase;

final class ContractLegalDocumentViewAccessTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_owner_and_active_customer_party_can_view_linked_internal_document(): void
    {
        $this->expectNotToPerformAssertions();
        foreach ([82, 83] as $organizationId) {
            [$access, $actor, $context] = $this->scenario($organizationId, true, true, true);

            $access->authorize($actor, $context, 99);
        }
    }

    public function test_other_organization_cannot_view_document_even_with_project_permission(): void
    {
        [$access, $actor, $context] = $this->scenario(84, true, true, false);

        $this->expectException(AuthorizationException::class);
        $access->authorize($actor, $context, 99);
    }

    public function test_project_assignment_is_required_for_contract_party(): void
    {
        [$access, $actor, $context] = $this->scenario(82, true, false, true);

        $this->expectException(AuthorizationException::class);
        $access->authorize($actor, $context, 99);
    }

    public function test_contract_permission_is_required_for_project_member(): void
    {
        [$access, $actor, $context] = $this->scenario(83, false, true, true);

        $this->expectException(AuthorizationException::class);
        $access->authorize($actor, $context, 99);
    }

    public function test_unlinked_document_cannot_use_contract_access(): void
    {
        [$access, $actor, $context] = $this->scenario(83, true, true, true);
        $context->document->id = 1758;

        $this->expectException(AuthorizationException::class);
        $access->authorize($actor, $context, 99);
    }

    public function test_restricted_document_still_requires_legal_archive_grant(): void
    {
        [$access, $actor, $context, $documents] = $this->scenario(83, true, true, true);
        $context->document->confidentiality_level = 'restricted';
        $documents->expects(self::once())->method('authorize')
            ->with($actor, $context->document, 'view')
            ->willThrowException(new AuthorizationException);

        $this->expectException(AuthorizationException::class);
        $access->authorize($actor, $context, 99);
    }

    private function scenario(int $organizationId, bool $permission, bool $project, bool $contract): array
    {
        $actor = new User;
        $actor->forceFill(['id' => 186, 'current_organization_id' => $organizationId]);
        $linkedContract = new Contract;
        $linkedContract->forceFill([
            'id' => 314,
            'organization_id' => 83,
            'project_id' => 99,
            'legal_archive_document_id' => 1757,
        ]);
        $document = new LegalArchiveDocument;
        $document->forceFill([
            'id' => 1757,
            'organization_id' => 83,
            'primary_project_id' => 99,
            'confidentiality_level' => 'internal',
        ]);
        $context = new ContractLegalDocumentContext($linkedContract, $document);

        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->with($actor, 'contracts.view', [
            'organization_id' => $organizationId,
            'project_id' => 99,
        ])->willReturn($permission);
        $accessibleProjects = Mockery::mock(Builder::class);
        $accessibleProjects->shouldReceive('whereKey')->with(99)->andReturnSelf();
        $accessibleProjects->shouldReceive('exists')->andReturn($project);
        $projects = $this->createMock(UserProjectAccessService::class);
        $projects->method('queryAccessibleProjects')->with($actor, $organizationId)->willReturn($accessibleProjects);
        $contracts = $this->createMock(ContractAccessService::class);
        $contracts->method('canAccess')->with($linkedContract, $organizationId, 99)->willReturn($contract);
        $documents = $this->createMock(LegalDocumentAuthorizer::class);

        return [
            new ContractLegalDocumentViewAccess($authorization, $contracts, $projects, $documents),
            $actor,
            $context,
            $documents,
        ];
    }
}
