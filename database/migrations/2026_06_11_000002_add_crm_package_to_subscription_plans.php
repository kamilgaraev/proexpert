<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->addPackage('business', 'base');
        $this->addPackage('profi', 'pro');
        $this->addPackage('enterprise', 'enterprise');
    }

    public function down(): void
    {
        foreach (['business', 'profi', 'enterprise'] as $planSlug) {
            $this->removePackage($planSlug);
        }
    }

    private function addPackage(string $planSlug, string $tier): void
    {
        $plan = DB::table('subscription_plans')
            ->where('slug', $planSlug)
            ->first(['id', 'included_packages']);

        if ($plan === null) {
            return;
        }

        $packages = array_values(array_filter(
            $this->decodePackages($plan->included_packages),
            static fn (array $package): bool => ($package['package_slug'] ?? $package['slug'] ?? null) !== 'crm'
        ));

        $packages[] = [
            'package_slug' => 'crm',
            'tier' => $tier,
        ];

        DB::table('subscription_plans')
            ->where('id', $plan->id)
            ->update([
                'included_packages' => json_encode($packages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function removePackage(string $planSlug): void
    {
        $plan = DB::table('subscription_plans')
            ->where('slug', $planSlug)
            ->first(['id', 'included_packages']);

        if ($plan === null) {
            return;
        }

        $packages = array_values(array_filter(
            $this->decodePackages($plan->included_packages),
            static fn (array $package): bool => ($package['package_slug'] ?? $package['slug'] ?? null) !== 'crm'
        ));

        DB::table('subscription_plans')
            ->where('id', $plan->id)
            ->update([
                'included_packages' => json_encode($packages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function decodePackages(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
};
