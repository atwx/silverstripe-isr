<?php

declare(strict_types=1);

namespace Atwx\ISR\Strategy;

class TagCollector
{
    /** @var array<string,true> */
    private array $tags = [];

    public function addTag(string $tag): void
    {
        if ($tag !== '') {
            $this->tags[$tag] = true;
        }
    }

    /**
     * @param string[] $tags
     */
    public function addTags(array $tags): void
    {
        foreach ($tags as $t) {
            $this->addTag((string)$t);
        }
    }

    /**
     * @return string[]
     */
    public function all(): array
    {
        return array_keys($this->tags);
    }

    public function reset(): void
    {
        $this->tags = [];
    }
}
