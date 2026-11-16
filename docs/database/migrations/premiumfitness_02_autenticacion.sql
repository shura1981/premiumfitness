-- PremiumFitness 02: autenticación (specs/seguridad/01-autenticacion-y-sesiones.md).
-- El celular es el usuario del login y el documento identifica a la persona ante la app de dietas
-- y SAP: los dos pasan a ser únicos. Se puede ejecutar más de una vez.

ALTER TABLE users
    MODIFY num_cell varchar(20) NOT NULL,
    ADD UNIQUE KEY IF NOT EXISTS uk_users_num_cell (num_cell),
    ADD UNIQUE KEY IF NOT EXISTS uk_users_document (document_id);

-- En radical `expires_at` se actualizaba sola (ON UPDATE): marcar un código como usado le cambiaba
-- el vencimiento. Ahora solo la fija quien crea el código.
ALTER TABLE verification_codes
    MODIFY expires_at timestamp NOT NULL,
    ADD KEY IF NOT EXISTS ix_verification_codes_active (user_id, purpose, used_at, expires_at),
    ADD KEY IF NOT EXISTS ix_verification_codes_destination (destination, created_at);

-- `token` guarda el hash del token de un solo uso que sale de verificar el código SMS.
ALTER TABLE password_reset_tokens
    MODIFY expires_at timestamp NOT NULL;
