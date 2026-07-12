param([ValidateSet('FAIL_SECOND_MOVE', 'FAIL_POST_VALIDATE', 'FAIL_BACKUP_CLEANUP')][string]$Scenario = 'FAIL_SECOND_MOVE')
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
        $env:MOST_LIBREDWG_TEST_FORCE_REBUILD = '1'
        Set-Item -Path "Env:MOST_LIBREDWG_TEST_$scenario" -Value '1'
        $failed = $false
        try { & $bootstrap -ArchivePath $archive | Out-Null } catch { $failed = $true }
        Remove-Item "Env:MOST_LIBREDWG_TEST_$scenario", Env:MOST_LIBREDWG_TEST_FORCE_REBUILD
        $after = (Get-FileHash -LiteralPath $marker -Algorithm SHA256).Hash
        $leftovers = @(Get-ChildItem -LiteralPath $env:MOST_LIBREDWG_CACHE -Directory | Where-Object { $_.Name -like 'win64.*' })
        $restored = & $bootstrap -ArchivePath $archive
        $version = (& $restored --version 2>&1 | Out-String).Trim()
        if (-not $failed -or $before -ne $after -or (Resolve-Path $restored).Path -ne (Resolve-Path $binary).Path -or $version -ne 'dwgread 0.13.4' -or $leftovers.Count -ne 0) {
            throw "publish_rollback_failed:$scenario"
        }
        Write-Output "publish-rollback-$scenario`: PASS"
    }
    'libredwg bootstrap rollback: PASS'
} finally {
    Get-ChildItem Env:MOST_LIBREDWG_TEST_* -ErrorAction SilentlyContinue | Remove-Item -ErrorAction SilentlyContinue
    Remove-Item Env:MOST_LIBREDWG_CACHE -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
