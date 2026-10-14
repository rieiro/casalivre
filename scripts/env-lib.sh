# shellcheck shell=bash
# Leitura segura do .env para scripts bash (sem `source`: nada do arquivo é executado).
# load_env_file <arquivo>: para cada linha KEY=VALUE válida, exporta KEY só se ela ainda NÃO existir no
# ambiente (variável do ambiente/linha de comando tem precedência, como Db::loadEnv no PHP).
# - ignora linhas vazias, comentários (#) e linhas sem KEY= válido ([A-Za-z_][A-Za-z0-9_]*);
# - aceita "export KEY=..." e tira um par de aspas simples ou duplas em volta do valor;
# - o valor é literal: $(...), `...`, $VAR e ; não são expandidos nem executados.
load_env_file() {
  local file="$1" line key value
  [[ -f "$file" ]] || return 0
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    line="${line#"${line%%[![:space:]]*}"}"
    [[ -z "$line" || "$line" == \#* ]] && continue
    line="${line#export }"
    [[ "$line" =~ ^([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*=(.*)$ ]] || continue
    key="${BASH_REMATCH[1]}"
    value="${BASH_REMATCH[2]}"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    if [[ ${#value} -ge 2 && ( ( "$value" == \"*\" ) || ( "$value" == \'*\' ) ) ]]; then
      value="${value:1:${#value}-2}"
    fi
    if [[ -z "${!key+x}" ]]; then
      export "$key=$value"
    fi
  done < "$file"
}
