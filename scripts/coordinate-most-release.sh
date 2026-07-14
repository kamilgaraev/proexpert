#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

readonly STATE=/var/lib/most-active-release
readonly CONFIG=/etc/most/release-coordinator.conf
readonly MANIFEST="$STATE/smoke-ready.manifest"
BACKEND_PROJECT_ROOT=
BACKEND_COMPOSE_FILE=

is_sha() { [[ ${1-} =~ ^[0-9a-f]{40}$ ]]; }
is_digest() { [[ ${1-} =~ ^sha256:[0-9a-f]{64}$ ]]; }
is_repo() { [[ ${1-} =~ ^ghcr\.io/[a-z0-9._/-]+$ ]]; }
is_digest_ref() { [[ ${1-} =~ ^ghcr\.io/[a-z0-9._/-]+@sha256:[0-9a-f]{64}$ ]]; }
is_artifact_digest() { [[ ${1-} =~ ^[0-9a-f]{64}$ ]]; }
is_staging_token() { [[ ${1-} =~ ^[0-9a-f]{40}-[0-9]+-[0-9]+$ ]]; }

dc() {
    [[ -n $BACKEND_PROJECT_ROOT && -n $BACKEND_COMPOSE_FILE ]]
    docker compose --project-directory "$BACKEND_PROJECT_ROOT" -f "$BACKEND_COMPOSE_FILE" "$@"
}

atomic_sha() {
    local component=$1 sha=$2 tmp
    is_sha "$sha"
    tmp=$(mktemp "$STATE/.${component}.active.XXXXXX")
    printf '%s\n' "$sha" >"$tmp"
    chown root:root "$tmp"
    chmod 0444 "$tmp"
    mv -f "$tmp" "$STATE/$component.active"
}

verify_public_release() {
    local url=$1 sha=$2 headers body
    is_sha "$sha"
    headers=$(mktemp "$STATE/.headers.XXXXXX")
    body=$(mktemp "$STATE/.body.XXXXXX")
    curl -fsS --retry 12 --retry-all-errors --retry-delay 2 -D "$headers" -o "$body" "${url}?verification=${sha}"
    grep -Eiq '^Cache-Control:.*no-store' "$headers"
    [[ $(tr -d '\r\n' <"$body") == "{\"sha\":\"${sha}\"}" ]]
    rm -f "$headers" "$body"
}

publish_pair() {
    local backend admin generation pair counter
    if [[ ! -f $STATE/backend.active || -L $STATE/backend.active || ! -f $STATE/admin.active || -L $STATE/admin.active ]]; then
        return 0
    fi
    backend=$(<"$STATE/backend.active")
    admin=$(<"$STATE/admin.active")
    is_sha "$backend" && is_sha "$admin"
    verify_public_release "$BACKEND_RELEASE_URL" "$backend"
    verify_public_release "$ADMIN_RELEASE_URL" "$admin"
    generation=$(<"$STATE/generation.counter")
    [[ $generation =~ ^[0-9]+$ ]]
    generation=$((generation + 1))
    counter=$(mktemp "$STATE/.generation.XXXXXX")
    printf '%s\n' "$generation" >"$counter"
    chown root:root "$counter"
    chmod 0644 "$counter"
    mv -f "$counter" "$STATE/generation.counter"
    pair=$(mktemp "$STATE/.smoke-ready.XXXXXX")
    printf 'schema=most-active-release/v1\ngeneration=%s\nbackend_sha=%s\nadmin_sha=%s\n' \
        "$generation" "$backend" "$admin" >"$pair"
    chown root:root "$pair"
    chmod 0444 "$pair"
    mv -f "$pair" "$MANIFEST"
}

image_has_digest() {
    local ref=$1
    is_digest_ref "$ref"
    docker image inspect -f '{{json .RepoDigests}}' "$ref" | grep -Fq "\"${ref}\""
}

write_backend_env() {
    local root=$1 ref=$2 sha=$3 env_file tmp mode uid gid
    env_file="$root/.env"
    [[ -f $env_file && ! -L $env_file ]]
    mode=$(stat -c '%a' "$env_file")
    uid=$(stat -c '%u' "$env_file")
    gid=$(stat -c '%g' "$env_file")
    tmp=$(mktemp "$root/.env.most.XXXXXX")
    awk -v ref="$ref" -v sha="$sha" '
        BEGIN { have_ref=0; have_sha=0; have_sentry=0 }
        /^MOST_IMAGE_REF=/ { print "MOST_IMAGE_REF=" ref; have_ref=1; next }
        /^MOST_RELEASE_SHA=/ { print "MOST_RELEASE_SHA=" sha; have_sha=1; next }
        /^SENTRY_RELEASE=/ { print "SENTRY_RELEASE=prohelper@" sha; have_sentry=1; next }
        { print }
        END {
            if (!have_ref) print "MOST_IMAGE_REF=" ref
            if (!have_sha) print "MOST_RELEASE_SHA=" sha
            if (!have_sentry) print "SENTRY_RELEASE=prohelper@" sha
        }
    ' "$env_file" >"$tmp"
    chown "$uid:$gid" "$tmp"
    chmod "$mode" "$tmp"
    mv -f "$tmp" "$env_file"
}

