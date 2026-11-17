-- Corrige en la intranet tres ciudades de las tarifas de Servientrega escritas con «Ð» en lugar de «Ñ».
-- Spec backend/03 (P-03). Fecha: 16 nov 2026.
--
-- Dónde: base `crisenri_intranet` (selecciónala en phpMyAdmin antes de ejecutar). PremiumFitness
-- nunca escribe en la intranet: este archivo lo ejecuta a mano quien administra la base.
--
-- Por qué: `tb_servientrega` (la que leen el webservice `/fletes` del sitio de Nutramerican y el flete
-- de PremiumFitness) y `tb_servientrega_v2` tienen «EL PEÐOL (NAR)», «EL PEÐON (SAN)» y
-- «EL PIÐON (MAG)». La «Ð» no es una Ñ: una búsqueda por «EL PIÑON» no encuentra la tarifa.
--
-- Solo cambia el nombre de la ciudad: tarifas, tipos y días quedan igual. Cada UPDATE busca el id y
-- el nombre exacto con «Ð», así que se puede ejecutar más de una vez sin efecto.

UPDATE `tb_servientrega`    SET `ciudad` = 'EL PEÑOL (NAR)' WHERE `id` = 359 AND `ciudad` = BINARY 'EL PEÐOL (NAR)';
UPDATE `tb_servientrega`    SET `ciudad` = 'EL PEÑON (SAN)' WHERE `id` = 360 AND `ciudad` = BINARY 'EL PEÐON (SAN)';
UPDATE `tb_servientrega`    SET `ciudad` = 'EL PIÑON (MAG)' WHERE `id` = 363 AND `ciudad` = BINARY 'EL PIÐON (MAG)';

UPDATE `tb_servientrega_v2` SET `ciudad` = 'EL PEÑOL (NAR)' WHERE `id` = 359 AND `ciudad` = BINARY 'EL PEÐOL (NAR)';
UPDATE `tb_servientrega_v2` SET `ciudad` = 'EL PEÑON (SAN)' WHERE `id` = 360 AND `ciudad` = BINARY 'EL PEÐON (SAN)';
UPDATE `tb_servientrega_v2` SET `ciudad` = 'EL PIÑON (MAG)' WHERE `id` = 363 AND `ciudad` = BINARY 'EL PIÐON (MAG)';

-- Verificación: debe devolver 0 en las dos tablas.
SELECT 'tb_servientrega' AS tabla, COUNT(*) AS con_eth FROM `tb_servientrega` WHERE `ciudad` LIKE BINARY '%Ð%'
UNION ALL
SELECT 'tb_servientrega_v2', COUNT(*) FROM `tb_servientrega_v2` WHERE `ciudad` LIKE BINARY '%Ð%';

-- Para deshacerlo (solo si algo que busque la escritura con «Ð» deja de encontrar la ciudad):
-- UPDATE `tb_servientrega`    SET `ciudad` = 'EL PEÐOL (NAR)' WHERE `id` = 359 AND `ciudad` = BINARY 'EL PEÑOL (NAR)';
-- UPDATE `tb_servientrega`    SET `ciudad` = 'EL PEÐON (SAN)' WHERE `id` = 360 AND `ciudad` = BINARY 'EL PEÑON (SAN)';
-- UPDATE `tb_servientrega`    SET `ciudad` = 'EL PIÐON (MAG)' WHERE `id` = 363 AND `ciudad` = BINARY 'EL PIÑON (MAG)';
-- UPDATE `tb_servientrega_v2` SET `ciudad` = 'EL PEÐOL (NAR)' WHERE `id` = 359 AND `ciudad` = BINARY 'EL PEÑOL (NAR)';
-- UPDATE `tb_servientrega_v2` SET `ciudad` = 'EL PEÐON (SAN)' WHERE `id` = 360 AND `ciudad` = BINARY 'EL PEÑON (SAN)';
-- UPDATE `tb_servientrega_v2` SET `ciudad` = 'EL PIÐON (MAG)' WHERE `id` = 363 AND `ciudad` = BINARY 'EL PIÑON (MAG)';
