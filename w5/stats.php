<?php
// Statistikken for begge udgaver er samlet på forsiden: statistik.php
require_once __DIR__ . '/../lib/config.php';
header('Location: ' . SITE_URL . 'statistik.php', true, 301);
