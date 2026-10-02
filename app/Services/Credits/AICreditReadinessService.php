<?php

declare(strict_types=1);

namespace App\Services\Credits;

use InvalidArgumentException;
use App\Support\AI\LunaModelPolicy;

final class AICreditReadinessService
{
    public const SCHEMA_VERSION = 2;
    public const EVALUATOR_VERSION = 5;
    public const CATEGORIES = ['financial', 'followup', 'rights', 'isolation', 'injection', 'context', 'tokens', 'billing', 'files', 'ocr', 'screenshots', 'actions', 'races', 'memory', 'index', 'errors', 'reports', 'navigation', 'estimates', 'retention'];
    public const DOMAIN_CATEGORIES = ['estimates_identity', 'estimates_totals', 'estimates_positions', 'contracts_status', 'contracts_term', 'contracts_money', 'finance_status', 'finance_due', 'finance_amount', 'works_status', 'works_date', 'works_quantity', 'warehouse_status', 'warehouse_quantity', 'warehouse_identity', 'machinery_status', 'machinery_inventory', 'machinery_identity', 'crm_stage', 'crm_close', 'crm_owner'];

    private array $policy;
    private ?string $implementationFingerprint = null;

    public function __construct(?array $policy = null, private ?string $moduleManifestPath = null)
    {
        $this->policy = $policy ?? config('ai-assistant-credits');
        foreach (['price_version', 'unit_minor', 'rub_per_unit', 'minimum_units_minor', 'profiles', 'pricing', 'packs'] as $key) {
            if (!isset($this->policy[$key])) {
                throw new InvalidArgumentException('missing_policy_'.$key);
            }
        }
        foreach (['price_version', 'unit_minor', 'minimum_units_minor'] as $key) {
            $this->nonnegativeInteger($this->policy[$key], 'policy.'.$key);
            if ($this->policy[$key] < 1 || $this->policy[$key] > 1_000_000) {
                throw new InvalidArgumentException('invalid_shadow_policy');
            }
        }
        $step = $this->policy['charge_step_minor'] ?? 50;
        $this->nonnegativeInteger($step, 'policy.charge_step_minor');
        if ($step < 1 || $step > 1_000_000 || !is_numeric($this->policy['rub_per_unit']) || !is_finite((float) $this->policy['rub_per_unit']) || round($this->policy['rub_per_unit'] * 1_000_000) < 1 || (float) $this->policy['rub_per_unit'] > 1000 || $this->policy['minimum_units_minor'] % $step !== 0) {
            throw new InvalidArgumentException('invalid_shadow_policy');
        }
        $this->policy['charge_step_minor'] = $step;
    }

    public function policyFingerprint(): string
    {
        return $this->hash(array_intersect_key($this->policy, array_flip(['price_version', 'unit_minor', 'rub_per_unit', 'minimum_units_minor', 'profiles', 'pricing', 'packs', 'charge_step_minor'])));
    }

    public function prelaunchPricePolicyEvidence(): array
    {
        $path = $this->moduleManifestPath ?? dirname(__DIR__, 3).'/config/ModuleList/addons/ai-assistant.json';
        if (!is_file($path)) { throw new InvalidArgumentException('missing_prelaunch_price_source'); }
        $contents = (string) file_get_contents($path);
        $module = json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
        $packs = ['ai-credits-1000' => ['units_minor' => 100000, 'amount_minor' => 100000], 'ai-credits-5000' => ['units_minor' => 500000, 'amount_minor' => 450000], 'ai-credits-10000' => ['units_minor' => 1000000, 'amount_minor' => 800000]];
        if (($module['pricing']['base_price'] ?? null) !== 3990 || ($module['pricing']['currency'] ?? null) !== 'RUB' || ($module['pricing']['duration_days'] ?? null) !== 30 || ($module['assistant_billing']['included_units_per_paid_period'] ?? null) !== 5000
            || $this->policy['price_version'] !== 2 || $this->policy['unit_minor'] !== 100 || (float) $this->policy['rub_per_unit'] !== 0.18 || $this->policy['minimum_units_minor'] !== 50 || $this->policy['charge_step_minor'] !== 50
            || $this->hash($this->policy['packs']) !== $this->hash($packs) || $this->hash($this->policy['pricing']) !== $this->hash(['input_micro_rub_per_million' => 13500000, 'output_micro_rub_per_million' => 67500000])) {
            throw new InvalidArgumentException('unapproved_prelaunch_price_policy');
        }
        $proof = ['economics_version' => 1, 'price_version' => 2, 'source' => 'server_module_manifest_and_credit_policy', 'source_normalization' => 'utf8_lf', 'module_source' => 'config/ModuleList/addons/ai-assistant.json', 'module_source_sha256' => $this->normalizedSourceHash($contents), 'policy_hash' => $this->policyFingerprint(),
            'subscription' => ['amount_minor' => 399000, 'units_minor' => 500000, 'currency' => 'RUB', 'duration_days' => 30], 'packs' => $packs, 'unit_minor' => 100, 'conservative_revenue_micro_rub_per_unit' => 798000];
        $proof['price_policy_sha256'] = $this->hash($proof);
        return $proof;
    }

