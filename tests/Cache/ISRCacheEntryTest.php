<?php

declare(strict_types=1);

namespace Atwx\ISR\Tests\Cache;

use Atwx\ISR\Cache\ISRCacheEntry;
use PHPUnit\Framework\TestCase;

class ISRCacheEntryTest extends TestCase
{
    public function testFreshEntryIsNotStale(): void
    {
        $now = 1_000_000;
        $entry = new ISRCacheEntry('body', [], 200, $now, 300, []);
        $this->assertFalse($entry->isStale($now + 100));
        $this->assertSame(100, $entry->age($now + 100));
    }

    public function testEntryBecomesStaleAfterTTL(): void
    {
        $now = 1_000_000;
        $entry = new ISRCacheEntry('body', [], 200, $now, 60, []);
        $this->assertFalse($entry->isStale($now + 60));
        $this->assertTrue($entry->isStale($now + 61));
    }

    public function testHardMaxAge(): void
    {
        $now = 1_000_000;
        $entry = new ISRCacheEntry('body', [], 200, $now, 60, []);
        $this->assertFalse($entry->isExpired(3600, $now + 60));
        $this->assertTrue($entry->isExpired(3600, $now + 3601));
    }
}
