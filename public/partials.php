<?php

declare(strict_types=1);

/**
 * Gedeelde presentatie-helpers voor alle pagina's. Elke pagina blijft zelf
 * verantwoordelijk voor auth-check + POST-handling + SQL (zoals de rest van
 * de app); dit bestand bevat alleen herbruikbare HTML-fragmenten zodat de
 * top-navigatie niet in 7 losse bestanden gedupliceerd hoeft te worden.
 */

/**
 * CSRF hidden input.
 */
function csrfField(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token']) . '">';
}

function csrfOk(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', is_string($token) ? $token : '');
}

/**
 * Kleine info-bubble die vaktermen uitlegt via de hz-tooltip component.
 */
function tooltip(string $label, string $glossaryKey): string
{
    $text = ESG_GLOSSARY[$glossaryKey] ?? '';
    if ($text === '') {
        return e($label);
    }
    return '<span class="hz-tooltip" tabindex="0">' . e($label)
        . ' <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path stroke-linecap="round" d="M12 16v-4M12 8h.01"/></svg>'
        . '<span class="hz-tooltip__bubble" style="white-space:normal;width:240px;bottom:auto;top:125%;">' . e($text) . '</span></span>';
}

/**
 * Statusbadge voor het goedkeuringsproces (concept / ter_goedkeuring / goedgekeurd).
 */
function statusBadge(string $status): string
{
    $map = [
        'concept'         => ['hz-badge--gray',   'Concept'],
        'ter_goedkeuring' => ['hz-badge--orange', 'Ter goedkeuring'],
        'goedgekeurd'     => ['hz-badge--green',  'Goedgekeurd'],
    ];
    [$class, $label] = $map[$status] ?? ['hz-badge--gray', ucfirst($status)];
    return '<span class="hz-badge ' . $class . '">' . e($label) . '</span>';
}

function checklistStatusBadge(string $status): string
{
    $map = [
        'open'          => ['hz-badge--red',    'Open'],
        'in_uitvoering' => ['hz-badge--orange', 'In uitvoering'],
        'gereed'        => ['hz-badge--green',  'Gereed'],
    ];
    [$class, $label] = $map[$status] ?? ['hz-badge--gray', ucfirst($status)];
    return '<span class="hz-badge ' . $class . '">' . e($label) . '</span>';
}

function categoryBadgeClass(string $category): string
{
    return match ($category) {
        'Energie'    => 'bg-amber-50 text-amber-700',
        'Water'      => 'bg-sky-50 text-sky-700',
        'Afval'      => 'bg-violet-50 text-violet-700',
        'Mobiliteit' => 'bg-emerald-50 text-emerald-700',
        'Sociaal'    => 'bg-pink-50 text-pink-700',
        'Governance' => 'bg-indigo-50 text-indigo-700',
        default      => 'bg-slate-100 text-slate-700',
    };
}

/**
 * Bereken percentuele verandering tussen twee waardes ("was" -> "is").
 * Retourneert null als er niets zinnigs te berekenen valt (bijv. was=0).
 */
function pctChange(float $previous, float $current): ?float
{
    if (abs($previous) < 0.0001) {
        return null;
    }
    return (($current - $previous) / abs($previous)) * 100;
}

/**
 * Pagina-header: <head> + opening body + topnav. $active is de bestandsnaam
 * zonder extensie, gebruikt om het actieve nav-item te markeren.
 */
function renderPageStart(string $title, string $active): void
{
    $user = getUser();
    $navItems = [
        'index'        => ['Dashboard', BASE . '/index.php'],
        'metrics'       => ['Data-invoer', BASE . '/metrics.php'],
        'frameworks'    => ['Frameworks', BASE . '/frameworks.php'],
        'reports'       => ['Rapportages', BASE . '/reports.php'],
        'benchmarks'    => ['Benchmarks', BASE . '/benchmarks.php'],
        'stakeholders'  => ['Stakeholders', BASE . '/stakeholders.php'],
    ];
    if ($user['role'] === 'admin') {
        $navItems['settings'] = ['Instellingen', BASE . '/settings.php'];
    }
    ?>
<!DOCTYPE html>
<html lang="nl" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> — ESG Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: { 50: '#ecfdf5', 500: '#10b981', 600: '#059669', 700: '#047857' },
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="<?= BASE ?>/assets/css/components.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <style>
        .modal-backdrop { background: rgba(0,0,0,0.4); }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
        }
    </style>
</head>
<body class="h-full bg-slate-50 text-slate-800 antialiased">

<nav class="bg-white shadow-sm border-b border-slate-200 no-print">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16 gap-4">
            <div class="flex items-center gap-3 shrink-0">
                <svg class="w-8 h-8 text-brand-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2z"/>
                    <path d="M12 6c-2 0-4 2-4 5s2 5 4 5c1 0 2.5-1 3-2.5"/>
                    <path d="M12 6v-2M16 8l1.5-1M17 12h2M16 16l1.5 1"/>
                    <path d="M12 18v2M8 16l-1.5 1M7 12H5M8 8L6.5 7"/>
                </svg>
                <h1 class="text-xl font-bold text-slate-900 tracking-tight hidden sm:block">ESG Manager</h1>
            </div>
            <div class="flex items-center gap-1 overflow-x-auto">
                <?php foreach ($navItems as $key => [$label, $href]): ?>
                    <a href="<?= $href ?>"
                       class="px-3 py-2 rounded-lg text-sm font-medium whitespace-nowrap transition-colors <?= $active === $key ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-900 hover:bg-slate-50' ?>">
                        <?= e($label) ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="flex items-center gap-3 shrink-0">
                <span class="hz-badge <?= roleBadgeClass($user['role']) ?> hidden md:inline-flex"><?= e(roleLabel($user['role'])) ?></span>
                <span class="text-sm text-slate-500 hidden lg:block"><?= e($user['email']) ?></span>
                <a href="<?= BASE ?>/logout.php"
                   class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-red-600 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    <span class="hidden sm:inline">Uitloggen</span>
                </a>
            </div>
        </div>
    </div>
</nav>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <?php
}

function renderFlash(string $message, string $type = 'success'): void
{
    if ($message === '') {
        return;
    }
    $cls = $type === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-brand-50 text-brand-700 border border-brand-200';
    echo '<div class="mb-6 px-4 py-3 rounded-lg text-sm font-medium ' . $cls . '">' . e($message) . '</div>';
}

function renderPageEnd(): void
{
    ?>
</main>
<script src="<?= BASE ?>/assets/js/components.js"></script>
</body>
</html>
    <?php
}
