#!/usr/bin/env bash
# Crea la estructura de tests de una feature sin pisar nada existente.
#
# Uso: scaffold-feature-tests.sh <feature> [--base features] [--public] [--root .]
#   <feature>  nombre de la carpeta de la feature en src/<base>/ (ej. items)
#   --base     carpeta de slicing: features (default), modules, domains...
#   --public   endpoints sin token (omite @token y Authorization)
#   --root     raíz del repo (default: directorio actual)
set -euo pipefail

usage() { sed -n '2,10p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }

feature=""; base="features"; access="protegidos"; root="."
while [ $# -gt 0 ]; do
	case "$1" in
		--base) base="${2:?--base requiere valor}"; shift 2 ;;
		--public) access="públicos"; shift ;;
		--root) root="${2:?--root requiere valor}"; shift 2 ;;
		-h|--help) usage 0 ;;
		-*) echo "Opción desconocida: $1" >&2; usage 1 ;;
		*) feature="$1"; shift ;;
	esac
done
[ -n "$feature" ] || usage 1

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
template="$script_dir/../templates/request.http"
cd "$root"

[ -d "src/$base/$feature" ] || echo "Aviso: no existe src/$base/$feature (¿--base correcto?)" >&2

created=()
mk() { if [ ! -d "$1" ]; then mkdir -p "$1"; created+=("$1/"); fi; }

mk "tests/$base/$feature/unit"
mk "tests/$base/$feature/integration"
mk "tests/http/$feature/responses"
[ -e "tests/http/$feature/responses/.gitkeep" ] || [ -n "$(ls -A "tests/http/$feature/responses")" ] \
	|| { touch "tests/http/$feature/responses/.gitkeep"; created+=("tests/http/$feature/responses/.gitkeep"); }

http="tests/http/$feature/request.http"
if [ -e "$http" ]; then
	echo "Existe $http: no se toca."
else
	title="$(printf '%s' "$feature" | sed -E 's/(^|[-_])([a-z])/\U\2/g')"
	if [ "$access" = "protegidos" ]; then
		sed -e "s/__TITLE__/$title/g" -e "s/__ACCESS__/$access/g" -e "s/__FEATURE__/$feature/g" \
			-e 's/^__TOKEN_LINE__$/@token = {{$dotenv TEST_TOKEN}}/' \
			-e 's/^__AUTH_HEADER__$/Authorization: Bearer {{token}}/' "$template" > "$http"
	else
		sed -e "s/__TITLE__/$title/g" -e "s/__ACCESS__/$access/g" -e "s/__FEATURE__/$feature/g" \
			-e '/^__TOKEN_LINE__$/d' -e '/^__AUTH_HEADER__$/d' "$template" > "$http"
	fi
	created+=("$http")
fi

if [ ${#created[@]} -gt 0 ]; then printf 'Creado:\n'; printf '  %s\n' "${created[@]}"; else echo "Nada que crear."; fi

missing=()
for v in HOST $([ "$access" = "protegidos" ] && echo TEST_TOKEN); do
	[ -f .env.example ] && grep -qE "^$v=" .env.example || missing+=("$v")
done
[ ${#missing[@]} -eq 0 ] || echo "Falta en .env.example: ${missing[*]} (ver templates/env.example)"
echo "Siguiente: ajusta las rutas de $http a los endpoints reales y escribe los tests."
