# 04 — Tareas de VS Code y build de producción

**Estado:** terminado · **Creado:** 24 de noviembre de 2026 · **Cierre inicial:** 24 de noviembre de 2026 · **Actualizado y cerrado:** 26 de noviembre de 2026

## Objetivo

Disponer en PremiumFitness de las tareas de VS Code de `bipro-gourmet.nutramerican.com` y generar un paquete de producción con la configuración propia de esta API.

## Alcance

- Las 10 tareas de referencia: servidor local, PHPStan, instalación completa y de producción, autoloader, todas las pruebas y sus tres suites, y empaquetado de producción.
- `.env.prod` privado, basado en `.env`, con entorno, dominios, CORS, retornos de pago y cron de PremiumFitness en producción.
- `scripts/build-prod.sh` y el alias `composer run build:prod`, con dependencias de producción instaladas desde `composer.lock` en un directorio temporal, sin modificar el `vendor/` local.
- Paquete `build/` con código, recursos, dependencias, `.env`, `.htaccess` y directorios de escritura vacíos. La credencial de Firebase se incorpora si existe en la ruta relativa configurada.
- Guía de todas las tareas y comandos, configuración pendiente y contenido del paquete.
- Sincronizar `.env.prod` con el `.env` actualizado de la copia de trabajo de la rama `dev` del mismo repositorio, preservando dominios, modo y credenciales propias de producción.

## Fuera de alcance

- Desplegar en el hosting, importar o modificar bases de datos, ejecutar cron o enviar SMS, correos y pagos reales.
- Compilar las apps Angular, que viven en otros repositorios y subdominios.
- Inventar credenciales de hosting o de proveedores que no estén en `.env`.

## Criterios de aceptación

1. Las 10 tareas de VS Code apuntan a comandos existentes y usan la raíz del workspace.
2. El empaquetado funciona desde cualquier directorio, instala únicamente dependencias de producción y deja intactos `.env` y las dependencias locales.
3. `build/.env` coincide con `.env.prod`, se valida con el bootstrap y usa `APP_ENV=production` y `SMS_DRIVER=relax`.
4. Si falta `.env.prod` o falla el empaquetado, el comando termina con error y conserva el build anterior.
5. El paquete excluye pruebas, scripts de desarrollo, documentación, datos locales de logs, cachés, reportes y comprobantes. Incluye las carpetas vacías que usa la API y, si está disponible, su credencial de Firebase.
6. `.env.prod` y `build/` siguen excluidos de Git. La documentación identifica los valores heredados que todavía requieren configuración real.
7. Se comprueban la sintaxis de Bash, las tareas JSON, Composer, PHPStan y el contenido de un build real.
8. La sincronización incorpora las claves y credenciales nuevas de `dev`, adapta sus retornos de pago al dominio de producción y valida ambos bootstraps. Solo se conserva como variable adicional `PAYMENT_GATEWAY_SPA_RETURN_URL`, porque todavía la exige el código de esta rama.
9. La guía y este spec no incluyen rutas absolutas de una máquina concreta. Explican cómo se resuelve la raíz del proyecto al mover o renombrar su carpeta y cómo conservar `.env.prod`.

## Supuestos y preguntas abiertas

| ID | Decisión | Clase |
| --- | --- | --- |
| P-01 | Se usa el nombre `.env.prod` solicitado; sustituye a `.prod.env`, que esperaba el script heredado. | Confirmado por el usuario |
| P-02 | API, web y admin usan los subdominios de PremiumFitness definidos en `.env.example` y las reglas del repo. | Confirmado por la documentación |
| P-03 | Las credenciales y constantes se heredan de `.env`; el usuario debe confirmar las de base de datos del hosting y completar los placeholders de correo, pasarelas, sockets y destinatarios. | Pendiente de configuración externa |
| P-04 | La cuenta de servicio de Firebase no está presente en este worktree. El build avisa si falta; sin ella, el envío push queda pendiente. | Pendiente de configuración externa |
| P-05 | La fuente de la sincronización del 26 de noviembre es el archivo privado `.env` del checkout `dev` (`3a2eef5`). El nombre de destino sigue siendo `.env.prod`, el que consume este empaquetador; no se crea un segundo `.prod.env`. | Derivado del estado de los archivos y del build |
| P-06 | No se integran los commits funcionales de `dev` en esta tarea de configuración. `PAYMENT_GATEWAY_SPA_RETURN_URL` se mantiene por compatibilidad con el bootstrap actual y se podrá retirar al integrar el código nuevo. | Derivado del alcance de sincronización |

