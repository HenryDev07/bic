-- Asignación de permisos por rol. Se ejecuta SIEMPRE al final (por eso el 999) para
-- cubrir también los permisos que añadan las etapas futuras. Idempotente.
-- Nota: los códigos de seller que aún no existen simplemente no se asignan hasta que existan.

-- Administrador: todo.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'admin';

-- Gerente: todo salvo usuarios, roles y edición de configuración.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'manager'
  AND p.module NOT IN ('users', 'roles')
  AND p.code <> 'settings.edit';

-- Vendedor: lista explícita (mínimo privilegio).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'seller'
  AND p.code IN (
    'dashboard.view', 'products.view', 'inventory.view',
    'customers.view', 'customers.create', 'customers.edit',
    'sales.view', 'sales.create', 'sales.edit', 'sales.pay'
  );

-- Analista: solo lectura (sin usuarios, roles, configuración ni auditoría) + IA y exportes.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'analyst'
  AND ((p.action = 'view' AND p.module NOT IN ('users', 'roles', 'settings', 'audit'))
       OR p.code IN ('ai.use', 'reports.export'));
