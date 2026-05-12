<?php

declare(strict_types=1);

namespace Atwx\ISR\Tests\Extension;

use Atwx\ISR\Cache\ISRCache;
use Atwx\ISR\Extension\ISRDataObjectExtension;
use Atwx\ISR\Middleware\ISRMiddleware;
use Atwx\ISR\Tests\Extension\Stub\TestNewsItem;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

class ISRDataObjectExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static array $extra_dataobjects = [
        TestNewsItem::class,
    ];

    private ISRCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new ISRCache(new TagAwareAdapter(new ArrayAdapter(), new ArrayAdapter()));
        Injector::inst()->registerService($this->cache, ISRCache::class);
        ISRMiddleware::resetTagCollector();
    }

    public function testAddISRTagCollectsPerRecordTag(): void
    {
        $item = TestNewsItem::create(['Title' => 'Foo']);
        $item->write();
        $item->addISRTag();
        $tags = ISRMiddleware::tagCollector()->all();
        $this->assertContains('news-' . $item->ID, $tags);
    }

    public function testAddISRListTagCollectsListTag(): void
    {
        $item = TestNewsItem::create(['Title' => 'Foo']);
        $item->write();
        $item->addISRListTag();
        $this->assertContains('news-list', ISRMiddleware::tagCollector()->all());
    }

    public function testWriteInvalidatesOwnAndListTags(): void
    {
        // First create an item to get an ID, then probe invalidation by storing a
        // tagged entry and checking that a subsequent write wipes it.
        $item = TestNewsItem::create(['Title' => 'Foo']);
        $item->write();
        $id = (int)$item->ID;

        $entry = new \Atwx\ISR\Cache\ISRCacheEntry('body', [], 200, time(), 60, ['news-' . $id]);
        $this->cache->set('detail-key', $entry, ['news-' . $id]);
        $listEntry = new \Atwx\ISR\Cache\ISRCacheEntry('list', [], 200, time(), 60, ['news-list']);
        $this->cache->set('list-key', $listEntry, ['news-list']);

        $this->assertNotNull($this->cache->get('detail-key'));
        $this->assertNotNull($this->cache->get('list-key'));

        $item->Title = 'Renamed';
        $item->write();

        $this->assertNull($this->cache->get('detail-key'), 'Per-record tag must have been invalidated.');
        $this->assertNull($this->cache->get('list-key'), 'List tag must have been invalidated.');
    }

    public function testDeleteInvalidatesOwnAndListTags(): void
    {
        $item = TestNewsItem::create(['Title' => 'Foo']);
        $item->write();
        $id = (int)$item->ID;

        $entry = new \Atwx\ISR\Cache\ISRCacheEntry('body', [], 200, time(), 60, ['news-' . $id]);
        $this->cache->set('detail-key', $entry, ['news-' . $id]);

        $item->delete();
        $this->assertNull($this->cache->get('detail-key'));
    }
}
