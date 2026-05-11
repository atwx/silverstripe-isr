# CLAUDE.md — atwx/silverstripe-isr

ISR (Incremental Static Regeneration) module for SilverStripe 6.

## Architecture in one paragraph

`ISRMiddleware` sits in front of every request via `Director.Middlewares`. On a cacheable request it asks the `CacheKeyResolver` for a key, looks the entry up in `ISRCache` (Symfony `TagAwareAdapter` over filesystem or Redis), and either replays the cached `HTTPResponse` immediately (HIT), replays + schedules a background revalidation (STALE), or runs the normal pipeline + stores the response (MISS). Background revalidation prefers `fastcgi_finish_request()` + a shutdown hook, with `ISRRevalidateJob` (QueuedJobs) as a fallback. `ISRPageExtension` adds `CacheTTL` / `DisableISRCache` and registers `page-{ID}` / `parent-{ID}` tags via `ISRMiddleware::tagCollector()`. Invalidation happens through tag invalidation on publish/unpublish.

## Code layout

```
src/
  Cache/             ISRCache, ISRCacheEntry, BackendFactory
  Middleware/        ISRMiddleware (the orchestrator)
  Strategy/          CacheKeyResolver interface + DefaultCacheKeyResolver + TagCollector
  Extension/         ISRPageExtension (SiteTree)
  Job/               ISRRevalidateJob (QueuedJobs fallback)
  Task/              ISRPurgeTask, ISRWarmupTask, ISRStatsTask
tests/
  Cache/             unit tests for entry + cache
  Strategy/          unit tests for key resolver
  Middleware/        functional tests with in-memory backend
_config/isr.yml     defaults
```

## Conventions

- PSR-12, `declare(strict_types=1)` everywhere.
- Namespace `Atwx\ISR\*`.
- Logging via `error_log()` only.
- Config keys live on the class via the `Configurable` trait.

## Run tests

In the module directory:

```bash
composer install
vendor/bin/phpunit
```

When the module is symlinked into a SilverStripe project, run tests from the project root so the framework bootstrap is available:

```bash
ddev exec vendor/bin/phpunit -c vendor/atwx/silverstripe-isr/phpunit.xml.dist
```

## Smoke tests in a project

```bash
curl -i https://app.ddev.site/                  # MISS
curl -i https://app.ddev.site/                  # HIT  (X-ISR-Cache: HIT, X-ISR-Age: …)
curl -i 'https://app.ddev.site/?flush=1'        # bypass
```

## Known TODOs / future work

- Vary-header support (currently not honoured).
- Optional PSR-3 logger (currently `error_log()`).
- Hit/miss metrics for `ISRStatsTask` (counters in the cache).
- Per-DataObject mapper from class+ID → tags (currently must be wired up by hand).
