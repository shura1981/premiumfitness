# Actores y casos de uso — PremiumFitness

## 1. Propósito y fuentes

Este documento describe quiénes interactúan con la app, qué objetivos persiguen, los flujos para alcanzarlos y las reglas que condicionan cada operación.

La fuente de verdad es [requerimientos.md](requerimientos.md). El [diagrama de flujo](img/diagrama-flujo.jpg) se utiliza únicamente como apoyo visual. Ante una diferencia, prevalece el texto de requisitos. Este documento no sustituye las decisiones de negocio pendientes ni convierte las propuestas de diseño en requisitos aprobados.

Se emplean tres categorías:

- **Confirmado:** comportamiento expresamente indicado en los requisitos.
- **Derivado:** comportamiento propuesto para hacer operable, verificable o comprensible un requisito. Requiere validación antes de incorporarse al alcance definitivo.
- **Pendiente:** regla incompleta, contradictoria o ausente que impide definir una parte del comportamiento con precisión.

Los flujos de error y controles identificados como derivados son recomendaciones de especificación. No implican que exista una implementación actual.

## 2. Alcance funcional

El alcance confirmado comprende:

1. Registro de entrenadores y clientes.
2. Referidos mediante dos tipos de QR: uno para clientes y otro para entrenadores.
3. Identificación del entrenador que refiere al cliente mediante una cookie.
4. Compras de clientes con un descuento del 10%.
5. Reconocimiento al entrenador del 5% en puntos y del 15% de comisión sin IVA cuando una compra es aprobada.
6. Un incentivo por referir entrenadores, sujeto a la condición de venta indicada en los requisitos y a las aclaraciones correspondientes.
7. Conversión del total de la comisión a puntos, con fórmula pendiente.
8. Canje de puntos por productos exclusivamente a través de la app.
9. Descuento del 25% para entrenadores en todo el portafolio.
10. Acceso a una app de dietas bajo una modalidad de suscripción condicionada por una regla de negocio pendiente.

No están especificados el proveedor de pagos, el procedimiento de retiro o liquidación de comisiones, la logística, las devoluciones, la administración del catálogo, los campos de registro ni los mecanismos de autenticación. Estos elementos no se incorporan como funciones confirmadas.

## 3. Actores

### 3.1. Actores principales

| ID | Actor | Objetivo | Interacciones | Estado |
| --- | --- | --- | --- | --- |
| ACT-01 | Entrenador | Obtener herramientas, descuentos y recompensas por compras de clientes y referidos. | Registrarse; compartir ambos tipos de QR; acceder a dietas; recibir puntos y comisiones; convertir comisiones; canjear puntos; comprar con descuento. | Confirmado. |
| ACT-02 | Cliente | Registrarse a partir del QR de un entrenador y comprar con el beneficio correspondiente. | Escanear el QR; registrarse; realizar compras con descuento. | Confirmado. |

### 3.2. Participaciones del entrenador

Estas denominaciones distinguen participantes de una operación. **No son roles de permisos diferentes** según los requisitos disponibles.

| Participación | Descripción | Ejemplo |
| --- | --- | --- |
| Entrenador referente de clientes | Comparte el QR para clientes y es el destinatario de los beneficios de las compras atribuidas. | Un entrenador comparte su QR con un cliente que luego compra. |
| Entrenador A o referente de entrenadores | Comparte el QR para entrenadores y recibe el incentivo sujeto a activación. | A invita a B. |
| Entrenador B o referido | Recibe el QR para entrenadores y se registra mediante ese recorrido. | B se registra a partir del QR de A. |
| Entrenador comprador | Utiliza el descuento del 25% o canjea puntos por productos. | Un entrenador compra un producto del portafolio. |

Una misma persona puede participar en varias de estas situaciones. No se define si una misma cuenta puede ser simultáneamente cliente y entrenador.

### 3.3. Participantes previos al registro y sistemas de apoyo

| Participante o sistema | Relación con los casos de uso | Tratamiento |
| --- | --- | --- |
| Persona aún no registrada | Inicia el registro de cliente o entrenador. | Estado previo de ACT-01 o ACT-02; no se asume un tercer rol de negocio. |
| Entorno de ejecución que conserva la cookie | Mantiene el identificador del entrenador en el recorrido del cliente. | Mecanismo técnico confirmado; no es un actor de negocio. Su soporte en una app nativa está pendiente. |
| Origen del estado de compra «Aprobada» | Proporciona el evento que habilita las recompensas por venta. | La condición existe, pero no se identifica quién la informa. No se presupone una pasarela, un administrador ni una aprobación automática. |
| App de dietas | Proporciona la herramienta de dietas al entrenador. | Beneficio confirmado. Falta definir si pertenece al mismo sistema o es una integración externa. |

No se incluyen como actores confirmados un administrador, un operador de bodega, un soporte o una pasarela de pagos, porque los requisitos no los describen.

## 4. Reglas de negocio y trazabilidad

