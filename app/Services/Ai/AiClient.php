<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client HTTP minimal vers une API "chat completions" compatible OpenAI.
 *
 * Volontairement sans dependance : Http::withToken() suffit et evite de
 * coupler le projet a un SDK de fournisseur donne.
 */
class AiClient
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{content: string, model: string, usage: array<string, int>}
     */
    public function chat(array $messages): array
    {
        if (! config('ai.enabled')) {
            throw new RuntimeException("L'assistant IA est desactive (AI_ENABLED=false).");
        }

        $key = (string) config('ai.api_key');

        if ($key === '') {
            throw new RuntimeException(
                "L'assistant IA n'est pas configure : definissez AI_API_KEY dans le fichier .env du backend."
            );
        }

        $response = Http::withToken($key)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('ai.timeout'))
            ->post(rtrim((string) config('ai.base_url'), '/').'/chat/completions', [
                'model' => config('ai.model'),
                'messages' => $messages,
                'temperature' => config('ai.temperature'),
                'max_tokens' => config('ai.max_tokens'),
            ]);

        if ($response->failed()) {
            $status = $response->status();
            $detail = $response->json('error.message') ?? $response->body();

            throw new RuntimeException(
                "Le fournisseur IA a repondu en erreur (HTTP {$status}) : ".mb_substr((string) $detail, 0, 300)
            );
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException("Le fournisseur IA a renvoye une reponse vide.");
        }

        return [
            'content' => trim($content),
            'model' => (string) $response->json('model', config('ai.model')),
            'usage' => (array) $response->json('usage', []),
        ];
    }

    /** L'assistant est-il pret a repondre ? */
    public function isConfigured(): bool
    {
        return (bool) config('ai.enabled') && (string) config('ai.api_key') !== '';
    }
}
