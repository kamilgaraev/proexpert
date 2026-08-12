param(
    [string] $TestPath = 'tests/Feature/Infrastructure/PostgresDatabaseSmokeTest.php'
)

$ErrorActionPreference = 'Stop'

$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$testsRoot = [IO.Path]::GetFullPath((Join-Path $root 'tests') + [IO.Path]::DirectorySeparatorChar)

if ([IO.Path]::IsPathRooted($TestPath)) {
    throw 'postgres_test_path_must_be_relative'
}

$resolvedTestPath = [IO.Path]::GetFullPath((Join-Path $root $TestPath))
if (-not $resolvedTestPath.StartsWith($testsRoot, [StringComparison]::OrdinalIgnoreCase) -or -not (Test-Path -LiteralPath $resolvedTestPath -PathType Leaf)) {
    throw 'postgres_test_path_invalid'
}

$phpunitConfigurationPath = Join-Path $root 'phpunit.postgres.xml'
[xml] $phpunitConfiguration = Get-Content -Raw -Encoding UTF8 $phpunitConfigurationPath
$environment = @{}
foreach ($node in $phpunitConfiguration.phpunit.php.env) {
    $environment[[string] $node.name] = [string] $node.value
}

if (
    $environment.DB_CONNECTION -ne 'pgsql' -or
    $environment.DB_HOST -ne '127.0.0.1' -or
    $environment.DB_PORT -ne '55433' -or
    $environment.DB_DATABASE -notmatch '_testing$'
) {
    throw 'postgres_test_database_configuration_unsafe'
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'docker_command_unavailable'
}

$dockerReady = $false
for ($attempt = 1; $attempt -le 30; $attempt++) {
    $dockerServerVersion = (& docker info --format '{{.ServerVersion}}' 2>&1 | Out-String).Trim()
    if ($LASTEXITCODE -eq 0 -and $dockerServerVersion -match '^\d+\.\d+') {
        $dockerReady = $true
        break
    }

    Start-Sleep -Seconds 2
}

if (-not $dockerReady) {
    throw 'docker_daemon_unavailable'
}

$composePath = Join-Path $root 'compose.testing.yml'
$projectName = 'most-postgres-tests'

Push-Location $root
try {
    & docker compose -p $projectName -f $composePath up -d --wait --wait-timeout 60
    if ($LASTEXITCODE -ne 0) {
        throw 'postgres_test_container_start_failed'
    }

    & php vendor/bin/phpunit -c $phpunitConfigurationPath $TestPath
    $testExitCode = $LASTEXITCODE
} finally {
    Pop-Location
}

exit $testExitCode
