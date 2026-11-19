<?php

declare(strict_types=1);

namespace Bic\Core\Audit;

use Bic\Core\Clock;
use Bic\Core\Database\Database;

/** Escribe en audit_logs (tabla solo-inserción). Nunca registra contraseñas ni tokens. */
final class AuditLogger
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /**
     * @param array<string,mixed>|null $old
     * @param array<string,mixed>|null $new
     */
    public function log(
        int $businessId,
        ?int $userId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $old = null,
        ?array $new = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        $this->db->execute(
            'INSERT INTO audit_logs (business_id, user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $businessId, $userId, $action, $entityType, $entityId,
                $old === null ? null : $this->encode($old),
                $new === null ? null : $this->encode($new),
                $ip, $userAgent !== null ? substr($userAgent, 0, 255) : null, $this->clock->sql(),
            ]
        );
    }

    /** @param array<string,mixed> $data */
    private function encode(array $data): string
    {
        return (string) json_encode($this->redact($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<mixed> $data */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/pass|token|secret|hash/i', $key)) {
                $data[$key] = '[oculto]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
