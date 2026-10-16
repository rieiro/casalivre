#!/usr/bin/env bash
# P0-3 (critério 10): falha cedo se faltar algo de que a suíte depende.
# Uso: ops/dev-env/check-test-deps.sh   (CL_PHP=<php do ambiente>, padrão: php no PATH)
# Checa: nenhum uso de GLOB_BRACE no código (indisponível no musl), extensão exif, node,
# post_max_size e upload_max_filesize >= 16 MiB (limite do upload de fotos).
set -euo pipefail
repo=$(cd "$(dirname "$0")/../.." && pwd)
php=${CL_PHP:-php}
fail=0
err() { echo "check-test-deps: ERRO: $*" >&2; fail=1; }

if grep -rIn --include='*.php' 'GLOB_BRACE' "$repo/src" "$repo/scripts" "$repo/tests" "$repo/public" "$repo/conta" "$repo/views" 2>/dev/null; then
	err "GLOB_BRACE no código (não existe no musl); use dois glob() e array_merge"
fi
"$php" -r 'exit(extension_loaded("exif") ? 0 : 1);' || err "extensão exif ausente no PHP ($php)"
command -v node >/dev/null || err "node ausente no PATH (xss_csp_test usa node)"
"$php" -r '
$b = static function (string $v): int { $n = (int) $v; return match (strtolower(substr(trim($v), -1))) { "g" => $n << 30, "m" => $n << 20, "k" => $n << 10, default => $n }; };
$min = 16 << 20; $bad = [];
foreach (["post_max_size", "upload_max_filesize"] as $k) { if ($b((string) ini_get($k)) < $min) { $bad[] = "$k=" . ini_get($k); } }
if ($bad) { fwrite(STDERR, implode(", ", $bad) . " (mínimo 16M)\n"); exit(1); }' || err "php.ini abaixo do limite do upload"

[ "$fail" -eq 0 ] || exit 1
echo "check-test-deps: ok"
