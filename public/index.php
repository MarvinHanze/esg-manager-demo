<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config.php';

initDatabase();
requireLogin();

$db = getDb();
$action  = $_POST['action'] ?? $_GET['action'] ?? 'list';
$editId  = (int) ($_GET['edit'] ?? 0);
$message = '';
$msgType = 'success';

// ── Handle POST actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $message = 'Ongeldige aanvraag.';
        $msgType = 'error';
    } else {
        if ($action === 'create' || $action === 'update') {
            $category   = trim((string) ($_POST['category'] ?? ''));
            $metricName = trim((string) ($_POST['metric_name'] ?? ''));
            $value      = (float) ($_POST['value'] ?? 0);
            $unit       = trim((string) ($_POST['unit'] ?? ''));
            $target     = (float) ($_POST['target_value'] ?? 0);
            $period     = trim((string) ($_POST['period'] ?? ''));

            if ($category === '' || $metricName === '') {
                $message = 'Categorie en metric naam zijn verplicht.';
                $msgType = 'error';
            } else {
                if ($action === 'create') {
                    $stmt = $db->prepare(
                        'INSERT INTO esg_metrics (category, metric_name, value, unit, target_value, period) VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$category, $metricName, $value, $unit, $target, $period]);
                    $message = 'Metric toegevoegd.';
                } elseif ($action === 'update') {
                    $updateId = (int) ($_POST['id'] ?? 0);
                    if ($updateId > 0) {
                        $stmt = $db->prepare(
                            'UPDATE esg_metrics SET category=?, metric_name=?, value=?, unit=?, target_value=?, period=? WHERE id=?'
                        );
                        $stmt->execute([$category, $metricName, $value, $unit, $target, $period, $updateId]);
                        $message = 'Metric bijgewerkt.';
                    }
                }
            }
        } elseif ($action === 'delete') {
            $deleteId = (int) ($_POST['id'] ?? 0);
            if ($deleteId > 0) {
                $stmt = $db->prepare('DELETE FROM esg_metrics WHERE id = ?');
                $stmt->execute([$deleteId]);
                $message = 'Metric verwijderd.';
            }
        }
    }
    $editId = 0;
}

// ── CSRF token ───────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Fetch data ───────────────────────────────────────────────────────
$search = trim((string) ($_GET['q'] ?? ''));
if ($search !== '') {
    $stmt = $db->prepare(
        'SELECT * FROM esg_metrics WHERE category LIKE ? OR metric_name LIKE ? ORDER BY category, metric_name'
    );
    $like = '%' . $search . '%';
    $stmt->execute([$like, $like]);
} else {
    $stmt = $db->query('SELECT * FROM esg_metrics ORDER BY category, metric_name');
}
$metrics = $stmt->fetchAll();

// ── Aggregates for stat cards ────────────────────────────────────────
function sumByCategory(array $metrics, string $cat, string $unit): string
{
    $total = 0.0;
    foreach ($metrics as $m) {
        if ($m['category'] === $cat && $m['unit'] === $unit) {
            $total += (float) $m['value'];
        }
    }
    if ($total >= 1000) {
        return number_format($total / 1000, 1, ',', '.') . 'k';
    }
    return number_format($total, 0, ',', '.');
}

$co2Total    = sumByCategory($metrics, 'Energie', 'kg');
$energyTotal = sumByCategory($metrics, 'Energie', 'kWh');
$waterTotal  = sumByCategory($metrics, 'Water', 'L');
$wasteTotal  = sumByCategory($metrics, 'Afval', 'kg');

// ── Edit data ────────────────────────────────────────────────────────
$editData = null;
if ($editId > 0) {
    $stmt = $db->prepare('SELECT * FROM esg_metrics WHERE id = ?');
    $stmt->execute([$editId]);
    $editData = $stmt->fetch();
    if ($editData !== false) {
        $action = 'update';
    }
}

// ── Categories ───────────────────────────────────────────────────────
$categories = ['Energie', 'Water', 'Afval', 'Mobiliteit'];

