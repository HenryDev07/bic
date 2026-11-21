-- Permisos de las etapas 4c/4d. Idempotente.
INSERT IGNORE INTO permissions (code, module, action, description) VALUES
  ('inventory.view',    'inventory', 'view',    'Ver existencias y movimientos'),
  ('inventory.adjust',  'inventory', 'adjust',  'Registrar ajustes y transferencias de stock'),
  ('purchases.view',    'purchases', 'view',    'Ver órdenes de compra'),
  ('purchases.create',  'purchases', 'create',  'Crear órdenes de compra y editar sus líneas'),
  ('purchases.receive', 'purchases', 'receive', 'Registrar recepciones de mercancía'),
  ('purchases.pay',     'purchases', 'pay',     'Registrar y anular pagos a proveedores'),
  ('purchases.cancel',  'purchases', 'cancel',  'Cancelar órdenes de compra');
