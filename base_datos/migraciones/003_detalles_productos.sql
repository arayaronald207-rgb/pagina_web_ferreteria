-- PREPARADA, NO APLICADA. Ejecutar una sola vez después de 002 y con respaldo.
-- DDL confirma cambios automáticamente. Si se interrumpe, revisar el esquema.
-- Conserva productos, fotografías principales y relaciones históricas.
ALTER TABLE productos ADD COLUMN especificaciones TEXT NULL;

CREATE TABLE producto_imagenes (
    id_imagen INT NOT NULL AUTO_INCREMENT,
    id_producto INT NOT NULL,
    imagen VARCHAR(255) NOT NULL,
    PRIMARY KEY (id_imagen),
    KEY idx_producto_imagenes_producto (id_producto),
    CONSTRAINT fk_producto_imagenes_producto
        FOREIGN KEY (id_producto) REFERENCES productos (id_producto) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
