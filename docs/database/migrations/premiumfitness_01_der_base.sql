-- PremiumFitness 01: tablas y columnas nuevas del DER (specs/diseño/01-DER.md, v0.16).
-- Solo añade: las tablas y columnas de radical siguen igual, para que el código heredado funcione
-- mientras se adapta cada parte. Lo que cambia o quita algo va en la migración de su fase
-- (specs/backend/02-migraciones-del-der.md).
-- Se puede ejecutar más de una vez: usa IF NOT EXISTS.
-- `agent_id` apunta a crisenri_intranet.tb_empleados (externa): no lleva clave foránea.
-- MariaDB no admite en un CHECK columnas con ON UPDATE CASCADE: por eso esas claves no lo llevan.

-- ---------------------------------------------------------------------------------------------
-- Tablas heredadas: columnas añadidas
-- ---------------------------------------------------------------------------------------------

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS phone_verified_at timestamp NULL DEFAULT NULL AFTER num_cell,
    ADD COLUMN IF NOT EXISTS role enum('client','trainer') NOT NULL DEFAULT 'client' AFTER password,
    ADD COLUMN IF NOT EXISTS status enum('pending','active','suspended') NOT NULL DEFAULT 'pending' AFTER role;

ALTER TABLE verification_codes
    MODIFY code char(64) NOT NULL COMMENT 'hash SHA-256 del código',
    ADD COLUMN IF NOT EXISTS purpose enum('phone_verification','password_reset') NOT NULL DEFAULT 'phone_verification' AFTER code,
    ADD COLUMN IF NOT EXISTS destination varchar(20) DEFAULT NULL COMMENT 'celular' AFTER channel,
    ADD COLUMN IF NOT EXISTS attempts tinyint(4) NOT NULL DEFAULT 0 AFTER destination;

ALTER TABLE orders
    MODIFY payment_gateway enum('epayco','addi','sistecredito','bold') DEFAULT NULL COMMENT 'NULL en los canjes',
    ADD COLUMN IF NOT EXISTS order_type enum('client_purchase','trainer_purchase','redemption') NOT NULL DEFAULT 'client_purchase' AFTER agent_id,
    ADD COLUMN IF NOT EXISTS payment_reference varchar(100) DEFAULT NULL AFTER payment_gateway,
    ADD COLUMN IF NOT EXISTS subtotal_full decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'a precio completo, con IVA' AFTER amount,
    ADD COLUMN IF NOT EXISTS discount_amount decimal(12,2) NOT NULL DEFAULT 0.00 AFTER subtotal_full,
    ADD COLUMN IF NOT EXISTS tax_base decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'pagado sin IVA' AFTER discount_amount,
    ADD COLUMN IF NOT EXISTS tax_amount decimal(12,2) NOT NULL DEFAULT 0.00 AFTER tax_base,
    ADD COLUMN IF NOT EXISTS reward_percentage decimal(5,2) DEFAULT NULL COMMENT 'porcentaje de puntos aplicado' AFTER tax_amount,
    ADD COLUMN IF NOT EXISTS reward_points int(11) DEFAULT NULL AFTER reward_percentage,
    ADD COLUMN IF NOT EXISTS redemption_points int(11) DEFAULT NULL COMMENT 'costo del canje' AFTER reward_points,
    ADD COLUMN IF NOT EXISTS approved_at timestamp NULL DEFAULT NULL AFTER status_id,
    ADD UNIQUE KEY IF NOT EXISTS uk_orders_payment_reference (payment_reference),
    ADD KEY IF NOT EXISTS ix_orders_type_approved (order_type, approved_at);

ALTER TABLE orders_items
    ADD COLUMN IF NOT EXISTS product_name varchar(255) NOT NULL DEFAULT '' COMMENT 'copia de producto' AFTER product_sku,
    ADD COLUMN IF NOT EXISTS tax_rate decimal(5,2) NOT NULL DEFAULT 0.00 AFTER line_total,
    ADD COLUMN IF NOT EXISTS tax_base decimal(12,2) NOT NULL DEFAULT 0.00 AFTER tax_rate,
    ADD COLUMN IF NOT EXISTS tax_amount decimal(12,2) NOT NULL DEFAULT 0.00 AFTER tax_base,
    ADD COLUMN IF NOT EXISTS commission_percentage decimal(5,2) DEFAULT NULL AFTER tax_amount,
    ADD COLUMN IF NOT EXISTS commission_amount decimal(12,2) DEFAULT NULL COMMENT 'sobre tax_base' AFTER commission_percentage,
    ADD COLUMN IF NOT EXISTS redemption_points int(11) DEFAULT NULL AFTER commission_amount;

