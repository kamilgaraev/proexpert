param(
    [string] $TestPath = '',
    [string] $TestSuite = '',
    [string] $Filter = ''
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($TestPath) -and [string]::IsNullOrWhiteSpace($TestSuite)) {
    $TestPath = 'tests/Feature/Infrastructure/PhpUnitPostgresProfileTest.php'
}

if (-not [string]::IsNullOrWhiteSpace($TestPath) -and -not [string]::IsNullOrWhiteSpace($TestSuite)) {
    throw 'postgres_test_selection_conflict'
}

$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$testsRoot = [IO.Path]::GetFullPath((Join-Path $root 'tests') + [IO.Path]::DirectorySeparatorChar)
$phpunitConfigurationPath = Join-Path $root 'phpunit.xml'
[xml] $phpunitConfiguration = Get-Content -Raw -Encoding UTF8 $phpunitConfigurationPath

if (-not [string]::IsNullOrWhiteSpace($TestPath)) {
    if ([IO.Path]::IsPathRooted($TestPath)) {
        throw 'postgres_test_path_must_be_relative'
    }

    $resolvedTestPath = [IO.Path]::GetFullPath((Join-Path $root $TestPath))
    if (-not $resolvedTestPath.StartsWith($testsRoot, [StringComparison]::OrdinalIgnoreCase) -or -not (Test-Path -LiteralPath $resolvedTestPath -PathType Leaf)) {
        throw 'postgres_test_path_invalid'
    }
}

if (-not [string]::IsNullOrWhiteSpace($TestSuite)) {
    $knownSuites = @($phpunitConfiguration.phpunit.testsuites.testsuite | ForEach-Object { [string] $_.name })
    if ($TestSuite -cnotin $knownSuites) {
        throw 'postgres_test_suite_invalid'
    }
}

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
    & docker compose -p $projectName -f $composePath down --volumes --remove-orphans
    if ($LASTEXITCODE -ne 0) {
        throw 'postgres_test_container_reset_failed'
    }

    & docker compose -p $projectName -f $composePath up -d --wait --wait-timeout 60
    if ($LASTEXITCODE -ne 0) {
        throw 'postgres_test_container_start_failed'
    }

    $phpunitArguments = @('vendor/bin/phpunit', '-c', $phpunitConfigurationPath)
    if (-not [string]::IsNullOrWhiteSpace($TestSuite)) {
        $phpunitArguments += @('--testsuite', $TestSuite)
    } else {
        $phpunitArguments += $TestPath
    }
    if (-not [string]::IsNullOrWhiteSpace($Filter)) {
        $phpunitArguments += @('--filter', $Filter)
    }

    & php @phpunitArguments
    $testExitCode = $LASTEXITCODE
} finally {
    Pop-Location
}

exit $testExitCode
