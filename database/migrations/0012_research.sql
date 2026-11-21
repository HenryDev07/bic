-- 0012: investigación de productos y precios de mercado.
CREATE TABLE IF NOT EXISTS research_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  status ENUM('idea','evaluating','approved','discarded') NOT NULL DEFAULT 'idea',
  estimated_cost DECIMAL(14,2) NULL,
  target_price DECIMAL(14,2) NULL,
  product_id BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_research_status (business_id, status),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (product_id) REFERENCES products (id),
  FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Observaciones de precio (historial inmutable), moneda base.
CREATE TABLE IF NOT EXISTS research_prices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  research_item_id BIGINT UNSIGNED NOT NULL,
  source VARCHAR(150) NOT NULL,
  observed_price DECIMAL(14,2) NOT NULL,
  observed_on DATE NOT NULL,
  url VARCHAR(500) NULL,
  notes VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_research_prices_item (research_item_id, observed_on),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (research_item_id) REFERENCES research_items (id),
  FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
