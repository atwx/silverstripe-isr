<?php

declare(strict_types=1);

namespace Atwx\ISR\Extension;

use Atwx\ISR\Cache\ISRCache;
use Atwx\ISR\Middleware\ISRMiddleware;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;

/**
 * Generic ISR tag wiring for any DataObject. Apply to a model and:
 *  - any write/delete (and publish/unpublish if Versioned is enabled) invalidates
 *    the per-record tag `{prefix}-{ID}` AND the collection tag `{prefix}-list`
 *  - controllers can call `$item->addISRTag()` while rendering to register the
 *    per-record tag against the in-flight cache entry
 *
 * Configure the prefix on the model (defaults to lowercased short class name):
 *
 *   private static string $isr_tag_prefix = 'news';
 *
 * @extends Extension<DataObject>
 */
class ISRDataObjectExtension extends Extension
{
    public function onAfterWrite(): void
    {
        $this->invalidateOwnTags();
    }

    public function onAfterDelete(): void
    {
        $this->invalidateOwnTags();
    }

    public function onAfterPublish(): void
    {
        $this->invalidateOwnTags();
    }

    public function onAfterUnpublish(): void
    {
        $this->invalidateOwnTags();
    }

    /**
     * Register the per-record tag against the cache entry currently being rendered.
     * Call this from a controller's rendering path (e.g. inside a foreach over items).
     */
    public function addISRTag(): void
    {
        $owner = $this->getOwner();
        $id = (int)$owner->ID;
        if ($id <= 0) {
            return;
        }
        ISRMiddleware::tagCollector()->addTag($this->prefix() . '-' . $id);
    }

    /**
     * Register the collection tag (e.g. for index/listing pages).
     */
    public function addISRListTag(): void
    {
        ISRMiddleware::tagCollector()->addTag($this->prefix() . '-list');
    }

    private function invalidateOwnTags(): void
    {
        $owner = $this->getOwner();
        $id = (int)$owner->ID;
        if ($id <= 0) {
            return;
        }
        $prefix = $this->prefix();
        Injector::inst()->get(ISRCache::class)->invalidateTags([
            $prefix . '-' . $id,
            $prefix . '-list',
        ]);
    }

    private function prefix(): string
    {
        $owner = $this->getOwner();
        $configured = $owner->config()->get('isr_tag_prefix');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        return strtolower(ClassInfo::shortName($owner));
    }
}
