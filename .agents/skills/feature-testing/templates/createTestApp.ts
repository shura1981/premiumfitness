// tests/helpers/createTestApp.ts
// Misma app HTTP que producción, pero sin container, sin .env y sin base de datos.
// No llames a createApp() de producción: arma el container, que lee .env y abre la DB.
import type { Controllers } from "@/app/registerRoutes"; // ajusta al tipo real
import { registerRoutes } from "@/app/registerRoutes"; // extraído de app.ts
import { errorHandler } from "@shared/http/middleware/errorHandler";
import express from "express";

export const TEST_JWT_SECRET = "test-secret-key-for-vitest";

// Partial: cada test de integración construye solo el controller de su feature.
export function createTestApp(controllers: Partial<Controllers>) {
	const app = express();
	app.use(express.json({ limit: "5mb" }));
	registerRoutes({ app, controllers, jwtSecret: TEST_JWT_SECRET });
	app.use(errorHandler);
	return app;
}

/*
 * Si todavía no existe registerRoutes, monta las rutas a mano y extráelo después:
 *
 *   app.use("/api/auth", authRoutes(controllers.auth));
 *   const protect = authMiddleware(TEST_JWT_SECRET);
 *   if (controllers.items) app.use("/api/items", protect, itemRoutes(controllers.items));
 *
 * registerRoutes debe ignorar los controllers ausentes para que Partial funcione.
 */
