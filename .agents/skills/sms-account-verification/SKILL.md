---
name: sms-account-verification
description: Implementar o replicar autenticación y verificación de teléfonos con códigos SMS de un solo uso y rate limiting de solicitudes e intentos. Portable entre lenguajes, frameworks, almacenes y proveedores, con adaptación a TypeScript y PHP. Usar para OTP por SMS y reenvíos, no para campañas SMS.
---

# Autenticación SMS y rate limiting portable

Implementa solicitar, reenviar y verificar códigos según el contrato del destino. El núcleo es independiente del lenguaje; TypeScript y PHP son adaptaciones, no requisitos.

## Procedimiento

1. Inspecciona instrucciones locales, framework, autenticación, validadores, migraciones, configuración, almacenamiento, cliente HTTP y pruebas. Reutilízalos: no impongas Express, Slim, MySQL, Redis, JWT ni una tabla users.
2. Determina propósito: login, registro, verificar teléfono o segundo factor. Verificar teléfono no inicia sesión automáticamente. Segundo factor debe estar ligado al primer factor y solo completarlo. Identidad y destino se resuelven en servidor.
3. Al replicar Radical Nutrition consulta [origen](references/source-behavior.md). Separa comportamiento observado de mejoras. Conserva contratos explícitos y documenta sus compromisos.
4. Implementa los contratos de [flujo y concurrencia](references/algorithms-and-flow.md), [datos](references/database-schema.md) y [seguridad y límites](references/security-and-rate-limiting.md).
5. Parte de las [plantillas ejecutables e integración](references/templates.md) para TypeScript/PHP. Lee también [adaptaciones](references/language-adapters.md); en otro lenguaje utiliza equivalentes. Consulta [Relax Global](references/sms-provider-relax-global.md) solo si corresponde; marca y región son configuración.
6. Prepara migraciones y [configuración](references/env-variables.md). Ejecuta dentro del alcance autorizado y las reglas locales: esta skill no autoriza migraciones en producción, SMS reales, instalación de servicios ni reemplazo de secretos. No inventes credenciales ni sobrescribas configuración existente.
7. Ejecuta [aceptación](references/acceptance.md). Reporta cambios, checks ejecutados y cobertura pendiente, distinguiendo verificación local de entrega real.

## Invariantes

- Código criptográfico, temporal y de un solo uso. Nunca devolver OTP por API ni registrarlo.
- Vincular desafío a destino canónico, identidad si existe, propósito y contexto de autenticación cuando aplique.
- Solicitudes simultáneas no crean varios desafíos activos del mismo ámbito.
- Comparación, contador de fallos y consumo se serializan; solo una verificación completa el desafío.
- Limitar por destinatario/identidad además de IP, con contadores compartidos entre instancias.
- Fallar la entrega no autentica; aceptación del proveedor no prueba recepción.
- No añadir fallback a email ni otro mecanismo de sesión salvo requisito del producto.

La skill incluye núcleos ejecutables y pruebas en TypeScript y PHP, además de contratos para otros lenguajes. Los adaptadores en memoria son solo demostraciones; para producción integra almacenamiento compartido y el transporte real. No necesita el repositorio de origen ni herramientas exclusivas de un agente.
