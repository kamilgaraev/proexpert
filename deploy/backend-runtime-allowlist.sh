#!/usr/bin/env bash

MOST_COMPOSE_WRITER_SERVICES=(
  api websockets horizon geometry-worker geometry-recovery-worker worker-heavy worker-ifc scheduler
)

# Include optional protected roles in drain/stop detection, never default startup.
MOST_PUBLIC_CORE_SERVICES=(public-core-processor public-core-gateway)
MOST_COMPOSE_STOP_SERVICES=("${MOST_COMPOSE_WRITER_SERVICES[@]}" "${MOST_PUBLIC_CORE_SERVICES[@]}")

# Called only by the existing privileged main deployment. No secrets, proof
# receipts, activation, egress claims or private startup readers are fabricated.
prepare_public_core_inactive_runtime() {
  local role role_gid role_path socket_path
  for role_path in /etc/most /etc/most/public-core /run/most-public-core; do
    [ ! -L "${role_path}" ] || return 1
    if [ ! -e "${role_path}" ]; then
      install -d -o root -g root -m 0755 "${role_path}"
    fi
    [ ! -L "${role_path}" ] && [ -d "${role_path}" ] \
      && [ "$(stat -c '%u:%g:%a' "${role_path}")" = '0:0:755' ] || return 1
  done
  for role in app processor gateway; do
    case "${role}" in
      app) role_gid=82 ;;
      processor) role_gid=41002 ;;
      gateway) role_gid=41003 ;;
    esac
    role_path="/etc/most/public-core/${role}"
    [ ! -L "${role_path}" ] || return 1
    if [ ! -e "${role_path}" ]; then
      install -d -o root -g "${role_gid}" -m 0750 "${role_path}"
    fi
    [ ! -L "${role_path}" ] && [ -d "${role_path}" ] \
      && [ "$(stat -c '%u:%g:%a' "${role_path}")" = "0:${role_gid}:750" ] || return 1
    if [ "${role}" = gateway ]; then
      [ ! -L "${role_path}/runtime.json" ] || return 1
      if [ ! -e "${role_path}/runtime.json" ]; then
        install -o root -g "${role_gid}" -m 0640 deploy/public-core-runtime.json.example "${role_path}/runtime.json"
      fi
      role_path="${role_path}/runtime.json"
    else
      [ ! -L "${role_path}/bootstrap.php" ] || return 1
      if [ ! -e "${role_path}/bootstrap.php" ]; then
        (set -o noclobber; printf '<?php return null;\n' > "${role_path}/bootstrap.php") || return 1
        chown "root:${role_gid}" "${role_path}/bootstrap.php"
        chmod 0640 "${role_path}/bootstrap.php"
      fi
      role_path="${role_path}/bootstrap.php"
    fi
    [ ! -L "${role_path}" ] && [ -f "${role_path}" ] \
      && [ "$(stat -c '%u:%g:%a' "${role_path}")" = "0:${role_gid}:640" ] || return 1
  done
  for role_gid in 41002 41003; do
    case "${role_gid}" in
      41002) socket_path=/run/most-public-core/processor ;;
      41003) socket_path=/run/most-public-core/gateway ;;
    esac
    [ ! -L "${socket_path}" ] || return 1
    if [ ! -e "${socket_path}" ]; then
      install -d -o "${role_gid}" -g "${role_gid}" -m 0750 "${socket_path}"
    fi
    [ ! -L "${socket_path}" ] && [ -d "${socket_path}" ] \
      && [ "$(stat -c '%u:%g:%a' "${socket_path}")" = "${role_gid}:${role_gid}:750" ] || return 1
  done
}

# Existing exact-image main route only. Never start a role or generate a proof.
prepare_public_core_image_inputs() {
  local image_ref="$1" release_sha="$2" previous_release_sha="${3:-}" role gid wrapper current expected_source source_sha previous_source= old_wrapper_hash
  [[ "${image_ref}" =~ @sha256:[0-9a-f]{64}$ ]] && [[ "${release_sha}" =~ ^[0-9a-f]{40}$ ]] || return 1
  for role in gateway/credential app processor; do
    case "${role}" in gateway/credential) gid=41003 ;; app) gid=82 ;; processor) gid=41002 ;; esac
    wrapper="/etc/most/public-core/${role}"
    [ ! -L "${wrapper}" ] || return 1
    if [ ! -e "${wrapper}" ]; then install -d -o root -g "${gid}" -m 0750 "${wrapper}"; fi
    [ "$(stat -c '%u:%g:%a' "${wrapper}")" = "0:${gid}:750" ] || return 1
  done
  for wrapper in /var/lib/most /var/lib/most/public-core; do
    [ ! -L "${wrapper}" ] || return 1
    if [ ! -e "${wrapper}" ]; then install -d -o root -g root -m 0755 "${wrapper}"; fi
    [ "$(stat -c '%u:%g:%a' "${wrapper}")" = '0:0:755' ] || return 1
  done
  for role in app processor; do
    case "${role}" in
      app) gid=82; wrapper=/run/most-public-core/app ;;
      processor) gid=41002; wrapper=/var/lib/most/public-core/processor ;;
    esac
    [ ! -L "${wrapper}" ] || return 1
    if [ ! -e "${wrapper}" ]; then install -d -o "${gid}" -g "${gid}" -m 0700 "${wrapper}"; fi
    [ "$(stat -c '%u:%g:%a' "${wrapper}")" = "${gid}:${gid}:700" ] || return 1
  done
  # The environment is a read-only file, never container env/argv or shell source.
  # The image parser suppresses private exceptions and only creates absent keys.
  docker run --rm --network none --read-only --cap-drop ALL --cap-add CHOWN \
    --security-opt no-new-privileges --user 0:0 \
    --mount type=bind,source=/var/www/prohelper/.env,target=/run/most-ci/environment,readonly \
    --mount type=bind,source=/etc/most/public-core,target=/etc/most/public-core \
    --tmpfs /tmp:rw,noexec,nosuid,size=16777216,mode=1777 \
    --entrypoint php "${image_ref}" docker/public-core/runtime.php provision-credentials "${release_sha}" || return 1
  source_sha="$(sha256sum docker/public-core/runtime.php | cut -d' ' -f1)"
  [[ "${source_sha}" =~ ^[0-9a-f]{64}$ ]] || return 1
  if [ -n "${previous_release_sha}" ]; then
    [[ "${previous_release_sha}" =~ ^[0-9a-f]{40}$ ]] || return 1
    previous_source="$(set -o pipefail; git show "${previous_release_sha}:docker/public-core/runtime.php" | sha256sum | cut -d' ' -f1)" || return 1
    [[ "${previous_source}" =~ ^[0-9a-f]{64}$ ]] || return 1
  fi
  for role in app processor; do
    case "${role}" in app) gid=82 ;; processor) gid=41002 ;; esac
    wrapper="/etc/most/public-core/${role}/bootstrap.php"
    [ ! -L "${wrapper}" ] && [ "$(stat -c '%u:%g:%a' "${wrapper}")" = "0:${gid}:640" ] || return 1
    # Accept only the inactive placeholder or a pinned generated bootstrap wrapper.
    expected_source="$(printf '<?php return null;\n' | sha256sum | cut -d' ' -f1)"
    current="$(sha256sum "${wrapper}" | cut -d' ' -f1)"
    # Only the exact inactive placeholder or our exact previous-source generated wrapper may upgrade.
    # An independently root-managed executable is never guessed or overwritten.
    local temporary
    temporary="$(mktemp "/etc/most/public-core/${role}/.bootstrap.XXXXXXXX")" || return 1
    {
      printf '<?php\ndeclare(strict_types=1);\n'
      printf "if (!hash_equals('%s', hash_file('sha256', '/var/www/html/docker/public-core/runtime.php'))) { return null; }\n" "${source_sha}"
      printf "require_once '/var/www/html/docker/public-core/runtime.php';\n"
      if [ "${role}" = app ]; then
        printf 'return static function ($app, $configure) { \\Most\\PublicCore\\AppRuntimeBootstrap::configureProtected($app, $configure); };\n'
      else
        printf 'return \\Most\\PublicCore\\ProcessorRuntimeBootstrap::protectedListener();\n'
      fi
    } > "${temporary}"
    if [ "${current}" != "${expected_source}" ]; then
      old_wrapper_hash="$(sha256sum "${temporary}" | cut -d' ' -f1)" || { rm -f -- "${temporary}"; return 1; }
      if [ "${current}" = "${old_wrapper_hash}" ]; then rm -f -- "${temporary}"; continue; fi
      [ -n "${previous_source}" ] || { rm -f -- "${temporary}"; return 1; }
      old_wrapper_hash="$(sed "s/${source_sha}/${previous_source}/" "${temporary}" | sha256sum | cut -d' ' -f1)" || { rm -f -- "${temporary}"; return 1; }
      [ "${current}" = "${old_wrapper_hash}" ] || { rm -f -- "${temporary}"; return 1; }
    fi
    chown "root:${gid}" "${temporary}" && chmod 0640 "${temporary}" || { rm -f -- "${temporary}"; return 1; }
    # Refuse concurrent root mutation of the exact accepted old bytes.
    [ "$(sha256sum "${wrapper}" | cut -d' ' -f1)" = "${current}" ] \
      && mv -T -- "${temporary}" "${wrapper}" || { rm -f -- "${temporary}"; return 1; }
  done
}

