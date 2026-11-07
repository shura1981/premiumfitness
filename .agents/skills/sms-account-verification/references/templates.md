# Plantillas ejecutables e integración (Node.js + TypeScript)

## Archivos

- [TypeScript: núcleo OTP, puertos y cuotas](../templates/typescript/otp.ts).
- [TypeScript: controlador Express](../templates/typescript/otp.controller.ts).
- [TypeScript: escenarios ejecutables y tests](../templates/typescript/otp.test.ts), [configuración](../templates/typescript/tsconfig.json) y [scripts](../templates/typescript/package.json).

Usar el toolchain estándar de TypeScript/Node.js del proyecto (`npm test` o `npx vitest` / `ts-node`).

Desde `templates/typescript`:
```bash
npm test
```

## Integración en Express / Node.js

1. **Inyección de Dependencias**: Inyectar `Store`, `Sender`, secreto HMAC criptográficamente aleatorio (mínimo 32 bytes) y función generadora de mensaje con marca/TTL.
2. **Controlador HTTP**: El controlador Express valida el formato canónico del teléfono (+57...), resuelve la IP del cliente (`req.ip` o `x-forwarded-for`), y construye el `Context`.
3. **Solicitud (`request`)**: `otpService.request(context, ip)` reserva cuotas y reemplaza el desafío. Devuelve un `challengeId` opaco (UUID), nunca el código OTP.
4. **Verificación (`verify`)**: `otpService.verify(context, code, ip)` consume el OTP de forma atómica y valida el intento en tiempo constante (`timingSafeEqual`).
5. **Rate Limiting**: El servicio gestiona de forma integrada el cooldown entre reenvíos (60s), límites por IP y límites por destino.

## Almacenamiento y transporte

Para producción en Node.js, implementar `Store` sobre Redis o MySQL transaccional con bloqueos atómicos, no memoria volatil.
`Sender` encapsula el cliente HTTP de envíos SMS (Relax Global, Twilio, etc.) y mapea los resultados a `accepted`, `rejected` o `unknown`.
