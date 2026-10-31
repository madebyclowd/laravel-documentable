<?php

declare(strict_types=1);

namespace App\Repositories;

class DocQuery
{
    public function query($user)
    {
        return $user->documents()->whereHas('documentType', fn ($q) => $q->where('code', 'invoice'))->get();
    }
}
