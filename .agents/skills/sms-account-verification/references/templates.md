# Plantillas ejecutables e integración

## Archivos

- [TypeScript: núcleo OTP, puertos y cuotas](../templates/typescript/otp.ts).
- [TypeScript: escenarios ejecutables](../templates/typescript/otp.test.ts), [configuración](../templates/typescript/tsconfig.json) y [scripts](../templates/typescript/package.json).
- [PHP: núcleo OTP, puertos y cuotas](../templates/php/Otp.php).
- [PHP: escenarios ejecutables](../templates/php/otp-test.php).

Copiar únicamente el lenguaje necesario y adaptar namespaces/estilo al destino. No sustituir su manifiesto por el package.json del ejemplo. No hay imports del backend de origen, frameworks HTTP, ORM ni dependencias de proveedor. PHP requiere 8.2+; también se verifica con PHP 8.5. Node debe soportar --experimental-transform-types (validado con Node 23.3); en proyectos con compilación usar su toolchain actual. Para comprobación estática TypeScript requiere TypeScript y @types/node del proyecto; el ejemplo no los instala automáticamente.

Desde templates/typescript: node --experimental-transform-types otp.test.ts (o npm test). Para tipos: tsc --noEmit con las herramientas del destino. Desde templates/php: php otp-test.php. No usar asserts PHP deshabilitables para estos escenarios: el ejecutor lanza excepciones explícitas ante fallo.

## Integración en el destino

1. Inyectar Store, Sender, secreto HMAC criptográficamente aleatorio y mensaje con marca/TTL. PHP también recibe reloj; TypeScript permite sustituirlo. Los secretos repetidos en tests son fixtures, nunca configuración real. Fijar presupuesto global de envíos: 100/h es un ejemplo, no una regla universal.
2. El adaptador HTTP limita tamaño y cuota de entrada antes de búsquedas/normalización costosas, valida DTO y normaliza teléfono con región/biblioteca existentes. Context se construye desde identidad, tenant, propósito y sesión resueltos en servidor. La IP viene de la conexión/proxy confiable, no del cuerpo. En flujos públicos sin contexto autenticado no aceptar un subject arbitrario del cliente.
3. request(context, ip) reserva cuotas y reemplaza el desafío. id es un identificador público aleatorio, no el OTP. Reenvío llama al mismo método y comparte presupuesto. El cuerpo SMS se crea internamente y solo se entrega a Sender.
4. verify(context, ip, id, code) devuelve éxito solo al consumir. Mapear limited a 429 con Retry-After y errores invalid a respuesta uniforme del contrato. Tras éxito completar únicamente el propósito autorizado mediante autenticación/sesiones existentes. No emitir tokens para una mera verificación de teléfono ni para un primer factor incompleto.
5. El núcleo devuelve un resultado de dominio, no una respuesta HTTP. Para usuarios desconocidos, aplicar cuotas equivalentes y devolver el mismo contrato público (incluido ID opaco si lo hay), sin envío. No exponer delivery como indicador de existencia. Conservar el 404 solo si se requiere explícitamente compatibilidad y documentar enumeración.
6. El núcleo consume el OTP antes de completar sesión. Si se necesita actualizar estado persistente del teléfono en la misma transacción, extender la operación de Store con ese efecto de dominio, sin HTTP dentro de ella. Una excepción posterior no rehabilita OTP.

## Almacenamiento y transporte

MemoryStore es solo demo/test de una instancia; en PHP se pierde entre requests. No usarlo para desplegar. TypeScript serializa callbacks síncronos en un proceso; PHP ejecuta secuencialmente sobre el mismo objeto. Esto NO prueba concurrencia distribuida ni sustituye pruebas con el motor real.

Store.atomic debe serializar desafío y cuotas entre todas las instancias, confirmar cambios al devolver rechazo de negocio y revertir excepciones. La plantilla usa una transacción amplia deliberadamente simple; adaptar a filas estables/bloqueos por ámbito o CAS exige conservar atomicidad. SQL siempre parametrizado en el repositorio del destino. No instalar un nuevo motor para ejecutar la demo. Almacén inaccesible debe propagar error transitorio; jamás sustituirlo silenciosamente por memoria.

Las cuotas son ventanas fijas iniciadas con el primer consumo, no ventanas alineadas. Se comprueban todas antes de consumir; un rechazo no aumenta otras cuotas. El cooldown y presupuestos por destino abarcan propósitos e IPs bajo el mismo secreto/servicio, para evitar evasión. El presupuesto global es por servicio; todos sus nodos deben compartir almacén y claves. Añadir cuotas por tenant si el producto lo requiere. Configurar expiración/limpieza de cuotas y desafíos terminales; MemoryStore no realiza limpieza automática.

La plantilla utiliza una sola clave HMAC y seis dígitos fijos para evitar configuración criptográfica ambigua. Si hay rotación en caliente, agregar key_version y resolución de claves según el modelo de datos. Rotar esta clave sin migración invalida desafíos y reinicia claves de cuota: no hacerlo sin una estrategia que conserve presupuestos. El namespace del almacén debe aislar servicios.

Sender convierte la respuesta real del proveedor a accepted, rejected o unknown. Rejected definitivo hace imposible verificar; timeout/excepción da unknown sin reintento automático. Política implementada: unknown sigue verificable hasta TTL porque pudo entregarse. Un fallo tardío solo afecta al ID que lo originó, nunca al reemplazo. No revelar errores sin sanitizar. Usar cliente HTTP del destino, timeout y la referencia opcional Relax Global cuando corresponda. Las plantillas no hacen HTTP ni envían SMS reales.

## Cobertura y límites

TypeScript prueba dos aciertos/solicitudes simultáneos en memoria, consumo único, intentos entre IPs, expiración, contexto, reenvío, cooldown, presupuesto por destino, fallo tardío y timeout. PHP prueba secuencia equivalente sin reclamar paralelismo: formato, respuesta sin OTP, cooldown, propósito, consumo único, intentos, reemplazo, caducidad y rechazo de entrega. Completar los escenarios de aceptación con el almacenamiento/proveedor del destino, especialmente concurrencia entre procesos, caída de almacén, entrada HTTP y uniformidad de respuestas.
