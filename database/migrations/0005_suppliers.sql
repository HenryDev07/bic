-- 0005: proveedores y cotizaciones. Las cotizaciones son un historial inmutable (la vigente es la última).

CREATE TABLE IF NOT EXISTS suppliers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  tax_id VARCHAR(50) NULL,
  contact_name VARCHAR(150) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(50) NULL,
  country VARCHAR(80) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  payment_terms VARCHAR(120) NULL,
  lead_time_days INT UNSIGNED NULL,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_name (business_id, name),
  FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_suppliers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  supplier_sku VARCHAR(60) NULL,
  is_preferred TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_supplier (product_id, supplier_id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (product_id) REFERENCES products (id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Importes en la moneda de la cotización; exchange_rate = unidades de moneda base por 1 unidad de esa moneda.
CREATE TABLE IF NOT EXISTS supplier_prices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  product_supplier_id BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  exchange_rate DECIMAL(18,8) NOT NULL DEFAULT 1,
  unit_price DECIMAL(14,4) NOT NULL,
  freight_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
  other_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
  min_quantity DECIMAL(14,3) NOT NULL DEFAULT 1,
  quoted_on DATE NOT NULL,
  valid_until DATE NULL,
  notes VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_supplier_prices_link (product_supplier_id, quoted_on),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (product_supplier_id) REFERENCES product_suppliers (id),
  FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