# Future separately assigned input-only: checked stage, readonly fixed store, no provisioning.
describe_managed_model_input() {
  local image_ref="$1" release_sha="$2" pins="$3" assignment="$4" accepted="$5" expected_pins="$6" output
  local helper runtime config policy provider workflow dockerfile ignore
  validate_public_core_input_source "${image_ref}" "${release_sha}" "${pins}" "${assignment}" "${accepted}" "${expected_pins}" >/dev/null || return 1
  [ "${MANAGED_MODE}" = input-only ] || return 1
  IFS=: read -r helper runtime config policy provider workflow dockerfile ignore <<< "${pins}"
  output="$(timeout --signal=TERM --kill-after=5s 45s docker run --pull never --rm --network none --read-only --cap-drop ALL \
    --security-opt no-new-privileges --user 0:0 --log-driver none \
    --mount type=bind,source=/var/www/prohelper/.env,target=/run/most-ci/environment,readonly \
    --entrypoint php "${image_ref}" docker/public-core/runtime.php describe-model-input "${release_sha}" 2>/dev/null)" || return 1
  [ "${#output}" -le 16384 ] || return 1
  # Never emit rejected output. Fixed schema/enums guard even a malformed export.
  validate_public_core_input_source "${image_ref}" "${release_sha}" "${pins}" "${assignment}" "${accepted}" "${expected_pins}" >/dev/null || return 1
  printf '%s' "${output}" | python3 -c '
import json,sys,re
try:
 d=json.load(sys.stdin)
 keys="schemaVersion releaseSha sourceConfigDigest sourcePolicyDigest observationKind provider baseUri apiMethod modelId defaultProfile profiles credentialReference fieldOrigins effectiveRuntimeSettingsObserved actualModelQualified activationAuthorized".split()
 assert set(d)==set(keys) and d["schemaVersion"]=="existing-model-route-input/1" and d["releaseSha"]==sys.argv[1]
 assert d["sourceConfigDigest"]==sys.argv[2] and d["sourcePolicyDigest"]==sys.argv[3]
 assert d["observationKind"]=="managed_deployment_input" and d["provider"] in ["timeweb","openai"]
 assert d["baseUri"] in ["https://api.timeweb.ai/v1","unavailable"] and d["apiMethod"] in ["responses","unavailable"]
 assert d["modelId"] in ["openai/gpt-6-luna","unavailable"] and d["defaultProfile"] in ["assistant","json","fast","premium","unavailable"]
 assert d["credentialReference"] in ["TIMEWEB_AI_API_KEY","TIMEWEB_API_KEY","TIMEWEB_AI_PROXY_KEY","unavailable"]
 assert all(d[k] is False for k in ["effectiveRuntimeSettingsObserved","actualModelQualified","activationAuthorized"])
 assert set(d["profiles"])==set(["assistant","json","fast","premium"])
 for p in d["profiles"].values():
  assert set(p)==set(["maxOutputTokens","timeout"]) and type(p["maxOutputTokens"]) is int and 1<=p["maxOutputTokens"]<=10000000 and type(p["timeout"]) is int and 1<=p["timeout"]<=120
 allowed=set("apiMethod modelId LLM_PROVIDER TIMEWEB_AI_BASE_URI TIMEWEB_AI_DEFAULT_PROFILE TIMEWEB_AI_MAX_TOKENS TIMEWEB_AI_TIMEOUT TIMEWEB_AI_ASSISTANT_MAX_TOKENS TIMEWEB_AI_ASSISTANT_TIMEOUT TIMEWEB_AI_JSON_MAX_TOKENS TIMEWEB_AI_JSON_TIMEOUT TIMEWEB_AI_FAST_MAX_TOKENS TIMEWEB_AI_FAST_TIMEOUT TIMEWEB_AI_PREMIUM_MAX_TOKENS TIMEWEB_AI_PREMIUM_TIMEOUT".split())
 assert set(d["fieldOrigins"])==allowed and all(v in ["default","managed_store","source_policy"] for v in d["fieldOrigins"].values())
 print(json.dumps(d,separators=(",",":")))
except Exception: sys.exit(1)
' "${release_sha}" "${config}" "${policy}" || return 1
}

# Full invalidation never restores old active inputs. CURRENT admission is a separate
# managed step before the bounded same-process publication attempt.
public_core_monotonic_ns() {
  python3 -c 'import time; print(time.monotonic_ns())'
}

# Kernel start time + boot identity is stable across PID reuse; no env/cmdline reads.
public_core_process_lifetime() {
  timeout --signal=TERM --kill-after=1s 2s python3 - "$1" <<'PYLIFETIME'
import sys,pathlib,hashlib
pid=sys.argv[1]; assert pid.isdigit() and int(pid)>0
p=pathlib.Path('/proc')/pid/'stat'
a=p.read_bytes(); assert len(a)<=65536
boot=pathlib.Path('/proc/sys/kernel/random/boot_id').read_text().strip(); assert boot
start=a[a.rfind(b')')+1:].split()[19]; assert start.isdigit()
b=p.read_bytes(); assert b[b.rfind(b')')+1:].split()[19]==start
print(hashlib.sha256(boot.encode()+b':'+pid.encode()+b':'+start).hexdigest())
PYLIFETIME
}

# Selected immutable generation metadata only; expiry is checked on EVERY read.
public_core_generation_identity() {
  python3 - "$1" "$2" <<'PYGENERATION'
import sys,pathlib,os,stat,json,hashlib,time,re
release,image=sys.argv[1:]; hashes=[]; profiles=[]; lifetimes=[]
def stable(v): return (v.st_dev,v.st_ino,v.st_mode,v.st_uid,v.st_gid,v.st_size,v.st_mtime_ns,v.st_ctime_ns)
for role,gid in [('app',82),('processor',41002),('gateway',41003)]:
 p=pathlib.Path('/etc/most/public-core')/role/'generation.json'
 assert str(p.resolve(strict=True))==str(p)
 parent=p.parent.stat(); assert stat.S_ISDIR(parent.st_mode) and parent.st_uid==0 and parent.st_gid==gid and stat.S_IMODE(parent.st_mode)==0o750
 before=p.lstat(); assert stat.S_ISREG(before.st_mode) and before.st_uid==0 and before.st_gid==gid and stat.S_IMODE(before.st_mode)==0o640 and 0<before.st_size<=65536
 with p.open('rb') as f:
  opened=os.fstat(f.fileno()); assert stable(opened)==stable(before)
  raw=f.read(65537)
 assert len(raw)==before.st_size and stable(p.lstat())==stable(before)
 d=json.loads(raw); assert set(d)=={'schemaVersion','releaseSha','imageDigest','expiresAt','profileFingerprint','files','roleLifetimes'}
 assert d['schemaVersion']=='public-core-projection-generation/1' and d['releaseSha']==release and d['imageDigest']==image
 assert type(d['expiresAt']) is int and d['expiresAt']>time.time()
 assert re.fullmatch('[0-9a-f]{64}',d['profileFingerprint'])
 assert set(d['roleLifetimes'])=={'processor','gateway'} and all(re.fullmatch('ref_[0-9a-f]{32}',v) for v in d['roleLifetimes'].values())
 assert isinstance(d['files'],dict) and d['files'] and all(isinstance(k,str) and re.fullmatch('[0-9a-f]{64}',v) for k,v in d['files'].items())
 profiles.append(d['profileFingerprint']); lifetimes.append(d['roleLifetimes']); hashes.append(hashlib.sha256(raw).hexdigest())
assert len(set(profiles))==1 and all(v==lifetimes[0] for v in lifetimes)
print(hashlib.sha256(json.dumps(hashes,separators=(',',':')).encode()).hexdigest())
PYGENERATION
}

