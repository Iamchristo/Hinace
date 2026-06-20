<?php

declare(strict_types=1);

namespace App\Core\Auth;

use OTPHP\TOTP;

/**
 * TOTP is account-wide (one secret per user). Step-up re-verification
 * (calling verify() again) is required at withdrawal, large internal
 * transfer, destination-detail changes, KYC changes, and always for admin
 * login - enforced by the relevant module's controller, not here.
 */
final class TwoFactorService
{
    public function generateSecret(string $accountLabel, string $issuer = 'Hinace'): TOTP
    {
        $totp = TOTP::generate();
        $totp->setLabel($accountLabel);
        $totp->setIssuer($issuer);

        return $totp;
    }

    public function verify(string $secret, string $code): bool
    {
        $totp = TOTP::createFromSecret($secret);

        return $totp->verify($code, null, 1);
    }

    public function generateBackupCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }

        return $codes;
    }
}
