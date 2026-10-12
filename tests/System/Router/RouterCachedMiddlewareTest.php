<?php declare(strict_types=1);

namespace PHP_SF\Tests\System\Router;

use PHP_SF\System\Classes\MiddlewareChecks\MiddlewareAll;
use PHP_SF\System\Classes\MiddlewareChecks\MiddlewareAny;
use PHP_SF\System\Classes\MiddlewareChecks\MiddlewareCustom;
use PHP_SF\System\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

/**
 * Regression coverage for issue #4 — a cached route decoded its `middleware`
 * (the class-name-keyed MiddlewareAll / MiddlewareAny shape) as `stdClass`,
 * tripping PhpSfContext's `array $middleware` TypeError on every cache hit.
 * Pins {@see Router::normalizeCachedMiddleware()}, the helper that repairs
 * the decoded shape.
 *
 * Calling the helper directly via reflection keeps the test focused on the
 * bug's surface without standing up a full controller + router walk.
 */
final class RouterCachedMiddlewareTest extends TestCase
{
    #[DataProvider('passThroughProvider')]
    public function testPassThrough(mixed $input): void
    {
        $this->assertSame($input, $this->normalize($input));
    }

    public static function passThroughProvider(): array
    {
        return [
            'scalar string' => ['Some\Middleware'],
            'null' => [null],
            'flat sequential array — never tripped the bug, pinned to keep a future refactor from silently breaking it' => [
                ['Auth::class', 'Api::class'],
            ],
        ];
    }

    #[DataProvider('stdClassConversionProvider')]
    public function testConvertsStdClassToArray(mixed $input, mixed $expected): void
    {
        $this->assertSame($expected, $this->normalize($input));
    }

    public static function stdClassConversionProvider(): array
    {
        return [
            'flat stdClass with string keys (JSON-object shape)' => [
                (object) ['first' => 'Auth::class', 'second' => 'Api::class'],
                ['first' => 'Auth::class', 'second' => 'Api::class'],
            ],
            'nested stdClass with class-name key (MiddlewareAll shape)' => [
                (object) [MiddlewareAll::class => ['Auth::class', 'Api::class']],
                [MiddlewareAll::class => ['Auth::class', 'Api::class']],
            ],
            'deeply nested stdClass (MiddlewareCustom shape)' => [
                (object) [
                    MiddlewareCustom::class => (object) [
                        MiddlewareAll::class => ['Auth::class'],
                        MiddlewareAny::class => (object) [
                            'Admin::class' => (object) ['noop'],
                        ],
                    ],
                ],
                [
                    MiddlewareCustom::class => [
                        MiddlewareAll::class => ['Auth::class'],
                        MiddlewareAny::class => [
                            'Admin::class' => ['noop'],
                        ],
                    ],
                ],
            ],
            'empty stdClass (JSON `{}` shape)' => [
                (object) [],
                [],
            ],
            'array of stdClass siblings (mixed array/object children)' => [
                [(object) ['x' => 1], (object) ['y' => 2]],
                [['x' => 1], ['y' => 2]],
            ],
        ];
    }

    /**
     * End-to-end against real j_encode / j_decode — the only test that exercises
     * the production round-trip, where the bug actually surfaced.
     */
    public function testRoundTripsThroughJEncodeJDecode(): void
    {
        $original = [
            MiddlewareAll::class => ['Auth::class', 'Api::class'],
        ];

        $encoded = j_encode($original);
        $this->assertIsString($encoded, 'j_encode must succeed on the round-trip input');
        $decoded = j_decode($encoded); // no assoc → stdClass
        $repaired = $this->normalize($decoded);

        $this->assertIsArray($repaired, 'middleware must be an array after repair');
        $this->assertArrayHasKey(MiddlewareAll::class, $repaired);
        $this->assertSame(['Auth::class', 'Api::class'], $repaired[MiddlewareAll::class]);
        $this->assertNoStdClass($repaired, 'normalisation must reach every nested node');
    }

    /**
     * Pins the actual fix — `Router::setCurrentRoute()` is invoked via reflection with a
     * pre-populated cache payload whose `middleware` is the class-keyed shape. The call
     * site that invokes the helper (cache-hit branch) is the whole fix; if that line is
     * ever deleted, this test restores the production TypeError.
     */
    public function testCacheHitBranchRepairsMiddlewareAtCallSite(): void
    {
        $savedRoute = Router::$currentRoute;
        $savedServer = $_SERVER;
        $savedRoutesByUrl = (new ReflectionProperty(Router::class, 'routesByUrl'))->getValue(null);

        try {
            // setCurrentRoute() rejects unknown HTTP methods with a 405 + exit() before
            // even reading the cache, so seed a minimal routes-by-url entry for GET.
            (new ReflectionProperty(Router::class, 'routesByUrl'))
                ->setValue(null, ['GET' => ['/cache-hit-test' => ['url' => '/cache-hit-test']]]);

            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/cache-hit-test';

            $cachedRoute = (object) [
                'url' => '/cache-hit-test',
                'class' => 'SomeController',
                'method' => 'action',
                'name' => 'cache_hit_test',
                'httpMethod' => 'GET',
                'middleware' => [MiddlewareAll::class => ['Auth::class', 'Api::class']],
                'routeParams' => [],
                'api' => false,
            ];

            $schema = (new ReflectionClass(Router::class))->getReflectionConstant('ROUTE_CACHE_SCHEMA')->getValue();
            $urlHash = hash('sha256', '/cache-hit-test');

            ca()->set(sprintf('parsed_url:%s:%s%s', 'GET', $urlHash, $schema), '/cache-hit-test');
            ca()->set(sprintf('parsed_url:%s:route:%s%s', 'GET', $urlHash, $schema), j_encode($cachedRoute));
            ca()->set(sprintf('parsed_url:%s:route_params:%s%s', 'GET', $urlHash, $schema), j_encode([]));

            $matched = (new ReflectionMethod(Router::class, 'setCurrentRoute'))->invoke(null);

            $this->assertTrue($matched, 'cache-hit branch must report a match');
            $this->assertIsObject(Router::$currentRoute);
            $this->assertIsArray(
                Router::$currentRoute->middleware,
                'middleware must be a plain array after the cache-hit repair — stdClass would re-introduce issue #4',
            );
            $this->assertArrayHasKey(MiddlewareAll::class, Router::$currentRoute->middleware);
            $this->assertNoStdClass(Router::$currentRoute->middleware);
        } finally {
            Router::$currentRoute = $savedRoute;
            $_SERVER = $savedServer;
            (new ReflectionProperty(Router::class, 'routesByUrl'))->setValue(null, $savedRoutesByUrl);
        }
    }

    /**
     * Recursively asserts that no `stdClass` instance survives anywhere inside `$value`.
     * `assertSame` on the top level alone would let a "unwrap only top-level stdClass"
     * regression slip through; this proves the recursion reaches every nested node.
     */
    private function assertNoStdClass(mixed $value, string $message = ''): void
    {
        if ($value instanceof stdClass) {
            $this->fail($message ?: 'stdClass leaked through the normalisation');
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                $this->assertNoStdClass($v, $message);
            }
        }
    }

    /**
     * Invoke the protected helper via reflection — keeps the production API closed.
     */
    private function normalize(mixed $middleware): mixed
    {
        return (new ReflectionMethod(Router::class, 'normalizeCachedMiddleware'))
            ->invoke(null, $middleware);
    }
}
