<?php

declare(strict_types=1);

namespace Atwx\ISR\Tests\Cache;

use Atwx\ISR\Cache\ISRCounters;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

class ISRCountersTest extends TestCase
{
    private function make(): ISRCounters
    {
        return new ISRCounters(new TagAwareAdapter(new ArrayAdapter(), new ArrayAdapter()));
    }

    public function testIncrementAndReadCurrentBucket(): void
    {
        $c = $this->make();
        $c->increment('HIT');
        $c->increment('HIT');
        $c->increment('MISS');
        $c->increment('STALE');
        $c->increment('REVALIDATE');

        $totals = $c->totals(1);
        $this->assertSame(2, $totals['HIT']);
        $this->assertSame(1, $totals['MISS']);
        $this->assertSame(1, $totals['STALE']);
        $this->assertSame(1, $totals['REVALIDATE']);
    }

    public function testUnknownStateIsIgnored(): void
    {
        $c = $this->make();
        $c->increment('BOGUS');
        foreach ($c->totals(1) as $count) {
            $this->assertSame(0, $count);
        }
    }

    public function testCaseInsensitiveState(): void
    {
        $c = $this->make();
        $c->increment('hit');
        $c->increment('Hit');
        $this->assertSame(2, $c->totals(1)['HIT']);
    }

    public function testReadReturnsHoursDescendingFromOldestToNewest(): void
    {
        $c = $this->make();
        $rows = $c->read(3);
        $this->assertCount(3, $rows);
        $keys = array_keys($rows);
        $this->assertSame($keys, array_values($keys), 'keys are sequential YmdH buckets');
        $this->assertLessThanOrEqual($keys[2], $keys[1]);
    }
}