# Fixed protected CURRENT store only. This validates genuine producer bytes; it never produces claims.
public_core_current_control_guard() {
  python3 - "$1" "$2" "$3" "$4" "${5:-candidate}" <<'PYCURRENT'
import hashlib,json,os,re,stat,sys,time
ROOT='/etc/most/public-core'
release,image,revision,digest,mode=sys.argv[1:]
pins={}
def fail(): raise ValueError('current_inputs_unavailable')
def exact(v,keys): return type(v) is dict and set(v)==set(keys)
def pairs(v):
    d={}
    for k,x in v:
        if k in d: fail()
        d[k]=x
    return d
def read_path(path,gid=0,limit=65536):
    if os.path.realpath(path)!=path: fail()
    a=os.lstat(path); parent=os.lstat(os.path.dirname(path))
    if not stat.S_ISREG(a.st_mode) or a.st_uid!=0 or a.st_gid!=gid or a.st_mode&0o037 or not 0<a.st_size<=limit: fail()
    if not stat.S_ISDIR(parent.st_mode) or parent.st_uid!=0 or parent.st_gid!=gid or parent.st_mode&0o027: fail()
    ancestor=os.path.dirname(os.path.dirname(path))
    while True:
        m=os.lstat(ancestor)
        if not stat.S_ISDIR(m.st_mode) or m.st_uid!=0 or m.st_mode&0o022: fail()
        if ancestor=='/': break
        ancestor=os.path.dirname(ancestor)
    with open(path,'rb') as f:
        b=os.fstat(f.fileno()); data=f.read(limit+1)
    c=os.lstat(path)
    fields=('st_dev','st_ino','st_mode','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns')
    if any(getattr(a,k)!=getattr(b,k) or getattr(b,k)!=getattr(c,k) for k in fields) or len(data)!=a.st_size: fail()
    pin=tuple(getattr(a,k) for k in fields)+(hashlib.sha256(data).hexdigest(),)
    if path in pins and pins[path][0]!=pin: fail()
    pins[path]=(pin,gid,limit)
    return data
def read(name):
    if not re.fullmatch(r'[A-Za-z0-9_-]{1,128}\.json',name): fail()
    data=read_path(ROOT+'/control/'+name)
    return data,json.loads(data,object_pairs_hook=pairs)
def valid_time(v,now):
    return type(v.get('issuedAt')) is int and 0<v['issuedAt']<=now and type(v.get('expiresAt')) is int and v['expiresAt']>max(now,v['issuedAt'])
try:
    if not re.fullmatch(r'[0-9a-f]{40}',release) or not re.fullmatch(r'sha256:[0-9a-f]{64}',image) or not re.fullmatch(r'[1-9][0-9]{0,8}',revision) or not re.fullmatch(r'[0-9a-f]{64}',digest): fail()
    raw,d=read('accepted-candidate.json'); now=int(time.time())
    if not exact(d,['schemaVersion','revision','status','acceptance','issuedAt','expiresAt','revokedAt','releaseSha','imageDigest','runtime','profileFingerprint','modelBinding','evidence','tokenizer']): fail()
    if hashlib.sha256(raw).hexdigest()!=digest or d['schemaVersion']!='public-core-accepted-candidate/1' or type(d['revision']) is not int or d['revision']!=int(revision) or d['status']!='current' or d['revokedAt'] is not None or not valid_time(d,now) or d['releaseSha']!=release or d['imageDigest']!=image: fail()
    if not exact(d['acceptance'],['decisionRef','documentRevisionId','artifactSha256','custodianChannel']) or d['acceptance']['custodianChannel']!='prod-backend-deploy' or not re.fullmatch(r'[A-Za-z0-9_.:-]{16,256}',d['acceptance']['decisionRef']) or not re.fullmatch(r'[A-Za-z0-9_.:-]{16,256}',d['acceptance']['documentRevisionId']) or not re.fullmatch(r'[0-9a-f]{64}',d['acceptance']['artifactSha256']): fail()
    binding={'revision':int(revision),'sha256':digest,'acceptanceRef':d['acceptance']['decisionRef']}
    runtime_bytes,runtime=read('candidate-runtime.json')
    if d['runtime']!={'file':'candidate-runtime.json','sha256':hashlib.sha256(runtime_bytes).hexdigest()}: fail()
    m=d['modelBinding']; profile=runtime.get('profile')
    if not exact(m,['provider','modelId','modelRevision','catalogDigest','apiMethod','templateVersion','contextWindow','maxOutputTokens','tokenizerId','tokenizerRevision','countMethod','vocabularySha256','patternSha256']) or m['provider']!='timeweb' or m['modelId']!='openai/gpt-6-luna' or m['apiMethod']!='responses' or m['templateVersion']!='timeweb-native-responses/1' or m['countMethod']!='full_wire_json_bpe_upper_bound' or not re.fullmatch(r'[0-9a-f]{64}',m['catalogDigest']): fail()
    if not exact(profile,['profileRef','qualification','adapterRevision','apiMethod','endpoint','modelId','modelRevision','tokenizerId','tokenizerRevision','mappingEvidenceRef','capabilityEvidenceRef','capacityEvidenceRef','contextWindow','maxOutputTokens','answerReserve','toolReserve']) or profile['qualification']!='actual' or profile['adapterRevision']!='timeweb-native-responses/1' or profile['apiMethod']!='responses' or profile['endpoint']!='https://api.timeweb.ai/v1/responses': fail()
    if hashlib.sha256(json.dumps(profile,sort_keys=True,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()!=d['profileFingerprint']: fail()
    for k in ['modelId','modelRevision','apiMethod','contextWindow','maxOutputTokens','tokenizerId','tokenizerRevision']:
        if m[k]!=profile[k] or type(m[k]) is not type(profile[k]): fail()
    for k in ['contextWindow','maxOutputTokens','answerReserve','toolReserve']:
        if type(profile[k]) is not int or not 1<=profile[k]<=10000000: fail()
    if not profile['maxOutputTokens']<=profile['answerReserve']<profile['contextWindow'] or profile['toolReserve']>=profile['contextWindow']-profile['answerReserve']: fail()
    if not exact(runtime,['schemaVersion','activation','gatewayUid','gatewayGid','processorPeer','socketPath','profile','evidenceDirectory','evidence','credentialFile','tokenizerFile','tokenizerSha256','tokenizerPattern','tokenizerPatternSha256','tokenizerVocabulary','deadlineMs','maxRequests']) or runtime['schemaVersion']!='public-core-gateway-runtime/1' or runtime['activation']!='approved' or runtime['gatewayUid']!=41003 or runtime['gatewayGid']!=41003 or runtime['processorPeer']!={'uid':41002,'gid':41002,'pid':None} or runtime['socketPath']!='/run/most-public-core/gateway/gateway.sock' or runtime['credentialFile']!=ROOT+'/gateway/credential/provider-key' or runtime['tokenizerFile']!=ROOT+'/gateway/tokenizer/vocabulary.tiktoken' or runtime['tokenizerPattern']!=ROOT+'/gateway/tokenizer/pattern.txt' or runtime['tokenizerVocabulary']!=profile['tokenizerId'] or runtime['tokenizerSha256']!=m['vocabularySha256'] or runtime['tokenizerPatternSha256']!=m['patternSha256'] or type(runtime['deadlineMs']) is not int or not 12000<=runtime['deadlineMs']<=30000 or type(runtime['maxRequests']) is not int or not 1<=runtime['maxRequests']<=128: fail()
    kinds=['catalog','method','capacity','tokenizer','key','identity','channel','egress','backendAuthority','nativeTransfer']
    if not exact(d['evidence'],kinds) or runtime.get('evidence')!=d['evidence'] or runtime.get('evidenceDirectory')!=ROOT+'/gateway/evidence': fail()
    for kind in kinds:
        r=d['evidence'][kind]
        if not exact(r,['ref','file','sha256']) or not re.fullmatch(r'[A-Za-z0-9_-]{1,128}\.json',r['file']): fail()
        data=read_path(ROOT+'/gateway/evidence/'+r['file'],41003)
        v=json.loads(data,object_pairs_hook=pairs)
        if hashlib.sha256(data).hexdigest()!=r['sha256'] or not exact(v,['schemaVersion','kind','ref','status','profileFingerprint','modelId','apiMethod','issuedAt','expiresAt','details']) or v['schemaVersion']!='public-core-runtime-evidence/1' or v['kind']!=kind or v['ref']!=r['ref'] or v['status']!='verified' or v['profileFingerprint']!=d['profileFingerprint'] or v['modelId']!=d['modelBinding']['modelId'] or v['apiMethod']!='responses' or not valid_time(v,now) or v['details'].get('releaseSha')!=release or v['details'].get('imageDigest')!=image: fail()
        if kind=='method' and (v['details'].get('endpoint')!='https://api.timeweb.ai/v1/responses' or v['details'].get('templateVersion')!='timeweb-native-responses/1'): fail()
    for key,file,limit in [('vocabulary','vocabulary.tiktoken',16777216),('pattern','pattern.txt',32768)]:
        t=d['tokenizer'][key]
        if not exact(t,['file','sha256']) or t['file']!=file or hashlib.sha256(read_path(ROOT+'/gateway/tokenizer/'+file,41003,limit)).hexdigest()!=t['sha256']: fail()
    if mode=='publication':
        _,pub=read('publication.json'); _,q=read('qualification.json'); _,observed=read('observed-peers.json')
        refs=['authorizationFenceEvidenceRef','identityEvidenceRef','channelEvidenceRef','egressEvidenceRef','secretEvidenceRef','activationRef']
        if not exact(pub,['schemaVersion','releaseSha','imageDigest','expiresAt','consumersStopped','peers','acceptedReceipts','expectedOutputs','candidate']) or pub['schemaVersion']!='public-core-projection-publication/2' or pub['candidate']!=binding or pub['consumersStopped'] is not True or pub['releaseSha']!=release or pub['imageDigest']!=image or type(pub['expiresAt']) is not int or pub['expiresAt']<=now: fail()
        if not exact(q,['schemaVersion','qualification','profileFingerprint','registryDigest']+refs) or q['schemaVersion']!='public-core-runtime-proof/1' or q['qualification']!='actual' or q['profileFingerprint']!=d['profileFingerprint'] or not re.fullmatch(r'[0-9a-f]{64}',q['registryDigest']): fail()
        if not exact(observed,['schemaVersion','releaseSha','imageDigest','observedAt','peers']) or observed['schemaVersion']!='public-core-observed-peers/1' or observed['releaseSha']!=release or observed['imageDigest']!=image or type(observed['observedAt']) is not int or not 0<=now-observed['observedAt']<30 or observed['peers']!=pub['peers'] or not exact(observed['peers'],['processor','gateway']): fail()
        lives={}
        for role,uid in [('processor',41002),('gateway',41003)]:
            v=observed['peers'][role]
            if not exact(v,['containerId','imageDigest','service','hostPid','peer','lifetimeRef']) or v['imageDigest']!=image or v['service']!='public-core-'+role or not re.fullmatch(r'[0-9a-f]{64}',v['containerId']) or type(v['hostPid']) is not int or v['hostPid']<1 or not exact(v['peer'],['pid','uid','gid']) or type(v['peer']['pid']) is not int or v['peer']['pid']<1 or v['peer']['uid']!=uid or v['peer']['gid']!=uid: fail()
            lives[role]=v['lifetimeRef']
        if not exact(pub['acceptedReceipts'],refs) or type(pub['expectedOutputs']) is not dict or 'gateway/runtime.json' not in pub['expectedOutputs'] or pub['expectedOutputs']['gateway/runtime.json']!=hashlib.sha256(runtime_bytes).hexdigest(): fail()
        for key in refs:
            r=pub['acceptedReceipts'][key]
            if not exact(r,['ref','file','sha256']) or r['ref']!=q[key]: fail()
            data,v=read(r['file'])
            if hashlib.sha256(data).hexdigest()!=r['sha256'] or not exact(v,['schemaVersion','kind','ref','status','profileFingerprint','modelId','apiMethod','issuedAt','expiresAt','details']) or v['schemaVersion']!='public-core-runtime-evidence/1' or v['kind']!=key or v['ref']!=q[key] or v['status']!='verified' or v['profileFingerprint']!=d['profileFingerprint'] or v['modelId']!=d['modelBinding']['modelId'] or v['apiMethod']!='responses' or not valid_time(v,now) or v['issuedAt']<observed['observedAt']: fail()
            for field,value in [('releaseSha',release),('imageDigest',image),('registryDigest',q['registryDigest']),('roleLifetimes',lives),('candidate',binding)]:
                if v['details'].get(field)!=value: fail()
    elif mode!='candidate': fail()
    final_now=int(time.time())
    for path,(_,gid,limit) in list(pins.items()):
        data=read_path(path,gid,limit)
        if path.endswith('.json'):
            value=json.loads(data,object_pairs_hook=pairs)
            if 'expiresAt' in value and (type(value['expiresAt']) is not int or value['expiresAt']<=final_now): fail()
    if d['expiresAt']<=final_now or (mode=='publication' and not 0<=final_now-observed['observedAt']<30): fail()
except Exception:
    sys.stderr.write('public-core: current_inputs_unavailable\n');sys.exit(1)
PYCURRENT
}

# Read-only exact image/source gate runs before any managed store mutation.
verify_public_core_candidate_image() {
  local image_ref="$1" release_sha="$2" source embedded helper runtime
  [[ "${image_ref}" =~ ^ghcr\.io/kamilgaraev/proexpert/prohelper@sha256:[0-9a-f]{64}$ ]] \
    && [[ "${release_sha}" =~ ^[0-9a-f]{40}$ ]] \
    && [[ "${PUBLIC_CORE_HELPER_SHA256}" =~ ^[0-9a-f]{64}$ ]] \
    && [[ "${PUBLIC_CORE_RUNTIME_SHA256}" =~ ^[0-9a-f]{64}$ ]] || return 1
  source="$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "${image_ref}")" || return 1
  [ "${source}" = "${release_sha}" ] || return 1
  embedded="$(timeout --signal=TERM --kill-after=5s 10s docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges --user 41003:41003 --entrypoint php "${image_ref}" -r 'echo json_decode(file_get_contents("/etc/most/release.json"), true, 8, JSON_THROW_ON_ERROR)["sha"];')" || return 1
  [ "${embedded}" = "${release_sha}" ] || return 1
  helper="$(timeout --signal=TERM --kill-after=5s 10s docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges --user 41003:41003 --entrypoint php "${image_ref}" -r 'echo hash_file("sha256", "deploy/backend-runtime-allowlist.sh");')" || return 1
  [ "${helper}" = "${PUBLIC_CORE_HELPER_SHA256}" ] || return 1
  runtime="$(timeout --signal=TERM --kill-after=5s 10s docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges --user 41003:41003 --entrypoint php "${image_ref}" -r 'echo hash_file("sha256", "docker/public-core/runtime.php");')" || return 1
  [ "${runtime}" = "${PUBLIC_CORE_RUNTIME_SHA256}" ] || return 1
}

# Only the exact image's embedded source/helper can admit the externally pinned CURRENT descriptor.
intake_public_core_current_candidate() {
  local image_ref="$1" release_sha="$2" revision="$3" descriptor_sha="$4" result
  [[ "${image_ref}" = ghcr.io/kamilgaraev/proexpert/prohelper@sha256:* ]] \
    && [[ "${image_ref##*@}" =~ ^sha256:[0-9a-f]{64}$ ]] || return 1
  public_core_current_control_guard "${release_sha}" "${image_ref##*@}" "${revision}" "${descriptor_sha}" || return 1
  verify_public_core_candidate_image "${image_ref}" "${release_sha}" || return 1
  result="$(timeout --signal=TERM --kill-after=5s 20s docker run --rm --network none --read-only --cap-drop ALL --cap-add CHOWN --security-opt no-new-privileges --user 0:0 \
    --mount type=bind,source=/etc/most/public-core,target=/etc/most/public-core --entrypoint php "${image_ref}" \
    docker/public-core/runtime.php intake-current-candidate "${release_sha}" "${image_ref##*@}" "${revision}" "${descriptor_sha}")" || return 1
  # No provider/output payload is echoed; acknowledgement is independently pinned.
  python3 - "${result}" "${release_sha}" "${image_ref##*@}" "${revision}" "${descriptor_sha}" <<'PYACK' || return 1
import json,re,sys
try:
    v=json.loads(sys.argv[1])
    assert set(v)=={'status','revision','candidateSha256','releaseSha','imageDigest','profileFingerprint','publicationReady'}
    assert v['status']=='candidate_staged' and type(v['revision']) is int and v['revision']==int(sys.argv[4]) and v['candidateSha256']==sys.argv[5]
    assert v['releaseSha']==sys.argv[2] and v['imageDigest']==sys.argv[3] and v['publicationReady'] is False
    assert re.fullmatch(r'[0-9a-f]{64}',v['profileFingerprint'])
except Exception:
    sys.stderr.write('public-core: candidate_ack_unavailable\n');sys.exit(1)
PYACK
  MOST_PUBLIC_CORE_CURRENT_REQUIRED=true
  MOST_PUBLIC_CORE_CURRENT_REVISION="${revision}"; MOST_PUBLIC_CORE_CURRENT_SHA256="${descriptor_sha}"
  MOST_PUBLIC_CORE_CURRENT_RELEASE="${release_sha}"; MOST_PUBLIC_CORE_CURRENT_IMAGE="${image_ref##*@}"
  public_core_current_control_guard "${release_sha}" "${image_ref##*@}" "${revision}" "${descriptor_sha}"
}

stage_public_core_current_publication_inputs() {
  # Producer writes the real checked bundle through the existing serialized custodian;
  # this importer validates it in place. Missing output cannot be invented from observed PIDs.
  public_core_current_control_guard "$2" "${1##*@}" "$3" "$4" publication
}

verify_public_core_current_candidate() {
  [ "${MOST_PUBLIC_CORE_CURRENT_REQUIRED:-false}" != true ] || \
    public_core_current_control_guard "${MOST_PUBLIC_CORE_CURRENT_RELEASE}" "${MOST_PUBLIC_CORE_CURRENT_IMAGE}" "${MOST_PUBLIC_CORE_CURRENT_REVISION}" "${MOST_PUBLIC_CORE_CURRENT_SHA256}" "${MOST_PUBLIC_CORE_CURRENT_PHASE:-candidate}"
}

# Every Docker status is checked outside test/command-substitution comparisons.
verify_public_core_staged_containers() {
  [ -n "${MOST_PUBLIC_CORE_STAGED_API:-}" ] || return 0
  local role ids observed lifetime now generation source
  now="$(public_core_monotonic_ns)" || return 1
  [[ "${now}" =~ ^[0-9]{1,19}$ ]] && [ "${now}" -ge "${MOST_PUBLIC_CORE_STAGED_AT}" ] \
    && [ "$((now - MOST_PUBLIC_CORE_STAGED_AT))" -lt 30000000000 ] || return 1
  source="$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "${MOST_PUBLIC_CORE_STAGED_IMAGE_REF}")" || return 1
  [ "${source}" = "${MOST_PUBLIC_CORE_STAGED_RELEASE}" ] || return 1
  for role in api processor gateway; do
    local service="${role}"
    [ "${role}" = api ] || service="public-core-${role}"
    ids="$(docker ps -q --no-trunc --filter label=com.docker.compose.project=prohelper --filter "label=com.docker.compose.service=${service}")" || return 1
    [ "${ids}" = "${MOST_PUBLIC_CORE_STAGED_IDS[$role]}" ] || return 1
    observed="$(docker inspect --format '{{.State.Running}}:{{.State.Pid}}:{{.Image}}' "${ids}")" || return 1
    [ "${observed}" = "${MOST_PUBLIC_CORE_STAGED_STATES[$role]}" ] || return 1
    observed="$(docker inspect --format '{{.HostConfig.PidMode}}' "${ids}")" || return 1
    [ "${observed}" = "${MOST_PUBLIC_CORE_STAGED_NAMESPACES[$role]}" ] || return 1
    observed="$(docker inspect --format '{{.State.Restarting}}:{{.RestartCount}}:{{.State.StartedAt}}' "${ids}")" || return 1
    [ "${observed}" = "${MOST_PUBLIC_CORE_STAGED_STARTS[$role]}" ] || return 1
    lifetime="$(public_core_process_lifetime "${MOST_PUBLIC_CORE_STAGED_PIDS[$role]}")" || return 1
    [ "${lifetime}" = "${MOST_PUBLIC_CORE_STAGED_LIFETIMES[$role]}" ] || return 1
  done
  return 0
}

verify_public_core_staged_tuple() {
  verify_public_core_current_candidate || return 1
  if [ -z "${MOST_PUBLIC_CORE_STAGED_API:-}" ]; then
    [ "${MOST_PUBLIC_CORE_CURRENT_REQUIRED:-false}" != true ]; return $?
  fi
  local generation
  verify_public_core_staged_containers || return 1
  generation="$(public_core_generation_identity "${MOST_PUBLIC_CORE_STAGED_RELEASE}" "${MOST_PUBLIC_CORE_STAGED_IMAGE_REF##*@}")" || return 1
  [ "${generation}" = "${MOST_PUBLIC_CORE_STAGED_GENERATION}" ] || return 1
}

# After drain/checks and checked CURRENT admission only; no acquisition is performed here.
stage_public_core_approved_runtime() {
  local image_ref="$1" release_sha="$2" api_id expected state role ids observed pid namespace lifetime source
  verify_public_core_current_candidate || return 1
  MOST_PUBLIC_CORE_STAGED_API=''
  declare -gA MOST_PUBLIC_CORE_STAGED_IDS=() MOST_PUBLIC_CORE_STAGED_STATES=() MOST_PUBLIC_CORE_STAGED_NAMESPACES=() MOST_PUBLIC_CORE_STAGED_STARTS=() MOST_PUBLIC_CORE_STAGED_PIDS=() MOST_PUBLIC_CORE_STAGED_LIFETIMES=()
  state="$(python3 -c 'import json; print(json.load(open("/etc/most/public-core/gateway/runtime.json"))["activation"])')" || return 1
  case "${state}" in inactive) [ "${MOST_PUBLIC_CORE_CURRENT_REQUIRED:-false}" != true ]; return $? ;; approved) ;; *) return 1 ;; esac
  [[ "${image_ref}" =~ @sha256:[0-9a-f]{64}$ ]] && [[ "${release_sha}" =~ ^[0-9a-f]{40}$ ]] || return 1
  expected="$(docker image inspect --format '{{.Id}}' "${image_ref}")" || return 1
  [[ "${expected}" =~ ^sha256:[0-9a-f]{64}$ ]] || return 1
  source="$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "${image_ref}")" || return 1
  [ "${source}" = "${release_sha}" ] || return 1
  MOST_PUBLIC_CORE_STAGED_AT="$(public_core_monotonic_ns)" || return 1
  [[ "${MOST_PUBLIC_CORE_STAGED_AT}" =~ ^[0-9]{1,19}$ ]] || return 1
  MOST_IMAGE_REF="${image_ref}" docker compose up -d --no-deps --force-recreate api || return 1
  api_id="$(docker ps -q --no-trunc --filter label=com.docker.compose.project=prohelper --filter label=com.docker.compose.service=api)" || return 1
  [[ "${api_id}" =~ ^[0-9a-f]{64}$ ]] || return 1
  MOST_IMAGE_REF="${image_ref}" docker compose -f docker-compose.yml -f - --profile public-core up -d --no-deps public-core-processor public-core-gateway <<'YAML' || return 1
services:
  public-core-processor:
    command: [php, docker/public-core/runtime.php, parked-processor]
  public-core-gateway:
    command: [php, docker/public-core/runtime.php, parked-gateway]
YAML
  for role in api processor gateway; do
    local service="${role}"
    [ "${role}" = api ] || service="public-core-${role}"
    ids="$(docker ps -q --no-trunc --filter label=com.docker.compose.project=prohelper --filter "label=com.docker.compose.service=${service}")" || return 1
    [[ "${ids}" =~ ^[0-9a-f]{64}$ ]] || return 1
    [ "${role}" != api ] || [ "${ids}" = "${api_id}" ] || return 1
    pid="$(docker inspect --format '{{.State.Pid}}' "${ids}")" || return 1
    [[ "${pid}" =~ ^[1-9][0-9]*$ ]] || return 1
    observed="$(docker inspect --format '{{.State.Running}}:{{.State.Pid}}:{{.Image}}' "${ids}")" || return 1
    [ "${observed}" = "true:${pid}:${expected}" ] || return 1
    MOST_PUBLIC_CORE_STAGED_STATES[$role]="${observed}"
    namespace="$(docker inspect --format '{{.HostConfig.PidMode}}' "${ids}")" || return 1
    if [ "${role}" = api ]; then [ -z "${namespace}" ] || return 1; else [ "${namespace}" = "container:${api_id}" ] || return 1; fi
    observed="$(docker inspect --format '{{.State.Restarting}}:{{.RestartCount}}:{{.State.StartedAt}}' "${ids}")" || return 1
    [[ "${observed}" =~ ^false:[0-9]+:.+ ]] || return 1
    lifetime="$(public_core_process_lifetime "${pid}")" || return 1
    [[ "${lifetime}" =~ ^[0-9a-f]{64}$ ]] || return 1
    MOST_PUBLIC_CORE_STAGED_IDS[$role]="${ids}"; MOST_PUBLIC_CORE_STAGED_PIDS[$role]="${pid}"
    MOST_PUBLIC_CORE_STAGED_NAMESPACES[$role]="${namespace}"; MOST_PUBLIC_CORE_STAGED_STARTS[$role]="${observed}"
    MOST_PUBLIC_CORE_STAGED_LIFETIMES[$role]="${lifetime}"
  done
  MOST_PUBLIC_CORE_STAGED_IMAGE_REF="${image_ref}"; MOST_PUBLIC_CORE_STAGED_RELEASE="${release_sha}"
  MOST_PUBLIC_CORE_STAGED_API="${api_id}"
  if ! verify_public_core_staged_containers \
    || ! observe_public_core_parked_peers "${image_ref}" "${release_sha}" \
    || ! verify_public_core_staged_containers \
    || ! stage_public_core_current_publication_inputs "${image_ref}" "${release_sha}" "${MOST_PUBLIC_CORE_CURRENT_REVISION}" "${MOST_PUBLIC_CORE_CURRENT_SHA256}"     || ! prepare_public_core_projections "${image_ref}" "${release_sha}"; then
    MOST_PUBLIC_CORE_STAGED_API=''; return 1
  fi
  MOST_PUBLIC_CORE_CURRENT_PHASE=publication
  MOST_PUBLIC_CORE_STAGED_GENERATION="$(public_core_generation_identity "${release_sha}" "${image_ref##*@}")" || { MOST_PUBLIC_CORE_STAGED_API=''; return 1; }
  [[ "${MOST_PUBLIC_CORE_STAGED_GENERATION}" =~ ^[0-9a-f]{64}$ ]] || return 1
  MOST_PUBLIC_CORE_STAGED_IMAGE_REF="${image_ref}"; MOST_PUBLIC_CORE_STAGED_RELEASE="${release_sha}"
  MOST_PUBLIC_CORE_STAGED_API="${api_id}"
  verify_public_core_staged_tuple || { MOST_PUBLIC_CORE_STAGED_API=''; return 1; }
}

resume_public_core_backend_writers() {
  local image_ref="$1" backend_services="$2" resume_services="${2}" service
  if [ -n "${MOST_PUBLIC_CORE_STAGED_API:-}" ]; then
    [ "${image_ref}" = "${MOST_PUBLIC_CORE_STAGED_IMAGE_REF}" ] || return 1
    verify_public_core_staged_tuple || return 1
    resume_services=''
    for service in "${MOST_COMPOSE_WRITER_SERVICES[@]}"; do
      case "${service}" in api|public-core-processor|public-core-gateway) ;; *) resume_services="${resume_services} ${service}" ;; esac
    done
    MOST_IMAGE_REF="${image_ref}" docker compose up -d --no-deps --force-recreate ${resume_services} || return 1
    verify_public_core_staged_tuple || return 1
  else
    [ "${MOST_PUBLIC_CORE_CURRENT_REQUIRED:-false}" != true ] || return 1
    MOST_IMAGE_REF="${image_ref}" docker compose up -d --force-recreate --remove-orphans ${backend_services} || return 1
  fi
}

