param(
    [string]$ArchivePath = '',
    [string]$InspectArchive = '',
    [string]$TestVersionBinary = ''
)

$ErrorActionPreference = 'Stop'
$version = '0.13.4'
$archiveSha256 = 'cb46bce034296e91cb1a982cd53ec1928b11f4f7f70512dd21513a27959688b5'
$binarySha256 = '88f3c398bc1ff5a83c365fe8180018ef26947a63fff21fad8a032dd056a47c94'
$entryListSha256 = 'f9e13dea1b8f4ac19d4c91bd76c9b7c56c60f6c68f411b40981964d4d6a69c6b'
$binaryRelativePath = 'dwgread.exe'
$url = 'https://github.com/LibreDWG/libredwg/releases/download/0.13.4/libredwg-0.13.4-win64.zip'
$cacheRoot = if ($env:MOST_LIBREDWG_CACHE) { $env:MOST_LIBREDWG_CACHE } else { Join-Path $env:USERPROFILE '.cache\most-libredwg\0.13.4' }
$final = Join-Path $cacheRoot 'win64'
$markerPath = Join-Path $final 'most-libredwg-install.json'

function Get-LowerSha256([string]$Path) {
    (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Assert-NotReparsePoint([string]$Path) {
    if (Test-Path -LiteralPath $Path) {
        $item = Get-Item -LiteralPath $Path -Force
        if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'libredwg_cache_reparse_invalid' }
    }
}

function Test-ExactVersion([string]$Binary) {
    $output = & $Binary --version 2>&1 | Out-String
    $exitCode = $LASTEXITCODE
    $match = [regex]::Match($output.Trim(), '^dwgread\s+(?<version>\d+\.\d+\.\d+)$', 'CultureInvariant')
    $exitCode -eq 0 -and $match.Success -and $match.Groups['version'].Value -eq '0.13.4'
}

function Test-AuthenticatedInstall {
    if (-not (Test-Path -LiteralPath $markerPath -PathType Leaf)) { return $false }
    try { $marker = Get-Content -LiteralPath $markerPath -Raw | ConvertFrom-Json } catch { return $false }
    if ($marker.version -ne $version -or $marker.archive_sha256 -ne $archiveSha256 -or
        $marker.binary_relative_path -ne $binaryRelativePath -or $marker.binary_sha256 -ne $binarySha256) { return $false }
    $binary = Join-Path $final $binaryRelativePath
    if (-not (Test-Path -LiteralPath $binary -PathType Leaf)) { return $false }
    if ((Get-LowerSha256 $binary) -ne $binarySha256) { return $false }
    Test-ExactVersion $binary
}

function Assert-SafeArchive([string]$Path) {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [IO.Compression.ZipFile]::OpenRead($Path)
    try {
        $seen = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
        $names = [Collections.Generic.List[string]]::new()
        [long]$total = 0
        foreach ($entry in $zip.Entries) {
            $name = $entry.FullName.Replace('\', '/')
            if ([string]::IsNullOrWhiteSpace($name) -or $name.StartsWith('/') -or $name.StartsWith('//') -or
                $name -match '^[A-Za-z]:' -or $name.Split('/') -contains '..' -or -not $seen.Add($name)) {
                throw 'libredwg_archive_path_invalid'
            }
            $names.Add($name)
            $unixType = (($entry.ExternalAttributes -shr 16) -band 0xF000)
            if ($unixType -eq 0xA000 -or ($entry.ExternalAttributes -band 0x400) -ne 0) {
                throw 'libredwg_archive_link_invalid'
            }
            if ($entry.Length -gt 45MB -or $entry.CompressedLength -gt 16MB) { throw 'libredwg_archive_entry_size_invalid' }
            $total += $entry.Length
            if ($total -gt 64MB) { throw 'libredwg_archive_total_size_invalid' }
        }
        if ($zip.Entries.Count -ne 75) { throw 'libredwg_archive_entry_count_invalid' }
        $names.Sort([StringComparer]::Ordinal)
        $nameBytes = [Text.Encoding]::UTF8.GetBytes([string]::Join("`n", [string[]]$names))
        $nameHasher = [Security.Cryptography.SHA256]::Create()
        try { $nameHash = (($nameHasher.ComputeHash($nameBytes) | ForEach-Object { $_.ToString('x2') }) -join '') } finally { $nameHasher.Dispose() }
        if ($nameHash -ne $entryListSha256) { throw 'libredwg_archive_layout_invalid' }
        $binaryEntry = $zip.GetEntry($binaryRelativePath)
        if ($null -eq $binaryEntry -or $binaryEntry.Length -ne 311139) { throw 'libredwg_archive_layout_invalid' }
    } finally { $zip.Dispose() }
}

function Expand-SafeArchive([string]$Path, [string]$Destination) {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [IO.Compression.ZipFile]::OpenRead($Path)
    try {
        foreach ($entry in $zip.Entries) {
            $name = $entry.FullName.Replace('/', [IO.Path]::DirectorySeparatorChar)
            $target = Join-Path $Destination $name
            if ($entry.FullName.EndsWith('/')) { [IO.Directory]::CreateDirectory($target) | Out-Null; continue }
            [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($target)) | Out-Null
            $input = $entry.Open()
            try {
                $output = [IO.File]::Open($target, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
                try { $input.CopyTo($output) } finally { $output.Dispose() }
            } finally { $input.Dispose() }
        }
    } finally { $zip.Dispose() }
}

if ($InspectArchive) { Assert-SafeArchive $InspectArchive; 'archive-safe'; exit 0 }
if ($TestVersionBinary) { if (Test-ExactVersion $TestVersionBinary) { 'version-valid'; exit 0 }; throw 'libredwg_runtime_version_invalid' }

[IO.Directory]::CreateDirectory($cacheRoot) | Out-Null
Assert-NotReparsePoint $cacheRoot
$mutexName = 'Local\MOST-LibreDWG-' + ([Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($cacheRoot)) -replace '[^A-Za-z0-9]', '')
$mutex = [Threading.Mutex]::new($false, $mutexName)
$lockHeld = $false
$work = $null
try {
    $lockHeld = $mutex.WaitOne([TimeSpan]::FromMinutes(5))
    if (-not $lockHeld) { throw 'libredwg_bootstrap_lock_timeout' }
    Assert-NotReparsePoint $final
    if (Test-AuthenticatedInstall) { (Resolve-Path (Join-Path $final $binaryRelativePath)).Path; exit 0 }

    $work = Join-Path ([IO.Path]::GetTempPath()) ('most-libredwg-' + [guid]::NewGuid().ToString('N'))
    $staging = Join-Path $work 'staging'
    [IO.Directory]::CreateDirectory($staging) | Out-Null
    $archive = if ($ArchivePath) { (Resolve-Path $ArchivePath).Path } else { Join-Path $work 'libredwg.zip' }
    if (-not $ArchivePath) {
        & curl.exe '--proto' '=https' '--proto-redir' '=https' '--tlsv1.2' '--fail' '--show-error' '--location' '--connect-timeout' '20' '--max-time' '180' '--retry' '2' '--output' $archive $url
        if ($LASTEXITCODE -ne 0) { throw 'libredwg_download_failed' }
    }
    if ((Get-LowerSha256 $archive) -ne $archiveSha256) { throw 'libredwg_archive_integrity_failed' }
    Assert-SafeArchive $archive
    Expand-SafeArchive $archive $staging
    $stagedBinary = Join-Path $staging $binaryRelativePath
    if ((Get-LowerSha256 $stagedBinary) -ne $binarySha256 -or -not (Test-ExactVersion $stagedBinary)) {
        throw 'libredwg_runtime_validation_failed'
    }
    [ordered]@{ version = $version; archive_sha256 = $archiveSha256; binary_relative_path = $binaryRelativePath; binary_sha256 = $binarySha256 } |
        ConvertTo-Json | Set-Content -LiteralPath (Join-Path $staging 'most-libredwg-install.json') -Encoding UTF8
    if (Test-Path -LiteralPath $final) { Assert-NotReparsePoint $final; Remove-Item -LiteralPath $final -Recurse -Force }
    [IO.Directory]::Move($staging, $final)
    (Resolve-Path (Join-Path $final $binaryRelativePath)).Path
} finally {
    if ($lockHeld) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
    if ($work -and (Test-Path -LiteralPath $work)) { Remove-Item -LiteralPath $work -Recurse -Force }
}
