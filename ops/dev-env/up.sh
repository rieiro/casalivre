#!/usr/bin/env bash
# P0-5: single entry point. Picks the runtime and passes the remaining arguments through:
#   up.sh [--bwrap|--incus] [args...]      or  CL_RUNTIME=bwrap|incus up.sh [args...]
# Default: bwrap (the bots' computer, no root). The flag wins over CL_RUNTIME.
set -euo pipefail
dir=$(cd "$(dirname "$0")" && pwd)
runtime=${CL_RUNTIME:-bwrap}
case "${1:-}" in
--bwrap) runtime=bwrap; shift ;;
--incus) runtime=incus; shift ;;
esac
case $runtime in
bwrap) exec "$dir/up-bwrap.sh" "$@" ;;
incus) exec "$dir/up-incus.sh" "$@" ;;
*) echo "dev-env: ERROR: CL_RUNTIME must be bwrap or incus (got: $runtime)" >&2; exit 2 ;;
esac
