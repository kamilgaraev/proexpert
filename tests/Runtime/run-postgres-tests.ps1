param(
    [string] $TestPath = '',
    [string] $TestSuite = '',
    [string] $Filter = '',
    [ValidateSet('phpunit', 'pest')]
    [string] $Runner = 'phpunit',
    [switch] $IsolatedAiAssistant,
    [switch] $LogProgressEvents,
    [switch] $LinuxPhp,
    [string] $LeaseReceiptPath = ''
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($TestPath) -and [string]::IsNullOrWhiteSpace($TestSuite)) {
    $TestPath = 'tests/Feature/Infrastructure/PhpUnitPostgresProfileTest.php'
}

if (-not [string]::IsNullOrWhiteSpace($TestPath) -and -not [string]::IsNullOrWhiteSpace($TestSuite)) {
    throw 'postgres_test_selection_conflict'
}

$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$testsRoot = [IO.Path]::GetFullPath((Join-Path $root 'tests') + [IO.Path]::DirectorySeparatorChar)
$phpunitConfigurationPath = Join-Path $root 'phpunit.xml'
[xml] $phpunitConfiguration = Get-Content -Raw -Encoding UTF8 $phpunitConfigurationPath

if (-not [string]::IsNullOrWhiteSpace($TestPath)) {
    if ([IO.Path]::IsPathRooted($TestPath)) {
        throw 'postgres_test_path_must_be_relative'
    }

    $resolvedTestPath = [IO.Path]::GetFullPath((Join-Path $root $TestPath))
    if (-not $resolvedTestPath.StartsWith($testsRoot, [StringComparison]::OrdinalIgnoreCase) -or -not (Test-Path -LiteralPath $resolvedTestPath -PathType Leaf)) {
        throw 'postgres_test_path_invalid'
    }
}

if (-not [string]::IsNullOrWhiteSpace($TestSuite)) {
    $knownSuites = @($phpunitConfiguration.phpunit.testsuites.testsuite | ForEach-Object { [string] $_.name })
    if ($TestSuite -cnotin $knownSuites) {
        throw 'postgres_test_suite_invalid'
    }
}

$environment = @{}
foreach ($node in $phpunitConfiguration.phpunit.php.env) {
    $environment[[string] $node.name] = [string] $node.value
}

if (
    $environment.DB_CONNECTION -ne 'pgsql' -or
    $environment.DB_HOST -ne '127.0.0.1' -or
    $environment.DB_PORT -ne '55433' -or
    $environment.DB_DATABASE -notmatch '_testing$'
) {
    throw 'postgres_test_database_configuration_unsafe'
}

$selectedProfile = if ($IsolatedAiAssistant) { 'ai-assistant' } else { '' }
$selectedProject = if ($IsolatedAiAssistant) { 'most-ai-assistant-tests' } else { 'most-postgres-tests' }
if ($env:MOST_POSTGRES_TEST_PROFILE -and $env:MOST_POSTGRES_TEST_PROFILE -cne $selectedProfile) {
    throw 'postgres_test_profile_unsafe'
}
if ($env:COMPOSE_PROJECT_NAME -and $env:COMPOSE_PROJECT_NAME -cne $selectedProject) {
    throw 'postgres_test_project_unsafe'
}
# The Linux branch is opt-in. Its receipt must be a separately accepted runtime
# lease; a source-only implementation receipt never authorizes Docker or SQL.
function Assert-MostCondition($Condition, [string] $Code) {
    if (-not $Condition) { throw $Code }
}

