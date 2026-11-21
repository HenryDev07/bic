-- 0010: pedidos → ventas. Un pedido es venta (ingreso) solo cuando está entregado (delivered_at).
-- Ventas en moneda base. Los totales no se guardan: se calculan de las líneas (cada línea guarda su instantánea de impuesto).

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  order_number VARCHAR(30) NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  status ENUM('draft','pending','confirmed','prepared','delivered','cancelled') NOT NULL DEFAULT 'draft',
  notes VARCHAR(500) NULL,
  confirmed_at DATETIME NULL,
  delivered_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  cancel_reason VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_number (business_id, order_number),
  KEY idx_orders_status (business_id, status),
  KEY idx_orders_delivered (business_id, delivered_at),
  KEY idx_orders_customer (customer_id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (customer_id) REFERENCES customers (id),
  FOREIGN KEY (warehouse_id) REFERENCES warehouses (id),
  FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- unit_price = precio tal como se pactó (con o sin impuesto según price_includes_tax).
-- net_amount / tax_amount / line_total: importes de la línea, redondeados por línea (half-up).
-- unit_cost_snapshot: costo promedio al entregar (congela el CMV).
CREATE TABLE IF NOT EXISTS order_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL CHECK (quantity > 0),
  returned_quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
  unit_price DECIMAL(14,2) NOT NULL,
  price_includes_tax TINYINT(1) NOT NULL DEFAULT 0,
  tax_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
  net_amount DECIMAL(14,2) NOT NULL,
  tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(14,2) NOT NULL,
  unit_cost_snapshot DECIMAL(14,4) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_line_variant (order_id, variant_id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (order_id) REFERENCES orders (id),
  FOREIGN KEY (variant_id) REFERENCES product_variants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invariante: stock_levels.reserved_quantity = SUM(quantity de reservas activas) por almacén y variante.
CREATE TABLE IF NOT EXISTS stock_reservations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  order_line_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL CHECK (quantity > 0),
  status ENUM('active','released','consumed') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reservation_line (order_line_id),
  KEY idx_reservations_level (warehouse_id, variant_id, status),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (order_line_id) REFERENCES order_lines (id),
  FOREIGN KEY (warehouse_id) REFERENCES warehouses (id),
  FOREIGN KEY (variant_id) REFERENCES product_variants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NOT NULL,
  note VARCHAR(255) NULL,
  changed_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order_history (order_id, id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (order_id) REFERENCES orders (id),
  FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- kind: deposit (anticipo, antes de entregar), payment (cobro, ya entregado), refund (reembolso, sale dinero).
-- El importe vive solo en el asiento del libro de caja; aquí el vínculo 1:1.
CREATE TABLE IF NOT EXISTS order_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  financial_transaction_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('deposit','payment','refund') NOT NULL,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_payment_tx (financial_transaction_id),
  KEY idx_order_payment_order (order_id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (order_id) REFERENCES orders (id),
  FOREIGN KEY (financial_transaction_id) REFERENCES financial_transactions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Devoluciones de pedidos entregados (nota de crédito). restock = 1 repone stock al costo original.
CREATE TABLE IF NOT EXISTS order_returns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  returned_on DATE NOT NULL,
  reason VARCHAR(255) NOT NULL,
  restock TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_returns_order (order_id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (order_id) REFERENCES orders (id),
  FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_return_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  return_id BIGINT UNSIGNED NOT NULL,
  order_line_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL CHECK (quantity > 0),
  net_amount DECIMAL(14,2) NOT NULL,
  tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(14,2) NOT NULL,
  unit_cost DECIMAL(14,4) NULL,
  stock_movement_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_return_line_movement (stock_movement_id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (return_id) REFERENCES order_returns (id),
  FOREIGN KEY (order_line_id) REFERENCES order_lines (id),
  FOREIGN KEY (stock_movement_id) REFERENCES stock_movements (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
