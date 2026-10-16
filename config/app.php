<?php
return [
    'name' => env('APP_NAME', 'Registro Asistencia CCITP'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'America/Lima'),
    'locale' => 'es',
    'fallback_locale' => 'es',
    'faker_locale' => 'es_PE',
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',
];
