# Tareas de VS Code y build de producción

Las tareas de `.vscode/tasks.json` reproducen las **10 acciones** de `bipro-gourmet.nutramerican.com`, adaptadas a esta API. Se abren desde **Terminal → Ejecutar tarea**. **Ctrl+Shift+B** genera el paquete de producción. Todas usan la raíz del workspace.

## Todos los comandos

| Tarea de VS Code | Comando desde la raíz del proyecto |
| --- | --- |
| 🚀 Iniciar Servidor (PHP Dev Server) | `composer run start --timeout=0` |
| ✨ Linter: Revisar Código con PHPStan | `composer run lint` |
| 📦 Composer: Instalar COMPLETO (Prod + Dev) | `composer run install:dev` |
| 🚀 Composer: Instalar LIGERO (Solo Producción) | `composer run build` |
| 🔄 Composer: Regenerar Autoloader (PSR-4) | `composer run dump` |
| 🧪 Tests: Ejecutar Todos (Pest) | `composer run test` |
| 🧪 Tests: Solo Unit | `composer run test:unit` |
| 🧪 Tests: Solo Integration | `composer run test:integration` |
| 🧪 Tests: Solo E2E | `composer run test:e2e` |
| 📦 Generar Build de Producción | `composer run build:prod` o `bash scripts/build-prod.sh` |

El linter instala primero las dependencias completas, como en la referencia. Desde terminal, ejecuta `composer run install:dev` antes del linter o de las pruebas si todavía no tienes `vendor/`.

`composer run build` instala dependencias de producción en el `vendor/` actual y quita las de desarrollo. Para volver a usar PHPStan y Pest después de ese comando, ejecuta `composer run install:dev`. `composer run build:prod` genera el paquete completo y conserva las dependencias locales: instala las de producción en un directorio temporal con las versiones de `composer.lock`.

El servidor de desarrollo escucha en `http://localhost:8000`. Las pruebas usan la base local configurada y su intranet de pruebas `crisenri_intranet_pruebas`; los archivos REST Client de `tests/E2E/http/` son manuales y no los ejecuta Pest.

## Configuración de producción

El archivo privado es **`.env.prod`** en la raíz, excluido de Git. Se creó a partir de `.env`, conservando sus claves, constantes y credenciales. El empaquetador lo copia como **`build/.env`** y no modifica `.env`. El nombre que consume este build es `.env.prod`; no se mantiene otro archivo `.prod.env`.

**Última sincronización: 26 de noviembre de 2026**, desde el `.env` privado de la copia de trabajo de la rama `dev` del mismo repositorio (commit `3a2eef5`). Se incorporó `TRAINER_REFERRAL_MIN_PURCHASE=149000`, se actualizaron siete credenciales de Addi, Bold y Sistecrédito, y se adaptaron los retornos de las tres pasarelas al dominio de producción. Ambos `.env` locales quedaron intactos.

Se ajustaron estos valores al entorno de producción:

- `APP_ENV=production` y `SMS_DRIVER=relax`.
- `APP_URL=https://premiumfitness-api.nutramerican.com`.
- `WEB_APP_URL=https://premiumfitness.nutramerican.com` y `ADMIN_APP_URL=https://premiumfitness-admin.nutramerican.com`.
- `CORS_ALLOWED_ORIGINS` con los dos dominios de las apps, sin localhost.
- Webhooks de Addi y Sistecrédito y URL del cron con el dominio de la API.
- `ADDI_URL_REDIRECT=https://premiumfitness.nutramerican.com/pago/resultado/addi`.
- `SISTECREDITO_URL_RESPONSE=https://premiumfitness.nutramerican.com/pago/resultado/sistecredito`.
- `BOLD_URL_RESPONSE=https://premiumfitness.nutramerican.com/pago/resultado/bold`.
- `TRAINER_REFERRAL_MIN_PURCHASE=149000`, el mínimo de productos con descuento e IVA, sin flete, que activa los puntos del referido.
- `DIET_ACCESS_RENEW_URL=https://premiumfitness.nutramerican.com/coach/dietas`.

Esta sincronización modifica la configuración de producción; los commits funcionales de `dev` todavía no están integrados en esta rama. Se conservan sus **104 variables**, más `PAYMENT_GATEWAY_SPA_RETURN_URL=https://premiumfitness.nutramerican.com/pago/resultado` por compatibilidad con el bootstrap actual. Al integrar el código de `dev`, que ya retiró esa variable, se puede eliminar. El build empaqueta el código de la rama donde se ejecuta.

### Valores que requieren revisión antes de desplegar

El archivo de origen contiene valores locales y placeholders. Crear el paquete valida la estructura del entorno; no comprueba credenciales con servicios externos.