function ConvertTo-MostWindowsArguments([string[]] $Values) {
    $encoded = foreach ($value in $Values) {
        $builder = [Text.StringBuilder]::new()
        [void] $builder.Append('"')
        $slashes = 0
        foreach ($character in $value.ToCharArray()) {
            if ($character -eq '\') { $slashes++; continue }
            if ($character -eq '"') {
                [void] $builder.Append(('\' * (2 * $slashes + 1)))
            } else {
                [void] $builder.Append(('\' * $slashes))
            }
            [void] $builder.Append($character)
            $slashes = 0
        }
        [void] $builder.Append(('\' * (2 * $slashes)))
        [void] $builder.Append('"')
        $builder.ToString()
    }
    return ($encoded -join ' ')
}

function Initialize-MostNativeSupervisor {
    if ('MostPostgresNativeCapture' -as [type]) { return }
    # Drain both pipes asynchronously even after the retention cap is reached.
    # No PowerShell event callback/runspace or unbounded ReadToEnd buffer.
    Add-Type -TypeDefinition @'
using System;
using System.ComponentModel;
using System.Diagnostics;
using System.IO;
using System.Text;
using System.Threading.Tasks;
using System.Runtime.InteropServices;
using Microsoft.Win32.SafeHandles;
public sealed class MostPostgresNativeCapture : IDisposable {
    public readonly Process Process;
    private readonly StringBuilder output = new StringBuilder(), error = new StringBuilder();
    private Task outputTask, errorTask, inputTask;
    private StreamReader outputReader, errorReader;
    private StreamWriter inputWriter;
    private IntPtr job;
    private const int Limit = 2097152;
    private volatile bool truncated;
    [StructLayout(LayoutKind.Sequential)] struct Security {
        public int length; public IntPtr descriptor; public int inherit;
    }
    [StructLayout(LayoutKind.Sequential, CharSet=CharSet.Unicode)] struct Startup {
        public int size; public string reserved, desktop, title;
        public int x, y, width, height, charsX, charsY, fill, flags;
        public short show, reservedSize; public IntPtr reservedBytes, input, output, error;
    }
    [StructLayout(LayoutKind.Sequential)] struct ProcessInfo {
        public IntPtr process, thread; public int pid, tid;
    }
    [StructLayout(LayoutKind.Sequential)] struct BasicLimits {
        public long processTime, jobTime; public uint flags;
        public UIntPtr minimumWorkingSet, maximumWorkingSet; public uint activeProcesses;
        public UIntPtr affinity; public uint priority, scheduling;
    }
    [StructLayout(LayoutKind.Sequential)] struct IoCounters {
        public ulong readOperations, writeOperations, otherOperations, readBytes, writeBytes, otherBytes;
    }
    [StructLayout(LayoutKind.Sequential)] struct ExtendedLimits {
        public BasicLimits basic; public IoCounters io;
        public UIntPtr processMemory, jobMemory, peakProcessMemory, peakJobMemory;
    }
    [StructLayout(LayoutKind.Sequential)] struct JobAccounting {
        public long userTime, kernelTime, periodUserTime, periodKernelTime;
        public uint faults, totalProcesses, activeProcesses, terminatedProcesses;
    }
    [DllImport("kernel32", SetLastError=true)] static extern bool CreatePipe(out IntPtr read, out IntPtr write, ref Security security, uint size);
    [DllImport("kernel32", SetLastError=true)] static extern bool SetHandleInformation(IntPtr handle, uint mask, uint flags);
    [DllImport("kernel32", CharSet=CharSet.Unicode, SetLastError=true)] static extern IntPtr CreateJobObject(IntPtr security, string name);
    [DllImport("kernel32", SetLastError=true)] static extern bool SetInformationJobObject(IntPtr job, int kind, ref ExtendedLimits limits, uint size);
    [DllImport("kernel32", SetLastError=true)] static extern bool AssignProcessToJobObject(IntPtr job, IntPtr process);
    [DllImport("kernel32", SetLastError=true)] static extern bool TerminateJobObject(IntPtr job, uint exit);
    [DllImport("kernel32", SetLastError=true)] static extern bool QueryInformationJobObject(IntPtr job, int kind, out JobAccounting accounting, uint size, IntPtr returned);
    [DllImport("kernel32", SetLastError=true)] static extern bool TerminateProcess(IntPtr process, uint exit);
    [DllImport("kernel32", SetLastError=true)] static extern uint ResumeThread(IntPtr thread);
    [DllImport("kernel32", SetLastError=true)] static extern bool CloseHandle(IntPtr handle);
    [DllImport("kernel32", CharSet=CharSet.Unicode, SetLastError=true)] static extern bool CreateProcess(
        string application, StringBuilder command, IntPtr processSecurity, IntPtr threadSecurity, bool inherit,
        uint flags, IntPtr environment, string cwd, ref Startup startup, out ProcessInfo info);
    private static void Require(bool success) {
        if (!success) throw new Win32Exception(Marshal.GetLastWin32Error());
    }
    private static void Close(ref IntPtr handle) { if (handle != IntPtr.Zero) { CloseHandle(handle); handle=IntPtr.Zero; } }
    public MostPostgresNativeCapture(ProcessStartInfo start, string input) {
        IntPtr stdinRead=IntPtr.Zero, stdinWrite=IntPtr.Zero, stdoutRead=IntPtr.Zero, stdoutWrite=IntPtr.Zero;
        IntPtr stderrRead=IntPtr.Zero, stderrWrite=IntPtr.Zero, environment=IntPtr.Zero;
        ProcessInfo info=new ProcessInfo();
        try {
            Security security=new Security {length=Marshal.SizeOf(typeof(Security)), inherit=1};
            Require(CreatePipe(out stdinRead,out stdinWrite,ref security,0));
            Require(CreatePipe(out stdoutRead,out stdoutWrite,ref security,0));
            Require(CreatePipe(out stderrRead,out stderrWrite,ref security,0));
            Require(SetHandleInformation(stdinWrite,1,0));
            Require(SetHandleInformation(stdoutRead,1,0));
            Require(SetHandleInformation(stderrRead,1,0));
            job=CreateJobObject(IntPtr.Zero,null); Require(job!=IntPtr.Zero);
            ExtendedLimits limits=new ExtendedLimits(); limits.basic.flags=0x2000; // KILL_ON_JOB_CLOSE
            Require(SetInformationJobObject(job,9,ref limits,(uint)Marshal.SizeOf(typeof(ExtendedLimits))));
            StringBuilder variables=new StringBuilder();
            string[] keys=new string[start.EnvironmentVariables.Count]; start.EnvironmentVariables.Keys.CopyTo(keys,0);
            Array.Sort(keys,StringComparer.OrdinalIgnoreCase);
            foreach(string key in keys) variables.Append(key).Append('=').Append(start.EnvironmentVariables[key]).Append('\0');
            variables.Append('\0'); environment=Marshal.StringToHGlobalUni(variables.ToString());
            Startup startup=new Startup {size=Marshal.SizeOf(typeof(Startup)),flags=0x100,input=stdinRead,output=stdoutWrite,error=stderrWrite};
            // Suspended creation closes the assign-before-spawn race: every
            // Compose descendant inherits this Job before any user code runs.
            StringBuilder command=new StringBuilder("\""+start.FileName+"\" "+start.Arguments);
            Require(CreateProcess(start.FileName,command,IntPtr.Zero,IntPtr.Zero,true,0x08000404,environment,start.WorkingDirectory,ref startup,out info));
            Require(AssignProcessToJobObject(job,info.process));
            Process=Process.GetProcessById(info.pid);
            // Cache the managed handle while the child is suspended. A Process
            // opened by PID otherwise loses ExitCode access after native exit.
            IntPtr managedHandle=Process.Handle;
            outputReader=new StreamReader(new FileStream(new SafeFileHandle(stdoutRead,true),FileAccess.Read),new UTF8Encoding(false)); stdoutRead=IntPtr.Zero;
            errorReader=new StreamReader(new FileStream(new SafeFileHandle(stderrRead,true),FileAccess.Read),new UTF8Encoding(false)); stderrRead=IntPtr.Zero;
            inputWriter=new StreamWriter(new FileStream(new SafeFileHandle(stdinWrite,true),FileAccess.Write),new UTF8Encoding(false)); stdinWrite=IntPtr.Zero;
            Close(ref stdinRead); Close(ref stdoutWrite); Close(ref stderrWrite);
            outputTask=Drain(outputReader,output); errorTask=Drain(errorReader,error);
            inputTask=Task.Run(async () => {
                try { if(input!=null) await inputWriter.WriteAsync(input); }
                catch(IOException) { }
                finally { inputWriter.Close(); }
            });
            Require(ResumeThread(info.thread)!=0xffffffff);
        } catch {
            if(info.process!=IntPtr.Zero) TerminateProcess(info.process,1);
            if(job!=IntPtr.Zero) { TerminateJobObject(job,1); Close(ref job); }
            throw;
        } finally {
            Close(ref info.thread); Close(ref info.process);
            Close(ref stdinRead); Close(ref stdinWrite); Close(ref stdoutRead); Close(ref stdoutWrite); Close(ref stderrRead); Close(ref stderrWrite);
            if(environment!=IntPtr.Zero) Marshal.FreeHGlobal(environment);
        }
    }
    private async Task Drain(StreamReader reader, StringBuilder target) {
        char[] buffer=new char[4096]; int count;
        while((count=await reader.ReadAsync(buffer,0,buffer.Length))!=0) {
            lock(target) { int retained=Math.Min(count,Math.Max(0,Limit-target.Length)); target.Append(buffer,0,retained); if(retained!=count)truncated=true; }
        }
    }
    public void KillTree() { Require(TerminateJobObject(job,1)); }
    public bool TreeTerminal(int milliseconds) {
        Stopwatch clock=Stopwatch.StartNew();
        do { JobAccounting accounting; Require(QueryInformationJobObject(job,1,out accounting,(uint)Marshal.SizeOf(typeof(JobAccounting)),IntPtr.Zero));
            if(accounting.activeProcesses==0)return true; System.Threading.Thread.Sleep(20);
        } while(clock.ElapsedMilliseconds<milliseconds);
        return false;
    }
    public bool Finish(int milliseconds) { return Task.WaitAll(new Task[]{outputTask,errorTask,inputTask},milliseconds); }
    public string Output { get { lock(output)return output.ToString(); } }
    public string Error { get { lock(error)return error.ToString(); } }
    public bool Truncated { get {return truncated;} }
    public void Dispose() {
        if(job!=IntPtr.Zero) { TerminateJobObject(job,1); Close(ref job); }
        if(outputReader!=null)outputReader.Dispose(); if(errorReader!=null)errorReader.Dispose(); if(inputWriter!=null)inputWriter.Dispose();
        if(Process!=null)Process.Dispose();
    }
}
'@
}

function Invoke-MostNativeClient {
    param([string] $Executable, [string[]] $Arguments, [hashtable] $ChildEnvironment,
        [string] $WorkingDirectory, [int] $MaxSeconds, [string] $InputText = $null,
        [string] $CancelPath = '', [scriptblock] $OnAbort = $null)
    Assert-MostCondition ($MaxSeconds -gt 0 -and $MaxSeconds -le 600) 'native_deadline_invalid'
    Initialize-MostNativeSupervisor
    $start = [Diagnostics.ProcessStartInfo]::new()
    $start.FileName = $Executable
    $start.Arguments = ConvertTo-MostWindowsArguments $Arguments
    $start.WorkingDirectory = $WorkingDirectory
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    $start.RedirectStandardInput = $true
    $start.EnvironmentVariables.Clear()
    foreach ($key in $ChildEnvironment.Keys) { $start.EnvironmentVariables[$key] = [string] $ChildEnvironment[$key] }
    $capture = $null
    $clock = [Diagnostics.Stopwatch]::StartNew()
    $outcome = 'completed'
    $abortFailure = $null
    try {
        $capture = [MostPostgresNativeCapture]::new($start, $InputText)
        while (-not $capture.Process.WaitForExit(50)) {
            if ($CancelPath -and [IO.File]::Exists($CancelPath)) { $outcome = 'cancelled'; break }
            if ($clock.Elapsed.TotalSeconds -ge $MaxSeconds) { $outcome = 'timeout'; break }
        }
        if ($outcome -ne 'completed') {
            # Killing a Docker client does not kill its daemon container. The
            # caller's exact-ID abort hook runs first, with its cleanup budget.
            try { if ($OnAbort) { & $OnAbort } }
            catch { $abortFailure = $_.Exception.Message; $outcome = 'abort_cleanup_failed' }
            finally {
                $capture.KillTree()
            }
        }
        Assert-MostCondition ($capture.Process.WaitForExit(5000)) 'native_child_not_terminal'
        if (-not $capture.TreeTerminal(0)) {
            $capture.KillTree()
            if ($outcome -ceq 'completed') { $outcome = 'native_descendant_outlived_client' }
        }
        Assert-MostCondition ($capture.TreeTerminal(5000)) 'native_process_tree_not_terminal'
        Assert-MostCondition ($capture.Finish(5000)) 'native_pipe_drain_not_terminal'
        return [pscustomobject]@{
            ExitCode = $capture.Process.ExitCode; Outcome = $outcome
            Stdout = $capture.Output; Stderr = $capture.Error
            Truncated = $capture.Truncated; Pid = $capture.Process.Id; AbortFailure = $abortFailure
            ElapsedMilliseconds = $clock.ElapsedMilliseconds
        }
    } finally {
        # Parent cancellation also goes through this path. Cleanup delegates do
        # not inherit cancellation. Abrupt host death requires journal recovery.
        if ($capture) {
            if (-not $capture.Process.HasExited) {
                try { if ($OnAbort) { & $OnAbort } }
                finally { $capture.KillTree(); [void] $capture.Process.WaitForExit(5000); [void] $capture.TreeTerminal(5000) }
            }
            $capture.Dispose()
        }
    }
}

function Get-MostFileHash([string] $Path) {
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Get-MostOwnedPath([string] $Base, [string] $Path) {
    $fullBase = [IO.Path]::GetFullPath($Base).TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    $full = [IO.Path]::GetFullPath($Path)
    Assert-MostCondition ($full -notmatch '[,\r\n]') 'lease_mount_path_unsafe'
    Assert-MostCondition ($full.StartsWith($fullBase, [StringComparison]::OrdinalIgnoreCase)) 'lease_path_outside_owned_scratch'
    Assert-MostCondition (((Get-Item -LiteralPath $Base).Attributes -band [IO.FileAttributes]::ReparsePoint) -eq 0) 'lease_reparse_base_refused'
    $cursor = $full
    while ($cursor -and $cursor.TrimEnd('\', '/') -ne $fullBase.TrimEnd('\', '/')) {
        if (Test-Path -LiteralPath $cursor) {
            Assert-MostCondition (((Get-Item -LiteralPath $cursor).Attributes -band [IO.FileAttributes]::ReparsePoint) -eq 0) 'lease_reparse_path_refused'
        }
        $cursor = [IO.Path]::GetDirectoryName($cursor)
    }
    return $full
}

function Get-MostRuntimeReceipt([string] $Path, [string] $Root, [string] $SelectedRunner, [string] $SelectedPath) {
    Assert-MostCondition ($Path -and (Test-Path -LiteralPath $Path -PathType Leaf)) 'linux_runtime_receipt_missing'
    $preflightClock = [Diagnostics.Stopwatch]::StartNew()
    $receipt = Get-Content -LiteralPath $Path -Raw -Encoding UTF8 | ConvertFrom-Json
    Assert-MostCondition ($receipt.schemaVersion -eq 1 -and $receipt.kind -ceq 'RUNTIME_EXISTING_DEFAULT_LOOPBACK_LINUX_PG') 'linux_runtime_receipt_kind_refused'
    Assert-MostCondition ($receipt.status -ceq 'ACTIVE' -and $receipt.runtimeGranted -eq $true -and $receipt.exclusivePgLease -eq $true) 'linux_runtime_lease_not_active'
    Assert-MostCondition ($receipt.companyId -ceq '35e4e4d5-14c2-4d39-83ac-44e5bad51f0b' -and
        $receipt.parentIssueId -ceq 'da4ad1fe-a31d-4abc-85ad-5a0206b5d761' -and
        $receipt.issueId -ceq '1d29c454-eae0-474b-867d-876cdbd6c595' -and
        $receipt.ownerAgentId -ceq '125ba435-15b5-4a20-b594-e2bacc9dee3c') 'linux_runtime_receipt_owner_refused'
    Assert-MostCondition ($env:PAPERCLIP_RUN_ID -and $receipt.runId -ceq $env:PAPERCLIP_RUN_ID -and
        $env:PAPERCLIP_AGENT_ID -ceq $receipt.ownerAgentId) 'linux_runtime_receipt_run_refused'
    $parsedRun = [guid]::Empty
    Assert-MostCondition ([guid]::TryParse([string] $receipt.runId, [ref] $parsedRun)) 'linux_runtime_run_invalid'
    $expiry = [DateTimeOffset]::Parse([string] $receipt.expiresAtUtc)
    Assert-MostCondition ($expiry -gt [DateTimeOffset]::UtcNow.AddSeconds(1200)) 'linux_runtime_receipt_expired_or_cleanup_unreserved'
    # The caller supplies the trusted Coordinator control location. The copied
    # receipt alone is not authority: its bytes must be pinned by the live CAS.
    Assert-MostCondition ($env:MOST_PAPERCLIP_CONTROL_PATH -and
        [IO.Path]::GetFullPath($receipt.controlPath) -ceq [IO.Path]::GetFullPath($env:MOST_PAPERCLIP_CONTROL_PATH)) 'linux_runtime_control_path_unbound'
    $control = Get-Content -LiteralPath $env:MOST_PAPERCLIP_CONTROL_PATH -Raw -Encoding UTF8 | ConvertFrom-Json
    $grant = $control.productionFirstCanonicalLinuxLauncherRuntimeGrant
    Assert-MostCondition ($control.stateWriterAgentId -ceq '79c85c69-a4a0-4109-9bfd-28254a8bc17d' -and
        $grant.kind -ceq $receipt.kind -and $grant.status -ceq 'ACTIVE_BOUND_RUNTIME' -and
        $grant.actualBackendRunId -ceq $receipt.runId -and $grant.ownerAgentId -ceq $receipt.ownerAgentId -and
        $grant.receiptSha256 -ceq (Get-MostFileHash $Path) -and $grant.sourceHead -ceq $receipt.sourceHead -and
        $grant.changedEventSyncVerdict -ceq 'VERIFIED' -and $grant.nativeManagerDisposition -ceq 'done' -and
        $grant.runtimeGrant -eq $true -and $grant.pgExclusiveLeaseGranted -eq $true -and
        [DateTimeOffset]::Parse([string] $grant.expiresAtUtc) -ge $expiry) 'linux_runtime_live_cas_refused'
    Assert-MostCondition ([IO.Path]::GetFullPath($receipt.sourceRoot) -ceq $Root -and
        $receipt.sourceHead -cmatch '^[0-9a-f]{40}$' -and
        $receipt.sourceBaseHead -ceq '7a191699a92c9539618aa3f77e20dc88df27d97e' -and
        $receipt.parentHead -cmatch '^[0-9a-f]{40}$' -and
        $receipt.sourceHead -cne $receipt.parentHead) 'linux_runtime_patched_source_unbound'
    Assert-MostCondition ($receipt.daemonEndpoint -ceq 'npipe:////./pipe/dockerDesktopLinuxEngine' -and
        $receipt.daemonId -ceq 'a58d6046-2716-4603-8619-e93b137722db' -and
        $receipt.phpImage -ceq 'sha256:97c2016672b495e2fc3e798cb7b559287b9bcd4f7fed85f769127b152bb3aa9f' -and
        $receipt.pgImage -ceq 'sha256:5fa1d4c74299c466a1a051ed66ce7a44b69cf27b66444a202f7e5592963ed596') 'linux_runtime_identity_refused'
    Assert-MostCondition ($receipt.project -ceq 'most-postgres-tests' -and $receipt.service -ceq 'postgres-testing' -and
        $receipt.volume.name -ceq 'most-postgres-tests_postgres_testing_data' -and
        $receipt.pgInitializedMajor -eq 16 -and $receipt.initializedClusterProvenanceVerified -eq $true -and
        $receipt.exclusiveCatalogProvenanceVerified -eq $true) 'linux_runtime_pg_provenance_unbound'
    $allowed = @{
        'tests/Feature/Infrastructure/PhpUnitPostgresProfileTest.php' = 'phpunit'
        'tests/Feature/Api/V1/Admin/AdminAuthSmokeTest.php' = 'pest'
        'tests/Feature/Auth/JwtRequestStateIsolationTest.php' = 'phpunit'
        'tests/Feature/Auth/ActiveOrganizationMembershipTest.php' = 'phpunit'
        'tests/Feature/Admin/UserProjectAccessTest.php' = 'phpunit'
        'tests/Feature/AIAssistant/AssistantAuthorizationBatchTest.php' = 'phpunit'
        'tests/Feature/AIAssistant/AssistantDataAccessRegressionTest.php' = 'phpunit'
        'tests/Feature/AIAssistant/AssistantApiContractTest.php' = 'phpunit'
    }
    Assert-MostCondition ($allowed.ContainsKey($SelectedPath) -and $allowed[$SelectedPath] -ceq $SelectedRunner) 'linux_runtime_selector_refused'
    $stages = @($receipt.stages | Where-Object { $_.testPath -ceq $SelectedPath -and $_.runner -ceq $SelectedRunner })
    Assert-MostCondition ($stages.Count -eq 1 -and $stages[0].maxCalls -eq 1 -and
        $stages[0].name -cmatch '^[a-z][a-z0-9-]{0,30}$' -and $stages[0].testMaxSeconds -ge 1 -and
        $stages[0].testMaxSeconds -le 600 -and $receipt.callMaxSeconds -eq 1200 -and
        $receipt.cleanupReserveSeconds -eq 360 -and $receipt.namespaceProbeMaxCalls -eq 1) 'linux_runtime_stage_bounds_refused'
    $scratch = [IO.Path]::GetFullPath($receipt.scratchPath)
    Assert-MostCondition ((Split-Path $scratch -Leaf) -ceq ('CMP-30-' + $receipt.runId)) 'linux_runtime_scratch_name_refused'
    $marker = Get-Content -LiteralPath (Join-Path $scratch 'OWNER.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    Assert-MostCondition ($marker.issueId -ceq $receipt.issueId -and $marker.runId -ceq $receipt.runId -and
        $marker.agentId -ceq $receipt.ownerAgentId) 'linux_runtime_scratch_owner_refused'
    foreach ($name in @('snapshotPath','vendorPath','snapshotManifestPath','vendorManifestPath','dockerConfigPath','emptyEnvPath','cancelPath')) {
        [void] (Get-MostOwnedPath $scratch ([string] $receipt.$name))
    }
    Assert-MostCondition ([IO.File]::ReadAllText($receipt.emptyEnvPath).Length -eq 0 -and
        ([IO.File]::ReadAllText((Join-Path $receipt.dockerConfigPath 'config.json')).Trim() -ceq '{}')) 'linux_runtime_environment_not_empty'
    Assert-MostCondition ((Get-MostFileHash $receipt.snapshotManifestPath) -ceq $receipt.snapshotManifestSha256 -and
        (Get-MostFileHash $receipt.vendorManifestPath) -ceq $receipt.vendorManifestSha256) 'linux_runtime_frozen_manifests_mismatch'
    foreach ($name in @('snapshot', 'vendor')) {
        $basePath = if ($name -eq 'snapshot') { $receipt.snapshotPath } else { $receipt.vendorPath }
        $manifestPath = if ($name -eq 'snapshot') { $receipt.snapshotManifestPath } else { $receipt.vendorManifestPath }
        $manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
        $entries = @($manifest.pins.PSObject.Properties)
        Assert-MostCondition ($entries.Count -gt 0) 'linux_runtime_empty_frozen_manifest'
        foreach ($pin in $entries) {
            Assert-MostCondition ($preflightClock.Elapsed.TotalSeconds -lt 90) 'linux_runtime_input_preflight_deadline'
            Assert-MostCondition ($pin.Name -notmatch '(^|[/\\])(\.env|auth\.json)$' -and
                -not [IO.Path]::IsPathRooted($pin.Name) -and $pin.Name -notmatch '(^|[/\\])\.\.([/\\]|$)') 'linux_runtime_snapshot_path_unsafe'
            $full = Get-MostOwnedPath $basePath (Join-Path $basePath $pin.Name)
            Assert-MostCondition ((Get-MostFileHash $full) -ceq $pin.Value) 'linux_runtime_snapshot_hash_mismatch'
        }
        $actual = @(Get-ChildItem -LiteralPath $basePath -File -Recurse)
        Assert-MostCondition ($actual.Count -eq $entries.Count) 'linux_runtime_snapshot_untracked_files'
    }
    $guardPaths = @('composer.json','composer.lock','tests/Runtime/run-postgres-tests.ps1','compose.testing.yml','phpunit.xml',
        'tests/bootstrap.php','tests/Support/IsolatedPostgresTestDatabase.php','tests/Feature/Infrastructure/PhpUnitPostgresProfileTest.php')
    Assert-MostCondition (@($receipt.sourcePins.PSObject.Properties).Count -eq 8) 'linux_runtime_source_guards_incomplete'
    foreach ($name in $guardPaths) {
        $expected = $receipt.sourcePins.PSObject.Properties[$name].Value
        Assert-MostCondition ($expected -cmatch '^[0-9a-f]{64}$' -and (Get-MostFileHash (Join-Path $Root $name)) -ceq $expected -and
            (Get-MostFileHash (Join-Path $receipt.snapshotPath $name)) -ceq $expected) 'linux_runtime_source_pin_mismatch'
    }
    Assert-MostCondition ($receipt.sourcePins.'composer.json' -ceq 'b480a41729a8c757f5a9e68f6d784bc9eb0b4303a552378a18fa668382b19fcd' -and
        $receipt.sourcePins.'composer.lock' -ceq '1cf0f03da2e8cbc5514634b664752911cd4416698f58846228a79455ef7061f2') 'linux_runtime_lock_changed'
    $gitEnvironment = @{ SystemRoot='C:/Windows'; WINDIR='C:/Windows'; PATH='C:/Windows/System32';
        TEMP=(Join-Path $scratch 'tmpcli'); TMP=(Join-Path $scratch 'tmpcli') }
    foreach ($check in @(
        @{ argv=@('rev-parse','HEAD'); expected=$receipt.sourceHead },
        @{ argv=@('rev-parse','HEAD^'); expected=$receipt.parentHead },
        @{ argv=@('merge-base','--is-ancestor',$receipt.sourceBaseHead,$receipt.sourceHead); expected='' },
        @{ argv=@('branch','--show-current'); expected='task/cmp-7-production-responses' },
        @{ argv=@('status','--porcelain=v1'); expected='' }
    )) {
        $git = Invoke-MostNativeClient -Executable 'C:/Program Files/Git/cmd/git.exe' -Arguments (@('--no-optional-locks','-C',$Root) + $check.argv) `
            -ChildEnvironment $gitEnvironment -WorkingDirectory $Root -MaxSeconds 30
        Assert-MostCondition ($git.ExitCode -eq 0 -and $git.Outcome -ceq 'completed' -and -not $git.Truncated -and
            -not $git.Stderr -and $git.Stdout.Trim() -ceq $check.expected) 'linux_runtime_fresh_git_mismatch'
    }
    return [pscustomobject]@{ Receipt = $receipt; Stage = $stages[0] }
}

function Save-MostPgJournal($Context, [string] $State) {
    $Context.Journal.state = $State
    $Context.Journal.updatedAtUtc = [DateTimeOffset]::UtcNow.ToString('o')
    $temporary = $Context.JournalPath + '.tmp'
    [IO.File]::WriteAllText($temporary, ($Context.Journal | ConvertTo-Json -Depth 40), [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporary -Destination $Context.JournalPath -Force
}

function Invoke-MostDocker($Context, [string[]] $DockerArguments, [int] $Seconds = 30,
    [string] $InputText = $null, [switch] $Cleanup, [scriptblock] $OnAbort = $null, [switch] $AllowFailure) {
    if (-not $Cleanup) {
        $live = Get-Content -LiteralPath $env:MOST_PAPERCLIP_CONTROL_PATH -Raw -Encoding UTF8 | ConvertFrom-Json
        $currentGrant = $live.productionFirstCanonicalLinuxLauncherRuntimeGrant
        Assert-MostCondition ($currentGrant.status -ceq 'ACTIVE_BOUND_RUNTIME' -and $currentGrant.runtimeGrant -eq $true -and
            $currentGrant.pgExclusiveLeaseGranted -eq $true -and $currentGrant.actualBackendRunId -ceq $Context.Receipt.runId -and
            $currentGrant.sourceHead -ceq $Context.Receipt.sourceHead -and
            [DateTimeOffset]::Parse([string] $currentGrant.expiresAtUtc) -gt [DateTimeOffset]::UtcNow) 'linux_runtime_lease_revoked'
    }
    $ceiling = if ($Cleanup) { 1200 } else { 840 }
    $remaining = [int] [Math]::Floor($ceiling - $Context.Clock.Elapsed.TotalSeconds)
    Assert-MostCondition ($remaining -gt 0) 'linux_runtime_stage_deadline'
    $limit = [Math]::Min($Seconds, $remaining)
    $cancel = if ($Cleanup) { '' } else { $Context.Receipt.cancelPath }
    $arguments = @('--config', $Context.Receipt.dockerConfigPath, '--host', $Context.Receipt.daemonEndpoint) + $DockerArguments
    $result = Invoke-MostNativeClient -Executable 'C:/Program Files/Docker/Docker/resources/bin/docker.exe' -Arguments $arguments `
        -ChildEnvironment $Context.ChildEnvironment -WorkingDirectory $Context.Root -MaxSeconds $limit -InputText $InputText -CancelPath $cancel -OnAbort $OnAbort
    $record = [ordered]@{ argv = $arguments; exit = $result.ExitCode; outcome = $result.Outcome; pid = $result.Pid
        elapsedMilliseconds = $result.ElapsedMilliseconds; truncated = $result.Truncated }
    # Fixture authentication is stdin-only. No stdin is saved. Compose render
    # and container environment values are never included in public receipts.
    $Context.Journal.clients += $record
    Save-MostPgJournal $Context $Context.Journal.state
    if (-not $AllowFailure) {
        Assert-MostCondition ($result.Outcome -ceq 'completed' -and -not $result.Truncated -and $result.ExitCode -eq 0) 'linux_runtime_native_client_failed'
    }
    return $result
}

function Get-MostContainerMetadata($Context, [string] $Id, [switch] $Cleanup) {
    Assert-MostCondition ($Id -cmatch '^[0-9a-f]{64}$') 'linux_runtime_container_id_invalid'
    $template = '{"id":{{json .Id}},"name":{{json .Name}},"state":{{json .State.Status}},"image":{{json .Image}},"labels":{{json .Config.Labels}},"args":{{json .Args}},"path":{{json .Path}},"host":{{json .HostConfig}},"mounts":{{json .Mounts}},"networks":{{json .NetworkSettings.Networks}},"ports":{{json .NetworkSettings.Ports}}}'
    $result = Invoke-MostDocker $Context @('inspect','--format',$template,$Id) -Cleanup:$Cleanup
    return ($result.Stdout | ConvertFrom-Json)
}

function Assert-MostReplacement($Context, $Metadata) {
    $r = $Context.Receipt
    Assert-MostCondition ($Metadata.id -ceq $Context.Journal.replacementId -and $Metadata.image -ceq $r.pgImage -and
        $Metadata.labels.'paperclip.issue' -ceq 'CMP-30' -and $Metadata.labels.'paperclip.run' -ceq $r.runId -and
        $Metadata.labels.'paperclip.source' -ceq $r.sourceHead -and $Metadata.host.NetworkMode -ceq 'none' -and
        @($Metadata.networks.PSObject.Properties).Count -eq 0 -and @($Metadata.host.PortBindings.PSObject.Properties | Where-Object { $null -ne $_ }).Count -eq 0) 'linux_runtime_pg_replacement_identity_mismatch'
    $volumes = @($Metadata.mounts | Where-Object { $_.Type -ceq 'volume' })
    Assert-MostCondition ($volumes.Count -eq 1 -and $volumes[0].Name -ceq $r.volume.name -and
        $volumes[0].Destination -ceq $r.volume.destination) 'linux_runtime_pg_replacement_volume_mismatch'
}

function Get-MostComposeArguments($Context, [string] $Model) {
    return @('compose','--env-file',$Context.Receipt.emptyEnvPath,'--project-directory',$Context.Root,
        '-p','most-postgres-tests','-f',$Model)
}

function New-MostPgModels($Context) {
    $r = $Context.Receipt
    $compose = Join-Path $Context.Root 'compose.testing.yml'
    $args = Get-MostComposeArguments $Context $compose
    $hash = Invoke-MostDocker $Context ($args + @('config','--hash','postgres-testing'))
    Assert-MostCondition ($hash.Stdout.Trim() -ceq ('postgres-testing ' + $r.originalComposeServiceHash)) 'linux_runtime_original_compose_hash_mismatch'
    $render = Invoke-MostDocker $Context ($args + @('config','--format','json'))
    $original = $render.Stdout | ConvertFrom-Json
    Assert-MostCondition (@($original.services.PSObject.Properties).Count -eq 1 -and
        $original.services.'postgres-testing' -and -not $original.services.'postgres-testing'.profiles) 'linux_runtime_compose_service_scope_mismatch'
    $service = $original.services.'postgres-testing'
    foreach ($field in @('build','env_file','secrets','configs','depends_on')) {
        Assert-MostCondition (-not $service.PSObject.Properties[$field]) 'linux_runtime_compose_extra_inputs_refused'
    }
    Assert-MostCondition ($service.environment.POSTGRES_USER -ceq $Context.Fixture.DB_USERNAME -and
        $service.environment.POSTGRES_DB -ceq $Context.Fixture.DB_DATABASE -and
        $service.environment.POSTGRES_PASSWORD -ceq $Context.Fixture.DB_PASSWORD) 'linux_runtime_fixture_mismatch'
    $mounts = @($service.volumes)
    Assert-MostCondition ($mounts.Count -eq 2 -and @($mounts | Where-Object { $_.type -ceq 'volume' }).Count -eq 1 -and
        @($mounts | Where-Object { $_.type -ceq 'bind' -and $_.read_only -eq $true }).Count -eq 1) 'linux_runtime_compose_mount_scope_mismatch'
    $bind = @($mounts | Where-Object { $_.type -ceq 'bind' })[0]
    Assert-MostCondition ((Get-MostFileHash $bind.source) -ceq $r.initSqlSha256 -and
        $bind.target -ceq $r.initSqlDestination) 'linux_runtime_init_pin_mismatch'
    $restore = $original | ConvertTo-Json -Depth 40 | ConvertFrom-Json
    $restoredService = $restore.services.'postgres-testing'
    # External declarations retain exact existing resources; Compose must never
    # allocate a replacement network or volume during restoration.
    $restore.volumes = [pscustomobject]@{}
    $volumeKey = @($mounts | Where-Object { $_.type -ceq 'volume' })[0].source
    $restore.volumes | Add-Member $volumeKey ([pscustomobject]@{ external = $true; name = $r.volume.name })
    $networkKeys = @($restoredService.networks.PSObject.Properties.Name)
    Assert-MostCondition ($networkKeys.Count -eq 1 -and $r.network.name -and $r.network.id -cmatch '^[0-9a-f]{64}$') 'linux_runtime_original_network_unbound'
    $restore.networks = [pscustomobject]@{}
    $restore.networks | Add-Member $networkKeys[0] ([pscustomobject]@{ external = $true; name = $r.network.name })
    $restoredService.image = $r.pgImage
    $restoredService | Add-Member pull_policy 'never' -Force
    $Context.RestorePath = Join-Path $Context.Directory 'restore.json'
    [IO.File]::WriteAllText($Context.RestorePath, ($restore | ConvertTo-Json -Depth 40), [Text.UTF8Encoding]::new($false))
    $runtime = $restore | ConvertTo-Json -Depth 40 | ConvertFrom-Json
    $runtime.PSObject.Properties.Remove('networks')
    $pg = $runtime.services.'postgres-testing'
    $pg.PSObject.Properties.Remove('ports'); $pg.PSObject.Properties.Remove('networks')
    $pg | Add-Member network_mode 'none' -Force
    $pg.command = @('postgres','-p','55433','-c','listen_addresses=127.0.0.1','-c','max_locks_per_transaction=1024')
    $pg.healthcheck.test = @('CMD','pg_isready','-h','127.0.0.1','-p','55433','-U',$Context.Fixture.DB_USERNAME,'-d',$Context.Fixture.DB_DATABASE)
    $pg | Add-Member cpus 2.0 -Force; $pg | Add-Member mem_limit 2147483648 -Force
    $pg | Add-Member pids_limit 128 -Force; $pg | Add-Member shm_size 268435456 -Force
    $labels = [pscustomobject]@{ 'paperclip.issue' = 'CMP-30'; 'paperclip.run' = $r.runId; 'paperclip.source' = $r.sourceHead }
    $pg | Add-Member labels $labels -Force
    $Context.RuntimePath = Join-Path $Context.Directory 'runtime.json'
    [IO.File]::WriteAllText($Context.RuntimePath, ($runtime | ConvertTo-Json -Depth 40), [Text.UTF8Encoding]::new($false))
    foreach ($model in @($Context.RuntimePath,$Context.RestorePath)) {
        $validated = Invoke-MostDocker $Context ((Get-MostComposeArguments $Context $model) + @('config','--no-interpolate','--no-path-resolution','--format','json'))
        Assert-MostCondition ($validated.Stderr -eq '') 'linux_runtime_model_render_warning'
    }
    $Context.Journal.runtimeModelHash = Get-MostFileHash $Context.RuntimePath
    $Context.Journal.restoreModelHash = Get-MostFileHash $Context.RestorePath
}

function Assert-MostOriginalPg($Context, [string] $Id) {
    $r = $Context.Receipt
    $original = Get-MostContainerMetadata $Context $Id
    Assert-MostCondition ($original.state -ceq 'exited' -or $original.state -ceq 'created') 'linux_runtime_original_pg_not_stopped'
    Assert-MostCondition ($original.name -ceq '/most-postgres-tests-postgres-testing-1' -and $original.image -ceq $r.pgImage -and
        $original.labels.'com.docker.compose.project' -ceq 'most-postgres-tests' -and
        $original.labels.'com.docker.compose.service' -ceq 'postgres-testing' -and
        $original.labels.'com.docker.compose.project.working_dir' -ceq $r.originalWorkingDirectory -and
        ($Context.ExpectedOriginalMetadata -or ($original.labels.'com.docker.compose.config-hash' -ceq $r.originalComposeServiceHash -and
        $original.labels.'com.docker.compose.project.config_files' -ceq $r.originalConfigFiles))) 'linux_runtime_original_pg_provenance_mismatch'
    if ($Context.ExpectedOriginalMetadata) {
        $expectedLabels = $Context.ExpectedOriginalMetadata.labels
        Assert-MostCondition ($original.labels.'com.docker.compose.config-hash' -ceq $expectedLabels.'com.docker.compose.config-hash' -and
            $original.labels.'com.docker.compose.project.config_files' -ceq $expectedLabels.'com.docker.compose.project.config_files') 'linux_runtime_restored_chain_drift'
    }
    # The live receipt pins the expected non-secret semantic configuration.
    $semantic = [ordered]@{ path = $original.path; args = $original.args; host = $original.host; mounts = $original.mounts; ports = $original.ports }
    $actual = $semantic | ConvertTo-Json -Depth 40 -Compress
    Assert-MostCondition ($actual -ceq ($r.originalSemantic | ConvertTo-Json -Depth 40 -Compress)) 'linux_runtime_original_pg_configuration_drift'
    $volume = Invoke-MostDocker $Context @('volume','inspect','--format','{"name":{{json .Name}},"driver":{{json .Driver}},"created":{{json .CreatedAt}},"scope":{{json .Scope}},"options":{{json .Options}}}',$r.volume.name)
    Assert-MostCondition (($volume.Stdout | ConvertFrom-Json | ConvertTo-Json -Compress) -ceq ($r.volume.metadata | ConvertTo-Json -Compress)) 'linux_runtime_volume_identity_drift'
    $network = Invoke-MostDocker $Context @('network','inspect','--format','{{.Id}}',$r.network.name)
    Assert-MostCondition ($network.Stdout.Trim() -ceq $r.network.id) 'linux_runtime_network_identity_drift'
    $consumers = Invoke-MostDocker $Context @('ps','-a','--no-trunc','--filter',('volume=' + $r.volume.name),'--format','{{.ID}}')
    $projectContainers = Invoke-MostDocker $Context @('ps','-a','--no-trunc','--filter','label=com.docker.compose.project=most-postgres-tests','--format','{{.ID}}')
    Assert-MostCondition ($consumers.Stdout.Trim() -ceq $Id -and $projectContainers.Stdout.Trim() -ceq $Id) 'linux_runtime_pg_resource_not_exclusive'
    $copyPath = Join-Path $Context.Directory 'PG_VERSION'
    [void] (Invoke-MostDocker $Context @('cp',($Id + ':' + $r.volume.destination + '/PG_VERSION'),$copyPath))
    Assert-MostCondition ([IO.File]::ReadAllText($copyPath).Trim() -ceq '16') 'linux_runtime_retained_cluster_not_pg16'
    $Context.Journal.originalMetadata = $original
    $Context.Journal.originalId = $Id
}

function Invoke-MostPgSql($Context, [string] $Sql, [switch] $Cleanup) {
    Assert-MostCondition ($Context.SqlElapsedSeconds -lt 180) 'linux_runtime_sql_budget_exhausted'
    $metadata = Get-MostContainerMetadata $Context $Context.Journal.replacementId -Cleanup:$Cleanup
    Assert-MostReplacement $Context $metadata
    # The literal shell reads the existing synthetic fixture password from
    # private stdin; SQL is never embedded into a shell command or argv.
    $shell = 'IFS= read -r PGPASSWORD; export PGPASSWORD; exec psql "$@"'
    $args = @('exec','-i','--user','postgres',$metadata.id,'/bin/sh','-c',$shell,'most-psql',
        '-X','-q','-A','-t','-v','ON_ERROR_STOP=1','-h','127.0.0.1','-p','55433',
        '-U',$Context.Fixture.DB_USERNAME,'-d',$Context.Fixture.DB_DATABASE)
    Assert-MostCondition ($Context.Fixture.DB_PASSWORD -notmatch '[\r\n]') 'linux_runtime_fixture_stdin_invalid'
    $inputText = $Context.Fixture.DB_PASSWORD + "`nSET statement_timeout='15s'; SET lock_timeout='5s';`n" + $Sql + "`n"
    $timer = [Diagnostics.Stopwatch]::StartNew()
    try { $result = Invoke-MostDocker $Context $args -Seconds ([Math]::Min(30, [Math]::Floor(180 - $Context.SqlElapsedSeconds))) -InputText $inputText -Cleanup:$Cleanup }
    finally { $Context.SqlElapsedSeconds += $timer.Elapsed.TotalSeconds }
    Assert-MostCondition ($Context.SqlElapsedSeconds -le 180) 'linux_runtime_sql_budget_exhausted'
    $Context.Journal.sql += [ordered]@{ statement = $Sql; exit = $result.ExitCode; elapsedMilliseconds = $result.ElapsedMilliseconds; output = $result.Stdout }
    Save-MostPgJournal $Context $Context.Journal.state
    return $result.Stdout.Trim()
}

function Get-MostPgCatalog($Context, [switch] $Cleanup) {
    $sql = @'
SELECT json_build_object(
 'role',(SELECT json_build_object('oid',oid,'name',rolname,'superuser',rolsuper,'createdb',rolcreatedb) FROM pg_roles WHERE rolname=current_user),
 'databases',(SELECT coalesce(json_agg(row_to_json(d) ORDER BY d.oid),'[]') FROM (SELECT oid,datname AS name,datdba AS owner FROM pg_database) d),
 'schemas',(SELECT coalesce(json_agg(row_to_json(s) ORDER BY s.oid),'[]') FROM (SELECT oid,nspname AS name,nspowner AS owner FROM pg_namespace) s),
 'objects',(SELECT coalesce(json_agg(row_to_json(o) ORDER BY o.kind,o.oid),'[]') FROM (
 SELECT 'class' AS kind,c.oid,c.relname AS name,c.relnamespace AS namespace,c.relowner AS owner FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname !~ '^pg_' AND n.nspname<>'information_schema'
 UNION ALL SELECT 'function',p.oid,p.proname,p.pronamespace,p.proowner FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname !~ '^pg_' AND n.nspname<>'information_schema'
 UNION ALL SELECT 'type',t.oid,t.typname,t.typnamespace,t.typowner FROM pg_type t JOIN pg_namespace n ON n.oid=t.typnamespace WHERE n.nspname !~ '^pg_' AND n.nspname<>'information_schema'
 ) o),
 'foreignClients',(SELECT count(*) FROM pg_stat_activity WHERE backend_type='client backend' AND pid<>pg_backend_pid())
)::text;
'@
    return ((Invoke-MostPgSql $Context $sql -Cleanup:$Cleanup) | ConvertFrom-Json)
}

function Remove-MostRunCatalogs($Context) {
    $baseline = $Context.Journal.catalogBaseline
    Assert-MostCondition ($baseline) 'linux_runtime_catalog_baseline_missing'
    $current = Get-MostPgCatalog $Context -Cleanup
    Assert-MostCondition ($current.foreignClients -eq 0) 'linux_runtime_catalog_cleanup_other_clients'
    $owner = [long] $baseline.role.oid
    foreach ($kind in @('schemas','databases')) {
        $new = @($current.$kind | Where-Object { $item = $_; -not @($baseline.$kind | Where-Object { $_.oid -eq $item.oid -and $_.name -ceq $item.name }).Count })
        foreach ($item in $new) {
            $format = if ($kind -eq 'schemas') { '^most_phpunit_[0-9a-f]{24}$' } else { '^most_phpunit_[0-9a-f]{24}_testing$' }
            Assert-MostCondition ($item.name -cmatch $format -and [long] $item.owner -eq $owner -and
                -not @($baseline.$kind | Where-Object { $_.name -ceq $item.name -or $_.oid -eq $item.oid }).Count) 'linux_runtime_new_catalog_provenance_ambiguous'
            $Context.Journal.generatedObjects += [ordered]@{ kind = $kind; database = $Context.Fixture.DB_DATABASE; name = $item.name; oid = $item.oid; owner = $item.owner }
            Save-MostPgJournal $Context 'CLEANUP_REQUIRED'
            # Recheck name/OID/owner server-side immediately before the exact
            # drop. A prefix alone never establishes ownership.
            $table = if ($kind -eq 'schemas') { 'pg_namespace' } else { 'pg_database' }
            $column = if ($kind -eq 'schemas') { 'nspname' } else { 'datname' }
            $ownerColumn = if ($kind -eq 'schemas') { 'nspowner' } else { 'datdba' }
            $object = if ($kind -eq 'schemas') { 'SCHEMA' } else { 'DATABASE' }
            $suffix = if ($kind -eq 'schemas') { 'CASCADE' } else { 'WITH (FORCE)' }
            $name = [string] $item.name # strictly lowercase generated-name regex above
            $oid = [long] $item.oid
            $proof = Invoke-MostPgSql $Context "SELECT count(*) FROM $table WHERE oid=$oid AND $column='$name' AND $ownerColumn=$owner;" -Cleanup
            Assert-MostCondition ($proof -ceq '1') 'linux_runtime_catalog_identity_changed'
            $drop = "SELECT format('DROP $object %I $suffix', $column) FROM $table WHERE oid=$oid AND $column='$name' AND $ownerColumn=$owner;`n\gexec"
            [void] (Invoke-MostPgSql $Context $drop -Cleanup)
        }
    }
    $after = Get-MostPgCatalog $Context -Cleanup
    foreach ($kind in @('schemas','databases','objects')) {
        Assert-MostCondition (($after.$kind | ConvertTo-Json -Depth 20 -Compress) -ceq ($baseline.$kind | ConvertTo-Json -Depth 20 -Compress)) 'linux_runtime_preexisting_catalog_changed_or_residual'
    }
    $Context.Journal.catalogAfter = $after
}

function Stop-MostOwnedPhp($Context, [string] $Id) {
    $meta = Get-MostContainerMetadata $Context $Id -Cleanup
    Assert-MostCondition ($meta.labels.'paperclip.issue' -ceq 'CMP-30' -and $meta.labels.'paperclip.run' -ceq $Context.Receipt.runId -and
        $meta.labels.'paperclip.source' -ceq $Context.Receipt.sourceHead -and $meta.image -ceq $Context.Receipt.phpImage -and
        $Id -cin $Context.Journal.phpIds -and $meta.host.NetworkMode -ceq ('container:' + $Context.Journal.replacementId)) 'linux_runtime_php_cleanup_identity_refused'
    Assert-MostCondition ($Id -cnotin $Context.Journal.phpCleanupAttemptedIds) 'linux_runtime_php_cleanup_requires_recovery'
    $Context.Journal.phpCleanupAttemptedIds += $Id
    Save-MostPgJournal $Context 'CLEANUP_REQUIRED'
    [void] (Invoke-MostDocker $Context @('rm','-f',$Id) -Seconds 30 -Cleanup)
    $remaining = Invoke-MostDocker $Context @('ps','-a','--no-trunc','--filter',('id=' + $Id),'--format','{{.ID}}') -Cleanup
    Assert-MostCondition (-not $remaining.Stdout.Trim()) 'linux_runtime_php_cleanup_not_absent'
}

function Invoke-MostLinuxPhp($Context, [string] $Name, [string[]] $PhpArguments, [int] $Seconds) {
    $r = $Context.Receipt
    $name = 'cmp30-' + $r.runId + '-' + $Name
    $collision = Invoke-MostDocker $Context @('ps','-a','--filter',('name=^' + $name + '$'),'--format','{{.ID}}')
    Assert-MostCondition (-not $collision.Stdout.Trim()) 'linux_runtime_php_name_collision'
    $cid = Join-Path $Context.Directory ($Name + '.cid')
    Assert-MostCondition (-not (Test-Path -LiteralPath $cid)) 'linux_runtime_php_cid_collision'
    $args = @('create','--name',$name,'--cidfile',$cid,'--label','paperclip.issue=CMP-30',
        '--label',('paperclip.run=' + $r.runId),'--label',('paperclip.source=' + $r.sourceHead),
        '--pull=never','--network',('container:' + $Context.Journal.replacementId),'--read-only','--cap-drop=ALL',
        '--security-opt=no-new-privileges','--user',([string] $r.phpUid + ':' + [string] $r.phpGid),
        '--pids-limit','64','--memory','1g','--cpus','1','--tmpfs','/tmp:rw,nosuid,nodev,noexec,size=268435456',
        '--mount',('type=bind,source=' + $r.snapshotPath + ',target=/work,readonly'),
        '--mount',('type=bind,source=' + $r.vendorPath + ',target=/work/vendor,readonly'),
        '--mount',('type=bind,source=' + (Join-Path $Context.Directory 'storage') + ',target=/work/storage'),
        '--mount',('type=bind,source=' + (Join-Path $Context.Directory 'bootstrap-cache') + ',target=/work/bootstrap/cache'),
        '--env-file',$Context.FixtureEnvPath,'--workdir','/work','--entrypoint','/usr/local/bin/php',$r.phpImage) + $PhpArguments
    $Context.Journal.pendingPhpName = $name; Save-MostPgJournal $Context 'PHP_CREATE_PENDING'
    try {
        [void] (Invoke-MostDocker $Context $args -Seconds 30)
    } finally {
        $id = ''
        if (Test-Path -LiteralPath $cid) { $id = [IO.File]::ReadAllText($cid).Trim() }
        else {
            $pending = Invoke-MostDocker $Context @('ps','-a','--no-trunc','--filter',('name=^' + $name + '$'),'--format','{{.ID}}') -Cleanup
            $id = $pending.Stdout.Trim()
        }
        if ($id) {
            Assert-MostCondition ($id -cmatch '^[0-9a-f]{64}$') 'linux_runtime_php_cid_invalid'
            $Context.Journal.phpIds += $id
            Save-MostPgJournal $Context 'PHP_CREATED'
        }
    }
    Assert-MostCondition ($id) 'linux_runtime_php_id_missing'
    $meta = Get-MostContainerMetadata $Context $id
    Assert-MostCondition ($meta.name -ceq ('/' + $name) -and $meta.labels.'paperclip.run' -ceq $r.runId -and
        $meta.labels.'paperclip.source' -ceq $r.sourceHead -and $meta.image -ceq $r.phpImage -and
        $meta.host.NetworkMode -ceq ('container:' + $Context.Journal.replacementId)) 'linux_runtime_php_create_drift'
    $abort = { Stop-MostOwnedPhp $Context $id }.GetNewClosure()
    try {
        $result = Invoke-MostDocker $Context @('start','-a',$id) -Seconds $Seconds -OnAbort $abort -AllowFailure
        $Context.Journal.phpResults += [ordered]@{ name = $Name; exit = $result.ExitCode; outcome = $result.Outcome; stdout = $result.Stdout; stderr = $result.Stderr }
        Save-MostPgJournal $Context 'PHP_TERMINAL'
        return $result
    } finally {
        # If the abort hook already removed it, don't reissue a destructive call.
        $exists = Invoke-MostDocker $Context @('ps','-a','--no-trunc','--filter',('id=' + $id),'--format','{{.ID}}') -Cleanup
        if ($exists.Stdout.Trim()) { Stop-MostOwnedPhp $Context $id }
    }
}

function Restore-MostOriginalPg($Context) {
    $r = $Context.Receipt
    if ($Context.Journal.replacementId) {
        $meta = Get-MostContainerMetadata $Context $Context.Journal.replacementId -Cleanup
        Assert-MostReplacement $Context $meta
        [void] (Invoke-MostDocker $Context @('stop','--time','20',$meta.id) -Seconds 30 -Cleanup)
        [void] (Invoke-MostDocker $Context @('rm',$meta.id) -Seconds 30 -Cleanup)
    }
    $network = Invoke-MostDocker $Context @('network','inspect','--format','{{.Id}}',$r.network.name) -Cleanup
    Assert-MostCondition ($network.Stdout.Trim() -ceq $r.network.id) 'linux_runtime_restore_network_changed'
    $volume = Invoke-MostDocker $Context @('volume','inspect','--format','{"name":{{json .Name}},"driver":{{json .Driver}},"created":{{json .CreatedAt}},"scope":{{json .Scope}},"options":{{json .Options}}}',$r.volume.name) -Cleanup
    Assert-MostCondition (($volume.Stdout | ConvertFrom-Json | ConvertTo-Json -Compress) -ceq ($r.volume.metadata | ConvertTo-Json -Compress)) 'linux_runtime_restore_volume_changed'
    $args = Get-MostComposeArguments $Context $Context.RestorePath
    [void] (Invoke-MostDocker $Context ($args + @('create','--no-build','--pull','never','postgres-testing')) -Seconds 60 -Cleanup)
    $created = Invoke-MostDocker $Context ($args + @('ps','-a','-q','postgres-testing')) -Cleanup
    $id = $created.Stdout.Trim()
    $metadata = Get-MostContainerMetadata $Context $id -Cleanup
    Assert-MostCondition ($id -cne $Context.Journal.originalId -and $metadata.state -ceq 'created' -and
        $metadata.image -ceq $r.pgImage -and $metadata.name -ceq '/most-postgres-tests-postgres-testing-1') 'linux_runtime_restore_not_stopped'
    # Ignore only recreation-specific container IDs and Compose file/hash
    # labels; compare the actual process, mounts, host settings and ports.
    $before = $Context.Journal.originalMetadata
    foreach ($field in @('path','args','host','mounts','ports')) {
        Assert-MostCondition (($metadata.$field | ConvertTo-Json -Depth 40 -Compress) -ceq ($before.$field | ConvertTo-Json -Depth 40 -Compress)) 'linux_runtime_restore_semantic_drift'
    }
    $networkIds = @($metadata.networks.PSObject.Properties | ForEach-Object { $_.Value.NetworkID })
    $networkNames = @($metadata.networks.PSObject.Properties.Name)
    Assert-MostCondition ($metadata.host.NetworkMode -ceq $before.host.NetworkMode -and $networkNames.Count -eq 1 -and
        $networkNames[0] -ceq $r.network.name -and ($networkIds[0] -ceq $r.network.id -or -not $networkIds[0])) 'linux_runtime_restore_network_identity_mismatch'
    $Context.Journal.restoredConfiguredNetworkId = $r.network.id
    $Context.Journal.restoredId = $id
    $Context.Journal.restoredMetadata = $metadata
    Save-MostPgJournal $Context 'RESTORED_STOPPED'
}

function Invoke-MostLinuxPostgresTests([string] $ReceiptPath, [string] $Root, [hashtable] $Fixture,
    [string] $SelectedRunner, [string] $SelectedPath) {
    $callClock = [Diagnostics.Stopwatch]::StartNew()
    $checked = Get-MostRuntimeReceipt $ReceiptPath $Root $SelectedRunner $SelectedPath
    $r = $checked.Receipt; $stage = $checked.Stage
    Assert-MostCondition ($r.phpUid -ge 1 -and $r.phpGid -ge 1) 'linux_runtime_php_nonroot_required'
    Assert-MostCondition ($Fixture.DB_USERNAME -cmatch '^[a-zA-Z_][a-zA-Z0-9_]*$' -and
        $Fixture.DB_DATABASE -cmatch '^[a-zA-Z_][a-zA-Z0-9_]*_testing$') 'linux_runtime_fixture_identifier_unsafe'
    $mutex = [Threading.Mutex]::new($false, 'Local\MostPostgresTests')
    $locked = $false; $context = $null; $exitCode = 1; $failure = $null
    try {
        try { $locked = $mutex.WaitOne([TimeSpan]::FromSeconds(60)) }
        catch [Threading.AbandonedMutexException] { $locked = $true; throw 'linux_runtime_abandoned_mutex_requires_recovery' }
        Assert-MostCondition $locked 'linux_runtime_mutex_timeout'
        $directory = Get-MostOwnedPath $r.scratchPath (Join-Path $r.scratchPath ('pg-' + $stage.name))
        Assert-MostCondition (-not (Test-Path -LiteralPath $directory)) 'linux_runtime_stage_already_reserved'
        New-Item -ItemType Directory -Path $directory | Out-Null
        # Reserve the single call under the mutex before any Docker operation.
        # A failed/partial call is consumed, not automatically retried.
        $journal = [ordered]@{ schemaVersion = 1; issueId = $r.issueId; runId = $r.runId; leaseId = $r.leaseId
            sourceHead = $r.sourceHead; stage = $stage.name; state = 'RESERVED'; clients = @(); sql = @(); phpIds = @(); phpCleanupAttemptedIds = @()
            phpResults = @(); generatedObjects = @(); originalId = ''; replacementId = ''; restoredId = ''
            originalRemoved = $false; catalogBaseline = $null; originalMetadata = $null; pendingPhpName = '' }
        $context = [pscustomobject]@{ Receipt = $r; Stage = $stage; Root = $Root; Fixture = $Fixture; Directory = $directory
            Journal = $journal; JournalPath = (Join-Path $directory 'journal.json'); Clock = $callClock
            SqlElapsedSeconds = 0.0; RuntimePath = ''; RestorePath = ''; FixtureEnvPath = ''; ExpectedOriginalMetadata = $null
            ChildEnvironment = @{ SystemRoot = 'C:/Windows'; WINDIR = 'C:/Windows'; ProgramFiles = 'C:/Program Files'; PATH = 'C:/Windows/System32'
                TEMP = (Join-Path $r.scratchPath 'tmpcli'); TMP = (Join-Path $r.scratchPath 'tmpcli'); DOCKER_CONFIG = $r.dockerConfigPath } }
        Save-MostPgJournal $context 'RESERVED'
        # Later calls consume only the restored-ID chain from successful earlier
        # calls. An unresolved journal retains the exclusive lease for recovery.
        $prior = @(Get-ChildItem -LiteralPath $r.scratchPath -Filter journal.json -Recurse |
            Where-Object { $_.FullName -cne $context.JournalPath } | ForEach-Object { Get-Content -LiteralPath $_.FullName -Raw -Encoding UTF8 | ConvertFrom-Json })
        foreach ($j in $prior) { Assert-MostCondition ($j.runId -ceq $r.runId -and $j.leaseId -ceq $r.leaseId -and $j.state -ceq 'RESTORED_STOPPED' -and $j.testExitCode -eq 0) 'linux_runtime_previous_call_not_accepted' }
        if ($SelectedPath -cne 'tests/Feature/Infrastructure/PhpUnitPostgresProfileTest.php') {
            Assert-MostCondition (@($prior | Where-Object { $_.stage -ceq $r.infrastructureStage -and $_.testExitCode -eq 0 }).Count -eq 1) 'linux_runtime_infrastructure_gate_missing'
        }
        $originalId = [string] $r.originalContainerId
        if ($prior.Count) {
            $previous = @($prior | Sort-Object updatedAtUtc)[-1]
            $originalId = [string] $previous.restoredId
            $context.ExpectedOriginalMetadata = $previous.restoredMetadata
        }
        $daemon = Invoke-MostDocker $context @('info','--format','{{.ID}}')
        Assert-MostCondition ($daemon.Stdout.Trim() -ceq $r.daemonId -and -not $daemon.Stderr) 'linux_runtime_daemon_mismatch'
        $image = Invoke-MostDocker $context @('image','inspect','--format','{"id":{{json .Id}},"os":{{json .Os}},"architecture":{{json .Architecture}},"volumes":{{json .Config.Volumes}}}',$r.phpImage)
        $physicalImage = $image.Stdout | ConvertFrom-Json
        Assert-MostCondition ($physicalImage.id -ceq $r.phpImage -and $physicalImage.os -ceq 'linux' -and
            $physicalImage.architecture -ceq 'amd64' -and -not $physicalImage.volumes) 'linux_runtime_php_image_precondition_failed'
        New-MostPgModels $context
        Assert-MostOriginalPg $context $originalId
        Assert-MostCondition ($context.Clock.Elapsed.TotalSeconds -le 150) 'linux_runtime_preflight_deadline'
        Save-MostPgJournal $context 'ORIGINAL_REMOVE_PENDING'
        # Record intent first: a client error after removal is ambiguous and must
        # still lead to exact-ID recovery/restoration, never a broad reset.
        $context.Journal.originalRemoved = $true
        try { [void] (Invoke-MostDocker $context @('rm',$originalId) -Seconds 30) }
        catch {
            $remainingOriginal = Invoke-MostDocker $context @('ps','-a','--no-trunc','--filter',('id=' + $originalId),'--format','{{.ID}}') -Cleanup
            if ($remainingOriginal.Stdout.Trim() -ceq $originalId) {
                $unchanged = Get-MostContainerMetadata $context $originalId -Cleanup
                Assert-MostCondition (($unchanged | ConvertTo-Json -Depth 40 -Compress) -ceq
                    ($context.Journal.originalMetadata | ConvertTo-Json -Depth 40 -Compress)) 'linux_runtime_original_remove_ambiguous'
                $context.Journal.originalRemoved = $false
            }
            throw
        }
        Save-MostPgJournal $context 'ORIGINAL_REMOVED'
        $args = Get-MostComposeArguments $context $context.RuntimePath
        try {
            [void] (Invoke-MostDocker $context ($args + @('up','-d','--no-build','--pull','never','--no-deps','--wait','--wait-timeout','60','postgres-testing')) -Seconds 90)
        } finally {
            # Resolve even a failed/timed-out up before any cleanup attempt.
            $created = Invoke-MostDocker $context ($args + @('ps','-a','-q','postgres-testing')) -Cleanup
            if ($created.Stdout.Trim()) { $context.Journal.replacementId = $created.Stdout.Trim(); Save-MostPgJournal $context 'CLEANUP_REQUIRED' }
        }
        $metadata = Get-MostContainerMetadata $context $context.Journal.replacementId
        Assert-MostReplacement $context $metadata
        Assert-MostCondition ($context.Clock.Elapsed.TotalSeconds -le 240) 'linux_runtime_readiness_deadline'
        $namespaceShell = 'test "$(ls /sys/class/net)" = lo && ! awk ''NR>1 && $1!="lo" {bad=1} END {exit !bad}'' /proc/net/route && ! awk ''$10!="lo" {bad=1} END {exit !bad}'' /proc/net/ipv6_route && readlink /proc/self/ns/net'
        $namespace = Invoke-MostDocker $context @('exec',$metadata.id,'/bin/sh','-c',$namespaceShell)
        $context.Journal.pgNamespace = $namespace.Stdout.Trim()
        $pg = Invoke-MostPgSql $context "SELECT json_build_object('version',current_setting('server_version_num')::int/10000,'listen',current_setting('listen_addresses'),'address',inet_server_addr(),'port',inet_server_port())::text;"
        $pgProof = $pg | ConvertFrom-Json
        Assert-MostCondition ($pgProof.version -eq 16 -and $pgProof.listen -ceq '127.0.0.1' -and $pgProof.address -ceq '127.0.0.1' -and $pgProof.port -eq 55433) 'linux_runtime_pg_listener_not_loopback'
        $baseline = Get-MostPgCatalog $context
        Assert-MostCondition ($baseline.foreignClients -eq 0 -and $baseline.role.superuser -eq $true -and $baseline.role.createdb -eq $true) 'linux_runtime_catalog_exclusivity_or_cleanup_privilege_failed'
        $context.Journal.catalogBaseline = $baseline; Save-MostPgJournal $context 'CATALOG_BASELINE_CAPTURED'
        foreach ($sub in @('storage/framework/cache','storage/framework/sessions','storage/framework/views','storage/logs','bootstrap-cache')) {
            New-Item -ItemType Directory -Path (Join-Path $directory $sub) -Force | Out-Null
        }
        $fixtureEnvironment = @{}; foreach ($key in $Fixture.Keys) { $fixtureEnvironment[$key] = $Fixture[$key] }
        $fixtureEnvironment.APP_ENV = 'testing'; $fixtureEnvironment.MOST_POSTGRES_TEST_PROFILE = ''
        $fixtureEnvironment.HOME = '/tmp/home'; $fixtureEnvironment.TMPDIR = '/tmp'; $fixtureEnvironment.COMPOSER_HOME = '/tmp/home'
        $fixtureEnvironment.COMPOSER_CACHE_DIR = '/tmp/composer-cache'
        $context.FixtureEnvPath = Join-Path $directory 'fixture.env'
        $lines = foreach ($key in ($fixtureEnvironment.Keys | Sort-Object)) {
            Assert-MostCondition ($key -cmatch '^[A-Z_][A-Z0-9_]*$' -and [string] $fixtureEnvironment[$key] -notmatch '[\r\n]') 'linux_runtime_fixture_env_invalid'
            $key + '=' + [string] $fixtureEnvironment[$key]
        }
        [IO.File]::WriteAllLines($context.FixtureEnvPath, $lines, [Text.UTF8Encoding]::new($false))
        $probeCode = @'
$ifaces=array_values(array_diff(scandir('/sys/class/net'),['.','..']));sort($ifaces);if($ifaces!==['lo'])exit(21);
foreach(array_slice(file('/proc/net/route'),1) as $l){$p=preg_split('/\s+/',trim($l));if($p[0]!=='lo')exit(22);}
foreach(file('/proc/net/ipv6_route') as $l){$p=preg_split('/\s+/',trim($l));if(end($p)!=='lo')exit(23);}
try{$db=new PDO('pgsql:host=127.0.0.1;port=55433;dbname='.getenv('DB_DATABASE'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'));$v=$db->query('show server_version_num')->fetchColumn();if(intdiv((int)$v,10000)!==16)exit(24);echo json_encode(['namespace'=>readlink('/proc/self/ns/net'),'interfaces'=>$ifaces,'pdo'=>true,'pgMajor'=>16]);}catch(Throwable $e){fwrite(STDERR,"loopback_pdo_failed\n");exit(25);}
'@
        $probe = Invoke-MostLinuxPhp $context ($stage.name + '-namespace') @('-r',$probeCode) 30
        Assert-MostCondition ($probe.ExitCode -eq 0 -and $probe.Outcome -ceq 'completed' -and -not $probe.Truncated) 'linux_runtime_php_namespace_probe_failed'
        $proof = $probe.Stdout | ConvertFrom-Json
        Assert-MostCondition ($proof.namespace -ceq $context.Journal.pgNamespace -and $proof.pdo -eq $true) 'linux_runtime_namespace_not_shared'
        Save-MostPgJournal $context 'PHYSICAL_LOOPBACK_VERIFIED'
        $phpArguments = @(('/work/vendor/bin/' + $SelectedRunner),'-c','/work/phpunit.xml',('/work/' + $SelectedPath))
        $test = Invoke-MostLinuxPhp $context $stage.name $phpArguments $stage.testMaxSeconds
        $exitCode = $test.ExitCode
        if ($test.Outcome -cne 'completed' -or $test.Truncated) { $exitCode = 1 }
        $context.Journal.testExitCode = $exitCode
        Write-Output $test.Stdout
        if ($test.Stderr) { [Console]::Error.WriteLine($test.Stderr) }
    } catch {
        $failure = $_.Exception.Message; $exitCode = 1
        if ($context) { $context.Journal.failure = $failure; $context.Journal.testExitCode = $exitCode }
    } finally {
        if ($context -and $context.Journal.originalRemoved) {
            Save-MostPgJournal $context 'CLEANUP_REQUIRED'
            try {
                # No SQL begins until all exact owned PHP IDs are terminal.
                foreach ($id in $context.Journal.phpIds) {
                    $exists = Invoke-MostDocker $context @('ps','-a','--no-trunc','--filter',('id=' + $id),'--format','{{.ID}}') -Cleanup
                    if ($exists.Stdout.Trim()) { Stop-MostOwnedPhp $context $id }
                }
                $sqlFailure = $null
                try { if ($context.Journal.catalogBaseline) { Remove-MostRunCatalogs $context } }
                catch { $sqlFailure = $_.Exception.Message; $context.Journal.sqlCleanupFailure = $sqlFailure }
                # Restoration is still attempted after a catalog failure. The
                # lease remains retained and the journal is CLEANUP_REQUIRED.
                Restore-MostOriginalPg $context
                if ($sqlFailure) { throw 'linux_runtime_catalog_cleanup_required' }
            } catch {
                $context.Journal.cleanupFailure = $_.Exception.Message
                Save-MostPgJournal $context 'CLEANUP_REQUIRED'
                $failure = 'linux_runtime_cleanup_required'; $exitCode = 1
            }
        } elseif ($context) {
            $context.Journal.failure = $failure
            Save-MostPgJournal $context 'PREFLIGHT_STOPPED_NO_RESOURCE_CHANGE'
        }
        if ($locked) { $mutex.ReleaseMutex() }
        $mutex.Dispose()
    }
    if ($failure) { [Console]::Error.WriteLine($failure) }
    return $exitCode
}

if ($LeaseReceiptPath -and -not $LinuxPhp) { throw 'lease_receipt_requires_linux_php' }
if ($LinuxPhp) {
    if ($IsolatedAiAssistant -or $LogProgressEvents -or $TestSuite -or $Filter -or $env:MOST_POSTGRES_TEST_PROFILE) {
        throw 'linux_postgres_selection_or_profile_unsafe'
    }
    $linuxExitCode = Invoke-MostLinuxPostgresTests $LeaseReceiptPath $root $environment $Runner $TestPath
    exit $linuxExitCode
}

$mutexName = if ($IsolatedAiAssistant) { 'Local\MostAIAssistantPostgresTests' } else { 'Local\MostPostgresTests' }
$pirTestsMutex = [System.Threading.Mutex]::new($false, $mutexName)
$pirTestsLockAcquired = $false
try {
    while (-not $pirTestsLockAcquired) {
        try {
            $pirTestsLockAcquired = $pirTestsMutex.WaitOne([TimeSpan]::FromSeconds(30))
        } catch [System.Threading.AbandonedMutexException] {
            $pirTestsLockAcquired = $true
        }
        if (-not $pirTestsLockAcquired) {
            Write-Host 'Ожидание завершения другого запуска PostgreSQL-тестов...'
        }
    }

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'docker_command_unavailable'
}

$dockerReady = $false
for ($attempt = 1; $attempt -le 30; $attempt++) {
    $dockerServerVersion = (& docker info --format '{{.ServerVersion}}' 2>&1 | Out-String).Trim()
    if ($LASTEXITCODE -eq 0 -and $dockerServerVersion -match '^\d+\.\d+') {
        $dockerReady = $true
        break
    }

    Start-Sleep -Seconds 2
}

if (-not $dockerReady) {
    throw 'docker_daemon_unavailable'
}

$composePath = Join-Path $root 'compose.testing.yml'
$projectName = $selectedProject
if ($IsolatedAiAssistant) {
    $composeContents = Get-Content -LiteralPath $composePath -Raw -Encoding UTF8
    if ([regex]::Matches($composeContents, '127\.0\.0\.1:55433:5432').Count -ne 1 -or
        $composeContents -notmatch 'image:\s*pgvector/pgvector:0\.8\.5-pg16-bookworm') {
        throw 'postgres_test_isolated_compose_source_unsafe'
    }
    $privateComposeDirectory = Join-Path $root 'storage/app/private/postgres-ai-assistant'
    New-Item -ItemType Directory -Path $privateComposeDirectory -Force | Out-Null
    $composePath = Join-Path $privateComposeDirectory 'compose.testing.yml'
    [IO.File]::WriteAllText($composePath, $composeContents.Replace('127.0.0.1:55433:5432', '127.0.0.1:55443:5432'), [Text.UTF8Encoding]::new($false))
}
$previousProfile = $env:MOST_POSTGRES_TEST_PROFILE

Push-Location $root
try {
    if (-not $IsolatedAiAssistant) {
        & docker compose -p $projectName -f $composePath down --volumes --remove-orphans
        if ($LASTEXITCODE -ne 0) {
            throw 'postgres_test_container_reset_failed'
        }
    }

    & docker compose --project-directory $root -p $projectName -f $composePath up -d --wait --wait-timeout 60
    if ($LASTEXITCODE -ne 0) {
        throw 'postgres_test_container_start_failed'
    }

    $phpunitArguments = @("vendor/bin/$Runner", '-c', $phpunitConfigurationPath)
    if (-not [string]::IsNullOrWhiteSpace($TestSuite)) {
        $phpunitArguments += @('--testsuite', $TestSuite)
    } else {
        $phpunitArguments += $TestPath
    }
    if (-not [string]::IsNullOrWhiteSpace($Filter)) {
        $phpunitArguments += @('--filter', $Filter)
    }

    if ($LogProgressEvents) {
        $eventsDirectory = [IO.Path]::GetFullPath((Join-Path $root 'storage/app/private/assistant-audit'))
        New-Item -ItemType Directory -Path $eventsDirectory -Force | Out-Null
        $eventsLogPath = Join-Path $eventsDirectory ((Get-Date -Format 'yyyyMMdd-HHmmss')+'-'+[guid]::NewGuid().ToString('N')+'.events.log')
        $phpunitArguments += @('--log-events-text', $eventsLogPath)
        Write-Host ('PHPUnit events: '+$eventsLogPath)
    }

    if ($IsolatedAiAssistant) { $env:MOST_POSTGRES_TEST_PROFILE = 'ai-assistant' }
    & php @phpunitArguments
    $testExitCode = $LASTEXITCODE
} finally {
    if ($null -eq $previousProfile) { Remove-Item Env:MOST_POSTGRES_TEST_PROFILE -ErrorAction SilentlyContinue }
    else { $env:MOST_POSTGRES_TEST_PROFILE = $previousProfile }
    Pop-Location
}

exit $testExitCode
} finally {
    if ($pirTestsLockAcquired) {
        $pirTestsMutex.ReleaseMutex()
    }
    $pirTestsMutex.Dispose()
}
