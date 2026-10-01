# INTEGRA

Aplicación independiente de gestión de solicitudes de servicio. Stack vigente: Django 5.2 LTS, Python 3.12, SQLite y Docker; decisión explícita del usuario del 1 de octubre de 2026. No asumir Next.js, Vercel o Supabase por referencias a ENLACE.

- Trabajar en rama; no hacer merge a main sin instrucción.
- Un agente; implementar y verificar capacidades completas secuencialmente.
- Consultar specs/001-integra y docs/ARCHITECTURE.md antes de cambiar reglas.
- Proceso configurable, versionado e inmutable después de publicarse.
- Congregación desde Membership validada, nunca desde un ID del cliente sin autorización.
- Líderes/contactos no tienen acceso. El equipo registra su decisión. INTEGRA no acepta automáticamente.
- Datos reales, secretos y bases locales fuera de git.
- Preservar trazabilidad: no borrar físicamente solicitudes ni editar eventos.
- Autorización y validación en servidor; transiciones y auditoría en transacción.
- Interfaz y documentos en español; código en inglés.
- Ejecutar check, makemigrations --check --dry-run y test core antes de cerrar; navegador para cambios visuales.
- No restaurar ni ejecutar migraciones destructivas sobre instalaciones reales sin autorización.