# Managed lifecycle only. Keep the old deny barrier until roles and endpoints are gone.
# Out-of-band privileged table deletion is outside this interface and remains unqualified.
quiesce_public_core_gateway_route() {
  local role container_id running host_pid network_id bridge project network_role member_id discovered members inspected matches=0
  local -a ids=()
  for role in "${MOST_PUBLIC_CORE_SERVICES[@]}"; do
    discovered="$(docker ps -aq --filter label=com.docker.compose.project=prohelper --filter "label=com.docker.compose.service=${role}")" || return 1
    for container_id in ${discovered}; do
      [[ "${container_id}" =~ ^[0-9a-f]{12,64}$ ]] || return 1
      inspected="$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "${container_id}")" || return 1
      [ "${inspected}" = prohelper ] || return 1
      inspected="$(docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}}' "${container_id}")" || return 1
      [ "${inspected}" = "${role}" ] || return 1
      host_pid="$(docker inspect --format '{{.State.Pid}}' "${container_id}")" || return 1
      [[ "${host_pid}" =~ ^[0-9]+$ ]] || return 1
      docker stop --time 30 "${container_id}" >/dev/null || return 1
      running="$(docker inspect --format '{{.State.Running}}:{{.State.Pid}}:{{.HostConfig.RestartPolicy.Name}}' "${container_id}")" || return 1
      [ "${running}" = 'false:0:no' ] || return 1
      # A still-live original role PID makes removal unavailable; no table teardown.
      if [ "${host_pid}" -gt 0 ] && [ -e "/proc/${host_pid}" ]; then return 1; fi
      ids+=("${container_id}")
    done
  done
  # Only the designated Gateway bridge may be disconnected. Foreign/colliding networks abort.
  discovered="$(docker network ls --format '{{.ID}}')" || return 1
  for network_id in ${discovered}; do
    bridge="$(docker network inspect --format '{{index .Options "com.docker.network.bridge.name"}}' "${network_id}")" || return 1
    [ "${bridge}" = br-most-pc ] || continue
    project="$(docker network inspect --format '{{index .Labels "com.docker.compose.project"}}' "${network_id}")" || return 1
    network_role="$(docker network inspect --format '{{index .Labels "com.docker.compose.network"}}' "${network_id}")" || return 1
    [ "${project}" = prohelper ] && [ "${network_role}" = public-core-gateway ] || return 1
    matches=$((matches + 1)); [ "${matches}" -eq 1 ] || return 1
    members="$(docker network inspect --format '{{range $id, $member := .Containers}}{{$id}} {{end}}' "${network_id}")" || return 1
    for member_id in ${members}; do
      [[ "${member_id}" =~ ^[0-9a-f]{12,64}$ ]] || return 1
      inspected="$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "${member_id}")" || return 1
      [ "${inspected}" = prohelper ] || return 1
      inspected="$(docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}}' "${member_id}")" || return 1
      [ "${inspected}" = public-core-gateway ] || return 1
      inspected="$(docker inspect --format '{{.State.Running}}:{{.State.Pid}}:{{.HostConfig.RestartPolicy.Name}}' "${member_id}")" || return 1
      [ "${inspected}" = 'false:0:no' ] || return 1
      docker network disconnect "${network_id}" "${member_id}" || return 1
    done
    inspected="$(docker network inspect --format '{{range $id, $member := .Containers}}{{$id}} {{end}}' "${network_id}")" || return 1
    [ -z "${inspected}" ] || return 1
  done
  if ip link show br-most-pc >/dev/null 2>&1; then [ "${matches}" -eq 1 ] || return 1; fi
  # Verify stopped roles have no endpoint on any network before invalidating readers.
  for container_id in "${ids[@]}"; do
    inspected="$(docker inspect --format '{{.State.Running}}:{{.State.Pid}}:{{.HostConfig.RestartPolicy.Name}}' "${container_id}")" || return 1
    [ "${inspected}" = 'false:0:no' ] || return 1
    role="$(docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}}' "${container_id}")" || return 1
    if [ "${role}" = public-core-processor ]; then
      inspected="$(docker inspect --format '{{.HostConfig.NetworkMode}}' "${container_id}")" || return 1
      [ "${inspected}" = none ] || return 1
    else
      inspected="$(docker inspect --format '{{range .NetworkSettings.Networks}}{{.EndpointID}}{{.IPAddress}}{{.GlobalIPv6Address}}{{end}}' "${container_id}")" || return 1
      [ -z "${inspected}" ] || return 1
    fi
  done
}

