param(
    [string] $TestPath = '',
    [string] $TestSuite = '',
    [string] $Filter = '',
    [ValidateSet('phpunit', 'pest')]
    [string] $Runner = 'phpunit',
    [switch] $IsolatedAiAssistant,
    [switch] $LogProgressEvents
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

$selectedProfile = if ($IsolatedAiAssistant) { 'ai-assistant' } else { '' }
$selectedProject = if ($IsolatedAiAssistant) { 'most-ai-assistant-tests' } else { 'most-postgres-tests' }
if ($env:MOST_POSTGRES_TEST_PROFILE -and $env:MOST_POSTGRES_TEST_PROFILE -cne $selectedProfile) {
    throw 'postgres_test_profile_unsafe'
}
if ($env:COMPOSE_PROJECT_NAME -and $env:COMPOSE_PROJECT_NAME -cne $selectedProject) {
    throw 'postgres_test_project_unsafe'
}
$mutexName = if ($IsolatedAiAssistant) { 'Local\MostAIAssistantPostgresTests' } else { 'Local\MostPostgresTests' }
$pirTestsMutex = [System.Threading.Mutex]::new($false, $mutexName)
$pirTestsLockAcquired = $false
try {
    while (-not $pirTestsLockAcquired) {
        try {
            $pirTestsLockAcquired = $pirTestsMutex.WaitOne([TimeSpan]::FromSeconds(30))
        } catch [System.Threading.AbandonedMutexException] {
            $pirTestsLockAcquired = $true
        }
        if (-not $pirTestsLockAcquired) {
            Write-Host 'Ожидание завершения другого запуска PostgreSQL-тестов...'
        }
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
$projectName = $selectedProject
if ($IsolatedAiAssistant) {
    $composeContents = Get-Content -LiteralPath $composePath -Raw -Encoding UTF8
    if ([regex]::Matches($composeContents, '127\.0\.0\.1:55433:5432').Count -ne 1 -or
        $composeContents -notmatch 'image:\s*pgvector/pgvector:0\.8\.5-pg16-bookworm') {
        throw 'postgres_test_isolated_compose_source_unsafe'
    }
    $privateComposeDirectory = Join-Path $root 'storage/app/private/postgres-ai-assistant'
    New-Item -ItemType Directory -Path $privateComposeDirectory -Force | Out-Null
    $composePath = Join-Path $privateComposeDirectory 'compose.testing.yml'
    [IO.File]::WriteAllText($composePath, $composeContents.Replace('127.0.0.1:55433:5432', '127.0.0.1:55443:5432'), [Text.UTF8Encoding]::new($false))
}
$previousProfile = $env:MOST_POSTGRES_TEST_PROFILE

Push-Location $root
try {
    if (-not $IsolatedAiAssistant) {
        & docker compose -p $projectName -f $composePath down --volumes --remove-orphans
        if ($LASTEXITCODE -ne 0) {
            throw 'postgres_test_container_reset_failed'
        }
    }

    & docker compose --project-directory $root -p $projectName -f $composePath up -d --wait --wait-timeout 60
    if ($LASTEXITCODE -ne 0) {
        throw 'postgres_test_container_start_failed'
    }

    $phpunitArguments = @("vendor/bin/$Runner", '-c', $phpunitConfigurationPath)
    if (-not [string]::IsNullOrWhiteSpace($TestSuite)) {
        $phpunitArguments += @('--testsuite', $TestSuite)
    } else {
        $phpunitArguments += $TestPath
    }
    if (-not [string]::IsNullOrWhiteSpace($Filter)) {
        $phpunitArguments += @('--filter', $Filter)
    }

    if ($LogProgressEvents) {
        $eventsDirectory = [IO.Path]::GetFullPath((Join-Path $root 'storage/app/private/assistant-audit'))
        New-Item -ItemType Directory -Path $eventsDirectory -Force | Out-Null
        $eventsLogPath = Join-Path $eventsDirectory ((Get-Date -Format 'yyyyMMdd-HHmmss')+'-'+[guid]::NewGuid().ToString('N')+'.events.log')
        $phpunitArguments += @('--log-events-text', $eventsLogPath)
        Write-Host ('PHPUnit events: '+$eventsLogPath)
    }

    if ($IsolatedAiAssistant) { $env:MOST_POSTGRES_TEST_PROFILE = 'ai-assistant' }
    & php @phpunitArguments
    $testExitCode = $LASTEXITCODE
} finally {
    if ($null -eq $previousProfile) { Remove-Item Env:MOST_POSTGRES_TEST_PROFILE -ErrorAction SilentlyContinue }
    else { $env:MOST_POSTGRES_TEST_PROFILE = $previousProfile }
    Pop-Location
}

exit $testExitCode
} finally {
    if ($pirTestsLockAcquired) {
        $pirTestsMutex.ReleaseMutex()
    }
    $pirTestsMutex.Dispose()
}
