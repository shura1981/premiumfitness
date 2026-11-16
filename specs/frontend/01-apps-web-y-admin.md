# 01 — Frontends: app web y admin

**Estado:** en curso · **Creado:** 15 de noviembre de 2026

## Petición original

> Los dos repos de frontend créalos en `/home/steven/Proyectos/node/angular/`. Ya tienes el sistema de diseño.
>
> Subdominios: `premiumfitness-api.nutramerican.com`, `premiumfitness.nutramerican.com` y `premiumfitness-admin.nutramerican.com`.

Los repos se llaman `premiumfitness-web` y `premiumfitness-admin` (15 nov 2026).

## Objetivo

Tener los dos frontends de PremiumFitness creados y funcionando en local, con el sistema de diseño aplicado, para avanzar por pantallas mientras se construye el backend.

## Alcance

| Repo | Qué es | Subdominio |
| --- | --- | --- |
| `/home/steven/Proyectos/node/angular/premiumfitness-web` | PWA Angular 22 para clientes y entrenadores, con un área por rol (spec `arquitectura/02`) | `premiumfitness.nutramerican.com` |
| `/home/steven/Proyectos/node/angular/premiumfitness-admin` | Fork de `admin-radical-nutrition` actualizado a Angular 22 (spec `diseño/02`, S-03) | `premiumfitness-admin.nutramerican.com` |

- **Base técnica de los dos:** Angular 22, Tailwind 4, tokens del sistema de diseño (claro y oscuro), alias de imports, entornos con la URL de la API y `.htaccess` para desplegar como estáticos.
- **App web:** componentes del sistema de diseño, sesión (registro, verificación por SMS, login, recuperación, guards por rol e interceptor), navegación por rol, tema y color de acento, tienda y detalle de producto, carrito, checkout y resultado del pago, pedidos y su seguimiento, cuenta (perfil, contraseña, direcciones y notificaciones), cupón del cliente, el área del entrenador (Inicio, Referidos, Puntos, Comisión y conversión, canje) y la PWA.
- **Admin:** el fork en Angular 22 con la marca de PremiumFitness, la sesión con cookie del spec `seguridad/01`, sin el saldo a favor de radical, y las pantallas nuevas: cierre mensual y pago con comprobante, entrenadores, estrategias por producto y ajustes.
- **API simulada:** cada app puede correr en desarrollo con datos de ejemplo detrás de un interceptor, sin el backend. En producción ese código no se incluye.

## Fuera de alcance

- El backend (fork de radical): tiene su propio trabajo.
- Probar el pago con las llaves reales de Bold y el push en dispositivos reales.
- Publicar los repos en GitHub y desplegar.

## Criterios de aceptación

1. `npm run build`, `npm run lint` y `npm test` pasan en `premiumfitness-web`; `npm run build` pasa en `premiumfitness-admin`.
2. La app web usa solo tokens del sistema de diseño, funciona en tema claro y oscuro, y el acento elegido se conserva al recargar.
3. El cliente entra y se registra en la raíz (`/entrar`, `/registro`) y el entrenador en `/coach` (`/coach/entrar`, `/coach/registro`). Ninguno de los dos logins abre sesión con una cuenta del otro rol. Cada uno ve su navegación: Tienda · Pedidos · Carrito · Cuenta, o Inicio · Tienda · Referidos · Puntos · Cuenta.
4. Todo lo del entrenador cuelga de `/coach/**`. Un cliente no puede abrir esas rutas y su navegador no descarga el código del área del entrenador; un entrenador que abre una ruta de cliente va a la misma ruta bajo `/coach`.
5. Un import de una feature dentro de otra hace fallar el lint (spec `arquitectura/02`, regla de aislamiento).
6. Los precios, saldos y puntos se muestran con el formato del sistema (`$224.910`, `11.246 pts`) y la app no calcula dinero: pinta lo que llega de la API.
7. El build de producción no incluye la API simulada ni sus datos.

## Supuestos y preguntas abiertas

