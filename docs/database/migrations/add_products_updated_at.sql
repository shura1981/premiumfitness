-- Fecha de última modificación de cada producto.
--
-- Alimenta el <lastmod> del sitemap público, que indica a los buscadores qué
-- fichas han cambiado y merecen volver a rastrearse.
--
-- MariaDB la mantiene sola: DEFAULT la rellena en cada INSERT y
-- ON UPDATE la refresca en cada UPDATE que cambie algún valor. No requiere
-- cambios en ProductRepository::create() ni ::update(), que listan columnas
-- de forma explícita.
--
-- Es idempotente y no modifica ningún otro dato de products. Las filas
-- existentes quedan con la fecha de ejecución de esta migración.

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS updated_at timestamp NOT NULL
        DEFAULT current_timestamp() ON UPDATE current_timestamp();
