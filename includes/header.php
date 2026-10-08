<?php
/**
 * Layout start. Set $page_title and $active_nav before including.
 * Optional: $wide (bool) removes the max-width container, $extra_head (string).
 */
$user = current_user();
$workspace = current_workspace();
$page_title = $page_title ?? APP_NAME;
$active_nav = $active_nav ?? '';
?><!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="app-url" content="<?= e(APP_URL) ?>">
    <title><?= e($page_title) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?= $extra_head ?? '' ?>
    <script src="<?= e(asset('js/app.js')) ?>"></script>
    <script defer src="<?= e(asset('vendor/alpine.min.js')) ?>"></script>
</head>
<body class="h-full" x-data x-on:keydown.window.prevent.ctrl.k="$store.search.toggle()" x-on:keydown.window.prevent.meta.k="$store.search.toggle()">
<div x-data="{ sidebarOpen: false }" class="min-h-full">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="lg:pl-64">
        <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6 lg:px-8">
            <button type="button" class="btn-icon lg:hidden" @click="sidebarOpen = true" aria-label="Open menu"><?= icon('menu') ?></button>
            <button type="button" @click="$store.search.open()" class="flex w-full max-w-md items-center gap-2 rounded-lg bg-slate-100 px-3 py-1.5 text-sm text-slate-500 hover:bg-slate-200/70">
                <?= icon('search', 'h-4 w-4') ?>
                <span class="flex-1 text-left">Search customers, campaigns, emails…</span>
                <kbd class="hidden rounded border border-slate-300 bg-white px-1.5 text-[11px] font-medium text-slate-500 sm:inline">Ctrl K</kbd>
            </button>
            <div class="ml-auto flex items-center gap-2">
                <a href="<?= e(url('customers/create.php')) ?>" class="btn-secondary btn-sm hidden sm:inline-flex"><?= icon('plus', 'h-4 w-4') ?> Customer</a>
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" @click="open = !open" class="flex items-center gap-2 rounded-lg px-1.5 py-1 hover:bg-slate-100">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600 text-xs font-semibold text-white"><?= e(initials($user['name'] ?? '')) ?></span>
                        <span class="hidden text-sm font-medium text-slate-700 md:block"><?= e($user['name'] ?? '') ?></span>
                        <?= icon('chevron-down', 'h-4 w-4 text-slate-400 hidden md:block') ?>
                    </button>
                    <div x-cloak x-show="open" x-transition.opacity class="dropdown right-0">
                        <div class="border-b border-slate-100 px-3 py-2">
                            <div class="text-sm font-medium text-slate-900"><?= e($user['name'] ?? '') ?></div>
                            <div class="truncate text-xs text-slate-500"><?= e($user['email'] ?? '') ?></div>
                            <div class="mt-1.5"><?= role_badge(user_role()) ?></div>
                        </div>
                        <a class="dropdown-item" href="<?= e(url('settings/index.php', ['tab' => 'profile'])) ?>"><?= icon('cog', 'h-4 w-4') ?> Profile &amp; settings</a>
                        <form method="post" action="<?= e(url('logout.php')) ?>">
                            <?= csrf_field() ?>
                            <button class="dropdown-item text-red-600"><?= icon('logout', 'h-4 w-4') ?> Sign out</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="px-4 py-6 sm:px-6 lg:px-8">
            <div class="<?= !empty($wide) ? '' : 'mx-auto max-w-7xl' ?>">