$currentUser = getUser();
?>
<!DOCTYPE html>
<html lang="nl" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ESG Manager — Duurzaamheidsdashboard</title>
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
    <style>
        .modal-backdrop { background: rgba(0,0,0,0.4); }
    </style>
</head>
<body class="h-full bg-slate-50 text-slate-800 antialiased">

<!-- ── Nav ─────────────────────────────────────────────────────── -->
<nav class="bg-white shadow-sm border-b border-slate-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <div class="flex items-center gap-3">
                <svg class="w-8 h-8 text-brand-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2z"/>
                    <path d="M12 6c-2 0-4 2-4 5s2 5 4 5c1 0 2.5-1 3-2.5"/>
                    <path d="M12 6v-2M16 8l1.5-1M17 12h2M16 16l1.5 1"/>
                    <path d="M12 18v2M8 16l-1.5 1M7 12H5M8 8L6.5 7"/>
                </svg>
                <h1 class="text-xl font-bold text-slate-900 tracking-tight">ESG Manager</h1>
            </div>
            <div class="flex items-center gap-4">
                <span class="text-sm text-slate-500 hidden sm:block"><?= htmlspecialchars($currentUser['email']) ?></span>
                <a href="<?= BASE ?>/logout.php"
                   class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-red-600 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    Uitloggen
                </a>
            </div>
        </div>
    </div>
</nav>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

<!-- ── Flash message ──────────────────────────────────────────── -->
<?php if ($message !== ''): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm font-medium <?= $msgType === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-brand-50 text-brand-700 border border-brand-200' ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- ── Stat cards ─────────────────────────────────────────────── -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <div class="flex items-center gap-3 mb-3">
            <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 2a10 10 0 100 20 10 10 0 000-20z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/>
                </svg>
            </div>
            <p class="text-sm font-medium text-slate-500">Totaal CO2 Besparing</p>
        </div>
        <p class="text-3xl font-bold text-slate-900"><?= $co2Total ?></p>
        <p class="text-sm text-slate-500 mt-1">kilogram CO2</p>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <div class="flex items-center gap-3 mb-3">
            <div class="w-10 h-10 rounded-lg bg-amber-50 flex items-center justify-center">
                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
            <p class="text-sm font-medium text-slate-500">Energie Bespaard</p>
        </div>
        <p class="text-3xl font-bold text-slate-900"><?= $energyTotal ?></p>
        <p class="text-sm text-slate-500 mt-1">kilowattuur</p>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <div class="flex items-center gap-3 mb-3">
            <div class="w-10 h-10 rounded-lg bg-sky-50 flex items-center justify-center">
                <svg class="w-5 h-5 text-sky-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c-4.97 0-9 3.19-9 7.13 0 2.82 2.03 5.24 4.97 6.37-.13-.89-.25-2.25.03-3.36.27-1.07.84-2 1.66-2.63C10.64 9.8 11.3 9.5 12 9.5s1.36.3 2.34 1.01c.82.63 1.39 1.56 1.66 2.63.28 1.11.16 2.47.03 3.36 2.94-1.13 4.97-3.55 4.97-6.37C21 6.19 16.97 3 12 3z"/>
                </svg>
            </div>
            <p class="text-sm font-medium text-slate-500">Water Teruggewonnen</p>
        </div>
        <p class="text-3xl font-bold text-slate-900"><?= $waterTotal ?></p>
        <p class="text-sm text-slate-500 mt-1">liter</p>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <div class="flex items-center gap-3 mb-3">
            <div class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
                <svg class="w-5 h-5 text-violet-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v16h16V4H4zm4 4h8M8 12h8M8 16h5"/>
                </svg>
            </div>
            <p class="text-sm font-medium text-slate-500">Afval Gerecycled</p>
        </div>
        <p class="text-3xl font-bold text-slate-900"><?= $wasteTotal ?></p>
        <p class="text-sm text-slate-500 mt-1">kilogram</p>
    </div>
</div>

