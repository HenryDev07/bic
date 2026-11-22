<?php

declare(strict_types=1);

namespace Bic\Modules\Ai\Provider;

/** Transporte HTTP mínimo con curl. Inyectable en pruebas: callable(url, headers, jsonBody): array{0:int,1:string}. */
final class HttpTransport
{
    /** @param list<string> $headers @return array{0:int,1:string} */
    public static function post(string $url, array $headers, string $body, int $timeout = 60): array
    {
        if (!function_exists('curl_init')) {
            throw new AiProviderException('La extensión curl de PHP no está habilitada.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false]);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) {
            throw new AiProviderException('No se pudo contactar al proveedor de IA: ' . $err);
        }

        return [$status, (string) $out];
    }
}
