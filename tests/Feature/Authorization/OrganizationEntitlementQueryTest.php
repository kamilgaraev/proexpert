<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\CommercialBankInvoice;
use App\Models\CommercialBankPaymentProof;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\Module;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Services\Billing\Bank\BankPaymentProofAccess;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class OrganizationEntitlementQueryTest extends TestCase
{
    public function test_matching_foundation_skips_irrelevant_bank_reads_and_keeps_module_status_fresh(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $service = app(OrganizationEntitlementService::class);
        self::assertSame(BankPaymentProofAccess::class, app(BankPaymentProofAccess::class)::class);
        self::assertSame(PackageCatalogService::class, app(PackageCatalogService::class)::class);
        self::assertContains('payments', app(PackageCatalogService::class)->foundationModules());
        self::assertTrue(Schema::hasTable('commercial_bank_payment_proofs'));
        $phase = null;
        $reads = [];
        DB::listen(static function ($query) use (&$phase, &$reads): void {
            if ($phase === null) {
                return;
            }
            $reads[$phase] ??= ['bank' => 0, 'modules' => 0];
            if (str_contains($query->sql, 'commercial_bank_payment_proofs') || in_array('commercial_bank_payment_proofs', $query->bindings, true)) {
                $reads[$phase]['bank']++;
            }
            if (preg_match('/^select\b.*from "modules"/is', $query->sql)) {
                $reads[$phase]['modules']++;
            }
        });
        try {
            $phase = 'full';
            self::assertContains('payments', $service->getEffectiveModuleSlugs($fixture->organization->id));
            $phase = 'matching';
            self::assertSame(['payments'], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'payments'));
            $phase = null;
            Module::query()->where('slug', 'payments')->update(['is_active' => false]);
            $phase = 'inactive';
            self::assertSame([], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'payments'));
            $phase = null;
            Module::query()->where('slug', 'payments')->update(['is_active' => true]);
            $phase = 'restored';
            self::assertSame(['payments'], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'payments'));
            $phase = 'full_models';
            self::assertContains('payments', $service->getEffectiveModules($fixture->organization->id)->pluck('slug')->all());
        } finally {
            $phase = null;
        }
        self::assertSame(['bank' => 2, 'modules' => 1], $reads['full']);
        self::assertSame(['bank' => 0, 'modules' => 1], $reads['matching']);
        self::assertSame(['bank' => 0, 'modules' => 1], $reads['inactive']);
        self::assertSame(['bank' => 0, 'modules' => 1], $reads['restored']);
        self::assertSame(['bank' => 2, 'modules' => 1], $reads['full_models']);
    }

    public function test_matching_initial_candidate_still_requires_a_real_module_row(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $path = tempnam(sys_get_temp_dir(), 'most-foundation-catalog-');
        self::assertIsString($path);
        try {
            file_put_contents($path, "<?php\ndeclare(strict_types=1);\nreturn ['foundation_modules' => ['missing-foundation-fixture']];\n");
            $catalog = new PackageCatalogService(settingsPath: $path);
            $service = new OrganizationEntitlementService($catalog);
            self::assertSame(['missing-foundation-fixture'], $catalog->foundationModules());
            self::assertFalse(Module::query()->where('slug', 'missing-foundation-fixture')->exists());
            self::assertSame([], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'missing-foundation-fixture'));
        } finally {
            unlink($path);
        }
    }

    public function test_matching_and_full_reads_remain_safe_without_the_bank_proof_table(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create([]);
        $service = app(OrganizationEntitlementService::class);
        Schema::rename('commercial_bank_payment_proofs', 'commercial_bank_payment_proofs_foundation_probe');
        try {
            self::assertSame(['payments'], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'payments'));
            self::assertSame([], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'ai-assistant'));
            self::assertContains('payments', $service->getEffectiveModuleSlugs($fixture->organization->id));
            self::assertSame(1, DB::selectOne('SELECT 1 AS value')->value);
        } finally {
            Schema::rename('commercial_bank_payment_proofs_foundation_probe', 'commercial_bank_payment_proofs');
        }
    }

    public function test_matching_initial_candidates_preserve_custom_bank_calls_and_exceptions(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $bank = new class extends BankPaymentProofAccess {
            public int $reads = 0;
            public ?RuntimeException $failure = null;

            public function packageSlugs(int $organizationId): array
            {
                $this->reads++;
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return [];
            }
        };
        app()->instance(BankPaymentProofAccess::class, $bank);
        $service = app(OrganizationEntitlementService::class);
        self::assertSame(['payments'], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'payments'));
        self::assertSame(1, $bank->reads);
        $bank->failure = new RuntimeException('custom_bank_failure');
        try {
            $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'payments');
            self::fail('The original custom bank exception must propagate');
        } catch (RuntimeException $exception) {
            self::assertSame($bank->failure, $exception);
        }
        self::assertSame(2, $bank->reads);
    }

    public function test_matching_initial_candidates_preserve_custom_catalog_bank_tiers_and_exceptions(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create([]);
        $this->createPendingBankProof($fixture, ['fixture-custom-bank-tier']);
        self::assertSame(BankPaymentProofAccess::class, app(BankPaymentProofAccess::class)::class);
        $catalog = new class extends PackageCatalogService {
            public int $bankTierReads = 0;
            public ?RuntimeException $failure = null;

            public function tierModules(string $packageSlug, string $tier, bool $includeFoundation = false): array
            {
                if ($packageSlug === 'fixture-custom-bank-tier') {
                    $this->bankTierReads++;
                    if ($this->failure !== null) {
                        throw $this->failure;
                    }

                    return ['payments'];
                }

                return parent::tierModules($packageSlug, $tier, $includeFoundation);
            }
        };
        $service = new OrganizationEntitlementService($catalog);
        self::assertContains('payments', $catalog->foundationModules());
        self::assertSame(['payments'], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'payments'));
        self::assertSame(1, $catalog->bankTierReads);
        $catalog->failure = new RuntimeException('custom_catalog_failure');
        try {
            $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'payments');
            self::fail('The original custom catalog exception must propagate');
        } catch (RuntimeException $exception) {
            self::assertSame($catalog->failure, $exception);
        }
        self::assertSame(2, $catalog->bankTierReads);
    }

    public function test_paid_matching_reads_keep_real_bank_expiry_status_and_organization_guards(): void
    {
        $this->freezeTime();
        $fixture = AssistantRealAuthorizationFixture::create([]);
        $service = app(OrganizationEntitlementService::class);
        self::assertSame([], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'ai-assistant'));
        $proof = $this->createPendingBankProof($fixture, ['working-entry']);
        self::assertSame(['ai-assistant'], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'ai-assistant'));
        self::assertSame([], $service->getEffectiveModuleSlugsMatching($fixture->foreignOrganization->id, 'ai-assistant'));
        $proof->update(['status' => 'rejected']);
        self::assertSame([], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'ai-assistant'));
        $proof->update(['status' => 'pending']);
        self::assertSame(['ai-assistant'], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'ai-assistant'));
        $this->travelTo($proof->temporary_expires_at);
        self::assertSame([], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'ai-assistant'));
        self::assertSame(['payments'], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'payments'));
    }

    public function test_matching_module_queries_do_not_build_unrelated_package_predicates(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $service = app(OrganizationEntitlementService::class);
        $measure = null;
        $widths = [];
        DB::listen(static function ($query) use (&$measure, &$widths): void {
            if ($measure !== null && preg_match('/^select\b.*from "modules"/is', $query->sql) === 1) {
                $widths[$measure][] = ['sql_chars' => strlen($query->sql), 'bindings' => count($query->bindings)];
            }
        });
        $measure = 'all';
        $all = $service->getEffectiveModuleSlugs($fixture->organization->id);
        $measure = 'matching';
        try {
            $matching = $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'ai-assistant');
        } finally {
            $measure = null;
        }
        self::assertSame(array_values(array_filter($all, static fn ($slug): bool => $slug === 'ai-assistant')), $matching);
        self::assertCount(1, $widths['all']);
        self::assertCount(1, $widths['matching']);
        fwrite(STDERR, 'entitlement_matching_query_synthetic='.json_encode($widths, JSON_THROW_ON_ERROR).PHP_EOL);
        self::assertLessThan(intdiv($widths['all'][0]['bindings'], 2), $widths['matching'][0]['bindings'], 'A single-module permission read must not bind every unrelated package module');
    }

    public function test_matching_module_reads_preserve_access_and_fresh_revocation(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $service = app(OrganizationEntitlementService::class);
        $free = $service->getEffectiveModuleSlugs($fixture->foreignOrganization->id);
        $paid = array_values(array_diff($service->getEffectiveModuleSlugs($fixture->organization->id), $free));
        self::assertNotEmpty($paid);
        self::assertSame([$paid[0]], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, $paid[0]));
        self::assertSame([], $service->getEffectiveModuleSlugsMatching($fixture->foreignOrganization->id, $paid[0]));
        self::assertSame([], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, 'unknown-candidate-module'));

        Module::query()->where('slug', $paid[0])->update(['is_active' => false]);
        self::assertSame([], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, $paid[0]));
        Module::query()->where('slug', $paid[0])->update(['is_active' => true]);
        self::assertSame([$paid[0]], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, $paid[0]));
        OrganizationPackageSubscription::query()->where('organization_id', $fixture->organization->id)->update(['status' => 'canceled']);
        self::assertSame([], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, $paid[0]));
    }

    public function test_matching_reads_preserve_every_catalogue_module_for_paid_and_foreign_organizations(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $service = app(OrganizationEntitlementService::class);
        $paid = $service->getEffectiveModuleSlugs($fixture->organization->id);
        $free = $service->getEffectiveModuleSlugs($fixture->foreignOrganization->id);
        $candidates = array_values(array_unique([...$paid, ...$free, 'unknown-candidate-module']));
        foreach ([$fixture->organization->id => $paid, $fixture->foreignOrganization->id => $free] as $organizationId => $slugs) {
            foreach ($candidates as $candidate) {
                self::assertSame(array_values(array_filter($slugs, static fn ($slug): bool => $slug === $candidate)),
                    $service->getEffectiveModuleSlugsMatching($organizationId, $candidate));
            }
        }
    }

    public function test_matching_read_preserves_subclass_full_read_override(): void
    {
        $service = new class(app(PackageCatalogService::class)) extends OrganizationEntitlementService {
            public int $reads = 0;

            public function getEffectiveModuleSlugs(int $organizationId): array
            {
                $this->reads++;

                return ['virtual-module'];
            }
        };
        self::assertSame(['virtual-module'], $service->getEffectiveModuleSlugsMatching(123, 'virtual-module'));
        self::assertSame([], $service->getEffectiveModuleSlugsMatching(123, 'unknown-module'));
        self::assertSame(2, $service->reads);
    }

    public function test_non_bindable_candidates_keep_the_legacy_database_reads(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $service = app(OrganizationEntitlementService::class);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            if (preg_match('/^select\b.*from "modules"/is', $query->sql)) {
                $queries[] = $query;
            }
        });
        $candidates = ["binary\0module", "invalid\xffmodule", str_repeat('x', 1025)];
        foreach ($candidates as $candidate) {
            self::assertSame([], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, $candidate));
        }

        self::assertCount(count($candidates), $queries);
        foreach ($queries as $query) {
            foreach ($candidates as $candidate) {
                self::assertNotContains($candidate, $query->bindings);
            }
        }
    }

    public function test_scoped_catalog_keeps_new_entitlement_reads_fresh(): void
    {
        $catalog = app(PackageCatalogService::class);
        $fixture = AssistantRealAuthorizationFixture::create(array_column($catalog->allPackages(), 'slug'));
        $service = app(OrganizationEntitlementService::class);
        $free = $service->getEffectiveModuleSlugs($fixture->foreignOrganization->id);
        $paid = array_values(array_diff($service->getEffectiveModuleSlugs($fixture->organization->id), $free));
        self::assertNotEmpty($paid);
        Module::query()->where('slug', $paid[0])->update(['is_active' => false]);

        $next = app(OrganizationEntitlementService::class);
        self::assertNotSame($service, $next);
        self::assertSame($catalog, app(PackageCatalogService::class));
        self::assertNotContains($paid[0], $next->getEffectiveModuleSlugs($fixture->organization->id));
        self::assertEqualsCanonicalizing($free, $next->getEffectiveModuleSlugs($fixture->foreignOrganization->id));

        Module::query()->where('slug', $paid[0])->update(['is_active' => true]);
        OrganizationPackageSubscription::query()->where('organization_id', $fixture->organization->id)->update(['status' => 'canceled']);
        self::assertEqualsCanonicalizing($free, app(OrganizationEntitlementService::class)->getEffectiveModuleSlugs($fixture->organization->id));
    }

    public function test_module_rows_are_loaded_once_and_inactive_foundation_is_excluded(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $foundation = app(PackageCatalogService::class)->foundationModules();
        self::assertNotEmpty($foundation);
        Module::query()->where('slug', $foundation[0])->update(['is_active' => false]);
        $reads = 0;
        DB::listen(static function ($query) use (&$reads): void {
            if (preg_match('/from "(?:modules|organization_package_subscriptions)"/i', $query->sql)) {
                $reads++;
            }
        });
        $modules = app(OrganizationEntitlementService::class)->getEffectiveModules($fixture->organization->id);
        self::assertSame(1, $reads);
        self::assertNotContains($foundation[0], $modules->pluck('slug')->all());
        self::assertTrue($modules->every(static fn (Module $module): bool => $module->is_active));
        self::assertSame([], app(OrganizationEntitlementService::class)->getEffectiveModuleSlugsMatching($fixture->organization->id, $foundation[0]));
    }

    public function test_paid_modules_are_scoped_and_next_read_observes_revocation(): void
    {
        $packages = array_values(array_unique(['working-entry', ...array_column(app(PackageCatalogService::class)->allPackages(), 'slug')]));
        $fixture = AssistantRealAuthorizationFixture::create($packages);
        $service = app(OrganizationEntitlementService::class);
        $free = $service->getEffectiveModuleSlugs($fixture->foreignOrganization->id);
        $paid = $service->getEffectiveModuleSlugs($fixture->organization->id);
        self::assertNotEmpty(array_diff($paid, $free));
        OrganizationPackageSubscription::query()->where('organization_id', $fixture->organization->id)->update(['status' => 'canceled']);
        self::assertEqualsCanonicalizing($free, $service->getEffectiveModuleSlugs($fixture->organization->id));
        self::assertEqualsCanonicalizing($free, $service->getEffectiveModuleSlugs($fixture->foreignOrganization->id));
    }

    public function test_module_reads_preserve_current_subscription_windows(): void
    {
        $this->freezeTime();
        $catalog = app(PackageCatalogService::class);
        $fixture = AssistantRealAuthorizationFixture::create(array_column($catalog->allPackages(), 'slug'));
        $service = app(OrganizationEntitlementService::class);
        $free = $service->getEffectiveModuleSlugs($fixture->foreignOrganization->id);
        $paid = array_values(array_diff($service->getEffectiveModuleSlugs($fixture->organization->id), $free));
        self::assertNotEmpty($paid);
        $account = OrganizationCommercialAccount::query()->findOrFail($fixture->subscription->commercial_account_id);
        $subscriptions = OrganizationPackageSubscription::query()->where('organization_id', $fixture->organization->id);
        $cases = [
            ['active paid', 'active', 'paid_package', now()->addHour(), null, 'active', null, false, true],
            ['paid without period', 'active', 'paid_package', null, null, 'active', null, false, false],
            ['unlimited corporate', 'active', 'corporate', null, null, 'active', null, false, true],
            ['expired corporate', 'active', 'corporate', now()->subHour(), null, 'active', null, false, false],
            ['scheduled removal', 'scheduled_for_removal', 'paid_package', now()->addHour(), null, 'active', null, false, true],
            ['current trial', 'trialing', 'paid_package', null, now()->addMinute(), 'active', null, false, true],
            ['expired trial', 'trialing', 'paid_package', null, now(), 'active', null, false, false],
            ['canceled', 'canceled', 'paid_package', now()->addHour(), null, 'active', null, false, false],
            ['current grace', 'grace', 'paid_package', now()->subHour(), null, 'grace', now()->addMinute(), false, true],
            ['expired grace', 'grace', 'paid_package', now()->subHour(), null, 'grace', now(), false, false],
            ['renewal processing', 'active', 'paid_package', now()->subMinute(), null, 'active', null, true, true],
            ['renewal without payment', 'active', 'paid_package', now()->subMinute(), null, 'active', null, false, false],
        ];
        foreach ($cases as [$label, $status, $source, $periodEnd, $trialEnd, $accountStatus, $graceEnd, $renew, $expected]) {
            $account->forceFill([
                'status' => $accountStatus, 'current_period_end_at' => $periodEnd, 'billing_anchor_at' => $periodEnd,
                'grace_ends_at' => $graceEnd, 'auto_renew_enabled' => $renew,
                'saved_payment_method_active' => $renew, 'saved_payment_method_id' => $renew ? 'pm_fixture' : null,
            ])->save();
            (clone $subscriptions)->update([
                'status' => $status, 'access_source' => $source,
                'current_period_end_at' => $periodEnd, 'trial_ends_at' => $trialEnd,
            ]);
            self::assertSame($expected, in_array($paid[0], $service->getEffectiveModuleSlugs($fixture->organization->id), true), $label);
            self::assertSame($expected ? [$paid[0]] : [], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, $paid[0]), $label);
            self::assertSame($expected, isset($service->getPackageModuleSources($fixture->organization->id)[$paid[0]]), $label);
        }
    }

    private function createPendingBankProof(AssistantRealAuthorizationFixture $fixture, array $packages): CommercialBankPaymentProof
    {
        $organizationId = $fixture->organization->id;
        $account = OrganizationCommercialAccount::query()->where('organization_id', $organizationId)->sole();
        $order = CommercialOrder::query()->create([
            'public_id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'commercial_account_id' => $account->id,
            'user_id' => $fixture->owner->id, 'status' => 'pending_payment', 'offer_type' => 'packages', 'quote_version' => 4,
            'billing_period' => '30_days', 'renew_subscription' => true, 'selected_package_slugs' => $packages,
            'current_package_slugs' => [], 'selected_resource_addons' => [], 'amount_minor' => 10000, 'amount' => '100.00',
            'currency' => 'RUB', 'period_start_at' => now(), 'period_end_at' => now()->addMonth(),
            'client_idempotency_key' => (string) Str::uuid(),
        ]);
        $payment = CommercialPayment::query()->create([
            'commercial_order_id' => $order->id, 'provider' => 'tochka', 'provider_status' => 'created', 'amount_minor' => 10000,
            'currency' => 'RUB', 'provider_idempotency_key' => (string) Str::uuid(),
        ]);
        $invoice = CommercialBankInvoice::query()->create([
            'public_id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'commercial_order_id' => $order->id,
            'commercial_payment_id' => $payment->id, 'number' => 'MOST-'.str_pad((string) $order->id, 10, '0', STR_PAD_LEFT),
            'amount_minor' => 10000, 'snapshot' => [], 'expires_at' => now()->addWeek(),
        ]);

        return CommercialBankPaymentProof::query()->create([
            'public_id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'commercial_account_id' => $account->id,
            'commercial_bank_invoice_id' => $invoice->id, 'submitted_by' => $fixture->owner->id, 'status' => 'pending',
            'storage_path' => 'org-'.$organizationId.'/billing/test.pdf', 'mime' => 'application/pdf', 'size_bytes' => 100,
            'sha256' => str_repeat('a', 64), 'package_slugs' => $packages, 'resource_addons' => [],
            'submitted_at' => now(), 'temporary_expires_at' => now()->addMinute(),
        ]);
    }

    public function test_retired_package_alias_and_unknown_package_preserve_module_access(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        config(['commercial_offers.retired_entry_packages' => ['legacy-entitlement-fixture']]);
        $service = app(OrganizationEntitlementService::class);
        $before = $service->getEffectiveModuleSlugs($fixture->organization->id);
        $sources = $service->getPackageModuleSources($fixture->organization->id);
        $fixture->subscription->update(['package_slug' => 'legacy-entitlement-fixture']);
        self::assertEqualsCanonicalizing($before, $service->getEffectiveModuleSlugs($fixture->organization->id));
        self::assertEquals($sources, $service->getPackageModuleSources($fixture->organization->id));
        foreach ($before as $candidate) {
            self::assertSame([$candidate], $service->getEffectiveModuleSlugsMatching($fixture->organization->id, $candidate));
        }
        $fixture->subscription->update(['package_slug' => 'unknown-entitlement-fixture']);
        self::assertSame([], $service->getPackageModuleSources($fixture->organization->id));
        self::assertEqualsCanonicalizing($service->getEffectiveModuleSlugs($fixture->foreignOrganization->id), $service->getEffectiveModuleSlugs($fixture->organization->id));
        $free = $service->getEffectiveModuleSlugs($fixture->foreignOrganization->id);
        foreach ($before as $candidate) {
            self::assertSame(array_values(array_filter($free, static fn ($slug): bool => $slug === $candidate)),
                $service->getEffectiveModuleSlugsMatching($fixture->organization->id, $candidate));
        }
    }
}
