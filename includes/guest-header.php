<?php /** Layout for login / register / password pages. Set $page_title. */ ?><!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title ?? 'Sign in') ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="h-full">
<div class="flex min-h-full">
    <div class="relative hidden w-[44%] flex-col justify-between overflow-hidden bg-slate-950 p-12 text-white lg:flex">
        <div class="absolute -left-24 -top-24 h-96 w-96 rounded-full bg-indigo-600/30 blur-3xl"></div>
        <div class="absolute -bottom-32 right-0 h-96 w-96 rounded-full bg-violet-600/20 blur-3xl"></div>
        <div class="relative flex items-center gap-2.5">
            <img src="<?= e(asset('images/favicon.svg')) ?>" class="h-9 w-9" alt="">
            <span class="text-lg font-semibold"><?= e(APP_NAME) ?></span>
        </div>
        <div class="relative">
            <h2 class="text-3xl font-semibold leading-tight !text-white">Customers, campaigns and replies<br>in one lightweight CRM.</h2>
            <ul class="mt-8 space-y-3 text-sm text-slate-300">
                <li class="flex gap-2"><span class="text-indigo-400">✓</span> Import contacts, tag and segment them</li>
                <li class="flex gap-2"><span class="text-indigo-400">✓</span> Personalised campaigns sent safely by cron</li>
                <li class="flex gap-2"><span class="text-indigo-400">✓</span> Opens, clicks, replies and bounces tracked</li>
                <li class="flex gap-2"><span class="text-indigo-400">✓</span> Sales pipeline board for follow-ups</li>
            </ul>
        </div>
        <p class="relative text-xs text-slate-500">Runs on plain PHP + MySQL.</p>
    </div>
    <div class="flex flex-1 items-center justify-center px-6 py-12">
        <div class="w-full max-w-sm">
            <?php foreach (get_flashes() as $f): ?>
                <div class="mb-4 rounded-lg px-4 py-3 text-sm <?= $f['type'] === 'error' ? 'bg-red-50 text-red-700 ring-1 ring-red-200' : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' ?>"><?= e($f['message']) ?></div>
            <?php endforeach; ?>
