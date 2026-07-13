param([int] $Passes = 2)

$ErrorActionPreference = 'Stop'
$container = 'most-ai-estimator-pg-contract'
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

try {
    Invoke-AdminSql @"
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
"@

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
        php tests/Runtime/provision-estimate-generation-contract.php training
        if ($LASTEXITCODE -ne 0) { throw "contract_provision_failed_pass_$pass" }
        vendor/bin/phpunit tests/Feature/EstimateGeneration/Benchmark/TrainingBenchmarkOnlineMigrationRuntimePostgresTest.php tests/Feature/EstimateGeneration/Benchmark/TrainingBenchmarkPostgresContractTest.php tests/Feature/EstimateGeneration/Benchmark/TrainingBenchmarkObjectAdoptionRaceTest.php
        if ($LASTEXITCODE -ne 0) { throw "contract_tests_failed_pass_$pass" }
        Write-Output "training benchmark contract pass $pass completed"
    }
} finally {
    Remove-Item Env:DB_PASSWORD -ErrorAction SilentlyContinue
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
