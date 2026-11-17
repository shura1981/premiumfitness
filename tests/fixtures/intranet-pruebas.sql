-- Datos de prueba de la intranet para la base `crisenri_intranet_pruebas`, que crean las pruebas
-- (tests/Support/TestIntranet.php). Nunca se cargan en `crisenri_intranet`: esa base solo se lee.
-- Si `crisenri_intranet` existe, las tablas se crean antes con su misma estructura (CREATE TABLE ... LIKE)
-- y los CREATE TABLE de este archivo no hacen nada; si no existe, son la estructura mínima.
-- Las personas y contraseñas de este archivo son inventadas.

CREATE TABLE IF NOT EXISTS `tb_empleados` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `email` varchar(150) NOT NULL,
  `pass` varchar(255) DEFAULT NULL,
  `usuario` varchar(150) NOT NULL,
  `departamento` varchar(100) DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `celular` bigint(20) DEFAULT NULL,
  `sede` varchar(100) DEFAULT NULL,
  `cargo` varchar(100) DEFAULT NULL,
  `F` varchar(20) NOT NULL DEFAULT '',
  `activo` bit(1) NOT NULL DEFAULT b'1',
  `deleteSoft` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_empleados_id_usuario` (`id_usuario`),
  UNIQUE KEY `uq_empleados_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estructura real de la tabla de productos de la web (SHOW COLUMNS del 15 nov 2026; spec catalogo/01).
