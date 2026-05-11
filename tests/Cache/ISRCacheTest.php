<?php

declare(strict_types=1);

namespace Atwx\ISR\Tests\Cache;

use Atwx\ISR\Cache\ISRCache;
use Atwx\ISR\Cache\ISRCacheEntry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

class ISRCacheTest extends TestCase
{
    private function makeCache(): ISRCache
    {
        $backend = new TagAwareAdapter(new ArrayAdapter(), new ArrayAdapter());
        return new ISRCache($backend);
    }

    public function testSetGet(): void
    {
        $cache = $this->makeCache();
        $entry = new ISRCacheEntry('hello', ['Content-Type' => 'text/html'], 200, time(), 60, ['page-1']);
        $cache->set('key/one', $entry);
        $loaded = $cache->get('key/one');
        $this->assertNotNull($loaded);
        $this->assertSame('hello', $loaded->body);
        $this->assertSame(200, $loaded->statusCode);
    }

    public function testTagInvalidation(): void
    {
        $cache = $this->makeCache();
        $entry = new ISRCacheEntry('a', [], 200, time(), 60, ['page-1']);
        $cache->set('a', $entry, ['page-1']);
        $this->assertNotNull($cache->get('a'));
        $cache->invalidateTag('page-1');
        $this->assertNull($cache->get('a'));
    }

    public function testLockAndUnlock(): void
    {
        $cache = $this->makeCache();
        $this->assertTrue($cache->lock('key', 30));
        $this->assertFalse($cache->lock('key', 30));
        $cache->unlock('key');
        $this->assertTrue($cache->lock('key', 30));
    }

    public function testDelete(): void
    {
        $cache = $this->makeCache();
        $cache->set('x', new ISRCacheEntry('y', [], 200, time(), 60, []));
        $this->assertNotNull($cache->get('x'));
        $cache->delete('x');
        $this->assertNull($cache->get('x'));
    }
}
