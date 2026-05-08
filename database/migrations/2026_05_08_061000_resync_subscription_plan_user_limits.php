<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $plans = [
            'free' => [
                'max_users' => 3,
                'max_foremen' => 1,
                'max_projects' => 1,
                'max_storage_gb' => 1,
                'max_contractor_invitations' => 3,
            ],
            'start' => [
                'max_users' => 5,
                'max_foremen' => 2,
                'max_projects' => 3,
                'max_storage_gb' => 1,
                'max_contractor_invitations' => 10,
            ],
            'business' => [
                'max_users' => 10,
                'max_foremen' => 5,
                'max_projects' => 10,
                'max_storage_gb' => 5,
                'max_contractor_invitations' => 50,
            ],
            'profi' => [
                'max_users' => 30,
                'max_foremen' => 15,
                'max_projects' => 30,
                'max_storage_gb' => 15,
                'max_contractor_invitations' => 150,
            ],
            'enterprise' => [
                'max_users' => 100,
                'max_foremen' => 100,
                'max_projects' => 100,
                'max_storage_gb' => 50,
                'max_contractor_invitations' => 500,
            ],
        ];

        $now = now();

        foreach ($plans as $slug => $limits) {
            DB::table('subscription_plans')
                ->where('slug', $slug)
                ->update(array_merge($limits, ['updated_at' => $now]));
        }

        Cache::forget('active_subscription_plans');
    }

    public function down(): void
    {
        Cache::forget('active_subscription_plans');
    }
};
