<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait ActingTestSchema
{
    public function refreshDatabase(): void
    {
    }

    protected function setUpActingSchema(): void
    {
        foreach ([
            'performance_act_lines',
            'acting_policies',
            'performance_act_completed_works',
            'contract_performance_acts',
            'contract_state_events',
            'completed_works',
            'journal_materials',
            'journal_equipment',
            'journal_workers',
            'journal_work_volumes',
            'construction_journal_entries',
            'construction_journals',
            'schedule_tasks',
            'project_schedules',
            'contract_estimate_items',
            'estimate_items',
            'estimates',
            'supplementary_agreements',
            'contracts',
            'contractors',
            'projects',
            'project_organization',
            'organization_user',
            'users',
            'organizations',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->string('verification_status')->nullable();
            $table->foreignId('parent_organization_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->foreignId('current_organization_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('user_id');
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('name');
            $table->string('status')->default('active');
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('geocoded_at')->nullable();
            $table->string('geocoding_status')->nullable();
            $table->text('description')->nullable();
            $table->decimal('budget_amount', 15, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contractors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('project_organization', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('organization_id');
            $table->string('role')->nullable();
            $table->string('role_new')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('project_id')->nullable();
            $table->foreignId('contractor_id');
            $table->string('number');
            $table->date('date');
            $table->text('subject')->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->string('status')->default('draft');
            $table->boolean('is_multi_project')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contract_state_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id');
            $table->string('event_type');
            $table->json('event_data')->nullable();
            $table->timestamps();
        });

        Schema::create('supplementary_agreements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id');
            $table->decimal('change_amount', 15, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('estimates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('project_id')->nullable();
            $table->foreignId('contract_id')->nullable();
            $table->string('name')->default('Estimate');
            $table->string('status')->default('approved');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('total_amount_with_vat', 15, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('estimate_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('estimate_id');
            $table->string('position_number')->nullable();
            $table->string('item_type')->nullable();
            $table->string('name');
            $table->decimal('quantity', 15, 8)->default(0);
            $table->decimal('quantity_total', 15, 8)->nullable();
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('current_unit_price', 15, 4)->nullable();
            $table->decimal('actual_unit_price', 15, 4)->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('current_total_amount', 15, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contract_estimate_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id');
            $table->foreignId('estimate_id');
            $table->foreignId('estimate_item_id');
            $table->decimal('quantity', 15, 8)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('construction_journals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('project_id');
            $table->foreignId('contract_id')->nullable();
            $table->string('name');
            $table->string('journal_number');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('construction_journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_id');
            $table->foreignId('schedule_task_id')->nullable();
            $table->foreignId('estimate_id')->nullable();
            $table->date('entry_date');
            $table->unsignedInteger('entry_number')->default(1);
            $table->text('work_description');
            $table->string('status')->default('draft');
            $table->foreignId('created_by_user_id')->nullable();
            $table->foreignId('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->json('weather_conditions')->nullable();
            $table->text('problems_description')->nullable();
            $table->text('safety_notes')->nullable();
            $table->text('visitors_notes')->nullable();
            $table->text('quality_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('journal_work_volumes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id');
            $table->foreignId('estimate_item_id')->nullable();
            $table->foreignId('work_type_id')->nullable();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->foreignId('measurement_unit_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('journal_workers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id');
            $table->string('specialty');
            $table->unsignedInteger('workers_count')->default(0);
            $table->decimal('hours_worked', 8, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('journal_equipment', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id');
            $table->string('equipment_name');
            $table->string('equipment_type')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('hours_used', 8, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('journal_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id');
            $table->foreignId('material_id')->nullable();
            $table->string('material_name');
            $table->decimal('quantity', 15, 3)->default(0);
            $table->string('measurement_unit');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('project_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('organization_id');
            $table->string('name')->default('Schedule');
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('schedule_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id');
            $table->foreignId('organization_id');
            $table->foreignId('estimate_item_id')->nullable();
            $table->foreignId('work_type_id')->nullable();
            $table->string('name');
            $table->decimal('quantity', 15, 4)->nullable();
            $table->decimal('completed_quantity', 15, 4)->nullable();
            $table->decimal('progress_percent', 8, 2)->default(0);
            $table->string('task_type')->default('task');
            $table->string('status')->default('not_started');
            $table->string('priority')->default('normal');
            $table->unsignedInteger('level')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('completed_works', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('project_id');
            $table->foreignId('contract_id')->nullable();
            $table->foreignId('estimate_item_id')->nullable();
            $table->foreignId('schedule_task_id')->nullable();
            $table->foreignId('journal_entry_id')->nullable();
            $table->foreignId('journal_work_volume_id')->nullable();
            $table->foreignId('work_type_id')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('contractor_id')->nullable();
            $table->string('work_origin_type', 32)->default('manual');
            $table->string('planning_status', 32)->default('planned');
            $table->decimal('quantity', 15, 3);
            $table->decimal('completed_quantity', 15, 4)->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->date('completion_date');
            $table->text('notes')->nullable();
            $table->string('status')->default('confirmed');
            $table->json('additional_info')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contract_performance_acts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id');
            $table->foreignId('project_id')->nullable();
            $table->string('act_document_number')->nullable();
            $table->date('act_date');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('draft');
            $table->boolean('is_approved')->default(false);
            $table->date('approval_date')->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->foreignId('submitted_by_user_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('signed_file_id')->nullable();
            $table->foreignId('signed_by_user_id')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('locked_by_user_id')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable();
            $table->nullableMorphs('fileable');
            $table->foreignId('user_id')->nullable();
            $table->string('name');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('disk')->default('s3');
            $table->string('type')->nullable();
            $table->string('category')->nullable();
            $table->json('additional_info')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payment_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('project_id')->nullable();
            $table->string('document_type')->default('invoice');
            $table->string('document_number');
            $table->date('document_date')->nullable();
            $table->string('direction')->default('outgoing');
            $table->string('invoice_type')->nullable();
            $table->nullableMorphs('invoiceable');
            $table->foreignId('contractor_id')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(20);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('amount_without_vat', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('remaining_amount', 15, 2)->default(0);
            $table->string('currency')->default('RUB');
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('performance_act_completed_works', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('performance_act_id');
            $table->foreignId('completed_work_id');
            $table->decimal('included_quantity', 15, 3);
            $table->decimal('included_amount', 15, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['performance_act_id', 'completed_work_id'], 'performance_act_completed_works_unique');
        });

        Schema::create('acting_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('contract_id')->nullable();
            $table->string('mode', 32)->default('operational');
            $table->boolean('allow_manual_lines')->default(false);
            $table->boolean('require_manual_line_reason')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'contract_id'], 'acting_policies_org_contract_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX acting_policies_org_default_unique ON acting_policies (organization_id) WHERE contract_id IS NULL'
        );

        DB::statement(
            "CREATE TABLE performance_act_lines (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                performance_act_id INTEGER NOT NULL,
                completed_work_id INTEGER NULL,
                estimate_item_id INTEGER NULL,
                line_type VARCHAR(32) NOT NULL,
                title VARCHAR(255) NOT NULL,
                unit VARCHAR(255) NULL,
                quantity NUMERIC NOT NULL,
                unit_price NUMERIC NULL,
                amount NUMERIC NOT NULL,
                manual_reason TEXT NULL,
                created_by INTEGER NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                CONSTRAINT performance_act_lines_line_type_check CHECK (line_type IN ('completed_work', 'manual')),
                CONSTRAINT performance_act_lines_quantity_positive_check CHECK (quantity > 0),
                CONSTRAINT performance_act_lines_amount_non_negative_check CHECK (amount >= 0),
                CONSTRAINT performance_act_lines_unit_price_non_negative_check CHECK (unit_price IS NULL OR unit_price >= 0)
            )"
        );

        Schema::table('performance_act_lines', function (Blueprint $table): void {
            $table->index(['performance_act_id', 'line_type'], 'performance_act_lines_act_type_idx');
            $table->index('completed_work_id', 'performance_act_lines_completed_work_idx');
        });
    }
}
