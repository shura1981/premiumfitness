// tests/<base>/<feature>/integration/<feature>.test.ts
// Integration: rutas, auth, validación y status codes. Use cases automockeados, sin DB.
import { CreateItem } from "@features/items/application/CreateItem";
import { ListItems } from "@features/items/application/ListItems";
import { ItemController } from "@features/items/http/ItemController";
import { AppError } from "@shared/domain/AppError";
import request from "supertest";
import { describe, expect, it, vi } from "vitest";
import { generateTestToken } from "../../../helpers/auth";
import { createTestApp } from "../../../helpers/createTestApp";

// Automock: las clases se instancian y sus métodos ya son vi.fn().
vi.mock("@features/items/application/ListItems");
vi.mock("@features/items/application/CreateItem");

function buildApp() {
	const listItemsUC = new ListItems(null as never);
	const createItemUC = new CreateItem(null as never);
	const items = new ItemController(listItemsUC, createItemUC);
	return { app: createTestApp({ items }), createItemUC, listItemsUC };
}

const token = generateTestToken();
const fakeItem = { id: 1, name: "Tornillo", quantity: 10 };

describe("GET /api/items", () => {
	it("200 — retorna la lista", async () => {
		const { app, listItemsUC } = buildApp();
		vi.mocked(listItemsUC.execute).mockResolvedValue([fakeItem]);

		const res = await request(app)
			.get("/api/items")
			.set("Authorization", `Bearer ${token}`);

		expect(res.status).toBe(200);
		expect(res.body).toHaveLength(1);
	});

	it("401 — sin token", async () => {
		const { app } = buildApp();

		const res = await request(app).get("/api/items");

		expect(res.status).toBe(401);
	});
});

describe("POST /api/items", () => {
	it("201 — crea el item", async () => {
		const { app, createItemUC } = buildApp();
		vi.mocked(createItemUC.execute).mockResolvedValue(fakeItem);

		const res = await request(app)
			.post("/api/items")
			.set("Authorization", `Bearer ${token}`)
			.send({ name: "Tornillo", quantity: 10 });

		expect(res.status).toBe(201);
		expect(res.body.id).toBe(1);
	});

	it("400 — validación fallida", async () => {
		const { app } = buildApp();

		const res = await request(app)
			.post("/api/items")
			.set("Authorization", `Bearer ${token}`)
			.send({ quantity: -1 });

		expect(res.status).toBe(400);
	});

	it("409 — el error de negocio llega con su status", async () => {
		const { app, createItemUC } = buildApp();
		vi.mocked(createItemUC.execute).mockRejectedValue(
			new AppError("El item ya existe", 409)
		);

		const res = await request(app)
			.post("/api/items")
			.set("Authorization", `Bearer ${token}`)
			.send({ name: "Tornillo", quantity: 10 });

		expect(res.status).toBe(409);
		expect(res.body.error).toBe("El item ya existe");
	});
});