container_id() {
    local service=$1 id
    id=$(dc ps -q "$service")
    [[ -n $id ]]
    printf '%s\n' "$id"
}

verify_runtime_images() {
    local expected_ref=$1 expected_sha=$2 services=$3 service id actual_ref actual_sha
    is_digest_ref "$expected_ref" && is_sha "$expected_sha" && image_has_digest "$expected_ref"
    for service in $services; do
        id=$(container_id "$service")
        actual_ref=$(docker inspect -f '{{.Config.Image}}' "$id")
        actual_sha=$(docker inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$id")
        [[ $actual_ref == "$expected_ref" && $actual_sha == "$expected_sha" ]]
    done
}

health_gate() {
    local sha=$1 services=$2 attempt service id state health all_ready
    for attempt in $(seq 1 60); do
        all_ready=true
        for service in $services; do
        id=$(dc ps -q "$service")
            if [[ -z $id ]]; then
                all_ready=false
                break
            fi
            state=$(docker inspect -f '{{.State.Status}}' "$id")
            health=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$id")
            if [[ $state != running || ($health != none && $health != healthy) ]]; then
                all_ready=false
                break
            fi
        done
        if [[ $all_ready == true ]] \
            && dc exec -T api curl -fsS http://localhost:8000/up >/dev/null \
            && verify_public_release "$BACKEND_RELEASE_URL" "$sha"; then
            return 0
        fi
        sleep 5
    done
    return 1
}

rollback_backend() {
    local root=$1 ref=$2 sha=$3 services=$4
    trap - ERR
    write_backend_env "$root" "$ref" "$sha"
    MOST_IMAGE_REF="$ref" dc up -d --force-recreate --remove-orphans $services
    health_gate "$sha" "$services"
    verify_runtime_images "$ref" "$sha" "$services"
    atomic_sha backend "$sha"
    publish_pair
}

deploy_backend() {
    local sha=$1 repo=$2 digest=$3 root services image_ref previous_id previous_ref previous_sha compose_tmp
    root=${BACKEND_ROOT:-/var/www/prohelper}
    services=${BACKEND_SERVICES:-api websockets horizon worker-heavy worker-ifc scheduler}
    image_ref="${repo}@${digest}"
    is_sha "$sha" && is_repo "$repo" && is_digest "$digest" && is_digest_ref "$image_ref"
    cd "$root"
    [[ $(git rev-parse HEAD) == "$sha" ]]
    git diff --quiet -- docker-compose.yml
    git diff --cached --quiet -- docker-compose.yml
    install -d -o root -g root -m 0700 "$STATE/backend-compose"
    compose_tmp=$(mktemp "$STATE/backend-compose/.${sha}.XXXXXX")
    git show "$sha:docker-compose.yml" >"$compose_tmp"
    chown root:root "$compose_tmp"
    chmod 0400 "$compose_tmp"
    BACKEND_PROJECT_ROOT=$root
    BACKEND_COMPOSE_FILE="$STATE/backend-compose/$sha.yml"
    mv -f "$compose_tmp" "$BACKEND_COMPOSE_FILE"

    previous_id=$(container_id api)
    previous_ref=$(docker inspect -f '{{.Config.Image}}' "$previous_id")
    previous_sha=$(docker inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$previous_id")
    is_digest_ref "$previous_ref" && is_sha "$previous_sha"
    verify_runtime_images "$previous_ref" "$previous_sha" "$services"

    [[ -f ${GHCR_TOKEN_FILE:?GHCR_TOKEN_FILE is required} && ! -L $GHCR_TOKEN_FILE ]]
    cat "$GHCR_TOKEN_FILE" | docker login ghcr.io -u "${GHCR_USERNAME:?GHCR_USERNAME is required}" --password-stdin >/dev/null
    docker pull "$image_ref"
    image_has_digest "$image_ref"
    [[ $(docker image inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$image_ref") == "$sha" ]]

    MOST_IMAGE_REF="$image_ref" dc run --rm --no-deps api php artisan migrate --force

    rm -f "$MANIFEST" "$STATE/backend.active"
    trap 'rm -f "$MANIFEST" "$STATE/backend.active"; rollback_backend "$root" "$previous_ref" "$previous_sha" "$services"' ERR
    write_backend_env "$root" "$image_ref" "$sha"
    MOST_IMAGE_REF="$image_ref" dc up -d --force-recreate --remove-orphans $services
    health_gate "$sha" "$services"
    verify_runtime_images "$image_ref" "$sha" "$services"
    atomic_sha backend "$sha"
    publish_pair
    trap - ERR
}

verify_admin_release() {
    local sha=$1
    verify_public_release "$ADMIN_RELEASE_URL" "$sha"
}

rollback_admin() {
    local root=$1 previous=$2 old_sha
    trap - ERR
    old_sha=$(basename "$previous")
    is_sha "$old_sha"
    ln -s "$previous" "$root/current.next"
    mv -Tf "$root/current.next" "$root/current"
    verify_admin_release "$old_sha"
    atomic_sha admin "$old_sha"
    publish_pair
}

deploy_admin() {
    local sha=$1 expected_digest=$2 token=$3 root incoming_root artifact artifact_real incoming_real release previous old_sha sealed candidate invalid path
    root=${ADMIN_ROOT:?ADMIN_ROOT is required}
    incoming_root=${ADMIN_STAGING_ROOT:?ADMIN_STAGING_ROOT is required}
    release="$root/releases/$sha"
    artifact="$incoming_root/$token/admin-release.tar.gz"
    is_sha "$sha" && is_artifact_digest "$expected_digest" && is_staging_token "$token"
    [[ $token == "$sha-"* && -f $artifact && ! -L $artifact ]]

    incoming_real=$(realpath --canonicalize-existing "$incoming_root")
    artifact_real=$(realpath --canonicalize-existing "$artifact")
    [[ $artifact_real == "$incoming_real/"* ]]

    previous=$(readlink -f "$root/current")
    [[ -n $previous && -d $previous && $previous == "$root/releases/"* ]]
    old_sha=$(basename "$previous")
    is_sha "$old_sha"

    if [[ -e $release ]]; then
        [[ -d $release && ! -L $release && $(stat -c '%u:%g' "$release") == 0:0 ]]
        if [[ $previous == "$release" ]]; then
            verify_admin_release "$sha"
            atomic_sha admin "$sha"
            publish_pair
            return 0
        fi
        install -d -o root -g root -m 0700 "$root/admin-release-quarantine"
        mv "$release" "$root/admin-release-quarantine/${sha}-${token}"
    fi

    sealed=$(mktemp -d "$root/.sealing.${sha}.XXXXXX")
    install -o root -g root -m 0400 "$artifact_real" "$sealed/admin-release.tar.gz"
    [[ $(sha256sum "$sealed/admin-release.tar.gz" | awk '{print $1}') == "$expected_digest" ]]
    candidate="$sealed/candidate"
    install -d -o root -g root -m 0700 "$candidate"
    tar -xzf "$sealed/admin-release.tar.gz" --no-same-owner --no-same-permissions -C "$candidate"

    invalid=$(find "$candidate" ! -type f ! -type d -print -quit)
    [[ -z $invalid ]]
    while IFS= read -r -d '' path; do
        [[ $(realpath --canonicalize-existing "$path") == "$candidate"/* ]]
    done < <(find "$candidate" -mindepth 1 -print0)
    [[ -f $candidate/release.json && $(tr -d '\r\n' <"$candidate/release.json") == "{\"sha\":\"${sha}\"}" ]]
    [[ -f $candidate/index.html ]]

    chown -R root:root "$candidate"
    find "$candidate" -type d -exec chmod 0555 {} +
    find "$candidate" -type f -exec chmod 0444 {} +
    mv "$candidate" "$release"
    rm -rf "$sealed"

    rm -f "$MANIFEST" "$STATE/admin.active"
    trap 'rm -f "$MANIFEST" "$STATE/admin.active"; rollback_admin "$root" "$previous"' ERR
    rm -f "$root/current.next"
    ln -s "$release" "$root/current.next"
    mv -Tf "$root/current.next" "$root/current"
    verify_admin_release "$sha"
    atomic_sha admin "$sha"
    publish_pair
    trap - ERR
}

main() {
    local component=${1-} config_mode
    [[ -f $CONFIG && ! -L $CONFIG && $(stat -c '%u:%g' "$CONFIG") == 0:0 ]]
    config_mode=$(stat -c '%a' "$CONFIG")
    (((8#$config_mode & 0077) == 0))
    source "$CONFIG"

    install -d -o root -g root -m 0755 "$STATE"
    [[ -f $STATE/deploy.lock ]] || install -o root -g root -m 0600 /dev/null "$STATE/deploy.lock"
    if [[ ! -f $STATE/generation.counter ]]; then
        install -o root -g root -m 0644 /dev/null "$STATE/generation.counter"
        printf '0\n' >"$STATE/generation.counter"
    fi
    install -d -o root -g root -m 0755 "${ADMIN_ROOT:?ADMIN_ROOT is required}/releases"
    exec 9<>"$STATE/deploy.lock"
    flock -x 9

    case "$component" in
        backend)
            [[ $# -eq 4 ]]
            deploy_backend "$2" "$3" "$4"
            ;;
        admin)
            [[ $# -eq 4 ]]
            deploy_admin "$2" "$3" "$4"
            ;;
        *)
            return 64
            ;;
    esac
}

main "$@"
