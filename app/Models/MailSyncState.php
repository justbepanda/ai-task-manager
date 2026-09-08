<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'provider',
    'folder',
    'uidvalidity',
    'last_uid',
    'last_synced_at',
])]
class MailSyncState extends Model
{
    protected function casts(): array
    {
        return [
            'uidvalidity' => 'integer',
            'last_uid' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }
}
