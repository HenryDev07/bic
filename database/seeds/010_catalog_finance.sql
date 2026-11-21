-- Permisos y datos iniciales de las etapas 4a/4b. Idempotente.
INSERT IGNORE INTO permissions (code, module, action, description) VALUES
  ('products.view',   'products',  'view',   'Ver productos y precios de venta'),
  ('products.cost',   'products',  'cost',   'Ver costos, márgenes y cotizaciones de proveedores'),
  ('products.create', 'products',  'create', 'Crear productos y variantes'),
  ('products.edit',   'products',  'edit',   'Editar productos, precios y variantes'),
  ('catalog.view',    'catalog',   'view',   'Ver categorías, marcas, unidades e impuestos'),
  ('catalog.manage',  'catalog',   'manage', 'Administrar categorías, marcas, unidades e impuestos'),
  ('suppliers.view',  'suppliers', 'view',   'Ver proveedores'),
  ('suppliers.create','suppliers', 'create', 'Crear proveedores'),
  ('suppliers.edit',  'suppliers', 'edit',   'Editar proveedores y registrar cotizaciones'),
  ('finance.view',    'finance',   'view',   'Ver cuentas y libro de caja'),
  ('finance.create',  'finance',   'create', 'Registrar movimientos, capital y transferencias'),
  ('finance.void',    'finance',   'void',   'Anular movimientos'),
  ('finance.manage',  'finance',   'manage', 'Administrar cuentas financieras');

INSERT IGNORE INTO units (business_id, code, name)
SELECT id, 'un', 'Unidad' FROM businesses;
INSERT IGNORE INTO units (business_id, code, name)
SELECT id, 'kg', 'Kilogramo' FROM businesses;
INSERT IGNORE INTO units (business_id, code, name)
SELECT id, 'l', 'Litro' FROM businesses;
INSERT IGNORE INTO units (business_id, code, name)
SELECT id, 'm', 'Metro' FROM businesses;
INSERT IGNORE INTO units (business_id, code, name)
SELECT id, 'caja', 'Caja' FROM businesses;

-- Única tasa inicial: Exento (0 %) como valor por defecto. Las demás las define el negocio según su país.
INSERT IGNORE INTO tax_rates (business_id, name, rate, is_default)
SELECT id, 'Exento', 0, 1 FROM businesses;
