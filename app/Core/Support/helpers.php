<?php

declare(strict_types=1);

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('layout')) {
    /**
     * Wraps page content in the shared chrome (header, account switcher,
     * per-dashboard theme class). Each dashboard gets its own CSS bundle via
     * $theme so the 3 dashboards stay visually distinct without a SPA
     * framework - see PROJECT_PLAN.md section 5.
     */
    function layout(string $title, string $content, string $theme = 'marketing', ?array $user = null): string
    {
        $nav = $user !== null ? renderAuthedNav() : '';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$title} - Hinace</title>
            <script src="https://cdn.tailwindcss.com"></script>
            <link rel="stylesheet" href="/assets/{$theme}/theme.css">
        </head>
        <body class="bg-slate-950 text-slate-100 min-h-screen" data-theme="{$theme}">
            {$nav}
            <main class="max-w-6xl mx-auto px-6 py-10">
                {$content}
            </main>
        </body>
        </html>
        HTML;
    }
}

if (!function_exists('renderAuthedNav')) {
    function renderAuthedNav(): string
    {
        return <<<HTML
        <header class="border-b border-slate-800 bg-slate-900">
            <div class="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between">
                <a href="/dashboard" class="font-semibold text-lg">Hinace</a>
                <nav class="flex gap-4 text-sm">
                    <a href="/dashboard/investment" class="hover:text-emerald-400">Investment</a>
                    <a href="/dashboard/forex" class="hover:text-emerald-400">Forex &amp; Markets</a>
                    <a href="/dashboard/realestate" class="hover:text-emerald-400">Real Estate</a>
                    <a href="/dashboard/transfer" class="hover:text-emerald-400">Transfer</a>
                    <a href="/dashboard/wallet" class="hover:text-emerald-400">Wallet</a>
                </nav>
            </div>
        </header>
        HTML;
    }
}

if (!function_exists('csrfField')) {
    function csrfField(): string
    {
        $token = $_COOKIE[App\Core\Http\Csrf::COOKIE_NAME] ?? App\Core\Http\Csrf::issue();

        return '<input type="hidden" name="_csrf" value="' . e($token) . '">';
    }
}
