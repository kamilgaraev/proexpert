<?php

declare(strict_types=1);

namespace Tests\Support\EstimateGeneration;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

final class EstimateGenerationContractDatabaseProvisioner
{
    private const LOCK_FUNCTION_DEFINITION_SHA256 = '5485864f6b968742ea73b23de39fed9e33380d5f5649f924923352ef8e4510f8';

    private const INVENTORY_DIGEST = [
        'geometry' => '1b89b49a8e5fc5e37a50de5d8541c2dc1a93811df2a5fe9af7ac135eec73b77a',
        'training' => '370e3d84566a3dce7ce816a0364b3e481c2a0333dd5dfd45d772f9a386e50bcc',
        'pricing' => '370e3d84566a3dce7ce816a0364b3e481c2a0333dd5dfd45d772f9a386e50bcc',
    ];

    private const FRESH_INVENTORY_DIGEST = 'a636278b8a8f4b1313e94690d143516f69c721cdd138e8bbfe04043f91e5e5e4';

    private const SUBJECT = [
        'geometry' => [
            'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_000250_convert_session_payloads_to_jsonb.php',
            'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000900_guard_review_summary_source_version.php',
            'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_001050_upgrade_review_summary_freshness_guard.php',
        ],
        'pricing' => [
            'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001500_publish_accepted_evidence_and_close_pricing_provenance.php',
            'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001600_harden_accepted_evidence_mapping.php',
        ],
        'training' => [
            'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001700_rebuild_estimate_generation_training_and_benchmarks.php',
            'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001800_harden_estimate_generation_training_and_benchmarks.php',
            'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001900_close_training_benchmark_edge_contracts.php',
            'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_002000_enforce_training_benchmark_storage_contracts.php',
            'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_002100_finalize_training_benchmark_architecture.php',
            'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_002200_close_training_benchmark_races.php',
        ],
    ];

    private const SUBJECT_DIGEST = [
        'geometry' => '740d3b343d86ff7d386546fc9dd63a5d680b79df8f25ecd2d8e1fd46d88f5eab',
        'pricing' => '22c28514b665272f7e8cffeb911bf9bda48b098bd25947c04ee22bed41238158',
        'training' => 'ff394b33d8717a20622b4895a627e8784d987f0d11611601b5446fd59ee23026',
    ];

    private const CORE = [
        'database/migrations/0001_01_01_000000_create_users_table.php',
        'database/migrations/2025_01_01_000010_create_organizations_table.php',
        'database/migrations/2025_01_01_000015_create_measurement_units_table.php',
        'database/migrations/2025_01_01_000020_create_projects_table.php',
        'database/migrations/2025_01_01_000025_create_work_types_table.php',
        'database/migrations/2025_01_01_000030_create_contractors_table.php',
        'database/migrations/2025_01_01_000070_create_project_organization_table.php',
        'database/migrations/2025_05_03_161545_create_organization_user_table.php',
        'database/migrations/2025_05_03_161553_add_fields_to_users_table.php',
        'database/migrations/2025_05_03_173813_create_project_user_table.php',
        'database/migrations/2025_05_08_221011_add_accounting_fields_to_projects_table.php',
        'database/migrations/2025_05_15_000002_create_contracts_table.php',
        'database/migrations/2025_05_16_000001_add_customer_and_designer_to_projects_table.php',
        'database/migrations/2025_06_22_164437_add_verification_fields_to_organizations_table.php',
        'database/migrations/2025_09_12_000002_create_new_modules_table.php',
        'database/migrations/2025_09_12_000003_create_new_organization_module_activations_table.php',
        'database/migrations/2025_09_12_000004_add_can_deactivate_to_modules_table.php',
        'database/migrations/2025_09_12_200001_create_authorization_contexts_table.php',
        'database/migrations/2025_09_12_200002_create_user_role_assignments_table.php',
        'database/migrations/2025_09_12_200003_create_organization_custom_roles_table.php',
        'database/migrations/2025_09_12_200004_create_role_conditions_table.php',
        'database/migrations/2025_09_16_000000_add_extra_fields_to_projects_table.php',
        'database/migrations/2025_10_10_120230_add_coordinates_to_projects_table.php',
        'database/migrations/2025_10_17_163745_extend_project_organization_table.php',
        'database/migrations/2025_10_21_120000_create_estimates_table.php',
        'database/migrations/2025_10_21_120100_create_estimate_sections_table.php',
        'database/migrations/2025_10_21_120200_create_estimate_items_table.php',
        'database/migrations/2026_05_14_120000_add_project_access_mode_to_organization_user_table.php',
        'database/migrations/2026_05_14_120100_extend_project_user_assignments.php',
        'app/BusinessModules/Core/Mdm/migrations/2026_05_16_000000_create_mdm_core_tables.php',
        'app/BusinessModules/Core/Mdm/migrations/2026_05_16_010000_extend_mdm_product_tables.php',
    ];