<!-- ── Toolbar ────────────────────────────────────────────────── -->
<div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
    <form method="get" class="flex items-center gap-2 w-full sm:w-auto">
        <div class="relative flex-1 sm:flex-none">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <circle cx="11" cy="11" r="8"/><path stroke-linecap="round" d="M21 21l-4.35-4.35"/>
            </svg>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Zoek metrics..."
                   class="w-full sm:w-72 pl-10 pr-4 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
        </div>
        <button type="submit" class="px-4 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
            Zoek
        </button>
    </form>
    <button onclick="openModal('create')"
            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-brand-500 rounded-lg hover:bg-brand-600 transition-colors shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" d="M12 5v14M5 12h14"/>
        </svg>
        Nieuwe Metric
    </button>
</div>

<!-- ── Data table ─────────────────────────────────────────────── -->
<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 bg-slate-50">
                    <th class="px-6 py-3 text-left font-semibold text-slate-600">Categorie</th>
                    <th class="px-6 py-3 text-left font-semibold text-slate-600">Metric</th>
                    <th class="px-6 py-3 text-right font-semibold text-slate-600">Waarde</th>
                    <th class="px-6 py-3 text-left font-semibold text-slate-600">Eenheid</th>
                    <th class="px-6 py-3 text-right font-semibold text-slate-600">Doel</th>
                    <th class="px-6 py-3 text-right font-semibold text-slate-600">Voortgang</th>
                    <th class="px-6 py-3 text-right font-semibold text-slate-600">Acties</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php if (empty($metrics)): ?>
                <tr>
                    <td colspan="7" class="px-6 py-12 text-center text-slate-400">
                        Geen metrics gevonden.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($metrics as $m): ?>
                    <?php
                    $progress = 0;
                    if ((float) $m['target_value'] > 0) {
                        $progress = min(100, round(((float) $m['value'] / (float) $m['target_value']) * 100));
                    }
                    $barColor = $progress >= 100 ? 'bg-emerald-500' : ($progress >= 60 ? 'bg-amber-400' : 'bg-red-400');
                    ?>
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium
                                <?php
                                switch ($m['category']) {
                                    case 'Energie':   echo 'bg-amber-50 text-amber-700'; break;
                                    case 'Water':     echo 'bg-sky-50 text-sky-700'; break;
                                    case 'Afval':     echo 'bg-violet-50 text-violet-700'; break;
                                    case 'Mobiliteit': echo 'bg-emerald-50 text-emerald-700'; break;
                                    default:           echo 'bg-slate-100 text-slate-700'; break;
                                }
                                ?>">
                                <?= htmlspecialchars($m['category']) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 font-medium text-slate-900"><?= htmlspecialchars($m['metric_name']) ?></td>
                        <td class="px-6 py-4 text-right tabular-nums"><?= number_format((float) $m['value'], 2, ',', '.') ?></td>
                        <td class="px-6 py-4 text-slate-500"><?= htmlspecialchars($m['unit']) ?></td>
                        <td class="px-6 py-4 text-right tabular-nums text-slate-500"><?= number_format((float) $m['target_value'], 2, ',', '.') ?></td>
                        <td class="px-6 py-4 w-32">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 h-2 bg-slate-100 rounded-full overflow-hidden">
                                    <div class="h-full <?= $barColor ?> rounded-full transition-all" style="width: <?= $progress ?>%"></div>
                                </div>
                                <span class="text-xs text-slate-500 tabular-nums w-10 text-right"><?= $progress ?>%</span>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <button onclick="openModal('edit', <?= (int) $m['id'] ?>)"
                                        class="p-1.5 rounded-md text-slate-400 hover:text-brand-600 hover:bg-brand-50 transition-colors"
                                        title="Bewerk">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </button>
                                <form method="post" class="inline"
                                      onsubmit="return confirm('Weet je zeker dat je deze metric wilt verwijderen?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <button type="submit"
                                            class="p-1.5 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors"
                                            title="Verwijder">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Footer ─────────────────────────────────────────────────── -->
<footer class="mt-12 pb-8 text-center text-sm text-slate-400">
    ESG Manager Demo &middot; PHP 8.2 + Apache + MySQL