| Configuración | Revisión necesaria |
| --- | --- |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Se heredaron de `.env`. Confirmar los datos del hosting y permisos de lectura sobre `crisenri_intranet`; no se tomaron credenciales de otros proyectos. |
| `MAIL_*` | Sustituir los datos de ejemplo del remitente, usuario y contraseña por los de SMTP real. |
| `SISTECREDITO_SUBSCRIPTION_KEY`, `SISTECREDITO_APPLICATION_KEY`, `SISTECREDITO_APPLICATION_TOKEN` | Sincronizadas con el `.env` de `dev` el 26 de noviembre; confirmar que corresponden a producción y `SISTECREDITO_SANDBOX_ACTIVE=false`. |
| `ADDI_CLIENT_ID`, `ADDI_CLIENT_SECRET` | Sincronizadas con el `.env` de `dev` el 26 de noviembre; confirmar que corresponden a producción. |
| `BOLD_IDENTITY_KEY`, `BOLD_SECRET_KEY` | Sincronizadas con el `.env` de `dev` el 26 de noviembre; confirmar que corresponden a producción. |
| `SOCKET_NOTIFICATION_TOKEN`, `SOCKET_GATEWAY_KEY_ADMIN` | Completar las claves de los proyectos PremiumFitness. |
| `COMMISSION_REPORT_RECIPIENTS` | Reemplazar el destinatario de ejemplo de financiero. |
| `JWT_SECRET`, `JWT_REFRESH_SECRET`, `CRON_JOB_TOKEN`, `DIET_ACCESS_API_KEY` | Se conservaron los valores del archivo de origen; confirmar los que se usarán en producción y coordinar las claves compartidas con sus consumidores. |
| `FIREBASE_CREDENTIALS_PATH` | Poner la cuenta de servicio en la ruta configurada (por defecto `storage/private/firebase-fcm-sender.json`) antes de generar el build, o incorporarla en el servidor. No está presente en este worktree. |

## Generar el paquete

Requiere Bash, PHP 8.5 con las extensiones de Composer y Composer en `PATH`:

```bash
composer run install:dev
composer run lint
composer run build:prod
```

Puedes mover o renombrar la carpeta completa del proyecto. `scripts/build-prod.sh` calcula la raíz a partir de su propia ubicación y las tareas de VS Code usan `${workspaceFolder}`. `.env.prod` se busca en esa raíz y `build/` se genera allí; ninguna de esas ubicaciones depende del nombre de tu usuario o de una carpeta fija. Conserva la estructura interna del proyecto y el archivo `.env.prod` al moverlo. Si creas una copia mediante Git, debes copiar o configurar `.env.prod` aparte porque no se versiona.

El script también se puede ejecutar desde otro directorio indicando su ubicación actual. No necesita que exista un `vendor/` local.

El comando falla si falta `.env.prod`, si no se valida con `load/bootstrap.php`, si no usa `APP_ENV=production` y `SMS_DRIVER=relax`, o si Composer no puede instalar. El build anterior se conserva cuando falla la preparación. Si falta la cuenta de Firebase, se genera con un aviso; el envío push requiere esa cuenta.

El directorio `build/`, también excluido de Git, incluye:

- `app/`, `assets/`, `cron-jobs/`, `load/`, `src/`, `views/` y `vendor/` sin dependencias de desarrollo.
- `index.php`, `.htaccess`, `.env` y `composer.json` sin `scripts`, `autoload-dev` ni `require-dev`. El lock se usa durante la instalación y se omite del paquete porque corresponde al manifiesto original.
- `cache/twig/`, `cache/data/`, `logs/`, `temp/`, `storage/private/reports/` y `storage/private/receipts/` vacíos, además de las rutas privadas configuradas si son distintas.
- La cuenta de Firebase, si existe. Solo se incorpora ese archivo privado; no se copian reportes, comprobantes ni otros datos locales.

Las rutas de reportes, comprobantes y la cuenta de Firebase deben ser relativas dentro de `storage/private/`, protegida por `.htaccess`. `.env` y la credencial se generan con permisos `600`; ajusta propietario o permisos en el hosting para que PHP pueda leerlos. PHP necesita escritura en `cache/`, `logs/`, `temp/` y las carpetas privadas (por ejemplo, `775` con propietario/grupo apropiados).

El contenido de `build/`, incluidos `.env` y `.htaccess`, se sube al document root de `premiumfitness-api.nutramerican.com`. La importación inicial del esquema de `docs/database/schema/crisenri_premiumfitness.sql` y la configuración de cron se realizan aparte. Las apps Angular se compilan y publican desde sus propios repositorios y subdominios.
