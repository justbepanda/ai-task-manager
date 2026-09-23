<?php

namespace App\Mail\Imap;

/**
 * Chooses UIDs for incremental IMAP sync.
 *
 * Yandex (and some other servers) reject SEARCH UID n:* — imap_search returns
 * false/empty even when newer UIDs exist. Fall back to ALL + PHP filter.
 */
final class ImapIncrementalUids
{
    /**
     * @param  list<int>  $rangeSearchResult  Result of SEARCH UID (lastUid+1):*
     * @param  list<int>  $allUids  Result of SEARCH ALL
     * @return list<int>
     */
    public static function after(int $lastUid, array $rangeSearchResult, array $allUids): array
    {
        if ($lastUid <= 0) {
            $uids = array_values(array_map('intval', $allUids));
            sort($uids);

            return $uids;
        }

        if ($rangeSearchResult !== []) {
            $uids = array_values(array_map('intval', $rangeSearchResult));
            sort($uids);

            return $uids;
        }

        $uids = [];
        foreach ($allUids as $uid) {
            $uid = (int) $uid;
            if ($uid > $lastUid) {
                $uids[] = $uid;
            }
        }
        sort($uids);

        return $uids;
    }
}
