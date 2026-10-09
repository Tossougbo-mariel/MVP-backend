<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiContextBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Assistant IA de l'application.
 *
 * Contrainte importante : cet assistant est en lecture seule. Il explique,
 * resume et propose des etapes, mais il n'ecrit jamais en base. Toute action
 * modifiante devra passer par une confirmation explicite cote client puis par
 * les routes/policies existantes, jamais par un appel direct du modele.
 */
class AiChatController extends Controller
{
    public function __construct(
        private readonly AiClient $client,
        private readonly AiContextBuilder $context,
    ) {}

    /** Le client appelle ceci au montage pour afficher l'etat de l'assistant. */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'configured' => $this->client->isConfigured(),
            'model' => config('ai.model'),
            'read_only' => true,
        ]);
    }

    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:2000'],
            'agency_id' => ['nullable', 'integer', 'exists:agencies,id'],
            'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        // L'agent ne voit que les agences dont l'utilisateur a une membership
        // active : meme perimetre que AgencyController@index.
        $agencyId = isset($validated['agency_id'])
            ? (int) $validated['agency_id']
            : null;

        if ($agencyId !== null && ! in_array($agencyId, $this->context->agencyIds($user), true)) {
            return response()->json([
                'message' => "Vous n'etes pas membre actif de cette agence.",
            ], 403);
        }

        $system = $this->context->build($user, $agencyId);

        $messages = [['role' => 'system', 'content' => $system]];

        $maxHistory = (int) config('ai.max_history');
        $history = array_slice($validated['history'] ?? [], -$maxHistory);

        foreach ($history as $turn) {
            $messages[] = [
                'role' => $turn['role'],
                'content' => $turn['content'],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $validated['message'],
        ];

        try {
            $result = $this->client->chat($messages);
        } catch (Throwable $e) {
            Log::warning('AI chat failed', ['message' => $e->getMessage()]);

            return response()->json([
                'message' => $e->getMessage(),
                'configured' => $this->client->isConfigured(),
            ], 503);
        }

        return response()->json([
            'reply' => $result['content'],
            'model' => $result['model'],
            'usage' => $result['usage'],
        ]);
    }
}
