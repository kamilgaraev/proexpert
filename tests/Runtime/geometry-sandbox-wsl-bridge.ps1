param([Parameter(ValueFromRemainingArguments = $true)][string[]] $Arguments)

if ($Arguments.Count -lt 10) {
    exit 125
}

$BwrapPath = $Arguments[0]
$Workspace = $Arguments[1]
$StdoutPath = $Arguments[2]
$StderrPath = $Arguments[3]
$WallLimit = $Arguments[4]
$MemoryLimit = $Arguments[5]
$CpuLimit = $Arguments[6]
$FileLimit = $Arguments[7]
$OpenFileLimit = $Arguments[8]
$Command = $Arguments[9..($Arguments.Count - 1)]

$distribution = 'Ubuntu-22.04'
function ConvertTo-WslPath([string] $Path) {
    $fullPath = [System.IO.Path]::GetFullPath($Path)
    if ($fullPath -notmatch '^([A-Za-z]):\\(.*)$') {
        throw "Unsupported Windows path: $Path"
    }

    return '/mnt/' + $Matches[1].ToLowerInvariant() + '/' + $Matches[2].Replace('\', '/')
}

$sandboxWindows = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\docker\geometry\geometry-sandbox.sh'))
$sandbox = ConvertTo-WslPath $sandboxWindows
$landlockAdapter = ConvertTo-WslPath ([System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot 'geometry-landlock-sandbox-bwrap-adapter.sh')))
$workspacePath = ConvertTo-WslPath $Workspace
$stdout = ConvertTo-WslPath $StdoutPath
$stderr = ConvertTo-WslPath $StderrPath
$bwrapDirectory = $BwrapPath -replace '/[^/]+$', ''
$path = "${bwrapDirectory}:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
$sandboxArguments = @(
    'bash', $sandbox,
    $workspacePath, $stdout, $stderr, $WallLimit, $MemoryLimit, $CpuLimit,
    $FileLimit, $OpenFileLimit
) + $Command
$payloadJson = @{ path = $path; landlock_sandbox = $landlockAdapter; arguments = $sandboxArguments } | ConvertTo-Json -Compress
$payload = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($payloadJson))
$execHelper = ConvertTo-WslPath ([System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot 'geometry-sandbox-wsl-exec.py')))

& wsl.exe -d $distribution -- python3 $execHelper $payload
exit $LASTEXITCODE
