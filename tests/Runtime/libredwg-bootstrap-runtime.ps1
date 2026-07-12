$ErrorActionPreference = 'Stop'
$root = Join-Path ([IO.Path]::GetTempPath()) ('most-libredwg-tests-' + [guid]::NewGuid().ToString('N'))
$archive = if ($env:MOST_LIBREDWG_TEST_ARCHIVE) { (Resolve-Path $env:MOST_LIBREDWG_TEST_ARCHIVE).Path } else { Join-Path $root 'official.zip' }
$bootstrap = Join-Path $PSScriptRoot 'bootstrap-libredwg-runtime.ps1'
New-Item -ItemType Directory -Path $root | Out-Null
try {
    if (-not $env:MOST_LIBREDWG_TEST_ARCHIVE) {
        & curl.exe '--proto' '=https' '--proto-redir' '=https' '--tlsv1.2' '--fail' '--show-error' '--location' '--connect-timeout' '20' '--max-time' '180' '--retry' '2' '--output' $archive 'https://github.com/LibreDWG/libredwg/releases/download/0.13.4/libredwg-0.13.4-win64.zip'
        if ($LASTEXITCODE -ne 0) { throw 'test_archive_download_failed' }
    }

    $env:MOST_LIBREDWG_CACHE = Join-Path $root 'cache'
    $binary = & $bootstrap -ArchivePath $archive
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $binary)) { throw 'clean_install_failed' }
    Write-Output 'clean-install: PASS'

    $corrupt = Join-Path $root 'corrupt.zip'
    [IO.File]::WriteAllText($corrupt, 'corrupt')
    $second = & $bootstrap -ArchivePath $corrupt
    if ($second -ne $binary) { throw 'idempotent_marker_failed' }
    Write-Output 'idempotent-marker: PASS'

    $sentinel = Join-Path $root 'version-called.txt'
    $env:MOST_LIBREDWG_TEST_VERSION_CALLED = $sentinel
    Add-Content -LiteralPath (Join-Path $env:MOST_LIBREDWG_CACHE 'win64\libredwg-0.dll') -Value 'mutated'
    $failed = $false
    try { & $bootstrap -ArchivePath $corrupt | Out-Null } catch { $failed = $true }
    if (-not $failed -or (Test-Path -LiteralPath $sentinel)) {
        $trace = if (Test-Path $sentinel) { Get-Content $sentinel -Raw } else { 'none' }
        throw "mutated_dll_was_executed:$trace"
    }

    Remove-Item Env:MOST_LIBREDWG_TEST_VERSION_CALLED
    Write-Output 'mutated-dll-no-launch: PASS'
    $binary = & $bootstrap -ArchivePath $archive
    $env:MOST_LIBREDWG_TEST_VERSION_CALLED = $sentinel
    [IO.File]::WriteAllText((Join-Path $env:MOST_LIBREDWG_CACHE 'win64\extra.exe'), 'extra')
    $failed = $false
    try { & $bootstrap -ArchivePath $corrupt | Out-Null } catch { $failed = $true }
    if (-not $failed -or (Test-Path -LiteralPath $sentinel)) { throw 'extra_file_was_trusted' }
    Remove-Item Env:MOST_LIBREDWG_TEST_VERSION_CALLED
    Write-Output 'extra-file-no-launch: PASS'

    $binary = & $bootstrap -ArchivePath $archive
    $junctionTarget = Join-Path $root 'junction-target'
    New-Item -ItemType Directory -Path $junctionTarget | Out-Null
    $junction = Join-Path $env:MOST_LIBREDWG_CACHE 'win64\extra-junction'
    & cmd.exe /c "mklink /J `"$junction`" `"$junctionTarget`"" | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'junction_fixture_failed' }
    $env:MOST_LIBREDWG_TEST_VERSION_CALLED = $sentinel
    $failed = $false
    try { & $bootstrap -ArchivePath $corrupt | Out-Null } catch { $failed = $true }
    if (-not $failed -or (Test-Path -LiteralPath $sentinel)) { throw 'reparse_cache_was_trusted' }
    Remove-Item Env:MOST_LIBREDWG_TEST_VERSION_CALLED
    $junctionAfterReconcile = if (Test-Path -LiteralPath $junction) { $junction } else {
        $failedGeneration = Get-ChildItem -LiteralPath $env:MOST_LIBREDWG_CACHE -Directory | Where-Object { $_.Name -like 'win64.failed.*' } | Select-Object -First 1
        if ($failedGeneration) { Join-Path $failedGeneration.FullName 'extra-junction' } else { $null }
    }
    if ($junctionAfterReconcile -and (Test-Path -LiteralPath $junctionAfterReconcile)) {
        & cmd.exe /c "rmdir `"$junctionAfterReconcile`"" | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'junction_cleanup_failed' }
    }
    Write-Output 'reparse-no-launch: PASS'

    Remove-Item -LiteralPath $env:MOST_LIBREDWG_CACHE -Recurse -Force
    New-Item -ItemType Directory -Path (Join-Path $env:MOST_LIBREDWG_CACHE 'win64') -Force | Out-Null
    [IO.File]::WriteAllText((Join-Path $env:MOST_LIBREDWG_CACHE 'win64\partial.tmp'), 'partial')
    $failed = $false
    try { & $bootstrap -ArchivePath $archive | Out-Null } catch { $failed = $true }
    if (-not $failed) { throw 'partial_cache_did_not_fail_closed' }
    $binary = & $bootstrap -ArchivePath $archive
    if (-not (Test-Path -LiteralPath $binary)) { throw 'partial_cache_recovery_failed' }
    Write-Output 'partial-cache-recovery: PASS'

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $traversal = Join-Path $root 'traversal.zip'
    $stream = [IO.File]::Open($traversal, [IO.FileMode]::CreateNew)
    $zip = [IO.Compression.ZipArchive]::new($stream, [IO.Compression.ZipArchiveMode]::Create)
    try {
        1..74 | ForEach-Object { $null = $zip.CreateEntry("safe-$_.txt") }
        $null = $zip.CreateEntry('../escape.txt')
    } finally { $zip.Dispose(); $stream.Dispose() }
    $failed = $false
    try { & $bootstrap -InspectArchive $traversal | Out-Null } catch { $failed = $_.Exception.Message -match 'path' }
    if (-not $failed) { throw 'traversal_archive_accepted' }
    Write-Output 'traversal-rejection: PASS'

    $swapArchive = Join-Path $root 'swap.zip'
    Copy-Item -LiteralPath $archive -Destination $swapArchive
    $swapCache = Join-Path $root 'swap-cache'
    $env:MOST_LIBREDWG_CACHE = $swapCache
    $env:MOST_LIBREDWG_TEST_SWAP_SOURCE_AFTER_COPY = $traversal
    $swapBinary = & $bootstrap -ArchivePath $swapArchive
    Remove-Item Env:MOST_LIBREDWG_TEST_SWAP_SOURCE_AFTER_COPY
    if (-not (Test-Path -LiteralPath $swapBinary) -or (Test-Path -LiteralPath (Join-Path $root 'escape.txt'))) { throw 'archive_swap_isolation_failed' }
    Write-Output 'archive-swap-isolation: PASS'

    $aliasCache = Join-Path $root 'alias-cache'
    $alias = Join-Path $root 'child\..\alias-cache'
    New-Item -ItemType Directory -Path (Join-Path $root 'child') | Out-Null
    $env:MOST_LIBREDWG_CACHE = $aliasCache
    $mutexA = & $bootstrap -MutexNameOnly
    $env:MOST_LIBREDWG_CACHE = $alias.ToUpperInvariant()
    $mutexB = & $bootstrap -MutexNameOnly
    if ($mutexA -ne $mutexB) { throw 'canonical_mutex_alias_mismatch' }
    Write-Output 'canonical-mutex-alias: PASS'
    foreach ($version in @('0.13.40', 'prefix 0.13.4', '0.13.4 malicious')) {
        $fake = Join-Path $root ("fake-" + [guid]::NewGuid().ToString('N') + '.cmd')
        [IO.File]::WriteAllText($fake, "@echo dwgread $version`r`n@exit /b 0`r`n")
        $failed = $false
        try { & $bootstrap -TestVersionBinary $fake | Out-Null } catch { $failed = $true }
        if (-not $failed) { throw "version_false_positive:$version" }
    }
    $fake = Join-Path $root 'fake-error.cmd'
    [IO.File]::WriteAllText($fake, "@echo dwgread 0.13.4`r`n@exit /b 9`r`n")
    $failed = $false
    try { & $bootstrap -TestVersionBinary $fake | Out-Null } catch { $failed = $true }
    if (-not $failed) { throw 'version_nonzero_exit_accepted' }
    'libredwg bootstrap runtime: PASS'
} finally {
    Remove-Item Env:MOST_LIBREDWG_CACHE -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
