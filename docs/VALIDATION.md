# Validación de INTEGRA

Fecha: 1 de octubre de 2026. Instalación aislada con datos ficticios; ninguna base del usuario fue modificada.

## Verificación automática

- `python manage.py check`: sin incidencias.
- `python manage.py makemigrations --check --dry-run`: sin cambios de modelo pendientes.
- `python manage.py test core`: **35 pruebas, todas PASS**.
- `ruff check .` y `ruff format --check .`: PASS.
- `python manage.py collectstatic --noinput`: PASS, recursos comprimidos y manifiesto generados.

Cobertura funcional: recepción pública/folio/consentimiento, campos obligatorios, convocatoria cerrada, duplicados, responsabilidad, transición y control de revisión, rutas inválidas, decisión ministerial, integración con fecha/función, reorientación activa, aislamiento de lectura/mutación/exportación, consulta restringida, rol técnico, versiones inmutables, validación de definiciones/preguntas, reasignación, pausa/reanudación, días hábiles, notas privadas, pruebas de un solo uso/puntaje, expiración, historial inmutable, CSRF, adjuntos rechazados, PDF, recordatorios idempotentes, bloqueo de login, usuarios inactivos, respaldo consistente, cabeceras de privacidad, QR y prevención de desactivación de responsables con casos activos.

El test de respaldo crea una base auxiliar y una copia ZIP privada; Django emite una advertencia por sobrescribir DATABASES solo dentro de esa prueba. No hay fallas de prueba.

## Navegador

Chromium headless con Playwright, contra servidor Django local y datos ficticios.

| Recorrido | Resultado |
|---|---|
| Login y resumen en escritorio 1440 px | PASS |
| Cambio a tema oscuro | PASS |
| Resumen a 360 px, sin desbordamiento horizontal | PASS |
| Crear y guardar flujo con constructor visual | PASS |
| Agregar pregunta en constructor de cuestionarios | PASS |
| Formulario público a 360 px, validación y envío | PASS |
| Confirmación con folio y persistencia en bandeja | PASS |
| Agregar actividad y pasar a conversación | PASS |
| Integración con decisión del líder, función y fecha | PASS |
| Reportes y cumplimiento por fase | PASS |
| Errores JavaScript durante los recorridos | Ninguno |

Se inspeccionaron capturas de escritorio y móvil. Los constructores funcionan sin exponer la definición técnica cuando JavaScript está habilitado; el servidor valida la configuración aunque la entrada del navegador sea manipulada.

## Límites de la evidencia

No existe Docker en el entorno de ejecución: la imagen y Compose se revisaron, pero no se ejecutó `docker build`. Se comprobó la aplicación directamente en Python y la generación de estáticos que utiliza la imagen. El operador debe probar la imagen en su servidor antes de producción.

SMTP, dominio, HTTPS, restauración en un servidor real y carga sostenida no se validaron porque no se proporcionó una instalación objetivo. No se realizó auditoría formal WCAG; existe estructura semántica, etiquetas, foco visible y enlace para saltar al contenido, con revisión de los recorridos principales.

## Repetir la prueba en navegador

En una base **local vacía**, con DEBUG=1, ejecutar migrate y seed_demo. Instalar requirements-dev.txt y el navegador con `python -m playwright install chromium`. Asignar a E2E_PASSWORD la contraseña aleatoria de la demo y ejecutar `python scripts/verify_browser.py`. El script inicia un servidor local en 8001, usa datos ficticios de Comunidad Horizonte · Demo y crea registros de verificación. No utilizar sobre datos reales. Opcionalmente BROWSER_EXECUTABLE selecciona un Chromium ya instalado. Capturas locales en qa-output/, excluidas de git.

`check --deploy` también pasó sin incidencias con DEBUG=0, clave aleatoria larga y la política HTTPS habilitada para la verificación. La configuración efectiva del proxy y TLS sigue siendo responsabilidad de la instalación real.
