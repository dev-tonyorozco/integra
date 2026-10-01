# Arquitectura de INTEGRA

## Decisión de instalación

El 1 de octubre de 2026 el usuario eligió instalación independiente. INTEGRA hereda el dominio vigente de ENLACE, pero usa Django 5.2 LTS y SQLite en lugar de Next.js/Supabase/Vercel. Es un monolito con renderizado en servidor: templates y formularios para presentación; services para reglas del flujo; modelos ORM para persistencia; operations y comandos para tareas del operador. No depende de una plataforma de nube específica.

## Aislamiento y autorización

Membership vincula un usuario activo con una congregación activa y un rol. El contexto autorizado proviene de la sesión y se valida en cada vista. Toda búsqueda de un identificador específico añade congregation; un ID ajeno produce 404. Los servicios validan el alcance de los objetos nuevamente. El selector solo muestra membresías autorizadas.

| Rol | Alcance |
|---|---|
| Administrador | Operación, configuración, usuarios, nueva congregación y respaldos |
| Coordinación | Operación, catálogos, usuarios, reasignación, pausa y auditoría |
| Asesor | Lectura y operación de solicitudes; notas, entrevistas, transición y pruebas |
| Consulta | Lectura; sin campos sensibles ni notas privadas; sin mutaciones |
| Técnico | Auditoría sin respuestas personales y creación de respaldo; sin solicitudes |

Líderes y contactos ministeriales no ingresan en esta versión. Un operador registra su respuesta y asume el seguimiento. Integración exige decisión documentada del líder, función y fecha. Reorientación exige responsable y no termina el caso.

## Flujo configurable

Workflow almacena estados, transiciones, roles, plazo y resultado como definición declarativa validada; no ejecuta código. Las definiciones publicadas son inmutables. Crear una nueva versión genera otra fila. Application conserva workflow y questionnaire específicos, incluso si se publica otra versión o cambia la convocatoria.

Cada solicitud tiene un único estado activo. Las ramas paralelas y migraciones de solicitudes entre versiones no se implementan. La transición bloquea/actualiza la solicitud dentro de una transacción y exige revision para evitar escritura sobre una pantalla desactualizada. El cambio, el Event y el Audit se guardan juntos. SQLite configura BEGIN IMMEDIATE para serializar escrituras. Los estados terminales no admiten salidas.

Todo caso activo conserva responsable, próxima acción y fecha límite. El plazo es configurable por estado; business_days interpreta bloques de 24 horas como días completos y excluye fines de semana. No incluye calendario de días festivos. La pausa conserva el plazo anterior en Event y añade al plazo la duración de la pausa al reanudar. Estar vencido nunca cierra una solicitud.

## Identidad, privacidad y archivos

Django usa hash de contraseña, CSRF, sesiones de servidor, cookies HttpOnly/SameSite y cookies Secure en producción. Diez intentos de login fallidos por IP en 15 minutos generan bloqueo temporal; el proxy debe preservar la IP de cliente de manera confiable si se quiere granularidad por usuario final. No confiar arbitrariamente en X-Forwarded-For.

El formulario público no requiere cuenta; genera folio y registra consentimiento. Los duplicados se detectan dentro de la congregación por correo; los casos se enlazan y se revisan manualmente. No se fusionan ni eliminan registros automáticamente. Un token aleatorio UUID da acceso a una tarea puntual, expira a los diez días por defecto y solo puede responderse una vez. No da acceso al expediente.

Los campos marcados sensibles no se imprimen en la ficha PDF ni aparecen en consulta. Las notas privadas se excluyen de consulta. PDF siempre escapa el contenido antes de renderizar. CSV protege contra fórmulas de hoja de cálculo. Los archivos se almacenan fuera de /static y se descargan por una ruta autenticada con alcance de congregación. Se restringen a 5 MB, PDF/JPG/PNG; imágenes se verifican y vuelven a codificar. PDFs se descargan, no se incrustan.

No se incluye antivirus. El operador define retención de datos y revisa el aviso de privacidad antes de usar información real. Bases, copias, archivos, secretos y datos de demo generados no se incluyen en git.

## Operación

Un volumen local persistente, un servicio web con Gunicorn de un worker y cuatro threads. Esta configuración no se diseña para replicas con SQLite compartida por red. Respaldos usan la API consistente sqlite backup y un ZIP privado con todos los archivos. Son copias de la instalación completa; no paquetes por congregación. Solo el operador del servidor puede acceder a sus bytes; la UI muestra estado y nombre.

Avisos y escalamiento son internos. sla_reminders se ejecuta por programación del servidor y es idempotente por caso, plazo y tipo. SMTP se utiliza para recuperación de contraseña. No se envían correos automáticamente al crear casos ni al ejecutar pruebas.
