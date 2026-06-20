<?php
$content = <<<HTML
<h1 class="text-2xl font-semibold mb-6">Welcome back, {$user['email']}</h1>
<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <a href="/dashboard/investment" class="block bg-slate-900 border border-slate-800 rounded-xl p-6 hover:border-emerald-500">
        <h2 class="font-semibold mb-1">Investment</h2>
        <p class="text-sm text-slate-400">High-yield plans, payouts, referrals.</p>
    </a>
    <a href="/dashboard/forex" class="block bg-slate-900 border border-slate-800 rounded-xl p-6 hover:border-emerald-500">
        <h2 class="font-semibold mb-1">Forex &amp; Markets</h2>
        <p class="text-sm text-slate-400">Forex, crypto, indices, commodities trading.</p>
    </a>
    <a href="/dashboard/realestate" class="block bg-slate-900 border border-slate-800 rounded-xl p-6 hover:border-emerald-500">
        <h2 class="font-semibold mb-1">Real Estate</h2>
        <p class="text-sm text-slate-400">Fractional investing and property marketplace.</p>
    </a>
</div>
HTML;

echo layout('Dashboard', $content, 'dashboard-shared', $user);
