# INTEGRA

Sistema PHP independiente para orientar, conectar y acompañar solicitudes de servicio. Laravel 13, PHP 8.3+, Blade, Alpine, Eloquent y Vite. Diseño basado en ENLACE: identidad roja/naranja/amarilla, Geist, barra lateral, tarjetas, tema claro/oscuro y navegación móvil.

Esta rama `feat/integra-php-laravel` reemplaza el stack de la propuesta anterior en una rama nueva. No requiere ejecutar Django. La instalación real, el proyecto Supabase y el correo SMTP aún deben configurarse.

## Funciones

- Organizaciones aisladas con una identidad global y membresías independientes. Directorio de personas, líderes y contactos; asignar un contacto no concede acceso.
- Roles editables, permisos por módulo y alcance por área. Administrador, gestor de procesos, gestor de solicitudes, revisor de casos asignados, responsable de área y soporte técnico.
- Áreas con capacidad, hasta dos líderes, contactos y requisitos. Correo opcional para contactos; obligatorio para acceso. Protección del último administrador activo.
- Flujos visuales con etapas, horas o días hábiles, salidas y roles autorizados. Cuestionarios con siete tipos de pregunta, campos sensibles y pruebas con puntajes orientativos.
- Versiones publicadas inmutables. Procesos y solicitudes conservan las versiones elegidas; los cambios posteriores no alteran solicitudes anteriores.
- Convocatorias públicas con fecha de cierre, enlace y QR. Captura sin cuenta, consentimiento, folio, revisión de posibles duplicados y asignación inicial.
- Bandeja con búsqueda y filtros, perfil, historial inmutable, notas, hallazgos, revisión de requisitos, entrevistas, responsable, próximos pasos, pausa y reanudación.
- Integración con decisión del líder, función y fecha; reorientación, cierre y archivo lógico. Nunca se acepta automáticamente una persona por su puntaje.
- Tareas personales de un solo uso, vencimiento a diez días, archivos privados PDF/JPG/PNG de hasta 5 MB, ficha PDF y exportaciones CSV protegidas contra fórmulas.
- Reportes de etapas y versiones, cargas, SLA, inactividad, mediana de ciclo, duración de etapas completadas y capacidad. Avisos internos y escalamiento de atrasos mayores de 48 horas.
- Recuperación de contraseña, sesiones nativas Laravel, auditoría, comprobación de Supabase y respaldo privado por comando.

## Instalación local

Necesitas PHP 8.3 o superior con PDO SQLite/PostgreSQL, ctype, curl, dom, fileinfo, mbstring, tokenizer, xml y zip; Composer 2 y Node 22.

```bash
git clone --branch feat/integra-php-laravel https://github.com/dev-tonyorozco/integra.git
cd integra
composer install
npm ci
cp .env.example .env
php artisan key:generate
php -r "touch('database/database.sqlite');"
php artisan migrate
npm run build
php artisan serve
```

El directorio público del servidor es `public/`. No sirvas la raíz del repositorio. No ejecutes `storage:link`: los adjuntos se descargan por una ruta autenticada.

Para datos de demostración, únicamente en local:

```bash
php artisan db:seed --class=DemoSeeder
```

Portal: `/c/comunidad-demo`. Acceso: `/login`.

| Cuenta local | Rol |
| --- | --- |
| admin@integra.test | Administración |
| gestor@integra.test | Gestión de solicitudes |
| procesos@integra.test | Gestión de procesos |
| consulta@integra.test | Responsable de Bienvenida |
| tecnico@integra.test | Soporte técnico |

Contraseña de demostración: `IntegraDemo2026!`. Estos usuarios son ficticios. El seeder rechaza producción y no vuelve a crear una organización ya sembrada.

Para una instalación vacía, crea el administrador con contraseña interactiva:

```bash
php artisan integra:bootstrap --organization="Mi comunidad" --slug=mi-comunidad --name="Administrador" --email=admin@mi-dominio.com
```

También admite `INTEGRA_ADMIN_PASSWORD` como variable privada del servidor. No recibe contraseñas como argumento del comando. Si reutiliza una identidad existente, conserva su contraseña.

## Supabase

PHP conecta directamente con PostgreSQL de Supabase. INTEGRA usa autenticación Laravel; no depende de Supabase Auth ni expone sus tablas mediante la Data API.

1. Crea el proyecto en la organización elegida. La cuenta `cerebralico` fue identificada; esta entrega no crea ni factura un proyecto.
2. Copia la conexión desde **Connect**. Para servidor IPv4 usa **Session pooler**, puerto **5432**. La contraseña en `DB_URL` debe codificar caracteres reservados. No uses el pooler transaccional 6543 con esta configuración de PDO y estado de sesión.
3. Configura variables privadas:

```dotenv
DB_CONNECTION=pgsql
DB_URL=postgresql://postgres.PROJECT_REF:ENCODED_PASSWORD@POOLER_HOST:5432/postgres
DB_SCHEMA=integra
DB_SSLMODE=require
```

4. Ejecuta sobre el proyecto propio:

```bash
php artisan integra:database-prepare
php artisan migrate --force
php artisan integra:supabase-check
```

