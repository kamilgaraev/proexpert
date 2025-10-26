<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Contractor;
use App\Models\Organization;

class ContractorsCheckDuplicates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contractors:check-duplicates
                            {--export : Export results to JSON file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for duplicate INN in contractors and organizations (DEVELOPMENT ONLY)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // ЗАЩИТА: Проверяем что команда запущена НЕ на production
        if (app()->environment('production')) {
            $this->error('❌ This command cannot be run in production environment!');
            $this->error('On production, all synchronization happens automatically via migrations.');
            return Command::FAILURE;
        }

        $this->info('🔍 Analyzing INN duplicates in the database...');
        $this->newLine();

        // Анализируем дубликаты в contractors
        $contractorDuplicates = $this->analyzeContractorDuplicates();
        
        // Анализируем дубликаты в organizations
        $organizationDuplicates = $this->analyzeOrganizationDuplicates();
        
        // Анализируем потенциальные синхронизации
        $potentialSyncs = $this->analyzePotentialSynchronizations();

        // Вывод результатов
        $this->displayResults($contractorDuplicates, $organizationDuplicates, $potentialSyncs);

        // Экспорт если требуется
        if ($this->option('export')) {
            $this->exportResults($contractorDuplicates, $organizationDuplicates, $potentialSyncs);
        }

        return Command::SUCCESS;
    }

    /**
     * Анализ дубликатов ИНН в contractors
     */
    private function analyzeContractorDuplicates(): array
    {
        $this->info('📋 Checking contractors table for duplicate INNs...');

        // Находим ИНН которые встречаются более 1 раза
        $duplicates = DB::table('contractors')
            ->select('inn', DB::raw('COUNT(*) as count'), DB::raw('COUNT(DISTINCT organization_id) as org_count'))
            ->whereNull('deleted_at')
            ->whereNotNull('inn')
            ->where('inn', '!=', '')
            ->groupBy('inn')
            ->having('count', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('✅ No duplicate INNs found in contractors table');
            return [];
        }

        $this->warn("⚠️  Found {$duplicates->count()} duplicate INNs in contractors table");

        $results = [];
        foreach ($duplicates as $duplicate) {
            $contractors = Contractor::where('inn', $duplicate->inn)
                ->whereNull('deleted_at')
                ->with('organization:id,name')
                ->get();

            $results[] = [
                'inn' => $duplicate->inn,
                'total_count' => $duplicate->count,
                'organizations_count' => $duplicate->org_count,
                'contractors' => $contractors->map(fn($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'organization' => $c->organization?->name,
                    'organization_id' => $c->organization_id,
                    'contracts_count' => $c->contracts()->count(),
                ])->toArray()
            ];
        }

        return $results;
    }

    /**
     * Анализ дубликатов tax_number в organizations
     */
    private function analyzeOrganizationDuplicates(): array
    {
        $this->info('📋 Checking organizations table for duplicate tax_numbers...');

        $duplicates = DB::table('organizations')
            ->select('tax_number', DB::raw('COUNT(*) as count'))
            ->whereNull('deleted_at')
            ->whereNotNull('tax_number')
            ->where('tax_number', '!=', '')
            ->groupBy('tax_number')
            ->having('count', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('✅ No duplicate tax_numbers found in organizations table');
            return [];
        }

        $this->warn("⚠️  Found {$duplicates->count()} duplicate tax_numbers in organizations table");

        $results = [];
        foreach ($duplicates as $duplicate) {
            $organizations = Organization::where('tax_number', $duplicate->tax_number)
                ->whereNull('deleted_at')
                ->get();

            $results[] = [
                'tax_number' => $duplicate->tax_number,
                'count' => $duplicate->count,
                'organizations' => $organizations->map(fn($o) => [
                    'id' => $o->id,
                    'name' => $o->name,
                    'is_active' => $o->is_active,
                    'created_at' => $o->created_at?->format('Y-m-d H:i:s'),
                ])->toArray()
            ];
        }

        return $results;
    }

    /**
     * Анализ потенциальных синхронизаций (contractors с ИНН существующих organizations)
     */
    private function analyzePotentialSynchronizations(): array
    {
        $this->info('📋 Checking for potential contractor-organization synchronizations...');

        // Находим contractors у которых ИНН совпадает с tax_number организаций
        $potentialSyncs = DB::table('contractors as c')
            ->join('organizations as o', 'c.inn', '=', 'o.tax_number')
            ->whereNull('c.deleted_at')
            ->whereNull('o.deleted_at')
            ->whereNull('c.source_organization_id') // Еще не синхронизированы
            ->whereNotNull('c.inn')
            ->where('c.inn', '!=', '')
            ->select(
                'c.id as contractor_id',
                'c.name as contractor_name',
                'c.inn',
                'c.organization_id as contractor_org_id',
                'o.id as match_org_id',
                'o.name as match_org_name'
            )
            ->get();

        if ($potentialSyncs->isEmpty()) {
            $this->info('✅ No contractors need synchronization');
            return [];
        }

        $this->info("📌 Found {$potentialSyncs->count()} contractors that can be synchronized with organizations");

        $results = [];
        foreach ($potentialSyncs as $sync) {
            $contractor = Contractor::find($sync->contractor_id);
            $contractsCount = $contractor->contracts()->count();

            $results[] = [
                'contractor_id' => $sync->contractor_id,
                'contractor_name' => $sync->contractor_name,
                'inn' => $sync->inn,
                'contractor_organization_id' => $sync->contractor_org_id,
                'match_organization_id' => $sync->match_org_id,
                'match_organization_name' => $sync->match_org_name,
                'contracts_count' => $contractsCount,
                'action' => 'Will be automatically synchronized when migration runs'
            ];
        }

        return $results;
    }

    /**
     * Отображение результатов
     */
    private function displayResults(array $contractorDuplicates, array $organizationDuplicates, array $potentialSyncs): void
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('                    ANALYSIS RESULTS                        ');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->newLine();

        // Contractor Duplicates
        if (!empty($contractorDuplicates)) {
            $this->warn('⚠️  CONTRACTOR DUPLICATES:');
            foreach ($contractorDuplicates as $dup) {
                $this->line("  INN: {$dup['inn']} - Found in {$dup['total_count']} contractors across {$dup['organizations_count']} organizations");
                foreach ($dup['contractors'] as $contractor) {
                    $this->line("    - #{$contractor['id']} {$contractor['name']} (Org: {$contractor['organization']}, Contracts: {$contractor['contracts_count']})");
                }
                $this->newLine();
            }
        }

        // Organization Duplicates
        if (!empty($organizationDuplicates)) {
            $this->error('❌ ORGANIZATION DUPLICATES (MUST BE FIXED):');
            foreach ($organizationDuplicates as $dup) {
                $this->line("  Tax Number: {$dup['tax_number']} - Found in {$dup['count']} organizations");
                foreach ($dup['organizations'] as $org) {
                    $this->line("    - #{$org['id']} {$org['name']} (Active: {$org['is_active']}, Created: {$org['created_at']})");
                }
                $this->newLine();
            }
            $this->error('⚠️  These duplicates MUST be resolved before adding unique constraint!');
        }

        // Potential Syncs
        if (!empty($potentialSyncs)) {
            $this->info('📌 POTENTIAL SYNCHRONIZATIONS:');
            foreach ($potentialSyncs as $sync) {
                $this->line("  INN: {$sync['inn']}");
                $this->line("    Contractor: #{$sync['contractor_id']} {$sync['contractor_name']} ({$sync['contracts_count']} contracts)");
                $this->line("    Will sync with: #{$sync['match_organization_id']} {$sync['match_organization_name']}");
                $this->newLine();
            }
        }

        // Summary
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('                         SUMMARY                            ');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->line("  Contractor duplicate INNs: " . count($contractorDuplicates));
        $this->line("  Organization duplicate tax_numbers: " . count($organizationDuplicates));
        $this->line("  Contractors ready for sync: " . count($potentialSyncs));
        $this->newLine();

        if (empty($organizationDuplicates)) {
            $this->info('✅ Database is ready for adding unique constraints!');
        } else {
            $this->error('❌ Please resolve organization duplicates before proceeding!');
        }
        $this->newLine();
    }

    /**
     * Экспорт результатов в JSON
     */
    private function exportResults(array $contractorDuplicates, array $organizationDuplicates, array $potentialSyncs): void
    {
        $filename = storage_path('logs/inn-duplicates-analysis-' . now()->format('Y-m-d-His') . '.json');
        
        $data = [
            'analyzed_at' => now()->toDateTimeString(),
            'environment' => app()->environment(),
            'contractor_duplicates' => $contractorDuplicates,
            'organization_duplicates' => $organizationDuplicates,
            'potential_synchronizations' => $potentialSyncs,
            'summary' => [
                'contractor_duplicates_count' => count($contractorDuplicates),
                'organization_duplicates_count' => count($organizationDuplicates),
                'potential_syncs_count' => count($potentialSyncs),
            ]
        ];

        file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        $this->info("📄 Results exported to: {$filename}");
    }
}

