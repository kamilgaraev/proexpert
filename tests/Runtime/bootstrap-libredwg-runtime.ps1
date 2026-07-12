$ErrorActionPreference = 'Stop'

$version = '0.13.4'
$sha256 = 'cb46bce034296e91cb1a982cd53ec1928b11f4f7f70512dd21513a27959688b5'
$url = 'https://github.com/LibreDWG/libredwg/releases/download/0.13.4/libredwg-0.13.4-win64.zip'
$cache = Join-Path $env:USERPROFILE ".cache\most-libredwg\$version\win64"
$existing = Get-ChildItem -LiteralPath $cache -Filter 'dwgread.exe' -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
if ($null -ne $existing -and (& $existing.FullName --version 2>&1 | Out-String) -match [regex]::Escape($version)) {
    $existing.FullName
    exit 0
}

$root = Join-Path ([System.IO.Path]::GetTempPath()) ("most-libredwg-" + [guid]::NewGuid().ToString('N'))
$archive = Join-Path $root 'libredwg.zip'
New-Item -ItemType Directory -Path $root -Force | Out-Null
try {
    & curl.exe -fsSL --retry 3 --output $archive $url
    if ($LASTEXITCODE -ne 0) { throw 'libredwg_download_failed' }
    if ((Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $sha256) {
        throw 'libredwg_archive_integrity_failed'
    }
    New-Item -ItemType Directory -Path $cache -Force | Out-Null
    Expand-Archive -LiteralPath $archive -DestinationPath $cache -Force
    $binary = Get-ChildItem -LiteralPath $cache -Filter 'dwgread.exe' -Recurse | Select-Object -First 1
    if ($null -eq $binary -or -not ((& $binary.FullName --version 2>&1 | Out-String) -match [regex]::Escape($version))) {
        throw 'libredwg_runtime_version_invalid'
    }
    $binary.FullName
} finally {
    Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue
}
