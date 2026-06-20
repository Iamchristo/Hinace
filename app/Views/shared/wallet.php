<?php
$rows = '';
foreach ($balances as $ledgerType => $wallet) {
    $available = number_format((float) $wallet['balance'] - (float) $wallet['locked_amount'], 2);
    $status = e($wallet['status']);
    $rows .= <<<HTML
    <tr class="border-b border-slate-800">
        <td class="py-3 capitalize">{$ledgerType}</td>
        <td class="py-3">\${$available}</td>
        <td class="py-3">{$wallet['currency']}</td>
        <td class="py-3"><span class="text-xs px-2 py-1 rounded bg-slate-800">{$status}</span></td>
    </tr>
    HTML;
}

$content = <<<HTML
<h1 class="text-2xl font-semibold mb-6">Wallet overview</h1>
<table class="w-full text-left bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
    <thead class="bg-slate-800 text-sm text-slate-400">
        <tr><th class="py-3 px-4">Ledger</th><th class="py-3">Available balance</th><th class="py-3">Currency</th><th class="py-3">Status</th></tr>
    </thead>
    <tbody class="px-4">{$rows}</tbody>
</table>
<a href="/dashboard/transfer" class="inline-block mt-6 bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold px-5 py-2 rounded">Transfer between ledgers</a>
HTML;

echo layout('Wallet', $content, 'dashboard-shared', $user);
