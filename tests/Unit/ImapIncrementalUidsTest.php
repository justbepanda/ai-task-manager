<?php

namespace Tests\Unit;

use App\Mail\Imap\ImapIncrementalUids;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImapIncrementalUidsTest extends TestCase
{
    #[Test]
    public function uses_range_search_when_server_supports_it(): void
    {
        $uids = ImapIncrementalUids::after(100, [101, 105], [1, 50, 100, 101, 105]);

        $this->assertSame([101, 105], $uids);
    }

    #[Test]
    public function falls_back_to_all_filter_when_uid_range_empty(): void
    {
        // Yandex: SEARCH UID 45674:* → [] while ALL still contains newer UIDs.
        $uids = ImapIncrementalUids::after(45673, [], [45670, 45673, 45674, 45681]);

        $this->assertSame([45674, 45681], $uids);
    }

    #[Test]
    public function returns_all_when_last_uid_is_zero(): void
    {
        $uids = ImapIncrementalUids::after(0, [], [3, 1, 2]);

        $this->assertSame([1, 2, 3], $uids);
    }
}
