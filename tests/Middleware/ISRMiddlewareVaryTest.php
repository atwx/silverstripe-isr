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

class ISRMiddlewareVaryTest extends SapphireTest
{
    private function makeMiddleware(): ISRMiddleware
    {
        $cache = new ISRCache(new TagAwareAdapter(new ArrayAdapter(), new ArrayAdapter()));
        return new ISRMiddleware($cache, new DefaultCacheKeyResolver());
    }

    private function requestWithLang(string $url, string $lang): HTTPRequest
    {
        $req = new HTTPRequest('GET', $url);
        $req->addHeader('Accept-Language', $lang);
        return $req;
    }

    public function testVaryByAcceptLanguageGivesSeparateCacheEntries(): void
    {
        $mw = $this->makeMiddleware();
        $url = '/lang-' . uniqid();
        $delegateFor = function (string $lang): callable {
            return function () use ($lang) {
                $r = HTTPResponse::create("body in $lang", 200);
                $r->addHeader('Vary', 'Accept-Language');
                return $r;
            };
        };

        $first = $mw->process($this->requestWithLang($url, 'en'), $delegateFor('en'));
        $this->assertSame('MISS', $first->getHeader('X-ISR-Cache'));

        $second = $mw->process($this->requestWithLang($url, 'de'), $delegateFor('de'));
        $this->assertSame('MISS', $second->getHeader('X-ISR-Cache'),
            'Different Accept-Language must produce a separate cache entry, not a hit on the en variant.');

        $third = $mw->process($this->requestWithLang($url, 'en'), function () {
            $this->fail('Delegate must not be called on HIT');
        });
        $this->assertSame('HIT', $third->getHeader('X-ISR-Cache'));
        $this->assertSame('body in en', (string)$third->getBody());
    }

    public function testVaryStarSkipsCachingEntirely(): void
    {
        $mw = $this->makeMiddleware();
        $url = '/uncacheable-' . uniqid();
        $delegate = function () {
            $r = HTTPResponse::create('private', 200);
            $r->addHeader('Vary', '*');
            return $r;
        };

        $mw->process(new HTTPRequest('GET', $url), $delegate);

        $calls = 0;
        $mw->process(new HTTPRequest('GET', $url), function () use (&$calls) {
            $calls++;
            return HTTPResponse::create('private', 200);
        });
        $this->assertSame(1, $calls, 'Vary: * responses must not be cached, so the next request rebuilds.');
    }
}
