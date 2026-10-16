#!/usr/bin/env bash

ENV_FILE="${ENV_FILE:-.env}"
ENV_TEMP_FILE=''
ENV_EXPECTED_UID=''
ENV_EXPECTED_GID=''

cleanup_deployment_temporary_files() {
  if [ -n "${ENV_TEMP_FILE}" ]; then
    rm -f -- "${ENV_TEMP_FILE}"
    ENV_TEMP_FILE=''
  fi
}

assert_env_security() {
  local actual_mode actual_uid actual_gid
  actual_mode="$(stat -c '%a' -- "${ENV_FILE}")" || return 1
  actual_uid="$(stat -c '%u' -- "${ENV_FILE}")" || return 1
  actual_gid="$(stat -c '%g' -- "${ENV_FILE}")" || return 1
  [ "${actual_mode}" = '600' ] || return 1
  [ "${actual_uid}" = "${ENV_EXPECTED_UID}" ] || return 1
  [ "${actual_gid}" = "${ENV_EXPECTED_GID}" ]
}

initialize_secure_env() {
  command -v sync >/dev/null 2>&1 || return 1
  [ -f "${ENV_FILE}" ] || return 1
  [ ! -L "${ENV_FILE}" ] || return 1
  ENV_EXPECTED_UID="$(stat -c '%u' -- "${ENV_FILE}")" || return 1
  ENV_EXPECTED_GID="$(stat -c '%g' -- "${ENV_FILE}")" || return 1
  chmod 600 -- "${ENV_FILE}" || return 1
  assert_env_security || return 1
}

atomic_env_replace() {
  local key="$1"
  local value="${2-}"
  local operation="$3"
  local env_directory env_basename
  env_directory="$(dirname -- "${ENV_FILE}")" || return 1
  env_basename="$(basename -- "${ENV_FILE}")" || return 1
  ENV_TEMP_FILE="$(mktemp "${env_directory}/.${env_basename}.tmp.XXXXXX")" || return 1
  awk -v key="${key}" 'index($0, key "=") != 1 { print }' "${ENV_FILE}" > "${ENV_TEMP_FILE}" || return 1
  if [ "${operation}" = 'upsert' ]; then
    printf '%s=%s\n' "${key}" "${value}" >> "${ENV_TEMP_FILE}" || return 1
  fi
  chown "${ENV_EXPECTED_UID}:${ENV_EXPECTED_GID}" -- "${ENV_TEMP_FILE}" || return 1
  chmod 600 -- "${ENV_TEMP_FILE}" || return 1
  sync -f -- "${ENV_TEMP_FILE}" || return 1
  mv -f -- "${ENV_TEMP_FILE}" "${ENV_FILE}" || return 1
  ENV_TEMP_FILE=''
  sync -f -- "${env_directory}" || return 1
  assert_env_security
}

upsert_env() {
  atomic_env_replace "$1" "$2" upsert
}

remove_env_key() {
  atomic_env_replace "$1" '' remove
}
