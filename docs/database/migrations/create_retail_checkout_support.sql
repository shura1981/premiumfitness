-- Soporte de checkout público minorista.
-- Ejecutar una sola vez sobre crisenri_radical_nutrition.

START TRANSACTION;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS email_verified_at timestamp NULL DEFAULT NULL
        AFTER password;

CREATE TABLE IF NOT EXISTS orders_coupons (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    order_id bigint(20) NOT NULL,
    coupon_code varchar(50) NOT NULL,
    discount_percentage decimal(5,2) NOT NULL DEFAULT 0.00,
    discount_amount decimal(12,2) NOT NULL DEFAULT 0.00,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    UNIQUE KEY uk_orders_coupons_order (order_id),
    KEY idx_orders_coupons_code (coupon_code),
    CONSTRAINT fk_orders_coupons_order
        FOREIGN KEY (order_id) REFERENCES orders (order_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO products (
    sku,
    sort_order,
    name,
    price,
    is_active,
    discount,
    is_out_of_stock,
    category_id,
    health_registry,
    presentation,
    short_description,
    title,
    description,
    usage_instructions,
    is_promo,
    has_qr,
    is_best_seller,
    slug,
    discount_list,
    search_keywords,
    old_price,
    is_callcenter_available,
    is_new
)
SELECT
    1,
    9999,
    'Flete',
    0,
    b'0',
    0,
    b'0',
    MIN(id),
    '',
    'Servicio de envío',
    'Concepto interno para registrar el valor del flete.',
    'Flete',
    'Servicio de envío asociado a una orden.',
    '',
    b'0',
    b'0',
    b'0',
    'flete',
    NULL,
    '',
    0,
    b'0',
    b'0'
FROM categories
WHERE NOT EXISTS (SELECT 1 FROM products WHERE sku = 1)
HAVING MIN(id) IS NOT NULL;

COMMIT;
