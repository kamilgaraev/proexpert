<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy\Gateway;

use LogicException;
use JsonException;
use Most\PublicCore\GatewayRuntimeBootstrap;
use Most\PublicCore\AppRuntimeBootstrap;
use Most\PublicCore\ProtectedRoleBootstrap;
use App\BusinessModules\Features\AIAssistant\AIAssistantServiceProvider;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreAssistantRuntime;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreBackendAuthorityFence;
use Illuminate\Foundation\Application;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4).'/docker/public-core/runtime.php';

final class PublicCoreRuntimeBootstrapTest extends TestCase
{

    /** @param array<string, string> $environment
     *  @return array{exit: int, stdout: string, stderr: string}
     */
    private function inputPreparationProcess(string $script, array $environment): array
    {
        $binary = PHP_OS_FAMILY === 'Windows' ? 'C:/Program Files/Git/bin/bash.exe' : '/bin/bash';
        $process = proc_open([$binary, '-c', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        if (!is_resource($process)) { throw new LogicException('fixture_process_unavailable'); }
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    public function testInputPreparationRoutingNeverDeploysOnPushOrWrongMainIdentity(): void
    {
        $workflow = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 4).'/.github/workflows/deploy-backend.yml');
        $source = str_replace(' >> "$GITHUB_OUTPUT"', '', $workflow['jobs']['release_mode']['steps'][0]['run']);
        $sha = str_repeat('a', 40);
        // No file or credentials: redirect only public selection outputs to stdout.
        foreach (['release', 'input-only', 'input-prepare', 'qualification-only', 'unknown'] as $mode) {
            foreach (['push', 'workflow_dispatch'] as $event) {
                foreach (['refs/heads/main', 'refs/heads/task/fixture'] as $ref) {
                    foreach ([$sha, '', str_repeat('b', 40)] as $expected) {
                        $result = $this->inputPreparationProcess($source, ['GITHUB_SHA' => $sha, 'GITHUB_REF' => $ref,
                            'GITHUB_EVENT_NAME' => $event, 'REQUESTED_MODE' => $mode, 'EXPECTED_SOURCE_SHA' => $expected,
                            'GITHUB_OUTPUT' => '/dev/stdout']);
                        $valid = $event === 'workflow_dispatch' && $ref === 'refs/heads/main' && $expected === $sha;
                        $expectedOutput = 'allowed='.($valid && $mode === 'release' ? 'true' : 'false')."\n"
                            .'input_allowed='.($valid && $mode === 'input-only' ? 'true' : 'false')."\n"
                            .'prepare_allowed='.($valid && $mode === 'input-prepare' ? 'true' : 'false')."\n";
                        self::assertSame(0, $result['exit']); self::assertSame('', $result['stderr']);
                        self::assertSame($expectedOutput, $result['stdout'], $mode.'/'.$event.'/'.$ref.'/'.$expected);
                    }
                }
            }
        }
    }

    public function testInputPreparationAndReleaseRejectBeforeCredentialSentinel(): void
    {
        $workflow = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 4).'/.github/workflows/deploy-backend.yml');
        $sha = str_repeat('a', 40);
        $base = ['GITHUB_SHA' => $sha, 'GITHUB_REF' => 'refs/heads/main', 'GITHUB_EVENT_NAME' => 'workflow_dispatch',
            'EXPECTED_SOURCE_SHA' => $sha, 'PREPARATION_REF' => 'ref_'.str_repeat('b', 32), 'PREPARATION_SHA' => $sha,
            'PREPARATION_PINS_SHA256' => str_repeat('c', 64), 'ACCEPTED_MAIN_SHA' => $sha,
            'CURRENT_CANDIDATE_REVISION' => '12', 'CURRENT_CANDIDATE_SHA256' => str_repeat('d', 64),
            'INPUT_IMAGE_REF' => 'ghcr.io/kamilgaraev/proexpert/prohelper@sha256:'.str_repeat('e', 64)];
        foreach (['model_input_prepare' => 'input-prepare', 'model_input' => 'input-only', 'deploy' => 'release'] as $job => $mode) {
            $code = $workflow['jobs'][$job]['steps'][0]['run']."\nprintf 'CREDENTIAL_SENTINEL\\n'\n";
            $cases = [[], ['GITHUB_EVENT_NAME' => 'push'], ['GITHUB_REF' => 'refs/heads/task/fixture'],
                ['REQUESTED_MODE' => 'qualification-only'], ['EXPECTED_SOURCE_SHA' => ''],
                ['EXPECTED_SOURCE_SHA' => strtoupper($sha)], ['EXPECTED_SOURCE_SHA' => $sha."\n"],
                ['EXPECTED_SOURCE_SHA' => str_repeat('f', 40)]];
            if ($job === 'deploy') {
                $independenceCases = [];
                foreach (['', strtoupper($sha), $sha."\n", str_repeat('f', 40)] as $value) { $independenceCases[] = ['ACCEPTED_MAIN_SHA' => $value]; }
                $independenceCases[] = ['CURRENT_CANDIDATE_REVISION' => '']; $independenceCases[] = ['CURRENT_CANDIDATE_SHA256' => ''];
                foreach ($independenceCases as $index => $change) {
                    $result = $this->inputPreparationProcess($code, array_replace($base, ['REQUESTED_MODE' => $mode], $change));
                    self::assertSame(0, $result['exit'], $job.'/independence/'.$index);
                    self::assertSame("CREDENTIAL_SENTINEL\n", $result['stdout'], $job.'/independence/'.$index);
                    self::assertSame('', $result['stderr']);
                }
            } else {
                $cases[] = ['PREPARATION_REF' => '']; $cases[] = ['PREPARATION_SHA' => str_repeat('f', 40)];
                $cases[] = ['PREPARATION_PINS_SHA256' => ''];
                if ($job === 'model_input') { $cases[] = ['INPUT_IMAGE_REF' => 'foreign:latest']; }
            }
            foreach ($cases as $index => $change) {
                $result = $this->inputPreparationProcess($code, array_replace($base, ['REQUESTED_MODE' => $mode], $change));
                self::assertSame($index === 0, $result['exit'] === 0, $job.'/'.$index);
                self::assertSame($index === 0 ? "CREDENTIAL_SENTINEL\n" : '', $result['stdout'], $job.'/'.$index);
                self::assertSame('', $result['stderr']);
            }
        }
    }

    public function testInputPreparationBootstrapCannotSourceSuppliedStageOrUseProviderStore(): void
    {
        $workflow = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 4).'/.github/workflows/deploy-backend.yml');
        $helper = file_get_contents(dirname(__DIR__, 4).'/deploy/backend-runtime-allowlist.sh');
        $bootstrap = substr($helper, strpos($helper, '# BEGIN fixed input transport.'));
        $prepare = $workflow['jobs']['model_input_prepare']['steps'][6]['with']['script'];
        $start = strpos($prepare, '# BEGIN fixed input transport.');
        $end = strpos($prepare, '# END fixed input transport.') + strlen('# END fixed input transport.');
        self::assertSame(trim($bootstrap), substr($prepare, $start, $end - $start));
        foreach (['/var/www/prohelper', 'describe-model-input', 'provision-credentials', 'publish-projections', 'docker compose'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $prepare);
        }
        self::assertDoesNotMatchRegularExpression('/^\s*source\s/m', $prepare);
        self::assertFalse($workflow['jobs']['model_input']['steps'][1]['with']['persist-credentials']);
        self::assertFalse($workflow['jobs']['model_input_prepare']['steps'][1]['with']['persist-credentials']);
        $input = $workflow['jobs']['model_input']['steps'][3]['with']['script'];
        self::assertLessThan(strpos($input, 'source /proc/self/fd/3'), strpos($input, 'validate_public_core_input_source "${INPUT_IMAGE_REF}"'));
        self::assertLessThan(strpos($input, 'source /proc/self/fd/3'), strpos($input, 'test "${helper_hash%% *}"'));
        self::assertStringNotContainsString('git rev-parse', $input);
    }

    private function example(): string
    {
        return dirname(__DIR__, 4).'/deploy/public-core-runtime.json.example';
    }

    public function testNativeResponsesProfileRequiresNewMethodAdapterAndFingerprint(): void
    {
        $class = \App\Services\Privacy\Gateway\Contracts\GatewayModelProfile::class;
        $value = ['profileRef' => 'profile:native-source-fixture', 'qualification' => 'actual',
            'adapterRevision' => $class::ADAPTER_REVISION, 'apiMethod' => 'responses', 'endpoint' => 'https://api.timeweb.ai/v1/responses',
            'modelId' => 'openai/gpt-6-luna', 'modelRevision' => 'synthetic-native-only', 'tokenizerId' => 'fixture-bpe', 'tokenizerRevision' => 'fixture-v1',
            'mappingEvidenceRef' => 'evidence:fixture-tokenizer', 'capabilityEvidenceRef' => 'evidence:fixture-native-method',
            'capacityEvidenceRef' => 'evidence:fixture-capacity', 'contextWindow' => 4096, 'maxOutputTokens' => 512, 'answerReserve' => 768, 'toolReserve' => 128];
        $profile = $class::fromArray($value);
        self::assertSame('responses', $profile->values()['apiMethod']);
        self::assertSame(hash('sha256', \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::canonicalJson($value)), $profile->fingerprint());
        foreach ([['apiMethod' => 'chat_completions'], ['endpoint' => 'https://api.timeweb.ai/v1/chat/completions'],
            ['adapterRevision' => 'fixture-v1'], ['capabilityEvidenceRef' => null]] as $change) {
            try { $class::fromArray(array_replace($value, $change)); self::fail('Historical method/profile accepted'); }
            catch (LogicException $error) { self::assertSame('model_profile_unqualified', $error->getMessage()); }
        }
        self::assertNotSame($profile->fingerprint(), $class::fromArray(array_replace($value, ['capabilityEvidenceRef' => 'evidence:other-native-proof']))->fingerprint());
    }

    public function testModelInputExportUsesOnlyStagedLiteralSourcePolicyAndRedactsPrivateValues(): void
    {
        $class = \Most\PublicCore\ModelInputDescription::class;
        $parse = \Most\PublicCore\ManagedLiteralEnvironment::parse(...);
        $sha = str_repeat('a', 40);
        $description = $class::describe($parse("TIMEWEB_AI_API_KEY=\nTIMEWEB_API_KEY=fixture-secret-do-not-output\nTIMEWEB_AI_TIMEOUT=29\nTIMEWEB_AI_FAST_MAX_TOKENS=777\nTIMEWEB_AI_MODEL=untrusted-model\nAPP_KEY=private-not-selected\n", $class::NAMES), $sha);
        self::assertSame('TIMEWEB_API_KEY', $description['credentialReference']);
        self::assertSame('responses', $description['apiMethod']);
        self::assertNotSame('chat_completions', $description['apiMethod']);
        self::assertSame('openai/gpt-6-luna', $description['modelId']);
        self::assertSame(29, $description['profiles']['assistant']['timeout']);
        self::assertSame(777, $description['profiles']['fast']['maxOutputTokens']);
        self::assertSame('managed_store', $description['fieldOrigins']['TIMEWEB_AI_FAST_MAX_TOKENS']);
        self::assertSame('default', $description['fieldOrigins']['TIMEWEB_AI_PREMIUM_MAX_TOKENS']);
        self::assertSame('source_policy', $description['fieldOrigins']['modelId']);
        $json = json_encode($description, JSON_THROW_ON_ERROR);
        foreach (['fixture-secret-do-not-output', 'private-not-selected', 'untrusted-model', 'APP_KEY', 'modelRevision', 'contextWindow', 'tokenizerSha256'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $json);
        }
        self::assertLessThanOrEqual(16384, strlen($json));
        foreach (['effectiveRuntimeSettingsObserved', 'actualModelQualified', 'activationAuthorized'] as $flag) { self::assertFalse($description[$flag]); }
        self::assertSame('unavailable', $class::describe([], $sha)['credentialReference']);
        self::assertSame('assistant', $class::describe(['TIMEWEB_AI_DEFAULT_PROFILE' => ''], $sha)['defaultProfile']);
        self::assertSame('openai', $class::describe(['LLM_PROVIDER' => 'OPENAI'], $sha)['provider']);
        self::assertSame('unavailable', $class::describe(['LLM_PROVIDER' => 'openai'], $sha)['apiMethod']);
    }

    public function testModelInputLiteralPrecedenceAndAmbiguityFailWithFixedErrorOnly(): void
    {
        $class = \Most\PublicCore\ModelInputDescription::class;
        self::assertSame('env', $class::selected(['X' => 'env'], ['X' => 'server'], ['X' => 'process'], 'X', 'default'));
        self::assertSame('server', $class::selected([], ['X' => 'server'], ['X' => 'process'], 'X', 'default'));
        self::assertSame('process', $class::selected([], [], ['X' => 'process'], 'X', 'default'));
        self::assertSame('default', $class::selected(['X' => ''], ['X' => 'server'], [], 'X', 'default'));
        foreach (["LLM_PROVIDER=timeweb\nLLM_PROVIDER=openai\n", 'TIMEWEB_AI_API_KEY="${PRIVATE_VALUE}"', 'TIMEWEB_AI_TIMEOUT="unfinished'] as $bytes) {
            try { \Most\PublicCore\ManagedLiteralEnvironment::parse($bytes, $class::NAMES); self::fail('Ambiguous selected literal accepted'); }
            catch (LogicException $error) { self::assertSame('model_input_unavailable', $error->getMessage()); }
        }
        foreach ([['LLM_PROVIDER' => 'secret-unknown-provider'], ['TIMEWEB_AI_BASE_URI' => 'https://secret:key@example.test/?private'],
            ['TIMEWEB_AI_DEFAULT_PROFILE' => 'secret-unknown-profile'], ['TIMEWEB_AI_FAST_TIMEOUT' => 'bad-private-value'],
            ['TIMEWEB_AI_MAX_TOKENS' => '0'], ['UNLISTED' => 'private']] as $store) {
            try { $class::describe($store, str_repeat('a', 40)); self::fail('Invalid selected metadata accepted'); }
            catch (LogicException $error) { self::assertSame('model_input_unavailable', $error->getMessage()); }
        }
    }

    public function testDeferredAppReaderDoesNotBindBeforePublicationAndRechecksEachScope(): void
    {
        $app = $this->application(); $published = false; $reads = 0;
        $reader = static function () use (&$published, &$reads): \Closure {
            $reads++;
            return static function ($app, $configure) use (&$published): void {
                if (!$published) { throw new LogicException('runtime_not_activated'); }
                $configure(new PublicCoreBackendAuthorityFence(), static fn (int $expiry): null => null);
            };
        };
        self::assertTrue(AppRuntimeBootstrap::defer($app, $reader));
        self::assertSame(0, $reads); // Worker boot performs no DB/native/provider preparation.
        $first = $app->make(PublicCoreAssistantRuntime::class);
        self::assertNull((new \ReflectionProperty($first, 'nativePortFactory'))->getValue($first));
        $published = true; $app->forgetScopedInstances();
        $second = $app->make(PublicCoreAssistantRuntime::class);
        self::assertNotSame($first, $second);
        self::assertInstanceOf(\Closure::class, (new \ReflectionProperty($second, 'nativePortFactory'))->getValue($second));
        $published = false; $app->forgetScopedInstances();
        $third = $app->make(PublicCoreAssistantRuntime::class);
        self::assertNull((new \ReflectionProperty($third, 'nativePortFactory'))->getValue($third));
        self::assertSame(3, $reads);
        self::assertFalse(AppRuntimeBootstrap::defer($app, $reader)); // Already resolved scope cannot be swapped.
    }

    public function testAppRegistrationInstallsUnavailableDeferredBindingBeforeProtectedInputsExist(): void
    {
        $app = $this->application();
        self::assertTrue(AppRuntimeBootstrap::register($app, '/not-observed/source-fixture/bootstrap.php'));
        self::assertTrue($app->bound(PublicCoreAssistantRuntime::class));
        $first = $app->make(PublicCoreAssistantRuntime::class);
        self::assertNull((new \ReflectionProperty($first, 'nativePortFactory'))->getValue($first));
        $app->forgetScopedInstances();
        $second = $app->make(PublicCoreAssistantRuntime::class);
        self::assertNotSame($first, $second);
        self::assertNull((new \ReflectionProperty($second, 'nativePortFactory'))->getValue($second));
        self::assertFalse(AppRuntimeBootstrap::register($app));
    }

    public function testDeferredAppReaderRejectsEmptyAndDuplicateConfiguration(): void
    {
        foreach ([0, 2] as $count) {
            $app = $this->application();
            AppRuntimeBootstrap::defer($app, static fn (): \Closure => static function ($app, $configure) use ($count): void {
                for ($i = 0; $i < $count; $i++) { $configure(new PublicCoreBackendAuthorityFence(), static fn (): null => null); }
            });
            $runtime = $app->make(PublicCoreAssistantRuntime::class);
            self::assertNull((new \ReflectionProperty($runtime, 'nativePortFactory'))->getValue($runtime));
        }
    }

    public function testApprovedTupleRejectsFinalErrorsAndResumeDrift(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') { self::markTestSkipped('Offline Bash tuple mocks need Linux, no Docker calls.'); }
        // All authority/generation/lifetime values below are synthetic source fixtures.
        $harness = <<<'TUPLE_BASH'
source "$1"
fixture_mode="$2"; scratch="$3"
api=$(printf '%064d' 1); processor=$(printf '%064d' 2); gateway=$(printf '%064d' 3); image=sha256:$(printf '%064d' 4); release=$(printf '%040d' 5)
python3() { [ "$fixture_mode" != inactive ] && printf approved || printf inactive; }
public_core_monotonic_ns() { if [[ "$fixture_mode" == expired* ]] && [ -e "$scratch/${fixture_mode#expired_}" ]; then printf 31000000000; else printf 1000000000; fi; }
public_core_process_lifetime() {
  if [ -e "$scratch/resumed" ] && [ "$fixture_mode" = lifetime_error ]; then printf '%064d' 6; return 17; fi
  if [ -e "$scratch/resumed" ] && [ "$fixture_mode" = lifetime_drift ]; then printf '%064d' 7; else printf '%064d' 6; fi
}
public_core_generation_identity() {
  if [ -e "$scratch/resumed" ] && [ "$fixture_mode" = generation_expired ]; then return 17; fi
  if [ -e "$scratch/resumed" ] && [ "$fixture_mode" = generation_error ]; then printf '%064d' 8; return 17; fi
  if [ -e "$scratch/resumed" ] && [ "$fixture_mode" = generation_drift ]; then printf '%064d' 9; else printf '%064d' 8; fi
}
observe_public_core_parked_peers() { printf observe >> "$scratch/events"; [ "$fixture_mode" != observation ]; }
prepare_public_core_projections() { printf publish >> "$scratch/events"; touch "$scratch/published"; [ "$fixture_mode" != missing_proof ]; }
# New CURRENT importer is mocked separately from actual tuple checks; no measurements fabricated.
MOST_PUBLIC_CORE_CURRENT_REVISION=7; MOST_PUBLIC_CORE_CURRENT_SHA256=$(printf '%064d' 9)
verify_public_core_current_candidate() { :; }
stage_public_core_current_publication_inputs() { [ "$fixture_mode" != missing_proof ]; }
MOST_COMPOSE_WRITER_SERVICES=(api queue-worker scheduler)
docker() {
  case "$1" in
    compose)
      printf compose >> "$scratch/events"
      if [[ "$*" == *queue-worker* ]]; then
        if [ -n "${MOST_PUBLIC_CORE_STAGED_API:-}" ]; then
          [[ "$*" != *api* ]] && [[ "$*" != *public-core-* ]] && [[ "$*" != *remove-orphans* ]] || return 87
        fi
        touch "$scratch/resumed"
      fi
      ;;
    image)
      if [ "$4" = '{{.Id}}' ]; then printf '%s' "$image"; else
        if [ -e "$scratch/resumed" ] && [ "$fixture_mode" = source_drift ]; then printf '%040d' 9; else printf '%s' "$release"; fi
        [ "$fixture_mode" != source_error ] || return 17
        if [ -e "$scratch/resumed" ] && [ "$fixture_mode" = resumed_source_error ]; then return 17; fi
      fi ;;
    ps)
      local role=api cid="$api"
      if [[ "$*" == *service=public-core-processor* ]]; then role=processor; cid="$processor"
      elif [[ "$*" == *service=public-core-gateway* ]]; then role=gateway; cid="$gateway"; fi
      [ "$fixture_mode" != missing_api ] || [ "$role" != api ] || return 0
      if [ -e "$scratch/resumed" ] && [ "$fixture_mode" = "${role}_id_drift" ]; then printf '%064d' 9; else printf '%s' "$cid"; fi
      if [ -e "$scratch/published" ] && [ ! -e "$scratch/resumed" ] && [ "$fixture_mode" = "final_${role}_ps" ]; then return 17; fi
      if [ -e "$scratch/resumed" ] && [ "$fixture_mode" = "resume_${role}_ps" ]; then return 17; fi
      ;;
    inspect)
      local role=api pid=100
      if [ "$4" = "$processor" ]; then role=processor; pid=101
      elif [ "$4" = "$gateway" ]; then role=gateway; pid=102; fi
      case "$3" in
        '{{.State.Pid}}') printf '%s' "$pid" ;;
        '{{.State.Running}}:{{.State.Pid}}:{{.Image}}')
          local running=true current_image="$image"
          if [ -e "$scratch/resumed" ]; then
            [ "$fixture_mode" != "${role}_pid_drift" ] || pid=999
            [ "$fixture_mode" != "${role}_death" ] || running=false
            [ "$fixture_mode" != "${role}_image_drift" ] || current_image=sha256:bad
          fi
          [ "$fixture_mode" != wrong_image ] || current_image=sha256:bad
          printf '%s:%s:%s' "$running" "$pid" "$current_image"
          if [ -e "$scratch/published" ] && [ ! -e "$scratch/resumed" ] && [ "$fixture_mode" = "final_${role}_state" ]; then return 17; fi
          if [ -e "$scratch/resumed" ] && [ "$fixture_mode" = "resume_${role}_state" ]; then return 17; fi
          ;;
        '{{.HostConfig.PidMode}}')
          if [ "$role" = api ] && [ -e "$scratch/resumed" ] && [ "$fixture_mode" = api_namespace_drift ]; then printf host; fi
          if [ "$role" != api ]; then
            if [ "$fixture_mode" = wrong_namespace ] || { [ -e "$scratch/resumed" ] && [ "$fixture_mode" = "${role}_namespace_drift" ]; }; then printf host; else printf 'container:%s' "$api"; fi
          fi
          if [ -e "$scratch/published" ] && [ ! -e "$scratch/resumed" ] && [ "$fixture_mode" = "final_${role}_namespace" ]; then return 17; fi
          if [ -e "$scratch/resumed" ] && [ "$fixture_mode" = "resume_${role}_namespace" ]; then return 17; fi
          ;;
        '{{.State.Restarting}}:{{.RestartCount}}:{{.State.StartedAt}}')
          if [ -e "$scratch/resumed" ] && [ "$fixture_mode" = "${role}_restart" ]; then printf false:1:changed; else printf false:0:unchanged; fi
          if [ -e "$scratch/resumed" ] && [ "$fixture_mode" = "${role}_start_error" ]; then return 17; fi
          ;;
        *) return 86 ;;
      esac ;;
    *) return 87 ;;
  esac
}
# Conditional call intentionally disables inherited errexit: all checks must handle status.
if stage_public_core_approved_runtime "ghcr.io/kamilgaraev/proexpert/prohelper@$image" "$release"; then
  resume_public_core_backend_writers "ghcr.io/kamilgaraev/proexpert/prohelper@$image" 'api queue-worker scheduler'
