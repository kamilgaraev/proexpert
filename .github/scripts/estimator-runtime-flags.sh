#!/usr/bin/env bash

ESTIMATOR_RUNTIME_FLAGS_STAGE_FILE=''

cleanup_estimator_runtime_flags() {
  if [ -n "${ESTIMATOR_RUNTIME_FLAGS_STAGE_FILE}" ]; then
    rm -f -- "${ESTIMATOR_RUNTIME_FLAGS_STAGE_FILE}"
    ESTIMATOR_RUNTIME_FLAGS_STAGE_FILE=''
  fi
}

estimator_runtime_read_env_value() {
  local key="$1" count value
  ESTIMATOR_RUNTIME_ENV_VALUE=''
  ESTIMATOR_RUNTIME_ENV_PRESENT=0
  count="$(awk -F= -v key="${key}" '$1 == key { count++ } END { print count + 0 }' "${ENV_FILE}")" || return 1
  [ "${count}" -le 1 ] || {
    echo "Duplicate estimator runtime setting: ${key}" >&2
    return 1
  }
  if [ "${count}" -eq 1 ]; then
    value="$(awk -F= -v key="${key}" '$1 == key { sub(/^[^=]*=/, ""); print; exit }' "${ENV_FILE}")" || return 1
    value="$(printf '%s' "${value}" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')" || return 1
    case "${value}" in
      \"*\") value="${value:1:${#value}-2}" ;;
      \'*\') value="${value:1:${#value}-2}" ;;
    esac
    ESTIMATOR_RUNTIME_ENV_VALUE="${value}"
    ESTIMATOR_RUNTIME_ENV_PRESENT=1
  fi
}

estimator_runtime_existing_bool() {
  local value="$1" normalized
  normalized="$(printf '%s' "${value}" | tr '[:upper:]' '[:lower:]')" || return 1
  case "${normalized}" in
    true|'(true)'|1) printf 'true' ;;
    false|'(false)'|0|empty|'(empty)'|null|'(null)'|'') printf 'false' ;;
    *) printf 'true' ;;
  esac
}

validate_estimator_runtime_flags() {
  local key input existing qa_enabled run_id effective normalized
  for key in \
    ESTIMATE_GENERATION_UNIVERSAL_ENABLED \
    ESTIMATE_GENERATION_SHARED_CREDITS_ENABLED \
    ESTIMATE_GENERATION_LIVE_QA_ENABLED; do
    input="${!key-}"
    estimator_runtime_read_env_value "${key}" || return 1
    if [ -n "${input}" ]; then
      case "${input}" in
        true|false) ;;
        *)
          echo "Invalid estimator boolean input: ${key}" >&2
          return 1
          ;;
      esac
      effective="${input}"
    else
      effective="$(estimator_runtime_existing_bool "${ESTIMATOR_RUNTIME_ENV_VALUE}")" || return 1
    fi
    case "${key}" in
      ESTIMATE_GENERATION_UNIVERSAL_ENABLED) ESTIMATOR_RUNTIME_EXPECTED_UNIVERSAL_ENABLED="${effective}" ;;
      ESTIMATE_GENERATION_SHARED_CREDITS_ENABLED) ESTIMATOR_RUNTIME_EXPECTED_SHARED_CREDITS_ENABLED="${effective}" ;;
      ESTIMATE_GENERATION_LIVE_QA_ENABLED) ESTIMATOR_RUNTIME_EXPECTED_LIVE_QA_ENABLED="${effective}" ;;
    esac
  done

  estimator_runtime_read_env_value ESTIMATE_GENERATION_LIVE_QA_RUN_ID || return 1
  existing="${ESTIMATOR_RUNTIME_ENV_VALUE}"
  ESTIMATOR_RUNTIME_EXPECTED_LIVE_QA_RUN_ID_TYPE=null
  run_id="${ESTIMATE_GENERATION_LIVE_QA_RUN_ID-}"
  if [ -n "${run_id}" ]; then
    [[ "${run_id}" =~ ^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$ ]] || {
      echo 'Invalid estimator live QA run ID; expected a UUID' >&2
      return 1
    }
    ESTIMATOR_RUNTIME_EXPECTED_LIVE_QA_RUN_ID_TYPE=string
  else
    run_id="${existing}"
    if [ "${ESTIMATOR_RUNTIME_ENV_PRESENT}" -eq 1 ]; then
      normalized="$(printf '%s' "${existing}" | tr '[:upper:]' '[:lower:]')" || return 1
      case "${normalized}" in
        null|'(null)') run_id='' ;;
        empty|'(empty)') run_id=''; ESTIMATOR_RUNTIME_EXPECTED_LIVE_QA_RUN_ID_TYPE=string ;;
        true|'(true)') run_id=true; ESTIMATOR_RUNTIME_EXPECTED_LIVE_QA_RUN_ID_TYPE=boolean ;;
        false|'(false)') run_id=false; ESTIMATOR_RUNTIME_EXPECTED_LIVE_QA_RUN_ID_TYPE=boolean ;;
        *) ESTIMATOR_RUNTIME_EXPECTED_LIVE_QA_RUN_ID_TYPE=string ;;
      esac
    fi
  fi
  ESTIMATOR_RUNTIME_EXPECTED_LIVE_QA_RUN_ID="${run_id}"

  qa_enabled="${ESTIMATOR_RUNTIME_EXPECTED_LIVE_QA_ENABLED}"
  if [ "${qa_enabled}" = true ] && [[ ! "${run_id}" =~ ^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$ ]]; then
    echo 'Estimator live QA requires a configured UUID run ID' >&2
    return 1
  fi
}