    private const MODULE = [
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_03_24_100000_create_estimate_generation_sessions_table.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_03_24_100100_create_estimate_generation_documents_table.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_03_24_100200_create_estimate_generation_feedback_table.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_05_07_000001_create_estimate_normative_sources_tables.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_05_07_000002_add_estimate_norm_sections_table.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_05_08_000001_extend_estimate_resource_prices_for_machine_and_labor_components.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_05_08_000002_create_estimate_regional_price_tables.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_05_09_000001_create_estimate_generation_package_tables.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_05_29_100000_extend_estimate_generation_documents_for_ocr.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_05_29_100100_create_estimate_generation_document_pages_table.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_05_29_100200_create_estimate_generation_document_facts_table.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_05_30_000001_create_estimate_generation_learning_examples_table.php',
        'database/migrations/2026_06_28_000002_create_estimate_generation_understanding_tables.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000001_rebuild_estimate_generation_session_workflow.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000100_create_estimate_generation_pipeline_checkpoints_table.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000200_create_estimate_generation_processing_units_table.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000300_create_estimate_generation_evidence_table.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000400_create_estimate_generation_ai_usage_table.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000500_create_estimate_generation_failures_table.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000600_create_estimate_generation_finalization_outbox_table.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000900_guard_review_summary_source_version.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_001000_create_estimate_generation_building_models_table.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_001100_add_price_snapshots_to_estimate_generation_package_items.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_002000_create_estimate_generation_settings_and_budgets.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_000100_create_geometry_regeneration_outbox_table.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_000200_add_input_version_to_estimate_generation_packages.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_000250_convert_session_payloads_to_jsonb.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_000300_create_geometry_confirmations_table.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001000_add_normative_retrieval_contract.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001200_harden_estimate_generation_pricing_boundary.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001300_close_activated_pricing_catalog_insert_boundary.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001400_finalize_estimate_generation_pricing_boundary.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001500_publish_accepted_evidence_and_close_pricing_provenance.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_12_001600_harden_accepted_evidence_mapping.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_000100_add_estimate_generation_dashboard_indexes.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_000200_create_estimate_generation_admin_operations.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_000300_add_estimate_generation_resource_indexes.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_000400_add_training_dataset_trusted_review.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_000450_add_settings_snapshot_hash.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_000500_add_benchmark_execution_snapshot.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_000950_canonicalize_settings_snapshot_hashes.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_001000_create_ai_budget_reservations.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_001050_upgrade_review_summary_freshness_guard.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_001100_harden_ai_operation_budget_lifecycle.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_001125_create_canonical_settings_snapshot_hashes.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_001150_enforce_exactly_once_ai_budget_wire_claims.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_001200_close_truthful_settings_schema.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_18_000100_price_only_positive_normative_resources.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_18_000200_keep_zero_resource_price_inputs_compatible.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_18_000300_exclude_normative_summary_rows_from_pricing.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_18_000400_allow_pinned_fsbc_resource_prices.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_18_000500_backfill_norm_search_and_implicit_units.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_18_000600_scale_quantity_by_norm_unit.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_18_000700_parenthesize_pricing_evidence_unit.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_18_000800_price_project_selected_resources.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_19_000100_register_scaled_piece_unit_conversion.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_20_000100_finalize_supplementary_project_material_prices.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_20_000200_canonicalize_supplementary_project_material_price_fields.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_20_000300_separate_work_scenario_from_project_material_assumption.php',
        'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_20_000500_allow_published_regional_price_lifecycle_transitions.php',
    ];

    public static function assertSafe(array $connection, bool $enabled): void
    {
        $role = getenv('ESTIMATE_GENERATION_CONTRACT_DB_ROLE') ?: '';
        if (! $enabled
            || ($connection['driver'] ?? null) !== 'pgsql'
            || ($connection['host'] ?? null) !== '127.0.0.1'
            || (int) ($connection['port'] ?? 0) !== 55432
            || ($connection['database'] ?? null) !== 'most_ai_estimator_contract'
            || preg_match('/^[a-z][a-z0-9_]{2,62}$/D', $role) !== 1
            || ($connection['username'] ?? null) !== $role
            || ! str_ends_with((string) ($connection['database'] ?? ''), '_contract')) {
            throw new InvalidArgumentException('estimate_generation_contract_database_unsafe');
        }
    }

