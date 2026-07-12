param([string]$ArchivePath = '', [string]$InspectArchive = '', [string]$TestVersionBinary = '', [switch]$MutexNameOnly)

$ErrorActionPreference = 'Stop'
$version = '0.13.4'
$archiveSha256 = 'cb46bce034296e91cb1a982cd53ec1928b11f4f7f70512dd21513a27959688b5'
$binarySha256 = '88f3c398bc1ff5a83c365fe8180018ef26947a63fff21fad8a032dd056a47c94'
$entryListSha256 = 'f9e13dea1b8f4ac19d4c91bd76c9b7c56c60f6c68f411b40981964d4d6a69c6b'
$fileManifestSha256 = 'be36775704db58bd820cad03c0e50212fa2d1041512c578d322ff1996a94de7a'
$binaryRelativePath = 'dwgread.exe'
$url = 'https://github.com/LibreDWG/libredwg/releases/download/0.13.4/libredwg-0.13.4-win64.zip'
$requestedCache = if ($env:MOST_LIBREDWG_CACHE) { $env:MOST_LIBREDWG_CACHE } else { Join-Path $env:USERPROFILE '.cache\most-libredwg\0.13.4' }
$cacheRoot = [IO.Path]::GetFullPath($requestedCache).TrimEnd([IO.Path]::DirectorySeparatorChar)
$final = Join-Path $cacheRoot 'win64'
$markerPath = Join-Path $final 'most-libredwg-install.json'

