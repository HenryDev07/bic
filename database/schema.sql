-- ============================================================
-- BIC: esquema consolidado. GENERADO por `php bin/schema.php build`.
-- NO EDITAR: la fuente de verdad son database/migrations/*.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS `business_intelligence_commerce` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `business_intelligence_commerce`;

CREATE TABLE IF NOT EXISTS schema_migrations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  version VARCHAR(100) NOT NULL,
  checksum CHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_schema_migrations_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 0001_core_business =====
-- 0001: negocio, almacenes y configuración.
-- Convenciones del esquema:
--   * Fechas en UTC (la aplicación convierte a la zona horaria del negocio al mostrar).
--   * Dinero: DECIMAL(14,2); costos unitarios: DECIMAL(14,4); cantidades: DECIMAL(14,3); tasas: DECIMAL(7,4).
--   * Toda tabla de negocio lleva business_id (multi-negocio preparado; hoy hay uno solo).

CREATE TABLE IF NOT EXISTS businesses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  legal_name VARCHAR(200) NULL,
  tax_id VARCHAR(50) NULL,
  base_currency CHAR(3) NOT NULL DEFAULT 'USD',
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/El_Salvador',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warehouses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(120) NOT NULL,
  address VARCHAR(255) NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_warehouses_code (business_id, code),
  FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Solo configuración (no datos de negocio).
CREATE TABLE IF NOT EXISTS settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  setting_key VARCHAR(100) NOT NULL,
  setting_value TEXT NULL,
  value_type ENUM('string','int','decimal','bool','json') NOT NULL DEFAULT 'string',
  group_name VARCHAR(50) NOT NULL DEFAULT 'general',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings_key (business_id, setting_key),
  FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 0002_security =====
-- 0002: usuarios, roles y permisos.
-- Roles y permisos son globales (catálogo de la aplicación); los usuarios pertenecen a un negocio.

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  theme ENUM('light','dark') NOT NULL DEFAULT 'light',
  last_login_at DATETIME NULL,
  password_changed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- code con formato modulo.accion (ej.: sales.cancel)
CREATE TABLE IF NOT EXISTS permissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(100) NOT NULL,
  module VARCHAR(50) NOT NULL,
  action VARCHAR(50) NOT NULL,
  description VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code),
  KEY idx_permissions_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  user_agent VARCHAR(255) NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_attempts_email (email, attempted_at),
  KEY idx_login_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 0003_audit_idempotency =====
-- 0003: auditoría y control de envíos duplicados.

-- Bitácora inmutable: bic_app solo tiene SELECT e INSERT sobre esta tabla (ver Provisioner).
-- entity_id es una referencia débil a propósito: apunta a cualquier tabla según entity_type.
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity_type VARCHAR(60) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  old_values JSON NULL,
  new_values JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_business_date (business_id, created_at),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_user_date (user_id, created_at),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada formulario crítico envía un token único; el índice UNIQUE rechaza el segundo envío.
-- result_id guarda el registro creado la primera vez (referencia débil) para poder redirigir.
CREATE TABLE IF NOT EXISTS idempotency_keys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  scope VARCHAR(50) NOT NULL,
  token CHAR(64) NOT NULL,
  result_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_idempotency_token (business_id, scope, token),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 0004_catalog =====
-- 0004: catálogo (impuestos, unidades, categorías, marcas, productos, variantes, historial de precios).
-- Tasas de impuesto en porcentaje (16.0000 = 16 %). No existe tasa universal: cada producto referencia una.

CREATE TABLE IF NOT EXISTS tax_rates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  rate DECIMAL(7,4) NOT NULL DEFAULT 0,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tax_rates_name (business_id, name),
  FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS units (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(10) NOT NULL,
  name VARCHAR(60) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_units_code (business_id, code),
  FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (business_id, name),
  FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brands (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_brands_name (business_id, name),
  FOREIGN KEY (business_id) REFERENCES businesses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  brand_id BIGINT UNSIGNED NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  tax_rate_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  description TEXT NULL,
  price_includes_tax TINYINT(1) NOT NULL DEFAULT 0,
  sale_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  promo_price DECIMAL(14,2) NULL,
  min_stock DECIMAL(14,3) NOT NULL DEFAULT 0,
  status ENUM('active','inactive','discontinued') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_products_status (business_id, status),
  KEY idx_products_name (business_id, name),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (category_id) REFERENCES categories (id),
  FOREIGN KEY (brand_id) REFERENCES brands (id),
  FOREIGN KEY (unit_id) REFERENCES units (id),
  FOREIGN KEY (tax_rate_id) REFERENCES tax_rates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Todo producto tiene al menos una variante (la predeterminada). El stock y las compras se llevan por variante.
CREATE TABLE IF NOT EXISTS product_variants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(60) NOT NULL,
  barcode VARCHAR(60) NULL,
  name VARCHAR(120) NULL,
  reference_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_variants_sku (business_id, sku),
  UNIQUE KEY uq_variants_barcode (business_id, barcode),
  KEY idx_variants_product (product_id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS variant_attributes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  attr_name VARCHAR(60) NOT NULL,
  attr_value VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_variant_attr (variant_id, attr_name),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (variant_id) REFERENCES product_variants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial inmutable de cambios de precio de venta, promoción y costo de referencia.
CREATE TABLE IF NOT EXISTS product_price_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NULL,
  kind ENUM('sale_price','promo_price','reference_cost') NOT NULL,
  old_price DECIMAL(14,4) NULL,
  new_price DECIMAL(14,4) NULL,
  changed_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_price_history_product (product_id, created_at),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (product_id) REFERENCES products (id),
  FOREIGN KEY (variant_id) REFERENCES product_variants (id),
  FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 0005_suppliers =====
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

-- ===== 0006_finance_core =====
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

-- ===== 0007_inventory =====
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

-- ===== 0008_purchasing =====
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

INSERT IGNORE INTO schema_migrations (version, checksum) VALUES
  ('0001_core_business', '46e44138dac300ec8801b840b8f1b6808486a79537167727c2f6be558acf47e4'),
  ('0002_security', '478534742a74e5c80c19c1b829ea228e2789f849ac5bfeaaf24ab754dc670497'),
  ('0003_audit_idempotency', '655cd8e3bb90eb04a7a9e88a5c72e04060a373fe4ade2f7ba0a39ddbb3add03a'),
  ('0004_catalog', '3f03cd1084c92d8032f28c059745076ab0e29ecc87664e38c680a126a902d8f4'),
  ('0005_suppliers', '901e7120d9edb8f89f9576aa142ebaaad4b68d4bb1b026f24573bba897fdebc8'),
  ('0006_finance_core', '111c48bc66cb33b9e06ea0afbb28bb8e5c340ea020d25d3dbd3da6796b84a0a0'),
  ('0007_inventory', '5639c6d7698e26df942ec4c4cf30fb7a2d31d09999c4c48e37b0185c12bf6650'),
  ('0008_purchasing', 'b42df7914e35d990e3666fa23ebc0d9d8f7b1f184d13be9e35d2be2ade2484e2');
