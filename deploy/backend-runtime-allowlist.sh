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

MOST_SYSTEMD_WRITER_UNITS=(
  prohelper-octane.service
  prohelper-queue.service
  reverb.service
)

MOST_SUPERVISOR_WRITER_PROGRAM_PATTERN='^(most|prohelper|laravel-worker|horizon|scheduler|queue|artisan)([-_:].*)?$'
