-- 0008: compras. Compra = inventario (activo), no gasto. Los costos de llegada se capitalizan al costo de lo recibido.
-- Importes de líneas en la moneda de la orden; cost_lines y pagos en moneda base. Totales no se guardan (se calculan de las líneas).

CREATE TABLE IF NOT EXISTS purchase_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  po_number VARCHAR(30) NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  status ENUM('draft','ordered','partial','received','cancelled') NOT NULL DEFAULT 'draft',
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  exchange_rate DECIMAL(18,8) NOT NULL DEFAULT 1,
  ordered_on DATE NULL,
  expected_on DATE NULL,
  notes VARCHAR(500) NULL,
  cancel_reason VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_po_number (business_id, po_number),
  KEY idx_po_status (business_id, status),
  KEY idx_po_supplier (supplier_id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  FOREIGN KEY (warehouse_id) REFERENCES warehouses (id),
  FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Impuesto de la línea: instantánea (tasa, recuperable o no). El no recuperable se capitaliza al costo.
CREATE TABLE IF NOT EXISTS purchase_order_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL CHECK (quantity > 0),
  received_quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
  unit_price DECIMAL(14,4) NOT NULL,
  tax_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
  tax_recoverable TINYINT(1) NOT NULL DEFAULT 1,
  line_subtotal DECIMAL(14,2) NOT NULL,
  tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_po_line_variant (purchase_order_id, variant_id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id),
  FOREIGN KEY (variant_id) REFERENCES product_variants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fletes, aranceles, seguros… en moneda base. Se reparten entre las líneas por valor al recibir.
CREATE TABLE IF NOT EXISTS purchase_cost_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  description VARCHAR(150) NULL,
  amount DECIMAL(14,2) NOT NULL CHECK (amount > 0),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  received_on DATE NOT NULL,
  notes VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_receipts_po (purchase_order_id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id),
  FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- unit_cost = costo puesto en almacén (moneda base), congelado al recibir. Cada línea ↔ un movimiento de stock.
CREATE TABLE IF NOT EXISTS purchase_receipt_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  receipt_id BIGINT UNSIGNED NOT NULL,
  order_line_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL CHECK (quantity > 0),
  unit_cost DECIMAL(14,4) NOT NULL,
  stock_movement_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_receipt_line_movement (stock_movement_id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (receipt_id) REFERENCES purchase_receipts (id),
  FOREIGN KEY (order_line_id) REFERENCES purchase_order_lines (id),
  FOREIGN KEY (variant_id) REFERENCES product_variants (id),
  FOREIGN KEY (stock_movement_id) REFERENCES stock_movements (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El importe vive solo en el asiento del libro de caja (moneda base); aquí el vínculo 1:1.
CREATE TABLE IF NOT EXISTS purchase_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  financial_transaction_id BIGINT UNSIGNED NOT NULL,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_purchase_payment_tx (financial_transaction_id),
  KEY idx_purchase_payment_po (purchase_order_id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id),
  FOREIGN KEY (financial_transaction_id) REFERENCES financial_transactions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
