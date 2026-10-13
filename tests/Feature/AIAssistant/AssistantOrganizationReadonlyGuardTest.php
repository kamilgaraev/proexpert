<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantContractSupplementaryReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationActivityReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationNotificationsReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationProjectsReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationSuppliersReader;
use App\Models\Contract;
use App\Models\Project;
use App\Services\Contract\ContractOrganizationViewService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantOrganizationReadonlyGuardTest extends TestCase
{
    public function test_five_native_readers_work_in_readonly_snapshot_under_active_statement_timeout_guard(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $default = DB::getDefaultConnection(); $alias = 'assistant_organization_guard';
        config(['database.connections.'.$alias => config('database.connections.'.$default)]); DB::setDefaultConnection($alias);
        $context = null;
        try {
            $f = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
            $org = (int) $f->organization->id; $actor = $f->owner;
            $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org, 'name' => 'Объект проверки']));
            $contract = Contract::withoutEvents(fn () => Contract::query()->create(['organization_id' => $org, 'project_id' => $project->id,
                'number' => 'GUARD-52', 'date' => today(), 'status' => 'draft', 'total_amount' => 100, 'is_self_execution' => true]));
            Model::withoutEvents(function () use ($contract, $f): void {
                $contract->parties()->create(['side' => 'first', 'role' => 'contractor', 'linked_organization_id' => $f->organization->id, 'name' => 'Участник', 'snapshot' => []]);
                $contract->parties()->create(['side' => 'second', 'role' => 'subcontractor', 'linked_organization_id' => $f->foreignOrganization->id, 'name' => 'Контрагент', 'snapshot' => []]);
                app(ContractOrganizationViewService::class)->synchronizeNewContract($contract);
            });
            self::assertSame(0, DB::transactionLevel());
            $lifecycle = (new \ReflectionClass(AssistantRequestLifecycle::class))->newInstanceWithoutConstructor();
            $context = new AssistantRequestExecutionContext($lifecycle, new AssistantRequest, $actor, 30000);
            app()->instance(AssistantRequestExecutionContext::class, $context); $context->activate();
            DB::transaction(function () use ($org, $actor): void {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                foreach ([AssistantOrganizationProjectsReader::class, AssistantOrganizationSuppliersReader::class,
                    AssistantOrganizationActivityReader::class, AssistantOrganizationNotificationsReader::class, AssistantContractSupplementaryReader::class] as $class) {
                    $reader = app($class); $args = $class === AssistantContractSupplementaryReader::class
                        ? ['contract_number' => 'GUARD-52', 'project_id' => null, 'project_query' => 'Объект проверки'] : ['scope' => 'organization'];
                    $result = $reader->read($actor, $org, $args);
                    self::assertNotEmpty($result['server_formatted_answer']);
                    self::assertTrue($reader->matchesReference($actor, $org, $result['source_refs'][0]), $class);
                }
            });
            self::assertSame(0, DB::transactionLevel());
            self::assertSame(0, DB::table('contract_supplementary_documents')->count());
        } finally {
            $context?->restoreDatabaseStatementTimeouts(); app()->forgetInstance(AssistantRequestExecutionContext::class);
            while (DB::connection($alias)->transactionLevel() > 0) { DB::connection($alias)->rollBack(); }
            DB::setDefaultConnection($default); DB::purge($alias); config(['database.connections.'.$alias => null]);
        }
    }
}
