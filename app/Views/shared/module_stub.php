<?php
$label = match ($module) {
    'investment' => 'Investment',
    'forex' => 'Forex & Markets',
    'realestate' => 'Real Estate',
    default => ucfirst($module),
};

$content = <<<HTML
<h1 class="text-2xl font-semibold mb-2">{$label} dashboard</h1>
<p class="text-slate-400">This module's full feature set ships in a later phase per PROJECT_PLAN.md. The shared shell, auth, ledger, and module-access freeze check are already wired up for this route.</p>
HTML;

echo layout($label, $content, "dashboard-{$module}", $user);
