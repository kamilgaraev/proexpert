#!/usr/bin/env bash

set -euo pipefail

readonly STATE=/var/lib/most-active-release
readonly CONFIG=/etc/most/release-coordinator.conf
readonly MANIFEST="$STATE/smoke-ready.manifest"

is_sha() { [[ ${1-} =~ ^[0-9a-f]{40}$ ]]; }
is_image_tag() { [[ ${1-} =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]]; }

atomic_sha() {
    local component=$1 sha=$2 tmp
    tmp=$(mktemp "$STATE/.${component}.active.XXXXXX")
    printf '%s\n' "$sha" >"$tmp"
    chown root:root "$tmp"
    chmod 0444 "$tmp"
    mv -f "$tmp" "$STATE/$component.active"
}

verify_public_release() {
    local url=$1 sha=$2 headers body
    headers=$(mktemp)
    body=$(mktemp)
    trap 'rm -f "$headers" "$body"' RETURN
    curl -fsS --retry 6 --retry-delay 2 -D "$headers" -o "$body" "${url}?verification=${sha}"
    grep -Eiq '^Cache-Control:.*no-store' "$headers"
    grep -Fq "\"sha\":\"${sha}\"" "$body"
}

publish_pair() {
    local backend admin generation pair counter
    if [[ ! -f $STATE/backend.active || -L $STATE/backend.active || ! -f $STATE/admin.active || -L $STATE/admin.active ]]; then
        return 0
    fi
    backend=$(<"$STATE/backend.active")
    admin=$(<"$STATE/admin.active")
    is_sha "$backend" && is_sha "$admin"
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

deploy_backend() {
    local sha=$1 root previous services
    root=${BACKEND_ROOT:-/var/www/prohelper}
    services=${BACKEND_SERVICES:-api websockets horizon worker-heavy worker-ifc scheduler}
    cd "$root"
    previous=$(sed -n 's/^MOST_IMAGE_TAG=//p' .env | tail -1)
    is_image_tag "$previous" || previous=''
    rm -f "$MANIFEST" "$STATE/backend.active"
    trap 'rm -f "$MANIFEST" "$STATE/backend.active"; if is_image_tag "$previous"; then sed -i "s/^MOST_IMAGE_TAG=.*/MOST_IMAGE_TAG=${previous}/; s/^SENTRY_RELEASE=.*/SENTRY_RELEASE=prohelper@${previous}/" .env; docker compose up -d --force-recreate $services; if is_sha "$previous"; then verify_public_release "$BACKEND_RELEASE_URL" "$previous" && atomic_sha backend "$previous" && publish_pair || true; fi; fi' ERR
    if grep -q '^MOST_IMAGE_TAG=' .env; then
        sed -i "s/^MOST_IMAGE_TAG=.*/MOST_IMAGE_TAG=${sha}/" .env
    else
        printf 'MOST_IMAGE_TAG=%s\n' "$sha" >>.env
    fi
    if grep -q '^SENTRY_RELEASE=' .env; then
        sed -i "s/^SENTRY_RELEASE=.*/SENTRY_RELEASE=prohelper@${sha}/" .env
    else
        printf 'SENTRY_RELEASE=prohelper@%s\n' "$sha" >>.env
    fi
    docker compose up -d --force-recreate --remove-orphans $services
    verify_public_release "$BACKEND_RELEASE_URL" "$sha"
    [[ $(docker inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' prohelper-api) == "$sha" ]]
    atomic_sha backend "$sha"
    publish_pair
    trap - ERR
}

deploy_admin() {
    local sha=$1 root staging release previous
    root=${ADMIN_ROOT:?ADMIN_ROOT is required}
    staging="${ADMIN_STAGING_ROOT:-$root/incoming}/$sha"
    release="$root/releases/$sha"
    [[ -d $staging && ! -L $staging && -f $staging/release.json && ! -e $release ]]
    grep -Fq "\"sha\":\"${sha}\"" "$staging/release.json"
    chown -R root:root "$staging"
    find "$staging" -type d -exec chmod 0555 {} +
    find "$staging" -type f -exec chmod 0444 {} +
    mv "$staging" "$release"
    previous=$(readlink "$root/current" 2>/dev/null || true)
    rm -f "$MANIFEST" "$STATE/admin.active"
    trap 'rm -f "$MANIFEST" "$STATE/admin.active"; if [[ -n $previous && -d $previous ]]; then ln -sfn "$previous" "$root/current.next"; mv -Tf "$root/current.next" "$root/current"; old_sha=$(basename "$previous"); verify_public_release "$ADMIN_RELEASE_URL" "$old_sha" && atomic_sha admin "$old_sha" && publish_pair || true; fi' ERR
    ln -sfn "$release" "$root/current.next"
    mv -Tf "$root/current.next" "$root/current"
    verify_public_release "$ADMIN_RELEASE_URL" "$sha"
    atomic_sha admin "$sha"
    publish_pair
    trap - ERR
}

main() {
    local component=${1-} sha=${2-} config_mode
    [[ $component == backend || $component == admin ]] && is_sha "$sha"
    [[ -f $CONFIG && ! -L $CONFIG && $(stat -c '%u:%g' "$CONFIG") == 0:0 ]]
    config_mode=$(stat -c '%a' "$CONFIG")
    (((8#$config_mode & 0077) == 0))
    source "$CONFIG"
    install -d -o root -g root -m 0755 "$STATE"
    if [[ $component == admin ]]; then
        install -d -o root -g root -m 0755 "${ADMIN_ROOT:?ADMIN_ROOT is required}/releases"
    fi
    [[ -f $STATE/deploy.lock ]] || install -o root -g root -m 0600 /dev/null "$STATE/deploy.lock"
    if [[ ! -f $STATE/generation.counter ]]; then
        printf '0\n' >"$STATE/generation.counter"
        chown root:root "$STATE/generation.counter"
        chmod 0644 "$STATE/generation.counter"
    fi
    exec 9<>"$STATE/deploy.lock"
    flock -x 9
    if [[ $component == backend ]]; then deploy_backend "$sha"; else deploy_admin "$sha"; fi
}

main "$@"
