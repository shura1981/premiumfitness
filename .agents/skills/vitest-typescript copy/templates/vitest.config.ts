import path from "node:path";
import { defineConfig } from "vitest/config";

// ─────────────────────────────────────────────────────────────
// PLANTILLA — Adaptar antes de usar:
//
// Si el proyecto tiene aliases en tsconfig.json → paths:
// 1. Descomentar la sección resolve.alias
// 2. Por cada "@alias/*": ["carpeta/*"] en paths, agregar:
//    "@alias": path.resolve(__dirname, "src/carpeta")
// 3. Los aliases aquí NO llevan /* al final
//
// Si el proyecto NO usa aliases:
//    Eliminar la sección resolve completa
//
// Ejemplo de conversión:
//   tsconfig:  "@models/*": ["models/*"]
//   vitest:    "@models": path.resolve(__dirname, "src/models")
// ─────────────────────────────────────────────────────────────

export default defineConfig({
    // Descomentar y adaptar si el proyecto usa path aliases:
    // resolve: {
    // 	alias: {
    // 		"@alias": path.resolve(__dirname, "src/<carpeta>"),
    // 	},
    // },
    test: {
        globals: true,
        root: ".",
        include: ["tests/**/*.test.ts"],
    },
});
