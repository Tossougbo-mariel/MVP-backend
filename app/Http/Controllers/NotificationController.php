<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    // GET /api/notifications
    // ?agency_id= ne garde que les notifications d'une agence,
    // ?unreadOnly=1 ne garde que les non lues.
    public function index(Request $request)
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->when(
                $request->filled('agency_id'),
                fn ($query) => $query->where('agency_id', $request->integer('agency_id'))
            )
            ->when($request->boolean('unreadOnly'), fn ($query) => $query->whereNull('read_at'))
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page') ?: 25);

        return response()->json($notifications);
    }

    // PATCH /api/notifications/{notification}/read
    public function markAsRead(Notification $notification)
    {
        if ($denied = $this->denyUnlessOwn($notification)) {
            return $denied;
        }

        $notification->update(['read_at' => now()]);

        return response()->json($notification);
    }

    // PATCH /api/notifications/{notification}/unread
    // L'utilisateur peut repasser une notification en non lue : sans ce
    // retournement, "marquer comme lu" serait définitif et la liste perdrait
    // son rôle de boîte de réception à trier.
    public function markAsUnread(Notification $notification)
    {
        if ($denied = $this->denyUnlessOwn($notification)) {
            return $denied;
        }

        $notification->update(['read_at' => null]);

        return response()->json($notification);
    }

    // DELETE /api/notifications
    // Suppression en lot : la sélection se fait côté client (cases à cocher),
    // le back ne supprime que ce qui appartient à l'utilisateur connecté.
    public function destroyMany(Request $request)
    {
        $ids = collect($request->input('ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        abort_if($ids->isEmpty(), 422, 'Aucune notification sélectionnée.');

        $deleted = Notification::where('user_id', $request->user()->id)
            ->whereIn('id', $ids)
            ->delete();

        return response()->json(['deleted' => $deleted]);
    }

    // DELETE /api/notifications/{notification}
    public function destroy(Notification $notification)
    {
        if ($denied = $this->denyUnlessOwn($notification)) {
            return $denied;
        }

        $notification->delete();

        return response()->json(null, 204);
    }

    private function denyUnlessOwn(Notification $notification): ?\Illuminate\Http\JsonResponse
    {
        if ($notification->user_id !== request()->user()->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        return null;
    }

    // POST /api/notifications/read-all
    public function markAllAsRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Toutes les notifications marquées comme lues.']);
    }
}
