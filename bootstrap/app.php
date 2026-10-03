<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\SuperAdminMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
         $middleware->alias([
        'admin' => AdminMiddleware::class,
        'super-admin' => SuperAdminMiddleware::class,
        ]);

        // Hostinger terminates TLS and proxies to PHP, so a request arrives as
        // plain HTTP from the loopback interface. Without this Laravel believes
        // it is serving over http:// and generates http:// URLs: mixed content
        // on an HTTPS page, and session cookies that will not be sent back,
        // which surfaces as a 419 on the first form post.
        //
        // '*' because the host's proxy address is not documented or stable.
        //
        // The cost is that X-Forwarded-For is client-influenced, so the login
        // throttle in LoginRequest::throttleKey() can be sidestepped by forging
        // the header. That trade is taken deliberately: not trusting it at all
        // collapses every visitor onto 127.0.0.1 as a single throttle key, so
        // one person's failed logins would lock out every user. Nothing else in
        // the app branches on client IP -- admin checks are on the session user.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
