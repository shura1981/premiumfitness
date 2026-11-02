1. Revisar repositorios relacionados con la lógica de negocio para extraer funcionalidades e infraestuctura que pueda reciclarse o usarse como inspiración.

1.1. /home/steven/Proyectos/php/backend-radical-nutrition. Contiene:

- Base de datos para clientes, vendedores, pedidos, direcciones.
- Fronted para comprar. 
- Fronted para admin
- Sistema de autenticación y recuperación de cuenta mediante mensaje sms.
- Pasarelas de pago y persistencia de respuestas de las pasarelas de pago.
- Tracking de pedidos y envíos.

Este backend está muy completo y tiene muchas funcionalidades que se pueden usar para el proyecto de entrenadores, por ejemplo la base de datos, la autenticación, las pasarelas de pago y la persistencia de las respuestas de las pasarelas de pago.

1.2. /home/steven/Proyectos/node/angular/join.nutramerican.com. Contiene:

- Ui y ux para mostrar el acumulado de comisiones y muestra como instalar la app pwd según el dispositivo que tenga el usuario.
- Registro de usuario en forma de onboarding con verficación mediante mensaje sms.

1.3. /home/steven/Proyectos/node/angular/gifts.nutramerican.com. Contiene:

- Diseño para mostrar el catálog de productos y realizar la compra con diferentes métodos de pago.

2. Definir la arquitectura del proyecto de entrenadores, teniendo en cuenta las funcionalidades que se van a implementar y las que se pueden reciclar de los repositorios revisados.

2.1 Base de datos: Se debe definir la estructura de la base de datos para almacenar la información de los entrenadores, clientes, pedidos, comisiones y puntos acumulados. Se puede tomar como referencia la base de datos del backend-radical-nutrition.


2.2 Backend: Se debe definir la arquitectura del backend, teniendo en cuenta las funcionalidades que se van a implementar y las que se pueden reciclar de los repositorios revisados. Se puede tomar como referencia el backend-radical-nutrition para la autenticación, pasarelas de pago y persistencia de respuestas de las pasarelas de pago. Se usará tecnología Node.js con Express para el desarrollo del backend. 

2.3 Frontend: Se debe definir la arquitectura del frontend, teniendo en cuenta las funcionalidades que se van a implementar y las que se pueden reciclar de los repositorios revisados. Se puede tomar como referencia el join.nutramerican.com para el registro de usuario y gifts.nutramerican.com para el catálogo de productos y la compra. Se usará tecnología Angular para el desarrollo del frontend. Angular será el candidato para reciclar la parte de la app de dietas, ya que es un proyecto que se encuentra en desarrollo y se puede adaptar para el proyecto de entrenadores.

2.4 División del frontend: Se va a dividir el frontend para Entrenadores y Clientes


1. App de las dietas 
- Se va a tomar como base el repositorio /home/steven/Proyectos/flutter/dietas_automaticas_flutter pero falta crear el sistema de backend para gestionar todo, va hacer una app tipo sass para que puedan servir para muchos entrenadores, también la lógica para desactivarla si el entrandor no cumple con los puntos. (esta fase la vamos hacer después)