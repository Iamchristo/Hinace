<?php
$options = '';
foreach (array_keys($balances) as $ledgerType) {
    $options .= '<option value="' . e($ledgerType) . '">' . e(ucfirst($ledgerType)) . '</option>';
}

$errorBlock = $error !== null
    ? '<div class="bg-red-950 border border-red-800 text-red-300 rounded px-4 py-3 mb-4">' . e($error) . '</div>'
    : '';

$csrf = csrfField();

$content = <<<HTML
<h1 class="text-2xl font-semibold mb-6">Transfer between your ledgers</h1>
{$errorBlock}
<form method="POST" action="/dashboard/transfer" class="max-w-md bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-4">
    {$csrf}
    <div>
        <label class="block text-sm text-slate-400 mb-1">From</label>
        <select name="from_ledger" class="w-full bg-slate-800 border border-slate-700 rounded px-3 py-2">{$options}</select>
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">To</label>
        <select name="to_ledger" class="w-full bg-slate-800 border border-slate-700 rounded px-3 py-2">{$options}</select>
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">Amount</label>
        <input type="text" name="amount" placeholder="0.00" required class="w-full bg-slate-800 border border-slate-700 rounded px-3 py-2">
    </div>
    <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold py-2 rounded">Transfer</button>
</form>
HTML;

echo layout('Transfer', $content, 'dashboard-shared', $user);
