---
name: sms-account-verification
description: Implementar o replicar autenticación y verificación de teléfonos con códigos SMS de un solo uso y rate limiting de solicitudes e intentos en Node.js y TypeScript. Usar para OTP por SMS, reenvíos y validación segura con Express.
---

# Autenticación SMS y rate limiting portable (Node.js + TypeScript)

Implementa solicitar, reenviar y verificar códigos según el contrato del backend Node.js. El núcleo está implementado en TypeScript con adaptadores para Express y proveedores SMS.

## Procedimiento

1. Inspecciona dependencias locales (Node.js, Express, MySQL/Redis, configuración y pruebas). Reutilízalos.
2. Determina propósito: login, registro, verificar teléfono o segundo factor. Verificar teléfono no inicia sesión automáticamente. Segundo factor debe estar ligado al primer factor y solo completarlo. Identidad y destino se resuelven en servidor.
3. Implementa los contratos de [flujo y concurrencia](references/algorithms-and-flow.md), [datos](references/database-schema.md) y [seguridad y límites](references/security-and-rate-limiting.md).
4. Parte de las [plantillas ejecutables e integración](references/templates.md) para TypeScript (`templates/typescript/otp.ts` y `templates/typescript/otp.controller.ts`).
5. Prepara migraciones y [configuración](references/env-variables.md). No inventes credenciales ni sobrescribas configuración existente.
6. Ejecuta [aceptación](references/acceptance.md). Reporta cambios, checks ejecutados y cobertura pendiente.

## Invariantes

- Código criptográfico, temporal y de un solo uso. Nunca devolver OTP por API ni registrarlo.
- Vincular desafío a destino canónico, identidad si existe, propósito y contexto de autenticación cuando aplique.
- Solicitudes simultáneas no crean varios desafíos activos del mismo ámbito.
- Comparación en tiempo constante (`timingSafeEqual`), contador de fallos y consumo se serializan; solo una verificación exitosa completa el desafío.
- Limitar por destinatario/identidad además de IP, con contadores compartidos entre instancias.
- Fallar la entrega no autentica; aceptación del proveedor no prueba recepción.

La skill incluye el núcleo ejecutable y pruebas en TypeScript (`templates/typescript/`), además del controlador Express para su integración inmediata.
