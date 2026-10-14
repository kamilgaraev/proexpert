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
$testsMutex = [System.Threading.Mutex]::new($false, $mutexName)
$lockAcquired = $false
$composePath = Join-Path $root 'compose.testing.yml'
$projectName = $selectedProject
$temporaryDirectory = $null
$cleanupRequired = $false
$cleanupSucceeded = $true
$locationPushed = $false
$environmentNames = @('MOST_POSTGRES_TEST_PROFILE', 'MOST_POSTGRES_TEST_PORT', 'MOST_POSTGRES_TEST_PROJECT')
$previousEnvironment = @{}
foreach ($name in $environmentNames) {
    $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}
if ($env:MOST_POSTGRES_TEST_PORT -or $env:MOST_POSTGRES_TEST_PROJECT) {
    $testsMutex.Dispose()
    throw 'postgres_test_runtime_environment_unsafe'
}
try {
    try {
        $lockAcquired = $testsMutex.WaitOne(0)
    } catch [System.Threading.AbandonedMutexException] {
        $lockAcquired = $true
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

    $defaultPort = if ($IsolatedAiAssistant) { 55443 } else { 55433 }
    $usePrivateContainer = -not $lockAcquired
    if ($lockAcquired) {
        $existingContainers = @(& docker ps -aq --filter "label=com.docker.compose.project=$selectedProject")
        if ($LASTEXITCODE -ne 0) {
            throw 'postgres_test_container_inventory_failed'
        }
        $usePrivateContainer = $existingContainers.Count -gt 0
        if (-not $usePrivateContainer) {
            $portProbe = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, $defaultPort)
            $portProbe.Server.ExclusiveAddressUse = $true
            try {
                $portProbe.Start()
            } catch [Net.Sockets.SocketException] {
                $usePrivateContainer = $true
            } finally {
                $portProbe.Stop()
            }
        }
    }

    if ($usePrivateContainer) {
        $projectName = $selectedProject + '-' + [guid]::NewGuid().ToString('N')
        if ($lockAcquired) {
            $testsMutex.ReleaseMutex()
            $lockAcquired = $false
        }
    }

    $composeContents = Get-Content -LiteralPath $composePath -Raw -Encoding UTF8
    if ([regex]::Matches($composeContents, '127\.0\.0\.1:55433:5432').Count -ne 1 -or
        $composeContents -notmatch 'image:\s*pgvector/pgvector:0\.8\.5-pg16-bookworm') {
        throw 'postgres_test_isolated_compose_source_unsafe'
    }
    $temporaryDirectory = Join-Path ([IO.Path]::GetTempPath()) ('most-postgres-tests-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $temporaryDirectory | Out-Null
    if ($IsolatedAiAssistant -or $usePrivateContainer) {
        $binding = if ($usePrivateContainer) { '127.0.0.1::5432' } else { "127.0.0.1:${defaultPort}:5432" }
        $composePath = Join-Path $temporaryDirectory 'compose.testing.yml'
        [IO.File]::WriteAllText($composePath, $composeContents.Replace('127.0.0.1:55433:5432', $binding), [Text.UTF8Encoding]::new($false))
    }

    Push-Location $root
    $locationPushed = $true
    if (-not $usePrivateContainer) {
        & docker compose --project-directory $root -p $projectName -f $composePath down --volumes --remove-orphans
        if ($LASTEXITCODE -ne 0) {
            throw 'postgres_test_container_reset_failed'
        }
    }

    $cleanupRequired = $true
    & docker compose --project-directory $root -p $projectName -f $composePath up -d --wait --wait-timeout 60
    if ($LASTEXITCODE -ne 0) {
        throw 'postgres_test_container_start_failed'
    }

    $publishedAddress = (& docker compose --project-directory $root -p $projectName -f $composePath port postgres-testing 5432 | Out-String).Trim()
    if ($LASTEXITCODE -ne 0 -or $publishedAddress -notmatch '^127\.0\.0\.1:(\d+)$') {
        throw 'postgres_test_container_port_unsafe'
    }
    $publishedPort = [int] $Matches[1]
    if ($publishedPort -lt 1024 -or $publishedPort -gt 65535 -or $publishedPort -eq 5432 -or
        (-not $usePrivateContainer -and $publishedPort -ne $defaultPort)) {
        throw 'postgres_test_container_port_unsafe'
    }
    $env:MOST_POSTGRES_TEST_PROFILE = $selectedProfile
    $env:MOST_POSTGRES_TEST_PORT = [string] $publishedPort
    $env:MOST_POSTGRES_TEST_PROJECT = $projectName
    Write-Host ("PostgreSQL tests: ${projectName}, 127.0.0.1:${publishedPort}")

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
        $eventsLogPath = Join-Path $temporaryDirectory 'phpunit.events.log'
        $phpunitArguments += @('--log-events-text', $eventsLogPath)
        Write-Host ('PHPUnit events: '+$eventsLogPath)
    }

    & php @phpunitArguments
    $testExitCode = $LASTEXITCODE
} finally {
    try {
        if ($cleanupRequired) {
            $cleanupSucceeded = $false
            & docker compose --project-directory $root -p $projectName -f $composePath down --volumes --remove-orphans
            if ($LASTEXITCODE -ne 0) {
                throw "postgres_test_container_cleanup_failed: $projectName ($composePath)"
            }
            $cleanupSucceeded = $true
        }
    } finally {
        foreach ($name in $environmentNames) {
            [Environment]::SetEnvironmentVariable($name, $previousEnvironment[$name], 'Process')
        }
        if ($locationPushed) { Pop-Location }
        if ($lockAcquired) { $testsMutex.ReleaseMutex() }
        $testsMutex.Dispose()
        if ($temporaryDirectory -and $cleanupSucceeded) {
            $temporaryCompose = Join-Path $temporaryDirectory 'compose.testing.yml'
            if (Test-Path -LiteralPath $temporaryCompose) { Remove-Item -LiteralPath $temporaryCompose }
            if (@(Get-ChildItem -LiteralPath $temporaryDirectory -Force).Count -eq 0) {
                Remove-Item -LiteralPath $temporaryDirectory
            }
        }
    }
}

exit $testExitCode