## Plan

Reutilizar los comandos existentes de Composer y añadir el alias del paquete completo. Copiar las tareas de referencia y corregir el detector de arranque para el servidor PHP actual. Preparar el paquete en un directorio temporal, instalar allí las versiones fijadas por el lock y validar la configuración antes de sustituir `build/`. Crear `.env.prod` conservando claves y valores ajenos al entorno, sin modificar `.env`, y documentar los ajustes pendientes sin exponer secretos.

## Checklist

- [x] Revisar las tareas y el empaquetado del repositorio de referencia
- [x] Crear las 10 tareas de VS Code y el alias de empaquetado
- [x] Crear `.env.prod` desde `.env` y ajustar los valores del entorno
- [x] Adaptar el script de producción y las carpetas privadas de PremiumFitness
- [x] Documentar tareas, comandos, contenido del build y configuración pendiente
- [x] Validar configuración, build real, errores controlados y PHPStan
- [x] Sincronizar la compra mínima, las siete credenciales actualizadas y los retornos por pasarela desde el `.env` de `dev`
- [x] Documentar la variable conservada por compatibilidad y actualizar el estado de las credenciales
- [x] Comprobar las 104 variables de `dev`, los valores propios de producción y la validación con ambos bootstraps, sin alterar los `.env` locales
- [x] Quitar las rutas absolutas de la documentación de despliegue y explicar la portabilidad del proyecto

## Verificación (24 de noviembre de 2026)

- Las 10 tareas referencian comandos existentes; Bash, PHP del empaquetador, JSON, `composer validate --strict` y `git diff --check` pasan.
- `composer run lint`: 217 archivos analizados, sin errores.
- `composer run build:prod`: paquete de 9,1 MB con los 33 paquetes de producción y las versiones de `composer.lock`; `build/.env` coincide con `.env.prod`. Se conservaron todas las variables originales, con 14 ajustes de entorno y URLs. Los retornos de Addi y Sistecrédito apuntan a `/pago/resultado` en la app web; el backend solo tiene `/response/bold`.
- Las huellas de `.env`, `.env.prod`, `composer.lock` y los archivos de `vendor/composer/` permanecen idénticas tras el empaquetado. Pest y PHPStan locales siguen disponibles.
- Casos en directorios desechables: ausencia de `.env.prod`, entorno inválido y fallo de Composer terminan con error, conservan el build anterior y no dejan temporales. Un build completo desde otro directorio y con espacios en la ruta funciona.
- Se comprobó la copia de una credencial de Firebase ficticia con permisos `600`, excluyendo un comprobante y un log locales. El build real avisa de que falta la cuenta de servicio.
- No se ejecutaron las suites Pest: el cambio afecta al empaquetado y las tareas, sin modificar la lógica de la API ni las bases de datos. Las credenciales externas pendientes de P-03 y P-04 siguen documentadas en `docs/despliegue.md`.

## Sincronización verificada (26 de noviembre de 2026)

- Fuente: archivo `.env` del checkout `dev` en el commit `3a2eef5`. Los cambios versionados de `.env.example` y los dos bootstraps confirman la compra mínima y los retornos por pasarela.
- `.env.prod` contiene las 104 claves de la fuente y la variable adicional de compatibilidad. Se añadió `TRAINER_REFERRAL_MIN_PURCHASE=149000` y se actualizaron diez valores existentes: siete credenciales y tres retornos; los webhooks ya apuntaban al dominio correcto de producción.
- Se conservaron los valores propios de producción y ambos `.env` locales. Las credenciales coinciden con la fuente, sin incluir sus valores en la documentación ni en Git.
- Ambos bootstraps validan la configuración mediante directorios desechables, sin levantar la API ni conectar con bases de datos o proveedores.
- Se regeneró `build/` (9,1 MB) con el código de esta rama y `build/.env` idéntico a `.env.prod`. Los permisos de ambos archivos son `600`; los archivos de Composer y las dependencias locales no cambiaron. El checkout `dev` permanece limpio.
- `composer run lint` y `git diff --check` pasan. No se cambiaron archivos PHP ni se ejecutaron las suites Pest.
- Se retiraron las rutas absolutas de la guía y de este spec. La revisión de las diez tareas y del script confirma que la raíz se obtiene con `${workspaceFolder}` y desde la ubicación del script, respectivamente; la guía explica cómo conservar `.env.prod` al mover o clonar el proyecto.
