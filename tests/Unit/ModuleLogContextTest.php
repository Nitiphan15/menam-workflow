<?php

namespace Tests\Unit;

use App\Support\ModuleLogContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;

class ModuleLogContextTest extends TestCase
{
    /** @dataProvider moduleRoutes */
    public function test_it_maps_only_supported_module_routes(string $route, ?string $expected): void
    {
        $this->assertSame($expected, ModuleLogContext::moduleForRouteName($route));
    }

    public function moduleRoutes(): array
    {
        return [
            ['fc.input.save', 'FC'],
            ['pp.store', 'PP'],
            ['wocr.planner_action', 'WOCR'],
            ['dp.inquiry.postpone', 'DP'],
            ['po.index', null],
        ];
    }

    public function test_it_keeps_only_safe_scalar_identifiers(): void
    {
        $request = Request::create('/dp/12', 'POST', [
            'mfg_no' => 'G2600001',
            'site' => 'WIRE',
            'password' => 'must-not-be-logged',
            'items' => [['secret' => 'must-not-be-logged']],
        ]);
        $route = new Route('POST', '/dp/{ordId}', fn () => null);
        $route->bind($request);
        $route->setParameter('ordId', 12);
        $request->setRouteResolver(fn () => $route);

        $this->assertSame([
            'ord_id' => '12',
            'mfg_no' => 'G2600001',
            'site' => 'WIRE',
        ], ModuleLogContext::identifiers($request));
    }

    public function test_it_rejects_unsafe_incoming_request_id(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_X_REQUEST_ID' => "bad\nvalue"]);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            ModuleLogContext::requestId($request)
        );
    }

    public function test_it_redacts_secrets_from_exception_messages(): void
    {
        $message = ModuleLogContext::exceptionMessage(
            new \RuntimeException('Connection failed password=hunter2 token:abc123')
        );

        $this->assertSame(
            'Connection failed password=[REDACTED] token=[REDACTED]',
            $message
        );
    }
}
