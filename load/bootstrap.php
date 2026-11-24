<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
$dotenv->required(['APP_ENV', 'DB_HOST', 'DB_NAME', 'DB_USER', 'CORS_ALLOWED_ORIGINS'])->notEmpty();
$dotenv->required('DB_PASS');
$dotenv->required('APP_URL')->notEmpty();
$dotenv->required('WEB_APP_URL')->notEmpty();

// Autenticación (spec seguridad/01)
$dotenv->required(['JWT_SECRET', 'JWT_REFRESH_SECRET'])->notEmpty();
$dotenv->required('JWT_SECRET')->assert(fn ($v) => strlen($v) >= 32, 'JWT_SECRET must be at least 32 characters.');
$dotenv->required('JWT_REFRESH_SECRET')->assert(fn ($v) => strlen($v) >= 32, 'JWT_REFRESH_SECRET must be at least 32 characters.');
$dotenv->required([
    'JWT_ACCESS_TTL_MINUTES', 'JWT_REFRESH_TTL_DAYS', 'ADMIN_SESSION_TTL_HOURS',
    'OTP_LENGTH', 'OTP_TTL_MINUTES', 'OTP_MAX_ATTEMPTS', 'OTP_MAX_SENDS_PER_DAY',
])->isInteger();
$dotenv->ifPresent('SMS_DRIVER')->allowedValues(['relax', 'log', 'memory']);

// Constantes de negocio (docs/reglas-de-negocio.md): ninguna se escribe en el código.
$dotenv->required([
    'CLIENT_DISCOUNT_PERCENTAGE', 'TRAINER_DISCOUNT_PERCENTAGE', 'CLIENT_PURCHASE_POINTS_PERCENTAGE',
    'TRAINER_COMMISSION_PERCENTAGE', 'COMMISSION_CONVERSION_PERCENTAGE', 'VAT_PERCENTAGE',
])->assert(fn ($v) => is_numeric($v) && $v >= 0 && $v <= 100, 'must be a percentage between 0 and 100.');
$dotenv->required(['TRAINER_REFERRAL_POINTS', 'POINTS_EXPIRATION_MONTHS'])->isInteger();

// Flete por ciudad (spec backend/03, P-03): los valores de la tienda de radical.
$dotenv->required([
    'SHIPPING_MINIMUM_FEE', 'SHIPPING_MAIN_CITIES_FEE', 'SHIPPING_MAIN_CITIES_FREE_FROM', 'SHIPPING_BOGOTA_FEE',
    'SHIPPING_BOGOTA_FREE_FROM', 'SHIPPING_RATE_DECLARED_VALUE', 'SHIPPING_RATE_SURCHARGE', 'SHIPPING_RATE_FLOOR_LIMIT',
    'SHIPPING_RATE_FLOOR', 'SHIPPING_RATE_TYPE3_FEE', 'SHIPPING_RATE_TYPE4_FEE',
])->isInteger();
$dotenv->required('SHIPPING_RATE_HANDLING_PERCENTAGE')->assert(fn ($v) => is_numeric($v) && $v >= 0 && $v <= 100, 'must be a percentage between 0 and 100.');
$dotenv->required(['SHIPPING_ORIGINS', 'SHIPPING_MAIN_CITIES'])->notEmpty();

// Acceso a la app de dietas (spec integraciones/02) y pago de la comisión (spec negocio/05).
$dotenv->required(['DIET_ACCESS_POINTS_GOAL', 'DIET_ACCESS_POINTS_COST', 'DIET_FREE_DAYS', 'COMMISSION_PAYMENT_DAY'])->isInteger();
$dotenv->required('DIET_ACCESS_API_KEY')->assert(fn ($v) => strlen($v) >= 32, 'DIET_ACCESS_API_KEY must be at least 32 characters.');
$dotenv->required('DIET_ACCESS_RENEW_URL')->notEmpty();
$dotenv->required('COMMISSION_PAYMENT_SHIFT')->allowedValues(['next', 'previous']);
$dotenv->required('NON_BUSINESS_DAYS');

// Notificaciones push (spec integraciones/01) y reportes del cierre (spec negocio/05).
$dotenv->required(['FIREBASE_CREDENTIALS_PATH', 'REPORTS_STORAGE_PATH', 'RECEIPTS_STORAGE_PATH', 'ADMIN_APP_URL', 'ADMIN_MANAGER_ROLES'])->notEmpty();
$dotenv->required('FIREBASE_PROJECT_ID');
$dotenv->required(['PUSH_MAX_ATTEMPTS', 'PUSH_EXPIRY_NOTICE_DAYS'])->isInteger();

// Catálogo de la intranet (spec catalogo/01). El nombre de la base va en el SQL: se valida aquí.
$dotenv->required('INTRANET_DB_NAME')->assert(fn ($v) => preg_match('/^[a-z0-9_]+$/', $v) === 1, 'must be a valid database name.');
$dotenv->required('PRODUCT_CATALOG_CACHE_SECONDS')->isInteger();
$dotenv->required(['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM', 'MAIL_FROM_NAME', 'MAIL_ENCRYPTION'])->notEmpty();
$dotenv->required(['SAP_API_URL', 'SAP_API_KEY'])->notEmpty();
$dotenv->required(['SMS_API_URL', 'SMS_API_TOKEN'])->notEmpty();

// Payment Gateways — Sistecrédito (Credinet)
$dotenv->required([
    'SISTECREDITO_URL_CREATE_ORDER', 'SISTECREDITO_URL_QUERY_TRANSACTION',
    'SISTECREDITO_SUBSCRIPTION_KEY', 'SISTECREDITO_APPLICATION_KEY', 'SISTECREDITO_APPLICATION_TOKEN',
    'SISTECREDITO_URL_RESPONSE', 'SISTECREDITO_URL_CONFIRMATION', 'SISTECREDITO_SANDBOX_ACTIVE',
])->notEmpty();

// Payment Gateways — Addi
$dotenv->required([
    'ADDI_AUDIENCE', 'ADDI_URL_TOKEN', 'ADDI_URL_REDIRECTION',
    'ADDI_CLIENT_ID', 'ADDI_CLIENT_SECRET', 'ADDI_GRANT_TYPE',
    'ADDI_URL_CALLBACK', 'ADDI_URL_REDIRECT',
])->notEmpty();

// Payment Gateways — Bold
$dotenv->required([
    'BOLD_IDENTITY_KEY', 'BOLD_SECRET_KEY',
    'BOLD_URL_NOTIFICATIONS', 'BOLD_URL_RESPONSE',
])->notEmpty();

// Payment Gateways — compartidas + socket post-pago
$dotenv->required(['PAYMENT_GATEWAY_TIMEOUT_SECONDS'])->notEmpty();
$dotenv->required(['SOCKET_NOTIFICATION_URL', 'SOCKET_NOTIFICATION_TOKEN'])->notEmpty();
$dotenv->required(['SOCKET_GATEWAY_PROJECT_ID_CLIENT', 'SOCKET_GATEWAY_PROJECT_ID_ADMIN', 'SOCKET_GATEWAY_KEY_ADMIN'])->notEmpty();

// Cron Job — consumo HTTP interno desde crontab
$dotenv->required(['CRON_JOB_TOKEN', 'CRON_JOB_PAYMENT_EVENTS_URL'])->notEmpty();

date_default_timezone_set('America/Bogota');
