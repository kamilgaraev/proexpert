<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessOptionsService;
use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\TestCase;

final class PayrollReadinessOptionsServiceTest extends TestCase
{
    public function test_options_are_limited_to_server_owned_organization_and_project(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        foreach ([
            'CREATE TABLE workforce_payroll_periods (id INTEGER PRIMARY KEY, organization_id INTEGER, project_id INTEGER, period_start TEXT, period_end TEXT, status TEXT)',
            'CREATE TABLE workforce_payroll_calculation_versions (id INTEGER PRIMARY KEY, organization_id INTEGER, payroll_period_id INTEGER)',
            'CREATE TABLE workforce_payroll_calculation_source_rows (id INTEGER PRIMARY KEY, organization_id INTEGER, calculation_version_id INTEGER, employee_id INTEGER, employee_name TEXT, source_type TEXT)',
            'CREATE TABLE workforce_payroll_calculation_issues (id INTEGER PRIMARY KEY, organization_id INTEGER, calculation_version_id INTEGER, issue_code TEXT)',
        ] as $sql) {
            $connection->statement($sql);
        }

        $connection->table('workforce_payroll_periods')->insert([
            ['id' => 1, 'organization_id' => 10, 'project_id' => 20, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'status' => 'locked'],
            ['id' => 2, 'organization_id' => 10, 'project_id' => 21, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'status' => 'locked'],
            ['id' => 3, 'organization_id' => 11, 'project_id' => 20, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'status' => 'locked'],
        ]);
        $connection->table('workforce_payroll_calculation_versions')->insert([
            ['id' => 101, 'organization_id' => 10, 'payroll_period_id' => 1],
            ['id' => 102, 'organization_id' => 10, 'payroll_period_id' => 2],
            ['id' => 103, 'organization_id' => 11, 'payroll_period_id' => 3],
        ]);
        $connection->table('workforce_payroll_calculation_source_rows')->insert([
            ['id' => 1, 'organization_id' => 10, 'calculation_version_id' => 101, 'employee_id' => 7, 'employee_name' => 'Ivan Petrov', 'source_type' => 'timesheet'],
            ['id' => 2, 'organization_id' => 10, 'calculation_version_id' => 102, 'employee_id' => 8, 'employee_name' => 'Чужой проект', 'source_type' => 'production'],
            ['id' => 3, 'organization_id' => 11, 'calculation_version_id' => 103, 'employee_id' => 9, 'employee_name' => 'Чужая организация', 'source_type' => 'timesheet'],
        ]);
        $connection->table('workforce_payroll_calculation_issues')->insert([
            ['id' => 1, 'organization_id' => 10, 'calculation_version_id' => 101, 'issue_code' => 'MISSING_RATE'],
            ['id' => 2, 'organization_id' => 10, 'calculation_version_id' => 102, 'issue_code' => 'FOREIGN_PROJECT'],
        ]);

        $service = new PayrollReadinessOptionsService($connection);
        self::assertSame([1], array_column($service->summary(10, 20)['periods'], 'id'));
        self::assertSame([1], array_column($service->search(10, 20, 'periods', '2026-07', 1)['items'], 'id'));
        self::assertSame(['Ivan Petrov'], array_column($service->search(10, 20, 'employees', 'Ivan', 1)['items'], 'name'));
        self::assertSame(['MISSING_RATE'], array_column($service->search(10, 20, 'issue_codes', null, 1)['items'], 'id'));
        self::assertSame(['timesheet'], array_column($service->search(10, 20, 'source_types', null, 1)['items'], 'id'));
    }
}
