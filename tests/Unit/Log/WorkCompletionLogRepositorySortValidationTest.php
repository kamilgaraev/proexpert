<?php

declare(strict_types=1);

namespace Tests\Unit\Log;

use App\Repositories\Log\WorkCompletionLogRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkCompletionLogRepositorySortValidationTest extends TestCase
{
    protected function refreshTestDatabase(): void
    {
    }

    public function test_paginated_logs_fall_back_to_completion_date_sorting(): void
    {
        $this->createSchema();

        DB::table('projects')->insert([
            'id' => 1,
            'organization_id' => 7,
            'name' => 'Project',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => 1,
            'name' => 'Foreman',
            'email' => 'foreman@example.test',
            'password' => 'secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('work_types')->insert([
            'id' => 1,
            'organization_id' => 7,
            'name' => 'Concrete',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('work_completion_logs')->insert([
            [
                'project_id' => 1,
                'work_type_id' => 1,
                'user_id' => 1,
                'organization_id' => 7,
                'quantity' => 1,
                'completion_date' => '2026-04-01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'project_id' => 1,
                'work_type_id' => 1,
                'user_id' => 1,
                'organization_id' => 7,
                'quantity' => 2,
                'completion_date' => '2026-04-03',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $result = (new WorkCompletionLogRepository())->getPaginatedLogs(
            7,
            15,
            [],
            'completion_date desc',
            'sideways'
        );

        $this->assertSame('2026-04-03', $result->items()[0]->completion_date->format('Y-m-d'));
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('work_completion_logs');
        Schema::dropIfExists('work_types');
        Schema::dropIfExists('users');
        Schema::dropIfExists('projects');

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('work_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->unsignedBigInteger('measurement_unit_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('work_completion_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('work_type_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('organization_id');
            $table->decimal('quantity', 15, 3);
            $table->date('completion_date');
            $table->timestamps();
        });
    }
}