| ID | Supuesto | Clase |
| --- | --- | --- |
| F-01 | Los repos van en `/home/steven/Proyectos/node/angular/` y se llaman `premiumfitness-web` y `premiumfitness-admin`. | Confirmado (15 nov 2026) |
| F-02 | Se usa el Node 25.1.0 ya instalado con `nvm`, fijado en `.nvmrc`. Angular 22 lo marca como no soportado (pide 22.22, 24.15 o 26), pero compila; solo se usa para construir. | Confirmado (15 nov 2026) |
| F-03 | Se empieza a codificar sin rehacer ni aprobar los mockups: el sistema de diseño ya está aprobado («quedó perfecto», 14 nov 2026). Reemplaza el criterio 6 del spec `diseño/02`. | Confirmado (15 nov 2026) |
| F-04 | Las rutas de la app web van en español. El cliente usa la raíz (`/entrar`, `/registro`, `/tienda`, `/pedidos`, `/carrito`, `/cuenta`) y el entrenador `/coach` con las mismas pantallas compartidas (`/coach/entrar`, `/coach/registro`, `/coach/tienda`…) y las suyas (`/coach`, `/coach/referidos`, `/coach/puntos`, `/coach/comision`, `/coach/canje`). Reemplaza `/entrenador/**` y el login único (16 nov 2026, spec `seguridad/01`, S-08). Los enlaces del entrenador siguen siendo `/r/c/<id>` y `/r/t/<id>`. | Confirmado (16 nov 2026) |
| F-05 | El contrato de la API es `specs/backend/03-api-de-premiumfitness.plan.md`, el mismo para el backend, la app web y el admin. Reemplaza los contratos provisionales del plan de este spec. | Confirmado (16 nov 2026) |
| F-09 | En local hay dos modos: `npm start` usa la API simulada y `npm run start:api` el backend de `localhost:8000` (web en el puerto 4200 y admin en el 4201, los orígenes que admite el CORS local). | Derivado |
| F-10 | El SDK de Firebase de la app web queda fijo en la 13.1.0 (Apache-2.0): se evita instalar versiones publicadas hace menos de dos semanas. | Derivado |
| F-11 | Ni la app web ni el admin se indexan en buscadores: `<meta name="robots" content="noindex, nofollow">` y el header `X-Robots-Tag`. No se bloquean con `robots.txt`: Google necesita leer la página para ver el `noindex`. | Confirmado (16 nov 2026) |
| F-06 | El admin se crea clonando `admin-radical-nutrition` con su historial; el remoto original queda con el nombre `radical`, solo para traer cambios. | Derivado |
| F-07 | Los iconos son Material Symbols Outlined como SVG dentro de la app (solo los que se usan), no la fuente completa desde Google Fonts: pesa menos y funciona sin conexión. | Derivado |
| A-01 | El admin usa ApexCharts 4.7.0 y `ng-apexcharts` 1.17.1, las versiones de radical, con licencia MIT y versión fija. La actualización a Angular 22 las había subido a la 8, que ya no es MIT; se revirtió el 15 nov 2026. **No se usan paquetes de pago ni con licencia restringida.** | Confirmado (15 nov 2026) |
| A-02 | El admin usa el gateway de WebSockets compartido (`socket.elitenutapp.com`) con el proyecto `premiumfitness-admin`. | Derivado |
| F-08 | La app web usa la letra del sistema (S-05 del spec `diseño/02`); el admin conserva la tipografía de radical hasta decidir si adopta Montserrat. | Derivado |

## Estado al 16 de noviembre de 2026

- **App web (16 nov 2026):** `npm run build`, `npm run lint` y `npm test` (161 pruebas) pasan. Todas las pantallas del checklist están hechas y se recorrieron en el navegador contra la API simulada y contra el backend local.
- **Admin (16 nov 2026):** `npm run build` y `npm test` (127 pruebas) pasan. Las pantallas nuevas se recorrieron en el navegador contra la API simulada y contra el backend local.
- Ninguno de los dos repos tiene commits nuevos: los cambios están en el árbol de trabajo.

## Plan

Plan técnico: [01-apps-web-y-admin.plan.md](01-apps-web-y-admin.plan.md).

## Checklist

### Base

- [x] Crear `premiumfitness-web` con Angular 22, Tailwind 4, ESLint con `eslint-plugin-boundaries`, Prettier, alias y `.nvmrc`
- [x] Clonar `admin-radical-nutrition` como `premiumfitness-admin` y actualizarlo a Angular 22
- [x] Entornos con la URL de la API y `.htaccess` de despliegue en los dos repos

### App web

- [x] Tokens del sistema de diseño (claro y oscuro), tipografía y clases base
- [x] Componentes del sistema de diseño en `shared/ui`
- [x] Tema (sistema, claro y oscuro) y color de acento con su derivación, con pruebas de contraste
- [x] Formato de dinero, puntos y fechas, con pruebas
- [x] Sesión: login, token en memoria, renovación, interceptor y guards por rol
- [x] API simulada para desarrollo, excluida del build de producción
- [x] Navegación por rol: barra inferior y riel lateral
- [x] Enlaces `/r/c/<id>` y `/r/t/<id>`
- [x] Tienda con categorías, búsqueda y bloque de precio (regla 14)
- [x] Carrito
  - Muestra líneas, cantidades y subtotal. El botón «Ir a pagar» queda desactivado hasta que exista el checkout.
