-- Habilita la pasarela de pago Bold en `orders.payment_gateway`.
--
-- Se agrega `'bold'` al final del ENUM existente (`'epayco','addi','sistecredito'`)
-- para que MariaDB pueda aplicar el cambio sólo en metadatos (sin reescribir filas
-- ni alterar los índices internos 1..3) y conservar intactos todos los pedidos
-- históricos registrados con `'epayco'`.
--
-- Es idempotente y segura para ejecutar en producción sobre `crisenri_radical_nutrition`.

ALTER TABLE `orders`
    MODIFY COLUMN `payment_gateway` enum('epayco','addi','sistecredito','bold') NOT NULL;

