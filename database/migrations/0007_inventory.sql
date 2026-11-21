-- 0007: inventario. El stock físico SOLO cambia mediante stock_movements (inmutable, solo INSERT).
-- stock_levels es el saldo vigente por almacén y variante; disponible = quantity − reserved_quantity (se calcula, no se guarda).
-- reserved_quantity la usarán los pedidos (etapa 4e); mientras tanto vale 0.

CREATE TABLE IF NOT EXISTS stock_levels (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL DEFAULT 0 CHECK (quantity >= 0),
  reserved_quantity DECIMAL(14,3) NOT NULL DEFAULT 0 CHECK (reserved_quantity >= 0),
  avg_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stock_levels (warehouse_id, variant_id),
  KEY idx_stock_levels_variant (variant_id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (warehouse_id) REFERENCES warehouses (id),
  FOREIGN KEY (variant_id) REFERENCES product_variants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- quantity con signo (+entra, −sale). unit_cost = costo por unidad en moneda base al momento del movimiento.
-- movement_type (validado por la aplicación): purchase_receipt, adjustment_in, adjustment_out, transfer_in, transfer_out
-- (las etapas siguientes añaden sale, return…).
CREATE TABLE IF NOT EXISTS stock_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  movement_type VARCHAR(30) NOT NULL,
  quantity DECIMAL(14,3) NOT NULL CHECK (quantity <> 0),
  unit_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
  balance_after DECIMAL(14,3) NOT NULL,
  avg_cost_after DECIMAL(14,4) NOT NULL DEFAULT 0,
  reference_type VARCHAR(30) NULL,
  reference_id BIGINT UNSIGNED NULL,
  reason VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_stock_mov_variant (business_id, variant_id, id),
  KEY idx_stock_mov_warehouse (business_id, warehouse_id, id),
  KEY idx_stock_mov_ref (reference_type, reference_id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (warehouse_id) REFERENCES warehouses (id),
  FOREIGN KEY (variant_id) REFERENCES product_variants (id),
  FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
