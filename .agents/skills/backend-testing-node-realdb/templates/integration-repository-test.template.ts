import { describe, it, expect, beforeAll, afterAll, beforeEach } from 'vitest';
import { OrderRepository } from '../../src/features/order/order.repository';
import { Database } from '../../src/infrastructure/database/database';
import { Seed } from '../support/seed';

// Reemplazar OrderRepository por el repositorio real de la feature objetivo.
describe('OrderRepository (Integration - Real MySQL)', () => {
  let repo: OrderRepository;
  let seededUserId: number;

  beforeAll(async () => {
    // Inicializar conexión a MySQL real de pruebas
    await Database.connect();
    repo = new OrderRepository();
  });

  afterAll(async () => {
    // Cerrar pool de conexiones
    await Database.disconnect();
  });

  beforeEach(async () => {
    // Fixture real en BD
    seededUserId = await Seed.user({ role: 'trainer' });
  });

  it('creates and finds entity in real DB', async () => {
    const payload = {
      userId: seededUserId,
      totalAmount: 150000,
      pointsEarned: 150,
      status: 'completed',
    };

    const createdId = await repo.create(payload);
    expect(createdId).toBeGreaterThan(0);

    const entity = await repo.findById(createdId);
    expect(entity).not.toBeNull();
    expect(entity?.userId).toBe(seededUserId);
    expect(entity?.pointsEarned).toBe(150);
  });

  it('handles business edge case in repository query', async () => {
    const balance = await repo.calculateBalanceByUserId(seededUserId);
    expect(typeof balance).toBe('number');
    expect(balance).toBeGreaterThanOrEqual(0);
  });
});
