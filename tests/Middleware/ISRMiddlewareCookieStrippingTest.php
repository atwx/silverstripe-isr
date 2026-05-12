<?php

declare(strict_types=1);

namespace Atwx\ISR\Tests\Middleware;

use Atwx\ISR\Cache\ISRCache;
use Atwx\ISR\Middleware\ISRMiddleware;
use Atwx\ISR\Strategy\DefaultCacheKeyResolver;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Dev\SapphireTest;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

class ISRMiddlewareCookieStrippingTest extends SapphireTest
{
    private function makeMiddleware(): ISRMiddleware
    {
        $cache = new ISRCache(new TagAwareAdapter(new ArrayAdapter(), new ArrayAdapter()));
        return new ISRMiddleware($cache, new DefaultCacheKeyResolver());
    }

    public function testStrippableCookieOnlyResponseIsCached(): void
    {
        ISRMiddleware::config()->set('strippable_set_cookies', ['FluentLocale']);
        $mw = $this->makeMiddleware();
        $url = '/strip-' . uniqid();

        $delegate = function () {
            $r = HTTPResponse::create('body', 200);
            $r->addHeader('Set-Cookie', 'FluentLocale=de_DE; path=/; expires=…');
            return $r;
        };

        $first = $mw->process(new HTTPRequest('GET', $url), $delegate);
        $this->assertSame('MISS', $first->getHeader('X-ISR-Cache'));

        $second = $mw->process(new HTTPRequest('GET', $url), function () {
            $this->fail('Delegate must not be called — second request should HIT.');
        });
        $this->assertSame('HIT', $second->getHeader('X-ISR-Cache'));
        $this->assertNull($second->getHeader('Set-Cookie'),
            'Replayed cached response must not carry the stripped FluentLocale cookie.');
        $this->assertSame('body', (string)$second->getBody());
    }

    public function testNonWhitelistedCookieBlocksCache(): void
    {
        ISRMiddleware::config()->set('strippable_set_cookies', ['FluentLocale']);
        $mw = $this->makeMiddleware();
        $url = '/block-' . uniqid();

        $delegate = function () {
            $r = HTTPResponse::create('body', 200);
            $r->addHeader('Set-Cookie', 'SomethingElse=value; path=/');
            return $r;
        };

        $first = $mw->process(new HTTPRequest('GET', $url), $delegate);
        $this->assertSame('MISS', $first->getHeader('X-ISR-Cache'));

        $calls = 0;
        $mw->process(new HTTPRequest('GET', $url), function () use (&$calls) {
            $calls++;
            return HTTPResponse::create('body', 200);
        });
        $this->assertSame(1, $calls, 'Non-whitelisted cookie must keep the cache empty.');
    }

    public function testParserHandlesArrayValue(): void
    {
        // Reach the private parser directly via reflection: validates that an array Set-Cookie
        // header (which can happen if downstream framework code passes multiple lines as an
        // array) is split into separate cookie names, not treated as a single concatenated value.
        $mw = $this->makeMiddleware();
        $ref = new \ReflectionMethod($mw, 'parseSetCookieNames');
        $ref->setAccessible(true);

        $names = $ref->invoke($mw, ['FluentLocale=de_DE; path=/', 'PHPSESSID=abc; secure']);
        $this->assertSame(['FluentLocale', 'PHPSESSID'], $names);
    }

    public function testEmptyWhitelistKeepsLegacyBehaviour(): void
    {
        ISRMiddleware::config()->set('strippable_set_cookies', []);
        $mw = $this->makeMiddleware();
        $url = '/legacy-' . uniqid();

        $delegate = function () {
            $r = HTTPResponse::create('body', 200);
            $r->addHeader('Set-Cookie', 'FluentLocale=de_DE');
            return $r;
        };

        $first = $mw->process(new HTTPRequest('GET', $url), $delegate);
        $this->assertSame('MISS', $first->getHeader('X-ISR-Cache'));

        $calls = 0;
        $mw->process(new HTTPRequest('GET', $url), function () use (&$calls) {
            $calls++;
            return HTTPResponse::create('body', 200);
        });
        $this->assertSame(1, $calls, 'With an empty whitelist any Set-Cookie must block caching.');
    }
}
