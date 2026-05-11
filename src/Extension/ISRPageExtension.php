<?php

declare(strict_types=1);

namespace Atwx\ISR\Extension;

use Atwx\ISR\Cache\ISRCache;
use Atwx\ISR\Middleware\ISRMiddleware;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;

/**
 * @extends Extension<SiteTree>
 */
class ISRPageExtension extends Extension
{
    private static array $db = [
        'CacheTTL' => 'Int',
        'DisableISRCache' => 'Boolean',
    ];

    private static array $defaults = [
        'CacheTTL' => 0,
        'DisableISRCache' => false,
    ];

    public function updateSettingsFields(FieldList $fields): void
    {
        $fields->addFieldsToTab('Root.Caching', [
            NumericField::create('CacheTTL', 'Cache TTL (seconds)')
                ->setDescription('0 = use default from config. -1 = never cache this page.'),
            CheckboxField::create('DisableISRCache', 'Disable ISR caching for this page'),
        ]);
    }

    public function onAfterPublish(): void
    {
        $owner = $this->getOwner();
        $cache = Injector::inst()->get(ISRCache::class);
        $cache->invalidateTags([
            'page-' . (int)$owner->ID,
            'parent-' . (int)$owner->ParentID,
        ]);
    }

    public function onAfterUnpublish(): void
    {
        $this->onAfterPublish();
    }

    public function contentcontrollerInit($controller): void
    {
        $page = $this->getOwner();
        $response = $controller->getResponse();
        if (!$response) {
            return;
        }
        if ((bool)$page->DisableISRCache || (int)$page->CacheTTL === -1) {
            $response->addHeader('X-ISR-Bypass', '1');
            return;
        }
        $collector = ISRMiddleware::tagCollector();
        $collector->addTag('page-' . (int)$page->ID);
        if ((int)$page->ParentID > 0) {
            $collector->addTag('parent-' . (int)$page->ParentID);
        }
        if ((int)$page->CacheTTL > 0) {
            $response->addHeader('X-ISR-TTL', (string)(int)$page->CacheTTL);
        }
    }
}
