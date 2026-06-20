<?php

declare(strict_types=1);

use App\Core\Audit\AuditLogger;
use App\Core\Auth\AdminAuthService;
use App\Core\Auth\AuthService;
use App\Core\Db\Connection;
use App\Core\Http\Csrf;
use App\Core\Http\Middleware\AuthMiddleware;
use App\Core\Http\Middleware\ModuleAccessMiddleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Ledger\LedgerService;
use App\Core\Ledger\WalletService;
use App\Core\Support\Money;
use App\Core\Support\View;
use App\Modules\Admin\Service\UserComplianceService;
use App\Modules\Admin\Service\WithdrawalApprovalService;

/** @var Router $router */

$pdo = Connection::get();
$auth = new AuthService($pdo, (int) ($_ENV['SESSION_LIFETIME_MINUTES'] ?? 120));
$adminAuth = new AdminAuthService($pdo);
$wallets = new WalletService($pdo);
$ledger = new LedgerService($pdo, $wallets);
$auditLogger = new AuditLogger($pdo);
$compliance = new UserComplianceService($pdo, $wallets, $auditLogger);
$withdrawals = new WithdrawalApprovalService($pdo, $ledger, $auditLogger);
$view = new View(__DIR__ . '/Views');

$authMiddleware = new AuthMiddleware($auth);

// --- Public marketing site (Phase 0: just the home page; the full ~95-page
// site is built out in Phase 5 once the product surface exists to describe). ---
$router->get('/', function (Request $request) use ($view) {
    return Response::html($view->render('marketing/home'));
});

// --- Auth ---
$router->get('/login', function (Request $request) use ($view) {
    return Response::html($view->render('shared/login'));
});

$router->post('/login', function (Request $request) use ($auth) {
    if (!Csrf::verify($request)) {
        return Response::text('Invalid or missing CSRF token.', 419);
    }

    $user = $auth->attemptLogin((string) $request->input('email', ''), (string) $request->input('password', ''));
    if ($user === null) {
        return Response::html('<p>Invalid credentials. <a href="/login">Try again</a></p>', 401);
    }

    $token = $auth->createSession((int) $user['id'], $request->ip(), $request->userAgent());
    setcookie(AuthMiddleware::SESSION_COOKIE, $token, [
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => ($_SERVER['HTTPS'] ?? '') !== '',
        'path' => '/',
    ]);

    return Response::redirect('/dashboard');
});