| ID | Regla | Estado y precisión | Referencia en requisitos |
| --- | --- | --- | --- |
| RN-01 | El entrenador se registra y comparte dos tipos de QR: clientes y entrenadores. | Confirmado. | Inicio del archivo y lista de tipos de QR. |
| RN-02 | En el recorrido del cliente se guarda en una cookie el ID del entrenador que compartió el QR. | Confirmado. No se define duración, sobrescritura ni persistencia posterior. | «El cliente escanea, se registra…». |
| RN-03 | El cliente recibe un 10% de descuento en la compra descrita en el flujo. | Confirmado. No se precisa si aplica a todas las compras futuras. | «El cliente compra…». |
| RN-04 | Las recompensas al entrenador por una venta se reconocen cuando la compra está aprobada. | Confirmado. Los demás estados no se definen. | «Cuando la compra sea Aprobada…». |
| RN-05 | El entrenador recibe en puntos el 5% del valor de la compra del cliente. | Confirmado. Base exacta y redondeo pendientes. | Párrafo de aprobación y beneficio 1. |
| RN-06 | El entrenador recibe una comisión del 15% sobre el valor sin IVA de la compra. | Confirmado por el párrafo de aprobación; existe una contradicción posterior sobre el beneficiario. | Párrafo de aprobación y beneficio 2. |
| RN-07 | El entrenador A recibe un incentivo por referir otro entrenador, con activación condicionada a una venta con clientes en la app. | Confirmado en intención. Monto expresado como «50.000 mil puntos» y participante que debe vender pendientes de aclaración. | Párrafo posterior a «Entrenador comparte qr con otro entrenador». |
| RN-08 | La app de dietas opera en modalidad de suscripción y se desactiva si el entrenador incumple una regla de negocio. | Confirmado. Regla, evaluación y alcance de la desactivación pendientes. | Beneficios para entrenadores, punto 1. |
| RN-09 | Los puntos se canjean por productos exclusivamente en la app; no se entregan en efectivo. | Confirmado. | Beneficios, punto 2, y último párrafo. |
| RN-10 | El entrenador tiene un descuento del 25% para todo el portafolio. | Confirmado. Acumulación con otros beneficios pendiente. | Beneficios para entrenadores, punto 3. |
| RN-11 | El entrenador puede convertir el valor del total de la comisión a puntos. | Confirmado. El incentivo del 50% y su base no quedan definidos de manera consistente. | Nota sobre conversión de comisiones. |
| RN-12 | Un peso equivale a un punto. | Confirmado. No se especifica tratamiento de fracciones. | Último párrafo. |
| RN-13 | Hay otros beneficios por definir. | Pendiente. No habilita casos de uso concretos. | Beneficios para entrenadores, punto 4. |

### 4.1. Interpretación de importes y porcentajes

- **Puntos por venta:** la expresión general es `puntos = base de compra × 0,05`, con equivalencia de un peso por punto. La selección de la base y el redondeo siguen pendientes.
- **Ejemplo de puntos:** `249.900 × 0,05 = 12.495`, mientras que el archivo indica `12.500`. No se adopta un redondeo a decenas, centenas u otra unidad sin confirmación.
- **Comisión:** la expresión es `comisión = base sin IVA × 0,15`. No se debe confundir «quitar el IVA» con restar directamente una tasa al precio final. Los requisitos no definen las tasas ni el desglose tributario del pedido.
- **Descuentos:** el 10% del cliente y el 25% del entrenador son porcentajes confirmados; quedan pendientes la base elegible, la interacción con promociones, el envío y el orden de aplicación de descuentos e impuestos.
- **Conversión:** no se establece una fórmula definitiva. La nota menciona convertir comisiones, pero calcula el 50% sobre puntos acumulados. El ejemplo de 100.000 puntos y 50.000 puntos no aclara si estos últimos se adicionan ni qué sucede con la comisión.

La restricción de no entregar efectivo se refiere expresamente al canje de puntos. No permite concluir cómo se pagan o liquidan las comisiones monetarias.

## 5. Catálogo de casos de uso

| ID | Caso de uso | Actor o participante principal | Base |
| --- | --- | --- | --- |
| CU-01 | Registrar entrenador | Entrenador por registrarse | Confirmado; detalles del formulario pendientes. |
| CU-02 | Compartir QR para clientes | Entrenador | Confirmado. |
| CU-03 | Registrar cliente y conservar referencia del entrenador | Cliente por registrarse | Confirmado; persistencia de la asociación pendiente. |
| CU-04 | Comprar como cliente con descuento | Cliente | Confirmado; proceso de compra no detallado. |
| CU-05 | Reconocer puntos y comisión por compra aprobada | Entrenador beneficiario; evento de aprobación como disparador | Confirmado; cálculos incompletos. |
| CU-06 | Compartir QR para entrenadores | Entrenador A | Confirmado. |
| CU-07 | Registrar entrenador referido | Entrenador B | Derivado del flujo confirmado de referidos. |
| CU-08 | Activar incentivo por entrenador referido | Entrenador A beneficiario; venta como disparador | Confirmado; condición y monto ambiguos. |
| CU-09 | Consultar puntos y movimientos | Entrenador | Derivado para permitir el uso informado de puntos. |
| CU-10 | Consultar comisiones | Entrenador | Derivado para permitir una conversión informada. |
| CU-11 | Convertir el total de la comisión a puntos | Entrenador | Confirmado; fórmula pendiente. |
| CU-12 | Canjear puntos por productos | Entrenador | Confirmado; reglas de canje incompletas. |
| CU-13 | Comprar como entrenador con descuento | Entrenador comprador | Confirmado; acumulación pendiente. |
| CU-14 | Acceder a la app de dietas | Entrenador | Confirmado; condiciones de habilitación pendientes. |
| CU-15 | Desactivar acceso a dietas por incumplimiento | Entrenador afectado; evaluación de regla como disparador | Confirmado; evaluación pendiente. |

