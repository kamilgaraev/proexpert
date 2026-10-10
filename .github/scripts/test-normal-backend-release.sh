#!/usr/bin/env bash
# Credential-free source regression. Requires Python 3 and PyYAML; never calls Docker.
set -euo pipefail
root="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
python3 - "$root" <<'PY'
import json, os, pathlib, subprocess, sys, tempfile
import yaml
root = pathlib.Path(sys.argv[1])
class UniqueLoader(yaml.BaseLoader):
    pass
def mapping(loader, node):
    out = {}
    for k, v in node.value:
        key = loader.construct_object(k)
        if key in out:
            raise ValueError('duplicate YAML key: ' + key)
        out[key] = loader.construct_object(v)
    return out
UniqueLoader.add_constructor(yaml.resolver.BaseResolver.DEFAULT_MAPPING_TAG, mapping)
workflow = yaml.load((root / '.github/workflows/deploy-backend.yml').read_text(), Loader=UniqueLoader)
compose = yaml.load((root / 'docker-compose.yml').read_text(), Loader=UniqueLoader)
helper = root / 'deploy/backend-runtime-allowlist.sh'
count = 0
def check(value, label):
    global count
    assert value, label
    count += 1

def bash(script, env=None):
    # No inherited credentials, provider settings or private application environment.
    return subprocess.run(['bash', '-c', script], env={'PATH': '/usr/bin:/bin', **(env or {})},
                          text=True, capture_output=True, timeout=10)

steps = workflow['jobs']['deploy']['steps']
guard = steps[0]['run']
sha = 'a' * 40
valid = dict(GITHUB_REF='refs/heads/main', GITHUB_EVENT_NAME='workflow_dispatch',
             REQUESTED_MODE='release', EXPECTED_SOURCE_SHA=sha, GITHUB_SHA=sha)
check(bash(guard, valid).returncode == 0, 'release without any PublicCore vars')
for key, values in {
    'GITHUB_REF': ['refs/heads/task/test', 'refs/heads/MAIN', ''],
    'GITHUB_EVENT_NAME': ['push', 'pull_request', ''],
    'REQUESTED_MODE': ['RELEASE', 'qualification-only', 'input-only', 'input-prepare', 'namespace-observe', ''],
    'EXPECTED_SOURCE_SHA': ['', 'b' * 40, 'A' * 40, sha + '\n', 'a' * 39],
}.items():
    for value in values:
        check(bash(guard, {**valid, key: value}).returncode != 0, key + ' denial ' + repr(value))
selector = workflow['jobs']['release_mode']['steps'][0]['run']
with tempfile.TemporaryDirectory(prefix='normal-release-', dir=os.environ.get('PAPERCLIP_SCRATCH_DIR') or os.environ.get('TMPDIR')) as temporary:
    output = pathlib.Path(temporary) / 'outputs'
    for event, mode, expected in [('workflow_dispatch', 'release', 'true'), ('push', 'release', 'false'), ('workflow_dispatch', 'RELEASE', 'false'), ('workflow_dispatch', 'input-prepare', 'false')]:
        output.write_text('')
        result = bash(selector, {**valid, 'GITHUB_EVENT_NAME': event, 'REQUESTED_MODE': mode, 'GITHUB_OUTPUT': str(output)})
        check(result.returncode == 0 and ('allowed=' + expected + '\n') in output.read_text(), 'selector ' + event + '/' + mode)

for name, job in workflow['jobs'].items():
    for step in job.get('steps', []):
        body = step.get('run') or step.get('with', {}).get('script')
        if body:
            result = subprocess.run(['bash', '-n'], input=body, text=True, capture_output=True)
            check(result.returncode == 0, 'bash syntax ' + name + '/' + step['name'])
