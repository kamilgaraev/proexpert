<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE estimate_generation_project_model_assertions ADD CONSTRAINT eg_pm_fact_origin_input_ck CHECK (fact_origin IN ('document','ai_inference','user_assumption','user_input','ai_technology_recommendation','unresolved')) NOT VALID");
        DB::statement('ALTER TABLE estimate_generation_project_model_assertions VALIDATE CONSTRAINT eg_pm_fact_origin_input_ck');
        DB::statement('ALTER TABLE estimate_generation_project_model_assertions DROP CONSTRAINT eg_pm_fact_origin_ck');
        DB::statement('ALTER TABLE estimate_generation_project_model_assertions RENAME CONSTRAINT eg_pm_fact_origin_input_ck TO eg_pm_fact_origin_ck');
    }

    public function down(): void
    {
        if (DB::table('estimate_generation_project_model_assertions')->where('fact_origin', 'user_input')->exists()) {
            throw new RuntimeException('user_input_fact_history_requires_preservation');
        }
        DB::statement('ALTER TABLE estimate_generation_project_model_assertions DROP CONSTRAINT eg_pm_fact_origin_ck');
        DB::statement("ALTER TABLE estimate_generation_project_model_assertions ADD CONSTRAINT eg_pm_fact_origin_ck CHECK (fact_origin IN ('document','ai_inference','user_assumption','ai_technology_recommendation','unresolved'))");
    }
};
