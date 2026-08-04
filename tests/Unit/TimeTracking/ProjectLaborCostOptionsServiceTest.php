<?php

declare(strict_types=1);

namespace Tests\Unit\TimeTracking;

use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostOptionsService;
use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProjectLaborCostOptionsServiceTest extends TestCase
{
    private SQLiteConnection $connection;

    private ProjectLaborCostOptionsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new PDO('sqlite::memory:');
        $pdo->sqliteCreateFunction('concat_ws', static function (string $separator, mixed ...$values): string {
            return implode($separator, array_filter($values, static fn (mixed $value): bool => $value !== null && $value !== ''));
        });
        $this->connection = new SQLiteConnection($pdo);
        $this->createSchema();
        $this->seedBaseData();
        $this->service = new ProjectLaborCostOptionsService($this->connection);
    }

    public function test_summary_uses_only_approved_current_entries_in_server_project_scope(): void
    {
        self::assertSame([
            'min' => '2026-07-02',
            'max' => '2026-07-03',
        ], $this->service->summary(1, 10)['period']);

        self::assertSame([
            'min' => '2026-06-01',
            'max' => '2026-06-01',
        ], $this->service->summary(1, 20)['period']);

        self::assertSame([
            'min' => null,
            'max' => null,
        ], $this->service->summary(2, 10)['period']);
    }

    public function test_search_returns_only_real_options_from_selected_project(): void
    {
        self::assertSame(
            [['id' => 1, 'name' => 'Иванов Иван']],
            $this->service->search(1, 10, 'employees', null, 1)['items'],
        );
        self::assertSame(
            [['id' => 101, 'name' => 'Монтаж']],
            $this->service->search(1, 10, 'tasks', null, 1)['items'],
        );
        self::assertSame(
            [['id' => 201, 'name' => 'Сварка']],
            $this->service->search(1, 10, 'work_types', null, 1)['items'],
        );
        self::assertSame(
            [['id' => 301, 'name' => 'Подрядчик А']],
            $this->service->search(1, 10, 'contractors', null, 1)['items'],
        );
    }

    public function test_search_is_paginated_without_losing_remaining_options(): void
    {
        $types = [];
        $entries = [];
        for ($index = 1; $index <= ProjectLaborCostOptionsService::LIMIT + 1; $index++) {
            $id = 1000 + $index;
            $types[] = ['id' => $id, 'organization_id' => 1, 'name' => sprintf('Type %03d', $index)];
            $entries[] = [
                'id' => 1000 + $index,
                'organization_id' => 1,
                'project_id' => 10,
                'user_id' => 1,
                'task_id' => 101,
                'work_type_id' => $id,
                'work_date' => '2026-07-03',
                'status' => 'approved',
                'deleted_at' => null,
            ];
        }
        $this->connection->table('work_types')->insert($types);
        $this->connection->table('time_entries')->insert($entries);

        $first = $this->service->search(1, 10, 'work_types', 'type', 1);
        $second = $this->service->search(1, 10, 'work_types', 'type', 2);

        self::assertCount(ProjectLaborCostOptionsService::LIMIT, $first['items']);
        self::assertTrue($first['has_more']);
        self::assertSame(2, $first['next_page']);
        self::assertSame([['id' => 1051, 'name' => 'Type 051']], $second['items']);
        self::assertFalse($second['has_more']);
        self::assertNull($second['next_page']);
    }

    private function createSchema(): void
    {
        $statements = [
            'CREATE TABLE time_entries (id INTEGER PRIMARY KEY, organization_id INTEGER, project_id INTEGER, user_id INTEGER, task_id INTEGER, work_type_id INTEGER, work_date TEXT, status TEXT, deleted_at TEXT NULL)',
            'CREATE TABLE workforce_employees (id INTEGER PRIMARY KEY, organization_id INTEGER, user_id INTEGER, last_name TEXT, first_name TEXT, middle_name TEXT NULL, deleted_at TEXT NULL)',
            'CREATE TABLE schedule_tasks (id INTEGER PRIMARY KEY, organization_id INTEGER, schedule_id INTEGER, name TEXT)',
            'CREATE TABLE project_schedules (id INTEGER PRIMARY KEY, organization_id INTEGER, project_id INTEGER)',
            'CREATE TABLE work_types (id INTEGER PRIMARY KEY, organization_id INTEGER, name TEXT)',
            'CREATE TABLE completed_works (id INTEGER PRIMARY KEY, organization_id INTEGER, project_id INTEGER, contractor_id INTEGER, status TEXT, deleted_at TEXT NULL)',
            'CREATE TABLE contractors (id INTEGER PRIMARY KEY, organization_id INTEGER, name TEXT)',
        ];
        foreach ($statements as $statement) {
            $this->connection->statement($statement);
        }
    }

    private function seedBaseData(): void
    {
        $this->connection->table('workforce_employees')->insert([
            ['id' => 1, 'organization_id' => 1, 'user_id' => 1, 'last_name' => 'Иванов', 'first_name' => 'Иван', 'middle_name' => null, 'deleted_at' => null],
            ['id' => 2, 'organization_id' => 1, 'user_id' => 2, 'last_name' => 'Петров', 'first_name' => 'Пётр', 'middle_name' => null, 'deleted_at' => null],
        ]);
        $this->connection->table('project_schedules')->insert([
            ['id' => 11, 'organization_id' => 1, 'project_id' => 10],
            ['id' => 12, 'organization_id' => 1, 'project_id' => 20],
        ]);
        $this->connection->table('schedule_tasks')->insert([
            ['id' => 101, 'organization_id' => 1, 'schedule_id' => 11, 'name' => 'Монтаж'],
            ['id' => 102, 'organization_id' => 1, 'schedule_id' => 12, 'name' => 'Чужая задача'],
        ]);
        $this->connection->table('work_types')->insert([
            ['id' => 201, 'organization_id' => 1, 'name' => 'Сварка'],
            ['id' => 202, 'organization_id' => 1, 'name' => 'Покраска'],
        ]);
        $this->connection->table('contractors')->insert([
            ['id' => 301, 'organization_id' => 1, 'name' => 'Подрядчик А'],
            ['id' => 302, 'organization_id' => 1, 'name' => 'Подрядчик Б'],
        ]);
        $this->connection->table('completed_works')->insert([
            ['id' => 1, 'organization_id' => 1, 'project_id' => 10, 'contractor_id' => 301, 'status' => 'confirmed', 'deleted_at' => null],
            ['id' => 2, 'organization_id' => 1, 'project_id' => 20, 'contractor_id' => 302, 'status' => 'confirmed', 'deleted_at' => null],
        ]);
        $this->connection->table('time_entries')->insert([
            ['id' => 1, 'organization_id' => 1, 'project_id' => 10, 'user_id' => 1, 'task_id' => 101, 'work_type_id' => 201, 'work_date' => '2026-07-02', 'status' => 'approved', 'deleted_at' => null],
            ['id' => 2, 'organization_id' => 1, 'project_id' => 10, 'user_id' => 1, 'task_id' => 101, 'work_type_id' => 201, 'work_date' => '2026-07-03', 'status' => 'approved', 'deleted_at' => null],
            ['id' => 3, 'organization_id' => 1, 'project_id' => 10, 'user_id' => 2, 'task_id' => 102, 'work_type_id' => 202, 'work_date' => '2026-07-04', 'status' => 'draft', 'deleted_at' => null],
            ['id' => 4, 'organization_id' => 1, 'project_id' => 10, 'user_id' => 2, 'task_id' => 102, 'work_type_id' => 202, 'work_date' => '2026-07-05', 'status' => 'approved', 'deleted_at' => '2026-07-06'],
            ['id' => 5, 'organization_id' => 1, 'project_id' => 20, 'user_id' => 2, 'task_id' => 102, 'work_type_id' => 202, 'work_date' => '2026-06-01', 'status' => 'approved', 'deleted_at' => null],
        ]);
    }
}
