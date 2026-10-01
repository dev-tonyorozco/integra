# INTEGRA

Sistema independiente para recibir, orientar y dar seguimiento a solicitudes de servicio por congregación, con autenticación propia y persistencia real. Flujos y cuestionarios configurables y versionados.

## Incluye

- Congregaciones aisladas, usuarios y cinco roles con permisos en servidor.
- Ministerios, líderes/contactos, requisitos y capacidad.
- Constructores de flujos, cuestionarios y pruebas; publicación inmutable y nuevas versiones.
- Convocatorias públicas, captura sin cuenta, consentimiento, validación y folio.
- Bandejas, búsqueda, filtros, responsable, siguiente acción, plazo y semáforo.
- Transiciones permitidas, control de concurrencia, reasignación y pausa/reanudación de SLA.
- Información adicional y pruebas con enlaces privados de un solo uso y vencimiento.
- Entrevistas, notas restringidas, respuesta del líder, integración y reorientación.
- Historial, ficha PDF sin campos sensibles, adjuntos privados PDF/JPG/PNG.
- Reportes, CSV seguro, avisos internos, escalamiento y respaldos reales.
- Recuperación de contraseña por SMTP, temas claro/oscuro e interfaz responsiva.

## Inicio local (Python 3.12)

```bash
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
export DEBUG=1
python manage.py migrate
python manage.py bootstrap --email admin@tu-dominio.com --congregation 'Mi congregación' --slug mi-congregacion
python manage.py runserver
```

En Windows PowerShell: `.venv\Scripts\Activate.ps1` y `$env:DEBUG='1'`. Abre http://localhost:8000. Bootstrap pide contraseña segura; no existen credenciales predeterminadas. Para una demo ficticia sobre una base vacía, sustituye bootstrap por `python manage.py seed_demo`; imprime una contraseña aleatoria temporal. La demo nunca se genera automáticamente en producción.

## Docker

```bash
cp .env.example .env
python -c "import secrets; print(secrets.token_urlsafe(64))"
# Copiar la clave generada a SECRET_KEY y configurar dominio/correo en .env.
docker compose up -d --build
docker compose exec web python manage.py bootstrap --email admin@tu-dominio.com --congregation 'Mi congregación' --slug mi-congregacion
```

Producción requiere HTTPS mediante proxy del servidor. El puerto se publica únicamente en 127.0.0.1. Datos persistentes en el volumen `integra_data`. Consulta [operación](docs/OPERATIONS.md).

## Verificación

```bash
python manage.py check
python manage.py makemigrations --check --dry-run
python manage.py test core
```

[Arquitectura y permisos](docs/ARCHITECTURE.md) · [Guía funcional](docs/USER_GUIDE.md) · [Alcance](specs/001-integra/spec.md) · [Validación](docs/VALIDATION.md)

## Límites operativos

Un servicio y un volumen SQLite local. No usar múltiples réplicas ni SQLite sobre red. Para escalar horizontalmente, migrar a PostgreSQL con pruebas específicas. Avisos internos; SMTP habilita recuperación de contraseña. Enlaces a pruebas/información se comparten manualmente por el canal autorizado. Los puntajes orientan; no reemplazan al líder. Adjuntos restringidos sin antivirus.

La configuración de Docker está incluida; publicar la aplicación requiere un servidor propio, dominio, HTTPS y configuración de correo.