## 6. Especificación de casos de uso

### CU-01. Registrar entrenador

- **Objetivo:** incorporar a una persona como entrenador para que pueda participar en los beneficios y referidos.
- **Actor:** ACT-01, antes de completar su registro.
- **Disparador:** la persona descarga o abre la app e inicia su registro como entrenador.
- **Precondición:** disponer de acceso a la app. Los requisitos no definen plataformas ni tiendas de distribución.
- **Reglas:** RN-01.

**Flujo principal:**

1. La persona inicia el registro de entrenador.
2. La app solicita los datos de registro; los campos están pendientes de definición.
3. La persona proporciona los datos y envía la solicitud.
4. El sistema registra al entrenador.
5. El entrenador puede iniciar los recorridos para compartir los dos tipos de QR.

**Alternativas y errores:** la validación de datos, el tratamiento de cuentas existentes y la verificación de identidad o contacto son aspectos derivados por definir. Si el registro no se completa, no debe comunicarse como exitoso.

**Resultado:** entrenador registrado. La activación inicial de dietas y otros beneficios sujetos a condiciones se define por separado.

**Criterio de aceptación:** dado un registro completado, el entrenador puede acceder al recorrido de QR para clientes y al de QR para entrenadores. No se acredita un bono de bienvenida, porque no aparece en la fuente de verdad.

### CU-02. Compartir QR para clientes

- **Objetivo:** invitar a un cliente y permitir identificar al entrenador referente.
- **Actor:** ACT-01 como referente de clientes.
- **Disparador:** el entrenador decide compartir su QR con un cliente.
- **Precondición:** entrenador registrado.
- **Reglas:** RN-01 y RN-02.

**Flujo principal:**

1. El entrenador elige el QR destinado a clientes.
2. La app presenta un QR que permite recuperar el identificador del entrenador en el recorrido de referencia.
3. El entrenador comparte o muestra el QR al cliente.
4. El cliente lo escanea y continúa en CU-03.

**Alternativas y errores:** un QR ilegible o inválido no permite completar la referencia. La forma de comunicar el error y de volver a compartirlo es derivada. No se establece si el QR vence o se puede regenerar.

**Resultado:** invitación compartida. Mostrar o compartir el QR no genera por sí mismo puntos ni comisión.

**Criterio de aceptación:** el QR de clientes permite identificar al entrenador que lo compartió y conduce al recorrido de cliente; no se confunde con el QR para entrenadores.

### CU-03. Registrar cliente y conservar referencia del entrenador

- **Objetivo:** registrar al cliente en el recorrido del entrenador que lo invitó.
- **Actor:** ACT-02, antes de completar su registro.
- **Disparador:** escaneo del QR para clientes.
- **Precondición:** QR que identifica a un entrenador.
- **Reglas:** RN-02.

**Flujo principal:**

1. El cliente escanea el QR recibido.
2. La app obtiene el identificador del entrenador referente.
3. El cliente realiza su registro con los datos que se definan para este proceso.
4. Como parte de ese recorrido, se guarda en una cookie el identificador del entrenador.
5. La referencia queda disponible para atribuir la compra al entrenador según RN-04 a RN-06.

**Alternativas y errores:**

- **Cookie bloqueada, eliminada o no disponible:** el mecanismo de recuperación o asociación alternativa está pendiente. No se presupone que la atribución sobreviva.
- **Cliente ya registrado:** falta definir cómo se vincula y si conserva un entrenador anterior.
- **Varios QR escaneados:** no se define si prevalece la primera o la última referencia.
- **Registro interrumpido:** falta definir cuándo se escribe la cookie y cuánto tiempo se conserva.

**Resultado:** cliente registrado y referencia guardada en cookie dentro del flujo completado. La asociación permanente en una base de datos no está expresamente especificada.

**Criterio de aceptación:** al completar el recorrido, la cookie contiene el ID del entrenador del QR. La atribución posterior debe poder comprobarse; su persistencia entre sesiones y dispositivos requiere una decisión adicional.

### CU-04. Comprar como cliente con descuento

- **Objetivo:** adquirir productos con el 10% de descuento del recorrido de clientes referidos.
- **Actor:** ACT-02.
- **Disparador:** el cliente inicia una compra en la app.
- **Precondiciones:** cliente registrado mediante el recorrido descrito y referencia del entrenador disponible para la atribución.
- **Reglas:** RN-03 y RN-04.

**Flujo principal:**