invalidate_public_core_projections() {
  local role gid directory file temporary
  for role in app processor gateway; do
    case "${role}" in app) gid=82 ;; processor) gid=41002 ;; gateway) gid=41003 ;; esac
    directory="/etc/most/public-core/${role}"
    [ ! -L "${directory}" ] && [ "$(stat -c '%u:%g:%a' "${directory}")" = "0:${gid}:750" ] || return 1
    file="${directory}/generation.json"
    [ ! -L "${file}" ] || return 1
    if [ -e "${file}" ]; then
      [ -f "${file}" ] && [ "$(stat -c '%u:%g:%a' "${file}")" = "0:${gid}:640" ] || return 1
      rm -- "${file}" || return 1
    fi
    # App/Processor code binding is held unavailable; no old active rollback.
    if [ "${role}" != gateway ]; then
      file="${directory}/bootstrap.php"
      [ ! -L "${file}" ] && [ "$(stat -c '%u:%g:%a' "${file}")" = "0:${gid}:640" ] || return 1
      temporary="$(mktemp "${directory}/.inactive.XXXXXXXX")" || return 1
      printf '<?php return null;\n' > "${temporary}"
      chown "root:${gid}" "${temporary}" && chmod 0640 "${temporary}" \
        && mv -T -- "${temporary}" "${file}" || { rm -f -- "${temporary}"; return 1; }
    fi
  done
  # The Gateway manifest is also revoked, even if later provisioning fails.
  file=/etc/most/public-core/gateway/runtime.json
  [ ! -L "${file}" ] && [ "$(stat -c '%u:%g:%a' "${file}")" = '0:41003:640' ] || return 1
  temporary="$(mktemp /etc/most/public-core/gateway/.inactive.XXXXXXXX)" || return 1
  install -o root -g 41003 -m 0640 deploy/public-core-runtime.json.example "${temporary}" \
    && mv -T -- "${temporary}" "${file}" || { rm -f -- "${temporary}"; return 1; }
}

