<?php

declare(strict_types=1);

namespace Atwx\ISR\Strategy;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;

class DefaultCacheKeyResolver implements CacheKeyResolver
{
    use Configurable;

    private const VERSION = 'v1';

    /**
     * @config
     * @var string[]
     */
    private static array $whitelist_query_params = [];

    public function keyFor(HTTPRequest $request): string
    {
        $path = '/' . trim($request->getURL(false), '/');
        $params = $this->filteredQueryParams($request);
        ksort($params);
        $serialized = $path . '?' . http_build_query($params);
        $hash = substr(hash('sha256', $serialized), 0, 24);
        $locale = $this->resolveLocale();
        return sprintf('isr_%s_%s_%s', self::VERSION, $locale, $hash);
    }

    public function tagsFor(HTTPRequest $request): array
    {
        return [
            'locale-' . $this->resolveLocale(),
        ];
    }

    private function filteredQueryParams(HTTPRequest $request): array
    {
        $whitelist = (array)static::config()->get('whitelist_query_params');
        $get = $request->getVars();
        unset($get['url']);
        if ($whitelist === []) {
            return [];
        }
        return array_intersect_key($get, array_flip($whitelist));
    }

    private function resolveLocale(): string
    {
        $class = 'TractorCow\\Fluent\\State\\FluentState';
        if (class_exists($class)) {
            $state = $class::singleton();
            $locale = method_exists($state, 'getLocale') ? $state->getLocale() : null;
            if (is_string($locale) && $locale !== '') {
                return $locale;
            }
        }
        return 'default';
    }
}
