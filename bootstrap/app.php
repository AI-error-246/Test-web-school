<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\ApiResponseHeaders;
use App\Http\Middleware\EnsureAccount;
use App\Http\Middleware\EnsureAdminConfirmation;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrafficGuard;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            Route::get('/health', HealthController::class)->name('health');
        })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(ApiResponseHeaders::class);
        $middleware->append(TrafficGuard::class);
        $middleware->web(append: [SetLocale::class]);
        $middleware->alias(['account' => EnsureAccount::class, 'role' => EnsureRole::class, 'admin.confirm' => EnsureAdminConfirmation::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['code']);
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*', 'health', 'assistant') || $request->expectsJson() || $request->route() === null);
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            $status = $exception->getStatusCode();
            if ($request->is('api/*', 'health', 'assistant') || $request->expectsJson() || $request->route() === null) {
                if (in_array($status, [404, 405], true)) {
                    return response()->json(['error' => $status === 404 ? 'NOT_FOUND' : 'METHOD_NOT_ALLOWED'], $status, $exception->getHeaders());
                }

                return null;
            }
            if (in_array($status, [403, 404, 409, 419, 429, 503], true)) {
                return response()->view('errors.status', ['status' => $status], $status, $exception->getHeaders());
            }

            return null;
        });
        $exceptions->respond(function (Response $response): Response {
            return ApiResponseHeaders::apply($response);
        });
    })->create();
