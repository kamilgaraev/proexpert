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
  actual_mode="$(stat -c '%a' -- "${ENV_FILE}")"
  actual_uid="$(stat -c '%u' -- "${ENV_FILE}")"
  actual_gid="$(stat -c '%g' -- "${ENV_FILE}")"
  [ "${actual_mode}" = '600' ]
  [ "${actual_uid}" = "${ENV_EXPECTED_UID}" ]
  [ "${actual_gid}" = "${ENV_EXPECTED_GID}" ]
}

initialize_secure_env() {
  [ -f "${ENV_FILE}" ]
  [ ! -L "${ENV_FILE}" ]
  ENV_EXPECTED_UID="$(stat -c '%u' -- "${ENV_FILE}")"
  ENV_EXPECTED_GID="$(stat -c '%g' -- "${ENV_FILE}")"
  chmod 600 -- "${ENV_FILE}"
  assert_env_security
}

atomic_env_replace() {
  local key="$1"
  local value="${2-}"
  local operation="$3"
  local env_directory env_basename
  env_directory="$(dirname -- "${ENV_FILE}")"
  env_basename="$(basename -- "${ENV_FILE}")"
  ENV_TEMP_FILE="$(mktemp "${env_directory}/.${env_basename}.tmp.XXXXXX")"
  awk -v key="${key}" 'index($0, key "=") != 1 { print }' "${ENV_FILE}" > "${ENV_TEMP_FILE}"
  if [ "${operation}" = 'upsert' ]; then
    printf '%s=%s\n' "${key}" "${value}" >> "${ENV_TEMP_FILE}"
  fi
  chown "${ENV_EXPECTED_UID}:${ENV_EXPECTED_GID}" -- "${ENV_TEMP_FILE}"
  chmod 600 -- "${ENV_TEMP_FILE}"
  mv -f -- "${ENV_TEMP_FILE}" "${ENV_FILE}"
  ENV_TEMP_FILE=''
  assert_env_security
}

upsert_env() {
  atomic_env_replace "$1" "$2" upsert
}

remove_env_key() {
  atomic_env_replace "$1" '' remove
}
