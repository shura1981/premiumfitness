import { describe, it, expect } from 'vitest';
// O alternativamente: import { describe, it, expect } from '@jest/globals';
import { validateOrderPayload } from '../../src/features/order/order.validator';
import { ValidationError } from '../../src/shared/errors/validation-error';

// Reemplazar validateOrderPayload por el validador real de la feature objetivo.
describe('OrderValidator (Unit)', () => {
  it('passes with a valid payload', () => {
    const validPayload = {
      trainerId: 'trainer-123',
      points: 50,
      clientDocument: '1098765432',
    };

    const result = validateOrderPayload(validPayload);
    expect(result).toBeDefined();
    expect(result.points).toBe(50);
  });

  it('fails when required fields are missing', () => {
    const invalidPayload = {};

    expect(() => validateOrderPayload(invalidPayload)).toThrow(ValidationError);
  });

  it('fails with invalid field formats or negative values', () => {
    const invalidPayload = {
      trainerId: '',
      points: -10,
      clientDocument: 'abc',
    };

    expect(() => validateOrderPayload(invalidPayload)).toThrow(ValidationError);
  });
});
