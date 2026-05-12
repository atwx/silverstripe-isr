# silverstripe-isr

Incremental Static Regeneration (ISR) caching for SilverStripe CMS 6.

Short-circuits the request pipeline at the HTTPMiddleware layer: cached responses skip the controller, template, and ORM work entirely. SilverStripe itself is still booted (the middleware runs as part of `Director.Middlewares`), but on a cache hit the response is returned before any page rendering happens. Stale entries are regenerated in the background; tag-based invalidation flushes entries on publish.

## Features

- HTTP-middleware based — on a cache hit the response is replayed without invoking the controller, template, or ORM. The SilverStripe kernel still boots since the middleware is registered on `Director`.
- Stale-while-revalidate semantics: a stale entry is served immediately while a background refresh runs.
- Background revalidation via internal cURL request (works on FPM, mod_php, and CLI).
- Tag-based invalidation through Symfony `TagAwareAdapter` (filesystem or Redis backend).
- Per-page `CacheTTL` and `DisableISRCache` flags via `ISRPageExtension`.
- Generic DataObject → tag invalidation via `ISRDataObjectExtension`.
- Vary-header support: response declares `Vary: Accept-Language` → variants get separate cache entries.
- Fluent-locale aware cache keys.
- Debug headers `X-ISR-Cache: HIT|STALE|MISS|REVALIDATE` and `X-ISR-Age: <seconds>`.
- PSR-3 logging via `Psr\Log\LoggerInterface.isr` (Monolog channel `isr`).
- Hit/Miss/Stale/Revalidate counters per hour bucket, surfaced via `isr-stats` task.

## Requirements

- PHP 8.3+
- SilverStripe Framework + CMS 6.x
- `symfony/cache ^7`
- `symbiote/silverstripe-queuedjobs ^6` (only used by the QueuedJobs fallback revalidation mode)

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

## Tag invalidation via `ISRDataObjectExtension`

Apply the extension to any DataObject — write/delete (and publish/unpublish if Versioned)
automatically invalidate `{prefix}-{ID}` and `{prefix}-list` tags:

```php
class News extends DataObject
{
    private static array $extensions = [
        \Atwx\ISR\Extension\ISRDataObjectExtension::class,
    ];

    private static string $isr_tag_prefix = 'news'; // optional, defaults to lowercase short class name
}
```

In your controller, register the matching tags during rendering:

```php
public function index(HTTPRequest $request): HTTPResponse
{
    $items = News::get();
    foreach ($items as $item) {
        $item->addISRTag();      // tag: news-{ID}
    }
    $items->first()->addISRListTag(); // tag: news-list
    // ... render
}
```

When any News item is `write()`-en the cache entries carrying `news-{ID}` and `news-list`
are flushed. Other tags / pages are unaffected.

For one-off custom tags without an extension:

```php
\Atwx\ISR\Middleware\ISRMiddleware::tagCollector()->addTag('news-list');
\SilverStripe\Core\Injector\Injector::inst()
    ->get(\Atwx\ISR\Cache\ISRCache::class)
    ->invalidateTag('news-list');
```

## Fluent integration