    public static function inventoryDigest(string $root, array $entries): string
    {
        $manifest = [];
        foreach ($entries as $entry) {
            $path = $root.DIRECTORY_SEPARATOR.$entry;
            $manifest[] = $entry.':'.(is_file($path) ? hash_file('sha256', $path) : 'missing');
        }

        return hash('sha256', implode("\n", $manifest));
    }

    public static function validateInventory(string $root, array $entries, string $expectedDigest): array
    {
        if ($entries === [] || count($entries) !== count(array_unique($entries, SORT_STRING))) {
            throw new InvalidArgumentException('estimate_generation_contract_inventory_invalid');
        }
        foreach ($entries as $entry) {
            if (! is_string($entry) || $entry === '' || str_contains($entry, '..')
                || ! is_file($root.DIRECTORY_SEPARATOR.$entry)) {
                throw new InvalidArgumentException('estimate_generation_contract_inventory_invalid');
            }
        }
        if (! hash_equals($expectedDigest, self::inventoryDigest($root, $entries))) {
            throw new InvalidArgumentException('estimate_generation_contract_inventory_tampered');
        }

        return $entries;
    }

    public static function validateAttestation(array $facts, array $expected): void
    {
        $valid = ($facts['database'] ?? null) === $expected['database']
            && ($facts['user'] ?? null) === $expected['user']
            && ($facts['session_user'] ?? null) === $expected['user']
            && ($facts['address'] ?? null) === $expected['address']
            && (int) ($facts['port'] ?? 0) === (int) $expected['port']
            && ($facts['marker'] ?? null) === $expected['marker']
            && (int) ($facts['marker_count'] ?? 0) === 1
            && ($facts['marker_owner'] ?? null) === $expected['marker_owner']
            && ($facts['marker_insert'] ?? null) === false
            && ($facts['marker_update'] ?? null) === false
            && ($facts['marker_delete'] ?? null) === false
            && ($facts['marker_truncate'] ?? null) === false
            && ($facts['marker_trigger'] ?? null) === false
            && ($facts['marker_references'] ?? null) === false
            && ($facts['column_insert'] ?? null) === false
            && ($facts['column_update'] ?? null) === false
            && ($facts['column_references'] ?? null) === false
            && ($facts['schema_create'] ?? null) === false
            && ($facts['owner_membership'] ?? null) === false
            && ($facts['owner_login'] ?? null) === false
            && ($facts['owner_superuser'] ?? null) === false;
        foreach (['superuser', 'createdb', 'createrole', 'replication', 'bypassrls'] as $flag) {
            $valid = $valid && ($facts[$flag] ?? null) === false;
        }
        if (! $valid) {
            throw new InvalidArgumentException('estimate_generation_contract_server_attestation_failed');
        }
    }

    public static function validateLockFunction(array $facts, string $expectedOwner): void
    {
        $runner = getenv('ESTIMATE_GENERATION_CONTRACT_DB_ROLE') ?: '';
        $expectedAcl = sprintf('{%1$s=X/%1$s,%2$s=X/%1$s}', $expectedOwner, $runner);
        if (($facts['signature'] ?? null) !== 'contract_guard.lock_instance_identity()'
            || ($facts['owner'] ?? null) !== $expectedOwner
            || ($facts['security_definer'] ?? null) !== true
            || ($facts['configuration'] ?? null) !== 'search_path=pg_catalog, contract_guard'
            || ($facts['language'] ?? null) !== 'plpgsql'
            || ($facts['volatility'] ?? null) !== 'v'
            || ($facts['acl'] ?? null) !== $expectedAcl
            || ($facts['definition_sha256'] ?? null) !== self::LOCK_FUNCTION_DEFINITION_SHA256) {
            throw new InvalidArgumentException('estimate_generation_contract_server_attestation_failed');
        }
    }

    public static function subjectInventory(string $phase, ?string $root = null): array
    {
        if (! isset(self::SUBJECT[$phase])) {
            throw new InvalidArgumentException('estimate_generation_contract_phase_invalid');
        }

        return $root === null
            ? self::SUBJECT[$phase]
            : self::validateInventory($root, self::SUBJECT[$phase], self::SUBJECT_DIGEST[$phase]);
    }

