// tests/<base>/<feature>/unit/<UseCase>.test.ts
// Unit: reglas de negocio de un use case con sus ports mockeados. Sin HTTP, sin DB.
import { CreateItem } from "@features/items/application/CreateItem";
import type { Item } from "@features/items/domain/Item";
import type { ItemRepository } from "@features/items/domain/ItemRepository";
import { AppError } from "@shared/domain/AppError";
import { beforeEach, describe, expect, it, vi } from "vitest";

// Tipado con el port: si el port cambia, el mock deja de compilar.
function createMockItemRepo(): ItemRepository {
	return {
		create: vi.fn(),
		findAll: vi.fn(),
		findByName: vi.fn()
	};
}

const fakeItem: Item = { id: 1, name: "Tornillo", quantity: 10 };
const input = { name: "Tornillo", quantity: 10 };

describe("CreateItem", () => {
	let itemRepo: ItemRepository;
	let createItem: CreateItem;

	beforeEach(() => {
		itemRepo = createMockItemRepo();
		createItem = new CreateItem(itemRepo);
	});

	it("crea el item si el nombre está libre", async () => {
		vi.mocked(itemRepo.findByName).mockResolvedValue(null);
		vi.mocked(itemRepo.create).mockResolvedValue(fakeItem);

		await expect(createItem.execute(input)).resolves.toEqual(fakeItem);
		expect(itemRepo.create).toHaveBeenCalledWith(input);
	});

	it("lanza 409 si el nombre ya existe", async () => {
		vi.mocked(itemRepo.findByName).mockResolvedValue(fakeItem);

		await expect(createItem.execute(input)).rejects.toThrow(AppError);
		await expect(createItem.execute(input)).rejects.toMatchObject({
			code: 409
		});
		expect(itemRepo.create).not.toHaveBeenCalled();
	});
});
