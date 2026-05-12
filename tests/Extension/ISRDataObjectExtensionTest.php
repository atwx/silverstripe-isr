<?php

declare(strict_types=1);

namespace Atwx\ISR\Tests\Extension;

use Atwx\ISR\Cache\ISRCache;
use Atwx\ISR\Cache\ISRCacheEntry;
use Atwx\ISR\Extension\ISRDataObjectExtension;
use Atwx\ISR\Middleware\ISRMiddleware;
use SilverStripe\Core\Config\Config_ForClass;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

class ISRDataObjectExtensionTest extends SapphireTest
{
    private ISRCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new ISRCache(new TagAwareAdapter(new ArrayAdapter(), new ArrayAdapter()));
        Injector::inst()->registerService($this->cache, ISRCache::class);
        ISRMiddleware::resetTagCollector();
    }

    private function makeOwner(int $id, ?string $prefix = 'news'): FakeISRTestOwner
    {
        $owner = new FakeISRTestOwner();
        $owner->ID = $id;
        $owner->isrPrefix = $prefix;
        return $owner;
    }

    private function bindExtension(FakeISRTestOwner $owner): ISRDataObjectExtension
    {
        $ext = new ISRDataObjectExtension();
        $ext->setOwner($owner);
        return $ext;
    }

    public function testAddISRTagCollectsPerRecordTag(): void
    {
        $ext = $this->bindExtension($this->makeOwner(42));
        $ext->addISRTag();
        $this->assertContains('news-42', ISRMiddleware::tagCollector()->all());
    }

    public function testAddISRListTagCollectsListTag(): void
    {
        $ext = $this->bindExtension($this->makeOwner(42));
        $ext->addISRListTag();
        $this->assertContains('news-list', ISRMiddleware::tagCollector()->all());
    }

    public function testOnAfterWriteInvalidatesOwnAndListTags(): void
    {
        $ext = $this->bindExtension($this->makeOwner(7));

        $detail = new ISRCacheEntry('detail', [], 200, time(), 60, ['news-7']);
        $this->cache->set('detail-key', $detail, ['news-7']);
        $list = new ISRCacheEntry('list', [], 200, time(), 60, ['news-list']);
        $this->cache->set('list-key', $list, ['news-list']);

        $this->assertNotNull($this->cache->get('detail-key'));
        $this->assertNotNull($this->cache->get('list-key'));

        $ext->onAfterWrite();

        $this->assertNull($this->cache->get('detail-key'));
        $this->assertNull($this->cache->get('list-key'));
    }

    public function testOnAfterDeleteAndPublishAlsoInvalidate(): void
    {
        $ext = $this->bindExtension($this->makeOwner(9));

        $set = function () {
            $this->cache->set('k', new ISRCacheEntry('x', [], 200, time(), 60, ['news-9']), ['news-9']);
        };

        $set();
        $ext->onAfterDelete();
        $this->assertNull($this->cache->get('k'));

        $set();
        $ext->onAfterPublish();
        $this->assertNull($this->cache->get('k'));

        $set();
        $ext->onAfterUnpublish();
        $this->assertNull($this->cache->get('k'));
    }

    public function testCustomPrefixIsUsed(): void
    {
        $ext = $this->bindExtension($this->makeOwner(3, 'article'));
        $ext->addISRTag();
        $this->assertContains('article-3', ISRMiddleware::tagCollector()->all());
    }

    public function testIdZeroDoesNothing(): void
    {
        $ext = $this->bindExtension($this->makeOwner(0));
        $ext->addISRTag();
        $this->assertNotContains('news-0', ISRMiddleware::tagCollector()->all());
    }
}

/**
 * Lightweight DataObject stand-in for testing ISRDataObjectExtension without a database.
 * Overrides config() to return a configurable isr_tag_prefix.
 */
class FakeISRTestOwner extends DataObject
{
    public ?string $isrPrefix = 'news';

    public function config(): Config_ForClass
    {
        $prefix = $this->isrPrefix;
        return new class($prefix) extends Config_ForClass {
            public function __construct(private readonly ?string $prefix)
            {
            }
            public function get($name, $options = 0)
            {
                if ($name === 'isr_tag_prefix') {
                    return $this->prefix;
                }
                return null;
            }
        };
    }
}
