#!/usr/bin/env node
// Migra tsconfig*.json para eliminar las opciones que TypeScript 6 marca como
// obsoletas y TypeScript 7 elimina: baseUrl, moduleResolution node/node10/classic
// e ignoreDeprecations. Sin dependencias (Node >= 18).
//
// Uso: node fix-tsconfig-deprecations.mjs [tsconfig...] [--write] [--verify]
//   sin archivos  busca tsconfig*.json en la raíz y un nivel abajo
//   (por defecto) muestra lo que cambiaría, sin escribir
//   --write       aplica los cambios (los comentarios del JSON se pierden)
//   --verify      corre tsc --noEmit con el TS del proyecto, TS 6 y TS 7
import { execFileSync } from "node:child_process";
import { existsSync, readdirSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";

const args = process.argv.slice(2);
const write = args.includes("--write");
const verify = args.includes("--verify");
if (args.includes("-h") || args.includes("--help")) {
	const help = readFileSync(new URL(import.meta.url), "utf8").split("\n");
	console.log(help.slice(1, 10).map((l) => l.replace(/^\/\/ ?/, "")).join("\n"));
	process.exit(0);
}
const root = process.cwd();
const SKIP = new Set(["node_modules", "dist", "build", ".git", "coverage"]);

function findConfigs() {
	const found = [];
	const scan = (dir, depth) => {
		for (const e of readdirSync(dir, { withFileTypes: true })) {
			if (e.isFile() && /^tsconfig.*\.json$/.test(e.name)) found.push(path.join(dir, e.name));
			if (e.isDirectory() && depth > 0 && !SKIP.has(e.name) && !e.name.startsWith(".")) scan(path.join(dir, e.name), depth - 1);
		}
	};
	scan(root, 1);
	return found;
}

// JSONC → JSON: quita comentarios y comas finales fuera de strings.
function parseJsonc(text) {
	let out = "";
	let hadComments = false;
	for (let i = 0; i < text.length; i++) {
		const c = text[i];
		if (c === '"') {
			let j = i + 1;
			while (j < text.length && text[j] !== '"') j += text[j] === "\\" ? 2 : 1;
			out += text.slice(i, j + 1);
			i = j;
		} else if (c === "/" && text[i + 1] === "/") {
			hadComments = true;
			while (i < text.length && text[i] !== "\n") i++;
			out += "\n";
		} else if (c === "/" && text[i + 1] === "*") {
			hadComments = true;
			i = text.indexOf("*/", i + 2) + 1;
		} else out += c;
	}
	let clean = "";
	for (let i = 0; i < out.length; i++) {
		const c = out[i];
		if (c === '"') {
			let j = i + 1;
			while (j < out.length && out[j] !== '"') j += out[j] === "\\" ? 2 : 1;
			clean += out.slice(i, j + 1);
			i = j;
		} else if (c === "," && /^\s*[}\]]/.test(out.slice(i + 1))) continue;
		else clean += c;
	}
	return { data: JSON.parse(clean), hadComments };
}

const posix = (p) => p.split(path.sep).join("/");
const relFrom = (dir, abs) => {
	const r = posix(path.relative(dir, abs)) || ".";
	return r.startsWith("..") ? r : `./${r}`;
};

const files = args.filter((a) => !a.startsWith("--")).map((f) => path.resolve(f));
const targets = files.length ? files : findConfigs();
if (!targets.length) {
	console.error("No se encontraron tsconfig*.json");
	process.exit(2);
}

// Lee todo antes de modificar: los hijos necesitan el baseUrl original del padre.
const original = new Map();
const load = (file) => {
	if (!original.has(file)) original.set(file, { ...parseJsonc(readFileSync(file, "utf8")), raw: readFileSync(file, "utf8") });
	return original.get(file);
};
for (const f of targets) load(f);

function inheritedBaseUrl(file) {
	const { data } = load(file);
	const ext = data.extends;
	if (typeof ext !== "string" || !ext.startsWith(".")) return null;
	let parent = path.resolve(path.dirname(file), ext);
	if (!parent.endsWith(".json")) parent += ".json";
	if (!existsSync(parent)) return null;
	const base = load(parent).data.compilerOptions?.baseUrl;
	return base ? path.resolve(path.dirname(parent), base) : inheritedBaseUrl(parent);
}

const pkgPath = path.join(root, "package.json");
const pkg = existsSync(pkgPath) ? JSON.parse(readFileSync(pkgPath, "utf8")) : {};
const isEsm = pkg.type === "module";
const deps = { ...pkg.dependencies, ...pkg.devDependencies };
const major = (v) => Number.parseInt(String(v ?? "").replace(/^[^\d]*/, ""), 10);

const globalWarnings = [];
if (deps["tsconfig-paths"] && major(deps["tsconfig-paths"]) < 4)
	globalWarnings.push("tsconfig-paths < 4 exige baseUrl: actualiza con npm i -D tsconfig-paths@^4");
