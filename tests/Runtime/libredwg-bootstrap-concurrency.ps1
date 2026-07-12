$ErrorActionPreference = 'Stop'
$archive = if ($env:MOST_LIBREDWG_TEST_ARCHIVE) { (Resolve-Path $env:MOST_LIBREDWG_TEST_ARCHIVE).Path } else { throw 'MOST_LIBREDWG_TEST_ARCHIVE_required' }
$bootstrap = Join-Path $PSScriptRoot 'bootstrap-libredwg-runtime.ps1'
$root = Join-Path ([IO.Path]::GetTempPath()) ('most-libredwg-concurrency-' + [guid]::NewGuid().ToString('N'))
$cache = Join-Path $root 'cache'
$alias = Join-Path $root 'child\..\cache'
New-Item -ItemType Directory -Path (Join-Path $root 'child') -Force | Out-Null
$runner = Join-Path $root 'runner.ps1'
[IO.File]::WriteAllText($runner, @'
param($Bootstrap, $Archive, $Cache, $Output)
$ErrorActionPreference = 'Stop'
$env:MOST_LIBREDWG_CACHE = $Cache
try { (& $Bootstrap -ArchivePath $Archive) | Set-Content -LiteralPath $Output -Encoding UTF8; exit 0 } catch { $_ | Out-String | Set-Content -LiteralPath $Output; exit 1 }
'@)
try {
    $outputs = @((Join-Path $root 'a.txt'), (Join-Path $root 'b.txt'))
    $caches = @($cache, $alias)
    $processes = for ($index = 0; $index -lt 2; $index++) {
        Start-Process powershell.exe -PassThru -WindowStyle Hidden -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File',$runner,$bootstrap,$archive,$caches[$index],$outputs[$index])
    }
    foreach ($process in $processes) {
        $process.WaitForExit(120000) | Out-Null
        if (-not $process.HasExited) { $process.Kill(); throw 'concurrent_alias_install_timeout' }
        if ($process.ExitCode -ne 0) { throw 'concurrent_alias_install_failed' }
    }
    $results = @((Get-Content -LiteralPath $outputs[0] -Raw).Trim(), (Get-Content -LiteralPath $outputs[1] -Raw).Trim())
    if ($results[0] -ne $results[1]) { throw 'concurrent_alias_install_race' }
    'libredwg bootstrap concurrency: PASS'
} finally { Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue }
