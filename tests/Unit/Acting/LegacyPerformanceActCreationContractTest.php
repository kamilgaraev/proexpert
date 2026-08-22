<?php

declare(strict_types=1);

namespace Tests\Unit\Acting;

use App\DTOs\Contract\ContractPerformanceActDTO;
use App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct\StoreContractPerformanceActRequest;
use App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct\UpdateContractPerformanceActRequest;
use App\Services\ActReport\ActReportAccessService;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class LegacyPerformanceActCreationContractTest extends TestCase
{
    public function test_legacy_creation_rejects_client_controlled_acceptance_and_money_fields(): void
    {
        $request = new StoreContractPerformanceActRequest;
        $rules = $request->rules();
        unset($rules['completed_works.*.completed_work_id']);
        $validator = (new Factory(new Translator(new ArrayLoader, 'ru')))->make([
            'act_date' => '2026-08-22',
            'currency' => 'RUB',
            'is_approved' => true,
            'approval_date' => '2026-08-22',
            'amount' => 1,
            'completed_works' => [[
                'completed_work_id' => 10,
                'included_quantity' => 2,
                'included_amount' => 1,
            ]],
        ], $rules);

        self::assertTrue($validator->fails());
        self::assertSame([
            'is_approved',
            'approval_date',
            'amount',
            'completed_works.0.included_amount',
        ], array_keys($validator->errors()->toArray()));
    }

    public function test_legacy_dto_defaults_to_unapproved_zero_amount_draft_input(): void
    {
        $dto = new ContractPerformanceActDTO(
            project_id: 1,
            act_document_number: 'КС-2-1',
            act_date: '2026-08-22',
            description: null,
        );

        self::assertFalse($dto->is_approved);
        self::assertSame(0.0, $dto->amount);
    }

    public function test_legacy_update_rejects_status_money_and_line_mutations(): void
    {
        $request = new UpdateContractPerformanceActRequest;
        $rules = $request->rules();
        foreach (array_keys($rules) as $field) {
            if (str_starts_with($field, 'completed_works.')) {
                unset($rules[$field]);
            }
        }
        $validator = (new Factory(new Translator(new ArrayLoader, 'ru')))->make([
            'is_approved' => true,
            'approval_date' => '2026-08-22',
            'amount' => 1,
            'currency' => 'USD',
            'completed_works' => [[
                'completed_work_id' => 10,
                'included_quantity' => 2,
                'included_amount' => 1,
            ]],
        ], $rules);

        self::assertTrue($validator->fails());
        self::assertSame([
            'is_approved',
            'approval_date',
            'currency',
            'amount',
            'completed_works',
        ], array_keys($validator->errors()->toArray()));
    }

    public function test_legacy_http_creation_route_is_not_published(): void
    {
        $routes = implode("\n", array_map(
            static fn (string $file): string => (string) file_get_contents(dirname(__DIR__, 3).'/routes/api/v1/admin/'.$file),
            ['contracts.php', 'project-based.php', 'act_reports.php'],
        ));

        self::assertStringNotContainsString(
            "Route::post('contracts/{contract}/performance-acts'",
            $routes,
        );
        self::assertStringNotContainsString('contracts.performance-acts.store', $routes);
        self::assertStringNotContainsString('available-works-for-acts', $routes);
        self::assertStringNotContainsString("Route::get('{act}/available-works'", $routes);
    }

    public function test_approval_has_a_separate_translated_permission_granted_only_to_approver_roles(): void
    {
        self::assertSame('act_reports.approve', ActReportAccessService::PERMISSION_APPROVE);

        $translations = require dirname(__DIR__, 3).'/lang/ru/permissions.php';
        self::assertSame('Согласование актов выполненных работ', $translations['values']['act_reports.approve'] ?? null);

        foreach ([
            'admin/web_admin.json',
            'lk/organization_admin.json',
            'project/project_manager.json',
        ] as $roleFile) {
            $role = json_decode(
                (string) file_get_contents(dirname(__DIR__, 3).'/config/RoleDefinitions/'.$roleFile),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            self::assertContains('act_reports.approve', $role['module_permissions']['act-reporting']);
        }

        $siteEngineer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/config/RoleDefinitions/project/site_engineer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertNotContains('act_reports.approve', $siteEngineer['module_permissions']['act-reporting']);
    }
}
