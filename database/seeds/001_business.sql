-- Negocio inicial y almacén por defecto. Idempotente.
INSERT INTO businesses (name, base_currency, timezone)
SELECT 'Mi Negocio', 'USD', 'America/El_Salvador' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM businesses);

INSERT IGNORE INTO warehouses (business_id, code, name, is_default)
SELECT MIN(id), 'PRINCIPAL', 'Almacén principal', 1 FROM businesses;
