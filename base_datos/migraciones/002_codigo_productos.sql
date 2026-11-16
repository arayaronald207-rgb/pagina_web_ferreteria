-- Ejecutar una sola vez, después de 001. No elimina productos existentes.
-- MySQL/MariaDB confirma DDL automáticamente: realizar respaldo antes de migrar.
ALTER TABLE productos ADD COLUMN codigo VARCHAR(64) NULL AFTER id_producto;
UPDATE productos SET codigo = CONCAT('LEGACY-', id_producto) WHERE codigo IS NULL;
ALTER TABLE productos
    MODIFY codigo VARCHAR(64) NOT NULL,
    ADD UNIQUE KEY uq_productos_codigo (codigo);