# Explicit future parked-start preparation only; not called by default inactive deployment.
# Reads only container labels/image/PIDs and host kernel metadata, never Config.Env or keys.
observe_public_core_parked_peers() {
  local image_ref="$1" release_sha="$2" image_digest="${1##*@}" image_id directory temporary role uid ids container_id host_pid service actual_image running inspected
  [[ "${image_ref}" =~ @sha256:[0-9a-f]{64}$ ]] && [[ "${release_sha}" =~ ^[0-9a-f]{40}$ ]] || return 1
  image_id="$(docker image inspect --format '{{.Id}}' "${image_ref}")" || return 1
  directory=/etc/most/public-core/control
  [ ! -L "${directory}" ] || return 1
  if [ ! -e "${directory}" ]; then install -d -o root -g root -m 0750 "${directory}"; fi
  [ "$(stat -c '%u:%g:%a' "${directory}")" = '0:0:750' ] || return 1
  temporary="$(mktemp "${directory}/.peers.XXXXXXXX")" || return 1
  for role in processor gateway; do
    case "${role}" in processor) uid=41002 ;; gateway) uid=41003 ;; esac
    ids="$(docker ps -q --no-trunc --filter label=com.docker.compose.project=prohelper --filter "label=com.docker.compose.service=public-core-${role}")" || { rm -f -- "${temporary}"; return 1; }
    [[ "${ids}" =~ ^[0-9a-f]{64}$ ]] || { rm -f -- "${temporary}"; return 1; }
    container_id="${ids}"
    host_pid="$(docker inspect --format '{{.State.Pid}}' "${container_id}")" || { rm -f -- "${temporary}"; return 1; }
    service="$(docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}}' "${container_id}")" || { rm -f -- "${temporary}"; return 1; }
    actual_image="$(docker inspect --format '{{.Image}}' "${container_id}")" || { rm -f -- "${temporary}"; return 1; }
    running="$(docker inspect --format '{{.State.Running}}:{{.HostConfig.RestartPolicy.Name}}' "${container_id}")" || { rm -f -- "${temporary}"; return 1; }
    [[ "${host_pid}" =~ ^[1-9][0-9]*$ ]] && [ "${service}" = "public-core-${role}" ] \
      && [ "${actual_image}" = "${image_id}" ] && [ "${running}" = 'true:no' ] || { rm -f -- "${temporary}"; return 1; }
    python3 - "${host_pid}" "${uid}" "${role}" "${container_id}" "${image_digest}" >> "${temporary}" <<'PYOBS' || { rm -f -- "${temporary}"; return 1; }
import sys,pathlib,re,json,hashlib
host,uid,role,cid,image=sys.argv[1:];uid=int(uid);proc=pathlib.Path('/proc')/host
before=(proc/'stat').read_text(); status=(proc/'status').read_text(); cmd=(proc/'cmdline').read_bytes()
assert b'docker/public-core/runtime.php\0parked-'+role.encode()+b'\0' in cmd
for field in ['Uid','Gid']:
 values=list(map(int,re.search(r'^'+field+r':\s+(.+)$',status,re.M).group(1).split()));assert values==[uid]*4
assert proc.stat().st_uid==uid
pids=list(map(int,re.search(r'^NSpid:\s+(.+)$',status,re.M).group(1).split()));assert pids[0]==int(host)
peer={'pid':pids[-1],'uid':uid,'gid':uid};start=before[before.rfind(')')+1:].split()[19]
boot=pathlib.Path('/proc/sys/kernel/random/boot_id').read_text().strip();assert boot and start.isdigit()
after=(proc/'stat').read_text();assert after[after.rfind(')')+1:].split()[19]==start
assert (proc/'cmdline').read_bytes()==cmd
again=(proc/'status').read_text()
for field in ['Uid','Gid','NSpid']:
 assert re.search(r'^'+field+r':\s+(.+)$',again,re.M).group(1)==re.search(r'^'+field+r':\s+(.+)$',status,re.M).group(1)
lifetime='ref_'+hashlib.sha256(json.dumps([peer,boot,start],separators=(',',':')).encode()).hexdigest()[:32]
print(json.dumps({'role':role,'observation':{'containerId':cid,'imageDigest':image,'service':'public-core-'+role,'hostPid':int(host),'peer':peer,'lifetimeRef':lifetime}},separators=(',',':')))
PYOBS
    inspected="$(docker inspect --format '{{.State.Running}}:{{.State.Pid}}:{{.Image}}' "${container_id}")" || { rm -f -- "${temporary}"; return 1; }
    [ "${inspected}" = "true:${host_pid}:${image_id}" ] || { rm -f -- "${temporary}"; return 1; }
  done
  local output
  output="$(mktemp "${directory}/.observed.XXXXXXXX")" || { rm -f -- "${temporary}"; return 1; }
  python3 - "${temporary}" "${release_sha}" "${image_digest}" > "${output}" <<'PYOBS'
import sys,json,time
rows=[json.loads(x) for x in open(sys.argv[1])];assert [x['role'] for x in rows]==['processor','gateway']
print(json.dumps({'schemaVersion':'public-core-observed-peers/1','releaseSha':sys.argv[2],'imageDigest':sys.argv[3],'observedAt':int(time.time()),'peers':{x['role']:x['observation'] for x in rows}},separators=(',',':')))
PYOBS
  local result=$?
  rm -f -- "${temporary}"
  [ "${result}" = 0 ] && [ ! -L "${directory}/observed-peers.json" ] \
    && chown root:root "${output}" && chmod 0640 "${output}" \
    && mv -T -- "${output}" "${directory}/observed-peers.json" || { rm -f -- "${output}"; return 1; }
}

