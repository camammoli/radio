<?php
// Copiá este archivo como config.php y completá los valores
define('RADIO_ADMIN_KEY', 'cambiame');
define('ADMIN_USER', 'admin');   // usuario del panel /admin.php
define('ADMIN_PASS', 'cambiame'); // contraseña del panel /admin.php
define('TG_TOKEN',  'botTOKEN:xxxx');
define('TG_CHAT_ID', '12345678');
define('NOTIFY_OYENTES', false); // true para recibir notificaciones cuando hay oyentes
define('GITHUB_PAT', '');        // PAT con scope repo/workflow para disparar add-station.yml
define('GA_ID', '');             // Google Analytics 4 Measurement ID (ej: G-XXXXXXXXXX) — vacío = desactivado
// V2: ruta a la DB SQLite (por defecto: ../../db/radio_v2.sqlite relativo a web/api/)
// define('RADIO_DB', '/home/usuario/radio/db/radio_v2.sqlite');
// V2: prefijo URL base (para staging en /radio/beta/)
// define('RADIO_BASE', '/radio/beta');