remote_step = next(s for s in steps if s['name'] == 'Deploy exact image digest')
remote = remote_step['with']['script']
check('BACKEND_STOP_SERVICES="${MOST_COMPOSE_WRITER_SERVICES[*]}"' in remote and 'MOST_COMPOSE_STOP_SERVICES' not in remote, 'ordinary cleanup excludes protected roles')
check('PUBLIC_CORE_CURRENT' not in json.dumps(workflow['jobs']['deploy']) and 'ACCEPTED_MAIN_SHA' not in json.dumps(workflow['jobs']['deploy']), 'normal candidate vars absent')
for forbidden in ['public_core_current_control_guard', 'prepare_public_core_', 'intake_public_core_', 'stage_public_core_', 'verify_public_core_staged_tuple', 'invalidate_public_core_projections', 'quiesce_public_core_gateway_route']:
    check(forbidden not in remote, 'normal route decoupled ' + forbidden)
for retained in ['verify_backend_release_image', 'assert_public_core_inactive_for_backend_release', 'assert_legacy_runtime_stopped', 'migrate:safe --force', 'writer-readiness', 'http://localhost:8000/ready', 'http://localhost:8000/up', 'systemctl is-active --quiet nginx']:
    check(retained in remote, 'retain ' + retained)
check(workflow['jobs']['deploy']['concurrency'] == {'group': 'prod-backend-deploy', 'cancel-in-progress': 'false'}, 'deployment concurrency')
check(remote_step['with'].get('script_stop', 'false') == 'false', 'Bash owns heredoc error handling')
remote_guard = remote[remote.index('test "${MANAGED_MODE}"'):remote.index('cd /var/www/prohelper')]
remote_valid = dict(MANAGED_MODE='release', MANAGED_REF='refs/heads/main', MANAGED_EVENT='workflow_dispatch', MANAGED_EXPECTED_SHA=sha, RELEASE_SHA=sha, BACKEND_HELPER_SHA256='c' * 64, IMAGE_REPO='ghcr.io/kamilgaraev/proexpert/prohelper', IMAGE_DIGEST='sha256:'+'d'*64, REVERB_APP_KEY='e'*64)
check(bash('set -euo pipefail\n'+remote_guard, remote_valid).returncode == 0, 'remote guard without candidate')
for key, value in [('MANAGED_MODE','input-only'), ('MANAGED_REF','refs/heads/task/test'), ('MANAGED_EVENT','push'), ('MANAGED_EXPECTED_SHA','b'*40), ('RELEASE_SHA',sha+'\n'), ('IMAGE_DIGEST','latest'), ('BACKEND_HELPER_SHA256',''), ('IMAGE_REPO','ghcr.io/foreign/image')]:
    check(bash('set -euo pipefail\n'+remote_guard, {**remote_valid,key:value}).returncode != 0, 'remote denial '+key)

normal = ['api', 'websockets', 'horizon', 'geometry-worker', 'geometry-recovery-worker', 'worker-heavy', 'worker-ifc', 'scheduler']
services = compose['services']
for service in normal:
    c = services[service]
    check('profiles' not in c, service + ' ordinary startup')
    check(not c.get('group_add') and c.get('pid') != 'service:api', service + ' no protected process/group access')
    check(not any(isinstance(v,dict) and 'most-public-core' in v.get('source','') or isinstance(v,dict) and '/public-core/' in v.get('source','') for v in c.get('volumes',[])), service + ' no private mounts')
    if service in ['api', 'horizon']:
        check(c.get('env_file') == '.env', service + ' protected existing env file')
        check('TIMEWEB_AI_API_KEY' not in c['environment'], service + ' canonical key not overridden')
        for key in ['OPENAI_API_KEY', 'DEEPSEEK_API_KEY', 'TIMEWEB_API_KEY', 'TIMEWEB_AI_PROXY_KEY', 'AI_RAG_EMBEDDING_API_KEY']:
            check(c['environment'][key] == '', service + ' disabled fallback ' + key)
    else:
        check(c['environment']['TIMEWEB_AI_API_KEY'] == '', service + ' unchanged inactive credentials')
for service in ['public-core-processor','public-core-gateway']:
    check(services[service]['profiles'] == ['public-core'] and services[service]['restart'] == 'no' and 'env_file' not in services[service], service + ' remains optional/inactive')
