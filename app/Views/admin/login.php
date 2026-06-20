<?php
$csrf = csrfField();
$content = <<<HTML
<div class="max-w-md mx-auto bg-slate-900 border border-slate-800 rounded-xl p-8">
    <h1 class="text-2xl font-semibold mb-6">Admin login</h1>
    <form method="POST" action="/admin/login" class="space-y-4">
        {$csrf}
        <div>
            <label class="block text-sm text-slate-400 mb-1">Email</label>
            <input type="email" name="email" required class="w-full bg-slate-800 border border-slate-700 rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm text-slate-400 mb-1">Password</label>
            <input type="password" name="password" required class="w-full bg-slate-800 border border-slate-700 rounded px-3 py-2">
        </div>
        <button type="submit" class="w-full bg-amber-500 hover:bg-amber-400 text-slate-950 font-semibold py-2 rounded">Log in</button>
    </form>
</div>
HTML;

echo layout('Admin login', $content, 'admin');
