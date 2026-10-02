-- Preparación aplicada al proyecto INTEGRA; también la realiza integra:database-prepare.
-- No crea tablas de aplicación: ejecuta las migraciones Laravel desde el servidor.
CREATE SCHEMA IF NOT EXISTS integra AUTHORIZATION postgres;
REVOKE ALL ON SCHEMA integra FROM PUBLIC, anon, authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA integra REVOKE ALL ON TABLES FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA integra REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
