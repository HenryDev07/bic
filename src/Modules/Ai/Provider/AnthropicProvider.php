<?php

declare(strict_types=1);

namespace Bic\Modules\Ai\Provider;

/** API de mensajes de Anthropic. */
final class AnthropicProvider implements AiProvider
{
    /** @param callable|null $transport callable(string $url, list<string> $headers, string $body): array{0:int,1:string} */
    public function __construct(private readonly string $apiKey, private readonly string $model, private readonly string $baseUrl = '', private $transport = null)
    {
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function complete(string $system, array $messages, array $tools): array
    {
        $payload = ['model' => $this->model, 'max_tokens' => 1500, 'system' => $system, 'messages' => $messages];
        if ($tools !== []) {
            $payload['tools'] = array_map(static fn (array $t): array => ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['schema']], $tools);
        }
        $url = rtrim($this->baseUrl !== '' ? $this->baseUrl : 'https://api.anthropic.com', '/') . '/v1/messages';
        $send = $this->transport ?? [HttpTransport::class, 'post'];
        [$status, $body] = $send($url, ['content-type: application/json', 'x-api-key: ' . $this->apiKey, 'anthropic-version: 2023-06-01'], (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        $data = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            throw new AiProviderException('El proveedor de IA respondió con error (' . $status . ').');
        }
        $text = '';
        $calls = [];
        foreach ((array) ($data['content'] ?? []) as $b) {
            if (($b['type'] ?? '') === 'text') {
                $text .= (string) ($b['text'] ?? '');
            } elseif (($b['type'] ?? '') === 'tool_use') {
                $calls[] = ['id' => (string) $b['id'], 'name' => (string) $b['name'], 'input' => is_array($b['input'] ?? null) ? $b['input'] : []];
            }
        }

        return ['text' => $text, 'tool_calls' => $calls, 'usage' => ['in' => (int) ($data['usage']['input_tokens'] ?? 0), 'out' => (int) ($data['usage']['output_tokens'] ?? 0)]];
    }
}