if (deps["tsc-alias"] && /^[\^~]?1\.[0-7]\./.test(deps["tsc-alias"]))
	globalWarnings.push("tsc-alias < 1.8: actualiza con npm i -D tsc-alias@^1.8");

let changedAny = false;
for (const file of targets) {
	const { data, hadComments, raw } = load(file);
	const next = structuredClone(data);
	const co = (next.compilerOptions ??= {});
	const dir = path.dirname(file);
	const changes = [];
	const warnings = [];

	const ownBase = co.baseUrl ? path.resolve(dir, co.baseUrl) : null;
	const base = ownBase ?? inheritedBaseUrl(file);
	if (co.paths && base) {
		for (const [alias, list] of Object.entries(co.paths)) {
			co.paths[alias] = list.map((t) => relFrom(dir, path.resolve(base, t)));
		}
		changes.push(`paths relativos al tsconfig: ${JSON.stringify(co.paths)}`);
	}
	if (ownBase) {
		delete co.baseUrl;
		changes.push("quita baseUrl");
		if (!co.paths && !inheritedBaseUrl(file) && !next.extends)
			warnings.push(`no había paths: si importas módulos sin ./ (p. ej. "shared/x"), agrega "paths": { "*": ["${relFrom(dir, ownBase)}/*"] }`);
	}

	const mr = String(co.moduleResolution ?? "").toLowerCase();
	const mod = String(co.module ?? "").toLowerCase();
	const legacyResolution = ["node", "node10", "classic"].includes(mr);
	if (legacyResolution || (mod === "commonjs" && !mr)) {
		if (!isEsm && (mod === "commonjs" || !mod)) {
			co.module = "node16";
			co.moduleResolution = "node16";
			changes.push('module y moduleResolution → "node16" (sigue emitiendo CommonJS: package.json no tiene "type": "module")');
		} else if (legacyResolution) {
			warnings.push(
				`moduleResolution "${co.moduleResolution}" con módulos ESM: usa "bundler" (con module "esnext"/"preserve") si un bundler o tsx ejecuta el código, o "nodenext" si corre Node directo (los imports relativos necesitan extensión .js). No se cambia automáticamente.`
			);
		}
	}
	if ("ignoreDeprecations" in co) {
		delete co.ignoreDeprecations;
		changes.push("quita ignoreDeprecations (ya no hay nada que silenciar)");
	}
	if (/^es(3|5)$/i.test(String(co.target ?? ""))) warnings.push(`target ${co.target} también está obsoleto en TS 6: sube a ES2020 o superior`);
	if (["amd", "umd", "system", "none"].includes(mod)) warnings.push(`module ${co.module} está obsoleto en TS 6`);
	if (co.outFile) warnings.push("outFile está obsoleto en TS 6");

	const rel = posix(path.relative(root, file)) || file;
	if (!changes.length && !warnings.length) {
		console.log(`✓ ${rel}: sin opciones obsoletas`);
		continue;
	}
	console.log(`${changes.length ? "✎" : "!"} ${rel}`);
	for (const c of changes) console.log(`    - ${c}`);
	for (const w of warnings) console.log(`    ! ${w}`);
	if (changes.length) {
		changedAny = true;
		if (write) {
			const indent = raw.match(/\n([ \t]+)"/)?.[1] ?? "\t";
			writeFileSync(file, `${JSON.stringify(next, null, indent)}\n`);
			if (hadComments) console.log("    ! tenía comentarios y se perdieron al reescribir: revisa el diff");
		}
	}
}
for (const w of globalWarnings) console.log(`! ${w}`);
if (changedAny && !write) console.log("\nModo simulación: agrega --write para aplicar.");

if (verify) {
	console.log("\nVerificando con tsc --noEmit…");
	let failed = false;
	for (const [label, cmd] of [
		["TS del proyecto", ["tsc"]],
		["TS 6", ["-y", "-p", "typescript@6", "tsc"]],
		["TS 7", ["-y", "-p", "typescript@7", "tsc"]]
	]) {
		for (const file of targets) {
			const rel = posix(path.relative(root, file));
			try {
				execFileSync("npx", [...cmd, "--noEmit", "-p", file], { cwd: root, stdio: "pipe" });
				console.log(`  ✓ ${label}: ${rel}`);
			} catch (e) {
				failed = true;
				const out = `${e.stdout ?? ""}${e.stderr ?? ""}`.split("\n").filter((l) => /error TS|TS\d{4}/.test(l));
				console.log(`  ✗ ${label}: ${rel}`);
				for (const l of out.slice(0, 8)) console.log(`      ${l}`);
			}
		}
	}
	console.log(failed ? "\nHay errores: revísalos antes de seguir." : "\nOK. Corre también build, tests y el arranque en dev.");
	process.exit(failed ? 1 : 0);
}
