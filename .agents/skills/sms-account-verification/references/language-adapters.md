# TypeScript / Node.js Adapters

Guía de adaptación para Node.js y TypeScript:

| Responsabilidad | Implementación Node.js / TypeScript | Detalle de seguridad |
| --- | --- | --- |
| Seis dígitos | `crypto.randomInt(0, 1_000_000).toString().padStart(6, '0')` | CSPRNG uniforme y seguro |
| MAC | `crypto.createHmac('sha256', key).update(payload).digest('hex')` | HMAC-SHA256 estándar |
| Comparar | `crypto.timingSafeEqual(Buffer.from(a), Buffer.from(b))` | Comparación en tiempo constante contra timing attacks |
| Datos | MySQL pool transaccional / Redis con lock atómico | Transacciones ACID o comandos atómicos |
| HTTP | `fetch` nativo de Node.js o `axios` | Timeout explícito y errores tipados |

## Reglas de Arquitectura en Node.js

1. **Desacoplamiento del Dominio**: El núcleo `OtpService` no depende de Express, Fastify o librerías HTTP.
2. **Controladores y Middlewares Express**: Los controladores HTTP (`OtpController`) traducen DTOs, validan formato telefónico E.164, extraen la IP y devuelven respuestas HTTP (200, 400, 429).
3. **Manejo de Errores**: Nunca exponer excepciones internas ni el código OTP en logs o respuestas JSON.
