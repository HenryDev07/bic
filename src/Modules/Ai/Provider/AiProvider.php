<?php

declare(strict_types=1);

namespace Bic\Modules\Ai\Provider;

/**
 * Proveedor de IA intercambiable. Formato interno (estilo bloques):
 *  mensajes: ['role' => 'user'|'assistant', 'content' => string | list<bloque>]
 *  bloque: ['type'=>'text','text'] | ['type'=>'tool_use','id','name','input'] | ['type'=>'tool_result','tool_use_id','content']
 *  herramientas: ['name','description','schema'] (JSON Schema de los argumentos)
 */
interface AiProvider
{
    public function name(): string;

    /**
     * @param list<array<string,mixed>> $messages
     * @param list<array<string,mixed>> $tools
     * @return array{text:string,tool_calls:list<array{id:string,name:string,input:array<string,mixed>}>,usage:array{in:int,out:int}}
     * @throws AiProviderException
     */
    public function complete(string $system, array $messages, array $tools): array;
}
