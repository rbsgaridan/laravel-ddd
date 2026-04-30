<?php

namespace Incoder\DDD\Tests\Unit;

use Incoder\DDD\Support\Attributes\RouteAttribute;
use Incoder\DDD\Tests\TestCase;

class RouteAttributeTest extends TestCase
{
    public function test_it_defaults_api_routes_to_api_middleware(): void
    {
        $attribute = new RouteAttribute('GET', '/app/api/widgets');

        $this->assertSame(['api'], $attribute->middleware);
    }

    public function test_it_defaults_web_routes_to_web_middleware(): void
    {
        $attribute = new RouteAttribute('GET', '/reports');

        $this->assertSame(['web'], $attribute->middleware);
    }
}
