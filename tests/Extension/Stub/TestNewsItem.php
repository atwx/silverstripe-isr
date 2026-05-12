<?php

declare(strict_types=1);

namespace Atwx\ISR\Tests\Extension\Stub;

use Atwx\ISR\Extension\ISRDataObjectExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class TestNewsItem extends DataObject implements TestOnly
{
    private static string $table_name = 'ISRTest_TestNewsItem';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $extensions = [
        ISRDataObjectExtension::class,
    ];

    private static string $isr_tag_prefix = 'news';
}
