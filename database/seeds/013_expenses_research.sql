-- Permisos de la etapa 4f. Idempotente.
INSERT IGNORE INTO permissions (code, module, action, description) VALUES
  ('expenses.view',   'expenses', 'view',   'Ver gastos y el estado de resultados'),
  ('expenses.create', 'expenses', 'create', 'Registrar gastos y categorías'),
  ('expenses.void',   'expenses', 'void',   'Anular gastos'),
  ('research.view',   'research', 'view',   'Ver investigación de productos'),
  ('research.manage', 'research', 'manage', 'Crear y editar investigación y precios observados');
