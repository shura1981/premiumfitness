# Configuración portable

Nombres adaptables, sin credenciales inventadas:

- Proveedor: URL, token, timeout, marca y países/región admitidos.
- OTP: longitud, TTL, máximo fallos, clave HMAC y versión.
- Cuotas: límites, ventanas, cooldown, presupuesto global, almacén y namespace servicio/tenant.
- Sesiones: reutilizar configuración existente, sin imponer JWT.

Validar al iniciar valores positivos, longitud soportada y secretos presentes. Sin secretos de desarrollo como respaldo. Inyectar configuración al dominio; entorno solo desde mecanismo del destino. Documentar nombres sin secretos en ejemplo y dejar credenciales al gestor existente.
