<?php

declare(strict_types=1);

namespace Bic\Core\Auth;

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Clock;
use Bic\Core\Database\Database;
use Bic\Core\Session;
use Bic\Core\Settings\SettingsService;

final class AuthService
{
    /** Hash ficticio para igualar tiempos cuando el correo no existe. */
    private const DUMMY_HASH = '$2y$10$T9uMNz75atVL03bcLNA7nu3ULPfvzLKfbYssP8ilba92rbUMFWiou';

    public function __construct(
        private readonly Database $db,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{ok:bool,error:?string,user_id:?int}
     */
    public function attempt(Session $session, string $email, string $password, string $ip, string $userAgent): array
    {
        $email = mb_strtolower(trim($email));
        $user = $this->db->first(
            'SELECT id, business_id, password_hash, is_active FROM users WHERE email = ? AND deleted_at IS NULL',
            [$email]
        );
        // Los límites se leen del negocio del usuario (o del primero si el correo no existe).
        $businessId = (int) ($user['business_id'] ?? $this->db->value('SELECT MIN(id) FROM businesses'));

        if ($this->isLockedOut($businessId, $email, $ip)) {
            return ['ok' => false, 'error' => 'Demasiados intentos fallidos. Inténtalo de nuevo más tarde.', 'user_id' => null];
        }

        $valid = password_verify($password, (string) ($user['password_hash'] ?? self::DUMMY_HASH))
            && $user !== null && (int) $user['is_active'] === 1;
        $this->recordAttempt($email, $ip, $userAgent, $valid);

        if (!$valid) {
            if ($user !== null) {
                $this->audit->log($businessId, (int) $user['id'], 'auth.login_failed', 'user', (int) $user['id'], null, null, $ip, $userAgent);
            }

            return ['ok' => false, 'error' => 'Correo o contraseña incorrectos.', 'user_id' => null];
        }

        $userId = (int) $user['id'];
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->execute('UPDATE users SET password_hash = ? WHERE id = ?', [PasswordPolicy::hash($password), $userId]);
        }
        $this->db->execute('UPDATE users SET last_login_at = ? WHERE id = ?', [$this->clock->sql(), $userId]);
        $this->db->execute('DELETE FROM login_attempts WHERE email = ? AND success = 0', [$email]);

        $session->regenerate();
        $session->set('user_id', $userId);
        $session->set('last_activity', $this->clock->now()->getTimestamp());
        $this->audit->log($businessId, $userId, 'auth.login', 'user', $userId, null, null, $ip, $userAgent);

        return ['ok' => true, 'error' => null, 'user_id' => $userId];
    }

    public function logout(Session $session, ?array $user, string $ip, string $userAgent): void
    {
        if ($user !== null) {
            $this->audit->log((int) $user['business_id'], (int) $user['id'], 'auth.logout', 'user', (int) $user['id'], null, null, $ip, $userAgent);
        }
        $session->invalidate();
    }

    /** @return array<string,mixed>|null usuario activo y no eliminado */
    public function findActiveUser(int $id): ?array
    {
        return $this->db->first(
            'SELECT id, business_id, name, email, theme, last_login_at FROM users WHERE id = ? AND is_active = 1 AND deleted_at IS NULL',
            [$id]
        );
    }

    /** @return list<string> */
    public function permissionsFor(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT p.code FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = ? ORDER BY p.code',
            [$userId]
        );

        return array_map(static fn (array $r): string => (string) $r['code'], $rows);
    }

    /** @return string|null error, o null si se cambió la contraseña */
    public function changePassword(array $user, string $current, string $new, string $ip, string $userAgent): ?string
    {
        $row = $this->db->first('SELECT password_hash FROM users WHERE id = ?', [(int) $user['id']]);
        if ($row === null || !password_verify($current, (string) $row['password_hash'])) {
            return 'La contraseña actual no es correcta.';
        }
        $min = $this->settings->int((int) $user['business_id'], 'password_min_length');
        if (($error = PasswordPolicy::check($new, (string) $user['email'], $min)) !== null) {
            return $error;
        }
        $this->db->execute(
            'UPDATE users SET password_hash = ?, password_changed_at = ? WHERE id = ?',
            [PasswordPolicy::hash($new), $this->clock->sql(), (int) $user['id']]
        );
        $this->audit->log((int) $user['business_id'], (int) $user['id'], 'auth.password_change', 'user', (int) $user['id'], null, null, $ip, $userAgent);

        return null;
    }

    private function isLockedOut(int $businessId, string $email, string $ip): bool
    {
        $max = $this->settings->int($businessId, 'max_login_attempts');
        $since = $this->clock->now()->modify('-' . $this->settings->int($businessId, 'login_lockout_minutes') . ' minutes')->format('Y-m-d H:i:s');

        $byEmail = (int) $this->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND attempted_at >= ?',
            [$email, $since]
        );
        // El límite por IP es más amplio para no bloquear a toda una oficina por un solo usuario.
        $byIp = (int) $this->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at >= ?',
            [$ip, $since]
        );

        return $byEmail >= $max || $byIp >= $max * 4;
    }

    private function recordAttempt(string $email, string $ip, string $userAgent, bool $success): void
    {
        $this->db->execute(
            'INSERT INTO login_attempts (email, ip_address, success, user_agent, attempted_at) VALUES (?, ?, ?, ?, ?)',
            [substr($email, 0, 190), $ip, $success ? 1 : 0, substr($userAgent, 0, 255), $this->clock->sql()]
        );
    }
}