1. El cliente elige los productos que desea comprar. El detalle del catálogo y del carrito es derivado.
2. El sistema calcula el descuento del 10% sobre la base que se acuerde para esta compra.
3. La app presenta el importe de la compra y el descuento aplicado, como comportamiento derivado para permitir su revisión.
4. El cliente completa el proceso de compra que se defina.
5. El sistema conserva la información necesaria para atribuir la compra al entrenador. El mecanismo de conservación está pendiente.
6. Cuando la compra alcanza el estado «Aprobada», se inicia CU-05 y, si corresponde, se evalúa CU-08.

**Alternativas y errores:**

- **Compra sin aprobación:** no se otorgan todavía las recompensas de CU-05.
- **Referencia ausente:** la elegibilidad del descuento y la atribución requieren definición; no se asigna un entrenador arbitrariamente.
- **Nueva compra del mismo cliente:** la continuidad del descuento y de la referencia está pendiente.
- **Cancelación, rechazo o devolución:** los estados y sus efectos sobre beneficios no están definidos.

**Resultado:** compra registrada con el descuento correspondiente; las recompensas dependen de su aprobación.

**Criterio de aceptación:** en la compra elegible se aplica el 10%. Crear una compra sin aprobación no basta para acreditar puntos y comisión al entrenador.

### CU-05. Reconocer puntos y comisión por compra aprobada

- **Objetivo:** reconocer al entrenador las dos recompensas de la compra atribuida.
- **Beneficiario:** ACT-01 como referente de clientes.
- **Disparador:** una compra del cliente alcanza el estado «Aprobada». No se requiere una solicitud manual del entrenador según el flujo descrito.
- **Precondiciones:** compra aprobada, entrenador referente identificable e importes de cálculo disponibles.
- **Reglas:** RN-04, RN-05, RN-06 y RN-12.

**Flujo principal:**

1. El sistema reconoce la aprobación de la compra.
2. Identifica al entrenador referente del cliente.
3. Determina la base de puntos según la regla de cálculo que se confirme.
4. Calcula el 5% en puntos, aplicando el redondeo que se defina.
5. Obtiene la base sin IVA de la compra.
6. Calcula el 15% de comisión sobre esa base.
7. Acredita los puntos y reconoce la comisión al entrenador.
8. Como comportamiento derivado, conserva la relación entre compra, entrenador y movimientos para permitir su consulta y verificación.

**Alternativas y errores:**

- **Compra no aprobada:** el caso no se ejecuta.
- **Entrenador no identificable o importes insuficientes:** se propone no acreditar una recompensa calculada con datos incompletos y dejar la incidencia identificable; tratamiento operativo pendiente.
- **Aprobación recibida más de una vez:** se propone evitar duplicar puntos y comisión por la misma compra. Es un control derivado.
- **Devolución posterior:** no se define reversión, retención ni descuento de saldos.

**Resultado:** puntos y comisión reconocidos al entrenador por la compra aprobada. Esto no equivale a un pago o retiro de la comisión.

**Criterios de aceptación:** una compra aprobada atribuida produce ambas recompensas; la comisión usa la base sin IVA; el cálculo de puntos del ejemplo de 249.900 debe quedar validado al resolver la discrepancia entre 12.495 y 12.500.

**Pendiente relevante:** se utiliza al entrenador como beneficiario por la instrucción explícita del párrafo de aprobación. La frase posterior que asigna el 15% al cliente debe corregirse o confirmarse.

### CU-06. Compartir QR para entrenadores

- **Objetivo:** invitar a otro entrenador mediante el recorrido específico de referidos.
- **Actor:** ACT-01 como entrenador A.
- **Disparador:** A decide invitar a otro entrenador.
- **Precondición:** A está registrado.
- **Reglas:** RN-01 y RN-07.

**Flujo principal:**

1. A elige el QR destinado a entrenadores.
2. La app presenta el QR que permite identificar a A como referente. El mecanismo técnico no se especifica.
3. A comparte el QR con B.
4. B lo utiliza para iniciar CU-07.

**Alternativas y errores:** QR inválido, ilegible o registro interrumpido requieren manejo por definir. No se prescribe el uso de una cookie en este recorrido: el requisito de cookie se formula para clientes.

**Resultado:** invitación a B compartida. Compartir el QR no basta para activar el incentivo.

**Criterio de aceptación:** el QR distingue la invitación a entrenadores de la invitación a clientes y permite identificar a A en el registro referido.

### CU-07. Registrar entrenador referido

- **Objetivo:** registrar a B y conservar qué entrenador lo invitó para evaluar el incentivo.
- **Actor:** ACT-01 como entrenador B; A participa como referente.
- **Disparador:** B utiliza el QR para entrenadores de A.
- **Precondiciones:** A registrado y QR de invitación identificable.
- **Reglas:** RN-01 y RN-07.
- **Clasificación:** detalle derivado del flujo de invitación; los requisitos no describen su almacenamiento ni su interfaz.

**Flujo principal:**

1. B abre el recorrido del QR compartido por A.
2. El sistema identifica a A como referente.
3. B completa CU-01.
4. Se conserva la relación A → B para poder comprobar la condición del incentivo. Esta persistencia es una necesidad derivada.
5. El incentivo queda sujeto a la condición de venta de CU-08; no se considera utilizable solo por el registro.