else exit 1; fi
TUPLE_BASH;
        $directory = getenv('PAPERCLIP_RUN_SCRATCH_DIR').'/tuple-'.bin2hex(random_bytes(6));
        mkdir($directory, 0755);
        $modes = ['inactive', 'normal', 'missing_api', 'wrong_image', 'wrong_namespace', 'observation', 'missing_proof',
            'final_api_state', 'final_api_ps', 'final_processor_state', 'final_gateway_state',
            'final_processor_namespace', 'final_gateway_namespace', 'resume_api_ps',
            'expired_published', 'expired_resumed', 'generation_expired', 'generation_drift', 'lifetime_drift', 'lifetime_error', 'source_error', 'source_drift', 'resumed_source_error', 'generation_error'];
        foreach (['api', 'processor', 'gateway'] as $role) {
            foreach (['pid_drift', 'id_drift', 'death', 'image_drift', 'restart', 'start_error', 'namespace_drift'] as $change) {
                $modes[] = $role.'_'.$change;
            }
            $modes[] = 'final_'.$role.'_ps';
            $modes[] = 'final_'.$role.'_namespace';
            $modes[] = 'resume_'.$role.'_ps';
            $modes[] = 'resume_'.$role.'_state';
            $modes[] = 'resume_'.$role.'_namespace';
        }
        try {
            foreach ($modes as $mode) {
                foreach (['events', 'published', 'resumed'] as $file) { @unlink($directory.'/'.$file); }
                $process = proc_open(['bash', '-c', $harness, 'fixture', dirname(__DIR__, 4).'/deploy/backend-runtime-allowlist.sh', $mode, $directory],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
                self::assertSame('', $err, $mode);
                self::assertSame('', $out, $mode);
                self::assertSame(in_array($mode, ['inactive', 'normal'], true), $exit === 0, $mode);
                if ($mode === 'normal') { self::assertSame('composecomposeobservepublishcompose', file_get_contents($directory.'/events')); }
                if ($mode === 'inactive') { self::assertSame('compose', file_get_contents($directory.'/events')); }
            }
        } finally {
            foreach (['events', 'published', 'resumed'] as $file) { @unlink($directory.'/'.$file); }
            rmdir($directory);
        }
    }

    public function testFullInvalidationRemainsUnavailableWithoutCurrentCandidateIntake(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') { self::markTestSkipped('Scratch-only invalidation regression needs Linux Bash.'); }
        // Reuses AR5-1 reviewer reproduction; no accepted input is invented after revoke.
        $directory = getenv('PAPERCLIP_RUN_SCRATCH_DIR').'/invalidate-'.bin2hex(random_bytes(6));
        mkdir($directory, 0755);
        $allowlist = file_get_contents(dirname(__DIR__, 4).'/deploy/backend-runtime-allowlist.sh');
        file_put_contents($directory.'/allowlist.sh', str_replace('/etc/most/public-core', $directory.'/root', $allowlist));
        foreach (['app', 'processor', 'gateway'] as $role) {
            mkdir($directory.'/root/'.$role, 0755, true);
            file_put_contents($directory.'/root/'.$role.'/generation.json', '{}');
            if ($role !== 'gateway') { file_put_contents($directory.'/root/'.$role.'/bootstrap.php', '<?php return null;'."\n"); }
        }
        file_put_contents($directory.'/root/gateway/runtime.json', '{"activation":"approved"}');
        $harness = <<<'INVALIDATE_BASH'
set -eu
cd "$2"
source "$1/allowlist.sh"
scratch="$1"
stat() { local gid=0; case "${@: -1}" in */app|*/app/*) gid=82 ;; */processor|*/processor/*) gid=41002 ;; */gateway|*/gateway/*) gid=41003 ;; esac; [ -d "${@: -1}" ] && printf '0:%s:750' "$gid" || printf '0:%s:640' "$gid"; }
chown() { :; }
install() { shift 6; cp -- "$1" "$2"; }
quiesce_public_core_gateway_route() { printf quiesce >> "$scratch/events"; }
ip() { return 1; }
nft() {
  if [ "$1" = list ]; then
    [ -e "$scratch/nft_applied" ] || return 1
    printf 'comment "most-public-core:gateway-only/1" br-most-pc'; return 0
  fi
  if [ "$1" = -j ]; then printf '{"nftables":[{"set":{"elem":[]}}]}'; return 0; fi
  printf deny >> "$scratch/events"; touch "$scratch/nft_applied"
}
docker() { if [ "$1" = network ]; then return 0; fi; printf UNEXPECTED_DOCKER_CALL; return 88; }
prepare_public_core_deny_policy
printf drain >> "$scratch/events"
stage_public_core_approved_runtime "ghcr.io/kamilgaraev/proexpert/prohelper@sha256:$(printf '%064d' 4)" "$(printf '%040d' 5)"
test -z "$MOST_PUBLIC_CORE_STAGED_API"
python3 -c 'import json,sys;assert json.load(open(sys.argv[1]))["activation"]=="inactive"' "$scratch/root/gateway/runtime.json"
for role in app processor gateway; do test ! -e "$scratch/root/$role/generation.json"; done
INVALIDATE_BASH;
        try {
            $process = proc_open(['bash', '-c', $harness, 'fixture', $directory, dirname(__DIR__, 4)],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
            self::assertSame('', $out); self::assertSame('', $err); self::assertSame(0, $exit);
            self::assertSame('quiescedenydenydrain', file_get_contents($directory.'/events'));
            // This proves revoke/unavailable, NOT the missing accepted restage→publication path.
        } finally {
            foreach (['app', 'processor', 'gateway'] as $role) {
                foreach (['generation.json', 'bootstrap.php', 'runtime.json'] as $file) { @unlink($directory.'/root/'.$role.'/'.$file); }
                rmdir($directory.'/root/'.$role);
            }
            rmdir($directory.'/root');
            foreach (['allowlist.sh', 'events', 'nft_applied'] as $file) { @unlink($directory.'/'.$file); }
            rmdir($directory);
        }
    }

    public function testInactiveManifestCannotStartGateway(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('runtime_not_activated');
        GatewayRuntimeBootstrap::serve($this->example());
    }

    public function testMetadataInspectionCannotQualifyAnActualRuntime(): void
    {
        $metadata = GatewayRuntimeBootstrap::inspect($this->example());
        self::assertSame('inactive', $metadata['configurationState']);
        self::assertFalse($metadata['actualRuntimeVerified']);
        self::assertFalse($metadata['actualModelVerified']);
        self::assertArrayNotHasKey('credentialFile', $metadata);
        self::assertArrayNotHasKey('profile', $metadata);
    }

    public function testMissingManifestIsUnavailableWithoutCreatingIt(): void
    {
        $path = $this->example().'.not-present';
        self::assertSame('unavailable', GatewayRuntimeBootstrap::inspect($path)['configurationState']);
        self::assertFileDoesNotExist($path);
    }

    public function testInvalidJsonDoesNotReachGateway(): void
    {
        // The suite can exceed the manifest size bound; use a bounded invalid-JSON file.
        $path = tempnam(sys_get_temp_dir(), 'cmp10-invalid-json-');
        file_put_contents($path, 'not-json');
        try { $this->expectException(JsonException::class); GatewayRuntimeBootstrap::serve($path); }
        finally { unlink($path); }
    }

    public function testRoleIdsStayDistinctAndManifestContainsNoActualProofs(): void
    {
        $configuration = json_decode(file_get_contents($this->example()), true, 64, JSON_THROW_ON_ERROR);
        self::assertNotSame(82, $configuration['gatewayUid']);
        self::assertNotSame(82, $configuration['processorPeer']['uid']);
        self::assertNotSame($configuration['gatewayUid'], $configuration['processorPeer']['uid']);
        self::assertSame(GatewayRuntimeBootstrap::GATEWAY_UID, $configuration['gatewayUid']);
        self::assertSame(GatewayRuntimeBootstrap::GATEWAY_GID, $configuration['gatewayGid']);
        self::assertNull($configuration['profile']);
        self::assertNull($configuration['tokenizerSha256']);
        self::assertNull($configuration['tokenizerPatternSha256']);
        self::assertSame([], $configuration['evidence']);
    }

    private function application(): Application
    {
        $app = new Application(dirname(__DIR__, 4));
        $app->instance('config', new Repository());
        // Register the actual provider without booting its unrelated routes/DB observers.
        (new AIAssistantServiceProvider($app))->register();
        return $app;
    }

    public function testProviderHookDoesNotResolveProvidersOrRuntimeDuringBoot(): void
    {
        $app = $this->application();
        foreach ([PublicCoreAssistantRuntime::class,
            \App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface::class,
            \App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface::class] as $class) {
            self::assertFalse($app->resolved($class));
        }
        $app->boot();
        self::assertFalse($app->resolved(PublicCoreAssistantRuntime::class));
        self::assertFalse($app->resolved(\App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface::class));
        self::assertFalse($app->resolved(\App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface::class));
        self::assertNull((new \ReflectionProperty(PublicCoreAssistantRuntime::class, 'nativePortFactory'))->getValue(
            $app->make(PublicCoreAssistantRuntime::class),
        ));
    }

    public function testMissingInactiveAndUntrustedReadersKeepDefaultBinding(): void
    {
        foreach ([AppRuntimeBootstrap::DEFAULT_BOOTSTRAP.'.absent', $this->example(), __FILE__, 'php://memory'] as $path) {
            $app = $this->application();
            self::assertTrue(AppRuntimeBootstrap::register($app, $path));
            $runtime = $app->make(PublicCoreAssistantRuntime::class);
            self::assertNull((new \ReflectionProperty($runtime, 'nativePortFactory'))->getValue($runtime));
        }
    }

    public function testAfterRegistrationBindingKeepsRequestDynamicAndScopesSeparate(): void
    {
        $app = $this->application();
        $nativeCalls = 0;
        $fence = new PublicCoreBackendAuthorityFence(); // Deliberately unavailable, no DB/provider.
        $app->booted(static function (Application $app) use ($fence, &$nativeCalls): void {
            self::assertTrue(AppRuntimeBootstrap::bind($app, $fence, static function (int $expiry) use (&$nativeCalls): null {
                $nativeCalls++;
                return null;
            }));
        });
        $app->boot();
        $first = Request::create('/public-core-one');
        $second = Request::create('/public-core-two');
        $app->instance('request', $first);
        $serviceClass = \App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreRequestService::class;
        $app->instance(\App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class,
            new \App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker());
        $service = $app->make($serviceClass);
        $runtime = $app->make(PublicCoreAssistantRuntime::class);
        self::assertSame($runtime, (new \ReflectionProperty($service, 'runtime'))->getValue($service));
        $origin = (new \ReflectionProperty($runtime, 'originSource'))->getValue($runtime);
        self::assertSame($first, $origin());
        $app->instance('request', $second);
        self::assertSame($second, $origin());
        $runtimeFence = (new \ReflectionProperty($runtime, 'sourceFence'))->getValue($runtime);
        self::assertNotSame($fence, $runtimeFence);
        (new \ReflectionProperty($runtimeFence, 'sourceHeld'))->setValue($runtimeFence, ['held' => true]);
        (new \ReflectionProperty($runtime, 'sourceDeliveries'))->setValue($runtime, ['old' => ['delivered' => true]]);
        (new \ReflectionProperty($runtime, 'sourcePublications'))->setValue($runtime, ['old' => ['pending' => true]]);
        $app->forgetScopedInstances();
        $next = $app->make(PublicCoreAssistantRuntime::class);
        self::assertNotSame($runtime, $next);
        $nextService = $app->make($serviceClass);
        self::assertNotSame($service, $nextService);
        self::assertSame($next, (new \ReflectionProperty($nextService, 'runtime'))->getValue($nextService));
        $nextOrigin = (new \ReflectionProperty($next, 'originSource'))->getValue($next);
        $third = Request::create('/public-core-three');
        $app->instance('request', $third);
        self::assertSame($third, $nextOrigin());
        $nextFence = (new \ReflectionProperty($next, 'sourceFence'))->getValue($next);
        self::assertNotSame($runtimeFence, $nextFence);
        self::assertNull((new \ReflectionProperty($nextFence, 'sourceHeld'))->getValue($nextFence));
        self::assertFalse($nextFence->available());
        self::assertSame([], (new \ReflectionProperty($next, 'sourceDeliveries'))->getValue($next));
        self::assertSame([], (new \ReflectionProperty($next, 'sourcePublications'))->getValue($next));
        // A prior-scope delivery cannot be reused after reset, even with a retained callback.
        self::assertNull($next->sourcePublicationCallback('old', null)([], new \stdClass(), static fn (): array => []));
        self::assertSame(0, $nativeCalls);
    }

    public function testAlreadyResolvedRuntimeCannotBeReplacedByLateBootstrap(): void
    {
        $app = $this->application();
        $runtime = $app->make(PublicCoreAssistantRuntime::class);
        self::assertFalse(AppRuntimeBootstrap::bind($app, new PublicCoreBackendAuthorityFence(), static fn (): null => null));
        self::assertSame($runtime, $app->make(PublicCoreAssistantRuntime::class));
    }

    public function testProtectedExecutableCannotBeLoadedFromUntrustedSource(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('runtime_not_activated');
        ProtectedRoleBootstrap::load(__FILE__);
    }

    public function testIncludingBootstrapDoesNotRunCliOrOpenAChannel(): void
    {
        $path = dirname(__DIR__, 4).'/docker/public-core/runtime.php';
        $script = 'require_once '.var_export($path, true).'; echo "included";';
        $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
        self::assertSame('included', $stdout);
        self::assertSame('', $stderr);
    }

    public function testRootManagedReaderMustConfigureExactlyOnceAndFailClosed(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Protected root-owned file qualification needs an isolated Linux test runtime.');
        }
        $scratch = getenv('PAPERCLIP_RUN_SCRATCH_DIR');
        self::assertIsString($scratch);
        $directory = $scratch.'/protected-reader-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0750));
        $file = $directory.'/bootstrap.php';
        $fenceClass = '\\'.PublicCoreBackendAuthorityFence::class;
        $valid = '<?php return static function ($app, $configure) { $configure(new '.$fenceClass.'(), static fn (int $expiry) => null); };';
        try {
            foreach (['<?php return null;', '<?php return true;', '<?php invalid syntax',
                '<?php return static function ($app, $configure) {};',
                '<?php return static function ($app, $configure) { $configure(new '.$fenceClass.'(), static fn () => null); $configure(new '.$fenceClass.'(), static fn () => null); };'] as $bytes) {
                file_put_contents($file, $bytes);
                chmod($file, 0640);
                $app = $this->application();
                self::assertSame(str_contains($bytes, 'static function'), AppRuntimeBootstrap::register($app, $file));
                $runtime = $app->make(PublicCoreAssistantRuntime::class);
                self::assertNull((new \ReflectionProperty($runtime, 'nativePortFactory'))->getValue($runtime));
            }
            file_put_contents($file, $valid);
            chmod($file, 0640);
            $app = $this->application();
            $app->booted(static function (Application $app) use ($file): void {
                self::assertTrue(AppRuntimeBootstrap::register($app, $file));
            });
            $app->boot();
            self::assertInstanceOf(\Closure::class, (new \ReflectionProperty(PublicCoreAssistantRuntime::class, 'nativePortFactory'))->getValue(
                $app->make(PublicCoreAssistantRuntime::class),
            ));
            chmod($file, 0666);
            self::assertFalse(AppRuntimeBootstrap::register($this->application(), $file));
            chmod($file, 0640);
            chmod($directory, 0777);
            self::assertFalse(AppRuntimeBootstrap::register($this->application(), $file));
            chmod($directory, 0750);
            self::assertTrue(symlink($file, $directory.'/linked.php'));
            self::assertFalse(AppRuntimeBootstrap::register($this->application(), $directory.'/linked.php'));
        } finally {
            @unlink($directory.'/linked.php');
            unlink($file);
            rmdir($directory);
        }
    }
    public function testMissingProjectionCannotQualifyAProfileOrStartBuiltinReaders(): void
    {
        $projection = new \Most\PublicCore\RoleProjection('/absent/public-core/role');
        $this->expectException(LogicException::class);
        $projection->profile();
    }

    public function testSemanticGateSupportsSevenRegisteredSelectorsWithoutTrustingModelText(): void
    {
        $registry = \App\Services\Privacy\PublicCore\RegisteredPublicFixtureRegistry::compiled();
        $gate = \Most\PublicCore\ProcessorRuntimeBootstrap::semanticVerdict(...);
        foreach ($registry->catalog() as $selection) {
            $payload = ['currentRef' => 'current', 'messages' => [
                ['role' => 'user', 'ref' => 'current', 'content' => $selection['display_text'], 'sourceRefs' => ['question-source']],
            ]];
            $value = ['text' => '', 'claims' => [], 'sourceRefs' => ['source'],
                'claimScope' => ['kind' => 'selected_entity', 'unitRefs' => ['transcript']]];
            $evidence = [];
            if ($selection['fixture_id'] === 'public-photo-metadata-v1') {
                $transcript = $registry->records($selection['fixture_id'], $selection['fixture_version'])[0]['text'];
                array_unshift($payload['messages'], ['role' => 'user', 'ref' => 'transcript', 'content' => $transcript, 'sourceRefs' => ['source']]);
                $value['text'] = $selection['input_id'] === 'photo-explain' ? $transcript
                    : 'Второй пункт учебной расшифровки — арматурный каркас. Это текстовая расшифровка, не проверка пикселей изображения.';
            } else {
                $corpus = \App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\SyntheticMaterialSearchCorpus::registered(
                    $selection['fixture_id'], $selection['fixture_version'], $selection['input_id']);
                $rows = $corpus->records();
                $rows = $selection['input_id'] === 'no-results' ? [] : [$rows[$selection['input_id'] === 'cement-price' ? 5 : 0]];
                $result = \App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch\MaterialSearchResult::fromRecords('search', $corpus, $rows, [], $corpus->context());
                $envelope = $result->localEnvelope();
                $scope = ['kind' => 'search_subset', 'scopeRef' => 'alias-scope', 'sourceGenerationRef' => 'alias-generation', 'unitRefs' => ['alias-unit']];
                $value['claimScope'] = $scope;
                $evidence = [['envelope' => $envelope, 'modelMetadata' => ['claimScope' => $scope]]];
                if ($selection['input_id'] === 'no-results') {
                    $value['text'] = 'В учебном каталоге по выбранному запросу ничего не найдено.';
                } else {
                    $record = $rows[0];
                    $quote = $selection['input_id'] === 'quote-12m3';
                    $value['claims'] = [['value' => $quote ? '93600.00' : $record->decimal, 'currency' => 'RUB',
                        'unit' => $quote ? null : $record->priceBasisUnit, 'sourceRefs' => ['source']]];
                    $value['text'] = $quote ? 'Стоимость 12 м³ '.$record->title.' по учебному каталогу: 93600.00 RUB.'
                        : $record->title.' стоит '.$record->decimal.' RUB за '.($record->priceBasisUnit === 'm3' ? 'м³' : 'кг').'.';
                }
            }
            // Exercise the original committed context + response validator, including its
            // alias provenance and derived-currency gate; these remain offline fixtures.
            $context = new \Tests\Unit\AIAssistant\Context\OfflineContextFixtures(0);
            $context->addArtifact('current', 'user', $selection['display_text']);
            if ($selection['fixture_id'] === 'public-photo-metadata-v1') {
                $context->addArtifact('transcript', 'user', $transcript);
                $context->snapshot['conversation']['historyRefs'][] = 'transcript';
            } else {
                $context->addArtifact('tool-result', 'tool', json_encode($envelope, JSON_THROW_ON_ERROR));
                $context->snapshot['conversation']['historyRefs'][] = 'tool-result';
            }
            $prepared = $context->service()->prepare('offline', $context->request());
            self::assertSame('READY', $prepared['status']);
            $receipt = \App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantContextReceipt::consume($prepared,
                ['snapshot' => $context->snapshot, 'profile' => $context->profile, 'lineage' => $context->lineage,
                    'stored' => $context->receipts[$prepared['payload']['contextRef']], 'artifacts' => $context->artifacts], 'offline');
            $checked = $value;
            $results = [];
            $artifact = $selection['fixture_id'] === 'public-photo-metadata-v1' ? 'transcript' : 'tool-result';
            foreach ($receipt->privateBinding()['receipt']['aliases'] as $alias) {
                if ($alias['artifactRef'] === $artifact) { $checked['sourceRefs'] = $alias['sourceRefs']; }
            }
            if ($selection['fixture_id'] === 'public-photo-metadata-v1') {
                $checked['claimScope'] = $receipt->contextScope();
            } else {
                $map = [];
                foreach ($envelope['coverage']['claimScope']['unitRefs'] as $ref) { $map['ref_'.bin2hex(random_bytes(16))] = $ref; }
                $tool = \App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantToolResult::projected($envelope,
                    ['artifactRef' => 'tool-result', 'referenceMap' => $map], [], 'material.search', [], $corpus->context());
                $results = [$tool];
                $checked['claimScope'] = $tool->modelMetadata()['claimScope'];
                foreach ($checked['claims'] as &$claim) { $claim['sourceRefs'] = $checked['sourceRefs']; }
                unset($claim);
            }
            $validator = \App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreContextBindings::processorResponseValidator($gate);
            $checked['type'] = 'final';
            $parse = \App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantModelAction::parse(...);
            self::assertSame('valid', $validator->validate($parse($checked), $receipt, $results, static fn (): null => null)['status'], $selection['input_id']);
            $wrong = $checked;
            $wrong['sourceRefs'] = ['ref_'.str_repeat('f', 32)];
            self::assertSame('provenance_invalid', $validator->validate($parse($wrong), $receipt, $results, static fn (): null => null)['reason']);
            if ($checked['claims'] !== []) {
                $wrong = $checked;
                $wrong['claims'][0]['value'] = '1.00';
                self::assertSame('claims_invalid', $validator->validate($parse($wrong), $receipt, $results, static fn (): null => null)['reason']);
            }
            self::assertSame('valid', $gate($value, $payload, $evidence)['status'], $selection['input_id']);
            self::assertSame('repair', $gate(array_replace($value, ['text' => $value['text'].' Чужой телефон.']), $payload, $evidence)['status']);
            $foreign = $payload;
            $foreign['messages'][array_key_last($foreign['messages'])]['content'] = 'Незарегистрированный вопрос';
            self::assertSame('repair', $gate($value, $foreign, $evidence)['status']);
            if ($selection['fixture_id'] === 'public-photo-metadata-v1') {
                $foreign = $payload;
                $foreign['messages'][0]['sourceRefs'] = ['foreign'];
                self::assertSame('repair', $gate($value, $foreign, $evidence)['status']);
                $foreign = $value;
                $foreign['claimScope']['unitRefs'] = ['current'];
                self::assertSame('repair', $gate($foreign, $payload, $evidence)['status']);
            } else {
                $foreign = $evidence;
                $foreign[0]['envelope']['resultGenerationRef'] = 'foreign';
                self::assertSame('repair', $gate($value, $payload, $foreign)['status']);
                if ($value['claims'] !== []) {
                    $wrong = $value;
                    $wrong['claims'][0]['value'] = '1.00';
                    self::assertSame('repair', $gate($wrong, $payload, $evidence)['status']);
                    $foreign = $evidence;
                    $foreign[0]['envelope']['facts'][0]['provenance']['unitRef'] = 'foreign';
                    self::assertSame('repair', $gate($value, $payload, $foreign)['status']);
                } else {
                    $foreign = $evidence;
                    $foreign[0]['envelope']['coverage']['status'] = 'partial';
                    self::assertSame('repair', $gate($value, $payload, $foreign)['status']);
                }
            }
        }
        self::assertCount(7, $registry->catalog());
    }

    public function testDockerDiscoveryErrorsNeverReachProjectionOrPolicyMutation(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') { self::markTestSkipped('Bash discovery mocks need Linux; no actual Docker or policy calls.'); }
        $directory = getenv('PAPERCLIP_RUN_SCRATCH_DIR').'/discovery-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0755));
        $source = dirname(__DIR__, 4).'/deploy/backend-runtime-allowlist.sh';
        $harness = <<<'BASH'
source "$1"
mode="$2"
work="$3"
entry="$4"
counter() { local n=0; [ ! -f "$work/$1" ] || read -r n < "$work/$1"; n=$((n+1)); printf '%s\n' "$n" > "$work/$1"; printf '%s' "$n"; }
docker() {
  local format="$3" n
  case "$1:$2" in
    ps:*)
      [ "$mode" != ps ] || return 73
      [ "$mode" != empty ] || return 0
      if [[ "$*" == *service=public-core-gateway* ]]; then printf '%s' aaaaaaaaaaaa; else printf '%s' cccccccccccc; fi ;;
    stop:*) return 0 ;;
    network:ls)
      n=$(counter list)
      [ "$mode" != list ] || return 74
      [ "$mode" != prepare-list ] || [ "$n" -ne 2 ] || return 74
      [ "$mode" != empty ] || return 0
      printf '%s' bbbbbbbbbbbb ;;
    network:disconnect) return 0 ;;
    network:inspect)
      format="$4"
      case "$format" in
        *bridge.name*)
          read -r n < "$work/list"
          [ "$mode" != collision ] || [ "$n" -ne 2 ] || return 75
          printf '%s' br-most-pc ;;
        *compose.project*) printf '%s' prohelper ;;
        *compose.network*) printf '%s' public-core-gateway ;;
        *.Containers*)
          n=$(counter members)
          [ "$mode" != initial-member ] || [ "$n" -ne 1 ] || return 76
          [ "$mode" != final-member ] || [ "$n" -ne 2 ] || return 77
          [ "$mode" != prepare-member ] || [ "$n" -ne 3 ] || return 78
          [ "$n" -ne 1 ] || printf '%s' aaaaaaaaaaaa ;;
        *) return 91 ;;
      esac ;;
    inspect:*)
      case "$format" in
        *compose.project*) printf '%s' prohelper; [ "$mode" != label ] || return 79 ;;
        *compose.service*)
          if [ "${@: -1}" = cccccccccccc ]; then printf '%s' public-core-processor; else printf '%s' public-core-gateway; fi
          [ "$mode" != service-label ] || return 82 ;;
        '{{.State.Pid}}') printf '%s' 0; [ "$mode" != pid ] || return 83 ;;
        *NetworkMode*) printf '%s' none; [ "$mode" != network-mode ] || return 84 ;;
        *State.Running*) printf '%s' false:0:no; [ "$mode" != state ] || return 80 ;;
        *EndpointID*) [ "$mode" != endpoint ] || return 81 ;;
        *) return 91 ;;
      esac ;;
    *) return 91 ;;
  esac
}
ip() { return 1; }
nft() { [ "$1" = list ] || { printf '%s' POLICY_MUTATION > "$work/policy-mutation"; return 93; }; printf '%s' 'comment "most-public-core:gateway-only/1"'; }
invalidate_public_core_projections() { printf '%s' MUTATION; return 92; }
if "$entry"; then printf '%s' ACCEPTED; else exit "$?"; fi
BASH;
        try {
            foreach (['ps', 'list', 'initial-member', 'final-member', 'endpoint', 'label', 'service-label', 'pid', 'network-mode', 'state', 'prepare-list', 'collision', 'prepare-member', 'empty', 'normal'] as $mode) {
                foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); }
                $process = proc_open(['bash', '-c', $harness, 'fixture', $source, $mode, $directory, 'prepare_public_core_deny_policy'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]); $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
                $err = stream_get_contents($pipes[2]); fclose($pipes[2]); $exit = proc_close($process);
                if (in_array($mode, ['empty', 'normal'], true)) {
                    self::assertSame(1, $exit, $mode.': '.$err);
                    self::assertSame('MUTATION', $out, $mode);
                } else {
                    self::assertSame(1, $exit, $mode.': '.$err);
                    self::assertFileDoesNotExist($directory.'/policy-mutation');
                    self::assertSame('', $out, $mode); // Includes no projection invalidation and no nft refresh.
                }
            }
            foreach (['empty', 'normal'] as $mode) {
                foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); }
                $process = proc_open(['bash', '-c', $harness, 'fixture', $source, $mode, $directory, 'quiesce_public_core_gateway_route'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process); fclose($pipes[0]);
                $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
                $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $err);
                self::assertSame('ACCEPTED', $out);
            }
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); }
            rmdir($directory);
        }
    }

    public function testRootCustodyParserCreatesOnlyRoleFilesAndRefusesRotationOrAmbiguity(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Needs isolated root-owned Linux fixture files, not production credentials.');
        }
        $directory = getenv('PAPERCLIP_RUN_SCRATCH_DIR').'/custody-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0755));
        foreach (['app' => 82, 'processor' => 41002, 'gateway' => 41003, 'gateway/credential' => 41003] as $role => $gid) {
            self::assertTrue(mkdir($directory.'/'.$role, 0750));
            self::assertTrue(chgrp($directory.'/'.$role, $gid));
        }
        $file = $directory.'/environment';
        $write = static function (string $bytes) use ($file): void { file_put_contents($file, $bytes); chmod($file, 0600); };
        $provision = \Most\PublicCore\RoleCredentialProvisioner::provision(...);
        try {
            $write("OTHER_KEY=unrelated\n");
            self::assertSame(['providerCredential' => false, 'controlKeys' => false], $provision($file, $directory));
            self::assertFileDoesNotExist($directory.'/app/control-key');
            $write("APP_KEY='".str_repeat('fixture-key-', 4)."'\nTIMEWEB_AI_API_KEY=fixture-provider-value\nTIMEWEB_API_KEY=lower-priority-fixture\n");
            self::assertSame(['providerCredential' => true, 'controlKeys' => true], $provision($file, $directory));
            self::assertSame('fixture-provider-value', file_get_contents($directory.'/gateway/credential/provider-key'));
            self::assertFileDoesNotExist($directory.'/app/provider-key');
            self::assertFileDoesNotExist($directory.'/processor/provider-key');
            self::assertNotSame(file_get_contents($directory.'/app/control-key'), file_get_contents($directory.'/processor/control-key'));
            foreach (['app/control-key' => 82, 'processor/control-key' => 41002, 'gateway/credential/provider-key' => 41003] as $name => $gid) {
                clearstatcache(true, $directory.'/'.$name);
                self::assertSame($gid, filegroup($directory.'/'.$name));
                self::assertSame(0640, fileperms($directory.'/'.$name) & 0777);
            }
            $before = hash_file('sha256', $directory.'/gateway/credential/provider-key');
            foreach (["TIMEWEB_AI_API_KEY=fixture-rotation\n", "TIMEWEB_AI_API_KEY=a\nTIMEWEB_AI_API_KEY=b\n",
                'TIMEWEB_AI_API_KEY=${OTHER_KEY}', "APP_KEY=short\n", 'TIMEWEB_AI_API_KEY="unterminated'] as $invalid) {
                $write($invalid);
                try { $provision($file, $directory); self::fail('Invalid custody input accepted'); }
                catch (\Throwable $failure) { self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $failure); }
                self::assertSame($before, hash_file('sha256', $directory.'/gateway/credential/provider-key'));
            }
            $write("TIMEWEB_AI_API_KEY=fixture-provider-value\n"); chmod($file, 0644);
            try { $provision($file, $directory); self::fail('World-readable environment accepted'); }
            catch (LogicException) { self::assertSame($before, hash_file('sha256', $directory.'/gateway/credential/provider-key')); }
        } finally {
            foreach (['app/control-key', 'processor/control-key', 'gateway/credential/provider-key', 'environment'] as $name) { @unlink($directory.'/'.$name); }
            foreach (['gateway/credential', 'gateway', 'processor', 'app'] as $role) { rmdir($directory.'/'.$role); }
            rmdir($directory);
        }
    }

    public function testProtectedProjectionPinsAnInodeAndBytesAndRejectsReplacements(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Needs isolated Linux protected file metadata.');
        }
        $directory = getenv('PAPERCLIP_RUN_SCRATCH_DIR').'/projection-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0750));
        $file = $directory.'/profile.json';
        file_put_contents($file, '{"safe":true}'); chmod($file, 0640);
        try {
            $reader = new \Most\PublicCore\ProtectedRoleFile($directory);
            self::assertSame(['safe' => true], $reader->json('profile.json'));
            file_put_contents($directory.'/replacement', '{"safe":true}'); chmod($directory.'/replacement', 0640);
            rename($directory.'/replacement', $file);
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('profile_changed');
            $reader->json('profile.json');
        } finally { unlink($file); rmdir($directory); }
    }

    public function testProcessLifetimeUsesCurrentKernelIdentityAndRejectsGuessedRole(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid')) {
            self::markTestSkipped('Linux proc identity metadata required.');
        }
        $peer = ['pid' => (int)getmypid(), 'uid' => posix_geteuid(), 'gid' => posix_getegid()];
        $reference = \Most\PublicCore\ProcessIdentity::lifetime($peer);
        self::assertMatchesRegularExpression('/\Aref_[0-9a-f]{32}\z/D', $reference);
        self::assertSame($reference, \Most\PublicCore\ProcessIdentity::lifetime($peer));
        $this->expectException(LogicException::class);
        \Most\PublicCore\ProcessIdentity::lifetime(array_replace($peer, ['uid' => $peer['uid'] + 1]));
    }

    /** Synthetic accepted shape only; never actual account, model, tokenizer or measurement evidence. */
    private function currentFixture(): array
    {
        $runtime = json_decode(file_get_contents($this->example()), true, 64, JSON_THROW_ON_ERROR);
        $profile = ['profileRef' => 'profile:synthetic-current', 'qualification' => 'actual',
            'adapterRevision' => \App\Services\Privacy\Gateway\Contracts\GatewayModelProfile::ADAPTER_REVISION,
            'apiMethod' => 'responses', 'endpoint' => 'https://api.timeweb.ai/v1/responses',
            'modelId' => 'openai/gpt-6-luna', 'modelRevision' => 'synthetic-current-v1', 'tokenizerId' => 'synthetic-bpe', 'tokenizerRevision' => 'synthetic-v1',
            'mappingEvidenceRef' => 'evidence:synthetic-tokenizer', 'capabilityEvidenceRef' => 'evidence:synthetic-method', 'capacityEvidenceRef' => 'evidence:synthetic-capacity',
            'contextWindow' => 4096, 'maxOutputTokens' => 512, 'answerReserve' => 768, 'toolReserve' => 128];
        $runtime['activation'] = 'approved'; $runtime['profile'] = $profile;
        $runtime['tokenizerSha256'] = str_repeat('c', 64); $runtime['tokenizerPatternSha256'] = str_repeat('d', 64); $runtime['tokenizerVocabulary'] = $profile['tokenizerId'];
        foreach (['catalog', 'method', 'capacity', 'tokenizer', 'key', 'identity', 'channel', 'egress', 'backendAuthority', 'nativeTransfer'] as $kind) {
            $runtime['evidence'][$kind] = ['ref' => 'evidence:synthetic-'.$kind, 'file' => $kind.'.json', 'sha256' => str_repeat('e', 64)];
        }
        $descriptor = ['schemaVersion' => 'public-core-accepted-candidate/1', 'revision' => 7, 'status' => 'current',
            'acceptance' => ['decisionRef' => 'decision:synthetic-only', 'documentRevisionId' => 'revision:synthetic-only', 'artifactSha256' => str_repeat('f', 64), 'custodianChannel' => 'prod-backend-deploy'],
            'issuedAt' => 999, 'expiresAt' => 1060, 'revokedAt' => null, 'releaseSha' => str_repeat('a', 40), 'imageDigest' => 'sha256:'.str_repeat('b', 64),
            'runtime' => ['file' => 'candidate-runtime.json', 'sha256' => hash('sha256', json_encode($runtime, JSON_THROW_ON_ERROR))],
            'profileFingerprint' => \App\Services\Privacy\Gateway\Contracts\GatewayModelProfile::fromArray($profile)->fingerprint(),
            'modelBinding' => ['provider' => 'timeweb', 'modelId' => $profile['modelId'], 'modelRevision' => $profile['modelRevision'], 'catalogDigest' => str_repeat('a', 64),
                'apiMethod' => 'responses', 'templateVersion' => $profile['adapterRevision'], 'contextWindow' => 4096, 'maxOutputTokens' => 512,
                'tokenizerId' => $profile['tokenizerId'], 'tokenizerRevision' => $profile['tokenizerRevision'], 'countMethod' => 'full_wire_json_bpe_upper_bound',
                'vocabularySha256' => $runtime['tokenizerSha256'], 'patternSha256' => $runtime['tokenizerPatternSha256']],
            'evidence' => $runtime['evidence'], 'tokenizer' => ['vocabulary' => ['file' => 'vocabulary.tiktoken', 'sha256' => $runtime['tokenizerSha256']],
                'pattern' => ['file' => 'pattern.txt', 'sha256' => $runtime['tokenizerPatternSha256']]]];
        return [$descriptor, $runtime];
    }

    public function testCurrentAdmissionRequiresExternalDigestRevisionAndClosedNativeBinding(): void
    {
        [$d, $r] = $this->currentFixture(); $json = json_encode(...);
        $admit = \Most\PublicCore\CurrentCandidateSnapshot::admit(...); $bytes = $json($d, JSON_THROW_ON_ERROR);
        self::assertSame($d, $admit($bytes, $json($r, JSON_THROW_ON_ERROR), $d['releaseSha'], $d['imageDigest'], 7, hash('sha256', $bytes), 1000, '/etc/most/public-core'));
        // An arbitrary verified/accepted envelope cannot authorize itself: caller hash/revision must match.
        foreach ([['digest', str_repeat('0', 64)], ['revision', 6], ['source', str_repeat('0', 40)], ['image', 'sha256:'.str_repeat('0', 64)], ['time', 1060]] as [$kind, $value]) {
            try {
                $admit($bytes, $json($r, JSON_THROW_ON_ERROR), $kind === 'source' ? $value : $d['releaseSha'], $kind === 'image' ? $value : $d['imageDigest'],
                    $kind === 'revision' ? $value : 7, $kind === 'digest' ? $value : hash('sha256', $bytes), $kind === 'time' ? $value : 1000, '/etc/most/public-core');
                self::fail('External acceptance pin bypassed: '.$kind);
            } catch (LogicException $error) { self::assertSame('candidate_unavailable', $error->getMessage()); }
        }
        $changes = [
            ['status', 'revoked'], ['status', 'verified'], ['revokedAt', 999], ['issuedAt', 1001], ['issuedAt', 0], ['expiresAt', 1000], ['revision', '7'],
            ['acceptance.custodianChannel', 'untrusted'], ['acceptance.decisionRef', 'https://private.invalid'], ['acceptance.artifactSha256', 'invalid'],
            ['acceptance.extra', true], ['runtime.file', '../runtime.json'], ['runtime.sha256', str_repeat('0', 64)], ['profileFingerprint', str_repeat('0', 64)],
            ['modelBinding.templateVersion', 'chat-completions-action/1'], ['modelBinding.templateVersion', 'responses-public-core/1'],
            ['modelBinding.apiMethod', 'chat_completions'], ['modelBinding.modelId', 'other/model'], ['modelBinding.provider', 'openai'],
            ['modelBinding.modelRevision', 'old'], ['modelBinding.catalogDigest', 'invented'], ['modelBinding.contextWindow', 8192],
            ['modelBinding.tokenizerRevision', 'borrowed'], ['modelBinding.countMethod', 'text_only'], ['modelBinding.vocabularySha256', str_repeat('0', 64)],
            ['tokenizer.vocabulary.file', '../vocabulary.tiktoken'], ['tokenizer.pattern.file', 'other.txt'], ['evidence.method.file', '../method.json'],
            ['evidence.method.sha256', 'invented'], ['extra', true],
        ];
        foreach ($changes as [$path, $value]) {
            $bad = $d; $target = &$bad; $parts = explode('.', $path); $last = array_pop($parts);
            foreach ($parts as $part) { $target = &$target[$part]; } $target[$last] = $value; unset($target);
            $badBytes = $json($bad, JSON_THROW_ON_ERROR);
            try { $admit($badBytes, $json($r, JSON_THROW_ON_ERROR), $d['releaseSha'], $d['imageDigest'], 7, hash('sha256', $badBytes), 1000, '/etc/most/public-core'); self::fail('Malformed CURRENT admitted: '.$path); }
            catch (LogicException $error) { self::assertContains($error->getMessage(), ['candidate_unavailable', 'invalid_model_output', 'model_profile_unqualified'], $path); }
        }
        foreach ([substr($bytes, 0, -1).',"revision":7}', str_repeat(' ', 65537).$bytes, '{}', '[]'] as $badBytes) {
            try { $admit($badBytes, $json($r, JSON_THROW_ON_ERROR), $d['releaseSha'], $d['imageDigest'], 7, hash('sha256', $badBytes), 1000, '/etc/most/public-core'); self::fail('Duplicate/oversized/partial CURRENT admitted'); }
            catch (LogicException $error) { self::assertContains($error->getMessage(), ['candidate_unavailable', 'invalid_model_output', 'model_profile_unqualified']); }
        }
    }

    public function testCurrentAdmissionRejectsOldPidPrivatePathsAndChangedManifestBytes(): void
    {
        [$d, $r] = $this->currentFixture();
        foreach ([['processorPeer', ['uid' => 41002, 'gid' => 41002, 'pid' => 77]], ['credentialFile', '/etc/private/key'],
            ['socketPath', '/other'], ['tokenizerFile', '/tmp/borrowed-bpe'], ['tokenizerPattern', '/other'], ['evidenceDirectory', '/other'],
            ['activation', 'inactive'], ['store', true], ['gatewayUid', 82], ['tokenizerVocabulary', 'borrowed']] as [$key, $bad]) {
            $runtime = array_replace($r, [$key => $bad]); $candidate = $d; $runtimeBytes = json_encode($runtime, JSON_THROW_ON_ERROR);
            $candidate['runtime']['sha256'] = hash('sha256', $runtimeBytes); $bytes = json_encode($candidate, JSON_THROW_ON_ERROR);
            try { \Most\PublicCore\CurrentCandidateSnapshot::admit($bytes, $runtimeBytes, $d['releaseSha'], $d['imageDigest'], 7, hash('sha256', $bytes), 1000, '/etc/most/public-core'); self::fail('Unsafe runtime staged: '.$key); }
            catch (LogicException $error) { self::assertContains($error->getMessage(), ['candidate_unavailable', 'invalid_model_output', 'model_profile_unqualified']); }
        }
        $this->expectException(LogicException::class);
        $bytes = json_encode($d, JSON_THROW_ON_ERROR);
        \Most\PublicCore\CurrentCandidateSnapshot::admit($bytes, json_encode($r, JSON_THROW_ON_ERROR)."\n", $d['releaseSha'], $d['imageDigest'], 7, hash('sha256', $bytes), 1000, '/etc/most/public-core');
    }

    public function testCurrentImageGateRejectsNonzeroStatusBeforeAnyStoreWrite(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') { self::markTestSkipped('Offline Bash image/status mocks require Linux; no image operations.'); }
        $harness = <<<'CURRENT_IMAGE_BASH'
source "$1"
mode="$2"; release=$(printf '%040d' 5); image="ghcr.io/kamilgaraev/proexpert/prohelper@sha256:$(printf '%064d' 4)"
PUBLIC_CORE_HELPER_SHA256=$(printf '%064d' 6); PUBLIC_CORE_RUNTIME_SHA256=$(printf '%064d' 7)
timeout() { shift 3; "$@"; }
docker() {
  local kind
  case "$1" in
    image) kind=source; printf '%s' "$release" ;;
    run)
      case "${@: -1}" in
        *release.json*) kind=embedded; printf '%s' "$release" ;;
        *backend-runtime-allowlist.sh*) kind=helper; printf '%s' "$PUBLIC_CORE_HELPER_SHA256" ;;
        *runtime.php*) kind=runtime; printf '%s' "$PUBLIC_CORE_RUNTIME_SHA256" ;;
        *) return 88 ;;
      esac ;;
    *) return 89 ;;
  esac
  [ "$mode" != "${kind}_status" ] || return 17
  [ "$mode" != "${kind}_mismatch" ] || printf extra
}
# Conditional invocation intentionally removes implicit errexit protection.
if verify_public_core_candidate_image "$image" "$release"; then exit 0; else exit 1; fi
CURRENT_IMAGE_BASH;
        foreach (['normal', 'source_status', 'embedded_status', 'helper_status', 'runtime_status', 'source_mismatch', 'embedded_mismatch', 'helper_mismatch', 'runtime_mismatch'] as $mode) {
            $process = proc_open(['bash', '-c', $harness, 'fixture', dirname(__DIR__, 4).'/deploy/backend-runtime-allowlist.sh', $mode],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
            self::assertSame('', $out); self::assertSame('', $err); self::assertSame($mode === 'normal', $exit === 0, $mode);
        }
    }

    public function testPublishedWrappersAreExactSourceBoundAndDoNotContainAcquisitionOrCredentials(): void
    {
        $publisher = \Most\PublicCore\RoleProjectionPublisher::class;
        foreach (['app', 'processor'] as $role) {
            $bytes = $publisher::bootstrapBytes($role);
            self::assertStringContainsString(hash_file('sha256', dirname(__DIR__, 4).'/docker/public-core/runtime.php'), $bytes);
            self::assertStringContainsString("require_once '/var/www/html/docker/public-core/runtime.php'", $bytes);
            self::assertStringNotContainsString('provider-key', $bytes); self::assertStringNotContainsString('accepted-candidate', $bytes);
            self::assertStringContainsString($role === 'app' ? 'configureProtected' : 'protectedListener', $bytes);
        }
        $this->expectException(LogicException::class); $publisher::bootstrapBytes('other');
    }

    /** Synthetic schema fixture only: these bytes are never actual profile/runtime evidence. */
    private function projectionFixture(): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Root-owned isolated Linux schema fixtures required.');
        }
        $root = getenv('PAPERCLIP_RUN_SCRATCH_DIR').'/compiler-'.bin2hex(random_bytes(6));
        mkdir($root, 0755);
        foreach (['gateway', 'gateway/evidence', 'gateway/tokenizer', 'gateway/credential', 'app', 'processor', 'control'] as $role) {
            mkdir($root.'/'.$role, 0750); chgrp($root.'/'.$role, match ($role) { 'app' => 82, 'processor' => 41002, 'control' => 0, default => 41003 });
        }
        $write = static function (string $path, string $bytes): void { file_put_contents($path, $bytes); chgrp($path, 41003); chmod($path, 0640); };
        $configuration = json_decode(file_get_contents($this->example()), true, 64, JSON_THROW_ON_ERROR);
        $configuration['activation'] = 'approved'; $configuration['processorPeer']['pid'] = 77;
        $configuration['evidenceDirectory'] = $root.'/gateway/evidence';
        $configuration['credentialFile'] = $root.'/gateway/credential/provider-key';
        $configuration['tokenizerFile'] = $root.'/gateway/tokenizer/vocabulary.tiktoken';
        $configuration['tokenizerPattern'] = $root.'/gateway/tokenizer/pattern.txt';
        $configuration['tokenizerSha256'] = hash('sha256', "YQ== 0\n");
        $configuration['tokenizerPatternSha256'] = hash('sha256', '/./');
        $configuration['tokenizerVocabulary'] = 'fixture-bpe';
        $profile = ['profileRef' => 'profile:synthetic-compiler-only', 'qualification' => 'actual', 'adapterRevision' => \App\Services\Privacy\Gateway\Contracts\GatewayModelProfile::ADAPTER_REVISION,
            'apiMethod' => 'responses', 'endpoint' => 'https://api.timeweb.ai/v1/responses',
            'modelId' => 'fixture/model', 'modelRevision' => 'fixture-v1', 'tokenizerId' => 'fixture-bpe', 'tokenizerRevision' => 'fixture-v1',
            'mappingEvidenceRef' => 'evidence:fixture-tokenizer', 'capabilityEvidenceRef' => 'evidence:fixture-method',
            'capacityEvidenceRef' => 'evidence:fixture-capacity', 'contextWindow' => 4096, 'maxOutputTokens' => 512, 'answerReserve' => 768, 'toolReserve' => 128];
        $configuration['profile'] = $profile;
        $fingerprint = \App\Services\Privacy\Gateway\Contracts\GatewayModelProfile::fromArray($profile)->fingerprint();
        $source = dirname(__DIR__, 4).'/app/Services/Privacy/';
        $details = ['catalog' => ['modelRevision' => 'fixture-v1', 'catalogDigest' => str_repeat('a', 64)],
            'method' => ['endpoint' => $profile['endpoint'], 'templateVersion' => \App\Services\Privacy\Gateway\Contracts\GatewayModelProfile::ADAPTER_REVISION],
            'capacity' => ['contextWindow' => 4096, 'maxOutputTokens' => 512],
            'tokenizer' => ['tokenizerId' => 'fixture-bpe', 'tokenizerRevision' => 'fixture-v1', 'modelRevision' => 'fixture-v1',
                'countMethod' => 'full_wire_json_bpe_upper_bound', 'vocabularySha256' => $configuration['tokenizerSha256'], 'patternSha256' => $configuration['tokenizerPatternSha256']],
            'key' => ['credentialFile' => $configuration['credentialFile']],
            'identity' => ['gatewayUid' => 41003, 'gatewayGid' => 41003, 'processorUid' => 41002, 'processorGid' => 41002, 'appUid' => 82],
            'channel' => ['protocol' => \App\Services\Privacy\PublicCore\Transport\AuthenticatedPublicCoreChannel::SCHEMA_VERSION, 'socketPath' => $configuration['socketPath']],
            'egress' => ['allowedEndpoint' => $profile['endpoint'], 'policyDigest' => str_repeat('b', 64)],
            'backendAuthority' => ['coverageDigest' => str_repeat('c', 64), 'strategyVersion' => 'strategy:fixture-only', 'releasePhase' => 'guarded_upload'],
            'nativeTransfer' => ['strategy' => 'curl_multi_watchdog/1', 'phpVersionId' => PHP_VERSION_ID, 'curlVersionNumber' => curl_version()['version_number'],
                'uploadEvent' => 'same_handle_xferinfo_complete', 'cancellation' => 'verified_remove_destroy_no_reuse', 'uploadMaxMs' => 2000,
                'senderSourceSha256' => hash_file('sha256', $source.'Gateway/GatewayPublicCoreHttpSender.php'),
                'channelSourceSha256' => hash_file('sha256', $source.'PublicCore/Transport/AuthenticatedPublicCoreChannel.php'),
                'gatewaySourceSha256' => hash_file('sha256', $source.'Gateway/GatewayPublicCoreTransport.php')]];
        foreach ($details as $kind => $value) {
            $proof = ['schemaVersion' => 'public-core-runtime-evidence/1', 'kind' => $kind, 'ref' => 'evidence:fixture-'.$kind,
                'status' => 'verified', 'profileFingerprint' => $fingerprint, 'modelId' => 'fixture/model', 'apiMethod' => 'responses',
                'issuedAt' => time() - 1, 'expiresAt' => time() + 60, 'details' => $value];
            $bytes = json_encode($proof, JSON_THROW_ON_ERROR);
            $write($configuration['evidenceDirectory'].'/'.$kind.'.json', $bytes);
            $configuration['evidence'][$kind] = ['ref' => $proof['ref'], 'file' => $kind.'.json', 'sha256' => hash('sha256', $bytes)];
        }
        $write($configuration['credentialFile'], 'offline-fixture-only');
        $write($configuration['tokenizerFile'], "YQ== 0\n"); $write($configuration['tokenizerPattern'], '/./');
        $write($root.'/gateway/runtime.json', json_encode($configuration, JSON_THROW_ON_ERROR));
        return [$root, $configuration, $write];
    }

    private function removeCompilerFixture(string $root): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($root);
    }

    public function testCompilerParityRejectsChangedEvidenceAndOriginalGatewayGuards(): void
    {
        [$root, $configuration, $write] = $this->projectionFixture();
        try {
            $snapshot = new \Most\PublicCore\GatewayProjectionSnapshot($root.'/gateway');
            self::assertSame($configuration, $snapshot->configuration($root.'/gateway/runtime.json'));
            $files = $snapshot->roleFiles($configuration);
            self::assertCount(13, $files); self::assertArrayNotHasKey('provider-key', $files);
            foreach ([['deadlineMs', 11999], ['maxRequests', 129], ['gatewayUid', 82], ['gatewayGid', 82],
                ['activation', 'inactive'], ['extra', true], ['tokenizerVocabulary', 'bad whitespace']] as [$key, $bad]) {
                $write($root.'/gateway/runtime.json', json_encode(array_replace($configuration, [$key => $bad]), JSON_THROW_ON_ERROR));
                try { (new \Most\PublicCore\GatewayProjectionSnapshot($root.'/gateway'))->configuration($root.'/gateway/runtime.json'); self::fail('Gateway guard bypassed'); }
                catch (LogicException $failure) { self::assertContains($failure->getMessage(), ['runtime_not_activated', 'model_profile_unqualified', 'tokenizer_unqualified']); }
            }
            $write($root.'/gateway/runtime.json', json_encode($configuration, JSON_THROW_ON_ERROR));
            foreach (['catalog' => ['catalogDigest', 'invalid'], 'method' => ['templateVersion', 'other'], 'tokenizer' => ['tokenizerRevision', 'other'],
                'key' => ['credentialFile', '/other'], 'identity' => ['appUid', 41003], 'channel' => ['socketPath', '/other'],
                'egress' => ['allowedEndpoint', 'https://other.invalid'], 'backendAuthority' => ['releasePhase', 'other'],
                'nativeTransfer' => ['gatewaySourceSha256', str_repeat('f', 64)]] as $kind => [$field, $bad]) {
                $file = $root.'/gateway/evidence/'.$kind.'.json'; $original = file_get_contents($file);
                $proof = json_decode($original, true, 64, JSON_THROW_ON_ERROR); $proof['details'][$field] = $bad;
                $bytes = json_encode($proof, JSON_THROW_ON_ERROR); $write($file, $bytes);
                $changed = $configuration; $changed['evidence'][$kind]['sha256'] = hash('sha256', $bytes);
                $write($root.'/gateway/runtime.json', json_encode($changed, JSON_THROW_ON_ERROR));
                try { (new \Most\PublicCore\GatewayProjectionSnapshot($root.'/gateway'))->configuration($root.'/gateway/runtime.json'); self::fail('Evidence detail guard bypassed'); }
                catch (LogicException $failure) { self::assertContains($failure->getMessage(), ['runtime_not_activated', 'model_profile_unqualified', 'tokenizer_unqualified']); }
                $write($file, $original);
            }
            $write($root.'/gateway/runtime.json', json_encode($configuration, JSON_THROW_ON_ERROR));
            $fresh = new \Most\PublicCore\GatewayProjectionSnapshot($root.'/gateway'); $fresh->configuration($root.'/gateway/runtime.json');
            $write($configuration['tokenizerFile'], "Yg== 0\n");
            try { $fresh->roleFiles($configuration); self::fail('Changed BPE accepted'); }
            catch (LogicException $failure) { self::assertContains($failure->getMessage(), ['runtime_not_activated', 'model_profile_unqualified', 'tokenizer_unqualified']); }
        } finally { $this->removeCompilerFixture($root); }
    }

    public function testPublisherCannotCreateQualificationFromInactiveOrMissingAcceptedInputs(): void
    {
        [$root, $configuration, $write] = $this->projectionFixture();
        $publish = \Most\PublicCore\RoleProjectionPublisher::publish(...);
        try {
            $inactive = $configuration; $inactive['activation'] = 'inactive';
            $write($root.'/gateway/runtime.json', json_encode($inactive, JSON_THROW_ON_ERROR));
            try { $publish($root, str_repeat('a', 40), 'sha256:'.str_repeat('b', 64)); self::fail('Required CURRENT cannot be inactive no-op'); }
            catch (LogicException $error) { self::assertContains($error->getMessage(), ['candidate_unavailable', 'invalid_model_output', 'model_profile_unqualified']); }
            self::assertSame([], scandir($root.'/app') === ['.', '..'] ? [] : ['unexpected output']);
            $write($root.'/gateway/runtime.json', json_encode($configuration, JSON_THROW_ON_ERROR));
            try { $publish($root, str_repeat('a', 40), 'sha256:'.str_repeat('b', 64)); self::fail('Missing checked inputs fabricated'); }
            catch (LogicException $failure) { self::assertContains($failure->getMessage(), ['candidate_unavailable', 'runtime_not_activated', 'model_profile_unqualified', 'tokenizer_unqualified']); }
            foreach (['app', 'processor', 'gateway'] as $role) { self::assertFileDoesNotExist($root.'/'.$role.'/generation.json'); }
            self::assertFileDoesNotExist($root.'/app/profile.json'); self::assertFileDoesNotExist($root.'/processor/qualification.json');
            self::assertSame('inactive', json_decode(file_get_contents($root.'/gateway/runtime.json'), true, 64, JSON_THROW_ON_ERROR)['activation']);
            self::assertSame('offline-fixture-only', file_get_contents($configuration['credentialFile']));
        } finally { $this->removeCompilerFixture($root); }
    }

    public function testParkedStartupRejectsWrongRoleBeforeWaitingOrReadingCredentials(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) { self::markTestSkipped('Isolated root Linux required.'); }
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('gateway_identity_unavailable');
        \Most\PublicCore\ParkedRoleBootstrap::wait('gateway', 1);
    }

}
