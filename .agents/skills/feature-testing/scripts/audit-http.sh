#!/usr/bin/env bash
# Auditoría de seguridad de la colección REST Client.
#
# Uso: audit-http.sh [dir-http] [--root .]
#   dir-http  carpeta de la colección (default: tests/http)
#   --root    raíz del repo, donde están .env y .env.example (default: .)
# Sale con 1 si encuentra problemas, 0 si todo está bien.
set -uo pipefail

dir="tests/http"; root="."
while [ $# -gt 0 ]; do
	case "$1" in
		--root) root="${2:?--root requiere valor}"; shift 2 ;;
		-h|--help) sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
		*) dir="$1"; shift ;;
	esac
done
cd "$root" || exit 2
[ -d "$dir" ] || { echo "No existe $dir" >&2; exit 2; }

fail=0
bad() { printf '  ✗ %s\n' "$1"; fail=1; }
good() { printf '  ✓ %s\n' "$1"; }
info() { printf '  · %s\n' "$1"; }
# Imprime coincidencias (archivo:línea: texto) recortadas.
show() { printf '%s\n' "$1" | cut -c1-160 | sed 's/^/      /'; }
check() { # check <descripción> <regex> <include-glob> [grep-flags]
	local out
	out="$(grep -rnE ${4:-} --include="$3" -- "$2" "$dir" 2>/dev/null || true)"
	if [ -n "$out" ]; then bad "$1"; show "$out"; else good "Sin $1"; fi
}

echo "1. .env fuera del repo"
if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	if git ls-files --error-unmatch .env >/dev/null 2>&1; then
		bad ".env está versionado: git rm --cached .env y rota las credenciales"
	else good ".env no está versionado"; fi
	if git check-ignore -q .env; then good ".env está en .gitignore"
	else bad ".env no está en .gitignore"; fi
else info "No es un repo git; se omite"; fi

echo "2. Variables \$dotenv documentadas"
used="$(grep -rhoE --include='*.http' '\{\{\$dotenv %?[A-Za-z_][A-Za-z0-9_]*\}\}' "$dir" \
	| sed -E 's/.*\$dotenv %?([A-Za-z0-9_]+).*/\1/' | sort -u)"
pct="$(grep -rhoE --include='*.http' '\{\{\$dotenv %[A-Za-z_]' "$dir" | head -1)"
if [ -z "$used" ]; then bad "Ningún .http usa {{\$dotenv}}"
elif [ ! -f .env.example ]; then bad "No existe .env.example"
else
	for v in $used; do
		if [ -n "$pct" ] && grep -qE "\{\{\\\$dotenv %$v\}\}" -r --include='*.http' "$dir"; then
			info "$v se resuelve por entorno (%): revisa rest-client.environmentVariables"; continue
		fi
		grep -qE "^$v=" .env.example || bad "$v se usa en .http pero falta en .env.example"
	done
	[ $fail -eq 0 ] && good "Todas documentadas: $(echo $used | tr '\n' ' ')"
fi
if [ -f .env ] && [ -n "$used" ]; then
	for v in $used; do grep -qE "^$v=" .env || info "$v no está en tu .env local (esa petición fallará)"; done
fi

echo "3. Valores literales en .http"
check "hosts literales en peticiones" '^(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)[[:space:]]+https?://' '*.http'
check "tokens literales" '(Bearer|Basic|Token)[[:space:]]+[A-Za-z0-9._~+/=-]{16,}' '*.http'
check "headers de auth con valor literal" '^(Authorization|X-Api-Key|Api-Key|X-Auth-Token):[[:space:]]*[^{[:space:]]+[[:space:]]*$' '*.http' -i
check "variables de archivo con secretos literales" '^@[A-Za-z0-9_]*(token|pass|pwd|secret|key|clave)[A-Za-z0-9_]*[[:space:]]*=[[:space:]]*[^{[:space:]]' '*.http' -i
check "secretos literales en bodies" '"(pass|password|pwd|passwd|secret|api_?key|token|clave|contrase(ñ|n)a)"[[:space:]]*:[[:space:]]*"[^{"][^"]*"' '*.http' -i

echo "4. Datos sensibles en respuestas guardadas"
check "JWT completos" 'eyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}' '*.json'
check "hashes de contraseña" '\$2[aby]\$[0-9]{2}\$|\$argon2' '*.json'
check "contraseñas con valor" '"(pass|password|pwd|passwd|contrase(ñ|n)a)"[[:space:]]*:[[:space:]]*"[^"]+"' '*.json' -i

emails="$(grep -rhoE --include='*.json' --include='*.http' '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' "$dir" 2>/dev/null \
	| grep -viE '@(example\.(com|org|net)|test\.[a-z]+|localhost)$' | sort -u || true)"
if [ -n "$emails" ]; then
	info "Correos que no son de example.com: confirma que sean ficticios"
	show "$emails"
fi

echo
if [ $fail -eq 0 ]; then echo "OK: sin problemas de seguridad."; else echo "Hay problemas que corregir (✗)."; fi
exit $fail
