# INTEGRA — Especificación de primera versión independiente

## Fuente y decisión

Contexto: requerimientos ENLACE v1.0, arquitectura inicial y evolución a flujos/questionarios configurables por congregación. El usuario pidió construir otro sistema, llamado INTEGRA, en dev-tonyorozco/integra, y eligió instalación independiente. Las restricciones de prototipo simulado de ENLACE no rigen esta nueva entrega; aquí se implementan persistencia y autenticación reales.

## Capacidades y aceptación

| Bloque | RF relacionados | Aceptación |
|---|---|---|
| Captura | 001–004 | Formulario sin cuenta, datos mínimos, consentimiento, folio, duplicado visible y responsable inicial |
| Flujo | 005–008, 014–021 | Definiciones versionadas, transiciones permitidas, responsable, decisión del líder y resultado auditado |
| Configuración | 009–013 | Ministerios, requisitos, capacidad, formularios/tests, puntajes orientativos y ficha resumida |
| Operación | 022–028 | Notas, entrevistas, adjuntos, tareas públicas seguras, SLA, avisos internos, pausa y escalamiento |
| Reportes | 029–036 | Embudo, cargas, SLA por fase, ciclo/mediana, integración, casos sin actividad, capacidad y CSV |
| Seguridad | 037–041 | Roles y aislamiento, campos sensibles, auditoría, archivos privados y archivo lógico |

La evolución funcional vigente de ENLACE prevalece sobre la secuencia universal del documento inicial: cada flujo usa sus propios estados. Líderes no tienen cuentas; el operador registra sus decisiones y mantiene la responsabilidad interna.

## Recorridos completos

1. Crear congregación/administrador, ingresar, agregar equipo y ministerios.
2. Construir y publicar flujo y cuestionario; publicar convocatoria, copiar enlace y descargar QR.
3. Aplicante completa formulario sin cuenta; recibe folio; asesor recibe aviso.
4. Operador revisa perfil/duplicados, agrega notas/hallazgos y realiza transiciones permitidas.
5. Generar tarea segura, responder públicamente, asignar prueba y registrar entrevista.
6. Documentar respuesta del líder. Integrar con función/fecha, reorientar con responsable o cerrar con comentario.
7. Consultar reporte, exportar CSV/ficha PDF, ver auditoría y respaldar instalación.
8. Crear nueva versión y comprobar que solicitudes anteriores mantienen su definición.

## Decisiones acotadas de la v1

- Una instalación con datos de múltiples congregaciones aislados.
- Formulario y contactos públicos; sin portal de cuenta del aplicante.
- Líder fuera del sistema; comunicación externa manual y decisión documentada por equipo.
- Puntaje de pruebas configurable y orientativo. No inventar una fórmula teológica de dones ni una regla de aceptación/compatibilidad automática. La ficha y las respuestas soportan la orientación humana.
- Duplicados enlazados y revisados manualmente, sin fusión automática.
- Estados con horas o días hábiles sin calendario de festivos.
- Respaldos globales; retención y horarios programados por operador del servidor.
- Adjuntos PDF/JPG/PNG de 5 MB sin antivirus.
- SMTP y servidor HTTPS se configuran en la instalación, no se provisionan en esta entrega.

## Fuera de alcance

Membresía completa, nómina, app nativa, aceptación automática, ramas paralelas de flujo, migración de casos entre versiones, escáner antivirus, SMTP administrado y escalado horizontal. RF-010 no incluye un algoritmo de compatibilidad no definido por el negocio; se resuelve con perfil y recomendaciones humanas documentadas.
