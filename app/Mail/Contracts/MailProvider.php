<?php

namespace App\Mail\Contracts;

use App\Mail\DTO\NormalizedIncomingMessage;
use App\Models\MailSyncState;

interface MailProvider
{
    /**
     * Folders to sync (e.g. INBOX + user labels). Excludes spam/trash/drafts/sent by default.
     *
     * @return list<string>
     */
    public function listSyncFolders(): array;

    /**
     * Fetch new messages since the sync cursor for the folder in $state.
     * Implementations must never include credentials in exceptions or logs.
     *
     * @return iterable<int, NormalizedIncomingMessage>
     */
    public function fetchNewMessages(?MailSyncState $state = null): iterable;
}
