CREATE DATABASE IF NOT EXISTS restaurante_argentina
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE restaurante_argentina;

CREATE TABLE IF NOT EXISTS productos (
  id VARCHAR(60) PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  categoria ENUM('comida', 'bebida') NOT NULL,
  precio DECIMAL(10, 2) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS pedidos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NOT NULL UNIQUE,
  nombre_cliente VARCHAR(120) NOT NULL,
  correo_cliente VARCHAR(160) NOT NULL,
  total DECIMAL(10, 2) NOT NULL,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS detalle_pedido (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pedido_id INT NOT NULL,
  producto_id VARCHAR(60) NOT NULL,
  nombre_producto VARCHAR(120) NOT NULL,
  precio_unitario DECIMAL(10, 2) NOT NULL,
  cantidad INT NOT NULL,
  subtotal DECIMAL(10, 2) NOT NULL,
  CONSTRAINT fk_detalle_pedido
    FOREIGN KEY (pedido_id) REFERENCES pedidos(id)
    ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS pagos_simulados (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pedido_id INT NOT NULL,
  tipo_tarjeta ENUM('visa', 'mastercard', 'amex') NOT NULL,
  ultimos_4 VARCHAR(4) NOT NULL,
  vencimiento VARCHAR(5) NOT NULL,
  estado VARCHAR(40) NOT NULL DEFAULT 'aprobado_simulado',
  total DECIMAL(10, 2) NOT NULL,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pago_pedido
    FOREIGN KEY (pedido_id) REFERENCES pedidos(id)
    ON DELETE CASCADE
);

INSERT INTO productos (id, nombre, categoria, precio, activo) VALUES
('asado', 'Asado argentino', 'comida', 8500.00, 1),
('empanadas', 'Empanadas argentinas', 'comida', 4200.00, 1),
('milanesa', 'Milanesa argentina', 'comida', 6900.00, 1),
('dulce-de-leche', 'Postre de dulce de leche', 'comida', 3200.00, 1),
('choripan', 'Choripan argentino', 'comida', 4800.00, 1),
('pizza-argentina', 'Pizza argentina', 'comida', 6100.00, 1),
('provoleta', 'Provoleta argentina', 'comida', 5400.00, 1),
('alfajor-maicena', 'Alfajor de maicena', 'comida', 2800.00, 1),
('mate', 'Mate argentino', 'bebida', 1800.00, 1),
('fernet-con-cola', 'Fernet con cola', 'bebida', 2900.00, 1),
('malbec', 'Vino Malbec', 'bebida', 4500.00, 1),
('submarino', 'Submarino argentino', 'bebida', 2600.00, 1),
('clerico', 'Clerico', 'bebida', 3200.00, 1),
('cafe-con-leche', 'Cafe con leche', 'bebida', 2100.00, 1),
('gancia-batido', 'Gancia batido', 'bebida', 3400.00, 1),
('vino-torrontes', 'Vino Torrontes', 'bebida', 4300.00, 1)
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre),
  categoria = VALUES(categoria),
  precio = VALUES(precio),
  activo = VALUES(activo);


SELECT 
  p.codigo,
  p.nombre_cliente,
  p.correo_cliente,
  dp.nombre_producto,
  dp.precio_unitario,
  dp.cantidad,
  dp.subtotal,
  ps.tipo_tarjeta,
  ps.ultimos_4,
  ps.estado,
  p.total,
  p.fecha
FROM pedidos p
INNER JOIN detalle_pedido dp ON dp.pedido_id = p.id
INNER JOIN pagos_simulados ps ON ps.pedido_id = p.id
ORDER BY p.id DESC, dp.id ASC;
