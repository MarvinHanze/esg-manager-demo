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

// ── Mobiele veldnotitie (incidenten/observaties direct vanaf de vloer) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_note') {
    if (!csrfOk()) {
        $message = 'Ongeldige aanvraag.';
        $msgType = 'error';
    } else {
        $noteCategory = trim((string) ($_POST['note_category'] ?? ''));
        $noteText     = trim((string) ($_POST['note'] ?? ''));
        if ($noteCategory === '' || $noteText === '') {
            $message = 'Categorie en notitie zijn verplicht.';
            $msgType = 'error';
        } else {
            $stmt = $db->prepare('INSERT INTO esg_field_notes (category, note, reported_by) VALUES (?, ?, ?)');
            $stmt->execute([$noteCategory, $noteText, $currentUser['name']]);
            auditLog('create', 'field_note', (int) $db->lastInsertId(), $noteCategory);
            $message = 'Veldnotitie geregistreerd.';
        }
    }
}

// ── Aggregaten ───────────────────────────────────────────────────────
$metrics = $db->query('SELECT * FROM esg_metrics ORDER BY category, metric_name')->fetchAll();

function sumByCategory(array $metrics, string $cat, string $unit): float
{
    $total = 0.0;
    foreach ($metrics as $m) {
        if ($m['category'] === $cat && $m['unit'] === $unit) {
            $total += (float) $m['value'];
        }
    }
    return $total;
}

function fmtTotal(float $total): string
{
    if (abs($total) >= 1000) {
        return number_format($total / 1000, 1, ',', '.') . 'k';
    }
    return number_format($total, 0, ',', '.');
}

$co2Total    = sumByCategory($metrics, 'Energie', 'kg');
$energyTotal = sumByCategory($metrics, 'Energie', 'kWh');
$waterTotal  = sumByCategory($metrics, 'Water', 'L');
$wasteTotal  = sumByCategory($metrics, 'Afval', 'kg');

$categories = ['Energie', 'Water', 'Afval', 'Mobiliteit', 'Sociaal', 'Governance'];
$progressByCategory = [];
foreach ($categories as $cat) {
    $sumVal = 0.0;
    $sumTarget = 0.0;
    foreach ($metrics as $m) {
        if ($m['category'] === $cat && (float) $m['target_value'] !== 0.0) {
            $sumVal += (float) $m['value'];
            $sumTarget += (float) $m['target_value'];
        }
    }
    $progressByCategory[$cat] = $sumTarget > 0 ? min(100, round(($sumVal / $sumTarget) * 100, 1)) : 0;
}

// ── Trendreeks over periodes voor de belangrijkste indicatoren ───────
$periodOrder = ['Q3 2025', 'Q4 2025', 'Q1 2026'];
$trendMetricNames = [
    'Energie'    => 'Totaal CO2 besparing',
    'Water'      => 'Water recycling percentage',
    'Sociaal'    => 'Medewerkerstevredenheid score',
    'Governance' => 'Onafhankelijke bestuursleden',
];
$trendSeries = [];
foreach ($trendMetricNames as $cat => $name) {
    $values = array_fill_keys($periodOrder, null);
    foreach ($metrics as $m) {
        if ($m['category'] === $cat && $m['metric_name'] === $name && in_array($m['period'], $periodOrder, true)) {
            $values[$m['period']] = (float) $m['value'];
        }
    }
    $trendSeries[$cat] = ['name' => $name, 'values' => array_values($values)];
}

// ── Alerts: rapportagedeadline + KPI-overschrijding ───────────────────
$stmt = $db->prepare('SELECT setting_value FROM esg_settings WHERE setting_key = ?');
$stmt->execute(['report_deadline']);
$deadline = $stmt->fetchColumn();
$daysLeft = $deadline !== false ? (int) ceil((strtotime((string) $deadline) - time()) / 86400) : null;

$kpiOverruns = 0;
foreach ($metrics as $m) {
    $target = (float) $m['target_value'];
    if ($target > 0) {
        $progress = ((float) $m['value'] / $target) * 100;
        if ($progress < 50) {
            $kpiOverruns++;
        }
    }
}

$pendingApproval = 0;
foreach ($metrics as $m) {
    if ($m['status'] === 'ter_goedkeuring') {
        $pendingApproval++;
    }
}

// ── Recente veldnotities & auditlog (voor compliance/admin) ──────────
$fieldNotes = $db->query('SELECT * FROM esg_field_notes ORDER BY created_at DESC LIMIT 5')->fetchAll();
$recentAudit = [];
if (in_array($currentUser['role'], ['admin', 'compliance_officer'], true)) {
    $recentAudit = $db->query('SELECT * FROM esg_audit_log ORDER BY id DESC LIMIT 6')->fetchAll();
}

