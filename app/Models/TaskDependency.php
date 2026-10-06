<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskDependency extends Model
{
    public $timestamps = false;

    protected $table = 'task_dependencies';

    protected $fillable = ['task_id', 'depends_on_task_id'];
}