-- ---------------------------------------------------------------------------------------------
-- Identidad y referidos
-- ---------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS trainers (
    user_id bigint(20) NOT NULL,
    coupon_code varchar(30) NOT NULL,
    joined_at timestamp NOT NULL DEFAULT current_timestamp(),
    updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    diet_first_access_at timestamp NULL DEFAULT NULL COMMENT 'inicia el mes gratis de dietas',
    PRIMARY KEY (user_id),
    UNIQUE KEY uk_trainers_coupon (coupon_code),
    CONSTRAINT fk_trainers_user FOREIGN KEY (user_id) REFERENCES users (id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_referrals (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    client_id bigint(20) NOT NULL,
    trainer_id bigint(20) NOT NULL,
    source enum('link','coupon') NOT NULL,
    linked_at timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    UNIQUE KEY uk_client_referrals_client (client_id),
    KEY ix_client_referrals_trainer (trainer_id, linked_at),
    CONSTRAINT fk_client_referrals_client FOREIGN KEY (client_id) REFERENCES users (id) ON UPDATE CASCADE,
    CONSTRAINT fk_client_referrals_trainer FOREIGN KEY (trainer_id) REFERENCES trainers (user_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trainer_referrals (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    referrer_id bigint(20) NOT NULL COMMENT 'entrenador A',
    referred_id bigint(20) NOT NULL COMMENT 'entrenador B',
    referral_points int(11) NOT NULL COMMENT 'valor de TRAINER_REFERRAL_POINTS al registrarse B',
    activation_order_id bigint(20) DEFAULT NULL COMMENT 'primera compra aprobada de un cliente de B',
    registered_at timestamp NOT NULL DEFAULT current_timestamp(),
    activated_at timestamp NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_trainer_referrals_referred (referred_id),
    UNIQUE KEY uk_trainer_referrals_activation_order (activation_order_id),
    KEY ix_trainer_referrals_pending (referrer_id, activated_at, referral_points),
    CONSTRAINT fk_trainer_referrals_referrer FOREIGN KEY (referrer_id) REFERENCES trainers (user_id),
    CONSTRAINT fk_trainer_referrals_referred FOREIGN KEY (referred_id) REFERENCES trainers (user_id),
    CONSTRAINT fk_trainer_referrals_order FOREIGN KEY (activation_order_id) REFERENCES orders (order_id) ON UPDATE CASCADE,
    CONSTRAINT ck_trainer_referrals_distinct CHECK (referrer_id <> referred_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------
-- Sesiones y notificaciones push
-- ---------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    user_id bigint(20) DEFAULT NULL COMMENT 'cliente o entrenador',
    agent_id int(11) DEFAULT NULL COMMENT 'agente del admin (tb_empleados)',
    app enum('web','admin') NOT NULL,
    family_id char(36) NOT NULL COMMENT 'una familia por sesión',
    token_hash char(64) NOT NULL,
    expires_at timestamp NOT NULL,
    revoked_at timestamp NULL DEFAULT NULL,
    replaced_by_id bigint(20) DEFAULT NULL,
    ip varchar(45) DEFAULT NULL,
    user_agent varchar(255) DEFAULT NULL,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    UNIQUE KEY uk_refresh_tokens_hash (token_hash),
    KEY ix_refresh_tokens_family (family_id),
    KEY ix_refresh_tokens_user (user_id, revoked_at),
    KEY ix_refresh_tokens_agent (agent_id, revoked_at),
    KEY ix_refresh_tokens_expires (expires_at),
    CONSTRAINT fk_refresh_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_refresh_tokens_replaced_by FOREIGN KEY (replaced_by_id) REFERENCES refresh_tokens (id) ON DELETE SET NULL,
    CONSTRAINT ck_refresh_tokens_owner CHECK (
        (app = 'web' AND user_id IS NOT NULL AND agent_id IS NULL)
        OR (app = 'admin' AND agent_id IS NOT NULL AND user_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_devices (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    user_id bigint(20) NOT NULL,
    app enum('web') NOT NULL DEFAULT 'web',
    platform enum('web','android','ios') NOT NULL,
    token varchar(255) NOT NULL COMMENT 'token FCM',
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    last_seen_at timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    UNIQUE KEY uk_push_devices_token (token),
    KEY ix_push_devices_user (user_id),
    CONSTRAINT fk_push_devices_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_notifications (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    user_id bigint(20) NOT NULL,
    app enum('web') NOT NULL DEFAULT 'web',
    type varchar(50) NOT NULL,
    title varchar(100) NOT NULL,
    body varchar(255) NOT NULL,
    data longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(data)),
    status enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts tinyint(4) NOT NULL DEFAULT 0,
    last_error varchar(255) DEFAULT NULL,
    source_key varchar(64) NOT NULL,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    sent_at timestamp NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_push_notifications_source (source_key),
    KEY ix_push_notifications_outbox (status, id),
    KEY ix_push_notifications_user (user_id, created_at),
    CONSTRAINT fk_push_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------
-- Estrategias por producto (el producto vive en la intranet: sin clave foránea)
-- ---------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS product_discount_rules (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    product_sku bigint(20) NOT NULL COMMENT 'codigo de tb_productos_megaplexstars',
    client_discount_percentage decimal(5,2) DEFAULT NULL COMMENT 'NULL = valor por defecto',
    trainer_discount_percentage decimal(5,2) DEFAULT NULL,
    commission_percentage decimal(5,2) DEFAULT NULL,
    valid_from timestamp NOT NULL DEFAULT current_timestamp(),
    valid_to timestamp NULL DEFAULT NULL,
    is_active tinyint(1) NOT NULL DEFAULT 1,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (id),
    KEY ix_product_discount_rules_lookup (product_sku, is_active, valid_from),
    CONSTRAINT ck_product_discount_rules_range CHECK (
        (client_discount_percentage IS NULL OR client_discount_percentage BETWEEN 0 AND 100)
        AND (trainer_discount_percentage IS NULL OR trainer_discount_percentage BETWEEN 0 AND 100)
        AND (commission_percentage IS NULL OR commission_percentage BETWEEN 0 AND 100)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------
-- Ajustes y auditoría
-- ---------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS adjustments (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    order_id bigint(20) NOT NULL,
    agent_id int(11) NOT NULL COMMENT 'agente que lo registra (tb_empleados)',
    type enum('cancellation','full_return','partial_return','restitution','correction') NOT NULL,
    reason text NOT NULL,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    KEY ix_adjustments_order (order_id),
    CONSTRAINT fk_adjustments_order FOREIGN KEY (order_id) REFERENCES orders (order_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    agent_id int(11) DEFAULT NULL COMMENT 'agente (tb_empleados)',
    user_id bigint(20) DEFAULT NULL COMMENT 'cliente o entrenador',
    action varchar(60) NOT NULL,
    entity varchar(60) NOT NULL,
    entity_id bigint(20) DEFAULT NULL,
    data longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(data)),
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    KEY ix_audit_logs_entity (entity, entity_id),
    KEY ix_audit_logs_agent (agent_id, created_at),
    KEY ix_audit_logs_user (user_id, created_at),
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------
-- Comisión: cierre mensual, liquidaciones, conversiones y movimientos
-- ---------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS commission_periods (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    period char(7) NOT NULL COMMENT 'AAAA-MM',
    period_start date NOT NULL,
    period_end date NOT NULL,
    closed_at timestamp NOT NULL DEFAULT current_timestamp(),
    trainers_count int(11) NOT NULL DEFAULT 0,
    orders_count int(11) NOT NULL DEFAULT 0,
    total_amount decimal(12,2) NOT NULL DEFAULT 0.00,
    file_name varchar(150) DEFAULT NULL COMMENT 'Excel en storage/private/reports',
    report_sent_at timestamp NULL DEFAULT NULL,
    report_recipients varchar(500) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_commission_periods_period (period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commission_settlements (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    period_id bigint(20) NOT NULL,
    trainer_id bigint(20) NOT NULL,
    orders_count int(11) NOT NULL DEFAULT 0,
    commission_amount decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'ventas del mes',
    conversions_amount decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'convertido a puntos',
    adjustments_amount decimal(12,2) NOT NULL DEFAULT 0.00,
    total_amount decimal(12,2) NOT NULL COMMENT 'a pagar',
    closed_at timestamp NOT NULL DEFAULT current_timestamp(),
    due_date date NOT NULL COMMENT 'día de pago, hábil',
    status enum('closed','paid') NOT NULL DEFAULT 'closed',
    paid_at timestamp NULL DEFAULT NULL,
    payment_reference varchar(100) DEFAULT NULL,
    receipt_path varchar(255) DEFAULT NULL COMMENT 'comprobante en storage/private/receipts',
    receipt_mime varchar(50) DEFAULT NULL,
    paid_by_agent_id int(11) DEFAULT NULL COMMENT 'agente financiero (tb_empleados)',
    PRIMARY KEY (id),
    UNIQUE KEY uk_commission_settlements_period_trainer (period_id, trainer_id),
    KEY ix_commission_settlements_trainer (trainer_id, closed_at),
    KEY ix_commission_settlements_status (status, due_date),
    CONSTRAINT fk_commission_settlements_period FOREIGN KEY (period_id) REFERENCES commission_periods (id) ON UPDATE CASCADE,
    CONSTRAINT fk_commission_settlements_trainer FOREIGN KEY (trainer_id) REFERENCES trainers (user_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commission_conversions (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    trainer_id bigint(20) NOT NULL,
    commission_amount decimal(12,2) NOT NULL COMMENT 'C',
    base_points int(11) NOT NULL COMMENT 'P: puntos vigentes al convertir',
    conversion_percentage decimal(5,2) NOT NULL COMMENT 'valor de COMMISSION_CONVERSION_PERCENTAGE',
    bonus_points int(11) NOT NULL COMMENT 'mín(porcentaje × P, C)',
    points_granted int(11) NOT NULL COMMENT 'C + adicional',
    idempotency_key varchar(64) NOT NULL,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    UNIQUE KEY uk_commission_conversions_key (idempotency_key),
    KEY ix_commission_conversions_trainer (trainer_id, created_at),
    CONSTRAINT fk_commission_conversions_trainer FOREIGN KEY (trainer_id) REFERENCES trainers (user_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commission_movements (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    order_id bigint(20) DEFAULT NULL,
    trainer_id bigint(20) NOT NULL,
    conversion_id bigint(20) DEFAULT NULL,
    adjustment_id bigint(20) DEFAULT NULL,
    settlement_id bigint(20) DEFAULT NULL COMMENT 'liquidación del mes',
    type enum('sale_credit','conversion_debit','adjustment','settlement_debit') NOT NULL,
    tax_base decimal(12,2) DEFAULT NULL,
    amount decimal(12,2) NOT NULL COMMENT '+ abono, − débito',
    source_key varchar(64) NOT NULL,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    UNIQUE KEY uk_commission_movements_source (source_key),
    UNIQUE KEY uk_commission_movements_conversion (conversion_id),
    KEY ix_commission_movements_balance (trainer_id, amount),
    KEY ix_commission_movements_period (trainer_id, created_at),
    KEY ix_commission_movements_order (order_id),
    CONSTRAINT fk_commission_movements_order FOREIGN KEY (order_id) REFERENCES orders (order_id) ON UPDATE CASCADE,
    CONSTRAINT fk_commission_movements_trainer FOREIGN KEY (trainer_id) REFERENCES trainers (user_id) ON UPDATE CASCADE,
    CONSTRAINT fk_commission_movements_conversion FOREIGN KEY (conversion_id) REFERENCES commission_conversions (id),
    CONSTRAINT fk_commission_movements_adjustment FOREIGN KEY (adjustment_id) REFERENCES adjustments (id),
    CONSTRAINT fk_commission_movements_settlement FOREIGN KEY (settlement_id) REFERENCES commission_settlements (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------
-- Puntos: lotes, acceso a dietas y movimientos
-- ---------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS point_lots (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    order_id bigint(20) DEFAULT NULL,
    trainer_id bigint(20) NOT NULL,
    conversion_id bigint(20) DEFAULT NULL,
    adjustment_id bigint(20) DEFAULT NULL,
    trainer_referral_id bigint(20) DEFAULT NULL,
    source enum('client_sale','trainer_referral','conversion','restitution') NOT NULL,
    initial_points int(11) NOT NULL,
    remaining_points int(11) NOT NULL,
    status enum('available','used','expired') NOT NULL DEFAULT 'available',
    accrued_at timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'orden FIFO',
    expires_at timestamp NOT NULL COMMENT 'accrued_at + POINTS_EXPIRATION_MONTHS',
    source_key varchar(64) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_point_lots_source (source_key),
    UNIQUE KEY uk_point_lots_conversion (conversion_id),
    UNIQUE KEY uk_point_lots_trainer_referral (trainer_referral_id),
    KEY ix_point_lots_balance (trainer_id, status, expires_at, source, remaining_points),
    KEY ix_point_lots_history (trainer_id, source, accrued_at, initial_points),
    KEY ix_point_lots_fifo (trainer_id, status, accrued_at, id),
    KEY ix_point_lots_expiration (status, expires_at),
    KEY ix_point_lots_order (order_id),
    CONSTRAINT fk_point_lots_order FOREIGN KEY (order_id) REFERENCES orders (order_id) ON UPDATE CASCADE,
    CONSTRAINT fk_point_lots_trainer FOREIGN KEY (trainer_id) REFERENCES trainers (user_id) ON UPDATE CASCADE,
    CONSTRAINT fk_point_lots_conversion FOREIGN KEY (conversion_id) REFERENCES commission_conversions (id),
    CONSTRAINT fk_point_lots_adjustment FOREIGN KEY (adjustment_id) REFERENCES adjustments (id),
    CONSTRAINT fk_point_lots_trainer_referral FOREIGN KEY (trainer_referral_id) REFERENCES trainer_referrals (id),
    CONSTRAINT ck_point_lots_points CHECK (initial_points > 0 AND remaining_points BETWEEN 0 AND initial_points)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diet_access_periods (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    trainer_id bigint(20) NOT NULL,
    period char(7) NOT NULL COMMENT 'AAAA-MM',
    source enum('free','sales_goal','points') NOT NULL,
    points_goal int(11) DEFAULT NULL COMMENT 'valor de DIET_ACCESS_POINTS_GOAL',
    points_earned int(11) DEFAULT NULL COMMENT 'puntos del mes anterior',
    points_spent int(11) DEFAULT NULL COMMENT 'valor de DIET_ACCESS_POINTS_COST',
    valid_from timestamp NOT NULL,
    valid_to timestamp NOT NULL,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    UNIQUE KEY uk_diet_access_periods (trainer_id, period, source),
    KEY ix_diet_access_periods_valid (trainer_id, valid_to),
    CONSTRAINT fk_diet_access_periods_trainer FOREIGN KEY (trainer_id) REFERENCES trainers (user_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS point_movements (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    point_lot_id bigint(20) NOT NULL,
    order_id bigint(20) DEFAULT NULL COMMENT 'canje',
    adjustment_id bigint(20) DEFAULT NULL,
    diet_access_period_id bigint(20) DEFAULT NULL COMMENT 'canje del acceso a dietas',
    type enum('credit','redemption','expiration','adjustment') NOT NULL,
    points int(11) NOT NULL COMMENT '+ abono, − consumo',
    source_key varchar(64) NOT NULL,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    UNIQUE KEY uk_point_movements_source (source_key),
    KEY ix_point_movements_lot (point_lot_id, created_at),
    KEY ix_point_movements_order (order_id),
    CONSTRAINT fk_point_movements_lot FOREIGN KEY (point_lot_id) REFERENCES point_lots (id),
    CONSTRAINT fk_point_movements_order FOREIGN KEY (order_id) REFERENCES orders (order_id) ON UPDATE CASCADE,
    CONSTRAINT fk_point_movements_adjustment FOREIGN KEY (adjustment_id) REFERENCES adjustments (id),
    CONSTRAINT fk_point_movements_diet_period FOREIGN KEY (diet_access_period_id) REFERENCES diet_access_periods (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------
-- Cupón del entrenador en el pedido (atribución de la venta)
-- ---------------------------------------------------------------------------------------------

ALTER TABLE orders_coupons
    ADD COLUMN IF NOT EXISTS trainer_id bigint(20) DEFAULT NULL COMMENT 'entrenador al que se atribuye la venta' AFTER order_id,
    ADD KEY IF NOT EXISTS ix_orders_coupons_trainer (trainer_id, order_id);

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'orders_coupons'
      AND CONSTRAINT_NAME = 'fk_orders_coupons_trainer'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE orders_coupons ADD CONSTRAINT fk_orders_coupons_trainer FOREIGN KEY (trainer_id) REFERENCES trainers (user_id) ON UPDATE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