**Alternativas y errores:** el autorreferido, un B ya registrado y varios referentes para B no tienen reglas definidas. Las restricciones contra abuso deberán acordarse, no asumirse.

**Resultado:** B registrado y referencia a A conservada según el diseño que se apruebe.

**Criterio de aceptación:** es posible identificar qué entrenador invitó a B al evaluar la venta calificadora. El registro por sí solo no activa el incentivo.

### CU-08. Activar incentivo por entrenador referido

- **Objetivo:** habilitar para A los puntos del incentivo cuando se cumple la condición de venta.
- **Beneficiario:** ACT-01 como entrenador A.
- **Disparador:** se registra la venta que satisface la condición del referido.
- **Precondiciones:** relación A → B identificada y regla de activación acordada.
- **Reglas:** RN-07.

**Flujo principal condicionado a aclaración:**

1. Se identifica una venta con clientes en la app relacionada con el flujo de referido.
2. Se comprueba quién debe haber gestionado esa venta según la decisión de negocio pendiente: A o B.
3. Se valida que la venta cumpla la condición acordada. Se propone exigir aprobación por remisión al primer flujo, pero la condición exacta debe confirmarse.
4. Se determina el monto definitivo del incentivo.
5. Se activa el incentivo para A y se incorpora a sus puntos disponibles conforme al diseño de saldos que se apruebe.
6. Como control derivado, se conserva la referencia al entrenador invitado y a la venta que justificó la activación.

**Alternativas y errores:** sin venta calificadora, el incentivo no se activa. La repetición del incentivo por varias ventas, los límites por referido y el manejo de una venta revertida están pendientes.

**Resultado:** incentivo activado para A únicamente cuando se cumple la condición que se confirme.

**Criterio de aceptación condicionado:** una vez definido monto, entrenador que debe vender y venta calificadora, el sistema puede distinguir un referido sin condición cumplida de otro con incentivo activado. No es posible cerrar una prueba numérica definitiva con la redacción actual.

### CU-09. Consultar puntos y movimientos

- **Objetivo:** conocer los puntos utilizables y comprender su origen antes de un canje.
- **Actor:** ACT-01.
- **Clasificación:** derivado de la acumulación y el canje; no hay una pantalla de consulta exigida expresamente.
- **Disparador:** el entrenador solicita consultar sus puntos.
- **Precondición:** entrenador identificado mediante el mecanismo de acceso que se defina.
- **Reglas relacionadas:** RN-05, RN-07, RN-09, RN-11 y RN-12.

**Flujo propuesto:**

1. El entrenador abre la consulta de puntos.
2. El sistema presenta los puntos disponibles.
3. Permite identificar abonos por compras, incentivos activados y conversiones, además de consumos por canje, si existen.
4. Si se modelan incentivos pendientes de activación, los diferencia del saldo utilizable.

**Alternativas y errores:** sin movimientos, se propone mostrar saldo y ausencia de movimientos de forma explícita. La información de otros entrenadores no debe quedar accesible; este control de acceso es derivado.

**Resultado y aceptación propuestos:** el entrenador puede distinguir lo que puede canjear de lo que aún no está activado. No se descuentan puntos por vencimiento sin una regla aprobada: los requisitos no establecen caducidad.

### CU-10. Consultar comisiones

- **Objetivo:** conocer el valor reconocido de comisiones antes de solicitar su conversión.
- **Actor:** ACT-01.
- **Clasificación:** derivado de RN-06 y RN-11.
- **Disparador:** el entrenador consulta sus comisiones.
- **Precondición:** entrenador identificado.

**Flujo propuesto:**

1. El entrenador abre la consulta de comisiones.
2. El sistema muestra el total que puede convertirse según la definición de disponibilidad que se acuerde.
3. Permite relacionar las comisiones con las compras aprobadas que las originaron.
4. Permite identificar conversiones ya realizadas para evitar confundir valores históricos con el saldo convertible.

**Alternativas y errores:** sin comisiones, se propone informar un total de cero. Las comisiones anuladas, retenidas o pagadas no tienen un tratamiento definido.

**Resultado y aceptación propuestos:** el entrenador distingue su comisión monetaria de sus puntos y conoce qué total se utilizaría en CU-11. La consulta no ejecuta una conversión ni un retiro.

### CU-11. Convertir el total de la comisión a puntos

- **Objetivo:** transformar el total de la comisión del entrenador en puntos.
- **Actor:** ACT-01.
- **Disparador:** el entrenador solicita la conversión.
- **Precondiciones:** comisión convertible disponible y fórmula de conversión definida.
- **Reglas:** RN-09, RN-11 y RN-12.

**Flujo principal condicionado a aclaración:**

1. El entrenador solicita convertir el total de su comisión.
2. El sistema determina qué se entiende por «total de la comisión» disponible.
3. Calcula los puntos conforme a la fórmula de conversión y al incentivo que se aprueben.
4. Como comportamiento derivado, presenta la comisión que se consumirá, los puntos que se obtendrán y el resultado sobre ambos saldos.
5. El entrenador confirma la operación; esta confirmación es una propuesta de interacción.
6. El sistema registra la conversión, consume el valor de comisión correspondiente y acredita los puntos. El tratamiento de saldos es derivado necesario para que una misma comisión no se utilice dos veces.

