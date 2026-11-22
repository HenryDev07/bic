<?php

declare(strict_types=1);

namespace Bic\Core;

/**
 * Gráficos SVG generados en el servidor: sin JavaScript, sin CDN y compatibles con la política CSP (solo atributos de
 * presentación y clases). Los valores se usan únicamente para dibujar; las cifras exactas se muestran con Decimal.
 */
final class Charts
{
    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Barras verticales. $labels y $values del mismo largo; $valueText opcional (texto del tooltip por barra).
     *
     * @param list<string> $labels
     * @param list<float> $values
     * @param list<string> $valueText
     */
    public static function bars(array $labels, array $values, string $ariaLabel, array $valueText = []): string
    {
        $n = count($values);
        if ($n === 0) {
            return '';
        }
        $w = 640;
        $h = 220;
        $left = 8;
        $bottom = 24;
        $top = 10;
        $max = max(array_merge([0.0], $values));
        $max = $max > 0 ? $max : 1.0;
        $slot = ($w - $left) / $n;
        $bw = max(2.0, min(40.0, $slot * 0.7));
        $plotH = $h - $bottom - $top;
        $every = max(1, (int) ceil($n / 10));
        $out = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . self::e($ariaLabel) . '" preserveAspectRatio="xMidYMid meet">';
        $out .= '<line class="chart-axis" x1="' . $left . '" y1="' . ($h - $bottom) . '" x2="' . $w . '" y2="' . ($h - $bottom) . '"/>';
        foreach ($values as $i => $v) {
            $bh = $v > 0 ? max(1.0, $v / $max * $plotH) : 0.0;
            $x = $left + $i * $slot + ($slot - $bw) / 2;
            $y = $h - $bottom - $bh;
            $tip = ($labels[$i] ?? '') . ': ' . ($valueText[$i] ?? (string) $v);
            $out .= '<rect class="chart-bar" x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bw, 1) . '" height="' . round($bh, 1) . '" rx="2"><title>' . self::e($tip) . '</title></rect>';
            if ($i % $every === 0) {
                $out .= '<text class="chart-label" x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 6) . '" text-anchor="middle">' . self::e($labels[$i] ?? '') . '</text>';
            }
        }

        return $out . '</svg>';
    }

    /**
     * Barras horizontales (ranking). El texto de valor va a la derecha de cada barra.
     *
     * @param list<string> $labels
     * @param list<float> $values
     * @param list<string> $valueText
     */
    public static function hbars(array $labels, array $values, string $ariaLabel, array $valueText = []): string
    {
        $n = count($values);
        if ($n === 0) {
            return '';
        }
        $w = 640;
        $row = 30;
        $h = $n * $row + 4;
        $labelW = 190;
        $valueW = 110;
        $plotW = $w - $labelW - $valueW;
        $max = max(array_merge([0.0], $values));
        $max = $max > 0 ? $max : 1.0;
        $out = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . self::e($ariaLabel) . '" preserveAspectRatio="xMinYMin meet">';
        foreach ($values as $i => $v) {
            $y = $i * $row + 4;
            $bw = $v > 0 ? max(2.0, $v / $max * $plotW) : 0.0;
            $label = (string) ($labels[$i] ?? '');
            $out .= '<text class="chart-label chart-label-left" x="0" y="' . ($y + 15) . '">' . self::e(mb_strlen($label) > 28 ? mb_substr($label, 0, 27) . '…' : $label) . '</text>';
            $out .= '<rect class="chart-bar" x="' . $labelW . '" y="' . $y . '" width="' . round($bw, 1) . '" height="20" rx="3"><title>' . self::e($label . ': ' . ($valueText[$i] ?? (string) $v)) . '</title></rect>';
            $out .= '<text class="chart-label" x="' . round($labelW + $bw + 6, 1) . '" y="' . ($y + 15) . '">' . self::e($valueText[$i] ?? (string) $v) . '</text>';
        }

        return $out . '</svg>';
    }
}
