-- 0006: núcleo financiero. El saldo NO se guarda: = saldo inicial + asientos vigentes (sin duplicación).
-- Un asiento nunca se borra ni cambia de importe: se anula (voided_at) y queda el rastro.
-- Cada documento que mueve dinero referencia su asiento con UNIQUE (relación 1:1 garantizada por la base).

CREATE TABLE IF NOT EXISTS financial_accounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  type ENUM('cash','bank','wallet','other') NOT NULL DEFAULT 'cash',
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_accounts_name (business_id, name),
  FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- source_type (texto validado por la aplicación; las etapas futuras añaden valores sin alterar la tabla):
-- capital, transfer, other_income, adjustment, order_payment, purchase_payment, expense.
CREATE TABLE IF NOT EXISTS financial_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  direction ENUM('in','out') NOT NULL,
  amount DECIMAL(14,2) NOT NULL CHECK (amount > 0),
  occurred_at DATETIME NOT NULL,
  source_type VARCHAR(30) NOT NULL,
  description VARCHAR(255) NOT NULL,
  reference VARCHAR(100) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_at DATETIME NULL,
  voided_by BIGINT UNSIGNED NULL,
  void_reason VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_fin_tx_account (business_id, account_id, occurred_at),
  KEY idx_fin_tx_source (business_id, source_type),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (account_id) REFERENCES financial_accounts (id),
  FOREIGN KEY (created_by) REFERENCES users (id),
  FOREIGN KEY (voided_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El importe vive solo en el asiento; aquí solo el significado del movimiento.
CREATE TABLE IF NOT EXISTS capital_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  financial_transaction_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('contribution','withdrawal') NOT NULL,
  partner_name VARCHAR(150) NULL,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_capital_tx (financial_transaction_id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (financial_transaction_id) REFERENCES financial_transactions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Una transferencia = dos asientos (salida y entrada) en la misma moneda base.
CREATE TABLE IF NOT EXISTS account_transfers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  out_transaction_id BIGINT UNSIGNED NOT NULL,
  in_transaction_id BIGINT UNSIGNED NOT NULL,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transfer_out (out_transaction_id),
  UNIQUE KEY uq_transfer_in (in_transaction_id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (out_transaction_id) REFERENCES financial_transactions (id),
  FOREIGN KEY (in_transaction_id) REFERENCES financial_transactions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
