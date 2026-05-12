# CLAUDE.md — atwx/silverstripe-isr

ISR (Incremental Static Regeneration) module for SilverStripe 6.

## Architecture in one paragraph

`ISRMiddleware` sits in front of every request via `Director.Middlewares`. On a cacheable
request it asks the `CacheKeyResolver` for a key, looks the entry up in `ISRCache` (Symfony
`TagAwareAdapter` over filesystem or Redis). The returned value is either an
`ISRCacheEntry` (cached response) or a `VaryMarker` (placeholder pointing at variant keys
when the response declared a `Vary` header). The middleware then replays the cached
`HTTPResponse` immediately (HIT), replays + schedules a background revalidation (STALE),
or runs the normal pipeline + stores the response (MISS). Background revalidation uses an
internal cURL request marked with `X-ISR-Internal: 1` (header bypasses cache lookup but
still stores). `ISRPageExtension` adds `CacheTTL`/`DisableISRCache` and registers
`page-{ID}`/`parent-{ID}` tags. `ISRDataObjectExtension` does the same for arbitrary
DataObjects via `{prefix}-{ID}` and `{prefix}-list`. State transitions are counted by
`ISRCounters` (per UTC hour bucket); logging goes through `Psr\Log\LoggerInterface.isr`.

## Code layout

```
src/
  Cache/             ISRCache, ISRCacheEntry, VaryMarker, ISRCounters, BackendFactory
  Middleware/        ISRMiddleware (the orchestrator)
  Strategy/          CacheKeyResolver + DefaultCacheKeyResolver + TagCollector + VaryKey
  Extension/         ISRPageExtension (SiteTree), ISRDataObjectExtension (DataObject)
  Job/               ISRRevalidateJob (QueuedJobs fallback)
  Task/              ISRPurgeTask, ISRWarmupTask, ISRStatsTask
tests/
  Cache/             unit tests for entry + cache + counters
  Strategy/          unit tests for key resolver + Vary
  Middleware/        functional tests with in-memory backend (HIT/STALE/MISS, logging, Vary)
  Extension/         ISRDataObjectExtension tests (no DB, uses Config::modify())
_config/isr.yml     defaults — Injector wiring, Monolog channel, middleware config
```

## Conventions

- PSR-12, `declare(strict_types=1)` everywhere.
- Namespace `Atwx\ISR\*`.
- Logging via `LoggerInterface.isr` (channel `isr`, ErrorLogHandler by default).
- Config keys live on the class via the `Configurable` trait.

## Run tests

In the module directory:

```bash
composer install
vendor/bin/phpunit
```

When the module is installed into a SilverStripe project, run tests from the project root
so the framework bootstrap is available. The first run needs a flush flag:

```bash
ddev exec "SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit \
  --bootstrap /var/www/html/vendor/silverstripe/framework/tests/bootstrap.php \
  /var/www/html/vendor/atwx/silverstripe-isr/tests"
```

## Smoke tests in a project

```bash
curl -i https://app.ddev.site/                  # MISS
curl -i https://app.ddev.site/                  # HIT  (X-ISR-Cache: HIT, X-ISR-Age: …)
curl -i 'https://app.ddev.site/?flush=1'        # bypass

# Stats incl. counters
ddev exec vendor/bin/sake tasks:isr-stats
```

## v1.1 — open items resolved

- ✅ PSR-3 logger via `Psr\Log\LoggerInterface.isr`
- ✅ Vary-header support (two-tier marker)
- ✅ DataObject tag mapper (`ISRDataObjectExtension`)
- ✅ Hit/Miss/Stale/Revalidate counters in `ISRStatsTask`

## Fluent integration

Two things projects need:

1. `app/_config/isr.yml` must declare `After: ['#fluentrouting']`. Without it,
   Fluent's `routing.yml` overrides `Director.Middlewares` (it sets the
   property as a map) and ISR silently drops out of the chain — symptom is
   `X-ISR-Cache` headers disappearing entirely.
2. `strippable_set_cookies` config lists cookie names that may be dropped
   from a cacheable response (default `FluentLocale, FluentLocale_CMS`).
   Fluent in SS6 mostly bypasses the `HTTPResponse` Set-Cookie path via PHP's
   native `setcookie()`, so this rarely fires — but it's defensive coverage
   for projects that route cookies through the response object.

`DefaultCacheKeyResolver::resolveLocale()` reads `FluentState::singleton()->getLocale()`
on every request and folds it into the key, so en/de variants always land
under different cache entries — no Vary handling needed for the locale axis.

## Possible v2 directions

- Per-route counter breakdown (currently flat per state).
- Atomic Redis counter path on top of the existing `ISRCounters` base class.
- Optional handler factory pattern for the Monolog channel.
- Per-DataObject opt-out flag analogous to `DisableISRCache` on pages.
