-- Migración: users.id INT(11) -> BIGINT(20)
-- Incluye las 5 columnas hijas con FOREIGN KEY hacia users.id
-- Ejecutar completo. Tomar backup antes (mysqldump) por si se interrumpe a mitad.

START TRANSACTION;

-- 1. Quitar las FKs que referencian users.id
ALTER TABLE `address_user_map`     DROP FOREIGN KEY `fk_aum_user`;
ALTER TABLE `credit_balances`      DROP FOREIGN KEY `fk_credit_user`;
ALTER TABLE `orders`               DROP FOREIGN KEY `fk_orders_user`;
ALTER TABLE `password_reset_tokens` DROP FOREIGN KEY `fk_prt_user_id`;
ALTER TABLE `verification_codes`   DROP FOREIGN KEY `fk_verification_codes_user`;

-- 2. Ampliar la columna padre
ALTER TABLE `users` MODIFY `id` BIGINT(20) NOT NULL AUTO_INCREMENT;

-- 3. Ampliar las columnas hijas al mismo tipo
ALTER TABLE `address_user_map`      MODIFY `user_id` BIGINT(20) NOT NULL;
ALTER TABLE `credit_balances`       MODIFY `user_id` BIGINT(20) NOT NULL;
ALTER TABLE `orders`                MODIFY `user_id` BIGINT(20) NOT NULL;
ALTER TABLE `password_reset_tokens` MODIFY `user_id` BIGINT(20) NOT NULL;
ALTER TABLE `verification_codes`    MODIFY `user_id` BIGINT(20) NOT NULL;

-- 4. Recrear las FKs con las mismas reglas ON DELETE/ON UPDATE originales
ALTER TABLE `address_user_map`
  ADD CONSTRAINT `fk_aum_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `credit_balances`
  ADD CONSTRAINT `fk_credit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

ALTER TABLE `password_reset_tokens`
  ADD CONSTRAINT `fk_prt_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `verification_codes`
  ADD CONSTRAINT `fk_verification_codes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

COMMIT;
