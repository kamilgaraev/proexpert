$ErrorActionPreference = 'Stop'
$launcherPath = Join-Path $PSScriptRoot 'run-postgres-tests.ps1'
$temporaryDirectory = Join-Path ([IO.Path]::GetTempPath()) ('most-postgres-launcher-check-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temporaryDirectory | Out-Null
$wrapperPath = Join-Path $temporaryDirectory 'wrapper.ps1'
$wrapper = @'
param([string] $LauncherPath, [string] $LogPath, [string] $Scenario)
$ErrorActionPreference = 'Stop'
function Write-Event($event) {
    $event | ConvertTo-Json -Compress -Depth 8 | Add-Content -LiteralPath $LogPath -Encoding UTF8
}
function docker {
    $arguments = @($args)
    $global:LASTEXITCODE = 0
    if ($arguments[0] -eq 'info') { '26.0.0'; return }
    if ($arguments[0] -eq 'ps') {
        if ($Scenario -ne 'busy-mutex' -and $Scenario -ne 'busy-port') { 'existing-foreign-container' }
        return
    }
    $project = $arguments[[array]::IndexOf($arguments, '-p') + 1]
    $compose = $arguments[[array]::IndexOf($arguments, '-f') + 1]
    if ($arguments -contains 'up') {
        Write-Event @{ operation = 'up'; project = $project; compose = [IO.File]::ReadAllText($compose) }
        if ($Scenario -eq 'start-failure') { $global:LASTEXITCODE = 7 }
    } elseif ($arguments -contains 'port') {
        if ($Scenario -eq 'unsafe-port') { '0.0.0.0:41321' } else { '127.0.0.1:41321' }
    } elseif ($arguments -contains 'down') {
        Write-Event @{ operation = 'down'; project = $project; arguments = $arguments; composePath = $compose }
        if ($Scenario -eq 'cleanup-failure') { $global:LASTEXITCODE = 9 }
    } else { throw 'unexpected_docker_command' }
}
function php {
    Write-Event @{ operation = 'php'; project = $env:MOST_POSTGRES_TEST_PROJECT; port = $env:MOST_POSTGRES_TEST_PORT; profile = $env:MOST_POSTGRES_TEST_PROFILE }
    $global:LASTEXITCODE = if ($Scenario -eq 'test-failure') { 3 } else { 0 }
}
foreach ($name in @('MOST_POSTGRES_TEST_PROFILE', 'MOST_POSTGRES_TEST_PORT', 'MOST_POSTGRES_TEST_PROJECT', 'COMPOSE_PROJECT_NAME')) {
    [Environment]::SetEnvironmentVariable($name, $null, 'Process')
}
try {
    if ($Scenario -eq 'busy-ai') { & $LauncherPath -IsolatedAiAssistant }
    else { & $LauncherPath }
    $exitCode = $LASTEXITCODE
} finally {
    Write-Event @{ operation = 'restored'; project = $env:MOST_POSTGRES_TEST_PROJECT; port = $env:MOST_POSTGRES_TEST_PORT; profile = $env:MOST_POSTGRES_TEST_PROFILE }
}
exit $exitCode
'@
[IO.File]::WriteAllText($wrapperPath, $wrapper, [Text.UTF8Encoding]::new($false))
$mutex = [Threading.Mutex]::new($false, 'Local\MostPostgresTests')
$mutexAcquired = $false
$portListener = $null
try {
    foreach ($scenario in @('existing-container', 'busy-mutex', 'busy-port', 'busy-ai', 'test-failure', 'start-failure', 'unsafe-port', 'cleanup-failure')) {
        $logPath = Join-Path $temporaryDirectory ($scenario + '.jsonl')
        $outputPath = Join-Path $temporaryDirectory ($scenario + '.out')
        if ($scenario -eq 'busy-mutex') {
            try { $mutexAcquired = $mutex.WaitOne(0) }
            catch [Threading.AbandonedMutexException] { $mutexAcquired = $true }
        }
        if ($scenario -eq 'busy-port') {
            $portListener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 55433)
            $portListener.Server.ExclusiveAddressUse = $true
            try { $portListener.Start() }
            catch [Net.Sockets.SocketException] { $portListener.Stop(); $portListener = $null }
        }
        try {
            & (Join-Path $PSHOME 'pwsh.exe') -NoProfile -File $wrapperPath -LauncherPath $launcherPath -LogPath $logPath -Scenario $scenario *> $outputPath
            $exitCode = $LASTEXITCODE
        } finally {
            if ($mutexAcquired) { $mutex.ReleaseMutex(); $mutexAcquired = $false }
            if ($portListener) { $portListener.Stop(); $portListener = $null }
        }
        $expectedExitCode = if ($scenario -eq 'test-failure') { 3 }
            elseif ($scenario -in @('start-failure', 'unsafe-port', 'cleanup-failure')) { 1 } else { 0 }
        if ($exitCode -ne $expectedExitCode) {
            throw ("${scenario}: unexpected exit code ${exitCode}. " + [IO.File]::ReadAllText($outputPath))
        }
        $events = @(Get-Content -LiteralPath $logPath | ForEach-Object { $_ | ConvertFrom-Json })
        $up = @($events | Where-Object operation -eq 'up')
        $down = @($events | Where-Object operation -eq 'down')
        $php = @($events | Where-Object operation -eq 'php')
        $restored = @($events | Where-Object operation -eq 'restored')
        $baseProject = if ($scenario -eq 'busy-ai') { 'most-ai-assistant-tests' } else { 'most-postgres-tests' }
        if ($up.Count -ne 1 -or $down.Count -ne 1 -or
            $up[0].project -notmatch ("^${baseProject}-[a-f0-9]{32}$") -or
            $down[0].project -ne $up[0].project -or
            $up[0].compose -notmatch '127\.0\.0\.1::5432' -or
            $down[0].arguments -notcontains '--volumes' -or
            $restored.Count -ne 1 -or $restored[0].project -or $restored[0].port -or $restored[0].profile) {
            throw "${scenario}: ownership, cleanup or environment restoration failed"
        }
        if ($scenario -in @('start-failure', 'unsafe-port')) {
            if ($php.Count -ne 0) { throw "${scenario}: PHP ran against an unsafe endpoint" }
        } elseif ($php.Count -ne 1 -or $php[0].port -ne '41321' -or $php[0].project -ne $up[0].project) {
            throw "${scenario}: runtime endpoint was not passed to PHP"
        }
        if ($scenario -eq 'cleanup-failure') {
            $retainedCompose = $down[0].composePath
            if (-not (Test-Path -LiteralPath $retainedCompose)) {
                throw 'cleanup-failure: recovery configuration was removed'
            }
            Remove-Item -LiteralPath $retainedCompose
            Remove-Item -LiteralPath (Split-Path -Parent $retainedCompose)
        }
        Write-Output "PASS ${scenario}"
    }
} finally {
    if ($mutexAcquired) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
    if ($portListener) { $portListener.Stop() }
    Get-ChildItem -LiteralPath $temporaryDirectory -File | Remove-Item
    Remove-Item -LiteralPath $temporaryDirectory
}
