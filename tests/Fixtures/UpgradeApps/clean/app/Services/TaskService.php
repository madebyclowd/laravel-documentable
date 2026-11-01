<?php

declare(strict_types=1);

namespace App\Services;

class TaskService
{
    public function open($task): void
    {
        $task->status = 'pending';
        $task->save();
    }
}
