<?php

declare(strict_types=1);

namespace Atwx\ISR\Cache;

use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

class ISRCache
{
    private const LOCK_PREFIX = 'lock__';

    private TagAwareAdapterInterface $backend;

    public function __construct(string|TagAwareAdapterInterface $backend = 'filesystem')
    {
        if ($backend instanceof TagAwareAdapterInterface) {
            $this->backend = $backend;
        } else {
            $this->backend = match ($backend) {
                'redis' => BackendFactory::redis(),
                default => BackendFactory::filesystem(),
            };
        }
    }

    public function get(string $key): ISRCacheEntry|VaryMarker|null
    {
        $item = $this->backend->getItem($this->sanitize($key));
        if (!$item->isHit()) {
            return null;
        }
        $value = $item->get();
        return $value instanceof ISRCacheEntry || $value instanceof VaryMarker ? $value : null;
    }

    public function set(string $key, ISRCacheEntry|VaryMarker $value, array $tags = []): void
    {
        $item = $this->backend->getItem($this->sanitize($key));
        $item->set($value);
        $entryTags = $value instanceof ISRCacheEntry ? $value->tags : [];
        $allTags = array_values(array_unique(array_merge($entryTags, $tags)));
        if ($allTags !== []) {
            $item->tag(array_map([$this, 'sanitize'], $allTags));
        }
        $hardMax = (int)($value->ttl + 86400 * 7);
        $item->expiresAfter($hardMax > 0 ? $hardMax : null);
        $this->backend->save($item);
    }

    public function delete(string $key): void
    {
        $this->backend->deleteItem($this->sanitize($key));
    }

    public function invalidateTag(string $tag): void
    {
        $this->backend->invalidateTags([$this->sanitize($tag)]);
    }

    public function invalidateTags(array $tags): void
    {
        $this->backend->invalidateTags(array_map([$this, 'sanitize'], $tags));
    }

    public function clear(): void
    {
        $this->backend->clear();
    }

    public function lock(string $key, int $ttl = 30): bool
    {
        $lockKey = self::LOCK_PREFIX . $this->sanitize($key);
        $item = $this->backend->getItem($lockKey);
        if ($item->isHit()) {
            return false;
        }
        $item->set(time());
        $item->expiresAfter($ttl);
        return (bool)$this->backend->save($item);
    }

    public function unlock(string $key): void
    {
        $this->backend->deleteItem(self::LOCK_PREFIX . $this->sanitize($key));
    }

    public function getBackend(): TagAwareAdapterInterface
    {
        return $this->backend;
    }

    private function sanitize(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.]/', '_', $value) ?? $value;
    }
}
