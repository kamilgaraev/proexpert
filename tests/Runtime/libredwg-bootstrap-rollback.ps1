$ErrorActionPreference = 'Stop'
$archive = if ($env:MOST_LIBREDWG_TEST_ARCHIVE) { (Resolve-Path $env:MOST_LIBREDWG_TEST_ARCHIVE).Path } else { throw 'MOST_LIBREDWG_TEST_ARCHIVE_required' }
$bootstrap = Join-Path $PSScriptRoot 'bootstrap-libredwg-runtime.ps1'
$root = Join-Path ([IO.Path]::GetTempPath()) ('most-libredwg-rollback-' + [guid]::NewGuid().ToString('N'))
$env:MOST_LIBREDWG_CACHE = Join-Path $root 'cache'
try {
    $null = & $bootstrap -ArchivePath $archive
    $marker = Join-Path $env:MOST_LIBREDWG_CACHE 'win64\most-libredwg-install.json'
    $before = (Get-FileHash -LiteralPath $marker -Algorithm SHA256).Hash
    $env:MOST_LIBREDWG_TEST_FORCE_REBUILD = '1'
    $env:MOST_LIBREDWG_TEST_FAIL_SECOND_MOVE = '1'
    $failed = $false
    try { & $bootstrap -ArchivePath $archive | Out-Null } catch { $failed = $_.Exception.Message -match 'publish' }
    Remove-Item Env:MOST_LIBREDWG_TEST_FORCE_REBUILD
    Remove-Item Env:MOST_LIBREDWG_TEST_FAIL_SECOND_MOVE
    $after = (Get-FileHash -LiteralPath $marker -Algorithm SHA256).Hash
    if (-not $failed -or $before -ne $after) { throw 'publish_rollback_failed' }
    'libredwg bootstrap rollback: PASS'
} finally {
    Remove-Item Env:MOST_LIBREDWG_CACHE,Env:MOST_LIBREDWG_TEST_FORCE_REBUILD,Env:MOST_LIBREDWG_TEST_FAIL_SECOND_MOVE -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
