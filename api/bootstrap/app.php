<?php

use App\Exceptions\InvalidStatusTransition;
use App\Http\Middleware\BindTenant;
use App\Http\Middleware\DemoReadOnly;
use App\Http\Middleware\RequireRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']])
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA auth: requests from SANCTUM_STATEFUL_DOMAINS get session cookies + CSRF.
        $middleware->statefulApi();
        $middleware->alias(['tenant' => BindTenant::class, 'role' => RequireRole::class, 'demo.readonly' => DemoReadOnly::class]);
        // Tenant must be bound before route-model binding resolves {document}.
        $middleware->prependToPriorityList(SubstituteBindings::class, BindTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $error = fn (int $status, string $code, string $title, ?string $detail = null, array $extra = []) => response()->json([
            'errors' => [array_filter(['status' => (string) $status, 'code' => $code, 'title' => $title, 'detail' => $detail] + $extra, fn ($v) => $v !== null)],
        ], $status);

        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->expectsJson());

        $exceptions->render(function (ValidationException $e, Request $r) {
            if (! $r->is('api/*')) {
                return null;
            }
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $errors[] = ['status' => '422', 'code' => 'validation_failed', 'title' => 'Invalid attribute', 'detail' => $m, 'source' => ['pointer' => '/'.str_replace('.', '/', $field)]];
                }
            }

            return response()->json(['errors' => $errors], 422);
        });
        $exceptions->render(fn (AuthenticationException $e, Request $r) => $r->is('api/*') ? $error(401, 'unauthenticated', 'Unauthenticated') : null);
        $exceptions->render(fn (InvalidStatusTransition $e, Request $r) => $error(409, 'invalid_transition', 'Invalid status transition', $e->getMessage()));
        $exceptions->render(function (NotFoundHttpException $e, Request $r) use ($error) {
            if (! $r->is('api/*')) {
                return null;
            }

            return $e->getPrevious() instanceof ModelNotFoundException
                ? $error(404, 'not_found', 'Resource not found')
                : $error(404, 'route_not_found', 'Endpoint not found');
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $r) use ($error) {
            if (! $r->is('api/*')) {
                return null;
            }

            // Policy denials (AuthorizationException → 403) carry a sentence for the person.
            if ($e->getStatusCode() === 403) {
                return $error(403, 'forbidden', 'Not allowed', $e->getMessage() ?: null);
            }

            return $error($e->getStatusCode(), 'http_'.$e->getStatusCode(), $e->getMessage() ?: 'HTTP error');
        });
    })->create();
