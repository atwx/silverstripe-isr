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

class ISRMiddlewareTest extends SapphireTest
{
    private function makeMiddleware(): ISRMiddleware
    {
        $cache = new ISRCache(new TagAwareAdapter(new ArrayAdapter(), new ArrayAdapter()));
        return new ISRMiddleware($cache, new DefaultCacheKeyResolver());
    }

    public function testMissThenHit(): void
    {
        $mw = $this->makeMiddleware();
        $req = new HTTPRequest('GET', '/page-' . uniqid());
        $delegate = fn () => HTTPResponse::create('rendered body', 200);

        $first = $mw->process($req, $delegate);
        $this->assertSame('MISS', $first->getHeader('X-ISR-Cache'));
        $this->assertSame('rendered body', (string)$first->getBody());

        $second = $mw->process($req, function () {
            $this->fail('Delegate must not be called on HIT');
        });
        $this->assertSame('HIT', $second->getHeader('X-ISR-Cache'));
        $this->assertSame('rendered body', (string)$second->getBody());
        $this->assertNotNull($second->getHeader('X-ISR-Age'));
    }

    public function testBypassQueryParamSkipsCache(): void
    {
        $mw = $this->makeMiddleware();
        $req = new HTTPRequest('GET', '/foo', ['flush' => '1']);
        $delegate = fn () => HTTPResponse::create('fresh', 200);
        $resp = $mw->process($req, $delegate);
        $this->assertNull($resp->getHeader('X-ISR-Cache'));
    }

    public function testExcludedPathSkipsCache(): void
    {
        $mw = $this->makeMiddleware();
        $req = new HTTPRequest('GET', '/admin/foo');
        $resp = $mw->process($req, fn () => HTTPResponse::create('admin', 200));
        $this->assertNull($resp->getHeader('X-ISR-Cache'));
    }

    public function testResponseWithSetCookieIsNotCached(): void
    {
        $mw = $this->makeMiddleware();
        $req = new HTTPRequest('GET', '/with-cookie-' . uniqid());
        $delegate = function () {
            $r = HTTPResponse::create('x', 200);
            $r->addHeader('Set-Cookie', 'foo=bar');
            return $r;
        };
        $first = $mw->process($req, $delegate);
        $this->assertSame('MISS', $first->getHeader('X-ISR-Cache'));

        $calls = 0;
        $second = $mw->process($req, function () use (&$calls) {
            $calls++;
            return HTTPResponse::create('x', 200);
        });
        $this->assertSame(1, $calls, 'Delegate should run again because response was not cached');
        $this->assertSame('MISS', $second->getHeader('X-ISR-Cache'));
    }

    public function testNonCacheableStatusIsNotStored(): void
    {
        $mw = $this->makeMiddleware();
        $req = new HTTPRequest('GET', '/500-' . uniqid());
        $delegate = fn () => HTTPResponse::create('boom', 500);
        $first = $mw->process($req, $delegate);
        $this->assertSame('MISS', $first->getHeader('X-ISR-Cache'));

        $calls = 0;
        $mw->process($req, function () use (&$calls) {
            $calls++;
            return HTTPResponse::create('boom', 500);
        });
        $this->assertSame(1, $calls);
    }
}
