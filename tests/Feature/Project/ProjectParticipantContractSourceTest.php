<?php

declare(strict_types=1);

namespace Tests\Feature\Project;

use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\ProjectOrganizationRole;
use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Contract\ContractPartySnapshotService;
use App\Services\Project\ProjectCustomerResolverService;
use App\Services\Project\ProjectParticipantService;
use Tests\TestCase;

final class ProjectParticipantContractSourceTest extends TestCase
{
    public function test_project_labels_use_registered_participants_before_legacy_text(): void
    {
        $owner = Organization::factory()->create();
        $customer = Organization::factory()->create(['name' => 'Заказчик из реестра']);
        $designer = Organization::factory()->create(['name' => 'Проектировщик из реестра']);
        $project = Project::factory()->create([
            'organization_id' => $owner->id,
            'customer' => 'Старое название заказчика',
            'designer' => 'Старое название проектировщика',
        ]);
        $participants = app(ProjectParticipantService::class);
        $participants->attach($project, $customer->id, ProjectOrganizationRole::CUSTOMER);
        $participants->attach($project, $designer->id, ProjectOrganizationRole::DESIGNER);

        $resource = new \App\Http\Resources\Api\V1\Admin\Project\ProjectResource($project);
        $data = $resource->resolve();

        self::assertSame('Заказчик из реестра', $data['customer']);
        self::assertSame('Проектировщик из реестра', $data['designer']);
    }

    public function test_contract_uses_active_participant_instead_of_obsolete_project_customer(): void
    {
        $owner = Organization::factory()->create();
        $customer = Organization::factory()->create([
            'name' => 'Действующий заказчик',
            'tax_number' => '7702000002',
            'registration_number' => '325169000191393',
            'address' => 'Адрес заказчика',
            'email' => 'customer@example.com',
        ]);
        $obsolete = Counterparty::create([
            'organization_id' => $owner->id,
            'name' => 'Прежний заказчик',
            'roles' => ['customer'],
            'source' => 'manual',
            'is_active' => true,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $owner->id,
            'customer_counterparty_id' => $obsolete->id,
        ]);
        app(ProjectParticipantService::class)->attach($project, $customer->id, ProjectOrganizationRole::CUSTOMER);
        $contract = Contract::create([
            'organization_id' => $owner->id,
            'project_id' => $project->id,
            'contractor_id' => null,
            'contract_side_type' => ContractSideTypeEnum::GENERAL_CONTRACT,
            'number' => 'PARTICIPANT-SOURCE-1',
            'date' => '2026-09-14',
            'status' => 'draft',
            'total_amount' => 1000,
        ]);

        app(ContractPartySnapshotService::class)->syncParties($contract, true);

        $party = $contract->firstParty()->firstOrFail();
        self::assertSame($customer->id, $party->linked_organization_id);
        self::assertNull($party->counterparty_id);
        self::assertSame('Действующий заказчик', $party->name);
        self::assertSame('7702000002', $party->inn);
        self::assertNull($party->kpp);
        self::assertSame('325169000191393', $party->ogrn);
        self::assertSame('Адрес заказчика', $party->legal_address);
        self::assertSame('customer@example.com', $party->email);

        $replacement = Organization::factory()->create();
        $participants = app(ProjectParticipantService::class);
        $participants->setActiveState($project, $customer->id, false);
        $participants->attach($project, $replacement->id, ProjectOrganizationRole::CUSTOMER);

        self::assertSame($replacement->id, app(ProjectCustomerResolverService::class)->resolveOrganizationId($project));
        app(ContractPartySnapshotService::class)->syncParties($contract);
        self::assertSame($customer->id, $contract->firstParty()->firstOrFail()->linked_organization_id);
    }

    public function test_matching_legacy_counterparty_keeps_identity_but_uses_participant_requisites(): void
    {
        $owner = Organization::factory()->create();
        $customer = Organization::factory()->create([
            'name' => 'Заказчик из реестра',
            'tax_number' => '7702000002',
            'registration_number' => null,
        ]);
        $counterparty = Counterparty::create([
            'organization_id' => $owner->id,
            'linked_organization_id' => $customer->id,
            'name' => 'Прежнее название',
            'inn' => '7701000001',
            'kpp' => '770101001',
            'roles' => ['customer'],
            'source' => 'manual',
            'is_active' => true,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $owner->id,
            'customer_counterparty_id' => $counterparty->id,
        ]);
        app(ProjectParticipantService::class)->attach($project, $customer->id, ProjectOrganizationRole::CUSTOMER);

        $resolved = app(ProjectCustomerResolverService::class)->resolveLegalCustomer($project);

        self::assertSame($counterparty->id, $resolved['counterparty_id']);
        self::assertSame($customer->id, $resolved['linked_organization_id']);
        self::assertSame('Заказчик из реестра', $resolved['name']);
        self::assertSame('7702000002', $resolved['inn']);
        self::assertNull($resolved['kpp']);
    }

    public function test_deleted_customer_organization_is_not_resolved_as_active_participant(): void
    {
        $owner = Organization::factory()->create();
        $customer = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id, 'customer' => null]);
        app(ProjectParticipantService::class)->attach($project, $customer->id, ProjectOrganizationRole::CUSTOMER);
        $customer->delete();

        $resolved = app(ProjectCustomerResolverService::class)->resolveLegalCustomer($project);

        self::assertSame($owner->id, $resolved['linked_organization_id']);
        self::assertSame('project_owner', $resolved['source']);
    }
}
