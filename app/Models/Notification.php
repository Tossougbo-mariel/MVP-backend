<?php

namespace App\Models;

use App\Events\NotificationCreated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

class Notification extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'type', 'title', 'message', 'link', 'read_at'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Crée une notification (en respectant les préférences de la personne)
     * et la diffuse en temps réel.
     */
    public static function notifyUser(int $userId, string $type, string $title, string $message, ?string $link = null): ?self
    {
        $user = User::find($userId);

        if ($user && ! $user->wantsNotification($type)) {
            return null;
        }

        $notification = self::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'link' => $link,
        ])->fresh();

        // La diffusion temps réel ne doit jamais faire échouer l'action métier :
        // si le serveur Reverb est indisponible, on journalise et on continue.
        try {
            $pending = broadcast(new NotificationCreated($notification));
            unset($pending);
        } catch (Throwable $e) {
            Log::warning('Diffusion de la notification échouée : '.$e->getMessage());
        }

        return $notification;
    }
}