$checklistTotals = $db->query(
    "SELECT framework, SUM(status='gereed') AS gereed, COUNT(*) AS totaal FROM esg_checklist_items GROUP BY framework"
)->fetchAll();

renderPageStart('Dashboard', 'index');
renderFlash($message, $msgType);
?>

<!-- ── Alerts ─────────────────────────────────────────────────────── -->
<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-6">
    <?php if ($daysLeft !== null): ?>
        <div class="flex items-center gap-3 px-4 py-3 rounded-lg border <?= $daysLeft <= 21 ? 'bg-amber-50 border-amber-200 text-amber-800' : 'bg-slate-50 border-slate-200 text-slate-600' ?>">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <p class="text-sm"><strong>Rapportagedeadline:</strong> <?= e((string) $deadline) ?>
                (<?= $daysLeft >= 0 ? $daysLeft . ' dagen resterend' : 'verstreken' ?>)</p>
        </div>
    <?php endif; ?>
    <div class="flex items-center gap-3 px-4 py-3 rounded-lg border <?= $kpiOverruns > 0 ? 'bg-red-50 border-red-200 text-red-800' : 'bg-emerald-50 border-emerald-200 text-emerald-800' ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
        <p class="text-sm"><strong><?= $kpiOverruns ?> metric<?= $kpiOverruns === 1 ? '' : 's' ?></strong> onder 50% van de doelstelling.</p>
    </div>
</div>

<!-- ── Stat cards ─────────────────────────────────────────────────── -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <div class="hz-card hz-card--stat">
        <p class="hz-card__label">Totaal CO2 Besparing</p>
        <p class="hz-card__value"><?= fmtTotal($co2Total) ?></p>
        <p class="text-sm text-slate-500 mt-1">kilogram CO2</p>
    </div>
    <div class="hz-card hz-card--stat">
        <p class="hz-card__label">Energie Bespaard</p>
        <p class="hz-card__value"><?= fmtTotal($energyTotal) ?></p>
        <p class="text-sm text-slate-500 mt-1">kilowattuur</p>
    </div>
    <div class="hz-card hz-card--stat">
        <p class="hz-card__label">Water Teruggewonnen</p>
        <p class="hz-card__value"><?= fmtTotal($waterTotal) ?></p>
        <p class="text-sm text-slate-500 mt-1">liter</p>
    </div>
    <div class="hz-card hz-card--stat">
        <p class="hz-card__label">Afval Gerecycled</p>
        <p class="hz-card__value"><?= fmtTotal($wasteTotal) ?></p>
        <p class="text-sm text-slate-500 mt-1">kilogram</p>
    </div>
</div>

<!-- ── Rol-specifiek paneel ───────────────────────────────────────── -->
<?php if ($currentUser['role'] === 'compliance_officer'): ?>
<div class="hz-card mb-8 border-l-4 border-amber-400">
    <div class="hz-card__header"><h2 class="text-base font-semibold text-slate-900">Compliance-officer overzicht</h2></div>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <p class="text-2xl font-bold text-amber-600"><?= $pendingApproval ?></p>
            <p class="text-sm text-slate-500">metrics wachten op goedkeuring — <a href="<?= BASE ?>/metrics.php" class="text-brand-600 underline">bekijk in Data-invoer</a></p>
        </div>
        <?php foreach ($checklistTotals as $c): ?>
        <div>
            <p class="text-2xl font-bold text-slate-900"><?= (int) $c['gereed'] ?>/<?= (int) $c['totaal'] ?></p>
            <p class="text-sm text-slate-500"><?= e($c['framework']) ?> checklist gereed</p>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php elseif ($currentUser['role'] === 'admin'): ?>
<div class="hz-card mb-8 border-l-4 border-red-400">
    <div class="hz-card__header"><h2 class="text-base font-semibold text-slate-900">Beheerdersoverzicht</h2></div>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
        <div>
            <p class="text-2xl font-bold text-amber-600"><?= $pendingApproval ?></p>
            <p class="text-sm text-slate-500">metrics ter goedkeuring</p>
        </div>
        <div>
            <p class="text-2xl font-bold text-slate-900"><?= count($metrics) ?></p>
            <p class="text-sm text-slate-500">totaal aantal metrics</p>
        </div>
        <div>
            <a href="<?= BASE ?>/settings.php" class="hz-btn hz-btn--secondary">Beheer & audit trail</a>
        </div>
    </div>
    <?php if ($recentAudit): ?>
    <p class="text-xs font-semibold text-slate-400 uppercase mb-2">Recente audit trail</p>
    <ul class="text-sm divide-y divide-slate-100">
        <?php foreach ($recentAudit as $a): ?>
        <li class="py-1.5 flex justify-between gap-2">
            <span class="text-slate-600"><?= e($a['actor']) ?> — <?= e($a['action']) ?> (<?= e($a['entity']) ?>)</span>
            <span class="text-slate-400 shrink-0"><?= e($a['created_at']) ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="hz-card mb-8 border-l-4 border-emerald-400">
    <div class="hz-card__header"><h2 class="text-base font-semibold text-slate-900">Milieumanager overzicht</h2></div>
    <p class="text-sm text-slate-600">Je hebt <strong><?= $pendingApproval ?></strong> metric(s) ingediend die nog op goedkeuring wachten van de compliance officer.
        Ga naar <a href="<?= BASE ?>/metrics.php" class="text-brand-600 underline">Data-invoer</a> om nieuwe metingen toe te voegen via de wizard.</p>
