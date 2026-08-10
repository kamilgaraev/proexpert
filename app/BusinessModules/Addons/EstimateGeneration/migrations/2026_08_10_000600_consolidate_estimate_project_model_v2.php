<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('estimate_generation_project_model_assertions', function (Blueprint $table): void {
            if (! Schema::hasColumn('estimate_generation_project_model_assertions', 'fact_origin')) {
                $table->string('fact_origin', 48)->default('document');
            }
            if (! Schema::hasColumn('estimate_generation_project_model_assertions', 'fact_status')) {
                $table->string('fact_status', 32)->default('candidate');
            }
            if (! Schema::hasColumn('estimate_generation_project_model_assertions', 'fact_version')) {
                $table->unsignedInteger('fact_version')->default(1);
            }
            if (! Schema::hasColumn('estimate_generation_project_model_assertions', 'supersedes_assertion_id')) {
                $table->unsignedBigInteger('supersedes_assertion_id')->nullable();
            }
            if (! Schema::hasColumn('estimate_generation_project_model_assertions', 'fact_value')) {
                $table->jsonb('fact_value')->nullable();
            }
            if (! Schema::hasColumn('estimate_generation_project_model_assertions', 'fact_unit')) {
                $table->string('fact_unit', 32)->nullable();
            }
        });
        Schema::table('estimate_generation_project_model_corrections', function (Blueprint $table): void {
            if (! Schema::hasColumn('estimate_generation_project_model_corrections', 'decision_actor_type')) {
                $table->string('decision_actor_type', 16)->default('user');
            }
            if (! Schema::hasColumn('estimate_generation_project_model_corrections', 'decision_version')) {
                $table->unsignedInteger('decision_version')->default(1);
            }
            if (! Schema::hasColumn('estimate_generation_project_model_corrections', 'target_conflict_key')) {
                $table->string('target_conflict_key', 192)->nullable();
            }
            if (! Schema::hasColumn('estimate_generation_project_model_corrections', 'selected_fact_stable_key')) {
                $table->string('selected_fact_stable_key', 192)->nullable();
            }
            if (! Schema::hasColumn('estimate_generation_project_model_corrections', 'system_actor_key')) {
                $table->string('system_actor_key', 191)->nullable();
            }
            if (! Schema::hasColumn('estimate_generation_project_model_corrections', 'evidence_lineage')) {
                $table->jsonb('evidence_lineage')->default('[]');
            }
        });

        $this->factEvidence();
        $this->factProjections();
        $this->conflicts();
        $this->derivedQuantities();
        $this->crossDocumentLinks();
        $this->understandingRuns();
    }

    private function factEvidence(): void
    {
        if (Schema::hasTable('estimate_generation_project_model_fact_evidence')) {
            return;
        }
        Schema::create('estimate_generation_project_model_fact_evidence', function (Blueprint $table): void {
            $table->unsignedBigInteger('fact_id');
            $table->unsignedBigInteger('evidence_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->string('source_version', 71);
            $table->string('evidence_source_version', 255);
            $table->unsignedInteger('evidence_invalidation_version');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['fact_id', 'evidence_id'], 'eg_pm_fact_evidence_pk');
        });
    }

    private function factProjections(): void
    {
        if (Schema::hasTable('estimate_generation_project_model_fact_projections')) {
            return;
        }
        Schema::create('estimate_generation_project_model_fact_projections', function (Blueprint $table): void {
            $table->id();
            $this->scope($table);
            $table->unsignedBigInteger('fact_id');
            $table->string('entity_stable_key', 192);
            $table->string('fact_type', 64);
            $table->unsignedInteger('projection_version');
            $table->boolean('is_current')->default(true);
            $table->string('replacement_source_version', 71)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('invalidated_at')->nullable();
            $table->unique(['organization_id', 'project_id', 'session_id', 'fact_id', 'projection_version'], 'eg_pm_fact_projection_replay_uq');
        });
    }

    private function conflicts(): void
    {
        if (! Schema::hasTable('estimate_generation_project_model_conflicts')) {
            Schema::create('estimate_generation_project_model_conflicts', function (Blueprint $table): void {
                $table->id();
                $this->scope($table);
                $table->string('stable_key', 192);
                $table->string('reason', 1000);
                $table->string('status', 24)->default('unresolved');
                $table->unsignedInteger('conflict_version')->default(1);
                $table->timestampTz('created_at')->useCurrent();
                $table->unique(['organization_id', 'project_id', 'session_id', 'source_version', 'stable_key', 'conflict_version'], 'eg_pm_conflict_replay_uq');
            });
        }
        if (! Schema::hasTable('estimate_generation_project_model_conflict_facts')) {
            Schema::create('estimate_generation_project_model_conflict_facts', function (Blueprint $table): void {
                $table->unsignedBigInteger('conflict_id');
                $table->unsignedBigInteger('fact_id');
                $this->scope($table);
                $table->primary(['conflict_id', 'fact_id'], 'eg_pm_conflict_facts_pk');
            });
        }
    }

    private function derivedQuantities(): void
    {
        if (! Schema::hasTable('estimate_generation_project_model_derived_quantities')) {
            Schema::create('estimate_generation_project_model_derived_quantities', function (Blueprint $table): void {
                $table->id();
                $this->scope($table);
                $table->string('stable_key', 192);
                $table->string('entity_stable_key', 192);
                $table->string('formula', 2000);
                $table->decimal('value', 32, 12)->nullable();
                $table->string('unit', 32);
                $table->string('rounding_mode', 16);
                $table->unsignedSmallInteger('rounding_scale');
                $table->string('status', 24);
                $table->jsonb('evidence_lineage');
                $table->jsonb('unresolved_inputs')->default('[]');
                $table->timestampTz('created_at')->useCurrent();
                $table->unique(['organization_id', 'project_id', 'session_id', 'source_version', 'stable_key'], 'eg_pm_derived_replay_uq');
            });
        }
        if (! Schema::hasTable('estimate_generation_project_model_derived_operands')) {
            Schema::create('estimate_generation_project_model_derived_operands', function (Blueprint $table): void {
                $table->unsignedBigInteger('derived_quantity_id');
                $table->unsignedBigInteger('fact_id');
                $this->scope($table);
                $table->unsignedSmallInteger('operand_ordinal');
                $table->jsonb('operand_snapshot');
                $table->primary(['derived_quantity_id', 'operand_ordinal'], 'eg_pm_derived_operands_pk');
            });
        }
    }

    private function crossDocumentLinks(): void
    {
        if (! Schema::hasTable('estimate_generation_project_model_cross_document_links')) {
            Schema::create('estimate_generation_project_model_cross_document_links', function (Blueprint $table): void {
                $table->id();
                $this->scope($table);
                $table->string('stable_key', 192);
                $table->unsignedBigInteger('left_fact_id');
                $table->unsignedBigInteger('right_fact_id');
                $table->string('strategy', 64);
                $table->string('reason', 1000);
                $table->unsignedInteger('strategy_version');
                $table->string('operation_identity', 64);
                $table->string('status', 24);
                $table->boolean('is_current')->default(true);
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('invalidated_at')->nullable();
                $table->unique(['organization_id', 'project_id', 'session_id', 'source_version', 'operation_identity'], 'eg_pm_cross_link_replay_uq');
            });
        }
        if (! Schema::hasTable('estimate_generation_project_model_cross_link_evidence')) {
            Schema::create('estimate_generation_project_model_cross_link_evidence', function (Blueprint $table): void {
                $table->unsignedBigInteger('link_id');
                $table->unsignedBigInteger('evidence_id');
                $this->scope($table);
                $table->string('side', 8);
                $table->primary(['link_id', 'evidence_id', 'side'], 'eg_pm_cross_link_evidence_pk');
            });
        }
    }

    private function understandingRuns(): void
    {
        if (Schema::hasTable('estimate_generation_project_understanding_runs')) {
            return;
        }
        Schema::create('estimate_generation_project_understanding_runs', function (Blueprint $table): void {
            $table->id();
            $this->scope($table);
            $table->string('result_fingerprint', 64);
            $table->jsonb('questions');
            $table->jsonb('limitations');
            $table->unsignedSmallInteger('provider_calls');
            $table->boolean('is_current')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('invalidated_at')->nullable();
            $table->unique(['organization_id', 'project_id', 'session_id', 'source_version', 'result_fingerprint'], 'eg_pm_understanding_replay_uq');
        });
    }

    private function scope(Blueprint $table): void
    {
        $table->unsignedBigInteger('organization_id');
        $table->unsignedBigInteger('project_id');
        $table->unsignedBigInteger('session_id');
        $table->string('source_version', 71);
    }

    public function down(): void
    {
        throw new RuntimeException('Project model v2 stores immutable audit history and has no destructive rollback.');
    }
};