**Alternativas y errores:**

- **Fórmula sin definir:** el caso permanece especificado de forma condicional; no se inventa un resultado numérico.
- **Conversión parcial:** no está solicitada; el texto se refiere al total.
- **Saldo cambiado o solicitud repetida:** se propone recalcular antes de confirmar y evitar duplicidad.
- **Fallo durante la operación:** se propone conservar consistencia entre comisión consumida y puntos acreditados.

**Resultado:** comisión convertida y puntos disponibles para canje, según la regla que se confirme.

**Criterio de aceptación condicionado:** una comisión no puede convertirse dos veces; el total de puntos coincide con la fórmula aprobada y se informa antes de confirmar. El ejemplo del 50% no basta para fijar esa fórmula.

### CU-12. Canjear puntos por productos

- **Objetivo:** utilizar puntos para obtener productos a través de la app.
- **Actor:** ACT-01.
- **Disparador:** el entrenador inicia un canje.
- **Precondiciones:** puntos disponibles y productos ofrecidos para canje. Las condiciones de disponibilidad de productos no están definidas.
- **Reglas:** RN-09 y RN-12.

**Flujo principal:**

1. El entrenador ingresa al recorrido de canje en la app.
2. Selecciona los productos que desea obtener.
3. El sistema determina los puntos necesarios aplicando la equivalencia de un peso por punto al valor de canje que se acuerde.
4. Como comportamiento derivado, muestra los productos, los puntos requeridos y el saldo resultante.
5. El entrenador solicita completar el canje.
6. El sistema aplica los puntos al canje y registra los productos correspondientes. La forma de descontar o reservar el saldo está pendiente de diseño.

**Alternativas y errores:**

- **Puntos insuficientes:** no se presupone pago mixto de dinero y puntos; debe definirse si se admite.
- **Solicitud de efectivo:** no corresponde a un canje permitido.
- **Canje fuera de la app:** no corresponde al canal autorizado por RN-09.
- **Producto no disponible o canje cancelado:** reposición de puntos, reserva de inventario y devolución están pendientes.
- **Uso del descuento del 25%:** no se presume su acumulación con el canje.

**Resultado:** canje registrado por productos. Entrega, envío y tiempos de cumplimiento no están especificados.

**Criterios de aceptación:** el canje se realiza en la app y entrega productos como contraprestación; no permite retirar los puntos en efectivo. El descuento del saldo debe corresponder al valor de canje confirmado.

### CU-13. Comprar como entrenador con descuento

- **Objetivo:** adquirir productos del portafolio con el descuento del 25%.
- **Actor:** ACT-01 como comprador.
- **Disparador:** el entrenador inicia una compra propia.
- **Precondición:** su condición de entrenador puede verificarse; requisitos adicionales de elegibilidad no definidos.
- **Reglas:** RN-10.

**Flujo principal:**

1. El entrenador selecciona productos del portafolio.
2. El sistema aplica el descuento del 25% según la base de cálculo que se defina.
3. Como comportamiento derivado, presenta el beneficio y el total de la compra para revisión.
4. El entrenador completa la compra mediante el proceso que se defina.

**Alternativas y errores:** la acumulación con promociones, puntos o el descuento de cliente no está definida. No se establece que una compra propia genere el 5% en puntos o el 15% de comisión; esas recompensas se describen para compras de clientes.

**Resultado:** compra propia del entrenador con el 25% de descuento en el portafolio.

**Criterio de aceptación:** se reconoce el 25% a la compra elegible del entrenador sin excluir categorías del portafolio por una regla inventada. Las bases de impuestos y envío deben validarse por separado.

### CU-14. Acceder a la app de dietas

- **Objetivo:** utilizar la herramienta de dietas ofrecida como beneficio.
- **Actor:** ACT-01.
- **Disparador:** el entrenador solicita acceso a dietas.
- **Precondiciones:** entrenador registrado y acceso habilitado conforme a la modalidad de suscripción que se defina.
- **Reglas:** RN-08.

**Flujo principal:**

1. El entrenador solicita ingresar a la app de dietas.
2. El sistema verifica si su acceso está habilitado. Esta verificación es un detalle derivado de la posibilidad de desactivación.
3. Si está habilitado, permite utilizar la herramienta de dietas.

**Alternativas y errores:** si el beneficio está desactivado por incumplimiento, no se permite usarlo conforme a CU-15. Se propone informar el motivo y la condición de recuperación cuando esas reglas estén definidas.

**Resultado:** acceso a la herramienta cuando corresponda.

**Criterio de aceptación:** se puede diferenciar un entrenador con acceso habilitado de uno con acceso desactivado. No se especifican creación de dietas, asignación a clientes, planes nutricionales ni edición, porque los requisitos solo nombran el beneficio.

**Pendientes:** activación inicial, posible cobro, periodicidad, integración con otra app y funciones concretas de dietas. La palabra «suscripción» no confirma por sí sola un pago periódico.

