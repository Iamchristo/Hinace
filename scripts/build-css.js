#!/usr/bin/env node
const { execFileSync } = require('child_process');
const path = require('path');

const themes = [
    'marketing',
    'shared',
    'dashboard-shared',
    'dashboard-investment',
    'dashboard-forex',
    'dashboard-realestate',
    'admin',
];

const root = path.resolve(__dirname, '..');
const tailwindBin = path.join(root, 'node_modules', '.bin', 'tailwindcss');

for (const theme of themes) {
    const input = path.join(root, 'resources', 'css', `${theme}.css`);
    const output = path.join(root, 'public', 'assets', theme, 'theme.css');
    console.log(`Building ${theme}...`);
    execFileSync(tailwindBin, ['-i', input, '-o', output, '--minify'], {
        cwd: root,
        stdio: 'inherit',
    });
}
