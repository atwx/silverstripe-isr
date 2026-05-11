<?php

declare(strict_types=1);

namespace Atwx\ISR\Strategy;

use SilverStripe\Control\HTTPRequest;

interface CacheKeyResolver
{
    public function keyFor(HTTPRequest $request): string;

    /**
     * @return string[]
     */
    public function tagsFor(HTTPRequest $request): array;
}
