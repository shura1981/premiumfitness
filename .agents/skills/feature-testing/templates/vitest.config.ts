// vitest.config.ts — los aliases deben coincidir con "paths" de tsconfig.json
import path from "node:path";
import { defineConfig } from "vitest/config";

export default defineConfig({
	resolve: {
		alias: {
			"@features": path.resolve(__dirname, "src/features"),
			"@shared": path.resolve(__dirname, "src/shared")
		}
	},
	test: {
		globals: true,
		include: ["tests/**/*.test.ts"],
		root: "."
	}
});
