<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Assistant IA
    |---------------------------------------------------------------------------
    |
    | Client compatible avec l'API "chat completions" d'OpenAI, ce qui permet de
    | cibler OpenAI, OpenRouter, Groq, Together, Ollama, LM Studio, etc. par la
    | seule variable AI_BASE_URL. Sans cle (AI_API_KEY vide), l'assistant refuse
    | de repondre plutot que d'inventer une reponse.
    |
    */

    'enabled' => env('AI_ENABLED', true),

    'api_key' => env('AI_API_KEY'),

    'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),

    'model' => env('AI_MODEL', 'gpt-4o-mini'),

    'timeout' => (int) env('AI_TIMEOUT', 45),

    'max_history' => (int) env('AI_MAX_HISTORY', 12),

    'max_tokens' => (int) env('AI_MAX_TOKENS', 700),

    'temperature' => (float) env('AI_TEMPERATURE', 0.2),

];
