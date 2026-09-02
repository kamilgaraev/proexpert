param(
    [string]$Image = 'clamav/clamav:1.4.3',
    [string]$ConfigPath = (Join-Path $PSScriptRoot '../../docker/clamav/clamd.conf')
)

$ErrorActionPreference = 'Stop'
$resolvedConfig = (Resolve-Path -LiteralPath $ConfigPath).Path
$testContainer = 'most-clamav-startup-' + [guid]::NewGuid().ToString('N')
$containerCreated = $false

try {
    & docker run --detach --name $testContainer --network none --pull never `
        --label most.test=clamav-startup `
        --env CLAMAV_NO_FRESHCLAMD=true --env CLAMD_STARTUP_TIMEOUT=60 `
        --mount "type=bind,source=$resolvedConfig,target=/etc/clamav/clamd.conf,readonly" `
        $Image
    if ($LASTEXITCODE -ne 0) { throw 'Cannot start isolated ClamAV test container.' }
    $containerCreated = $true
    $watch = [System.Diagnostics.Stopwatch]::StartNew()

    while ($watch.Elapsed.TotalSeconds -lt 75) {
        Start-Sleep -Seconds 2
        $running = & docker inspect --format '{{.State.Running}}' $testContainer
        if ($LASTEXITCODE -ne 0 -or $running -ne 'true') {
            throw 'ClamAV exited before the startup stability interval completed.'
        }
    }

    & docker exec $testContainer sh -c 'test -S /tmp/clamd.sock && clamdscan --ping 1'
    if ($LASTEXITCODE -ne 0) { throw 'ClamAV startup socket or daemon readiness check failed.' }
    Write-Output 'PASS: ClamAV remained running beyond its startup timeout and the socket/daemon are ready.'
} catch {
    if ($containerCreated) { & docker logs --tail 35 $testContainer }
    throw
} finally {
    if ($containerCreated) { & docker rm --force $testContainer | Out-Null }
}
