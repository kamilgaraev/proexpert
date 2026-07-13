param([int] $Passes = 2, [string] $Container = 'most-ai-estimator-pg-contract', [switch] $AttestOnly, [int] $OnlyOrdinal = 0)

$ErrorActionPreference = 'Stop'
$container = $Container
$database = 'most_ai_estimator_contract'
$role = 'most_contract_' + ([Guid]::NewGuid().ToString('N').Substring(0, 16))
$passwordBytes = New-Object byte[] 36
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
$rng.GetBytes($passwordBytes)
$rng.Dispose()
$password = [Convert]::ToBase64String($passwordBytes)

function Invoke-AdminSql([string] $sql) {
    $sql | docker exec -i $container sh -lc 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d most_ai_estimator_contract -f -'
    if ($LASTEXITCODE -ne 0) { throw 'contract_admin_sql_failed' }
}

function Test-ReadOnlyAttestation {
    $containerName = docker inspect -f '{{.Name}}' $container
    $imageName = docker inspect -f '{{.Config.Image}}' $container
    $hostPort = docker port $container 5432/tcp
    $containerAddress = docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' $container
    if ($LASTEXITCODE -ne 0 -or $containerName -ne '/most-ai-estimator-pg-contract' -or $imageName -ne 'postgres:16-alpine' -or $hostPort -notmatch ':55432$' -or $null -eq ($containerAddress -as [Net.IPAddress])) { return $false }
    $definitionBase64 = "SELECT encode(convert_to(pg_get_functiondef('contract_guard.lock_instance_identity()'::regprocedure), 'UTF8'), 'base64');" | docker exec -i $container sh -lc 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d most_ai_estimator_contract -At -f -'
    if ($LASTEXITCODE -ne 0 -or $definitionBase64.Count -lt 1) { return $false }
    $definitionBytes = [Convert]::FromBase64String(($definitionBase64 -join ''))
    $sha = [Security.Cryptography.SHA256]::Create()
    $definitionHash = ([BitConverter]::ToString($sha.ComputeHash($definitionBytes))).Replace('-', '').ToLowerInvariant()
    $sha.Dispose()
    if ($definitionHash -ne '5485864f6b968742ea73b23de39fed9e33380d5f5649f924923352ef8e4510f8') { return $false }
    $script:definitionAttestation = $definitionBase64 -join ''
    $sql = @'
BEGIN READ ONLY;
SELECT (
 current_database() = 'most_ai_estimator_contract'
 AND (SELECT count(*) = 1 FROM contract_guard.instance_identity)
 AND (SELECT pg_get_userbyid(c.relowner) = 'most_contract_guard' FROM pg_class c WHERE c.oid = 'contract_guard.instance_identity'::regclass)
 AND (SELECT p.oid::regprocedure::text = 'contract_guard.lock_instance_identity()'
      AND pg_get_userbyid(p.proowner) = 'most_contract_guard' AND p.prosecdef
      AND array_to_string(p.proconfig, ',') = 'search_path=pg_catalog, contract_guard'
      AND p.proacl::text = '{most_contract_guard=X/most_contract_guard,most_contract_runner=X/most_contract_guard}'
      FROM pg_proc p WHERE p.oid = 'contract_guard.lock_instance_identity()'::regprocedure)
 AND (SELECT NOT rolcanlogin AND NOT rolsuper AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolinherit AND NOT rolreplication AND NOT rolbypassrls FROM pg_roles WHERE rolname = 'most_contract_runner')
 AND current_user = session_user
);
ROLLBACK;
'@
    $result = $sql | docker exec -i $container sh -lc 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d most_ai_estimator_contract -At -f -'
    $booleans = @($result | Where-Object { $_ -in @('t', 'f') })
    return $LASTEXITCODE -eq 0 -and $booleans.Count -eq 1 -and $booleans[0] -eq 't'
}

