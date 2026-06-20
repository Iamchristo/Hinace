<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Auth\AuthService;
use App\Core\Http\Request;
use App\Core\Http\Response;

final class AuthMiddleware
{
    public const SESSION_COOKIE = 'hinace_session';

    public function __construct(private readonly AuthService $auth)
    {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $token = $request->cookies[self::SESSION_COOKIE] ?? null;
        $user = $token !== null ? $this->auth->validateSession($token) : null;

        if ($user === null) {
            return Response::redirect('/login');
        }

        $request->user = $user;

        return $next($request);
    }
}