    public static function subjectMigration(string $phase, string $basename, string $root): string
    {
        if (basename($basename) !== $basename || ! str_ends_with($basename, '.php')) {
            throw new InvalidArgumentException('estimate_generation_contract_subject_invalid');
        }
        $matches = array_values(array_filter(self::subjectInventory($phase, $root),
            static fn (string $path): bool => basename($path) === $basename));
        if (count($matches) !== 1) {
            throw new InvalidArgumentException('estimate_generation_contract_subject_invalid');
        }

        return $root.DIRECTORY_SEPARATOR.$matches[0];
    }

    public static function completeInventory(): array
    {
        return array_values(array_unique([...self::CORE, ...self::MODULE, ...self::SUBJECT['training']], SORT_STRING));
    }

    public static function freshInventory(): array
    {
        $entries = self::inventory('training');
        $insertAt = array_search('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000001_rebuild_estimate_generation_session_workflow.php', $entries, true);
        if ($insertAt === false) {
            throw new InvalidArgumentException('estimate_generation_contract_inventory_invalid');
        }
        array_splice($entries, $insertAt, 0, [
            'database/migrations/2026_02_16_074305_create_system_admins_table.php',
            'database/migrations/2026_06_28_000004_create_estimate_generation_training_dataset_tables.php',
        ]);

        return [...$entries, ...self::SUBJECT['training']];
    }

    public static function inventory(string $phase = 'training'): array
    {
        $module = self::MODULE;
        if ($phase === 'geometry') {
            $module = array_values(array_filter($module, static fn (string $path): bool => ! str_contains($path, '_000250_convert_session_payloads_')
                && ! str_contains($path, '_000900_guard_review_summary_')
                && ! str_contains($path, '_001050_upgrade_review_summary_')));
        } elseif (! in_array($phase, ['training', 'pricing'], true)) {
            throw new InvalidArgumentException('estimate_generation_contract_phase_invalid');
        }

        return [...self::CORE, ...$module];
    }

    public static function provision(ConnectionInterface $connection, string $root, string $phase): void
    {
        $configuration = $connection->getConfig();
        self::assertSafe($configuration, getenv('RUN_ESTIMATE_GENERATION_CONTRACT_PROVISIONER') === '1');
        $entries = $phase === 'fresh'
            ? self::validateInventory($root, self::freshInventory(), self::FRESH_INVENTORY_DIGEST)
            : self::validateInventory($root, self::inventory($phase), self::INVENTORY_DIGEST[$phase] ?? '');
        self::validateCompleteInventory($root);
        self::resetPublicSchema($connection);
        $connection->statement('CREATE TABLE estimate_generation_contract_migrations (path text PRIMARY KEY, sha256 char(64) NOT NULL, applied_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        foreach ($entries as $entry) {
            $migration = require $root.DIRECTORY_SEPARATOR.$entry;
            $migration->up();
            $connection->table('estimate_generation_contract_migrations')->insert([
                'path' => $entry,
                'sha256' => hash_file('sha256', $root.DIRECTORY_SEPARATOR.$entry),
            ]);
        }
    }

