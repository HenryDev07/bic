-- 0011: gastos operativos. El importe vive solo en el asiento del libro de caja (1:1 por UNIQUE).
-- Los fletes/aranceles de una compra NO son gastos: van en purchase_cost_lines (se capitalizan al costo).
CREATE TABLE IF NOT EXISTS expense_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expense_category (business_id, name),
  FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  expense_category_id BIGINT UNSIGNED NOT NULL,
  financial_transaction_id BIGINT UNSIGNED NOT NULL,
  expense_date DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  product_id BIGINT UNSIGNED NULL,
  supplier_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expense_tx (financial_transaction_id),
  KEY idx_expenses_date (business_id, expense_date),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (expense_category_id) REFERENCES expense_categories (id),
  FOREIGN KEY (financial_transaction_id) REFERENCES financial_transactions (id),
  FOREIGN KEY (order_id) REFERENCES orders (id),
  FOREIGN KEY (product_id) REFERENCES products (id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