### CU-15. Desactivar acceso a dietas por incumplimiento

- **Objetivo:** aplicar la condición de permanencia del beneficio de dietas.
- **Participante afectado:** ACT-01.
- **Disparador:** se determina que el entrenador no cumple la regla de negocio.
- **Precondiciones:** regla de permanencia definida, datos para evaluarla y acceso previamente habilitado.
- **Reglas:** RN-08.

**Flujo principal condicionado a aclaración:**

1. Se evalúa al entrenador según la regla y el periodo que se acuerden.
2. Se determina que no cumple la condición.
3. Se desactiva su acceso a la app de dietas.
4. Como comportamiento derivado, se conserva el motivo y se informa al entrenador en el siguiente intento de acceso.

**Alternativas y errores:**

- **Cumple la regla:** el acceso no se desactiva por este motivo.
- **No hay datos suficientes para evaluar:** el tratamiento está pendiente; no se debe equiparar automáticamente una evaluación fallida con incumplimiento.
- **Vuelve a cumplir:** reactivación, plazos y responsable no están definidos.

**Resultado:** acceso a dietas desactivado por incumplimiento conforme a la regla aprobada.

**Criterio de aceptación condicionado:** una vez definida la regla, se desactiva el acceso en el supuesto que corresponda. No hay fundamento en los requisitos para extender esa desactivación a la cuenta, las comisiones o los puntos.

## 7. Recorridos completos y dependencias

### 7.1. Compra de cliente referido

1. El entrenador se registra: CU-01.
2. Comparte su QR para clientes: CU-02.
3. El cliente escanea, se registra y conserva la referencia en cookie: CU-03.
4. El cliente compra con el 10% de descuento: CU-04.
5. Al aprobarse la compra, el entrenador recibe el 5% en puntos y el 15% de comisión sin IVA: CU-05.
6. El entrenador puede canjear sus puntos: CU-12. También puede convertir el total de su comisión: CU-11, sujeto a la fórmula pendiente.

### 7.2. Referido de entrenador

1. A comparte el QR para entrenadores: CU-06.
2. B se registra desde ese QR: CU-07, que utiliza CU-01.
3. Se completa el recorrido de venta con clientes que se confirme como condición.
4. Se activa para A el incentivo: CU-08.
5. A puede utilizar los puntos activados en CU-12.

La fuente no permite determinar de forma inequívoca si el paso 3 corresponde a clientes de A o de B.

### 7.3. Acceso condicionado a dietas

1. El entrenador solicita usar dietas: CU-14.
2. Su acceso depende de la suscripción y la regla de permanencia que se acuerden.
3. Si se determina incumplimiento, se aplica CU-15.

El origen y la frecuencia de la evaluación no están definidos. No se vincula automáticamente a una cuota de ventas ni a la vigencia de los puntos.

## 8. Inconsistencias y decisiones pendientes

| ID | Tema | Evidencia o vacío | Decisión necesaria | Casos afectados |
| --- | --- | --- | --- | --- |
| PD-01 | Beneficiario del 15% | El párrafo de aprobación dice «entrenador»; el beneficio 2 dice «cliente». | Confirmar y unificar el beneficiario. Este documento sigue provisionalmente la instrucción explícita del párrafo de aprobación. | CU-05, CU-10, CU-11. |
| PD-02 | Base del 5% | Se menciona el valor de compra sin precisar si es antes o después del descuento, con IVA, con envío u otros cargos. | Definir componentes de la base y orden de cálculo. | CU-04, CU-05. |
| PD-03 | Redondeo de puntos | El ejemplo produce matemáticamente 12.495, pero el archivo indica 12.500. | Definir precisión, redondeo y momento de aplicación. | CU-05, CU-09. |
| PD-04 | Base de comisión | Se exige retirar el IVA, sin detallar descuentos, tasas, envío o pedidos con tratamientos tributarios diferentes. | Definir la base sin IVA por compra o por producto. | CU-05. |
| PD-05 | Incentivo por referido | La expresión «50.000 mil puntos» es ambigua; el diagrama muestra 50.000. | Confirmar el monto sin corregirlo por inferencia. | CU-08. |
| PD-06 | Venta que activa el referido | No se identifica inequívocamente si vende A o B, ni si basta crear la compra o debe aprobarse. | Precisar participante, evento, número de incentivos y límites por referido. | CU-07, CU-08. |
| PD-07 | Conversión de comisión | El texto mezcla el total de comisión con un 50% de los puntos acumulados. | Definir saldo de entrada, fórmula, base del incentivo, puntos de salida y tratamiento de la comisión. | CU-10, CU-11. |
| PD-08 | Permanencia en dietas | Se menciona una regla de negocio sin describirla. | Definir condición, periodo de evaluación, activación inicial, desactivación y reactivación. | CU-14, CU-15. |
| PD-09 | Suscripción de dietas | No se define si hay pago o si depende de actividad comercial. | Definir modalidad y funciones de la herramienta. | CU-14, CU-15. |
| PD-10 | Cookie y asociación | No hay duración, política de sustitución ni relación persistente en cuenta. | Definir escritura, caducidad, clientes existentes, varios QR, cambio de dispositivo y soporte técnico en la app. | CU-02 a CU-05. |
| PD-11 | Continuidad del 10% | Se habla de «esa compra», sin establecer compras posteriores. | Definir frecuencia y elegibilidad del beneficio. | CU-04. |
| PD-12 | Acumulación y valoración | No se indica si descuentos, promociones y puntos se combinan ni cuál es el precio de canje. | Definir compatibilidad, base del descuento y valor de productos en puntos. | CU-04, CU-12, CU-13. |
| PD-13 | Aprobación y reversión | No se define quién informa la aprobación ni qué ocurre con cancelaciones o devoluciones. | Definir origen del estado, transición válida y efectos sobre puntos, comisión e incentivos. | CU-04, CU-05, CU-08, CU-12. |
| PD-14 | Liquidación de comisión | Solo se define su generación y conversión a puntos. | Definir si existe pago monetario, procedimiento, disponibilidad y restricciones. No confundirlo con el canje de puntos. | CU-10, CU-11. |
| PD-15 | Mecánica del canje | Sin reglas de pago mixto, inventario, reserva, entrega o cancelación. | Definir qué hace efectivo el canje y cuándo se consumen o restituyen puntos. | CU-12. |
| PD-16 | Registro y acceso | No hay campos, credenciales, verificación ni coexistencia de roles. | Definir datos mínimos, identificación y permisos. | CU-01, CU-03, CU-07 y consultas. |
| PD-17 | Otros beneficios | Se enumeran como «por definir». | Describir cada beneficio antes de crear sus casos de uso. | Sin caso específico. |

