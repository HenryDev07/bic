-- Configuración inicial (solo seguridad por ahora; cada etapa añade la suya). Idempotente.
INSERT IGNORE INTO settings (business_id, setting_key, setting_value, value_type, group_name)
SELECT b.id, s.k, s.v, s.t, s.g
FROM (SELECT MIN(id) AS id FROM businesses) b
CROSS JOIN (
  SELECT 'session_lifetime_minutes' AS k, '120' AS v, 'int' AS t, 'security' AS g
  UNION ALL SELECT 'max_login_attempts', '5', 'int', 'security'
  UNION ALL SELECT 'login_lockout_minutes', '15', 'int', 'security'
  UNION ALL SELECT 'password_min_length', '10', 'int', 'security'
) s;
