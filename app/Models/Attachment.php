<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $fillable = [
        'task_id', 'user_id', 'file_name', 'file_path', 'file_size', 'mime_type',
    ];

    /**
     * Le chemin de stockage ne sort jamais de l'API.
     *
     * Il est interne : la téléchargement passe par AttachmentController, qui
     * vérifie les droits avant de servir le fichier. L'exposer donnerait aux
     * clients une information sur l'arborescence du disque sans aucun usage.
     */
    protected $hidden = ['file_path'];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
