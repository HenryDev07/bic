<?php

declare(strict_types=1);

namespace Bic\Core\Settings;

use Bic\Core\Database\Database;

/** Lectura y escritura validada de la configuración del negocio (tabla settings). */
final class SettingsService
{
    /** Configuración editable desde la interfaz (todas enteras). */
    public const DEFINITIONS = [
        'session_lifetime_minutes' => ['label' => 'Cierre de sesión por inactividad (minutos)', 'min' => 5, 'max' => 1440, 'default' => 120, 'group' => 'security'],
        'max_login_attempts'       => ['label' => 'Intentos de acceso fallidos antes de bloquear', 'min' => 3, 'max' => 20, 'default' => 5, 'group' => 'security'],
        'login_lockout_minutes'    => ['label' => 'Duración del bloqueo por intentos (minutos)', 'min' => 1, 'max' => 1440, 'default' => 15, 'group' => 'security'],
        'password_min_length'      => ['label' => 'Longitud mínima de contraseña', 'min' => 8, 'max' => 72, 'default' => 10, 'group' => 'security'],
        'insight_min_margin_pct'   => ['label' => 'Margen mínimo esperado en el análisis (%)', 'min' => 1, 'max' => 90, 'default' => 20, 'group' => 'insights'],
        'insight_slow_days'        => ['label' => 'Días sin ventas para considerar un producto estancado', 'min' => 7, 'max' => 365, 'default' => 60, 'group' => 'insights'],
    ];

    /** @var array<int,array<string,int>> */
    private array $cache = [];

    public function __construct(private readonly Database $db)
    {
    }

    public function int(int $businessId, string $key): int
    {
        return $this->all($businessId)[$key] ?? (int) (self::DEFINITIONS[$key]['default'] ?? 0);
    }

    /** @return array<string,int> */
    public function all(int $businessId): array
    {
        if (!isset($this->cache[$businessId])) {
            $values = [];
            foreach (self::DEFINITIONS as $key => $def) {
                $values[$key] = $def['default'];
            }
            $rows = $this->db->select('SELECT setting_key, setting_value FROM settings WHERE business_id = ?', [$businessId]);
            foreach ($rows as $row) {
                if (isset(self::DEFINITIONS[$row['setting_key']])) {
                    $values[(string) $row['setting_key']] = (int) $row['setting_value'];
                }
            }
            $this->cache[$businessId] = $values;
        }

        return $this->cache[$businessId];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{errors:array<string,string>,old:array<string,int>,new:array<string,int>}
     */
    public function update(int $businessId, array $input): array
    {
        $errors = [];
        $new = [];
        foreach (self::DEFINITIONS as $key => $def) {
            if (!array_key_exists($key, $input)) {
                continue; // ajuste no enviado: conserva su valor actual
            }
            $raw = $input[$key];
            $raw = is_string($raw) ? trim($raw) : $raw;
            if (!is_string($raw) || !preg_match('/^\d+$/', $raw) || (int) $raw < $def['min'] || (int) $raw > $def['max']) {
                $errors[$key] = "«{$def['label']}» debe estar entre {$def['min']} y {$def['max']}.";
                continue;
            }
            $new[$key] = (int) $raw;
        }
        $old = $this->all($businessId);
        if ($errors !== []) {
            return ['errors' => $errors, 'old' => $old, 'new' => []];
        }

        $this->db->transaction(function (Database $db) use ($businessId, $new): void {
            foreach ($new as $key => $value) {
                $exists = $db->value('SELECT id FROM settings WHERE business_id = ? AND setting_key = ?', [$businessId, $key]);
                if ($exists !== null) {
                    $db->execute('UPDATE settings SET setting_value = ? WHERE id = ?', [(string) $value, (int) $exists]);
                } else {
                    $db->execute(
                        'INSERT INTO settings (business_id, setting_key, setting_value, value_type, group_name) VALUES (?, ?, ?, ?, ?)',
                        [$businessId, $key, (string) $value, 'int', self::DEFINITIONS[$key]['group']]
                    );
                }
            }
        });
        unset($this->cache[$businessId]);

        return ['errors' => [], 'old' => $old, 'new' => $new];
    }
}
