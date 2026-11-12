CREATE TABLE usuarios (
    id_usuario INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL,
    correo VARCHAR(150) NOT NULL,
    clave_hash VARCHAR(255) NOT NULL,
    rol ENUM('administrador', 'empleado') NOT NULL,
    activo BOOLEAN NOT NULL,
    PRIMARY KEY (id_usuario),
    UNIQUE KEY uq_usuarios_correo (correo)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categorias (
    id_categoria INT NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL,
    PRIMARY KEY (id_categoria),
    UNIQUE KEY uq_categorias_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE productos (
    id_producto INT NOT NULL AUTO_INCREMENT,
    id_categoria INT NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT NULL,
    imagen VARCHAR(255) NULL,
    visible BOOLEAN NOT NULL,
    PRIMARY KEY (id_producto),
    CONSTRAINT fk_productos_categorias
        FOREIGN KEY (id_categoria) REFERENCES categorias (id_categoria)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE solicitudes (
    id_solicitud INT NOT NULL AUTO_INCREMENT,
    id_usuario_asignado INT NULL,
    cliente_nombre VARCHAR(120) NOT NULL,
    cliente_whatsapp VARCHAR(25) NOT NULL,
    cliente_correo VARCHAR(150) NOT NULL,
    observaciones TEXT NULL,
    estado ENUM('Pendiente', 'En proceso', 'Atendida', 'Cancelada') NOT NULL DEFAULT 'Pendiente',
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_solicitud),
    CONSTRAINT fk_solicitudes_usuarios
        FOREIGN KEY (id_usuario_asignado) REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE detalle_solicitud (
    id_detalle INT NOT NULL AUTO_INCREMENT,
    id_solicitud INT NOT NULL,
    id_producto INT NOT NULL,
    producto_nombre VARCHAR(150) NOT NULL,
    cantidad INT NOT NULL,
    PRIMARY KEY (id_detalle),
    CONSTRAINT chk_detalle_solicitud_cantidad CHECK (cantidad > 0),
    CONSTRAINT fk_detalle_solicitud_solicitudes
        FOREIGN KEY (id_solicitud) REFERENCES solicitudes (id_solicitud),
    CONSTRAINT fk_detalle_solicitud_productos
        FOREIGN KEY (id_producto) REFERENCES productos (id_producto)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
