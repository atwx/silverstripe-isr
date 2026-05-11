<?php

declare(strict_types=1);

namespace Atwx\ISR\Cache;

use SilverStripe\Core\Injector\Injector;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

class BackendFactory
{
    public static function filesystem(): TagAwareAdapter
    {
        $dir = defined('TEMP_FOLDER') ? TEMP_FOLDER . '/isr' : sys_get_temp_dir() . '/isr';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $items = new FilesystemAdapter('items', 0, $dir);
        $tags = new FilesystemAdapter('tags', 0, $dir);
        return new TagAwareAdapter($items, $tags);
    }

    public static function redis(?string $dsn = null): TagAwareAdapter
    {
        $dsn ??= getenv('ISR_REDIS_DSN') ?: 'redis://localhost:6379';
        $client = RedisAdapter::createConnection($dsn);
        $items = new RedisAdapter($client, 'isr_items');
        $tags = new RedisAdapter($client, 'isr_tags');
        return new TagAwareAdapter($items, $tags);
    }

    public static function make(string $type = 'filesystem'): AdapterInterface
    {
        return match ($type) {
            'redis' => self::redis(),
            default => self::filesystem(),
        };
    }
}