    private static function validateCompleteInventory(string $root): void
    {
        $registered = self::completeInventory();
        foreach ($registered as $entry) {
            if (! is_file($root.DIRECTORY_SEPARATOR.$entry)) {
                throw new InvalidArgumentException('estimate_generation_contract_inventory_invalid');
            }
        }
        $actual = glob($root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/*.php');
        if (! is_array($actual)) {
            throw new InvalidArgumentException('estimate_generation_contract_inventory_invalid');
        }
        $actual = array_map(static fn (string $path): string => str_replace('\\', '/', substr($path, strlen($root) + 1)), $actual);
        $module = array_values(array_filter($registered, static fn (string $path): bool => str_starts_with($path, 'app/BusinessModules/Addons/EstimateGeneration/migrations/')));
        sort($actual, SORT_STRING);
        sort($module, SORT_STRING);
        if ($actual !== $module) {
            throw new InvalidArgumentException('estimate_generation_contract_inventory_unregistered');
        }
    }

    private static function resetPublicSchema(ConnectionInterface $connection): void
    {
        $expected = [
            'database' => 'most_ai_estimator_contract',
            'user' => getenv('ESTIMATE_GENERATION_CONTRACT_DB_ROLE') ?: '',
            'marker_owner' => getenv('ESTIMATE_CONTRACT_MARKER_OWNER') ?: '',
            'address' => getenv('ESTIMATE_CONTRACT_SERVER_ADDR') ?: '',
            'port' => (int) getenv('ESTIMATE_CONTRACT_SERVER_PORT'),
            'marker' => getenv('ESTIMATE_CONTRACT_INSTANCE_ID') ?: '',
        ];
        if (filter_var($expected['address'], FILTER_VALIDATE_IP) === false
            || $expected['port'] <= 0
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $expected['marker']) !== 1) {
            throw new InvalidArgumentException('estimate_generation_contract_server_attestation_failed');
        }

        $role = $expected['user'];
        $connection->beginTransaction();
        try {
            $lockFunction = (array) $connection->selectOne(<<<'SQL'
SELECT p.oid::regprocedure::text AS signature, pg_get_userbyid(p.proowner) AS owner,
       p.prosecdef AS security_definer, array_to_string(p.proconfig, ',') AS configuration,
       l.lanname AS language, p.provolatile AS volatility, p.proacl::text AS acl,
       pg_get_functiondef(p.oid) AS definition
FROM pg_proc p
JOIN pg_language l ON l.oid = p.prolang
WHERE p.oid = 'contract_guard.lock_instance_identity()'::regprocedure
SQL);
            $lockFunction['definition_sha256'] = hash('sha256', (string) ($lockFunction['definition'] ?? ''));
            self::validateLockFunction($lockFunction, $expected['marker_owner']);
            $markerRow = (array) $connection->selectOne('SELECT * FROM contract_guard.lock_instance_identity()');
            $row = (array) $connection->selectOne(<<<'SQL'
SELECT current_database() AS database, current_user AS "user", session_user AS session_user,
       host(inet_server_addr()) AS address, inet_server_port() AS port,
       role.rolsuper AS superuser, role.rolcreatedb AS createdb, role.rolcreaterole AS createrole,
       role.rolreplication AS replication, role.rolbypassrls AS bypassrls,
       pg_get_userbyid(cls.relowner) AS marker_owner,
       has_table_privilege(current_user, 'contract_guard.instance_identity', 'INSERT') AS marker_insert,
       has_table_privilege(current_user, 'contract_guard.instance_identity', 'UPDATE') AS marker_update,
       has_table_privilege(current_user, 'contract_guard.instance_identity', 'DELETE') AS marker_delete,
       has_table_privilege(current_user, 'contract_guard.instance_identity', 'TRUNCATE') AS marker_truncate,
       has_table_privilege(current_user, 'contract_guard.instance_identity', 'TRIGGER') AS marker_trigger,
       has_table_privilege(current_user, 'contract_guard.instance_identity', 'REFERENCES') AS marker_references,
       has_any_column_privilege(current_user, 'contract_guard.instance_identity', 'INSERT') AS column_insert,
       has_any_column_privilege(current_user, 'contract_guard.instance_identity', 'UPDATE') AS column_update,
       has_any_column_privilege(current_user, 'contract_guard.instance_identity', 'REFERENCES') AS column_references,
       has_schema_privilege(current_user, 'contract_guard', 'CREATE') AS schema_create,
       pg_has_role(current_user, pg_get_userbyid(cls.relowner), 'MEMBER') AS owner_membership,
       owner.rolcanlogin AS owner_login, owner.rolsuper AS owner_superuser
FROM pg_roles role
JOIN pg_class cls ON cls.oid = 'contract_guard.instance_identity'::regclass
JOIN pg_roles owner ON owner.oid = cls.relowner
WHERE role.rolname = current_user
SQL);
            $row['marker'] = $markerRow['marker'] ?? null;
            $row['marker_count'] = $markerRow['marker_count'] ?? 0;
            self::validateAttestation($row, $expected);
            $holdMilliseconds = (int) (getenv('ESTIMATE_CONTRACT_HOLD_AFTER_ATTEST_MS') ?: 0);
            if ($holdMilliseconds < 0 || $holdMilliseconds > 5000) {
                throw new InvalidArgumentException('estimate_generation_contract_server_attestation_failed');
            }
            if ($holdMilliseconds > 0) {
                usleep($holdMilliseconds * 1000);
            }
            $connection->unprepared('DROP SCHEMA public CASCADE');
            if (getenv('ESTIMATE_CONTRACT_INJECT_AFTER_DROP') === '1') {
                throw new \RuntimeException('estimate_generation_contract_injected_reset_failure');
            }
            $connection->unprepared('CREATE SCHEMA public AUTHORIZATION "'.$role.'"');
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            if ($exception instanceof InvalidArgumentException) {
                throw $exception;
            }
            throw new InvalidArgumentException('estimate_generation_contract_server_attestation_failed', previous: $exception);
        }
    }
}
