<?php

declare(strict_types=1);

namespace Bic\Modules\Ai\Provider;

/** Modo sin IA externa: enruta por palabras clave a las mismas herramientas de solo lectura y presenta el resultado tal cual. */
final class LocalProvider implements AiProvider
{
    private const ROUTES = [
        'profit_and_loss' => ['ganancia', 'utilidad', 'resultado', 'rentab', 'gasto'],
        'reorder_suggestions' => ['reponer', 'reposicion', 'reposición', 'stock bajo', 'agot', 'comprar'],
        'slow_movers' => ['estancad', 'sin venta', 'sin rotaci', 'parado'],
        'inventory_value' => ['valor del inventario', 'inventario', 'valoraci'],
        'receivables' => ['cobrar', 'deben', 'deuda'],
        'top_customers' => ['cliente'],
        'top_products' => ['producto', 'más vendido', 'mas vendido'],
        'sales_summary' => ['venta', 'ingreso', 'factur', 'vend'],
    ];

    public function name(): string
    {
        return 'local';
    }

    public function complete(string $system, array $messages, array $tools): array
    {
        $last = $messages[count($messages) - 1] ?? ['content' => ''];
        $usage = ['in' => 0, 'out' => 0];
        if (is_array($last['content'])) { // resultado de herramienta → presentarlo
            $parts = [];
            foreach ($last['content'] as $b) {
                if (($b['type'] ?? '') === 'tool_result') {
                    $data = json_decode((string) $b['content'], true);
                    $parts[] = is_array($data) ? self::present($data) : (string) $b['content'];
                }
            }

            return ['text' => "Modo local (sin IA externa). Datos reales de tu negocio:\n\n" . implode("\n\n", $parts), 'tool_calls' => [], 'usage' => $usage];
        }
        $q = mb_strtolower((string) $last['content']);
        $available = array_column($tools, 'name');
        foreach (self::ROUTES as $tool => $words) {
            if (!in_array($tool, $available, true)) {
                continue;
            }
            foreach ($words as $w) {
                if (str_contains($q, $w)) {
                    $days = str_contains($q, 'hoy') ? 7 : (str_contains($q, 'semana') ? 7 : (str_contains($q, 'trimestre') ? 90 : (str_contains($q, 'año') || str_contains($q, 'anual') ? 365 : 30)));

                    return ['text' => '', 'tool_calls' => [['id' => 'local-1', 'name' => $tool, 'input' => ['days' => $days]]], 'usage' => $usage];
                }
            }
        }
        $names = $available === [] ? 'ninguna (tu rol no tiene herramientas)' : implode(', ', $available);

        return ['text' => "Modo local: puedo responder sobre ventas, ganancias y gastos, productos más vendidos, reposición, estancados, cobros, clientes e inventario (según tus permisos). Herramientas disponibles: {$names}. Para conversar en lenguaje natural configura un proveedor de IA.", 'tool_calls' => [], 'usage' => $usage];
    }

    /** @param array<string,mixed> $data */
    private static function present(array $data, int $depth = 0): string
    {
        $out = [];
        foreach ($data as $k => $v) {
            $label = is_int($k) ? '•' : ucfirst(str_replace('_', ' ', (string) $k)) . ':';
            if (is_array($v)) {
                $out[] = $label . "\n" . self::present($v, $depth + 1);
            } else {
                $out[] = ($depth > 0 ? '  ' : '') . $label . ' ' . ($v === null ? '—' : (string) $v);
            }
        }

        return implode("\n", $out);
    }
}