CREATE TABLE IF NOT EXISTS `tb_productos_megaplexstars` (
  `codigo` bigint(20) NOT NULL AUTO_INCREMENT,
  `orden` int(11) NOT NULL DEFAULT 50,
  `producto` varchar(255) DEFAULT NULL,
  `valor` int(255) DEFAULT NULL,
  `thumbnails` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `activo` bit(1) DEFAULT b'1',
  `indexable` bit(1) NOT NULL DEFAULT b'1',
  `descuento` int(11) NOT NULL DEFAULT 0,
  `agotado` bit(1) NOT NULL DEFAULT b'0',
  `imgtabla` varchar(200) NOT NULL DEFAULT '',
  `category` varchar(50) NOT NULL DEFAULT '',
  `invima` text NOT NULL DEFAULT '',
  `presentation` varchar(60) NOT NULL DEFAULT '',
  `intro` text NOT NULL DEFAULT '',
  `arte` varchar(200) NOT NULL DEFAULT '',
  `memoficha` varchar(200) NOT NULL DEFAULT '',
  `imgcard` longtext NOT NULL DEFAULT '',
  `imgLinkInstagram` longtext DEFAULT NULL,
  `video` varchar(100) DEFAULT NULL,
  `title` text NOT NULL DEFAULT '',
  `description` text NOT NULL DEFAULT '',
  `datanutritional` text NOT NULL DEFAULT '',
  `ingredientes` text NOT NULL DEFAULT '',
  `uso` text NOT NULL DEFAULT '',
  `promo` bit(1) NOT NULL DEFAULT b'0',
  `qr` bit(1) NOT NULL DEFAULT b'0',
  `bestsale` bit(1) NOT NULL DEFAULT b'0',
  `ruta` varchar(100) DEFAULT NULL,
  `images` longtext DEFAULT NULL,
  `tablasjpg` longtext DEFAULT NULL,
  `imageswebp` longtext DEFAULT NULL,
  `keyfind` varchar(500) DEFAULT '',
  `html` text NOT NULL DEFAULT '',
  `grupo` varchar(200) NOT NULL DEFAULT '',
  `meta_title` varchar(100) DEFAULT NULL,
  `sap_code` bigint(20) NOT NULL DEFAULT 0,
  `sap_name` varchar(200) DEFAULT NULL,
  `old_price` int(255) NOT NULL DEFAULT 0,
  `callcenter` bit(1) DEFAULT b'1',
  `camiseta` bit(1) NOT NULL DEFAULT b'0',
  `nuevo` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agentes de prueba del admin (contraseña de los dos: admin-local-123).
-- En la intranet, `id` e `id_usuario` coinciden; el backend busca por `id_usuario`.
INSERT IGNORE INTO `tb_empleados` (`id`, `id_usuario`, `email`, `pass`, `usuario`, `departamento`, `celular`, `sede`, `cargo`, `F`) VALUES
  (9001, 9001, 'agente.prueba@premiumfitness.local', 'admin-local-123', 'Agente de Prueba', 'CALL CENTER', 3000000001, 'CALI', 'CALL CENTER', '2026-01-15'),
  (9002, 9002, 'financiero.prueba@premiumfitness.local', 'admin-local-123', 'Financiero de Prueba', 'FINANCIERO', 3000000002, 'CALI', 'ADMINISTRADOR', '2026-01-15');

-- Algunos productos reales del catálogo, con sus códigos y precios (15 nov 2026).
INSERT IGNORE INTO `tb_productos_megaplexstars` (`codigo`, `orden`, `producto`, `valor`, `category`, `presentation`, `grupo`, `sap_code`, `sap_name`, `promo`, `agotado`) VALUES
  (100507, 1, 'BIPRO CLASSIC VAINILLA 2LB', 249000, 'MÓDULOS PROTEICOS', '2 lb. Vainilla', 'bipro-classic', 100570, 'Bipro Classic Vainilla 2 lb Bolsa', b'0', b'0'),
  (100512, 2, 'BIPRO CLASSIC CHOCOLATE 2lb', 249000, 'MÓDULOS PROTEICOS', '2 lb. Chocolate', 'bipro-classic', 100512, 'BIPRO CLASSIC chocolate 2 Lb (910g)', b'0', b'0'),
  (100517, 3, 'BIPRO LITE', 249000, 'MÓDULOS PROTEICOS', '2.4 lb. Vainilla', '', 100517, 'BIPRO LITE vainilla 2.4 b (1080g)', b'0', b'0'),
  (102060, 4, 'CREA STACK', 149990, 'AMINOÁCIDOS Y ENERGÍA', '1.3 lb. Frutos rojos', '', 102060, 'CREA STACK frutos rojos 1.3 Lb (600g)', b'0', b'0'),
  (103040, 5, 'Nutra-C', 64990, 'NUTRICIÓN GENERAL', '1.1 lb. Naranja', '', 103040, 'NUTRA-C naranja 1,1 Lb (500 g)', b'0', b'0'),
  (100540293, 6, 'NUTRA POPS', 59900, 'SNACKS', '500 g', '', 100552, 'Nutra pops proteina 500 g', b'0', b'1'),
  (100540363, 7, 'PROMO NUTRA-C PAGUE 1 LLEVA 2', 64990, 'NUTRICIÓN GENERAL', '', '', 106763, 'PROMO NUTRA-C naranja 1,1 Lb (500 g) PAGUE 1 LLEVA 2', b'1', b'0');

-- Imágenes y ficha de ejemplo para desarrollo, con el formato real de `imageswebp` y `tablasjpg`:
-- un JSON con las URL de cada tamaño (small, medium, semiMedium, big, bigger y seo).
UPDATE `tb_productos_megaplexstars`
SET `imageswebp` = JSON_OBJECT(
        'small',  'https://nutramerican.com/api_MegaplexStar/assets/bipro-vainilla2lb-bolsa/biproclassic_vainilla2l/biproclassic_vainilla2l-nutramerican-pharma-small.webp',
        'medium', 'https://nutramerican.com/api_MegaplexStar/assets/bipro-vainilla2lb-bolsa/biproclassic_vainilla2l/biproclassic_vainilla2l-nutramerican-pharma-medium.webp',
        'big',    'https://nutramerican.com/api_MegaplexStar/assets/bipro-vainilla2lb-bolsa/biproclassic_vainilla2l/biproclassic_vainilla2l-nutramerican-pharma-big.webp',
        'bigger', 'https://nutramerican.com/api_MegaplexStar/assets/bipro-vainilla2lb-bolsa/biproclassic_vainilla2l/biproclassic_vainilla2l-nutramerican-pharma-bigger.webp'
    ),
    `intro` = 'Proteína de suero para después de entrenar.',
    `description` = '<p>Proteína de suero de leche. Texto de ejemplo para desarrollo.</p>',
    `uso` = 'Mezcla una porción en 250 ml de agua o leche.'
WHERE `codigo` = 100507 AND `imageswebp` IS NULL;

-- Tarifas de Servientrega (spec backend/03, P-03), con la estructura real de `tb_servientrega`: costo
-- (`_c`, con coma decimal), tipo (`_t`) y días (`_d`) desde cada bodega. Los valores son de prueba;
-- las ciudades son las de `cities`. «N.A.»: sin servicio desde esa bodega.
CREATE TABLE IF NOT EXISTS `tb_servientrega` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ciudad` varchar(50) NOT NULL,
  `departamento` varchar(50) NOT NULL,
  `barranquilla_c` varchar(50) NOT NULL, `bogota_c` varchar(50) NOT NULL, `bucaros_c` varchar(50) NOT NULL,
  `cali_c` varchar(50) NOT NULL, `cucuta_c` varchar(50) NOT NULL, `medallo_c` varchar(50) NOT NULL,
  `barranquilla_t` varchar(50) NOT NULL, `bogota_t` varchar(50) NOT NULL, `bucaros_t` varchar(50) NOT NULL,
  `cali_t` varchar(50) NOT NULL, `cucuta_t` varchar(50) NOT NULL, `medallo_t` varchar(50) NOT NULL,
  `barranquilla_d` varchar(50) NOT NULL, `bogota_d` varchar(50) NOT NULL, `bucaros_d` varchar(50) NOT NULL,
  `cali_d` varchar(50) NOT NULL, `cucuta_d` varchar(50) NOT NULL, `medallo_d` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

REPLACE INTO `tb_servientrega`
  (`id`, `ciudad`, `departamento`,
   `barranquilla_c`, `bogota_c`, `bucaros_c`, `cali_c`, `cucuta_c`, `medallo_c`,
   `barranquilla_t`, `bogota_t`, `bucaros_t`, `cali_t`, `cucuta_t`, `medallo_t`,
   `barranquilla_d`, `bogota_d`, `bucaros_d`, `cali_d`, `cucuta_d`, `medallo_d`) VALUES
  -- La más barata es desde Bogotá: 10.000,75 → 10.000 + 1.500 + 2.000 = 13.500 (la de Cali, 23.500).
  (1, 'ARMENIA (QUI)', 'QUINDIO', 'N.A.', '10000,75', 'N.A.', '20000', 'N.A.', 'N.A.', 'N.A.', '1', 'N.A.', '1', 'N.A.', 'N.A.', 'N.A.', '2', 'N.A.', '3', 'N.A.', 'N.A.'),
  -- Ninguna bodega llega.
  (2, 'APARTADO (ANT)', 'ANTIOQUIA', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.'),
  (3, 'BOGOTA (CUN)', 'CUNDINAMARCA', '10500,75', '3315', 'N.A.', '6201', 'N.A.', 'N.A.', '1', '3', 'N.A.', '1', 'N.A.', 'N.A.', '2', '1', 'N.A.', '1', 'N.A.', 'N.A.'),
  (4, 'CALI (VAL)', 'VALLE DEL CAUCA', 'N.A.', '6201', 'N.A.', '3315', 'N.A.', 'N.A.', 'N.A.', '1', 'N.A.', '3', 'N.A.', 'N.A.', 'N.A.', '1', 'N.A.', '1', 'N.A.', 'N.A.'),
  -- Tipo 3: 4.900, que sube al mínimo de 9.900.
  (5, 'PALMIRA (VAL)', 'VALLE DEL CAUCA', 'N.A.', 'N.A.', 'N.A.', '3315', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.', '3', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.', '1', 'N.A.', 'N.A.'),
  -- En `cities` es «OCA?A (NOR)». Tipo 4: 11.400.
  (6, 'OCAÑA (NOR)', 'NORTE DE SANTANDER', 'N.A.', 'N.A.', 'N.A.', 'N.A.', '9000', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.', '4', 'N.A.', 'N.A.', 'N.A.', 'N.A.', 'N.A.', '2', 'N.A.');
