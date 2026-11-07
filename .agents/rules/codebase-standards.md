---
description: 'Estándares de arquitectura, código y mejores prácticas para el repositorio de PremiumFitness. Aplica para cualquier modificación o creación de código.'
applyTo: '**/*.ts, **/*.js, **/*.json, **/*.html, **/*.css, **/*.scss'
---

# PremiumFitness — Estándares de código

## Priority Guidelines

When generating code for this repository:

1. **Version Compatibility**: Always detect and respect the exact versions of languages, frameworks, and libraries used in this project:
   - Node.js `v20.20.2` (as defined in `.nvmrc`)
   - TypeScript `^5.0`
   - Express.js `^4.19` (backend REST API)
   - Angular `^18.0` (frontend SPA para entrenadores y clientes)
   - MySQL / MariaDB (motor de persistencia relacional)
2. **Architectural Consistency**: Maintain our Vertical Slicing / Screaming Architecture style (`src/features`, `src/infrastructure`, `src/shared`) and established boundaries.
3. **Code Quality**: Prioritize maintainability, strict typing (`strict: true` in `tsconfig.json`), early returns, and security in all generated code.

## Technology Stack

1. **Language & Runtime**:
   - Node.js LTS (v20.20.2)
   - TypeScript strict mode (no `any` sin justificación, interfaces explícitas, type safety en parámetros y retornos).
2. **Backend Framework**:
   - Express.js con routers modulares por feature slice.
   - Middlewares: `express.json()`, `express.urlencoded()`, `cors`, `cookie-parser`, `helmet`.
3. **Database & ORM / Query Builder**:
   - MySQL pool de conexiones (`mysql2/promise`).
   - Consultas siempre parametrizadas contra inyecciones SQL.
4. **Authentication & Security**:
   - "The Shielded Architecture": Access Token (JWT en memoria, TTL 15m) + Refresh Token (`httpOnly`, `Secure`, `SameSite=Strict` cookie, TTL 7d con rotación y revocación).
5. **Testing**:
   - Vitest / Jest con TypeScript.
   - Supertest para E2E sobre Express.
   - Pruebas de integración sobre base de datos MySQL real.

## Organization & Feature Slices

Todavía no hay código de aplicación. Esta es la estructura objetivo del backend, alineada con los módulos de `docs/fases-de-desarrollo.md` (§2.2), y se valida en la fase de arquitectura. Si al crear el código se decide otra organización, actualiza esta sección.

El backend se organizará en Vertical Slices bajo `src/`:

```
src/
├── features/
│   ├── auth/                  # Registro, verificación de contacto, login, refresh token, recuperación, logout
│   ├── trainers/              # Perfil y área del entrenador
│   ├── clients/               # Perfil y área del cliente
│   ├── referrals/             # QR de clientes y de entrenadores, cookie de referente, atribución, relación A → B
│   ├── catalog/               # Productos, categorías, detalle
│   ├── orders/                # Carrito, pedidos con descuento (25% entrenador, 10% cliente), seguimiento
│   ├── payments/              # Pasarelas de pago y persistencia de respuestas (proveedor por definir)
│   ├── commissions/           # Comisión del 15% sin IVA, saldo, conversión
│   ├── points/                # Lotes de puntos, estados, vencimiento a 6 meses, consumo FIFO
│   ├── redemptions/           # Canje de productos con puntos a precio completo
│   └── reports/               # Reportes por período y entrenador: ventas, IVA, comisiones, puntos
├── infrastructure/
│   ├── database/              # Pool MySQL, migraciones, transacciones
│   ├── http/                  # Clientes HTTP externos (fetch con timeouts)
│   ├── mail/                  # Servicio de emails transaccionales
│   ├── sms/                   # Proveedor de mensajes SMS (OTP)
│   └── middlewares/           # Auth, error handling, rate limiting, validación
└── shared/
    ├── errors/                # AppError, ValidationError, NotFoundError, UnauthorizedError
    └── utils/                 # Criptografía, formateadores, constantes
```

Cada feature slice suele contener:
- `*.controller.ts`: Maneja HTTP Request/Response y valida inputs.
- `*.service.ts`: Lógica de negocio pura y orquestación.
- `*.repository.ts`: Interacción SQL parametrizada con la base de datos.
- `*.validator.ts`: Esquemas de validación (Zod o schemas tipados).
- `*.routes.ts`: Router Express de la feature.

## Code Quality Standards

### Maintainability & Clean Code
- Usar TypeScript estricto.
- Funciones cortas con **Early Return** para reducir anidación.
- Manejo asíncrono moderno con `async/await` (evitar cadenas de `.then().catch()`).
- Encapsular la lógica en servicios y repositorios; los controladores deben ser delgados.

### Security
- **Inyección SQL**: NUNCA concatenar strings en queries SQL. Usar siempre sentencias preparadas con placeholders `?`.
- **CORS**: Configurar con dominios permitidos desde `process.env.CORS_ALLOWED_ORIGINS`.
- **Tokens**: No exponer secrets ni guardar Access Tokens en `localStorage`.
- **Validación**: Sanitizar y validar todos los cuerpos de petición (`req.body`, `req.query`, `req.params`).
- **Rate Limiting**: Aplicar limitación de peticiones en endpoints sensibles (autenticación, SMS, OTP).

### Performance
- Reusar el pool de MySQL para todas las conexiones.
- Cerrar conexiones y recursos apropiadamente.
- Usar paginación (`LIMIT`, `OFFSET`) en listados grandes.

## Environment Variables

Todas las variables de entorno se validan en el inicio de la aplicación y se acceden de forma tipada:

```env
NODE_ENV=development|production
PORT=3000
DB_HOST=localhost
DB_PORT=3306
DB_USER=root
DB_PASS=secret
DB_NAME=premiumfitness
JWT_SECRET=super-secure-secret-minimum-32-chars
JWT_REFRESH_SECRET=super-secure-refresh-secret-minimum-32-chars
CORS_ALLOWED_ORIGINS=http://localhost:4200
```
