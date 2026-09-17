<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    // GET /api/notifications
    public function index(Request $request)
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 25));

        return response()->json($notifications);
    }

    // PATCH /api/notifications/{notification}/read
    public function markAsRead(Notification $notification)
    {
        if ($notification->user_id !== request()->user()->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $notification->update(['read_at' => now()]);

        return response()->json($notification);
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
