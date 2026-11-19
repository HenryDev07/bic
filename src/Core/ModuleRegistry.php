<?php

declare(strict_types=1);

namespace Bic\Core;

use Bic\Core\Http\Router;
use Bic\Core\Security\Gate;

/**
 * Descubre src/Modules/<Nombre>/module.php. Agregar un módulo = crear su carpeta;
 * sus rutas y su menú se registran solos. Manifiesto:
 *   key, name, routes: callable(Router), menu: [ [group,label,path,icon,permission,order] ]
 */
final class ModuleRegistry
{
    /** @var array<string,array<string,mixed>> */
    private array $modules = [];

    public function __construct(string $modulesDir)
    {
        foreach (glob(rtrim($modulesDir, '/\\') . '/*/module.php') ?: [] as $file) {
            $manifest = require $file;
            $this->modules[(string) $manifest['key']] = $manifest;
        }
        ksort($this->modules);
    }

    public function registerRoutes(Router $router): void
    {
        foreach ($this->modules as $module) {
            ($module['routes'])($router);
        }
    }

    /** @return array<string,string> clave de módulo => nombre visible */
    public function names(): array
    {
        return array_map(static fn (array $m): string => (string) $m['name'], $this->modules);
    }

    /**
     * Menú filtrado por permisos, agrupado y ordenado.
     *
     * @return list<array{label:string,items:list<array<string,mixed>>}>
     */
    public function menu(Gate $gate): array
    {
        $items = [];
        foreach ($this->modules as $module) {
            foreach ($module['menu'] ?? [] as $item) {
                if ($gate->can((string) $item['permission'])) {
                    $items[] = $item;
                }
            }
        }
        usort($items, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $groups = [];
        foreach ($items as $item) {
            $groups[$item['group']][] = $item;
        }

        $result = [];
        foreach ($groups as $label => $list) {
            $result[] = ['label' => (string) $label, 'items' => $list];
        }

        return $result;
    }
}
