<?php
$withdrawalRows = '';
foreach ($pendingWithdrawals as $w) {
    $csrf = csrfField();
    $withdrawalRows .= <<<HTML
    <tr class="border-b border-slate-800">
        <td class="py-3">{$w['id']}</td>
        <td class="py-3">{$w['email']}</td>
        <td class="py-3">\${$w['amount']}</td>
        <td class="py-3 flex gap-2">
            <form method="POST" action="/admin/withdrawals/{$w['id']}/approve">{$csrf}<button class="bg-emerald-600 hover:bg-emerald-500 text-xs px-3 py-1 rounded">Approve</button></form>
            <form method="POST" action="/admin/withdrawals/{$w['id']}/reject">{$csrf}<input type="hidden" name="reason" value="Failed manual review"><button class="bg-red-700 hover:bg-red-600 text-xs px-3 py-1 rounded">Reject</button></form>
        </td>
    </tr>
    HTML;
}
if ($withdrawalRows === '') {
    $withdrawalRows = '<tr><td class="py-3 text-slate-500" colspan="4">No pending withdrawal requests.</td></tr>';
}

$userRows = '';
foreach ($users as $u) {
    $csrf = csrfField();
    $action = $u['status'] === 'suspended'
        ? "<form method=\"POST\" action=\"/admin/users/{$u['id']}/unsuspend\">{$csrf}<input type=\"hidden\" name=\"reason\" value=\"Reviewed and cleared\"><button class=\"bg-emerald-600 hover:bg-emerald-500 text-xs px-3 py-1 rounded\">Unsuspend</button></form>"
        : "<form method=\"POST\" action=\"/admin/users/{$u['id']}/suspend\">{$csrf}<input type=\"hidden\" name=\"reason\" value=\"Flagged for review\"><button class=\"bg-amber-600 hover:bg-amber-500 text-xs px-3 py-1 rounded\">Suspend</button></form>";

    $userRows .= <<<HTML
    <tr class="border-b border-slate-800">
        <td class="py-3">{$u['id']}</td>
        <td class="py-3">{$u['email']}</td>
        <td class="py-3">{$u['status']}</td>
        <td class="py-3">{$u['kyc_tier']}</td>
        <td class="py-3">{$action}</td>
    </tr>
    HTML;
}

$content = <<<HTML
<h1 class="text-2xl font-semibold mb-6">Admin overview</h1>

<h2 class="text-lg font-semibold mb-3">Pending withdrawal requests</h2>
<table class="w-full text-left bg-slate-900 border border-slate-800 rounded-xl overflow-hidden mb-10">
    <thead class="bg-slate-800 text-sm text-slate-400">
        <tr><th class="py-3 px-4">ID</th><th class="py-3">User</th><th class="py-3">Amount</th><th class="py-3">Action</th></tr>
    </thead>
    <tbody>{$withdrawalRows}</tbody>
</table>

<h2 class="text-lg font-semibold mb-3">Users</h2>
<table class="w-full text-left bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
    <thead class="bg-slate-800 text-sm text-slate-400">
        <tr><th class="py-3 px-4">ID</th><th class="py-3">Email</th><th class="py-3">Status</th><th class="py-3">KYC tier</th><th class="py-3">Action</th></tr>
    </thead>
    <tbody>{$userRows}</tbody>
</table>
HTML;

echo layout('Admin', $content, 'admin');
