<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::connection($this->getConnection());
        $schemaManager = $connection->getDoctrineSchemaManager();
        $indexes = $schemaManager->listTableIndexes($table);
        
        return isset($indexes[$index]);
    }

    public function up(): void
    {
        if (!Schema::hasColumn('estimate_items', 'normative_rate_id')) {
            Schema::table('estimate_items', function (Blueprint $table) {
                $table->foreignId('normative_rate_id')->nullable()->after('estimate_section_id')->constrained('normative_rates')->onDelete('set null');
                $table->string('normative_rate_code', 100)->nullable()->after('normative_rate_id');
                
                $table->decimal('materials_cost', 15, 2)->default(0)->after('direct_costs');
                $table->decimal('machinery_cost', 15, 2)->default(0)->after('materials_cost');
                $table->decimal('labor_cost', 15, 2)->default(0)->after('machinery_cost');
                $table->decimal('equipment_cost', 15, 2)->default(0)->after('labor_cost');
                
                $table->decimal('labor_hours', 15, 4)->default(0)->after('equipment_cost');
                $table->decimal('machinery_hours', 15, 4)->default(0)->after('labor_hours');
                
                $table->decimal('base_materials_cost', 15, 2)->default(0)->after('machinery_hours');
                $table->decimal('base_machinery_cost', 15, 2)->default(0)->after('base_materials_cost');
                $table->decimal('base_labor_cost', 15, 2)->default(0)->after('base_machinery_cost');
                
                $table->decimal('materials_index', 10, 4)->nullable()->after('base_labor_cost');
                $table->decimal('machinery_index', 10, 4)->nullable()->after('materials_index');
                $table->decimal('labor_index', 10, 4)->nullable()->after('machinery_index');
                
                $table->jsonb('applied_coefficients')->nullable()->after('labor_index');
                $table->decimal('coefficient_total', 10, 4)->default(1)->after('applied_coefficients');
                
                $table->jsonb('resource_calculation')->nullable()->after('coefficient_total');
                $table->jsonb('custom_resources')->nullable()->after('resource_calculation');
                
                $table->text('notes')->nullable()->after('justification');
            });
        }

        if (!$this->indexExists('estimate_items', 'estimate_items_normative_rate_id_index')) {
            Schema::table('estimate_items', function (Blueprint $table) {
                $table->index('normative_rate_id');
            });
        }
        
        if (!$this->indexExists('estimate_items', 'estimate_items_estimate_id_item_type_index')) {
            Schema::table('estimate_items', function (Blueprint $table) {
                $table->index(['estimate_id', 'item_type']);
            });
        }

        DB::statement('CREATE INDEX IF NOT EXISTS estimate_items_applied_coefficients_gin_idx ON estimate_items USING GIN(applied_coefficients)');
        DB::statement('CREATE INDEX IF NOT EXISTS estimate_items_resource_calculation_gin_idx ON estimate_items USING GIN(resource_calculation)');
        DB::statement('CREATE INDEX IF NOT EXISTS estimate_items_custom_resources_gin_idx ON estimate_items USING GIN(custom_resources)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS estimate_items_applied_coefficients_gin_idx');
        DB::statement('DROP INDEX IF EXISTS estimate_items_resource_calculation_gin_idx');
        DB::statement('DROP INDEX IF EXISTS estimate_items_custom_resources_gin_idx');

        if (Schema::hasColumn('estimate_items', 'normative_rate_id')) {
            Schema::table('estimate_items', function (Blueprint $table) {
                $table->dropForeign(['normative_rate_id']);
                $table->dropColumn([
                    'normative_rate_id',
                    'normative_rate_code',
                    'materials_cost',
                    'machinery_cost',
                    'labor_cost',
                    'equipment_cost',
                    'labor_hours',
                    'machinery_hours',
                    'base_materials_cost',
                    'base_machinery_cost',
                    'base_labor_cost',
                    'materials_index',
                    'machinery_index',
                    'labor_index',
                    'applied_coefficients',
                    'coefficient_total',
                    'resource_calculation',
                    'custom_resources',
                    'notes',
                ]);
            });
        }
    }
};