    public function assistantImplementationFingerprint(bool $refresh = false): string
    {
        if (!$refresh && $this->implementationFingerprint !== null) { return $this->implementationFingerprint; }
        $root = dirname(__DIR__, 3);
        $paths = [];
        foreach (['app/BusinessModules/Features/AIAssistant', 'app/Services/Credits', 'app/Models/Credits', 'app/Support/AI', 'app/Exceptions/AI'] as $directory) {
            if (!is_dir($root.'/'.$directory)) { throw new InvalidArgumentException('assistant_implementation_source_unavailable'); }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'json'], true)) { $paths[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)); }
            }
        }
        $paths = array_merge($paths, ['app/BusinessModules/Features/AIAssistant/config/ai-assistant.php', 'config/ai-assistant-credits.php', 'config/ModuleList/addons/ai-assistant.json', 'app/Console/Commands/AICreditShadowReportCommand.php',
            'app/Models/CommercialOrder.php', 'database/migrations/2026_09_29_000006_create_ai_credit_tables.php',
            'database/migrations/2026_09_29_000009_create_ai_assistant_requests_table.php', 'database/migrations/2026_09_29_000014_add_assistant_revenue_allocation_to_commercial_orders.php',
            'app/Services/Billing/CommercialCheckoutService.php', 'app/Services/Billing/CommercialRenewalService.php',
            'app/Domain/Authorization/ValueObjects/ModulePermissionAliases.php', 'app/Domain/Authorization/Services/PermissionResolver.php',
            'app/BusinessModules/Addons/EstimateGeneration/Vision/Providers/TimewebVisionProvider.php', 'app/BusinessModules/Addons/EstimateGeneration/Vision/Providers/TimewebProviderErrorInspector.php',
            'app/Jobs/ScanAssistantDocuments.php', 'app/Jobs/RegisterAssistantEntityFile.php', 'app/Jobs/ProcessAssistantDocumentOcr.php', 'app/Jobs/ProcessAssistantDocument.php', 'app/Jobs/AuthorizeBackgroundAssistantDocumentOcr.php']);
        $hashes = [];
        foreach (array_unique($paths) as $path) {
            $contents = file_get_contents($root.'/'.$path);
            if ($contents === false) { throw new InvalidArgumentException('assistant_implementation_source_unavailable'); }
            $hashes[$path] = $this->normalizedSourceHash($contents);
        }
        return $this->implementationFingerprint = $this->hash(['fingerprint_version' => 1, 'source_normalization' => 'utf8_lf', 'sources' => $hashes]);
    }

    private function normalizedSourceHash(string $contents): string
    {
        return hash('sha256', str_replace(["\r\n", "\r"], "\n", $contents));
    }

    public function reportFromFile(string $path): array
    {
        if (!is_file($path) || filesize($path) > 32 * 1024 * 1024) {
            throw new InvalidArgumentException('invalid_trace_file');
        }
        $data = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException('invalid_trace_envelope');
        }
        return $this->evaluate($data);
    }

    public function evaluate(array $traces): array
    {
        if (!in_array($traces['schema_version'] ?? null, [1, self::SCHEMA_VERSION], true) || !in_array($traces['stage'] ?? null, ['actual', 'synthetic'], true)) {
            throw new InvalidArgumentException('invalid_trace_schema');
        }
        $version = $traces['schema_version'];
        $economicsBasis = $traces['economics_basis'] ?? 'actual_revenue';
        if (!in_array($economicsBasis, ['actual_revenue', 'prelaunch_projection'], true) || ($economicsBasis === 'prelaunch_projection' && ($version !== 2 || ($traces['economics_version'] ?? null) !== 1))) {
            throw new InvalidArgumentException('invalid_economics_basis');
        }
        $contract = $traces['scenario_contract'] ?? 'workflow_v1';
        if (!in_array($contract, ['workflow_v1', 'domain_v1'], true) || ($contract === 'domain_v1' && $version !== 2)) {
            throw new InvalidArgumentException('invalid_scenario_contract');
        }
        $requiredCategories = $contract === 'domain_v1' ? self::DOMAIN_CATEGORIES : self::CATEGORIES;
        $acceptedCategories = $contract === 'domain_v1' ? array_merge(self::DOMAIN_CATEGORIES, self::CATEGORIES) : self::CATEGORIES;
        $partialRun = $traces['partial_run'] ?? false;
        if (!is_bool($partialRun)) { throw new InvalidArgumentException('invalid_partial_run'); }
        $chargingMode = $version === 1 ? 'paid' : ($traces['charging_mode'] ?? null);
        if (!in_array($chargingMode, ['paid', 'shadow'], true)) { throw new InvalidArgumentException('invalid_charging_mode'); }
        $this->nonnegativeInteger($traces['assistant_revenue_minor'] ?? null, 'assistant_revenue_minor');
        if (!isset($traces['scenarios'], $traces['background_calls'], $traces['cost_coverage'], $traces['period']) || !is_array($traces['period']) || !is_array($traces['cost_coverage']) || !is_string($traces['period']['started_at'] ?? null) || !is_string($traces['period']['ended_at'] ?? null) || !is_array($traces['scenarios']) || !array_is_list($traces['scenarios']) || !is_array($traces['background_calls']) || !array_is_list($traces['background_calls'])) {
            throw new InvalidArgumentException('invalid_trace_collections');
        }
        $started = strtotime($traces['period']['started_at'] ?? '');
        $ended = strtotime($traces['period']['ended_at'] ?? '');
        if ($started === false || $ended === false || $started >= $ended) {
            throw new InvalidArgumentException('invalid_trace_period');
        }
        $reasons = [];
        if ($partialRun) { $reasons[] = 'partial_run_not_eligible'; }
        if ($traces['stage'] !== 'actual') {
            $reasons[] = 'synthetic_traces_not_production_evidence';
        }
        if (($traces['policy_hash'] ?? null) !== $this->policyFingerprint()) {
            $reasons[] = 'policy_hash_mismatch';
        }
        if ($economicsBasis === 'prelaunch_projection' && ($traces['assistant_implementation_fingerprint'] ?? null) !== $this->assistantImplementationFingerprint(true)) {
            $reasons[] = 'assistant_implementation_fingerprint_mismatch';
        }
        foreach (['assistant', 'memory', 'index', 'errors', 'ocr'] as $kind) {
            if (($traces['cost_coverage'][$kind] ?? null) !== true && !($version === 2 && $kind === 'memory' && $this->verifiedNoExternalCalls($traces['cost_coverage'][$kind] ?? null))) {
                $reasons[] = 'missing_cost_coverage_'.$kind;
            }
        }
        $ids = [];
        $inputs = [];
        $categories = [];
        $profiles = [];
        $totals = array_fill_keys(['assistant', 'memory', 'index', 'ocr', 'estimate_generation'], 0);
        $observedCostKinds = [];
        $hasProviderError = false;
        $successfulCost = 0;
        $errorCost = 0;
        $normal = [];
        $actualNormal = [];
        $providerModels = [];
        $qualityFailures = [];
        $usefulSpend = 0;
        $usefulCount = 0;
        $verifiedModelInputs = [];
        $requiredAssertions = ['rights', 'leak', 'unconfirmed_actions', 'factual_amounts', 'business_quality'];
        foreach ($traces['scenarios'] as $scenario) {
            if (!is_array($scenario) || !is_string($scenario['id'] ?? null) || $scenario['id'] === '' || isset($ids[$scenario['id']])) {
                throw new InvalidArgumentException('invalid_or_duplicate_scenario_id');
            }
            $id = $scenario['id'];
            $ids[$id] = true;
            $category = $scenario['category'] ?? null;
            $profile = $scenario['requested_profile'] ?? null;
            if (!in_array($category, $acceptedCategories, true) || !in_array($profile, ['short', 'normal', 'detailed'], true) || !is_array($scenario['input'] ?? null) || !is_string($scenario['input']['message'] ?? null) || trim($scenario['input']['message']) === '') {
                throw new InvalidArgumentException('invalid_scenario_metadata_'.$id);
            }
            $categories[$category] = ($categories[$category] ?? 0) + 1;
            $profiles[$profile] = ($profiles[$profile] ?? 0) + 1;
            $distinctInput = array_intersect_key($scenario['input'], array_flip(['message', 'context', 'followup', 'attachment_spec', 'attachments']));
            $inputs[$this->hash($distinctInput)] = true;
            if (!in_array($scenario['outcome'] ?? null, ['completed', 'blocked', 'error', 'cancelled'], true) || !is_array($scenario['provider_calls'] ?? null) || !array_is_list($scenario['provider_calls'])) {
                throw new InvalidArgumentException('invalid_scenario_outcome_'.$id);
            }
            foreach (['successful_cost_micro_rub', 'estimated_minor', 'approved_minor', 'charged_minor'] as $field) {
                $this->nonnegativeInteger($scenario[$field] ?? null, $id.'.'.$field);
            }
            if ($traces['stage'] === 'actual' && (!is_string($scenario['execution_evidence_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $scenario['execution_evidence_sha256']))) {
                $reasons[] = 'missing_execution_evidence:'.$id;
            }
            $billable = 0;
            $completedAssistantCalls = 0;
            $verifiedModelCall = false;
            foreach ($scenario['provider_calls'] as $call) {
                $this->validateCall($call, $traces['stage'], $reasons, $id, $version);
                $model = $call['generative'] ? LunaModelPolicy::OPENAI : $call['model'];
                $providerModels[$model][$call['raw_model'] ?? $call['model']] = ($providerModels[$model][$call['raw_model'] ?? $call['model']] ?? 0) + 1;
                $totals[$call['kind']] += $call['cost_micro_rub'];
                $observedCostKinds[$call['kind']] = true;
                $hasProviderError = $hasProviderError || !$call['success'];
                if ($call['kind'] === 'assistant' && $call['success']) {
                    $completedAssistantCalls++;
                }
                if ($call['kind'] === 'assistant' && $call['success'] && $call['generative']
                    && $call['input_tokens'] + $call['output_tokens'] > 0 && ($call['evidence'] ?? null) === 'provider_usage'
                    && ($call['provider_usage_available'] ?? true) !== false && ($call['cost_available'] ?? true) !== false && ($call['cost_is_estimate'] ?? false) !== true
                    && (LunaModelPolicy::isLuna($call['model'], 'openai') || LunaModelPolicy::isLuna($call['model'], 'timeweb'))
                    && (!isset($call['raw_model']) || LunaModelPolicy::isLuna($call['raw_model'], 'openai') || LunaModelPolicy::isLuna($call['raw_model'], 'timeweb'))
                    && ($version === 1 || (in_array($call['usage_source'] ?? null, ['provider_response', 'provider_invoice'], true)
                        && $this->hasEvidenceHash($call['provider_evidence_sha256'] ?? null)))) {
                    $verifiedModelCall = true;
                }
                if ($call['success'] && in_array($call['kind'], ['assistant', 'memory', 'ocr'], true)) {
                    $billable += $call['cost_micro_rub'];
                }
                if (!$call['success']) {
                    $errorCost += $call['cost_micro_rub'];
                }
            }
            $serverRead = $version === 2 && $this->verifiedServerRead($scenario['server_read_evidence'] ?? null);
            if ($scenario['outcome'] === 'completed' && $completedAssistantCalls === 0 && !$serverRead) {
                $reasons[] = 'completed_without_assistant_call:'.$id;
            }
            $successfulCost += $billable;
            if ($billable !== $scenario['successful_cost_micro_rub']) {
                $reasons[] = 'successful_cost_mismatch:'.$id;
            }
            $expectedCharge = $scenario['outcome'] === 'completed' ? $this->charge($billable) : 0;
            if ($version === 2) {
                $this->nonnegativeInteger($scenario['projected_charge_minor'] ?? null, $id.'.projected_charge_minor');
                $expectedCharge = $scenario['outcome'] === 'completed'
                    ? $this->snapshotCharge($billable, $scenario['pricing_snapshot'] ?? null) : 0;
                if ($scenario['projected_charge_minor'] !== $expectedCharge) { $reasons[] = 'projected_charge_mismatch:'.$id; }
                if ($expectedCharge > $scenario['approved_minor']) {
                    $reasons[] = 'projected_charge_exceeds_approved_budget:'.$id;
                    $qualityFailures[] = $id.':approved_budget';
                }
            }
            $actualExpected = $chargingMode === 'shadow' ? 0 : ($version === 2 ? min($scenario['approved_minor'], $expectedCharge) : $expectedCharge);
            if ($scenario['charged_minor'] !== $actualExpected || $scenario['charged_minor'] > $scenario['approved_minor'] || $scenario['approved_minor'] > $scenario['estimated_minor']) {
                $reasons[] = 'charge_or_approval_mismatch:'.$id;
            }
            if ($profile === 'normal' && $scenario['outcome'] === 'completed') {
                $normal[] = $scenario['charged_minor'];
                if ($version === 2) { $normal[array_key_last($normal)] = $scenario['projected_charge_minor']; }
                $actualNormal[] = $scenario['charged_minor'];
            }
            $verifiedAssertions = true;
            foreach ($requiredAssertions as $assertion) {
                $check = $scenario['assertions'][$assertion] ?? null;
                if (!is_array($check) || !array_key_exists('expected', $check) || !array_key_exists('observed', $check) || !is_scalar($check['expected']) || !is_scalar($check['observed'])) {
                    throw new InvalidArgumentException('missing_quality_assertion:'.$id.':'.$assertion);
                }
                if ($check['expected'] !== $check['observed']) {
                    $verifiedAssertions = false;
                    $qualityFailures[] = $id.':'.$assertion;
                }
                if ($traces['stage'] === 'actual' && (!is_string($check['verifier'] ?? null) || trim($check['verifier']) === '' || !is_string($check['evidence_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $check['evidence_sha256']))) {
                    $verifiedAssertions = false;
                    $reasons[] = 'missing_verification_evidence:'.$id.':'.$assertion;
                }
            }
            if ($traces['stage'] === 'actual' && $scenario['outcome'] === 'completed' && $verifiedModelCall && $verifiedAssertions
                && $this->hasEvidenceHash($scenario['execution_evidence_sha256'] ?? null)
                && ($scenario['assertions']['business_quality']['expected'] ?? false) !== false
                && ($scenario['assertions']['business_quality']['expected'] ?? '') !== ''
                && ($economicsBasis !== 'prelaunch_projection' || (($scenario['useful_outcome'] ?? null) === true
                    && ($scenario['assertions']['business_quality']['expected'] ?? null) === true
                    && ($scenario['useful_evidence']['verified'] ?? null) === true
                    && is_string($scenario['useful_evidence']['verifier'] ?? null) && trim($scenario['useful_evidence']['verifier']) !== ''
                    && $this->hasEvidenceHash($scenario['useful_evidence']['evidence_sha256'] ?? null)))) {
                $verifiedModelInputs[$this->hash($distinctInput)] = true;
            }
            if ($economicsBasis === 'prelaunch_projection') {
                $evidence = $scenario['useful_evidence'] ?? null;
                if (!is_bool($scenario['useful_outcome'] ?? null) || !is_array($evidence) || ($evidence['verified'] ?? null) !== true || !is_string($evidence['verifier'] ?? null) || trim($evidence['verifier']) === '' || !$this->hasEvidenceHash($evidence['evidence_sha256'] ?? null)) {
                    $reasons[] = 'missing_useful_outcome_evidence:'.$id;
                } elseif ($scenario['useful_outcome']) {
                    if ($scenario['outcome'] !== 'completed' || ($scenario['assertions']['business_quality']['expected'] ?? null) !== true || ($scenario['assertions']['business_quality']['observed'] ?? null) !== true) {
                        $reasons[] = 'invalid_useful_outcome:'.$id;
                    } else {
                        $usefulSpend += $expectedCharge;
                        $usefulCount++;
                    }
                }
                $snapshot = $scenario['pricing_snapshot'] ?? [];
                if ($expectedCharge > 0 && (($snapshot['unit_minor'] ?? null) !== $this->policy['unit_minor'] || ($snapshot['unit_cost_micro_rub'] ?? null) !== (int) round($this->policy['rub_per_unit'] * 1000000)
                    || ($snapshot['minimum_minor'] ?? $snapshot['minimum_units_minor'] ?? null) !== $this->policy['minimum_units_minor'] || ($snapshot['charge_step_minor'] ?? null) !== $this->policy['charge_step_minor'])) {
                    $reasons[] = 'prelaunch_snapshot_policy_mismatch:'.$id;
                }
            }
        }
        foreach ($traces['background_calls'] as $call) {
            $this->validateCall($call, $traces['stage'], $reasons, 'background', $version);
            $model = $call['generative'] ? LunaModelPolicy::OPENAI : $call['model'];
            $providerModels[$model][$call['raw_model'] ?? $call['model']] = ($providerModels[$model][$call['raw_model'] ?? $call['model']] ?? 0) + 1;
            $totals[$call['kind']] += $call['cost_micro_rub'];
            $observedCostKinds[$call['kind']] = true;
            $hasProviderError = $hasProviderError || !$call['success'];
            if (!$call['success']) {
                $errorCost += $call['cost_micro_rub'];
            }
        }
        if ($traces['stage'] === 'actual') {
            if ($version === 2 && isset($observedCostKinds['memory']) && $this->verifiedNoExternalCalls($traces['cost_coverage']['memory'] ?? null)) {
                $reasons[] = 'memory_no_external_calls_conflicts_with_observed_calls';
            }
            foreach (['assistant', 'memory', 'index', 'ocr'] as $kind) {
                if (!isset($observedCostKinds[$kind]) && !($version === 2 && $kind === 'memory' && $this->verifiedNoExternalCalls($traces['cost_coverage']['memory'] ?? null))) {
                    $reasons[] = 'missing_observed_cost_kind:'.$kind;
                }
            }
            if (!$hasProviderError) {
                $reasons[] = 'missing_observed_provider_error';
            }
        }
        if (count($ids) < 200 || count($inputs) < 200) {
            $reasons[] = 'minimum_200_distinct_scenarios_required';
        }
        if (count($verifiedModelInputs) < 200) {
            $reasons[] = 'minimum_200_verified_model_quality_scenarios_required';
        }
        foreach ($requiredCategories as $category) {
            if (($categories[$category] ?? 0) < 2) {
                $reasons[] = 'insufficient_category_coverage:'.$category;
            }
        }
        foreach (['short', 'normal', 'detailed'] as $profile) {
            if (($profiles[$profile] ?? 0) < 1) {
                $reasons[] = 'missing_profile:'.$profile;
            }
        }
        if ($qualityFailures !== []) {
            $reasons[] = 'business_or_security_assertions_failed';
        }
        $externalCost = array_sum($totals) - $totals['estimate_generation'];
        $revenueMicroRub = $traces['assistant_revenue_minor'] * 10_000;
        $qualityReasons = $reasons;
        $priceProof = null;
        $projectedRevenue = 0;
        $economicsReasons = [];
        if ($economicsBasis === 'prelaunch_projection') {
            try {
                $priceProof = $this->prelaunchPricePolicyEvidence();
                if (!is_array($traces['price_policy_evidence'] ?? null) || $this->hash($traces['price_policy_evidence']) !== $this->hash($priceProof)) { $economicsReasons[] = 'prelaunch_price_policy_evidence_mismatch'; }
            } catch (\Throwable $exception) {
                $economicsReasons[] = 'prelaunch_price_policy_unavailable';
            }
            if ($traces['assistant_revenue_minor'] !== 0 || !empty($traces['revenue_evidence'])) { $economicsReasons[] = 'prelaunch_actual_revenue_must_be_zero'; }
            if ($chargingMode !== 'shadow') { $economicsReasons[] = 'prelaunch_requires_shadow_measurement'; }
            if ($usefulSpend > intdiv(PHP_INT_MAX, 798000)) { throw new InvalidArgumentException('economics_integer_overflow'); }
            $projectedRevenue = intdiv($usefulSpend * 798000, 100);
            if ($projectedRevenue <= 0 || $externalCost > intdiv($projectedRevenue * 3, 10)) { $economicsReasons[] = 'external_cost_exceeds_30_percent_of_prelaunch_projection'; }
        } else {
            if ($version === 2 && !$this->verifiedRevenue($traces['revenue_evidence'] ?? null, $traces['assistant_revenue_minor'], $traces['period'])) { $economicsReasons[] = 'actual_assistant_revenue_evidence_required'; }
            if ($revenueMicroRub <= 0 || $externalCost > $revenueMicroRub * 0.30) { $economicsReasons[] = 'external_cost_exceeds_30_percent_of_assistant_revenue'; }
        }
        $reasons = array_merge($reasons, $economicsReasons);
        if ($normal === []) {
            $reasons[] = 'no_completed_normal_scenarios';
            $qualityReasons[] = 'no_completed_normal_scenarios';
        }
        $inRange = count(array_filter($normal, fn (int $minor): bool => $minor >= 50 && $minor <= 150));
        $reasons = array_values(array_unique($reasons));
        $report = [
            'schema_version' => $version,
            'scenario_contract' => $contract,
            'partial_run' => $partialRun,
            'evaluator_version' => self::EVALUATOR_VERSION,
            'stage' => $traces['stage'],
            'policy_hash' => $this->policyFingerprint(),
            'traces_hash' => $this->hash($traces),
            'period' => $traces['period'],
            'ready_for_approval' => $reasons === [],
            'charging_mode' => $chargingMode,
            'economics_basis' => $economicsBasis,
            'economics_version' => $economicsBasis === 'prelaunch_projection' ? 1 : null,
            'implementation_fingerprint_version' => $economicsBasis === 'prelaunch_projection' ? 1 : null,
            'source_normalization' => $economicsBasis === 'prelaunch_projection' ? 'utf8_lf' : null,
            'assistant_implementation_fingerprint' => $economicsBasis === 'prelaunch_projection' ? ($traces['assistant_implementation_fingerprint'] ?? null) : null,
            'economics_ready' => $economicsReasons === [],
            'economics_reasons' => $economicsReasons,
            'price_policy_evidence' => $priceProof,
            'projected_assistant_revenue_micro_rub' => $economicsBasis === 'prelaunch_projection' ? $projectedRevenue : null,
            'external_cost_projected_revenue_ratio' => $projectedRevenue > 0 ? $externalCost / $projectedRevenue : null,
            'useful_projected_spend_minor' => $economicsBasis === 'prelaunch_projection' ? $usefulSpend : null,
            'useful_scenario_count' => $economicsBasis === 'prelaunch_projection' ? $usefulCount : null,
            'actual_revenue_monitoring' => ['assistant_revenue_minor' => $traces['assistant_revenue_minor'], 'status' => $economicsBasis === 'prelaunch_projection' ? 'awaiting_settled_assistant_revenue' : 'actual_revenue_evaluation'],
            'quality_ready' => $qualityReasons === [],
            'quality_reasons' => array_values(array_unique($qualityReasons)),
            'reasons' => $reasons,
            'scenario_count' => count($ids),
            'distinct_input_count' => count($inputs),
            'verified_model_quality_success_count' => count($verifiedModelInputs),
            'categories' => $categories,
            'requested_profiles' => $profiles,
            'quality_failures' => $qualityFailures,
            'costs_micro_rub' => $totals,
            'successful_billable_cost_micro_rub' => $successfulCost,
            'errors_cost_micro_rub' => $errorCost,
            'external_assistant_cost_micro_rub' => $externalCost,
            'assistant_revenue_minor' => $traces['assistant_revenue_minor'],
            'external_cost_revenue_ratio' => $revenueMicroRub > 0 ? $externalCost / $revenueMicroRub : null,
            'provider_models' => $providerModels,
            'provider_call_count' => array_sum(array_map(static fn (array $models): int => array_sum($models), $providerModels)),
            'pricing_projection' => $this->pricingProjection($normal, $externalCost),
            'actual_normal_spend_minor' => $actualNormal,
            'normal_spend_basis' => $version === 2 ? 'projected_immutable_quote' : 'actual_paid_charge',
            'revenue_evidence' => $version === 2 ? ($traces['revenue_evidence'] ?? null) : null,
            'normal_spend_minor' => ['count' => count($normal), 'min' => $normal === [] ? null : min($normal), 'max' => $normal === [] ? null : max($normal), 'mean' => $normal === [] ? null : array_sum($normal) / count($normal), 'within_50_150' => $inRange, 'outside_50_150' => count($normal) - $inRange],
        ];
        $report['report_hash'] = $this->hash($report);
        return $report;
    }

    public function approveReport(array $report, string $key, ?int $now = null): array
    {
        $now ??= time();
        $expires = $now + (int) ($this->policy['readiness_ttl_seconds'] ?? 604800);
        $approval = ['report' => $report, 'approved_at' => $now, 'expires_at' => $expires];
        $approval['signature'] = hash_hmac('sha256', $this->hash($approval), $key);
        if (!$this->verifyApproval($approval, $key, $now)) {
            throw new InvalidArgumentException('report_not_eligible_for_approval');
        }
        return $approval;
    }

    public function approveLaunchReport(array $report, string $key, string $releaseSha, ?int $now = null): array
    {
        if (($report['economics_basis'] ?? null) !== 'prelaunch_projection' || !preg_match('/^[a-f0-9]{40}$/D', $releaseSha) || $releaseSha !== ($this->policy['release_sha'] ?? null)) {
            throw new InvalidArgumentException('invalid_launch_approval_provenance');
        }
        $this->assistantImplementationFingerprint(true);
        $approval = $this->approveReport($report, $key, $now);
        unset($approval['signature']);
        $approval['approval_type'] = 'version_bound_launch';
        $approval['release_sha'] = $releaseSha;
        $approval['expires_at'] = null;
        $approval['signature'] = hash_hmac('sha256', $this->hash($approval), $key);
        if (!$this->verifyApproval($approval, $key, $now)) { throw new InvalidArgumentException('report_not_eligible_for_launch_approval'); }
        return $approval;
    }

    public function verifyApproval(array $approval, string $key, ?int $now = null): bool
    {
        $now ??= time();
        $report = $approval['report'] ?? null;
        $ttl = (int) ($this->policy['readiness_ttl_seconds'] ?? 604800);
        $type = $approval['approval_type'] ?? 'time_bound';
        $launch = $type === 'version_bound_launch';
        if (!in_array($type, ['time_bound', 'version_bound_launch'], true) || $key === '' || !is_array($report) || !is_int($approval['approved_at'] ?? null) || !is_string($approval['signature'] ?? null) || $approval['approved_at'] > $now || $ttl < 1) {
            return false;
        }
        if ($launch) {
            if (!array_key_exists('expires_at', $approval) || $approval['expires_at'] !== null || !is_string($approval['release_sha'] ?? null) || !preg_match('/^[a-f0-9]{40}$/D', $approval['release_sha']) || ($report['economics_basis'] ?? null) !== 'prelaunch_projection') { return false; }
        } elseif (!is_int($approval['expires_at'] ?? null) || $approval['expires_at'] <= $now || $approval['expires_at'] > $approval['approved_at'] + $ttl) { return false; }
        $unsigned = $approval;
        unset($unsigned['signature']);
        if (!hash_equals(hash_hmac('sha256', $this->hash($unsigned), $key), $approval['signature'])) {
            return false;
        }
        if (($report['ready_for_approval'] ?? null) !== true || ($report['stage'] ?? null) !== 'actual' || !in_array($report['schema_version'] ?? null, [1, self::SCHEMA_VERSION], true) || ($report['evaluator_version'] ?? null) !== self::EVALUATOR_VERSION || ($report['policy_hash'] ?? null) !== $this->policyFingerprint() || ($report['reasons'] ?? null) !== [] || ($report['quality_failures'] ?? null) !== [] || ($report['scenario_count'] ?? 0) < 200 || ($report['distinct_input_count'] ?? 0) < 200 || !is_int($report['verified_model_quality_success_count'] ?? null) || $report['verified_model_quality_success_count'] < 200 || $report['verified_model_quality_success_count'] > $report['distinct_input_count'] || !is_string($report['traces_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $report['traces_hash']) || !is_string($report['report_hash'] ?? null)) {
            return false;
        }
        $unsignedReport = $report;
        unset($unsignedReport['report_hash']);
        if (!hash_equals($this->hash($unsignedReport), $report['report_hash'])) {
            return false;
        }
        if (!is_array($report['period'] ?? null) || !is_string($report['period']['ended_at'] ?? null) || !is_array($report['categories'] ?? null)) {
            return false;
        }
        $basis = $report['economics_basis'] ?? 'actual_revenue';
        if ($basis === 'prelaunch_projection') {
            $spend = $report['useful_projected_spend_minor'] ?? null;
            $cost = $report['external_assistant_cost_micro_rub'] ?? null;
            if ($report['schema_version'] !== 2 || ($report['economics_version'] ?? null) !== 1 || ($report['implementation_fingerprint_version'] ?? null) !== 1 || ($report['source_normalization'] ?? null) !== 'utf8_lf' || ($report['assistant_implementation_fingerprint'] ?? null) !== $this->assistantImplementationFingerprint() || ($report['charging_mode'] ?? null) !== 'shadow' || ($report['quality_ready'] ?? null) !== true || ($report['quality_reasons'] ?? null) !== [] || ($report['economics_ready'] ?? null) !== true || ($report['economics_reasons'] ?? null) !== []
                || ($report['assistant_revenue_minor'] ?? null) !== 0 || ($report['external_cost_revenue_ratio'] ?? null) !== null || !empty($report['revenue_evidence']) || !is_int($spend) || $spend < 1 || $spend > intdiv(PHP_INT_MAX, 798000) || !is_int($cost) || $cost < 0 || ($report['useful_scenario_count'] ?? 0) < 1) { return false; }
            $projected = intdiv($spend * 798000, 100);
            if (($report['projected_assistant_revenue_micro_rub'] ?? null) !== $projected || $cost > intdiv($projected * 3, 10) || !is_numeric($report['external_cost_projected_revenue_ratio'] ?? null) || abs($report['external_cost_projected_revenue_ratio'] - $cost / $projected) > 0.000000000001) { return false; }
            try {
                if ($this->hash($report['price_policy_evidence'] ?? null) !== $this->hash($this->prelaunchPricePolicyEvidence())) { return false; }
            } catch (\Throwable $exception) { return false; }
        } elseif ($basis !== 'actual_revenue' || ($report['assistant_revenue_minor'] ?? 0) <= 0 || ($report['external_cost_revenue_ratio'] ?? 1) > 0.30 || ($report['schema_version'] === 2 && !$this->verifiedRevenue($report['revenue_evidence'] ?? null, $report['assistant_revenue_minor'], $report['period']))) {
            return false;
        }
        $contract = $report['scenario_contract'] ?? 'workflow_v1';
        if (!in_array($contract, ['workflow_v1', 'domain_v1'], true) || ($contract === 'domain_v1' && $report['schema_version'] !== 2) || ($report['partial_run'] ?? false) !== false) {
            return false;
        }
        $ended = strtotime($report['period']['ended_at']);
        if ($ended === false || $ended > $approval['approved_at'] || (!$launch && $ended < $now - $ttl) || ($launch && $ended < $approval['approved_at'] - $ttl)) {
            return false;
        }
        foreach ($contract === 'domain_v1' ? self::DOMAIN_CATEGORIES : self::CATEGORIES as $category) {
            if (($report['categories'][$category] ?? 0) < 2) {
                return false;
            }
        }
        foreach (['short', 'normal', 'detailed'] as $profile) {
            if (($report['requested_profiles'][$profile] ?? 0) < 1) { return false; }
        }
        return true;
    }

    private function hasEvidenceHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function verifiedNoExternalCalls(mixed $evidence): bool
    {
        return is_array($evidence) && ($evidence['mode'] ?? null) === 'no_external_calls'
            && ($evidence['verified'] ?? null) === true && is_string($evidence['verifier'] ?? null)
            && trim($evidence['verifier']) !== '' && $this->hasEvidenceHash($evidence['evidence_sha256'] ?? null)
            && ($evidence['observed_external_call_count'] ?? null) === 0
            && $this->hasEvidenceHash($evidence['source_code_sha256'] ?? null)
            && $this->hasEvidenceHash($evidence['record_snapshot_sha256'] ?? null);
    }

    private function verifiedServerRead(mixed $evidence): bool
    {
        return is_array($evidence) && ($evidence['verified'] ?? null) === true
            && is_string($evidence['verifier'] ?? null) && trim($evidence['verifier']) !== ''
            && $this->hasEvidenceHash($evidence['evidence_sha256'] ?? null)
            && is_array($evidence['source_receipts'] ?? null) && $evidence['source_receipts'] !== [];
    }

    private function verifiedRevenue(mixed $evidence, int $amount, array $period): bool
    {
        return $amount > 0 && is_array($evidence) && ($evidence['verified'] ?? null) === true
            && ($evidence['source'] ?? null) === 'settled_assistant_billing_ledger'
            && ($evidence['amount_minor'] ?? null) === $amount && is_array($evidence['period'] ?? null)
            && $this->hash($evidence['period']) === $this->hash($period)
            && is_string($evidence['verifier'] ?? null) && trim($evidence['verifier']) !== ''
            && $this->hasEvidenceHash($evidence['evidence_sha256'] ?? null)
            && is_array($evidence['settled_payment_ids'] ?? null) && $evidence['settled_payment_ids'] !== [];
    }

    private function snapshotCharge(int $cost, mixed $pricing): int
    {
        if (!is_array($pricing)) { throw new InvalidArgumentException('missing_immutable_pricing_snapshot'); }
        $minimum = $pricing['minimum_minor'] ?? $pricing['minimum_units_minor'] ?? null;
        foreach (['unit_minor', 'unit_cost_micro_rub', 'charge_step_minor'] as $field) {
            $this->nonnegativeInteger($pricing[$field] ?? null, 'pricing_snapshot.'.$field);
            if ($pricing[$field] < 1) { throw new InvalidArgumentException('invalid_immutable_pricing_snapshot'); }
        }
        $this->nonnegativeInteger($minimum, 'pricing_snapshot.minimum_minor');
        if ($cost > intdiv(PHP_INT_MAX, $pricing['unit_minor']) || $pricing['unit_cost_micro_rub'] > intdiv(PHP_INT_MAX, $pricing['charge_step_minor'])) {
            throw new InvalidArgumentException('charge_integer_overflow');
        }
        $numerator = $cost * $pricing['unit_minor'];
        $denominator = $pricing['unit_cost_micro_rub'] * $pricing['charge_step_minor'];
        $steps = intdiv($numerator, $denominator) + ($numerator % $denominator === 0 ? 0 : 1);
        if ($steps > intdiv(PHP_INT_MAX, $pricing['charge_step_minor'])) { throw new InvalidArgumentException('charge_integer_overflow'); }
        return max($minimum, $steps * $pricing['charge_step_minor']);
    }

    private function pricingProjection(array $normal, int $externalCost): array
    {
        $path = $this->moduleManifestPath ?? dirname(__DIR__, 3).'/config/ModuleList/addons/ai-assistant.json';
        $module = is_file($path) ? json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR) : [];
        $baseAmount = (int) round(($module['pricing']['base_price'] ?? 0) * 100);
        $baseUnits = (int) ($module['assistant_billing']['included_units_per_paid_period'] ?? 0) * $this->policy['unit_minor'];
        $spend = array_sum($normal);
        $offers = ['subscription' => ['units_minor' => $baseUnits, 'amount_minor' => $baseAmount]] + $this->policy['packs'];
        foreach ($offers as &$offer) {
            $offer['nominal_full_consumption_cost_micro_rub'] = $offer['units_minor'] / $this->policy['unit_minor'] * $this->policy['rub_per_unit'] * 1_000_000;
            $offer['nominal_cost_price_ratio'] = $offer['amount_minor'] > 0 ? $offer['nominal_full_consumption_cost_micro_rub'] / ($offer['amount_minor'] * 10_000) : null;
        }
        unset($offer);
        return ['projection_only' => true, 'eligible_for_approval' => false, 'actual_revenue_minor' => null,
            'source' => 'config/ModuleList/addons/ai-assistant.json + ai-assistant-credits.packs',
            'offers' => $offers, 'normal_projected_spend_minor' => $normal,
            'all_external_cost_micro_rub' => $externalCost, 'normal_projected_spend_total_minor' => $spend];
    }

    private function charge(int $costMicroRub): int
    {
        if ($costMicroRub > intdiv(PHP_INT_MAX, $this->policy['unit_minor'])) {
            throw new InvalidArgumentException('charge_integer_overflow');
        }
        $numerator = $costMicroRub * $this->policy['unit_minor'];
        $denominator = (int) round($this->policy['rub_per_unit'] * 1_000_000) * $this->policy['charge_step_minor'];
        $steps = intdiv($numerator, $denominator) + ($numerator % $denominator > 0 ? 1 : 0);
        return max($this->policy['minimum_units_minor'], $steps * $this->policy['charge_step_minor']);
    }

    private function validateCall(mixed $call, string $stage, array &$reasons, string $id, int $version): void
    {
        if (!is_array($call) || !in_array($call['kind'] ?? null, ['assistant', 'memory', 'index', 'ocr', 'estimate_generation'], true) || !is_bool($call['success'] ?? null) || !is_bool($call['generative'] ?? null) || !is_string($call['provider'] ?? null) || trim($call['provider']) === '' || !is_string($call['model'] ?? null) || trim($call['model']) === '') {
            throw new InvalidArgumentException('invalid_provider_call:'.$id);
        }
        foreach (['cost_micro_rub', 'input_tokens', 'output_tokens'] as $field) {
            $this->nonnegativeInteger($call[$field] ?? null, $id.'.'.$field);
        }
        if (array_key_exists('raw_model', $call) && (!is_string($call['raw_model']) || trim($call['raw_model']) === '')) {
            throw new InvalidArgumentException('invalid_raw_provider_model:'.$id);
        }
        if ($stage === 'actual' && ($call['evidence'] ?? null) !== 'provider_usage') {
            $reasons[] = 'missing_provider_usage:'.$id;
        }
        if ($call['generative'] && !LunaModelPolicy::isLuna($call['model'], 'openai') && !LunaModelPolicy::isLuna($call['model'], 'timeweb')) {
            $reasons[] = 'unexpected_generative_model:'.$id;
        }
        if ($call['generative'] && array_key_exists('raw_model', $call) && (!is_string($call['raw_model']) || (!LunaModelPolicy::isLuna($call['raw_model'], 'openai') && !LunaModelPolicy::isLuna($call['raw_model'], 'timeweb')))) {
            $reasons[] = 'unexpected_raw_generative_model:'.$id;
        }
        if ($call['kind'] !== 'index' && !$call['generative']) {
            $reasons[] = 'unexpected_nongenerative_call:'.$id;
        }
        if ($version === 2 && $stage === 'actual' && (!in_array($call['usage_source'] ?? null, ['provider_response', 'provider_invoice'], true) || !$this->hasEvidenceHash($call['provider_evidence_sha256'] ?? null))) {
            $reasons[] = 'unverified_provider_usage:'.$id;
        }
        if ($version === 2 && $stage === 'actual' && (($call['provider_usage_available'] ?? true) === false
            || ($call['cost_available'] ?? true) === false || ($call['cost_is_estimate'] ?? false) === true)) {
            $reasons[] = 'unavailable_provider_usage:'.$id;
        }
    }

    private function nonnegativeInteger(mixed $value, string $field): void
    {
        if (!is_int($value) || $value < 0 || $value > 1_000_000_000_000) {
            throw new InvalidArgumentException('invalid_nonnegative_integer:'.$field);
        }
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }
        return $value;
    }
}
