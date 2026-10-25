<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Overrides the road stub for one test. The stub itself is registered
     * once in setUp() and the HTTP factory always honors the FIRST matching
     * stub — a second Http::fake() never wins — so a test that needs a
     * different answer cannot re-stub the host. It sets this instead, and the
     * standing stub delegates to it. Reset on every setUp, so one test's road
     * can never leak into the next.
     *
     * @var callable|null Receives the OSRM Request, returns an Http stub
     *                    response. PHP ignores extra arguments, so existing
     *                    single-answer closures with no parameters keep
     *                    working unchanged.
     */
    public static $osrmResponder = null;

    protected function setUp(): void
    {
        parent::setUp();

        static::$osrmResponder = null;

        /*
         * The road API is never on the test path. Without this, every save of
         * a two-stop day and every reflow preview would issue live HTTP calls
         * — slow, quota-spending, and red on a machine with no network. The
         * stub answers a fixed 8.4 km / 10 min leg for any pair of pins, which
         * is also what makes routed assertions deterministic.
         */
        Http::fake([
            'router.project-osrm.org*' => function (\Illuminate\Http\Client\Request $request) {
                $responder = static::$osrmResponder
                    ?? fn () => Http::response([
                        'code' => 'Ok',
                        'routes' => [['distance' => 8400, 'duration' => 600]],
                    ], 200);

                // The responder MAY ignore the request (every existing stub
                // does); one that needs to see the pair — a test ranking by
                // road, for instance — reads the from/to query off it.
                return $responder($request);
            },
        ]);
    }

    /**
     * Answer the road differently for one test: a custom leg, a failure, or
     * a dead host. See $osrmResponder for why this exists instead of a
     * second Http::fake().
     */
    protected function stubOsrm(callable $responder): void
    {
        static::$osrmResponder = $responder;
    }

    /**
     * An OSRM response for one pair of pins, for tests that re-stub the road.
     */
    protected function osrmResponse(int $meters, int $seconds): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response([
            'code' => 'Ok',
            'routes' => [['distance' => $meters, 'duration' => $seconds]],
        ], 200);
    }
}
