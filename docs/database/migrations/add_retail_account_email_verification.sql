-- Activación del acceso minorista por correo.
-- Es idempotente y no modifica ningún otro dato de users.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS email_verified_at timestamp NULL DEFAULT NULL
        AFTER password;
