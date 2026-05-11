# silverstripe-isr

Incremental Static Regeneration (ISR) caching for SilverStripe CMS 6.

Serves cached page output in a few milliseconds without booting SilverStripe, regenerates stale entries in the background, and invalidates cache entries on publish via tag-based dependencies.

## Features

- HTTP-middleware based — full responses are cached and replayed without touching SilverStripe internals on a cache hit.
- Stale-while-revalidate semantics: a stale entry is served immediately while a background refresh runs.
- Background revalidation via `fastcgi_finish_request()` on FPM. Falls back to QueuedJobs on non-FPM SAPIs.
- Tag-based invalidation through Symfony `TagAwareAdapter` (filesystem or Redis backend).
- Per-page `CacheTTL` and `DisableISRCache` flags via `ISRPageExtension`.
- Fluent-locale aware cache keys.
- Debug headers `X-ISR-Cache: HIT|STALE|MISS` and `X-ISR-Age: <seconds>`.

## Requirements

- PHP 8.3+
- SilverStripe Framework + CMS 6.x
- `symfony/cache ^7`
- `symbiote/silverstripe-queuedjobs ^5` (for the non-FPM revalidation fallback)

## Installation

```bash
composer require atwx/silverstripe-isr
```

Then enable the middleware in your project's YAML config:

```yaml
SilverStripe\Core\Injector\Injector:
  SilverStripe\Control\Director:
    properties:
      Middlewares:
        ISRMiddleware: '%$Atwx\ISR\Middleware\ISRMiddleware'

SilverStripe\CMS\Model\SiteTree:
  extensions:
    - Atwx\ISR\Extension\ISRPageExtension
```

Run `dev/build?flush=1` once to add the new DB columns (`CacheTTL`, `DisableISRCache`).

## Configuration

All knobs live on `Atwx\ISR\Middleware\ISRMiddleware`:

```yaml
Atwx\ISR\Middleware\ISRMiddleware:
  default_ttl: 300            # default cache lifetime (seconds)
  stale_grace: 3600           # serve stale + revalidate up to this many seconds past TTL
  hard_max_age: 86400         # entries older than this are never served, always re-rendered
  cacheable_methods: [GET, HEAD]
  cacheable_status_codes: [200, 404]
  bypass_cookies: [PHPSESSID, login_session, bypass-cache]
  bypass_query_params: [flush, stage]
  excluded_paths: [/admin, /Security, /dev, /api]
  revalidation_mode: auto     # auto | shutdown | queue
  lock_ttl: 30
```

Switch to the Redis backend:

```yaml
SilverStripe\Core\Injector\Injector:
  Atwx\ISR\Cache\ISRCache:
    constructor:
      backend: 'redis'
```

Set `ISR_REDIS_DSN` in your environment (defaults to `redis://localhost:6379`).

## CacheTTL semantics

- `0` (default) — use `ISRMiddleware.default_ttl`.
- `> 0` — explicit TTL for this page.
- `-1` — never cache this page (same as `DisableISRCache = true`).

## Custom tags

```php
use Atwx\ISR\Middleware\ISRMiddleware;

ISRMiddleware::tagCollector()->addTag('news-list');
ISRMiddleware::tagCollector()->addTag('news-' . $newsItem->ID);
```

On write of a `News` record, invalidate them:

```php
public function onAfterWrite(): void
{
    parent::onAfterWrite();
    Injector::inst()->get(\Atwx\ISR\Cache\ISRCache::class)
        ->invalidateTag('news-' . $this->ID);
}
```

## Dev tasks

- `vendor/bin/sake tasks:isr-purge` — clears the entire ISR cache.
- `vendor/bin/sake tasks:isr-warmup` — renders all published `SiteTree` pages once to populate the cache.
- `vendor/bin/sake tasks:isr-stats` — shows cache size on disk (filesystem backend only).

## Smoke-testing

```bash
curl -i https://your.ddev.site/                # MISS on first request
curl -i https://your.ddev.site/                # HIT on second
curl -i 'https://your.ddev.site/?flush=1'      # bypass
sleep 310 && curl -i https://your.ddev.site/   # STALE + background revalidate
```

## Edge cases

- Responses containing a `SecurityID` form token are not cached (CSRF guard).
- Responses with `Set-Cookie` or `Cache-Control: no-store|private` are never cached.
- Pages with active sessions / login cookies bypass automatically.

## License

BSD-3-Clause.
