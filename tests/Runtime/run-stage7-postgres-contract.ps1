param(
    [string[]] $TestPath = @('tests/Feature/EstimateGeneration/Review/EstimateReviewExceptionsPostgresTest.php'),
    [string] $Container = 'most-ai-estimator-pg-contract'
)

$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$commonGitDirectory = (& git -C $root rev-parse --path-format=absolute --git-common-dir).Trim()
if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $commonGitDirectory -PathType Container)) {
    throw 'contract_repository_unavailable'
}
$referenceLauncher = Join-Path (Split-Path $commonGitDirectory -Parent) 'tests\Runtime\run-training-benchmark-contract.ps1'
if (-not (Test-Path -LiteralPath $referenceLauncher -PathType Leaf)) {
    throw 'contract_reference_launcher_missing'
}

& $referenceLauncher -Container $Container -AttestOnly
if ($LASTEXITCODE -ne 0) {
    throw 'contract_read_only_attestation_failed'
}

$testsRoot = [IO.Path]::GetFullPath((Join-Path $root 'tests') + [IO.Path]::DirectorySeparatorChar)
$resolvedTests = @(foreach ($path in $TestPath) {
    if ([IO.Path]::IsPathRooted($path)) {
        throw 'contract_test_path_must_be_relative'
    }
    $resolved = [IO.Path]::GetFullPath((Join-Path $root $path))
    if (-not $resolved.StartsWith($testsRoot, [StringComparison]::OrdinalIgnoreCase) -or -not (Test-Path -LiteralPath $resolved -PathType Leaf)) {
        throw 'contract_test_path_invalid'
    }
    $path
})

$database = 'most_ai_estimator_contract'
$role = 'most_contract_' + ([Guid]::NewGuid().ToString('N').Substring(0, 16))
$passwordBytes = New-Object byte[] 36
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
$rng.GetBytes($passwordBytes)
$rng.Dispose()
$password = [Convert]::ToBase64String($passwordBytes)
$environmentNames = @(
    'APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    'ESTIMATE_GENERATION_CONTRACT_DB_ROLE', 'ESTIMATE_CONTRACT_INSTANCE_ID',
    'ESTIMATE_CONTRACT_SERVER_ADDR', 'ESTIMATE_CONTRACT_SERVER_PORT',
    'ESTIMATE_CONTRACT_MARKER_OWNER', 'RUN_ESTIMATE_GENERATION_CONTRACT_PROVISIONER',
    'RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT'
)
$previousEnvironment = @{}
foreach ($name in $environmentNames) {
    $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}
$mutated = $false

function Invoke-ContractAdminSql([string] $sql) {
    $sql | docker exec -i $Container sh -lc 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d most_ai_estimator_contract -f -'
    if ($LASTEXITCODE -ne 0) {
        throw 'contract_admin_sql_failed'
    }
}

try {
    Invoke-ContractAdminSql @"
BEGIN;
DO `$`$
BEGIN
  IF current_database() <> '$database'
     OR (SELECT count(*) FROM contract_guard.instance_identity) <> 1
     OR (SELECT pg_get_userbyid(c.relowner) FROM pg_class c WHERE c.oid = 'contract_guard.instance_identity'::regclass) <> 'most_contract_guard' THEN
    RAISE EXCEPTION 'contract_atomic_attestation_failed';
  END IF;
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

    $facts = 'SELECT f.marker, pg_get_userbyid(c.relowner) FROM contract_guard.lock_instance_identity() f CROSS JOIN pg_class c WHERE c.oid = ''contract_guard.instance_identity''::regclass;' |
        docker exec -i $Container sh -lc 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d most_ai_estimator_contract -At -f -'
    if ($LASTEXITCODE -ne 0 -or $facts.Count -ne 1) {
        throw 'contract_facts_unavailable'
    }
    $parts = $facts.Split('|')
    $serverAddress = docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' $Container
    if ($parts.Count -ne 2 -or $null -eq ($serverAddress -as [Net.IPAddress])) {
        throw 'contract_facts_invalid'
    }

    $env:APP_ENV = 'testing'
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
    $env:RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT = '1'

    Push-Location $root
    try {
        php tests/Runtime/provision-estimate-generation-contract.php fresh
        if ($LASTEXITCODE -ne 0) {
            throw 'contract_provision_failed'
        }
        php vendor/bin/phpunit @resolvedTests
        if ($LASTEXITCODE -ne 0) {
            throw 'contract_tests_failed'
        }
    } finally {
        Pop-Location
    }
} finally {
    if ($mutated) {
        Invoke-ContractAdminSql @"
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
    }
    foreach ($name in $environmentNames) {
        [Environment]::SetEnvironmentVariable($name, $previousEnvironment[$name], 'Process')
    }
}
