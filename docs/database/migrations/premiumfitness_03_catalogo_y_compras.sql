-- PremiumFitness 03: catálogo de la intranet y compras (specs catalogo/01 y negocio/01).
-- Quita las tablas de productos y de saldo a favor de radical: el catálogo se lee de
-- crisenri_intranet.tb_productos_megaplexstars y cada línea del pedido guarda la copia de lo vendido.
-- Se puede ejecutar más de una vez.

-- La línea ya no apunta a una tabla de productos: guarda código, nombre, precio y artículo SAP.
ALTER TABLE orders_items DROP FOREIGN KEY IF EXISTS fk_items_product;

-- El saldo a favor no existe en PremiumFitness.
ALTER TABLE orders DROP FOREIGN KEY IF EXISTS fk_orders_balance;
ALTER TABLE orders
    DROP KEY IF EXISTS idx_orders_balance,
    DROP COLUMN IF EXISTS balance_id,
    ADD COLUMN IF NOT EXISTS shipping_amount decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'envío cobrado' AFTER tax_amount,
    ADD KEY IF NOT EXISTS ix_orders_user_created (user_id, created_at);

DROP TABLE IF EXISTS credit_balances;
DROP TABLE IF EXISTS product_sap_map;
DROP TABLE IF EXISTS sap_products;
DROP TABLE IF EXISTS product_group_map;
DROP TABLE IF EXISTS product_groups;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