check(compose['networks']['public-core-gateway']['internal'] == 'true', 'protected network still internal')
check(json.loads((root / 'deploy/public-core-runtime.json.example').read_text())['activation'] == 'inactive', 'default manifest remains inactive')
check("printf '<?php return null;\\n' > /etc/most/public-core/app/bootstrap.php" in (root / 'Dockerfile.prod').read_text(), 'image App bootstrap remains unavailable')
r = bash('source "$HELPER"; printf "%s\\n" "${MOST_COMPOSE_WRITER_SERVICES[*]}"', {'HELPER': str(helper)})
check(r.returncode == 0 and r.stdout.strip() == ' '.join(normal), 'exact normal writer allowlist')

# Real helper functions, inert metadata interfaces; every unexpected mutation fails.
mock = r'''
source "$HELPER"
docker() {
  local args="$*"
  case "$args" in
    'ps '* )
      [ "$CASE" != discovery-error ] || return 17
      [ "$CASE" = absent ] || printf '%s\n' 111111111111
      ;;
    'inspect '* )
      [ "$CASE" != inspect-error ] || return 17
      case "$args" in
        *com.docker.compose.project*)
          if [ "$CASE" = foreign ]; then echo foreign:"$role"; else echo prohelper:"$role"; fi ;;
        *State.Running*)
          case "$CASE" in active) echo true:42:no ;; restart) echo false:0:always ;; *) echo false:0:no ;; esac ;;
        *NetworkSettings*) [ "$CASE" != endpoint ] || echo endpoint ;;
        *NetworkMode*) echo none ;;
        *) return 99 ;;
      esac ;;
    'network ls '* ) [ "$CASE" != network-error ] || return 17; [ "$CASE" = absent ] || echo 222222222222 ;;
    'network inspect '* )
      [ "$CASE" != network-inspect-error ] || return 17
      case "$args" in
        *bridge.name*) echo br-most-pc ;;
        *com.docker.compose.project*) [ "$CASE" != network-foreign ] && echo prohelper:public-core-gateway || echo foreign:public-core-gateway ;;
        *Containers*) [ "$CASE" != member ] || echo 333333333333 ;;
        *) return 99 ;;
      esac ;;
    *) echo MUTATION >&2; return 99 ;;
  esac
  return 0
}
ip() { [ "$CASE" != link-error ] || return 17; [ "$CASE" != orphan-bridge ] || echo '1: br-most-pc:'; return 0; }
assert_public_core_inactive_for_backend_release
'''
for case in ['absent','stopped','discovery-error','inspect-error','foreign','active','restart','endpoint','network-error','network-inspect-error','network-foreign','member','link-error','orphan-bridge']:
    # orphan bridge has no discovered owned network.
    body = mock.replace('[ "$CASE" = absent ] || echo 222222222222', '[ "$CASE" = absent ] || [ "$CASE" = orphan-bridge ] || echo 222222222222')
    r = bash(body, {'HELPER':str(helper),'CASE':case})
    check((r.returncode == 0) == (case in ['absent','stopped']) and 'MUTATION' not in r.stderr, 'inactive read-only '+case)

image_mock = r'''
source "$HELPER"
docker() {
  [ "$CASE" != command-error ] || return 17
  case "$*" in
    'image inspect '*) [ "$CASE" != label ] && echo "$SHA" || echo wrong ;;
    'run '*release.json*) [ "$CASE" != embedded ] && echo "$SHA" || echo wrong ;;
    'run '*hash_file*) [ "$CASE" != helper ] && echo "$BACKEND_HELPER_SHA256" || echo wrong ;;
    *) return 99 ;;
  esac
}
timeout() { shift 3; "$@"; }
verify_backend_release_image "$IMAGE" "$SHA"
'''
for case in ['valid','command-error','label','embedded','helper']:
    r=bash(image_mock, {'HELPER':str(helper),'CASE':case,'SHA':sha,'BACKEND_HELPER_SHA256':'c'*64,'IMAGE':'ghcr.io/kamilgaraev/proexpert/prohelper@sha256:'+'d'*64})
    check((r.returncode == 0) == (case == 'valid'), 'image '+case)
print('PASS: %d source/guard/service/inert-metadata checks; runtime/production NOT_RUN' % count)
PY
