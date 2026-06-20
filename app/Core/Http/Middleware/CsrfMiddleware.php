<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\Csrf;
use App\Core\Http\Request;
use App\Core\Http\Response;

final class CsrfMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        if ($request->method === 'POST' && !Csrf::verify($request)) {
            return Response::text('Invalid or missing CSRF token.', 419);
        }

        return $next($request);
    }
}
