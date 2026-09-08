<?php

namespace App\Mail\Contracts;

use App\Mail\DTO\NormalizedIncomingMessage;
use App\Models\MailSyncState;

interface MailProvider
{
    /**
     * Fetch new messages since the sync cursor.
     * Implementations must never include credentials in exceptions or logs.
     *
     * @return iterable<int, NormalizedIncomingMessage>
     */
    public function fetchNewMessages(?MailSyncState $state = null): iterable;
}
