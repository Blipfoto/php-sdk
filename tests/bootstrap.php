<?php

require __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_cookies', '0');
    ini_set('session.cache_limiter', '');
    session_start();
}