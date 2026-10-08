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

# Empty-set deny policy: no DNS/provider calls and no modification of Docker tables.
# Normal Compose still has an internal bridge. A usable egress grant is a separate,
# checked release input; loading this artifact never grants network readiness.
prepare_public_core_deny_policy() {
  command -v nft >/dev/null 2>&1 || return 1
  local batch existing network_id bridge_name project_name role_name member_id matches=0
  # Refuse name collisions before touching even our own deny table.
  for network_id in $(docker network ls --format '{{.ID}}'); do
    bridge_name="$(docker network inspect --format '{{index .Options "com.docker.network.bridge.name"}}' "${network_id}")" || return 1
    [ "${bridge_name}" = br-most-pc ] || continue
    project_name="$(docker network inspect --format '{{index .Labels "com.docker.compose.project"}}' "${network_id}")" || return 1
    role_name="$(docker network inspect --format '{{index .Labels "com.docker.compose.network"}}' "${network_id}")" || return 1
    [ "${project_name}" = prohelper ] && [ "${role_name}" = public-core-gateway ] || return 1
    matches=$((matches + 1))
    [ "${matches}" -eq 1 ] || return 1
    for member_id in $(docker network inspect --format '{{range $id, $member := .Containers}}{{$id}} {{end}}' "${network_id}"); do
      role_name="$(docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}}' "${member_id}")" || return 1
      [ "${role_name}" = public-core-gateway ] || return 1
    done
  done
  if ip link show br-most-pc >/dev/null 2>&1; then [ "${matches}" -eq 1 ] || return 1; fi
  existing="$(nft list table inet most_public_core 2>/dev/null || true)"
  if [ -n "${existing}" ]; then
    grep -Fq 'comment "most-public-core:gateway-only/1"' <<< "${existing}" || return 1
  fi
  batch="$(mktemp /etc/most/public-core/.nft.XXXXXXXX)" || return 1
  if [ -n "${existing}" ]; then printf 'delete table inet most_public_core\n' > "${batch}"; fi
  cat docker/public-core/egress-policy.nft >> "${batch}" || { rm -f -- "${batch}"; return 1; }
  nft -c -f "${batch}" && nft -f "${batch}" || { rm -f -- "${batch}"; return 1; }
  rm -f -- "${batch}"
}

MOST_SYSTEMD_WRITER_UNITS=(
  prohelper-octane.service
  prohelper-queue.service
  reverb.service
)

MOST_SUPERVISOR_WRITER_PROGRAM_PATTERN='^(most|prohelper|laravel-worker|horizon|scheduler|queue|artisan)([-_:].*)?$'