## 9. Diferencias entre el diagrama y la fuente de verdad

| Elemento visual | Tratamiento en este documento |
| --- | --- |
| 30.000 puntos de bienvenida | No se incorpora como requisito: no está en requerimientos.md. |
| Puntos con duración de seis meses | No se incorpora caducidad: no está en requerimientos.md. |
| Seminarios de nutrición y materiales de apoyo | No se convierten en casos de uso: el archivo solo menciona otros beneficios por definir. |
| 50.000 puntos por metas/registro | Se conserva el flujo textual de referido condicionado a venta y se señalan monto y condición pendientes. No se crea una recompensa independiente por metas o por simple registro. |
| Un único recorrido de QR visible para clientes | Se documentan los dos tipos de QR exigidos por el archivo: clientes y entrenadores. |
| Conversión de comisión a puntos sin detalle | Se documenta la facultad de conversión, pero se deja pendiente la fórmula ambigua del texto. |
| Beneficios no representados con detalle en la imagen | Se incluyen el descuento del 25%, el canje exclusivo en app, la equivalencia peso/punto y la condición de acceso a dietas porque sí están en el archivo. |

## 10. Cobertura de los requisitos

| Enunciado de la fuente | Actores | Casos que lo cubren | Reglas |
| --- | --- | --- | --- |
| El entrenador descarga la app y se registra. | ACT-01. | CU-01. La descarga se trata como paso previo de distribución, no como función interna. | RN-01. |
| Comparte un QR para clientes. | ACT-01, ACT-02. | CU-02. | RN-01. |
| Cliente escanea, se registra y guarda ID del entrenador en cookie. | ACT-02; ACT-01 como referente. | CU-03. | RN-02. |
| Cliente compra con 10% de descuento. | ACT-02. | CU-04. | RN-03. |
| Compra aprobada otorga 5% en puntos y 15% sin IVA al entrenador. | ACT-01 como beneficiario. | CU-05; CU-09 y CU-10 como consultas derivadas. | RN-04, RN-05, RN-06, RN-12. |
| Comparte QR con otro entrenador. | ACT-01 como A y B. | CU-06, CU-07. | RN-01, RN-07. |
| Incentivo de referido sujeto a venta. | ACT-01 como A beneficiario; vendedor por confirmar. | CU-08. | RN-07. |
| App de dietas en suscripción con desactivación por regla. | ACT-01. | CU-14, CU-15. | RN-08. |
| Puntos canjeables por productos, solo en app y sin efectivo. | ACT-01. | CU-12. | RN-09. |
| 25% de descuento para todo el portafolio. | ACT-01. | CU-13. | RN-10. |
| Convertir total de comisión a puntos con incentivo por aclarar. | ACT-01. | CU-11. | RN-11, RN-12. |
| Un peso equivale a un punto. | ACT-01. | CU-05, CU-11, CU-12. | RN-12. |
| Otros beneficios por definir. | ACT-01. | Sin caso hasta definir alcance. | RN-13. |

## 11. Condiciones para cerrar la especificación

La cobertura funcional del texto queda documentada. Para usar todos los casos como criterios de implementación y pruebas definitivos, se deben resolver las decisiones pendientes, especialmente el beneficiario de la comisión, las bases y redondeos, el incentivo por referido, la conversión de comisión y la condición de dietas.

Los casos derivados de consulta y los controles de consistencia deben validarse como parte del alcance. Cada decisión debe actualizar su regla de negocio, los casos afectados y los criterios de aceptación correspondientes, manteniendo requerimientos.md como fuente de verdad.