$environmentNames = @('DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','ESTIMATE_GENERATION_CONTRACT_DB_ROLE','ESTIMATE_CONTRACT_INSTANCE_ID','ESTIMATE_CONTRACT_SERVER_ADDR','ESTIMATE_CONTRACT_SERVER_PORT','ESTIMATE_CONTRACT_MARKER_OWNER','RUN_ESTIMATE_GENERATION_CONTRACT_PROVISIONER','RUN_POSTGRES_TRAINING_BENCHMARK_CONTRACT','ESTIMATE_CONTRACT_INTERRUPT_ORDINAL','ESTIMATE_CONTRACT_FAIL_SECOND_TIMEOUT_SET')
$previousEnvironment = @{}
foreach ($name in $environmentNames) { $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
$mutated = $false

if (-not (Test-ReadOnlyAttestation)) { throw 'contract_read_only_pre_attestation_failed' }
if ($AttestOnly) { Write-Output 'contract read-only attestation completed'; exit 0 }

try {
    Invoke-AdminSql @"
BEGIN;
DO `$`$
BEGIN
  IF current_database() <> '$database'
     OR (SELECT count(*) FROM contract_guard.instance_identity) <> 1
     OR (SELECT pg_get_userbyid(c.relowner) FROM pg_class c WHERE c.oid = 'contract_guard.instance_identity'::regclass) <> 'most_contract_guard'
     OR NOT EXISTS (SELECT 1 FROM pg_proc p WHERE p.oid = 'contract_guard.lock_instance_identity()'::regprocedure
        AND p.oid::regprocedure::text = 'contract_guard.lock_instance_identity()'
        AND p.prosecdef AND pg_get_userbyid(p.proowner) = 'most_contract_guard'
        AND array_to_string(p.proconfig, ',') = 'search_path=pg_catalog, contract_guard'
        AND p.proacl::text = '{most_contract_guard=X/most_contract_guard,most_contract_runner=X/most_contract_guard}'
        AND replace(encode(convert_to(pg_get_functiondef(p.oid), 'UTF8'), 'base64'), E'\n', '') = '$definitionAttestation')
     OR NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'most_contract_runner' AND NOT rolcanlogin AND NOT rolsuper AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolinherit AND NOT rolreplication AND NOT rolbypassrls) THEN
    RAISE EXCEPTION 'contract_atomic_attestation_failed';
  END IF;
END `$`$;
DO `$`$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$role') THEN
    CREATE ROLE $role;
  END IF;
END `$`$;
ALTER ROLE $role LOGIN PASSWORD '$password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS;
GRANT CONNECT ON DATABASE $database TO $role;
GRANT CREATE ON DATABASE $database TO $role;
GRANT USAGE ON SCHEMA contract_guard TO $role;
REVOKE EXECUTE ON FUNCTION contract_guard.lock_instance_identity() FROM most_contract_runner;
GRANT EXECUTE ON FUNCTION contract_guard.lock_instance_identity() TO $role;
DROP SCHEMA IF EXISTS public CASCADE;
CREATE SCHEMA public AUTHORIZATION $role;
COMMIT;
"@
    $mutated = $true

    $facts = 'SELECT f.marker, pg_get_userbyid(c.relowner) FROM contract_guard.lock_instance_identity() f CROSS JOIN pg_class c WHERE c.oid = ''contract_guard.instance_identity''::regclass;' | docker exec -i $container sh -lc 'psql -U "$POSTGRES_USER" -d most_ai_estimator_contract -At -f -'
    if ($LASTEXITCODE -ne 0 -or $facts.Count -ne 1) { throw 'contract_facts_unavailable' }
    $parts = $facts.Split('|')
    if ($parts.Count -ne 2) { throw 'contract_facts_invalid' }
    $serverAddress = docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' $container
    if ($LASTEXITCODE -ne 0 -or $null -eq ($serverAddress -as [Net.IPAddress])) { throw 'contract_address_invalid' }

    $env:DB_CONNECTION = 'pgsql'
    $env:DB_HOST = '127.0.0.1'
    $env:DB_PORT = '55432'
    $env:DB_DATABASE = $database
    $env:DB_USERNAME = $role
    $env:DB_PASSWORD = $password
    $env:ESTIMATE_GENERATION_CONTRACT_DB_ROLE = $role
    $env:ESTIMATE_CONTRACT_INSTANCE_ID = $parts[0]
    $env:ESTIMATE_CONTRACT_SERVER_ADDR = $serverAddress
    $env:ESTIMATE_CONTRACT_SERVER_PORT = '5432'
    $env:ESTIMATE_CONTRACT_MARKER_OWNER = $parts[1]
    $env:RUN_ESTIMATE_GENERATION_CONTRACT_PROVISIONER = '1'
    $env:RUN_POSTGRES_TRAINING_BENCHMARK_CONTRACT = '1'

    for ($pass = 1; $pass -le $Passes; $pass++) {
        php tests/Runtime/provision-estimate-generation-contract.php fresh
        if ($LASTEXITCODE -ne 0) { throw "contract_fresh_provision_failed_pass_$pass" }
        vendor/bin/phpunit tests/Feature/EstimateGeneration/Benchmark/TrainingBenchmarkFreshInstallPostgresTest.php
        if ($LASTEXITCODE -ne 0) { throw "contract_fresh_tests_failed_pass_$pass" }
        $ordinal = 0
        $maximumObserved = 0
        $stableDiscoveries = 0
        do {
            php tests/Runtime/provision-estimate-generation-contract.php training
            if ($LASTEXITCODE -ne 0) { throw "contract_provision_failed_pass_${pass}_ordinal_$ordinal" }
            if ($ordinal -eq 0) { Remove-Item Env:ESTIMATE_CONTRACT_INTERRUPT_ORDINAL -ErrorAction SilentlyContinue } else { $env:ESTIMATE_CONTRACT_INTERRUPT_ORDINAL = [string]$ordinal }
            Write-Output "training benchmark ordinal pass $pass target $ordinal"
            $contractOutput = @(vendor/bin/phpunit tests/Feature/EstimateGeneration/Benchmark/TrainingBenchmarkPostgresContractTest.php 2>&1)
            $contractOutput | Write-Output
            if ($LASTEXITCODE -ne 0) { throw "contract_ordinal_failed_pass_${pass}_ordinal_$ordinal" }
            $countLine = @($contractOutput | Where-Object { $_ -match 'ESTIMATE_CONTRACT_CHECKPOINT_COUNT:(\d+)' })
            if ($countLine.Count -ne 1 -or $countLine[0] -notmatch 'ESTIMATE_CONTRACT_CHECKPOINT_COUNT:(\d+)') { throw 'contract_checkpoint_count_missing' }
            $observed = [int]$Matches[1]
            if ($ordinal -eq 0) {
                if ($observed -eq $maximumObserved) {
                    $stableDiscoveries++
                } else {
                    $maximumObserved = $observed
                    $stableDiscoveries = 0
                }
                if ($stableDiscoveries -lt 1) { continue }
                $ordinal = if ($OnlyOrdinal -gt 0) { $OnlyOrdinal } else { 1 }
            } else {
                $ordinal = if ($OnlyOrdinal -gt 0) { $maximumObserved + 1 } else { $ordinal + 1 }
            }
        } while ($ordinal -le $maximumObserved)
        Remove-Item Env:ESTIMATE_CONTRACT_INTERRUPT_ORDINAL -ErrorAction SilentlyContinue
        php tests/Runtime/provision-estimate-generation-contract.php training
        if ($LASTEXITCODE -ne 0) { throw "contract_final_provision_failed_pass_$pass" }
        vendor/bin/phpunit tests/Feature/EstimateGeneration/Benchmark/TrainingBenchmarkOnlineMigrationRuntimePostgresTest.php
        if ($LASTEXITCODE -ne 0) { throw "contract_runtime_tests_failed_pass_$pass" }
        vendor/bin/phpunit tests/Feature/EstimateGeneration/Benchmark/TrainingBenchmarkPostgresContractTest.php tests/Feature/EstimateGeneration/Benchmark/TrainingBenchmarkObjectAdoptionRaceTest.php
        if ($LASTEXITCODE -ne 0) { throw "contract_adoption_tests_failed_pass_$pass" }
        Write-Output "training benchmark ordinal convergence pass $pass completed at $maximumObserved checkpoints"
        Write-Output "training benchmark contract pass $pass completed"
    }
} finally {
    if ($mutated) {
        Invoke-AdminSql @"
SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE usename = '$role' AND pid <> pg_backend_pid();
DROP SCHEMA IF EXISTS public CASCADE;
CREATE SCHEMA public AUTHORIZATION CURRENT_USER;
REVOKE EXECUTE ON FUNCTION contract_guard.lock_instance_identity() FROM $role;
REVOKE USAGE ON SCHEMA contract_guard FROM $role;
REVOKE CONNECT ON DATABASE $database FROM $role;
REVOKE CREATE ON DATABASE $database FROM $role;
ALTER ROLE $role NOLOGIN PASSWORD NULL;
DROP ROLE IF EXISTS $role;
GRANT EXECUTE ON FUNCTION contract_guard.lock_instance_identity() TO most_contract_runner;
"@
        $remaining = "SELECT count(*) FROM pg_roles WHERE rolname = '$role';" | docker exec -i $container sh -lc 'psql -U "$POSTGRES_USER" -d most_ai_estimator_contract -At -f -'
        if ($LASTEXITCODE -ne 0 -or $remaining -ne '0') { throw 'contract_role_revocation_failed' }
        Write-Output 'ephemeral contract role revoked'
    }
    foreach ($name in $environmentNames) {
        [Environment]::SetEnvironmentVariable($name, $previousEnvironment[$name], 'Process')
        if ([Environment]::GetEnvironmentVariable($name, 'Process') -ne $previousEnvironment[$name]) { throw 'contract_environment_restore_failed' }
    }
}