# Offline compiler, default inactive. Approved inputs need real parked-role observations
# and accepted control receipts supplied by the release owner, never generated here.
prepare_public_core_projections() {
  local image_ref="$1" release_sha="$2" image_digest="${1##*@}"
  [[ "${image_ref}" =~ @sha256:[0-9a-f]{64}$ ]] && [[ "${release_sha}" =~ ^[0-9a-f]{40}$ ]] || return 1
  local state api_id actual_image expected_image
  local -a pid_options=()
  state="$(python3 -c 'import json; print(json.load(open("/etc/most/public-core/gateway/runtime.json"))["activation"])')" || return 1
  case "${state}" in
    inactive) [ "${MOST_PUBLIC_CORE_CURRENT_REQUIRED:-false}" != true ]; return $? ;;
    approved)
      api_id="$(docker ps -q --no-trunc --filter label=com.docker.compose.project=prohelper --filter label=com.docker.compose.service=api)" || return 1
      [[ "${api_id}" =~ ^[0-9a-f]{64}$ ]] || return 1
      actual_image="$(docker inspect --format '{{.Image}}' "${api_id}")" || return 1
      expected_image="$(docker image inspect --format '{{.Id}}' "${image_ref}")" || return 1
      [ "${actual_image}" = "${expected_image}" ] || return 1
      pid_options=(--pid "container:${api_id}") ;;
    *) return 1 ;;
  esac
  timeout --signal=TERM --kill-after=5s 20s docker run --rm "${pid_options[@]}" --network none --read-only --cap-drop ALL --cap-add CHOWN \
    --security-opt no-new-privileges --user 0:0 \
    --mount type=bind,source=/etc/most/public-core,target=/etc/most/public-core \
    --tmpfs /tmp:rw,noexec,nosuid,size=16777216,mode=1777 \
    --entrypoint php "${image_ref}" docker/public-core/runtime.php publish-projections "${release_sha}" "${image_digest}" "${MOST_PUBLIC_CORE_CURRENT_REVISION}" "${MOST_PUBLIC_CORE_CURRENT_SHA256}"
}

# Empty-set deny policy: no DNS/provider calls and no modification of Docker tables.
# Normal Compose still has an internal bridge. A usable egress grant is a separate,
# checked release input; loading this artifact never grants network readiness.
prepare_public_core_deny_policy() {
  quiesce_public_core_gateway_route || return 1
  command -v nft >/dev/null 2>&1 || return 1
  local batch existing network_id bridge_name project_name role_name member_id discovered members member_project member_state matches=0
  # Refuse name collisions before touching even our own deny table.
  discovered="$(docker network ls --format '{{.ID}}')" || return 1
  for network_id in ${discovered}; do
    bridge_name="$(docker network inspect --format '{{index .Options "com.docker.network.bridge.name"}}' "${network_id}")" || return 1
    [ "${bridge_name}" = br-most-pc ] || continue
    project_name="$(docker network inspect --format '{{index .Labels "com.docker.compose.project"}}' "${network_id}")" || return 1
    role_name="$(docker network inspect --format '{{index .Labels "com.docker.compose.network"}}' "${network_id}")" || return 1
    [ "${project_name}" = prohelper ] && [ "${role_name}" = public-core-gateway ] || return 1
    matches=$((matches + 1))
    [ "${matches}" -eq 1 ] || return 1
    members="$(docker network inspect --format '{{range $id, $member := .Containers}}{{$id}} {{end}}' "${network_id}")" || return 1
    for member_id in ${members}; do
      [[ "${member_id}" =~ ^[0-9a-f]{12,64}$ ]] || return 1
      role_name="$(docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}}' "${member_id}")" || return 1
      [ "${role_name}" = public-core-gateway ] || return 1
      member_project="$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "${member_id}")" || return 1
      member_state="$(docker inspect --format '{{.State.Running}}:{{.State.Pid}}:{{.HostConfig.RestartPolicy.Name}}' "${member_id}")" || return 1
      [ "${member_project}" = prohelper ] && [ "${member_state}" = 'false:0:no' ] || return 1
    done
    # A member reappearing after quiesce is a changed route, not permission to refresh.
    [ -z "${members}" ] || return 1
  done
  if ip link show br-most-pc >/dev/null 2>&1; then [ "${matches}" -eq 1 ] || return 1; fi
  existing="$(nft list table inet most_public_core 2>/dev/null || true)"
  if [ -n "${existing}" ]; then
    grep -Fq 'comment "most-public-core:gateway-only/1"' <<< "${existing}" || return 1
  fi
  # All Docker discovery and ownership checks succeeded before reader mutation.
  invalidate_public_core_projections || return 1
  batch="$(mktemp /etc/most/public-core/.nft.XXXXXXXX)" || return 1
  if [ -n "${existing}" ]; then printf 'delete table inet most_public_core\n' > "${batch}"; fi
  cat docker/public-core/egress-policy.nft >> "${batch}" || { rm -f -- "${batch}"; return 1; }
  nft -c -f "${batch}" && nft -f "${batch}" || { rm -f -- "${batch}"; return 1; }
  rm -f -- "${batch}"
  # Read back only our table. Empty provider set means existing flows lose permission too.
  existing="$(nft list table inet most_public_core)" || return 1
  grep -Fq 'comment "most-public-core:gateway-only/1"' <<< "${existing}"     && grep -Fq 'br-most-pc' <<< "${existing}" || return 1
  local provider_set
  provider_set="$(nft -j list set inet most_public_core provider4)" || return 1
  python3 -c 'import json,sys; n=json.load(sys.stdin)["nftables"]; s=[x["set"] for x in n if "set" in x]; sys.exit(0 if len(s)==1 and not s[0].get("elem") else 1)' <<< "${provider_set}"
}

MOST_SYSTEMD_WRITER_UNITS=(
  prohelper-octane.service
  prohelper-queue.service
  reverb.service
)

MOST_SUPERVISOR_WRITER_PROGRAM_PATTERN='^(most|prohelper|laravel-worker|horizon|scheduler|queue|artisan)([-_:].*)?$'

# BEGIN fixed input transport. Mirrored literally in the managed workflow BEFORE
# sourcing any extracted helper. Authority is the separately checked CI assignment
# and exact-main pins, never a supplied staging manifest or a verified status enum.
public_core_input_assignment_guard() {
  local release="$1" pins="$2" assignment="$3" accepted="$4" expected_pins="$5" digest
  [ "${MANAGED_REF:-}" = refs/heads/main ] && [ "${MANAGED_EVENT:-}" = workflow_dispatch ] || return 1
  case "${MANAGED_MODE:-}" in input-prepare|input-only) ;; *) return 1 ;; esac
  [[ "${release}" =~ ^[0-9a-f]{40}$ ]] && [ "${release}" = "${accepted}" ] && [ "${release}" = "${MANAGED_EXPECTED_SHA:-}" ] || return 1
  [[ "${assignment}" =~ ^ref_[0-9a-f]{32}$ ]] && [[ "${expected_pins}" =~ ^[0-9a-f]{64}$ ]] || return 1
  [[ "${pins}" =~ ^[0-9a-f]{64}(:[0-9a-f]{64}){7}$ ]] || return 1
  digest="$(printf '%s' "${pins}" | sha256sum)" || return 1
  [ "${digest%% *}" = "${expected_pins}" ] || return 1
}

