<?php

use App\Http\Middleware\EnsureRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

if (! function_exists('bcadd')) {
    function bcadd($left, $right, $scale = 2) {
        return number_format((float) $left + (float) $right, (int) $scale, '.', '');
    }
}
if (! function_exists('bcsub')) {
    function bcsub($left, $right, $scale = 2) {
        return number_format((float) $left - (float) $right, (int) $scale, '.', '');
    }
}
if (! function_exists('bcmul')) {
    function bcmul($left, $right, $scale = 2) {
        return number_format((float) $left * (float) $right, (int) $scale, '.', '');
    }
}
if (! function_exists('bcdiv')) {
    function bcdiv($left, $right, $scale = 2) {
        return (float) $right == 0 ? '0.00' : number_format((float) $left / (float) $right, (int) $scale, '.', '');
    }
}
if (! function_exists('bccomp')) {
    function bccomp($left, $right, $scale = 2) {
        $diff = round((float) $left - (float) $right, (int) $scale);
        return $diff > 0 ? 1 : ($diff < 0 ? -1 : 0);
    }
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
