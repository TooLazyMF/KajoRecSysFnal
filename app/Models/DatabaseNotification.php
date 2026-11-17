<?php

namespace App\Models;

use App\Casts\EncryptedArrayObject;
use Illuminate\Notifications\DatabaseNotification as FrameworkDatabaseNotification;

class DatabaseNotification extends FrameworkDatabaseNotification
{
    protected function casts(): array
    {
        return [
            'data' => EncryptedArrayObject::class,
            'read_at' => 'datetime',
        ];
    }
}
