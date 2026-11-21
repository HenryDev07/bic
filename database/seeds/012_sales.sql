-- Permisos y configuración de las etapas 4e. Idempotente.
-- (sales.view/create/edit/pay ya los referencia el vendedor en 999_role_defaults.)
INSERT IGNORE INTO permissions (code, module, action, description) VALUES
  ('customers.view',   'customers', 'view',   'Ver clientes'),
  ('customers.create', 'customers', 'create', 'Crear clientes'),
  ('customers.edit',   'customers', 'edit',   'Editar clientes'),
  ('sales.view',   'sales', 'view',   'Ver pedidos y ventas'),
  ('sales.create', 'sales', 'create', 'Crear pedidos, editar líneas y avanzar hasta confirmado'),
  ('sales.edit',   'sales', 'edit',   'Preparar y entregar pedidos'),
  ('sales.pay',    'sales', 'pay',    'Registrar cobros y anticipos'),
  ('sales.refund', 'sales', 'refund', 'Registrar reembolsos y anular cobros'),
  ('sales.cancel', 'sales', 'cancel', 'Cancelar pedidos'),
  ('sales.return', 'sales', 'return', 'Registrar devoluciones');

-- La opción order_reserve_stock_on (pending|confirmed|never) se guarda en settings al cambiarla; por defecto: confirmed.