$router->post('/logout', function (Request $request) use ($auth) {
    $token = $request->cookies[AuthMiddleware::SESSION_COOKIE] ?? null;
    if ($token !== null) {
        $auth->revokeSession($token);
        setcookie(AuthMiddleware::SESSION_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    }

    return Response::redirect('/login');
}, [$authMiddleware]);

// --- Shared dashboard shell (module picker, internal transfer, unified wallet view) ---
$router->group([$authMiddleware], function (Router $router) use ($view, $wallets, $ledger) {
    $router->get('/dashboard', function (Request $request) use ($view) {
        return Response::html($view->render('shared/dashboard_picker', ['user' => $request->user]));
    });

    $router->get('/dashboard/wallet', function (Request $request) use ($view, $wallets) {
        $balances = [];
        foreach (WalletService::LEDGER_TYPES as $ledgerType) {
            $balances[$ledgerType] = $wallets->getOrCreateWallet((int) $request->user['id'], $ledgerType);
        }

        return Response::html($view->render('shared/wallet', ['user' => $request->user, 'balances' => $balances]));
    });

    $router->get('/dashboard/transfer', function (Request $request) use ($view, $wallets) {
        $balances = [];
        foreach (WalletService::LEDGER_TYPES as $ledgerType) {
            $balances[$ledgerType] = $wallets->getOrCreateWallet((int) $request->user['id'], $ledgerType);
        }

        return Response::html($view->render('shared/transfer', ['user' => $request->user, 'balances' => $balances, 'error' => null]));
    });

    $router->post('/dashboard/transfer', function (Request $request) use ($view, $wallets, $ledger) {
        if (!Csrf::verify($request)) {
            return Response::text('Invalid or missing CSRF token.', 419);
        }

        $from = (string) $request->input('from_ledger', '');
        $to = (string) $request->input('to_ledger', '');
        $amount = (string) $request->input('amount', '0');

        try {
            $ledger->transferInternal((int) $request->user['id'], $from, $to, Money::fromString($amount));

            return Response::redirect('/dashboard/wallet');
        } catch (\Throwable $e) {
            $balances = [];
            foreach (WalletService::LEDGER_TYPES as $ledgerType) {
                $balances[$ledgerType] = $wallets->getOrCreateWallet((int) $request->user['id'], $ledgerType);
            }

            return Response::html($view->render('shared/transfer', [
                'user' => $request->user,
                'balances' => $balances,
                'error' => $e->getMessage(),
            ]), 422);
        }
    });

    // Module landing stubs - full feature sets ship in Phases 1-3.
    foreach (['investment', 'forex', 'realestate'] as $module) {
        $router->get("/dashboard/{$module}", function (Request $request) use ($view, $module) {
            return Response::html($view->render('shared/module_stub', ['user' => $request->user, 'module' => $module]));
        }, [new ModuleAccessMiddleware($wallets, $module === 'realestate' ? 'realestate' : $module)]);
    }
});

// --- Admin (Phase 0: login + the compliance actions the ledger core already supports) ---
$router->get('/admin/login', function (Request $request) use ($view) {
    return Response::html($view->render('admin/login'));
});

$router->post('/admin/login', function (Request $request) use ($adminAuth) {
    if (!Csrf::verify($request)) {
        return Response::text('Invalid or missing CSRF token.', 419);
    }

    $admin = $adminAuth->attemptLogin((string) $request->input('email', ''), (string) $request->input('password', ''));
    if ($admin === null) {
        return Response::html('<p>Invalid credentials. <a href="/admin/login">Try again</a></p>', 401);
    }

    $token = $adminAuth->createSession((int) $admin['id'], $request->ip(), $request->userAgent());
    setcookie('hinace_admin_session', $token, [
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => ($_SERVER['HTTPS'] ?? '') !== '',
        'path' => '/',
    ]);

    return Response::redirect('/admin');
});

$adminAuthMiddleware = function (Request $request, callable $next) use ($adminAuth) {
    $token = $request->cookies['hinace_admin_session'] ?? null;
    $admin = $token !== null ? $adminAuth->validateSession($token) : null;

    if ($admin === null) {
        return Response::redirect('/admin/login');
    }

    $request->user = $admin;

    return $next($request);
};

$router->group([$adminAuthMiddleware], function (Router $router) use ($view, $pdo, $compliance, $withdrawals) {
    $router->get('/admin', function (Request $request) use ($view, $pdo) {
        $pendingWithdrawals = $pdo->query(
            'SELECT w.*, u.email FROM withdrawal_requests w JOIN users u ON u.id = w.user_id WHERE w.status = "pending" ORDER BY w.requested_at'
        )->fetchAll();

        $users = $pdo->query('SELECT id, email, status, kyc_tier FROM users WHERE email != "system@hinace.internal" ORDER BY id DESC LIMIT 50')->fetchAll();

        return Response::html($view->render('admin/dashboard', [
            'admin' => $request->user,
            'pendingWithdrawals' => $pendingWithdrawals,
            'users' => $users,
        ]));
    });

    $router->post('/admin/withdrawals/{id}/approve', function (Request $request) use ($withdrawals) {
        if (!Csrf::verify($request)) {
            return Response::text('Invalid or missing CSRF token.', 419);
        }

        $withdrawals->approve((int) $request->user['id'], (int) $request->param('id'), $request->ip());

        return Response::redirect('/admin');
    });

    $router->post('/admin/withdrawals/{id}/reject', function (Request $request) use ($withdrawals) {
        if (!Csrf::verify($request)) {
            return Response::text('Invalid or missing CSRF token.', 419);
        }

        $withdrawals->reject((int) $request->user['id'], (int) $request->param('id'), (string) $request->input('reason', 'No reason provided'), $request->ip());

        return Response::redirect('/admin');
    });

    $router->post('/admin/users/{id}/suspend', function (Request $request) use ($compliance) {
        if (!Csrf::verify($request)) {
            return Response::text('Invalid or missing CSRF token.', 419);
        }

        $compliance->suspendUser((int) $request->user['id'], (int) $request->param('id'), (string) $request->input('reason', 'No reason provided'), $request->ip());

        return Response::redirect('/admin');
    });

    $router->post('/admin/users/{id}/unsuspend', function (Request $request) use ($compliance) {
        if (!Csrf::verify($request)) {
            return Response::text('Invalid or missing CSRF token.', 419);
        }

        $compliance->unsuspendUser((int) $request->user['id'], (int) $request->param('id'), (string) $request->input('reason', 'No reason provided'), $request->ip());

        return Response::redirect('/admin');
    });
});
