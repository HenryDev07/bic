-- Permiso del asistente de IA (etapa 6). Idempotente. El analista lo recibe por 999_role_defaults.
INSERT IGNORE INTO permissions (code, module, action, description) VALUES
  ('ai.use', 'ai', 'use', 'Usar el asistente de IA (consultas de solo lectura)');