ISR ships with first-class support for [tractorcow/silverstripe-fluent](https://github.com/tractorcow-farm/silverstripe-fluent).

**Two things you need to configure in your project:**

1. **Middleware ordering.** Fluent's `routing.yml` overwrites the
   `Director.Middlewares` property, so a plain `app/_config/isr.yml` without an
   `After: ['#fluentrouting']` directive will drop `ISRMiddleware` from the
   chain entirely. Always include both anchors:

   ```yaml
   ---
   Name: app-isr
   After:
     - '#isr'
     - '#fluentrouting'
   ---
   SilverStripe\Core\Injector\Injector:
     SilverStripe\Control\Director:
       properties:
         Middlewares:
           ISRMiddleware: '%$Atwx\ISR\Middleware\ISRMiddleware'
   ```

   With the correct order, Fluent's `InitStateMiddleware` and
   `DetectLocaleMiddleware` run first → `FluentState` knows the locale →
   `DefaultCacheKeyResolver` folds that locale into the cache key
   (`isr_v1_{locale}_{hash}`). en and de variants of the same URL land under
   different keys automatically.

2. **The `FluentLocale` cookie.** Fluent's `DetectLocaleMiddleware` calls
   `Cookie::set()` on every request, which in SilverStripe 6 writes through
   PHP's native `setcookie()` rather than the `HTTPResponse` object — so it
   doesn't actually block ISR's cache. If you ever route Fluent's cookie via
   `$response->addHeader('Set-Cookie', …)` directly, ISR's
   `strippable_set_cookies` config (default `[FluentLocale, FluentLocale_CMS]`)
   keeps it out of the cached entry:

   ```yaml
   Atwx\ISR\Middleware\ISRMiddleware:
     strippable_set_cookies:
       - FluentLocale
       - FluentLocale_CMS
       - YourOwnSafeCookie
   ```

   Set-Cookie headers carrying *any* cookie name not in this whitelist still
   block caching for that response (correct behaviour for session cookies).

The cached response on a HIT will not carry `Set-Cookie: FluentLocale=…`, but
Fluent re-detects the locale on every request anyway, so the persistence
cookie isn't load-bearing for correctness.

## Vary header support

If a response declares `Vary: Accept-Language` (or any list of headers), each variant gets
its own cache entry — keyed by the listed request-header values.

```php
$resp->addHeader('Vary', 'Accept-Language');
```

- `Vary: *` is honoured as "do not cache".
- `Cookie` is excluded from the Vary list (every distinct session cookie would otherwise
  create a new variant that's never re-hit).

## Logging

The module publishes a Monolog channel `isr` bound to `Psr\Log\LoggerInterface.isr`,
using a `Monolog\Handler\ErrorLogHandler` by default (so messages land in your standard
PHP error log). Override to add file/syslog/Sentry handlers:

```yaml
SilverStripe\Core\Injector\Injector:
  Psr\Log\LoggerInterface.isr:
    type: singleton
    class: Monolog\Logger
    constructor: ['isr']
    calls:
      pushFileHandler: [ pushHandler, [ '%$Monolog\Handler\StreamHandler' ] ]
```

## Dev tasks

- `vendor/bin/sake tasks:isr-purge` — clears the entire ISR cache.
- `vendor/bin/sake tasks:isr-warmup` — renders all published `SiteTree` pages once to populate the cache.
- `vendor/bin/sake tasks:isr-stats` — shows on-disk cache size plus a 24-hour per-state
  breakdown of HIT / STALE / MISS / REVALIDATE counters.

## Counters

Counts of HIT/STALE/MISS/REVALIDATE per UTC hour bucket are kept for 7 days. They use
read-modify-write on top of the cache adapter and are **approximate under high concurrency**
on filesystem-backed caches — operators who need exact counts can subclass `ISRCounters`
and use Redis `HINCRBY` directly.

## Smoke-testing

```bash
curl -i https://your.ddev.site/                # MISS on first request
curl -i https://your.ddev.site/                # HIT on second
curl -i 'https://your.ddev.site/?flush=1'      # bypass
sleep 310 && curl -i https://your.ddev.site/   # STALE + background revalidate
```

## Edge cases

- Responses containing a `SecurityID` form token are not cached (CSRF guard).
- Responses with `Set-Cookie` or `Vary: *` are never cached.
- Pages with active sessions / login cookies bypass automatically.
- `Cache-Control: no-store|private` headers from upstream are **not** treated as a cache
  bypass — they are downstream-HTTP-cache hints. ISR is a separate server-side cache that
  the operator opted into. Use `X-ISR-Bypass: 1` on the response (or `DisableISRCache` on
  a page) to opt out.

## License

BSD-3-Clause.
