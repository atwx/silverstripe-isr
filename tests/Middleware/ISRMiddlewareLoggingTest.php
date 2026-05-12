<?php

declare(strict_types=1);

namespace Atwx\ISR\Tests\Middleware;

use Atwx\ISR\Cache\ISRCache;
use Atwx\ISR\Middleware\ISRMiddleware;
use Atwx\ISR\Strategy\DefaultCacheKeyResolver;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Dev\SapphireTest;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

class ISRMiddlewareLoggingTest extends SapphireTest
{
    private function makeMiddleware(TestHandler $handler): ISRMiddleware
    {
        $cache = new ISRCache(new TagAwareAdapter(new ArrayAdapter(), new ArrayAdapter()));
        $logger = new Logger('isr-test');
        $logger->pushHandler($handler);
        return new ISRMiddleware($cache, new DefaultCacheKeyResolver(), $logger);
    }

    public function testSecurityIDFormBodyLogsInfoAndSkipsCache(): void
    {
        $handler = new TestHandler();
        $mw = $this->makeMiddleware($handler);
        $req = new HTTPRequest('GET', '/has-form-' . uniqid());

        $delegate = fn () => HTTPResponse::create(
            '<form><input type="hidden" name="SecurityID" value="abc" /></form>',
            200
        );

        $first = $mw->process($req, $delegate);
        $this->assertSame('MISS', $first->getHeader('X-ISR-Cache'));

        $this->assertTrue($handler->hasInfoThatContains('SecurityID'),
            'Expected an info-level log record about SecurityID skip.');

        $calls = 0;
        $mw->process($req, function () use (&$calls) {
            $calls++;
            return HTTPResponse::create('<form name="SecurityID">', 200);
        });
        $this->assertSame(1, $calls, 'Delegate should rerun because the previous response was not cached.');
    }

    public function testNullLoggerByDefault(): void
    {
        $cache = new ISRCache(new TagAwareAdapter(new ArrayAdapter(), new ArrayAdapter()));
        $mw = new ISRMiddleware($cache, new DefaultCacheKeyResolver());
        $req = new HTTPRequest('GET', '/no-logger-' . uniqid());
        $resp = $mw->process($req, fn () => HTTPResponse::create('hi', 200));
        $this->assertSame('MISS', $resp->getHeader('X-ISR-Cache'));
    }
}
