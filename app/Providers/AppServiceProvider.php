<?php

namespace App\Providers;

use App\Models\Comment;
use App\Models\Task;
use App\Observers\CommentObserver;
use App\Observers\TaskObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Task::observe(TaskObserver::class);
        Comment::observe(CommentObserver::class);
    }
}
