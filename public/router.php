<?php
declare(strict_types=1);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$file = realpath(__DIR__ . $path);
$root = realpath(__DIR__) . DIRECTORY_SEPARATOR;
if ($file && str_starts_with($file, $root) && is_file($file) && !in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['php','phtml','mp4','webm','mov','mp3','wav','ogg'], true)) return false;
require __DIR__ . '/index.php';
