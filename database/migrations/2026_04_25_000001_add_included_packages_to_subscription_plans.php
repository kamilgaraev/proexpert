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
            'start' => [],
            'business' => [
                ['package_slug' => 'projects', 'tier' => 'base'],
            ],
            'profi' => [
                ['package_slug' => 'projects', 'tier' => 'pro'],
                ['package_slug' => 'finance', 'tier' => 'base'],
                ['package_slug' => 'supply', 'tier' => 'base'],
                ['package_slug' => 'analytics', 'tier' => 'base'],
            ],
            'enterprise' => [
                ['package_slug' => 'projects', 'tier' => 'pro'],
                ['package_slug' => 'finance', 'tier' => 'pro'],
                ['package_slug' => 'supply', 'tier' => 'pro'],
                ['package_slug' => 'analytics', 'tier' => 'pro'],
                ['package_slug' => 'integrations', 'tier' => 'pro'],
                ['package_slug' => 'ai', 'tier' => 'pro'],
                ['package_slug' => 'enterprise', 'tier' => 'enterprise'],
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
