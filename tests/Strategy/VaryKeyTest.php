<?php

declare(strict_types=1);

namespace Atwx\ISR\Tests\Strategy;

use Atwx\ISR\Strategy\VaryKey;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\SapphireTest;

class VaryKeyTest extends SapphireTest
{
    public function testNormalizeEmpty(): void
    {
        $this->assertSame([], VaryKey::normalize(''));
    }

    public function testNormalizeLowercaseAndSort(): void
    {
        $this->assertSame(
            ['accept-encoding', 'accept-language'],
            VaryKey::normalize('Accept-Language, Accept-Encoding'),
        );
    }

    public function testNormalizeDedupe(): void
    {
        $this->assertSame(['accept-language'], VaryKey::normalize('Accept-Language, accept-language'));
    }

    public function testNormalizeStarShortCircuits(): void
    {
        $this->assertSame(['*'], VaryKey::normalize('Accept-Language, *'));
    }

    public function testNormalizeDropsCookie(): void
    {
        $this->assertSame(
            ['accept-language'],
            VaryKey::normalize('Cookie, Accept-Language'),
        );
    }

    public function testExpandStableForSameHeaders(): void
    {
        $req1 = new HTTPRequest('GET', '/x');
        $req1->addHeader('Accept-Language', 'en');
        $req2 = new HTTPRequest('GET', '/x');
        $req2->addHeader('Accept-Language', 'en');

        $a = VaryKey::expand('base', ['accept-language'], $req1);
        $b = VaryKey::expand('base', ['accept-language'], $req2);
        $this->assertSame($a, $b);
        $this->assertStringStartsWith('base__v_', $a);
    }

    public function testExpandDifferentForDifferentHeaderValues(): void
    {
        $req1 = new HTTPRequest('GET', '/x');
        $req1->addHeader('Accept-Language', 'en');
        $req2 = new HTTPRequest('GET', '/x');
        $req2->addHeader('Accept-Language', 'de');

        $this->assertNotSame(
            VaryKey::expand('base', ['accept-language'], $req1),
            VaryKey::expand('base', ['accept-language'], $req2),
        );
    }
}
