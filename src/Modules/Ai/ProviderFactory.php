<?php

declare(strict_types=1);

namespace Bic\Modules\Ai;

use Bic\Core\Config;
use Bic\Modules\Ai\Provider\AiProvider;
use Bic\Modules\Ai\Provider\AnthropicProvider;
use Bic\Modules\Ai\Provider\LocalProvider;
use Bic\Modules\Ai\Provider\OpenAiProvider;

final class ProviderFactory
{
    public static function make(Config $config): AiProvider
    {
        $kind = strtolower((string) $config->get('ai.provider', 'local'));
        $key = (string) $config->get('ai.api_key', '');
        $model = (string) $config->get('ai.model', '');
        $base = (string) $config->get('ai.base_url', '');
        if ($key === '' || !in_array($kind, ['anthropic', 'openai'], true)) {
            return new LocalProvider(); // sin clave configurada: modo local, nunca falla en silencio
        }

        return $kind === 'anthropic'
            ? new AnthropicProvider($key, $model !== '' ? $model : 'claude-sonnet-5-5', $base)
            : new OpenAiProvider($key, $model !== '' ? $model : 'gpt-4o-mini', $base);
    }
}