- [x] Pedidos
- [x] Cuenta: apariencia (tema y acento) y cerrar sesión
- [x] Cliente: asociar cupón
- [x] Entrenador: Inicio con saldos y acceso a dietas
- [x] Entrenador: Referidos con los dos enlaces
- [x] Entrenador: Puntos
- [x] Entrenador: Comisión y conversión
- [x] Registro, verificación por SMS y recuperación de contraseña (el entrenador puede elegir su cupón)
- [x] Detalle de producto con galería y presentaciones
- [x] Checkout y resultado del pago
  - Probado contra el backend local simulando el aviso de Bold (`scripts/dev-pagar-pedido.php`). Falta probar el botón real de Bold con las llaves de PremiumFitness.
- [x] Detalle del pedido con seguimiento, con «Pagar» si quedó pendiente
- [x] Canje con puntos
- [x] Cuenta: perfil, contraseña, direcciones y notificaciones push
  - Las notificaciones quedan como «no disponibles» hasta poner la configuración web de Firebase en el entorno: falta la `apiKey` (el resto está en el spec `integraciones/01`).
- [x] PWA: manifest, service worker y guía de instalación en iOS
  - Falta probar la instalación y el push en un iPhone real.
- [x] Conectar la app al backend real (`npm run start:api`) y recorrer los flujos sin la API simulada (16 nov 2026)
- [x] Separar el login y el registro por rol y mover el área del entrenador a `/coach/**` (F-04, S-08; 16 nov 2026)
- [x] Pantallas de acceso: título y logo más grandes (hasta 42 px; en pantallas angostas, el 11 % del ancho del formulario, sin desplazamiento lateral)
- [x] Imágenes de producto con skeleton mientras cargan, carga diferida y la tienda con tarjetas de esqueleto (directiva `pfImage` en `shared/ui/lazy-image`)
- [x] Que buscadores no indexen la app web: `noindex` en `index.html` y `X-Robots-Tag` en el `.htaccess` (F-11)
- [x] Inicio del entrenador rediseñado: tarjeta de puntos en el color de acento con su origen, atajos, fichas por origen, comisión con el avance hasta el cierre y movimientos con icono (16 nov 2026)
- [x] Esqueletos con brillo que recorre la forma (también en las imágenes que cargan), en lugar del parpadeo
- [x] Llevar el mismo estilo a Puntos (lotes con barra de remanente y vencimiento), Comisión (avance al cierre y hoja de conversión «comisión → puntos») y Referidos (fichas que eligen la lista y enlace en el color de acento)
- [ ] Pantalla para canjear el acceso del mes a la app de dietas (`POST /trainer/diet-access/redeem`): Inicio solo muestra el estado
- [ ] Pruebas unitarias de las pantallas de `features/` (hoy se verifican en el navegador; `core/` y `shared/` sí tienen)

### Admin

- [x] Marca, logo y tokens de PremiumFitness
- [x] Apuntar a `premiumfitness-api.nutramerican.com` y sesión según el spec `seguridad/01`: token en memoria, cookie `pf_rt_admin`, renovación ante un 401 y nada en `localStorage`
- [x] Quitar las pantallas de saldo a favor retail
- [x] Cierre mensual: períodos, Excel, registrar el pago con comprobante y corregirlo
- [x] Entrenadores: lista, saldos, referidos, y suspender o reactivar con motivo
- [x] Estrategias por producto
- [x] Ajustes por cancelación y devolución, con vista previa de lo que se revierte
- [ ] Dar de alta el proyecto `premiumfitness-admin` en el gateway de sockets (`socket.elitenutapp.com`) y probar el tiempo real de pedidos
- [x] Volver a ApexCharts 4.7.0 (MIT) y revisar las licencias de las dependencias de los tres proyectos (A-01)
- [x] Añadir pruebas al admin: 127 pruebas (sesión, guards, validación del comprobante, estrategias, ajustes y API simulada)
- [x] Conectar el admin al backend real (`npm run start:api`, puerto 4201) y recorrer sus flujos (16 nov 2026)
  - Tras las correcciones del backend falta repetir en el navegador: la lista de pedidos al entrar, el detalle de un canje y «Corregir pago».
- [x] Que buscadores no indexen el admin: `noindex` en `index.html` y `X-Robots-Tag` en el `.htaccess` (F-11)
- [ ] Reducir el bundle inicial: pesa 515 kB y el aviso salta a los 500 kB
