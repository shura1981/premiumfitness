// tests/helpers/auth.ts
// Firma con la MISMA función que producción y el secreto fijo de test.
import { signToken } from "@shared/infrastructure/auth/jwt"; // ajusta la ruta
import { TEST_JWT_SECRET } from "./createTestApp";

export function generateTestToken(): string {
	return signToken({
		expiresIn: "1h",
		payload: { email: "test@example.com", id: 1 },
		secret: TEST_JWT_SECRET
	});
}
