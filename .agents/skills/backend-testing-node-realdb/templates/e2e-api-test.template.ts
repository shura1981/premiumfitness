import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import request from 'supertest';
import { createApp } from '../../src/app';
import { Database } from '../../src/infrastructure/database/database';
import { JwtFactory } from '../support/jwt-factory';
import { Seed } from '../support/seed';

describe('Orders API (E2E - Express + Real DB)', () => {
  let app: ReturnType<typeof createApp>;
  let seededUserId: number;
  let authHeader: { Authorization: string };

  beforeAll(async () => {
    await Database.connect();
    app = createApp();
    seededUserId = await Seed.user({ role: 'trainer' });
    authHeader = JwtFactory.authHeader(seededUserId);
  });

  afterAll(async () => {
    await Database.disconnect();
  });

  it('rejects protected endpoint without JWT', async () => {
    const response = await request(app)
      .get('/api/orders');

    expect(response.status).toBe(401);
  });

  it('accepts request with valid JWT and valid payload', async () => {
    const validPayload = {
      clientDocument: '1098765432',
      points: 100,
      description: 'Puntos por membresía de cliente',
    };

    const response = await request(app)
      .post('/api/orders')
      .set(authHeader)
      .send(validPayload);

    expect(response.status).toBe(201);
    expect(response.body).toHaveProperty('id');
    expect(response.body.points).toBe(100);
  });

  it('returns validation error (422 or 400) with invalid payload', async () => {
    const invalidPayload = {
      points: -50,
    };

    const response = await request(app)
      .post('/api/orders')
      .set(authHeader)
      .send(invalidPayload);

    expect([400, 422]).toContain(response.status);
    expect(response.body).toHaveProperty('errors');
  });
});
