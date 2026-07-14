#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

readonly TARGET=/usr/local/libexec/most/coordinate-most-release
readonly CONFIG_TARGET=/etc/most/release-coordinator.conf
readonly SUDOERS_TARGET=/etc/sudoers.d/most-release-coordinator

main() {
    local backend_user=${1-} admin_user=${2-} config_source=${3-} root script_dir sudoers_tmp
    [[ $(id -u) -eq 0 && $# -eq 3 ]]
    [[ $backend_user =~ ^[a-z_][a-z0-9_-]*$ && $admin_user =~ ^[a-z_][a-z0-9_-]*$ ]]
    [[ -f $config_source && ! -L $config_source ]]

    root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
    script_dir=$(dirname "$TARGET")
    install -d -o root -g root -m 0755 "$script_dir" /etc/most /var/lib/most-active-release
    install -o root -g root -m 0555 "$root/scripts/coordinate-most-release.sh" "$TARGET"
    install -o root -g root -m 0600 "$config_source" "$CONFIG_TARGET"
    source "$CONFIG_TARGET"
    [[ -f ${BACKEND_ROOT:?BACKEND_ROOT is required}/.env && ! -L $BACKEND_ROOT/.env ]]
    chown root:root "$BACKEND_ROOT/.env"
    chmod 0600 "$BACKEND_ROOT/.env"

    sudoers_tmp=$(mktemp /etc/sudoers.d/.most-release-coordinator.XXXXXX)
    printf '%s ALL=(root) NOPASSWD: %s backend [0-9a-f]* ghcr.io/* sha256:*\n' "$backend_user" "$TARGET" >"$sudoers_tmp"
    printf '%s ALL=(root) NOPASSWD: %s admin [0-9a-f]* [0-9a-f]* [0-9a-f]*\n' "$admin_user" "$TARGET" >>"$sudoers_tmp"
    chown root:root "$sudoers_tmp"
    chmod 0440 "$sudoers_tmp"
    visudo -cf "$sudoers_tmp"
    mv -f "$sudoers_tmp" "$SUDOERS_TARGET"
    visudo -cf "$SUDOERS_TARGET"
}

main "$@"
