-- Permisos de la etapa 5. Idempotente. (El analista recibe view + reports.export por 999_role_defaults.)
INSERT IGNORE INTO permissions (code, module, action, description) VALUES
  ('reports.view',   'reports',  'view',   'Ver reportes'),
  ('reports.export', 'reports',  'export', 'Exportar reportes a CSV, Excel y PDF'),
  ('insights.view',  'insights', 'view',   'Ver análisis e inteligencia comercial');
