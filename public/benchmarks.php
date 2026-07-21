<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/partials.php';

initDatabase();
requireLogin();

$db = getDb();
$currentUser = getUser();
$message = '';
$msgType = 'success';
$categories = ['Energie', 'Water', 'Afval', 'Mobiliteit', 'Sociaal', 'Governance'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfOk()) {
        $message = 'Ongeldige aanvraag.';
        $msgType = 'error';
    } elseif (($_POST['action'] ?? '') === 'create_kpi') {
        $name     = trim((string) ($_POST['name'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $unit     = trim((string) ($_POST['unit'] ?? ''));
        $current  = (float) ($_POST['current_value'] ?? 0);
        $previous = ($_POST['previous_value'] ?? '') !== '' ? (float) $_POST['previous_value'] : null;
        $target   = ($_POST['target_value'] ?? '') !== '' ? (float) $_POST['target_value'] : null;

        if ($name === '' || $category === '') {
            $message = 'Naam en categorie zijn verplicht.';
            $msgType = 'error';
        } else {
            $stmt = $db->prepare(
                'INSERT INTO esg_custom_kpis (name, category, unit, current_value, previous_value, target_value, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $category, $unit, $current, $previous, $target, $currentUser['name']]);
            auditLog('create', 'custom_kpi', (int) $db->lastInsertId(), $name);
            $message = 'Eigen KPI toegevoegd.';
        }
    } elseif (($_POST['action'] ?? '') === 'delete_kpi') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare('DELETE FROM esg_custom_kpis WHERE id = ?');
            $stmt->execute([$id]);
            auditLog('delete', 'custom_kpi', $id);
            $message = 'Eigen KPI verwijderd.';
        }
    }
}

$kpis = $db->query('SELECT * FROM esg_custom_kpis ORDER BY category, name')->fetchAll();

renderPageStart('Benchmarks', 'benchmarks');
renderFlash($message, $msgType);
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-slate-900">Analyses, benchmarks & eigen <?= tooltip('KPI\'s', 'kpi') ?></h1>
    <p class="text-sm text-slate-500 mt-1">Definieer eigen indicatoren, bekijk de trend t.o.v. de vorige meting en vergelijk met vaste sectorgemiddeldes.</p>
</div>

<!-- ── Sectorbenchmark chart ────────────────────────────────────── -->
<div class="hz-card mb-8">
    <div class="hz-card__header"><h2 class="text-base font-semibold text-slate-900">Jouw waarde vs. sectorgemiddelde</h2></div>
    <canvas id="benchmarkChart" height="220"></canvas>
    <p class="text-xs text-slate-400 mt-3">Sectorgemiddeldes zijn vaste demo-constanten (geen live databron) — bedoeld ter illustratie van de benchmarkfunctionaliteit.</p>
</div>

<!-- ── Eigen KPI's ──────────────────────────────────────────────── -->
<div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-semibold text-slate-900">Eigen KPI's</h2>
    <button onclick="document.getElementById('kpiModal').style.display='flex'" class="hz-btn hz-btn--primary">+ Nieuwe KPI</button>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
    <?php if (!$kpis): ?>
        <p class="text-slate-400 text-sm">Nog geen eigen KPI's gedefinieerd.</p>
    <?php endif; ?>
    <?php foreach ($kpis as $k): ?>
        <?php
        $change = $k['previous_value'] !== null ? pctChange((float) $k['previous_value'], (float) $k['current_value']) : null;
        $benchmark = SECTOR_BENCHMARKS[$k['category']] ?? null;
        ?>
        <div class="hz-card">
            <div class="flex items-start justify-between mb-2">
                <div>
                    <p class="text-xs font-medium text-slate-400 uppercase"><?= e($k['category']) ?></p>
                    <p class="font-semibold text-slate-900"><?= e($k['name']) ?></p>
                </div>
                <form method="post" data-hz-confirm="Deze KPI verwijderen?">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_kpi">
                    <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                    <button type="submit" class="text-slate-300 hover:text-red-500">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </form>
            </div>
            <p class="text-2xl font-bold text-slate-900"><?= number_format((float) $k['current_value'], 2, ',', '.') ?> <span class="text-sm font-normal text-slate-400"><?= e((string) $k['unit']) ?></span></p>
            <?php if ($change !== null): ?>
                <p class="text-sm mt-1 <?= $change >= 0 ? 'text-emerald-600' : 'text-red-600' ?>">
                    <?= $change >= 0 ? '▲' : '▼' ?> <?= number_format(abs($change), 1, ',', '.') ?>% t.o.v. vorige meting
                </p>
            <?php endif; ?>
            <?php if ($benchmark): ?>
                <?php $vsB = pctChange((float) $benchmark['value'], (float) $k['current_value']); ?>
                <p class="text-xs text-slate-500 mt-2 pt-2 border-t border-slate-100">
                    Sectorgemiddelde: <?= number_format($benchmark['value'], 1, ',', '.') ?> <?= e($benchmark['unit']) ?>
                    <?php if ($vsB !== null): ?>
                        — <span class="<?= $vsB >= 0 ? 'text-emerald-600' : 'text-red-600' ?>"><?= $vsB >= 0 ? '+' : '' ?><?= number_format($vsB, 1, ',', '.') ?>%</span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- ── Nieuwe KPI modal ─────────────────────────────────────────── -->
<div id="kpiModal" class="fixed inset-0 z-50 hidden items-center justify-center modal-backdrop" style="display:none;">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
            <h2 class="text-lg font-semibold text-slate-900">Nieuwe eigen KPI</h2>
            <button onclick="document.getElementById('kpiModal').style.display='none'" class="p-1 rounded-md text-slate-400 hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="post" class="p-6 space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_kpi">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Naam <span class="text-red-500">*</span></label>
                <input type="text" name="name" required placeholder="bijv. CO2-uitstoot per FTE" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Categorie <span class="text-red-500">*</span></label>
                <select name="category" required class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                    <?php foreach ($categories as $cat): ?><option value="<?= e($cat) ?>"><?= e($cat) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Huidige waarde</label>
                    <input type="number" step="0.01" name="current_value" value="0" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Eenheid</label>
                    <input type="text" name="unit" placeholder="bijv. %, kg" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Vorige waarde</label>
                    <input type="number" step="0.01" name="previous_value" placeholder="optioneel" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Doelwaarde</label>
                    <input type="number" step="0.01" name="target_value" placeholder="optioneel" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                </div>
            </div>
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200">
                <button type="button" onclick="document.getElementById('kpiModal').style.display='none'" class="hz-btn hz-btn--secondary">Annuleren</button>
                <button type="submit" class="hz-btn hz-btn--primary">Opslaan</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('kpiModal').addEventListener('click', function (e) { if (e.target === this) this.style.display = 'none'; });

const benchmarkLabels = <?= json_encode(array_keys(SECTOR_BENCHMARKS)) ?>;
const benchmarkSector = <?= json_encode(array_column(SECTOR_BENCHMARKS, 'value')) ?>;
<?php
// Gemiddelde huidige waarde per categorie uit de eigen KPI's (indien aanwezig), anders 0.
$avgByCategory = [];
foreach (array_keys(SECTOR_BENCHMARKS) as $cat) {
    $vals = array_filter($kpis, fn($k) => $k['category'] === $cat);
    $avgByCategory[$cat] = $vals ? array_sum(array_column($vals, 'current_value')) / count($vals) : 0;
}
?>
const benchmarkYours = <?= json_encode(array_values($avgByCategory)) ?>;
new Chart(document.getElementById('benchmarkChart'), {
    type: 'bar',
    data: {
        labels: benchmarkLabels,
        datasets: [
            { label: 'Sectorgemiddelde', data: benchmarkSector, backgroundColor: '#cbd5e1' },
            { label: 'Eigen KPI-gemiddelde', data: benchmarkYours, backgroundColor: '#059669' },
        ]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } } }
});
</script>

<?php renderPageEnd(); ?>
