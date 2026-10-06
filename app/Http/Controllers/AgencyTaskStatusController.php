<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyTaskStatus;
use App\Models\Task;
use App\Support\TaskStatusResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CRUD des statuts de taches d'une agence.
 *
 * Regle metier : un statut utilise par au moins une tache ne peut etre
 * supprime, seulement renomme ou repositionne. On privilegie toujours
 * l'integrite des donnees a la liberte de configuration.
 */
class AgencyTaskStatusController extends Controller
{
    public function index(Request $request, Agency $agency): JsonResponse
    {
        $this->authorize('view', $agency);

        // `id` n'existe que pour les lignes réellement configurees : un statut
        // par defaut n'a pas de ligne en base, donc pas d'identifiant, et
        // n'est donc pas modifiable directement (il faut le personnaliser,
        // ce qui cree la ligne).
        $ids = AgencyTaskStatus::where('agency_id', $agency->id)
            ->pluck('id', 'key')
            ->all();

        $statuses = TaskStatusResolver::forAgency((int) $agency->id)
            ->map(fn (array $s, int $i) => [
                ...$s,
                'id' => $ids[$s['key']] ?? null,
                'position' => $i,
            ]);

        return response()->json([
            'statuses' => $statuses->values(),
            'uses_defaults' => AgencyTaskStatus::where('agency_id', $agency->id)->doesntExist(),
        ]);
    }

    public function store(Request $request, Agency $agency): JsonResponse
    {
        $this->authorize('update', $agency);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $label = trim($data['label']);
        $key = $this->slugify($label);

        if (AgencyTaskStatus::isReservedKey($key) || $key === '') {
            return response()->json([
                'message' => "Ce libellé ne peut pas être utilisé comme statut.",
            ], 422);
        }

        // Doublon = la agence a DEJA une ligne pour cette cle. On regarde la
        // table et non la liste resolue : une cle historique qui n'a pas encore
        // de ligne doit pouvoir etre personnalisee (renommer « En cours »),
        // sinon les colonnes par defaut seraient figees pour toujours.
        if (AgencyTaskStatus::where('agency_id', $agency->id)->where('key', $key)->exists()) {
            return response()->json([
                'message' => "Un statut « {$label} » existe déjà pour cette agence.",
            ], 422);
        }

        $status = AgencyTaskStatus::create([
            'agency_id' => $agency->id,
            'key' => $key,
            'label' => $label,
            'color' => $data['color'] ?? '#056cf2',
            // Apres les defaults, donc en fin de Kanban.
            'position' => ((int) AgencyTaskStatus::where('agency_id', $agency->id)->max('position')) + 10,
            'is_terminal' => false,
        ]);

        TaskStatusResolver::flush();

        return response()->json($status, 201);
    }

    public function update(Request $request, Agency $agency, AgencyTaskStatus $agencyTaskStatus): JsonResponse
    {
        $this->authorize('update', $agency);
        $this->assertSameAgency($agency, $agencyTaskStatus);

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:80'],
            'color' => ['sometimes', 'string', 'max:20'],
            'is_terminal' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (! $this->wouldKeepATerminalStatus($agency, $agencyTaskStatus, $data)) {
            return response()->json([
                'message' => "Une agence doit garder au moins un statut qui clôt une tâche.",
            ], 422);
        }

        $agencyTaskStatus->fill(array_filter(
            $data,
            fn ($value, $key) => in_array($key, ['label', 'color', 'is_terminal', 'position'], true),
            ARRAY_FILTER_USE_BOTH
        ));

        $agencyTaskStatus->save();
        TaskStatusResolver::flush();

        return response()->json($agencyTaskStatus->fresh());
    }

    /**
     * Verifie que l'invariance "au moins un statut terminal" tient apres la
     * modification.
     *
     * On raisonne sur la liste resolue (defauts fusionnes + personnalisations)
     * plutot que sur une simple lecture de la table, sinon une agence sans
     * aucune personnalisation passerait pour n'avoir aucun statut terminal.
     * Rendre un statut terminal ne peut pas casser l'invariant ; seul
     * l'enlever le peut.
     *
     * @param  array<string, mixed>  $data
     */
    private function wouldKeepATerminalStatus(
        Agency $agency,
        AgencyTaskStatus $status,
        array $data
    ): bool {
        // Rendre un statut terminal ne peut pas casser l'invariant.
        if (($data['is_terminal'] ?? null) === true) {
            return true;
        }

        // Le drapeau n'est pas demande : rien a prouver.
        if (! array_key_exists('is_terminal', $data)) {
            return true;
        }

        // Ici on le passe de true a false : un autre terminal doit subsister.
        $terminals = TaskStatusResolver::forAgency((int) $agency->id)
            ->reject(fn (array $s) => $s['key'] === $status->key)
            ->filter(fn (array $s) => (bool) $s['is_terminal']);

        return $terminals->isNotEmpty();
    }

    public function destroy(Agency $agency, AgencyTaskStatus $agencyTaskStatus): JsonResponse
    {
        $this->authorize('update', $agency);
        $this->assertSameAgency($agency, $agencyTaskStatus);

        $used = Task::where('project_id', $this->projectIdsOf($agency))
            ->where('status', $agencyTaskStatus->key)
            ->count();

        if ($used > 0) {
            return response()->json([
                'message' => "Impossible de supprimer « {$agencyTaskStatus->label} » : {$used} tâche(s) l'utilisent encore. Renommez-le plutôt.",
            ], 409);
        }

        if ($agencyTaskStatus->is_terminal) {
            $terminalCount = TaskStatusResolver::forAgency((int) $agency->id)
                ->filter(fn (array $s) => (bool) $s['is_terminal'])
                ->count();

            if ($terminalCount <= 1) {
                return response()->json([
                    'message' => "Impossible de supprimer le dernier statut qui clôt une tâche.",
                ], 422);
            }
        }

        $agencyTaskStatus->delete();
        TaskStatusResolver::flush();

        return response()->json(['deleted' => true]);
    }

    /** Replace toutes les taches d'un statut vers un autre (nettoyage). */
    public function reassign(Request $request, Agency $agency, AgencyTaskStatus $agencyTaskStatus): JsonResponse
    {
        $this->authorize('update', $agency);
        $this->assertSameAgency($agency, $agencyTaskStatus);

        $data = $request->validate([
            'to' => ['required', 'string'],
        ]);

        if (! TaskStatusResolver::isValidKey((int) $agency->id, $data['to'])) {
            return response()->json(['message' => "Statut cible inconnu."], 422);
        }

        $count = DB::table('tasks')
            ->whereIn('project_id', $this->projectIdsOf($agency))
            ->where('status', $agencyTaskStatus->key)
            ->update([
                'status' => $data['to'],
                // C'est `is_terminal` qui decide, pas la cle 'terminee'.
                'completed_at' => TaskStatusResolver::isTerminal((int) $agency->id, $data['to'])
                    ? now()
                    : null,
                'updated_at' => now(),
            ]);

        return response()->json(['moved' => $count, 'to' => $data['to']]);
    }

    /** @return array<int, int> */
    private function projectIdsOf(Agency $agency): array
    {
        return DB::table('projects')->where('agency_id', $agency->id)->pluck('id')->all();
    }

    private function assertSameAgency(Agency $agency, AgencyTaskStatus $status): void
    {
        abort_unless((int) $status->agency_id === (int) $agency->id, 404);
    }

    private function slugify(string $label): string
    {
        $key = Str::of($label)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->limit(50, '')
            ->value();

        return $key;
    }
}
