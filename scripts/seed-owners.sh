#!/usr/bin/env bash
set -euo pipefail
echo "seed-owners.sh agora é seed-users.sh" >&2
exec "$(cd "$(dirname "$0")" && pwd)/seed-users.sh" "$@"
