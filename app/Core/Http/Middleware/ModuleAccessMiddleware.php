<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Ledger\WalletService;

/**
 * A single logged-in session can reach all 3 dashboards; this checks
 * per-request whether the specific ledger backing the requested module is
 * frozen, rather than treating module access as a separate login. Server
 * checks are authoritative - client-side dashboard navigation is never
 * trusted as a security boundary.
 */
final class ModuleAccessMiddleware
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly string $ledgerType,
    ) {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $wallet = $this->wallets->getOrCreateWallet((int) $request->user['id'], $this->ledgerType);

        if ($wallet['status'] === 'frozen_all') {
            return Response::html(
                "<h1>This account area is under review</h1><p>Your {$this->ledgerType} ledger is temporarily frozen pending compliance review. Contact support for details.</p>",
                423,
            );
        }

        return $next($request);
    }
}