El esquema `integra` es privado, revoca acceso a PUBLIC/anon/authenticated y habilita RLS. La conexión del backend actúa como propietario; la autorización por organización, área y rol se aplica en Laravel. No existe una política de acceso directo desde el navegador. No agregues `integra` a esquemas expuestos ni uses claves privilegiadas en JavaScript.

Para Supabase Storage crea un bucket **privado** `integra-private` con límite de 5 MB y tipos permitidos PDF/JPEG/PNG, y configura:

```dotenv
INTEGRA_FILES_DRIVER=supabase
SUPABASE_URL=https://PROJECT_REF.supabase.co
SUPABASE_SECRET_KEY=SERVER_SECRET_KEY
SUPABASE_BUCKET=integra-private
```

La clave `sb_secret_...` viaja sólo en llamadas HTTP del servidor. La descarga usa el endpoint autenticado y previamente valida permisos sobre la solicitud. Con `INTEGRA_FILES_DRIVER=local` utiliza `storage/app/private`, que requiere volumen persistente.

Documentación oficial: [Laravel con Supabase](https://supabase.com/docs/guides/getting-started/quickstarts/laravel), [conexiones PostgreSQL](https://supabase.com/docs/guides/database/connecting-to-postgres), [Render con PHP y Docker](https://render.com/docs/deploy-php-laravel-docker).

## Producción y Docker

Configura `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` HTTPS, una `APP_KEY` estable y privada y `SESSION_SECURE_COOKIE=true`. Genera la clave una vez, conserva una copia segura y compártela entre procesos de la instalación. Los enlaces y activos fuerzan HTTPS en producción.

```bash
docker compose build
docker compose up -d app
docker compose exec app php artisan integra:database-prepare
docker compose exec app php artisan migrate --force
docker compose exec app php artisan integra:bootstrap --organization="Mi comunidad" --slug=mi-comunidad --name="Administrador" --email=admin@mi-dominio.com
docker compose --profile scheduler up -d scheduler
```

El servicio local escucha en `127.0.0.1:8080`, detrás de un proxy HTTPS. El volumen preserva datos SQLite y adjuntos locales. Con Supabase la URL de conexión prevalece sobre `DB_DATABASE` del compose. No se ejecutan migraciones automáticamente al iniciar.

En Render: crea un **Web Service**, conecta este repositorio y rama, selecciona **Docker**, conserva el Dockerfile de la raíz y configura las variables anteriores. Usa Supabase PostgreSQL y Storage para datos y archivos duraderos. Ejecuta preparación, migraciones y bootstrap en el shell del servicio o mediante un job privado con el mismo entorno. Usa `/up` para salud. El contenedor escucha en el puerto 80. Configura un proceso planificador independiente con `php artisan schedule:work`, o un cron cada minuto. No ejecutes el cron desde una ruta pública. El Dockerfile está incluido; no se construyó una imagen Docker ni se desplegó un servicio externo en esta entrega.

En un servidor PHP convencional, instala dependencias con `composer install --no-dev --optimize-autoloader`, compila activos, asigna escritura a `storage/` y `bootstrap/cache/`, y ejecuta `php artisan optimize` después de configurar el entorno.

Para recuperación e invitación de acceso por correo, configura `MAIL_MAILER=smtp`, host, puerto, credenciales y remitente de tu proveedor. El modo `log` local no entrega mensajes. Las invitaciones se resuelven con recuperación de contraseña; no se envían invitaciones automáticamente al guardar una persona.

## Operación y respaldo

Cron del servidor:

```cron
* * * * * cd /ruta/integra && php artisan schedule:run >> /dev/null 2>&1
```

`php artisan integra:reminders` genera avisos idempotentes, omite pausadas/finalizadas/archivadas y escala atrasos de 48 horas. Los días hábiles omiten fines de semana; no existe calendario de festivos. Las fechas se almacenan y muestran en UTC en esta versión.

`php artisan integra:backup` crea un respaldo SQLite consistente o un dump del esquema PostgreSQL. Para PostgreSQL requiere `pg_dump` compatible con la versión del servidor. Los archivos de Supabase Storage o locales se respaldan por separado, junto con APP_KEY. Transfiere respaldos fuera del servidor y prueba restauración en una instalación aislada; el sistema no ofrece restauración destructiva desde la interfaz.

## Verificación de esta entrega

- 47 pruebas funcionales aprobadas con SQLite y PostgreSQL local PGlite: autorización, organizaciones, áreas, contactos/acceso, versiones, captura, transiciones, concurrencia, tareas, archivos, CSV, PDF, QR, recuperación y SLA.
- Recorrido real de navegador: login, catálogos, publicación con editores visuales, captura pública, notas y transición hasta integración; escritorio y móvil de 360 px, cinco comprobaciones sin desbordamiento y cero errores JavaScript.
- Vite, Laravel Pint, caché de vistas y rutas verificados.
- La integración HTTP Storage fue probada con respuestas simuladas; la conexión de producción, SMTP y Docker quedan pendientes de configuración y verificación en la infraestructura real.

```bash
php vendor/bin/phpunit
npm run build
php artisan view:cache
php artisan route:cache
```

El workflow GitHub Actions es **manual** (`workflow_dispatch`); subir código o abrir el PR no consume minutos de Actions automáticamente. Para PostgreSQL de pruebas define DB_CONNECTION/DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD/DB_SCHEMA y usa una base exclusiva de pruebas antes de ejecutar PHPUnit.
