<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $fillable = ['agency_id', 'name', 'color'];

    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }

    public function tasks()
    {
        return $this->belongsToMany(Task::class);
    }
}
