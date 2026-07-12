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

    $corrupt = Join-Path $root 'corrupt.zip'
    [IO.File]::WriteAllText($corrupt, 'corrupt')
    $second = & $bootstrap -ArchivePath $corrupt
    if ($second -ne $binary) { throw 'idempotent_marker_failed' }

    $sentinel = Join-Path $root 'executed.txt'
    [IO.File]::WriteAllText($binary, "MZ fake $sentinel")
    $failed = $false
    try { & $bootstrap -ArchivePath $corrupt | Out-Null } catch { $failed = $_.Exception.Message -match 'integrity' }
    if (-not $failed -or (Test-Path -LiteralPath $sentinel)) { throw 'fake_cache_was_trusted' }

    Remove-Item -LiteralPath $env:MOST_LIBREDWG_CACHE -Recurse -Force
    New-Item -ItemType Directory -Path (Join-Path $env:MOST_LIBREDWG_CACHE 'win64') -Force | Out-Null
    [IO.File]::WriteAllText((Join-Path $env:MOST_LIBREDWG_CACHE 'win64\partial.tmp'), 'partial')
    $binary = & $bootstrap -ArchivePath $archive
    if (-not (Test-Path -LiteralPath $binary)) { throw 'partial_cache_recovery_failed' }

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
