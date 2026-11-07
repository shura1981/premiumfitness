# Seguridad y límites

Valores iniciales configurables para un sistema nuevo: seis dígitos incluyendo ceros iniciales, TTL 300 s, tres fallos por desafío, cooldown 60 s/destino; solicitudes 3/min/IP y 5/h/destino; verificaciones 5/min/IP y 10/15 min/destino o identidad. Ajustar a riesgo y tráfico; no son garantías universales ni sustituyen presupuesto global SMS.

Separar solicitar y verificar; reenvío comparte presupuesto de solicitud. Cuotas por destino/identidad sobreviven reenvíos aunque cada desafío tenga contador propio. Namespace por servicio/tenant y propósito sin permitir evadir presupuesto global del destino cambiando propósito.

Elegir ventana fija/deslizante o token bucket y documentar ráfagas: ventana fija permite doble cuota cerca de frontera. Consumir/reservar atómicamente con expiración en almacén compartido entre workers/servidores. Memoria local solo pruebas o instancia única explícita; archivos requieren comprobar bloqueo y alcance, no garantizan cuota global.

IP desde conexión o proxies confiables configurados, no X-Forwarded-For arbitrario. Definir tratamiento IPv6 con plataforma. Evitar bloqueos permanentes inducidos remotamente. HMAC en claves de cuota evita teléfonos legibles pero sigue siendo identificador sensible.

429 incluye Retry-After positivo según bloqueo efectivo: si varias cuotas rechazan, indicar cuándo todas permitirán reintentar. Sin SMS ante rechazo. Caída del almacén bloquea por defecto envíos/verificaciones sin garantía de cuota o consumo; devolver error transitorio sin autenticar.

Normalizar con biblioteca mantenida y región configurada; no asumir Colombia ni eliminar caracteres sin validar. E.164 es representación canónica habitual; adaptar al proveedor en el borde y restringir países según cobertura/producto.

Flujos públicos nuevos: respuesta uniforme para usuarios conocidos/desconocidos sin registered. Revisar cuerpo, status, cooldown y tiempos; texto genérico solo no elimina enumeración. Error de verificación uniforme para incorrecto, usado, bloqueado o expirado.

No registrar OTP, MAC, Authorization, cuerpo SMS ni teléfono completo. Sanitizar errores del proveedor. Métricas agregadas de envíos, cuotas y coste detectan abuso. SMS no resiste phishing ni secuestro de SIM: conservar vínculo al primer factor y recuperación si es segundo factor.
