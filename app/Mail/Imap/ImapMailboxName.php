<?php

namespace App\Mail\Imap;

/**
 * Helpers for IMAP mailbox path segments (folder names from imap_list).
 */
final class ImapMailboxName
{
    /**
     * Quote mailbox names that contain characters special to the IMAP open path.
     * Unquoted commas are treated as mailbox-list separators by c-client.
     */
    public static function quoteForOpen(string $folder): string
    {
        if ($folder === '') {
            return $folder;
        }

        if (str_starts_with($folder, '"') && str_ends_with($folder, '"')) {
            return $folder;
        }

        if (preg_match('/[ ,"\\\\\\r\\n]/', $folder) !== 1) {
            return $folder;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $folder).'"';
    }
}