</div>
<?php endif; ?>

<!-- ── Grafieken ──────────────────────────────────────────────────── -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <div class="hz-card">
        <div class="hz-card__header">
            <h2 class="text-base font-semibold text-slate-900">Trendanalyse (laatste 3 kwartalen)</h2>
        </div>
        <canvas id="trendChart" height="220"></canvas>
    </div>
    <div class="hz-card">
        <div class="hz-card__header">
            <h2 class="text-base font-semibold text-slate-900">Voortgang per categorie t.o.v. doel</h2>
        </div>
        <canvas id="progressChart" height="220"></canvas>
    </div>
</div>

<!-- ── Mobiele veldnotitie ────────────────────────────────────────── -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <div class="hz-card">
        <div class="hz-card__header">
            <h2 class="text-base font-semibold text-slate-900">Veldnotitie registreren</h2>
        </div>
        <p class="text-sm text-slate-500 mb-3">Mobiel-vriendelijk formulier om direct vanaf de locatie een observatie of incident vast te leggen.</p>
        <form method="post" class="space-y-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_note">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Categorie <span class="text-red-500">*</span></label>
                <select name="note_category" required class="w-full px-3 py-2.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-500 outline-none">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Notitie <span class="text-red-500">*</span></label>
                <textarea name="note" required rows="3" placeholder="bijv. lekkage bij leiding hal 3"
                    class="w-full px-3 py-2.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-500 outline-none"></textarea>
            </div>
            <button type="submit" class="hz-btn hz-btn--primary w-full sm:w-auto">Registreren</button>
        </form>
    </div>
    <div class="hz-card">
        <div class="hz-card__header"><h2 class="text-base font-semibold text-slate-900">Recente veldnotities</h2></div>
        <?php if (!$fieldNotes): ?>
            <p class="text-sm text-slate-400">Nog geen veldnotities geregistreerd.</p>
        <?php else: ?>
            <ul class="divide-y divide-slate-100 text-sm">
                <?php foreach ($fieldNotes as $n): ?>
                <li class="py-2">
                    <div class="flex items-center justify-between gap-2 mb-0.5">
                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium <?= categoryBadgeClass($n['category']) ?>"><?= e($n['category']) ?></span>
                        <span class="text-xs text-slate-400"><?= e($n['created_at']) ?></span>
                    </div>
                    <p class="text-slate-700"><?= e($n['note']) ?></p>
                    <p class="text-xs text-slate-400">door <?= e((string) $n['reported_by']) ?></p>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<footer class="mt-4 pb-8 text-center text-sm text-slate-400">
    ESG Manager Demo &middot; PHP 8.2 + Apache + MySQL
</footer>

<script>
const trendLabels = <?= json_encode($periodOrder) ?>;
const trendDatasets = <?= json_encode(array_map(fn($s) => ['label' => $s['name'], 'data' => $s['values']], array_values($trendSeries))) ?>;
const trendColors = ['#059669', '#0284c7', '#db2777', '#7c3aed'];
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: trendLabels,
        datasets: trendDatasets.map((d, i) => ({
            label: d.label, data: d.data, borderColor: trendColors[i % trendColors.length],
            backgroundColor: trendColors[i % trendColors.length], tension: 0.3, spanGaps: true,
        }))
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } } }
});

const progressLabels = <?= json_encode(array_keys($progressByCategory)) ?>;
const progressValues = <?= json_encode(array_values($progressByCategory)) ?>;
new Chart(document.getElementById('progressChart'), {
    type: 'bar',
    data: {
        labels: progressLabels,
        datasets: [
            { label: '% van doel behaald', data: progressValues, backgroundColor: '#10b981' },
            { label: 'Restant tot doel', data: progressValues.map(v => Math.max(0, 100 - v)), backgroundColor: '#e2e8f0' },
        ]
    },
    options: {
        indexAxis: 'y', responsive: true,
        scales: { x: { stacked: true, max: 100 }, y: { stacked: true } },
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }
    }
});
</script>

<?php renderPageEnd(); ?>
