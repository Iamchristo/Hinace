<?php
$content = <<<HTML
<div class="text-center py-20">
    <h1 class="text-4xl font-bold mb-4">Hinace</h1>
    <p class="text-slate-400 max-w-xl mx-auto mb-8">
        One account for high-yield investment plans, multi-asset forex &amp; markets trading, and real estate investing and marketplace listings.
    </p>
    <a href="/login" class="bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold px-6 py-3 rounded-lg">Log in</a>
</div>
HTML;

echo layout('Home', $content, 'marketing');
