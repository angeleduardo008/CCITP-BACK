# Backend Laravel 12

## Requisitos
- PHP 8.2+
- Composer 2
- MySQL 8 / MariaDB compatible
- Extensiones: pdo_mysql, mbstring, openssl, fileinfo, curl, xml, dom, tokenizer, ctype

## Instalación

```bat
cd backend-laravel
composer install
copy .env.example .env
php artisan key:generate
```

Edite `.env` y coloque las credenciales reales de MySQL. La base debe ser `bd_CCITP`.

Genere un secreto JWT:

```bat
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Pegue el resultado en `JWT_SECRET=`.

Luego:

```bat
php artisan storage:link
php artisan app:crear-admin admin admin@ccitp.local
php artisan serve
```

API: http://127.0.0.1:8000/api

## Importante
No ejecute `php artisan migrate:fresh` sobre la base real. Las 18 tablas ya existen y este proyecto está preparado para trabajar con ellas.
