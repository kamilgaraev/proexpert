<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->json('included_packages')
                ->nullable()
                ->after('features');
        });

        $includedPackagesByPlan = [
            'free' => [],
            'start' => [
                ['package_slug' => 'objects-execution', 'tier' => 'base'],
            ],
            'business' => [
                ['package_slug' => 'objects-execution', 'tier' => 'base'],
                ['package_slug' => 'supply-warehouse', 'tier' => 'base'],
                ['package_slug' => 'finance-acts', 'tier' => 'base'],
            ],
            'profi' => [
                ['package_slug' => 'objects-execution', 'tier' => 'pro'],
                ['package_slug' => 'supply-warehouse', 'tier' => 'pro'],
                ['package_slug' => 'finance-acts', 'tier' => 'pro'],
                ['package_slug' => 'estimates-pto', 'tier' => 'pro'],
                ['package_slug' => 'holding-analytics', 'tier' => 'pro'],
                ['package_slug' => 'ai-contour', 'tier' => 'pro'],
            ],
            'enterprise' => [
                ['package_slug' => 'objects-execution', 'tier' => 'enterprise'],
                ['package_slug' => 'finance-acts', 'tier' => 'enterprise'],
                ['package_slug' => 'supply-warehouse', 'tier' => 'enterprise'],
                ['package_slug' => 'holding-analytics', 'tier' => 'enterprise'],
                ['package_slug' => 'estimates-pto', 'tier' => 'enterprise'],
                ['package_slug' => 'ai-contour', 'tier' => 'enterprise'],
            ],
        ];

        foreach ($includedPackagesByPlan as $planSlug => $includedPackages) {
            DB::table('subscription_plans')
                ->where('slug', $planSlug)
                ->update(['included_packages' => json_encode($includedPackages, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('included_packages');
        });
    }
};
