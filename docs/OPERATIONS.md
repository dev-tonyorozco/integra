# Operación del servidor

## Instalación

Python 3.12 y Docker Compose. Copiar .env.example a .env, generar SECRET_KEY aleatoria, ajustar ALLOWED_HOSTS, SITE_URL y CSRF_TRUSTED_ORIGINS al dominio propio. No usar valores de ejemplo. Arrancar `docker compose up -d --build`; crear administrador con bootstrap desde terminal interactiva.

El puerto se publica solo en 127.0.0.1. Configurar Nginx/Caddy con HTTPS delante del puerto 8000. Las cookies productivas requieren HTTPS. No activar DEBUG en una interfaz expuesta. No confiar en encabezados de proxy provenientes de internet. Si el proxy termina TLS y se configura SECURE_SSL_REDIRECT=1, configurar de manera controlada SECURE_PROXY_SSL_HEADER en settings y sanitizar ese encabezado en el proxy; sin esa configuración, dejar redirección HTTPS en el proxy para evitar bucles.

Healthcheck usa /health/. Crear la base con `migrate`; no cargar la demo en producción. El volumen integra_data almacena base, adjuntos y copias. Solo un servicio comparte este volumen local. La imagen corre como usuario no root. Verificar permisos del volumen si se sustituye por bind mount.

## Recordatorios y copias

Programar estos comandos en cron del servidor (adaptar el directorio absoluto de instalación):

```cron
*/15 * * * * cd /srv/integra && docker compose exec -T web python manage.py sla_reminders
0 3 * * * cd /srv/integra && docker compose exec -T web python manage.py backup
```

Los recordatorios son internos e idempotentes. Una copia contiene todas las congregaciones y los adjuntos; no compartir ZIP con un usuario de una sola congregación. La UI no expone el archivo de respaldo por HTTP. El comando imprime el nombre de la copia. Copiar a almacenamiento externo cifrado usando herramientas del operador y definir retención; no hay borrado automático de copias.

## Restauración

1. Detener web. Tomar otra copia del volumen actual y conservar .env/SECRET_KEY.
2. Obtener el ZIP desde el volumen privado del servidor, verificar su origen y listar contenido.
3. Extraer en un directorio temporal aislado; no extraer un ZIP no confiable en el volumen.
4. Comprobar SQLite con `PRAGMA integrity_check` y presencia de tablas.
5. Reemplazar integra.sqlite3 y uploads/ del volumen apagado con la copia validada; ajustar propietario uid 10001 y permisos privados. No conservar archivos -wal/-shm de la base anterior.
6. Arrancar web, que ejecutará migraciones compatibles pendientes. Verificar /health/, login, conteos, una solicitud y descarga de archivo.
7. Si falla, parar y recuperar el volumen anterior. La restauración es una tarea del operador; nunca se ejecuta desde una sesión funcional del sistema.

## Correo y usuarios

SMTP configurable con EMAIL_HOST/PORT/USER/PASSWORD, EMAIL_USE_TLS y DEFAULT_FROM_EMAIL. Recuperación de contraseña usa el proveedor SMTP propio y no revela si la dirección existe. Verificar envío y el enlace HTTPS en el servidor real antes de ofrecer esa función. No hay envío SMTP durante pruebas automatizadas ni por creación de solicitudes.

Si no existe SMTP, el operador puede cambiar contraseña desde terminal con `python manage.py changepassword correo@dominio`; no imprimir contraseñas en logs ni pasarla en argumentos. Conceder otra congregación con `grant_access --email ... --slug ... --role ...`.

## Actualizaciones

Respaldar antes de actualizar. Construir imagen nueva, ejecutar migración y pruebas en una copia de datos antes de producción. Conservar imagen anterior y respaldo de datos para rollback. Pin de dependencias en requirements.txt; revisar actualizaciones de seguridad periódicamente. Archivos solo PDF/JPG/PNG de 5 MB; sin antivirus. Definir aviso de privacidad y retención legal con los responsables de la congregación.
