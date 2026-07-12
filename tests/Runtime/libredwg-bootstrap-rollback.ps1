param([ValidateSet('FAIL_SECOND_MOVE', 'FAIL_POST_VALIDATE', 'FAIL_RETIRED_CLEANUP', 'CRASH_STATE_RESTORE')][string]$Scenario = 'FAIL_SECOND_MOVE')
$ErrorActionPreference = 'Stop'
$archive = if ($env:MOST_LIBREDWG_TEST_ARCHIVE) { (Resolve-Path $env:MOST_LIBREDWG_TEST_ARCHIVE).Path } else { throw 'MOST_LIBREDWG_TEST_ARCHIVE_required' }
$bootstrap = Join-Path $PSScriptRoot 'bootstrap-libredwg-runtime.ps1'
$root = Join-Path ([IO.Path]::GetTempPath()) ('most-libredwg-rollback-' + [guid]::NewGuid().ToString('N'))
try {
    foreach ($scenario in @($Scenario)) {
        $env:MOST_LIBREDWG_CACHE = Join-Path $root $scenario
        $binary = & $bootstrap -ArchivePath $archive
        $marker = Join-Path $env:MOST_LIBREDWG_CACHE 'win64\most-libredwg-install.json'
        $before = (Get-FileHash -LiteralPath $marker -Algorithm SHA256).Hash
        if ($scenario -eq 'CRASH_STATE_RESTORE') {
            $backup = Join-Path $env:MOST_LIBREDWG_CACHE 'win64.backup.seeded'
            [IO.Directory]::Move((Join-Path $env:MOST_LIBREDWG_CACHE 'win64'), $backup)
            New-Item -ItemType Directory -Path (Join-Path $env:MOST_LIBREDWG_CACHE 'win64') | Out-Null
            [IO.File]::WriteAllText((Join-Path $env:MOST_LIBREDWG_CACHE 'win64\dwgread.exe'), 'invalid')
            $sentinel = Join-Path $root 'invalid-version-called'
            $env:MOST_LIBREDWG_TEST_VERSION_CALLED = $sentinel
            $restored = & $bootstrap -ArchivePath (Join-Path $root 'missing-archive.zip')
            Remove-Item Env:MOST_LIBREDWG_TEST_VERSION_CALLED
            $version = (& $restored --version 2>&1 | Out-String).Trim()
            $leftovers = @(Get-ChildItem -LiteralPath $env:MOST_LIBREDWG_CACHE -Directory | Where-Object { $_.Name -like 'win64.*' })
            $launchedHashes = @(Get-Content -LiteralPath $sentinel)
            if ($launchedHashes.Count -eq 0 -or @($launchedHashes | Where-Object { $_ -ne '88f3c398bc1ff5a83c365fe8180018ef26947a63fff21fad8a032dd056a47c94' }).Count -ne 0 -or
                $version -ne 'dwgread 0.13.4' -or $leftovers.Count -ne 0 -or (Get-FileHash $marker).Hash -ne $before) {
                throw 'crash_state_restore_failed'
            }
            Write-Output 'crash-state-restore: PASS'
            continue
        }
        $env:MOST_LIBREDWG_TEST_FORCE_REBUILD = '1'
        Set-Item -Path "Env:MOST_LIBREDWG_TEST_$scenario" -Value '1'
        $failed = $false
        try { & $bootstrap -ArchivePath $archive | Out-Null } catch { $failed = $true }
        Remove-Item "Env:MOST_LIBREDWG_TEST_$scenario", Env:MOST_LIBREDWG_TEST_FORCE_REBUILD
        $after = (Get-FileHash -LiteralPath $marker -Algorithm SHA256).Hash
        $leftoversBeforeHit = @(Get-ChildItem -LiteralPath $env:MOST_LIBREDWG_CACHE -Directory | Where-Object { $_.Name -like 'win64.*' })
        $restored = & $bootstrap -ArchivePath $archive
        $leftovers = @(Get-ChildItem -LiteralPath $env:MOST_LIBREDWG_CACHE -Directory | Where-Object { $_.Name -like 'win64.*' })
        $version = (& $restored --version 2>&1 | Out-String).Trim()
        $expectedStale = if ($scenario -eq 'FAIL_RETIRED_CLEANUP') { 1 } else { 0 }
        if (-not $failed -or (Resolve-Path $restored).Path -ne (Resolve-Path $binary).Path -or $version -ne 'dwgread 0.13.4' -or
            $leftoversBeforeHit.Count -ne $expectedStale -or $leftovers.Count -ne 0 -or
            ($scenario -ne 'FAIL_RETIRED_CLEANUP' -and $before -ne $after)) {
            throw "publish_rollback_failed:$scenario"
        }
        Write-Output "publication-scenario-$scenario`: PASS"
    }
    'libredwg bootstrap rollback: PASS'
} finally {
    Get-ChildItem Env:MOST_LIBREDWG_TEST_* -ErrorAction SilentlyContinue | Remove-Item -ErrorAction SilentlyContinue
    Remove-Item Env:MOST_LIBREDWG_CACHE -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