function Get-LowerSha256([string]$Path) { (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant() }
function Get-BytesSha256([byte[]]$Bytes) {
    $hasher = [Security.Cryptography.SHA256]::Create()
    try { (($hasher.ComputeHash($Bytes) | ForEach-Object { $_.ToString('x2') }) -join '') } finally { $hasher.Dispose() }
}
function Assert-NoReparseComponents([string]$Path) {
    $absolute = [IO.Path]::GetFullPath($Path)
    $root = [IO.Path]::GetPathRoot($absolute)
    $current = $root
    foreach ($part in $absolute.Substring($root.Length).Split([IO.Path]::DirectorySeparatorChar, [StringSplitOptions]::RemoveEmptyEntries)) {
        $current = Join-Path $current $part
        if (Test-Path -LiteralPath $current) {
            $item = Get-Item -LiteralPath $current -Force
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'libredwg_reparse_component_invalid' }
        }
    }
}
function Test-ExactVersion([string]$Binary) {
    if ($env:MOST_LIBREDWG_TEST_VERSION_CALLED) { [IO.File]::WriteAllText($env:MOST_LIBREDWG_TEST_VERSION_CALLED, 'called') }
    $output = & $Binary --version 2>&1 | Out-String
    $exitCode = $LASTEXITCODE
    $match = [regex]::Match($output.Trim(), '^dwgread\s+(?<version>\d+\.\d+\.\d+)$', 'CultureInvariant')
    $exitCode -eq 0 -and $match.Success -and $match.Groups['version'].Value -eq $version
}
function Get-CanonicalFileManifestSha256([string]$Root) {
    Assert-NoReparseComponents $Root
    $Root = (Get-Item -LiteralPath $Root).FullName.TrimEnd([IO.Path]::DirectorySeparatorChar)
    $lines = [Collections.Generic.List[string]]::new()
    $files = @(Get-ChildItem -LiteralPath $Root -File -Recurse -Force | Where-Object { $_.FullName -ne (Join-Path $Root 'most-libredwg-install.json') })
    if ($files.Count -ne 63) { throw 'libredwg_install_file_count_invalid' }
    foreach ($file in $files) {
        Assert-NoReparseComponents $file.FullName
        $relative = $file.FullName.Substring($Root.Length + 1).Replace('\', '/')
        $lines.Add("$relative`t$($file.Length)`t$(Get-LowerSha256 $file.FullName)")
    }
    $lines.Sort([StringComparer]::Ordinal)
    Get-BytesSha256 ([Text.Encoding]::UTF8.GetBytes([string]::Join("`n", [string[]]$lines)))
}
function Get-CanonicalLayoutSha256([string]$Root) {
    Assert-NoReparseComponents $Root
    $Root = (Get-Item -LiteralPath $Root).FullName.TrimEnd([IO.Path]::DirectorySeparatorChar)
    $names = [Collections.Generic.List[string]]::new()
    $items = @(Get-ChildItem -LiteralPath $Root -Recurse -Force | Where-Object { $_.FullName -ne (Join-Path $Root 'most-libredwg-install.json') })
    if ($items.Count -ne 75) { throw 'libredwg_install_layout_count_invalid' }
    foreach ($item in $items) {
        Assert-NoReparseComponents $item.FullName
        $relative = $item.FullName.Substring($Root.Length + 1).Replace('\', '/')
        if ($item.PSIsContainer) { $relative += '/' }
        $names.Add($relative)
    }
    $names.Sort([StringComparer]::Ordinal)
    Get-BytesSha256 ([Text.Encoding]::UTF8.GetBytes([string]::Join("`n", [string[]]$names)))
}
function Test-AuthenticatedInstall {
    try {
        Assert-NoReparseComponents $final
        if (-not (Test-Path -LiteralPath $markerPath -PathType Leaf)) { return $false }
        $marker = Get-Content -LiteralPath $markerPath -Raw | ConvertFrom-Json
        if ($marker.version -ne $version -or $marker.archive_sha256 -ne $archiveSha256 -or
            $marker.binary_relative_path -ne $binaryRelativePath -or $marker.binary_sha256 -ne $binarySha256 -or
            $marker.file_manifest_sha256 -ne $fileManifestSha256) { return $false }
        if ((Get-CanonicalLayoutSha256 $final) -ne $entryListSha256 -or
            (Get-CanonicalFileManifestSha256 $final) -ne $fileManifestSha256) { return $false }
        $binary = Join-Path $final $binaryRelativePath
        if ((Get-LowerSha256 $binary) -ne $binarySha256) { return $false }
        Test-ExactVersion $binary
    } catch { return $false }
}
function Assert-AndExtractArchive([string]$Path, [string]$Destination, [bool]$Extract) {
    Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem
    $stream = [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::None)
    $zip = [IO.Compression.ZipArchive]::new($stream, [IO.Compression.ZipArchiveMode]::Read, $false)
    try {
        $seen = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
        $names = [Collections.Generic.List[string]]::new()
        [long]$total = 0
        $destinationPrefix = [IO.Path]::GetFullPath($Destination).TrimEnd([IO.Path]::DirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar
        foreach ($entry in $zip.Entries) {
            $name = $entry.FullName.Replace('\', '/')
            if ([string]::IsNullOrWhiteSpace($name) -or $name.StartsWith('/') -or $name.StartsWith('//') -or
                $name -match '^[A-Za-z]:' -or $name.Split('/') -contains '..' -or -not $seen.Add($name)) { throw 'libredwg_archive_path_invalid' }
            $names.Add($name)
            $unixType = (($entry.ExternalAttributes -shr 16) -band 0xF000)
            if ($unixType -eq 0xA000 -or ($entry.ExternalAttributes -band 0x400) -ne 0) { throw 'libredwg_archive_link_invalid' }
            if ($entry.Length -gt 45MB -or $entry.CompressedLength -gt 16MB) { throw 'libredwg_archive_entry_size_invalid' }
            $total += $entry.Length
            if ($total -gt 64MB) { throw 'libredwg_archive_total_size_invalid' }
            if ($Extract) {
                $target = [IO.Path]::GetFullPath((Join-Path $Destination $name.Replace('/', [IO.Path]::DirectorySeparatorChar)))
                if (-not $target.StartsWith($destinationPrefix, [StringComparison]::OrdinalIgnoreCase)) { throw 'libredwg_archive_target_invalid' }
                Assert-NoReparseComponents ([IO.Path]::GetDirectoryName($target))
                if ($entry.FullName.EndsWith('/')) { [IO.Directory]::CreateDirectory($target) | Out-Null; continue }
                [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($target)) | Out-Null
                Assert-NoReparseComponents ([IO.Path]::GetDirectoryName($target))
                $input = $entry.Open()
                try {
                    $output = [IO.File]::Open($target, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
                    try { $input.CopyTo($output) } finally { $output.Dispose() }
                } finally { $input.Dispose() }
            }
        }
        if ($zip.Entries.Count -ne 75) { throw 'libredwg_archive_entry_count_invalid' }
        $names.Sort([StringComparer]::Ordinal)
        if ((Get-BytesSha256 ([Text.Encoding]::UTF8.GetBytes([string]::Join("`n", [string[]]$names)))) -ne $entryListSha256) { throw 'libredwg_archive_layout_invalid' }
        $binaryEntry = $zip.GetEntry($binaryRelativePath)
        if ($null -eq $binaryEntry -or $binaryEntry.Length -ne 311139) { throw 'libredwg_archive_layout_invalid' }
    } finally { $zip.Dispose(); $stream.Dispose() }
}
function Copy-ArchivePrivately([string]$Source, [string]$Destination) {
    $canonical = (Resolve-Path -LiteralPath $Source).Path
    Assert-NoReparseComponents $canonical
    $input = [IO.File]::Open($canonical, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::None)
    try {
        $output = [IO.File]::Open($Destination, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
        try { $input.CopyTo($output) } finally { $output.Dispose() }
    } finally { $input.Dispose() }
}

if ($InspectArchive) { Assert-AndExtractArchive (Resolve-Path $InspectArchive).Path ([IO.Path]::GetTempPath()) $false; 'archive-safe'; return }
if ($TestVersionBinary) { if (Test-ExactVersion $TestVersionBinary) { 'version-valid'; return }; throw 'libredwg_runtime_version_invalid' }
Assert-NoReparseComponents ([IO.Path]::GetDirectoryName($cacheRoot))
[IO.Directory]::CreateDirectory($cacheRoot) | Out-Null
$cacheRoot = (Get-Item -LiteralPath $cacheRoot).FullName.TrimEnd([IO.Path]::DirectorySeparatorChar)
$final = Join-Path $cacheRoot 'win64'
$markerPath = Join-Path $final 'most-libredwg-install.json'
Assert-NoReparseComponents $cacheRoot
$canonicalLockPath = $cacheRoot.ToLowerInvariant()
$mutexHash = Get-BytesSha256 ([Text.Encoding]::UTF8.GetBytes($canonicalLockPath))
$mutexName = "Local\MOST-LibreDWG-$mutexHash"
if ($MutexNameOnly) { $mutexName; return }

$mutex = [Threading.Mutex]::new($false, $mutexName)
$lockHeld = $false
$work = $null
$backup = $null
try {
    $lockHeld = $mutex.WaitOne([TimeSpan]::FromMinutes(5))
    if (-not $lockHeld) { throw 'libredwg_bootstrap_lock_timeout' }
    if ($env:MOST_LIBREDWG_TEST_FORCE_REBUILD -ne '1' -and (Test-AuthenticatedInstall)) { (Resolve-Path (Join-Path $final $binaryRelativePath)).Path; return }
    $work = Join-Path ([IO.Path]::GetTempPath()) ('most-libredwg-' + [guid]::NewGuid().ToString('N'))
    $staging = Join-Path $work 'staging'
    [IO.Directory]::CreateDirectory($staging) | Out-Null
    $privateArchive = Join-Path $work 'authenticated.zip'
    if ($ArchivePath) { Copy-ArchivePrivately $ArchivePath $privateArchive } else {
        & curl.exe '--proto' '=https' '--proto-redir' '=https' '--tlsv1.2' '--fail' '--show-error' '--location' '--connect-timeout' '20' '--max-time' '180' '--retry' '2' '--output' $privateArchive $url
        if ($LASTEXITCODE -ne 0) { throw 'libredwg_download_failed' }
    }
    if ($env:MOST_LIBREDWG_TEST_SWAP_SOURCE_AFTER_COPY -and $ArchivePath) {
        Copy-Item -LiteralPath $env:MOST_LIBREDWG_TEST_SWAP_SOURCE_AFTER_COPY -Destination $ArchivePath -Force
    }
    if ((Get-LowerSha256 $privateArchive) -ne $archiveSha256) { throw 'libredwg_archive_integrity_failed' }
    Assert-AndExtractArchive $privateArchive $staging $true
    if ((Get-CanonicalLayoutSha256 $staging) -ne $entryListSha256 -or
        (Get-CanonicalFileManifestSha256 $staging) -ne $fileManifestSha256) { throw 'libredwg_install_manifest_invalid' }
    $stagedBinary = Join-Path $staging $binaryRelativePath
    if ((Get-LowerSha256 $stagedBinary) -ne $binarySha256 -or -not (Test-ExactVersion $stagedBinary)) { throw 'libredwg_runtime_validation_failed' }
    [ordered]@{ version=$version; archive_sha256=$archiveSha256; binary_relative_path=$binaryRelativePath; binary_sha256=$binarySha256; file_manifest_sha256=$fileManifestSha256 } |
        ConvertTo-Json | Set-Content -LiteralPath (Join-Path $staging 'most-libredwg-install.json') -Encoding UTF8
    if (Test-Path -LiteralPath $final) {
        Assert-NoReparseComponents $final
        $backup = Join-Path $cacheRoot ('win64.backup.' + [guid]::NewGuid().ToString('N'))
        [IO.Directory]::Move($final, $backup)
    }
    try {
        if ($env:MOST_LIBREDWG_TEST_FAIL_SECOND_MOVE -eq '1') { throw 'libredwg_test_publish_failure' }
        [IO.Directory]::Move($staging, $final)
    } catch {
        if ($backup -and (Test-Path -LiteralPath $backup) -and -not (Test-Path -LiteralPath $final)) { [IO.Directory]::Move($backup, $final); $backup = $null }
        throw
    }
    if (-not (Test-AuthenticatedInstall)) { throw 'libredwg_published_install_invalid' }
    if ($backup -and (Test-Path -LiteralPath $backup)) { Remove-Item -LiteralPath $backup -Recurse -Force; $backup = $null }
    (Resolve-Path (Join-Path $final $binaryRelativePath)).Path
} finally {
    if ($backup -and (Test-Path -LiteralPath $backup) -and -not (Test-Path -LiteralPath $final)) { [IO.Directory]::Move($backup, $final) }
    if ($lockHeld) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
    if ($work -and (Test-Path -LiteralPath $work) -and $env:MOST_LIBREDWG_TEST_KEEP_WORK -ne '1') {
        1..20 | ForEach-Object {
            if (Test-Path -LiteralPath $work) {
                try { Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction Stop } catch { Start-Sleep -Milliseconds 50 }
            }
        }
        if (Test-Path -LiteralPath $work) { throw 'libredwg_work_cleanup_failed' }
    }
}
