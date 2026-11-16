-- Datos de desarrollo opcionales para reset-db.sh.
-- No se importan cuando el script se ejecuta con --clear.

INSERT INTO `users` (`id`, `document_id`, `document_type_id`, `full_name`, `num_cell`, `email`, `url_image`, `password`, `token_push_notification`, `socket_token`, `created_at`, `updated_at`) VALUES
(20, '6394880', 2, 'STEVEN HERMINSUL REALPE PARRA', '3168848850', 'realpelee@gmail.com', 'https://elitenutapp.com/intranet/uploads/avatars/avatar_1775604387559.png', '$argon2id$v=19$m=65536,t=4,p=1$VUltRFRIcDJ3cG5QdVBsSw$jQwzdmBSnq0ES8/Yq8I+iKUk22fj6FeI9RJudPfxUwA', NULL, NULL, '2026-04-13 19:52:42', '2026-04-14 19:47:59');

INSERT INTO `addresses` (`id`, `city_id`, `address`, `address_name`, `created_at`, `updated_at`) VALUES
(18, 55, 'CL 32B 4AE 23', 'ENTREGA', '2026-04-13 19:52:42', '2026-04-14 21:18:48'),
(24, 166, '# 22, Cl. 15 #25a207, Bodega c11 Terminal logístico', 'TRABAJO', '2026-04-14 21:12:51', '2026-04-14 21:12:51'),
(25, 10, 'CR 32 83 81', 'CASA BOGOTÁ', '2026-04-14 21:19:45', '2026-04-14 21:19:45');

INSERT INTO `address_locality_map` (`address_id`, `locality_id`) VALUES
(25, 1);

INSERT INTO `address_user_map` (`user_id`, `address_id`, `is_main`) VALUES
(20, 18, 0),
(20, 24, 1),
(20, 25, 0);
