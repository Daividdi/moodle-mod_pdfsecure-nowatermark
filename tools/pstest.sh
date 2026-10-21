#!/bin/bash
# Prova, por HTTP e com sessao real, que a entrega do PDF esta correta.
#
# Quatro checagens, e as tres ultimas sao as que importam para seguranca:
#   1. usuario matriculado recebe 200 e os bytes certos
#   2. a area crua do arquivo enviado devolve 404 (nao existe rota para ela)
#   3. sem sessao devolve 303 (manda para o login)
#   4. usuario logado mas SEM matricula devolve 303
#
# Uso:
#   ./pstest.sh <BASE_URL> <CONTEXTID> <NOME_DO_ARQUIVO> <SHA256_ESPERADO>
# Ex.:
#   ./pstest.sh http://moodle.exemplo 4857 'Handbook 2.pdf' 8d79dd...
set -u

BASE="${1:?url base, ex.: http://moodle.exemplo}"
CTX="${2:?contextid do modulo}"
FILE="${3:?nome do arquivo}"
WANT="${4:-}"
USER_="${PSUSER:-ps.verify}"
PASS_="${PSPASS:-Ps!verify2026#x}"

# Percent-encode do nome do arquivo (espacos, acentos, CJK).
ENC=$(python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))' "$FILE")
STAMPED="$BASE/pluginfile.php/$CTX/mod_pdfsecure/stamped/0/$ENC"
RAW="$BASE/pluginfile.php/$CTX/mod_pdfsecure/content/0/$ENC"

JAR=$(mktemp); OUT=$(mktemp)
trap 'rm -f "$JAR" "$OUT"' EXIT
CURL="curl -sk"

# O Moodle exige o logintoken no POST de login; ele vem no HTML do formulario.
TOKEN=$($CURL -c "$JAR" "$BASE/login/index.php" \
        | grep -o 'logintoken" value="[a-zA-Z0-9]*' | head -1 | cut -d'"' -f3)
$CURL -b "$JAR" -c "$JAR" -o /dev/null \
      -d "username=$USER_&password=$PASS_&logintoken=$TOKEN" "$BASE/login/index.php"

if ! $CURL -b "$JAR" "$BASE/my/" | grep -q "Teste Verificacao"; then
  echo "FALHA: nao consegui autenticar como $USER_"; exit 1
fi
echo "autenticado como $USER_"

CODE=$($CURL -b "$JAR" -o "$OUT" -w '%{http_code}' "$STAMPED")
GOT=$(shasum -a 256 "$OUT" 2>/dev/null | cut -d' ' -f1 || sha256sum "$OUT" | cut -d' ' -f1)
echo "1. entrega autenticada : $CODE  $(head -c 8 "$OUT")"
echo "   sha256 recebido     : $GOT"
if [ -n "$WANT" ]; then
  [ "$GOT" = "$WANT" ] && echo "   -> IDENTICO ao arquivo guardado" \
                       || echo "   -> DIVERGE do esperado ($WANT)"
fi
$CURL -b "$JAR" -D - -o /dev/null "$STAMPED" \
  | grep -iE '^(content-type|content-disposition|cache-control|accept-ranges)' | sed 's/^/   /'

echo "2. area crua content   : $($CURL -b "$JAR" -o /dev/null -w '%{http_code}' "$RAW")  (esperado 404)"
echo "3. sem sessao          : $($CURL -o /dev/null -w '%{http_code}' "$STAMPED")  (esperado 303)"

if [ -n "${PSOUTSIDER:-}" ]; then
  J2=$(mktemp)
  T2=$($CURL -c "$J2" "$BASE/login/index.php" \
       | grep -o 'logintoken" value="[a-zA-Z0-9]*' | head -1 | cut -d'"' -f3)
  $CURL -b "$J2" -c "$J2" -o /dev/null \
        -d "username=$PSOUTSIDER&password=${PSOUTSIDERPASS}&logintoken=$T2" "$BASE/login/index.php"
  echo "4. logado sem matricula: $($CURL -b "$J2" -o /dev/null -w '%{http_code}' "$STAMPED")  (esperado 303)"
  rm -f "$J2"
fi
