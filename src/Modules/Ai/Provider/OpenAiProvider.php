<?php

declare(strict_types=1);

namespace Bic\Modules\Ai\Provider;

/** API estilo OpenAI (chat/completions): sirve para OpenAI y para servidores compatibles (base_url). */
final class OpenAiProvider implements AiProvider
{
    /** @param callable|null $transport */
    public function __construct(private readonly string $apiKey, private readonly string $model, private readonly string $baseUrl = '', private $transport = null)
    {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function complete(string $system, array $messages, array $tools): array
    {
        $msgs = [['role' => 'system', 'content' => $system]];
        foreach ($messages as $m) {
            if (is_string($m['content'])) {
                $msgs[] = ['role' => $m['role'], 'content' => $m['content']];
                continue;
            }
            $text = '';
            $calls = [];
            foreach ($m['content'] as $b) {
                if ($b['type'] === 'text') {
                    $text .= $b['text'];
                } elseif ($b['type'] === 'tool_use') {
                    $calls[] = ['id' => $b['id'], 'type' => 'function', 'function' => ['name' => $b['name'], 'arguments' => (string) json_encode($b['input'] === [] ? new \stdClass() : $b['input'], JSON_UNESCAPED_UNICODE)]];
                } elseif ($b['type'] === 'tool_result') {
                    $msgs[] = ['role' => 'tool', 'tool_call_id' => $b['tool_use_id'], 'content' => $b['content']];
                }
            }
            if ($m['role'] === 'assistant') {
                $msgs[] = ['role' => 'assistant', 'content' => $text === '' ? null : $text] + ($calls !== [] ? ['tool_calls' => $calls] : []);
            } elseif ($text !== '') {
                $msgs[] = ['role' => 'user', 'content' => $text];
            }
        }
        $payload = ['model' => $this->model, 'messages' => $msgs, 'max_tokens' => 1500];
        if ($tools !== []) {
            $payload['tools'] = array_map(static fn (array $t): array => ['type' => 'function', 'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['schema']]], $tools);
        }
        $url = rtrim($this->baseUrl !== '' ? $this->baseUrl : 'https://api.openai.com/v1', '/') . '/chat/completions';
        $send = $this->transport ?? [HttpTransport::class, 'post'];
        [$status, $body] = $send($url, ['content-type: application/json', 'authorization: Bearer ' . $this->apiKey], (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        $data = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($data) || !isset($data['choices'][0]['message'])) {
            throw new AiProviderException('El proveedor de IA respondió con error (' . $status . ').');
        }
        $msg = $data['choices'][0]['message'];
        $calls = [];
        foreach ((array) ($msg['tool_calls'] ?? []) as $c) {
            $args = json_decode((string) ($c['function']['arguments'] ?? '{}'), true);
            $calls[] = ['id' => (string) $c['id'], 'name' => (string) $c['function']['name'], 'input' => is_array($args) ? $args : []];
        }

        return ['text' => (string) ($msg['content'] ?? ''), 'tool_calls' => $calls, 'usage' => ['in' => (int) ($data['usage']['prompt_tokens'] ?? 0), 'out' => (int) ($data['usage']['completion_tokens'] ?? 0)]];
    }
}