public_core_input_image_identity() {
  local image="$1" release="$2" runtime="$3" id revision digests embedded
  [[ "${image}" =~ ^ghcr\.io/kamilgaraev/proexpert/prohelper@sha256:[0-9a-f]{64}$ ]] || return 1
  id="$(docker image inspect --format '{{.Id}}' "${image}" 2>/dev/null)" || return 1
  [[ "${id}" =~ ^sha256:[0-9a-f]{64}$ ]] || return 1
  revision="$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "${image}" 2>/dev/null)" || return 1
  [ "${revision}" = "${release}" ] || return 1
  # Read only the expected same-repository digest, never unrelated image aliases.
  digests="$(docker image inspect --format "{{range .RepoDigests}}{{if eq . \"${image}\"}}{{print .}}{{end}}{{end}}" "${image}" 2>/dev/null)" || return 1
  [ "${digests}" = "${image}" ] || return 1
  embedded="$(timeout --signal=TERM --kill-after=5s 45s docker run --pull never --rm --network none --read-only --cap-drop ALL \
    --security-opt no-new-privileges --user 0:0 --log-driver none --entrypoint php "${image}" -r \
    '$r=json_decode(file_get_contents("/etc/most/release.json"),true,8,JSON_THROW_ON_ERROR);echo ($r["sha"]??"")."|".hash_file("sha256","docker/public-core/runtime.php");' 2>/dev/null)" || return 1
  [ "${embedded}" = "${release}|${runtime}" ] || return 1
  printf '%s' "${id}"
}

# Fixed, flat stage: five source files plus our bounded tuple, no caller paths.
# Stable reads/ancestor ownership and full directory equality reject foreign or
# mutable files. The tuple is compared to external CI pins on EVERY use.
public_core_input_stage_check() {
  python3 - "$@" <<'PYSTAGE'
import sys,os,pathlib,stat,hashlib,json,re
try:
 mode,release,image,image_id,pins,assignment=sys.argv[1:]
 assert mode in ['write','read','parent'] and re.fullmatch('[0-9a-f]{40}',release)
 assert re.fullmatch('ghcr.io/kamilgaraev/proexpert/prohelper@sha256:[0-9a-f]{64}',image)
 assert re.fullmatch('sha256:[0-9a-f]{64}',image_id) and re.fullmatch('ref_[0-9a-f]{32}',assignment)
 hashes=pins.split(':'); assert len(hashes)==8 and all(re.fullmatch('[0-9a-f]{64}',v) for v in hashes)
 root=pathlib.Path('/etc/most/public-core/input-source'); stage=root/release
 def stable(s):return (s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
 def directory(p,exact=False):
  s=p.lstat(); assert stat.S_ISDIR(s.st_mode) and s.st_uid==0 and s.st_gid==0 and not s.st_mode&0o022
  if exact:assert stat.S_IMODE(s.st_mode)==0o700
  return stable(s)
 for a in [pathlib.Path('/'),pathlib.Path('/etc'),pathlib.Path('/etc/most'),pathlib.Path('/etc/most/public-core'),root]:directory(a)
 if mode=='parent':sys.exit(0)
 initial=directory(stage,True)
 names=['allowlist.sh','runtime.php','config.php','policy.php','provider.php']
 assert set(os.listdir(stage))==set(names+(['source.json'] if mode=='read' else []))
 def read(name,limit):
  path=stage/name; s=path.lstat(); assert stat.S_ISREG(s.st_mode) and s.st_uid==0 and s.st_gid==0 and stat.S_IMODE(s.st_mode)==0o600 and 0<s.st_size<=limit
  fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
  with os.fdopen(fd,'rb') as f:
   assert stable(os.fstat(f.fileno()))==stable(s); raw=f.read(limit+1); assert stable(os.fstat(f.fileno()))==stable(s)
  assert len(raw)==s.st_size and stable(path.lstat())==stable(s)
  return raw
 for name,expected in zip(names,hashes[:5]):assert hashlib.sha256(read(name,2*1024*1024)).hexdigest()==expected
 tuple={'schemaVersion':'public-core-input-source/1','sourceSha':release,'imageRef':image,'imageId':image_id,'assignmentRef':assignment,'sourcePins':hashes}
 if mode=='read':
  def unique(pairs):
   d={}
   for k,v in pairs:
    assert k not in d; d[k]=v
   return d
  assert json.loads(read('source.json',4096),object_pairs_hook=unique)==tuple
 else:
  raw=json.dumps(tuple,separators=(',',':')).encode(); assert len(raw)<=4096
  fd=os.open(stage/'source.json',os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
  with os.fdopen(fd,'wb') as f:f.write(raw);f.flush();os.fsync(f.fileno())
 # Directory changes only by our exclusive manifest creation in write mode.
 if mode=='read':assert directory(stage,True)==initial
 for name,expected in zip(names,hashes[:5]):assert hashlib.sha256(read(name,2*1024*1024)).hexdigest()==expected
 print(hashlib.sha256(json.dumps(tuple,separators=(',',':')).encode()).hexdigest())
except Exception:sys.exit(1)
PYSTAGE
}

validate_public_core_input_source() {
  local image="$1" release="$2" pins="$3" assignment="$4" accepted="$5" expected_pins="$6" image_id
  public_core_input_assignment_guard "${release}" "${pins}" "${assignment}" "${accepted}" "${expected_pins}" || return 1
  local helper runtime config policy provider workflow dockerfile ignore
  IFS=: read -r helper runtime config policy provider workflow dockerfile ignore <<< "${pins}"
  image_id="$(public_core_input_image_identity "${image}" "${release}" "${runtime}")" || return 1
  public_core_input_stage_check read "${release}" "${image}" "${image_id}" "${pins}" "${assignment}" || return 1
}

prepare_public_core_input_source() (
  local image="$1" release="$2" pins="$3" assignment="$4" accepted="$5" expected_pins="$6"
  local helper runtime config policy provider workflow dockerfile ignore image_id cid='' owned_stage=false owned_parent=false stage stage_identity='' parent_identity='' observed hash output
  public_core_input_assignment_guard "${release}" "${pins}" "${assignment}" "${accepted}" "${expected_pins}" || return 1
  [ "${MANAGED_MODE}" = input-prepare ] || return 1
  [[ "${image}" =~ ^ghcr\.io/kamilgaraev/proexpert/prohelper@sha256:[0-9a-f]{64}$ ]] || return 1
  IFS=: read -r helper runtime config policy provider workflow dockerfile ignore <<< "${pins}"
  stage="/etc/most/public-core/input-source/${release}"
  # Root custodian only. Never create/repair foreign ancestors or reuse a stage.
  observed="$(id -u)" || return 1
  [ "${observed}" = 0 ] || return 1
  cleanup_input_source() {
    local code=$? cleanup_failed=false
    trap - EXIT
    if [ -n "${cid}" ]; then docker rm -- "${cid}" >/dev/null 2>&1 || cleanup_failed=true; fi
    if [ "${code}" -ne 0 ] && [ "${owned_stage}" = true ]; then
      local current_identity
      current_identity="$(stat -c '%d:%i' -- "${stage}")" || exit 1
      [ ! -L "${stage}" ] && [ "${current_identity}" = "${stage_identity}" ] || exit 1
      rm -f -- "${stage}/allowlist.sh" "${stage}/runtime.php" "${stage}/config.php" "${stage}/policy.php" "${stage}/provider.php" "${stage}/source.json" || cleanup_failed=true
      rmdir -- "${stage}" || cleanup_failed=true
    fi
    if [ "${code}" -ne 0 ] && [ "${owned_parent}" = true ]; then
      local current_parent
      current_parent="$(stat -c '%d:%i' -- /etc/most/public-core/input-source)" || exit 1
      [ ! -L /etc/most/public-core/input-source ] && [ "${current_parent}" = "${parent_identity}" ] || exit 1
      rmdir -- /etc/most/public-core/input-source || cleanup_failed=true
    fi
    if [ "${cleanup_failed}" = true ]; then exit 1; fi
    exit "${code}"
  }
  trap cleanup_input_source EXIT
  if [ ! -e /etc/most/public-core/input-source ] && [ ! -L /etc/most/public-core/input-source ]; then
    mkdir -m 0700 -- /etc/most/public-core/input-source || return 1
    owned_parent=true
    parent_identity="$(stat -c '%d:%i' -- /etc/most/public-core/input-source)" || return 1
  fi
  public_core_input_stage_check parent "${release}" "${image}" "sha256:$(printf '%064d' 0)" "${pins}" "${assignment}" >/dev/null || return 1
  [ ! -e "${stage}" ] && [ ! -L "${stage}" ] || return 1
  docker pull "${image}" >/dev/null 2>&1 || return 1
  image_id="$(public_core_input_image_identity "${image}" "${release}" "${runtime}")" || return 1
  mkdir -m 0700 -- "${stage}" || return 1
  owned_stage=true
  stage_identity="$(stat -c '%d:%i' -- "${stage}")" || return 1
  cid="$(docker create --network none --read-only --cap-drop ALL --security-opt no-new-privileges \
    --label "most.input-preparation=${assignment}" --entrypoint /bin/true "${image}" 2>/dev/null)" || { cid=''; return 1; }
  [[ "${cid}" =~ ^[0-9a-f]{64}$ ]] || { cid=''; return 1; }
  observed="$(docker inspect --format '{{.State.Running}}|{{.State.Pid}}|{{.Image}}|{{index .Config.Labels "most.input-preparation"}}' "${cid}" 2>/dev/null)" || return 1
  [ "${observed}" = "false|0|${image_id}|${assignment}" ] || return 1
  # No loop over supplied source names: exactly the five agreed literal copies.
  docker cp "${cid}:/var/www/html/deploy/backend-runtime-allowlist.sh" "${stage}/allowlist.sh" >/dev/null 2>&1 || return 1
  docker cp "${cid}:/var/www/html/docker/public-core/runtime.php" "${stage}/runtime.php" >/dev/null 2>&1 || return 1
  docker cp "${cid}:/var/www/html/app/BusinessModules/Features/AIAssistant/config/ai-assistant.php" "${stage}/config.php" >/dev/null 2>&1 || return 1
  docker cp "${cid}:/var/www/html/app/Support/AI/LunaModelPolicy.php" "${stage}/policy.php" >/dev/null 2>&1 || return 1
  docker cp "${cid}:/var/www/html/app/BusinessModules/Features/AIAssistant/Services/LLM/TimewebProvider.php" "${stage}/provider.php" >/dev/null 2>&1 || return 1
  local name expected
  for name in allowlist.sh runtime.php config.php policy.php provider.php; do
    [ -f "${stage}/${name}" ] && [ ! -L "${stage}/${name}" ] || return 1
    chmod 0600 -- "${stage}/${name}" || return 1
    case "${name}" in allowlist.sh) expected="$helper" ;; runtime.php) expected="$runtime" ;; config.php) expected="$config" ;; policy.php) expected="$policy" ;; provider.php) expected="$provider" ;; esac
    hash="$(sha256sum -- "${stage}/${name}")" || return 1
    [ "${hash%% *}" = "${expected}" ] || return 1
  done
  observed="$(docker inspect --format '{{.State.Running}}|{{.State.Pid}}|{{.Image}}|{{index .Config.Labels "most.input-preparation"}}' "${cid}" 2>/dev/null)" || return 1
  [ "${observed}" = "false|0|${image_id}|${assignment}" ] || return 1
  output="$(public_core_input_stage_check write "${release}" "${image}" "${image_id}" "${pins}" "${assignment}")" || return 1
  [[ "${output}" =~ ^[0-9a-f]{64}$ ]] || return 1
  validate_public_core_input_source "${image}" "${release}" "${pins}" "${assignment}" "${accepted}" "${expected_pins}" >/dev/null || return 1
  docker rm -- "${cid}" >/dev/null 2>&1 || return 1
  cid=''
  # No store read, helper sourcing or activation occurs in preparation.
  python3 - "${release}" "${image}" "${image_id}" "${pins}" "${output}" <<'PYOUTPUT'
import sys,json
release,image,image_id,pins,staging=sys.argv[1:]; h=pins.split(':')
print(json.dumps(dict(schemaVersion='public-core-input-preparation/1',sourceSha=release,workflowDigest=h[5],allowlistDigest=h[0],runtimeDigest=h[1],configDigest=h[2],policyDigest=h[3],providerDigest=h[4],dockerfileDigest=h[6],imageRef=image,imageId=image_id,ociRevision=release,embeddedSourceSha=release,stagingDigest=staging,preparationOnly=True,storeRead=False,providerCalled=False,runtimeActivated=False),separators=(',',':')))
PYOUTPUT
)
# END fixed input transport.
