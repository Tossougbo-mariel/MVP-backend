<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $fillable = ['agency_id', 'name', 'description', 'membership', 'created_by'];

    protected function casts(): array
    {
        return [
            'membership' => 'string',
        ];
    }

    /** La création d'équipe est-elle organisée comme un groupe de collaborateurs. */
    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'team_members')->withTimestamps();
    }
}