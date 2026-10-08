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
  local image_ref="$1" release_sha="$2" role gid wrapper current expected_source source_sha
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
  for role in app processor; do
    case "${role}" in app) gid=82 ;; processor) gid=41002 ;; esac
    wrapper="/etc/most/public-core/${role}/bootstrap.php"
    [ ! -L "${wrapper}" ] && [ "$(stat -c '%u:%g:%a' "${wrapper}")" = "0:${gid}:640" ] || return 1
    # Replace only our exact inactive placeholder, never root-managed active code.
    expected_source="$(printf '<?php return null;\n' | sha256sum | cut -d' ' -f1)"
    current="$(sha256sum "${wrapper}" | cut -d' ' -f1)"
    [ "${current}" = "${expected_source}" ] || continue
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
    chown "root:${gid}" "${temporary}" && chmod 0640 "${temporary}" || { rm -f -- "${temporary}"; return 1; }
    # Refuse concurrent root mutation of the expected inactive wrapper.
    [ "$(sha256sum "${wrapper}" | cut -d' ' -f1)" = "${expected_source}" ] \
      && mv -T -- "${temporary}" "${wrapper}" || { rm -f -- "${temporary}"; return 1; }
  done
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
    inactive) ;;
    approved)
      api_id="$(docker ps -q --no-trunc --filter label=com.docker.compose.project=prohelper --filter label=com.docker.compose.service=api)" || return 1
      [[ "${api_id}" =~ ^[0-9a-f]{64}$ ]] || return 1
      actual_image="$(docker inspect --format '{{.Image}}' "${api_id}")" || return 1
      expected_image="$(docker image inspect --format '{{.Id}}' "${image_ref}")" || return 1
      [ "${actual_image}" = "${expected_image}" ] || return 1
      pid_options=(--pid "container:${api_id}") ;;
    *) return 1 ;;
  esac
  docker run --rm "${pid_options[@]}" --network none --read-only --cap-drop ALL --cap-add CHOWN \
    --security-opt no-new-privileges --user 0:0 \
    --mount type=bind,source=/etc/most/public-core,target=/etc/most/public-core \
    --tmpfs /tmp:rw,noexec,nosuid,size=16777216,mode=1777 \
    --entrypoint php "${image_ref}" docker/public-core/runtime.php publish-projections "${release_sha}" "${image_digest}"
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