</footer>

</main>

<!-- ── Modal ──────────────────────────────────────────────────── -->
<div id="modal" class="fixed inset-0 z-50 hidden items-center justify-center modal-backdrop" style="display:none;">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg mx-4 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
            <h2 id="modal-title" class="text-lg font-semibold text-slate-900">Nieuwe Metric</h2>
            <button onclick="closeModal()" class="p-1 rounded-md text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form id="modal-form" method="post" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" id="form-action" value="create">
            <input type="hidden" name="id" id="form-id" value="0">

            <div>
                <label for="form-category" class="block text-sm font-medium text-slate-700 mb-1">Categorie</label>
                <select name="category" id="form-category" required
                        class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="form-metric-name" class="block text-sm font-medium text-slate-700 mb-1">Metric naam</label>
                <input type="text" name="metric_name" id="form-metric-name" required
                       class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none"
                       placeholder="bijv. KWh bespaard">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="form-value" class="block text-sm font-medium text-slate-700 mb-1">Waarde</label>
                    <input type="number" name="value" id="form-value" step="0.01" value="0"
                           class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                </div>
                <div>
                    <label for="form-unit" class="block text-sm font-medium text-slate-700 mb-1">Eenheid</label>
                    <input type="text" name="unit" id="form-unit"
                           class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none"
                           placeholder="bijv. kWh, kg, L">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="form-target" class="block text-sm font-medium text-slate-700 mb-1">Doelwaarde</label>
                    <input type="number" name="target_value" id="form-target" step="0.01" value="0"
                           class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                </div>
                <div>
                    <label for="form-period" class="block text-sm font-medium text-slate-700 mb-1">Periode</label>
                    <input type="text" name="period" id="form-period"
                           class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none"
                           placeholder="bijv. Q1 2026">
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200">
                <button type="button" onclick="closeModal()"
                        class="px-4 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                    Annuleren
                </button>
                <button type="submit"
                        class="px-5 py-2 text-sm font-semibold text-white bg-brand-500 rounded-lg hover:bg-brand-600 transition-colors shadow-sm">
                    Opslaan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(mode, id) {
    var modal = document.getElementById('modal');
    var title = document.getElementById('modal-title');
    var formAction = document.getElementById('form-action');
    var formId = document.getElementById('form-id');

    if (mode === 'edit' && id) {
        title.textContent = 'Metric Bewerken';
        formAction.value = 'update';
        formId.value = id;
        // Redirect to load metric data for editing
        window.location.href = '<?= BASE ?>/index.php?edit=' + id;
        return;
    }

    title.textContent = 'Nieuwe Metric';
    formAction.value = 'create';
    formId.value = '0';
    document.getElementById('form-category').value = 'Energie';
    document.getElementById('form-metric-name').value = '';
    document.getElementById('form-value').value = '0';
    document.getElementById('form-unit').value = '';
    document.getElementById('form-target').value = '0';
    document.getElementById('form-period').value = '';

    modal.style.display = 'flex';
}

function closeModal() {
    document.getElementById('modal').style.display = 'none';
}

// Close modal on backdrop click
document.getElementById('modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php if ($editData !== null): ?>
<script>
(function() {
    var modal = document.getElementById('modal');
    var title = document.getElementById('modal-title');
    title.textContent = 'Metric Bewerken';
    document.getElementById('form-action').value = 'update';
    document.getElementById('form-id').value = '<?= (int) $editData['id'] ?>';
    document.getElementById('form-category').value = <?= json_encode($editData['category']) ?>;
    document.getElementById('form-metric-name').value = <?= json_encode($editData['metric_name']) ?>;
    document.getElementById('form-value').value = <?= json_encode($editData['value']) ?>;
    document.getElementById('form-unit').value = <?= json_encode($editData['unit']) ?>;
    document.getElementById('form-target').value = <?= json_encode($editData['target_value']) ?>;
    document.getElementById('form-period').value = <?= json_encode($editData['period']) ?>;
    modal.style.display = 'flex';
})();
</script>
<?php endif; ?>

</body>
</html>
