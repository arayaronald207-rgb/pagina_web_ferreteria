-- PREPARADA, NO APLICADA. Ejecutar una sola vez, con respaldo y revisión del esquema.
-- Ampliación de categorias (001); no depende de las tablas opcionales de 003.
-- DDL confirma cambios automáticamente: si se interrumpe, revisar antes de repetir.
-- Solo cambia categorías; no modifica ni elimina productos o relaciones.
ALTER TABLE categorias ADD COLUMN visible BOOLEAN NOT NULL DEFAULT 1;

UPDATE categorias SET visible = 0 WHERE LOWER(TRIM(nombre)) = 'sin clasificar';
