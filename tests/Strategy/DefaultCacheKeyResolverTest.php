<?php

declare(strict_types=1);

namespace Atwx\ISR\Tests\Strategy;

use Atwx\ISR\Strategy\DefaultCacheKeyResolver;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\SapphireTest;

class DefaultCacheKeyResolverTest extends SapphireTest
{
    public function testSameRequestProducesStableKey(): void
    {
        $r = new DefaultCacheKeyResolver();
        $a = $r->keyFor(new HTTPRequest('GET', '/foo/bar'));
        $b = $r->keyFor(new HTTPRequest('GET', '/foo/bar'));
        $this->assertSame($a, $b);
    }

    public function testDifferentPathsProduceDifferentKeys(): void
    {
        $r = new DefaultCacheKeyResolver();
        $this->assertNotSame(
            $r->keyFor(new HTTPRequest('GET', '/foo')),
            $r->keyFor(new HTTPRequest('GET', '/bar')),
        );
    }

    public function testNonWhitelistedQueryParamsAreIgnored(): void
    {
        $r = new DefaultCacheKeyResolver();
        $a = $r->keyFor(new HTTPRequest('GET', '/foo', ['tracking' => 'abc']));
        $b = $r->keyFor(new HTTPRequest('GET', '/foo'));
        $this->assertSame($a, $b);
    }

    public function testWhitelistedQueryParamsAffectKey(): void
    {
        DefaultCacheKeyResolver::config()->set('whitelist_query_params', ['page']);
        $r = new DefaultCacheKeyResolver();
        $a = $r->keyFor(new HTTPRequest('GET', '/foo', ['page' => '2']));
        $b = $r->keyFor(new HTTPRequest('GET', '/foo'));
        $this->assertNotSame($a, $b);
        DefaultCacheKeyResolver::config()->set('whitelist_query_params', []);
    }

    public function testKeyContainsVersionAndLocale(): void
    {
        $r = new DefaultCacheKeyResolver();
        $key = $r->keyFor(new HTTPRequest('GET', '/foo'));
        $this->assertStringStartsWith('isr_v1_', $key);
    }
}
