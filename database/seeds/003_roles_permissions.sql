-- Roles del sistema y permisos de los módulos que ya existen. Idempotente.
-- Cada etapa añade sus propios permisos en un seed nuevo (010_..., 020_...).
INSERT IGNORE INTO roles (code, name, description, is_system) VALUES
  ('admin',   'Administrador', 'Acceso total y gestión de usuarios y permisos', 1),
  ('manager', 'Gerente',       'Operación y finanzas; sin gestión de usuarios ni roles', 1),
  ('seller',  'Vendedor',      'Ventas, clientes y consulta de catálogo', 1),
  ('analyst', 'Analista',      'Solo lectura, inteligencia comercial y reportes', 1);

INSERT IGNORE INTO permissions (code, module, action, description) VALUES
  ('dashboard.view', 'dashboard', 'view',   'Ver el dashboard'),
  ('users.view',     'users',     'view',   'Ver usuarios'),
  ('users.create',   'users',     'create', 'Crear usuarios'),
  ('users.edit',     'users',     'edit',   'Editar usuarios'),
  ('users.delete',   'users',     'delete', 'Desactivar usuarios'),
  ('roles.view',     'roles',     'view',   'Ver roles y permisos'),
  ('roles.edit',     'roles',     'edit',   'Modificar permisos de los roles'),
  ('settings.view',  'settings',  'view',   'Ver configuración'),
  ('settings.edit',  'settings',  'edit',   'Modificar configuración'),
  ('audit.view',     'audit',     'view',   'Ver la bitácora de auditoría');
