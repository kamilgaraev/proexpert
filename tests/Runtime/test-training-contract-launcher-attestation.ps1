$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path))
$launcher = Join-Path $root 'tests\Runtime\run-training-benchmark-contract.ps1'
$container = 'most-ai-estimator-pg-contract'

function Get-MutationFingerprint {
    $sql = "SELECT count(*) FROM pg_roles WHERE rolname LIKE 'most_contract_%'; SELECT pg_get_userbyid(c.relowner) FROM pg_class c WHERE c.oid = 'public'::regnamespace; SELECT p.proacl::text FROM pg_proc p WHERE p.oid = 'contract_guard.lock_instance_identity()'::regprocedure;"
    return (($sql | docker exec -i $container sh -lc 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d most_ai_estimator_contract -At -f -') -join '|')
}

$before = Get-MutationFingerprint
cmd /c "docker rm -f most-ai-estimator-pg-contract-lookalike >nul 2>nul" | Out-Null
docker run -d --name most-ai-estimator-pg-contract-lookalike alpine:3.20 sleep 300 | Out-Null
try {
    & $launcher -Container 'most-ai-estimator-pg-contract-lookalike' -Passes 0
    throw 'lookalike_container_was_accepted'
} catch {
    if ($_.Exception.Message -notmatch 'contract_read_only_pre_attestation_failed') { throw }
} finally {
    docker rm -f most-ai-estimator-pg-contract-lookalike | Out-Null
}
$after = Get-MutationFingerprint
if ($after -ne $before) { throw 'lookalike_container_mutated_contract_state' }

$names = @('DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','ESTIMATE_GENERATION_CONTRACT_DB_ROLE','ESTIMATE_CONTRACT_INSTANCE_ID','ESTIMATE_CONTRACT_SERVER_ADDR','ESTIMATE_CONTRACT_SERVER_PORT','ESTIMATE_CONTRACT_MARKER_OWNER','RUN_ESTIMATE_GENERATION_CONTRACT_PROVISIONER','RUN_POSTGRES_TRAINING_BENCHMARK_CONTRACT','ESTIMATE_CONTRACT_INTERRUPT_ORDINAL','ESTIMATE_CONTRACT_FAIL_SECOND_TIMEOUT_SET')
$original = @{}
foreach ($name in $names) {
    $original[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
    [Environment]::SetEnvironmentVariable($name, 'sentinel_'+$name, 'Process')
}
try {
    & $launcher -Passes 0
    foreach ($name in $names) {
        if ([Environment]::GetEnvironmentVariable($name, 'Process') -ne 'sentinel_'+$name) { throw 'launcher_environment_not_restored' }
    }
} finally {
    foreach ($name in $names) { [Environment]::SetEnvironmentVariable($name, $original[$name], 'Process') }
}

Write-Output 'launcher attestation zero-mutation and environment restoration verified'
