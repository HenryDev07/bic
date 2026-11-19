<?php

declare(strict_types=1);

namespace Bic\Core\Security;

/** Autorización por permisos con formato modulo.accion, evaluada siempre en el servidor. */
final class Gate
{
    /** @param list<string> $permissions */
    public function __construct(private readonly array $permissions = [])
    {
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissions;
    }
}
