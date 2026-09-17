<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgencyMember extends Model
{
    public $timestamps = false; // seul created_at existe, pas updated_at

    protected $fillable = ['agency_id', 'user_id', 'role', 'status'];

    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'actif');
    }
}
