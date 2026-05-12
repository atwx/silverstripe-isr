<?php

declare(strict_types=1);

namespace Atwx\ISR\Tests\Extension;

use Atwx\ISR\Cache\ISRCache;
use Atwx\ISR\Cache\ISRCacheEntry;
use Atwx\ISR\Extension\ISRDataObjectExtension;
use Atwx\ISR\Middleware\ISRMiddleware;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\TestOnly;
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
        Config::modify()->set(FakeISRTestOwner::class, 'isr_tag_prefix', 'news');
    }

    private function makeOwner(int $id): FakeISRTestOwner
    {
        $owner = new FakeISRTestOwner();
        $owner->ID = $id;
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
        Config::modify()->set(FakeISRTestOwner::class, 'isr_tag_prefix', 'article');
        $ext = $this->bindExtension($this->makeOwner(3));
        $ext->addISRTag();
        $this->assertContains('article-3', ISRMiddleware::tagCollector()->all());
    }

    public function testFallbackPrefixIsShortClassName(): void
    {
        Config::modify()->set(FakeISRTestOwner::class, 'isr_tag_prefix', null);
        $ext = $this->bindExtension($this->makeOwner(5));
        $ext->addISRTag();
        $this->assertContains('fakeisrtestowner-5', ISRMiddleware::tagCollector()->all());
    }

    public function testIdZeroDoesNothing(): void
    {
        $ext = $this->bindExtension($this->makeOwner(0));
        $ext->addISRTag();
        $this->assertNotContains('news-0', ISRMiddleware::tagCollector()->all());
    }
}

/**
 * Minimal DataObject used by tests above. Not persisted to a database.
 */
class FakeISRTestOwner extends DataObject implements TestOnly
{
    private static string $table_name = 'ISRTest_FakeISRTestOwner';
}