apply_estimator_runtime_flags() {
  local original_env="${ENV_FILE}" original_uid="${ENV_EXPECTED_UID}" original_gid="${ENV_EXPECTED_GID}"
  local env_directory env_basename key value changed=0

  for key in \
    ESTIMATE_GENERATION_UNIVERSAL_ENABLED \
    ESTIMATE_GENERATION_SHARED_CREDITS_ENABLED \
    ESTIMATE_GENERATION_LIVE_QA_ENABLED \
    ESTIMATE_GENERATION_LIVE_QA_RUN_ID; do
    if [ -n "${!key-}" ]; then
      changed=1
      break
    fi
  done
  validate_estimator_runtime_flags || return 1
  [ "${changed}" -eq 1 ] || return 0
  env_directory="$(dirname -- "${original_env}")" || return 1
  env_basename="$(basename -- "${original_env}")" || return 1
  ESTIMATOR_RUNTIME_FLAGS_STAGE_FILE="$(mktemp "${env_directory}/.${env_basename}.estimator.XXXXXX")" || return 1
  cp -- "${original_env}" "${ESTIMATOR_RUNTIME_FLAGS_STAGE_FILE}" || return 1
  chown "${original_uid}:${original_gid}" -- "${ESTIMATOR_RUNTIME_FLAGS_STAGE_FILE}" || return 1
  chmod 600 -- "${ESTIMATOR_RUNTIME_FLAGS_STAGE_FILE}" || return 1

  ENV_FILE="${ESTIMATOR_RUNTIME_FLAGS_STAGE_FILE}"
  ENV_TEMP_FILE=''
  ENV_EXPECTED_UID=''
  ENV_EXPECTED_GID=''
  initialize_secure_env || return 1

  for key in \
    ESTIMATE_GENERATION_UNIVERSAL_ENABLED \
    ESTIMATE_GENERATION_SHARED_CREDITS_ENABLED \
    ESTIMATE_GENERATION_LIVE_QA_ENABLED \
    ESTIMATE_GENERATION_LIVE_QA_RUN_ID; do
    value="${!key-}"
    if [ -n "${value}" ]; then
      upsert_env "${key}" "${value}" || return 1
    fi
  done

  ENV_FILE="${original_env}"
  ENV_EXPECTED_UID="${original_uid}"
  ENV_EXPECTED_GID="${original_gid}"
  ENV_TEMP_FILE=''
  mv -f -- "${ESTIMATOR_RUNTIME_FLAGS_STAGE_FILE}" "${original_env}" || return 1
  ESTIMATOR_RUNTIME_FLAGS_STAGE_FILE=''
  sync -f -- "${env_directory}" || return 1
  assert_env_security
}
