<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/partials.php';
initSession();

initDatabase();
requireLogin();

$db = getDb();

/**
 * Template-gebaseerde "AI-assistent": vertaalt echte berekende cijfers naar
 * een Nederlandse toelichting. Bewust GEEN externe LLM-aanroep — puur
 * stringopbouw op basis van database-waarden, zodat het altijd deterministisch
 * en uitlegbaar blijft (belangrijk in een compliance-context).
 */
function generateNarrative(string $category, float $current, ?float $previous, ?array $benchmark): string
{
    $sentences = [];
    if ($previous !== null) {
        $pct = pctChange($previous, $current);
        if ($pct !== null) {
            $richting = $pct >= 0 ? 'gestegen' : 'gedaald';
            $sentences[] = sprintf(
                'Dit kwartaal is de kernindicator voor %s met %s%% %s ten opzichte van het vorige kwartaal.',
                $category,
                number_format(abs($pct), 1, ',', '.'),
                $richting
            );
        }
    }
    if ($benchmark !== null) {
        $pctB = pctChange((float) $benchmark['value'], $current);
        if ($pctB !== null) {
            $vergelijking = $pctB >= 0 ? 'boven' : 'onder';
            $sentences[] = sprintf(
                'Daarmee ligt de huidige waarde %s%% %s het sectorgemiddelde (%s %s).',
                number_format(abs($pctB), 1, ',', '.'),
                $vergelijking,
                number_format($benchmark['value'], 1, ',', '.'),
                $benchmark['unit']
            );
        }
    }
    if (!$sentences) {
        $sentences[] = sprintf('Voor %s zijn nog onvoldoende historische data beschikbaar voor een trendtoelichting.', $category);
    }
    return implode(' ', $sentences);
}

$templates = [
    'volledig' => ['label' => 'Volledig ESG-rapport', 'categories' => ['Energie', 'Water', 'Afval', 'Mobiliteit', 'Sociaal', 'Governance']],
    'esrs_e1'  => ['label' => 'ESRS E1 — Klimaat', 'categories' => ['Energie']],
    'esrs_s1'  => ['label' => 'ESRS S1 — Eigen personeel', 'categories' => ['Sociaal']],
    'esrs_g1'  => ['label' => 'ESRS G1 — Governance', 'categories' => ['Governance']],
];
$template = is_string($_GET['template'] ?? null) ? $_GET['template'] : 'volledig';
if (!isset($templates[$template])) {
    $template = 'volledig';
}
$activeCategories = $templates[$template]['categories'];

// Rapportages tonen alléén gevalideerde (goedgekeurde) data — kernpunt van de
// datavalidatie-workflow: concept/ter-goedkeuring cijfers verschijnen hier niet.
$placeholders = implode(',', array_fill(0, count($activeCategories), '?'));
$stmt = $db->prepare("SELECT * FROM esg_metrics WHERE status = 'goedgekeurd' AND category IN ($placeholders) AND period = 'Q1 2026' ORDER BY category, metric_name");
$stmt->execute($activeCategories);
$reportMetrics = $stmt->fetchAll();

$notApprovedCount = 0;
$stmt2 = $db->prepare("SELECT COUNT(*) FROM esg_metrics WHERE status != 'goedgekeurd' AND category IN ($placeholders) AND period = 'Q1 2026'");
$stmt2->execute($activeCategories);
$notApprovedCount = (int) $stmt2->fetchColumn();

$trendMetricNames = [
    'Energie'    => 'Totaal CO2 besparing',
    'Water'      => 'Water recycling percentage',
    'Sociaal'    => 'Medewerkerstevredenheid score',
    'Governance' => 'Onafhankelijke bestuursleden',
];

$byCategory = [];
foreach ($activeCategories as $cat) {
    $byCategory[$cat] = array_values(array_filter($reportMetrics, fn($m) => $m['category'] === $cat));
}

$checklistTotals = $db->query("SELECT framework, SUM(status='gereed') AS gereed, COUNT(*) AS totaal FROM esg_checklist_items GROUP BY framework")->fetchAll();

renderPageStart('Rapportages', 'reports');
?>

<div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6 no-print">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Geavanceerde rapportages</h1>
        <p class="text-sm text-slate-500 mt-1">EU-ESRS-rapportsjablonen op basis van uitsluitend <strong>goedgekeurde</strong> data.</p>
    </div>
    <div class="flex gap-2">
        <button onclick="window.print()" class="hz-btn hz-btn--primary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6v-8z"/></svg>
            Printen / Exporteren naar PDF
        </button>
        <span class="hz-tooltip">
            <button disabled class="hz-btn hz-btn--secondary opacity-60 cursor-not-allowed">PowerPoint-export (binnenkort beschikbaar)</button>
            <span class="hz-tooltip__bubble" style="white-space:normal;width:260px;bottom:auto;top:125%;">Een echte PPTX-generator (bijv. PHPPresentation) vereist Composer-dependencies die buiten de scope van deze vanilla-PHP demo vallen. Bewust als placeholder gelabeld.</span>
        </span>
    </div>
</div>

<div class="flex flex-wrap gap-2 mb-6 no-print">
    <?php foreach ($templates as $key => $t): ?>
        <a href="<?= BASE ?>/reports.php?template=<?= $key ?>"
           class="px-4 py-2 text-sm font-medium rounded-lg border <?= $template === $key ? 'bg-brand-500 text-white border-brand-500' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50' ?>">
            <?= e($t['label']) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($notApprovedCount > 0): ?>
<div class="mb-6 px-4 py-3 rounded-lg text-sm bg-amber-50 text-amber-800 border border-amber-200 no-print">
    <?= $notApprovedCount ?> metric(s) voor deze scope zijn nog niet goedgekeurd en ontbreken daarom in dit rapport. Keur ze goed via <a href="<?= BASE ?>/metrics.php" class="underline font-medium">Data-invoer</a>.
</div>
<?php endif; ?>

<!-- ── Printbare rapportinhoud ──────────────────────────────────── -->
<div class="bg-white rounded-xl shadow-sm border border-slate-200 p-8 print:shadow-none print:border-0">
    <div class="mb-8 pb-4 border-b border-slate-200">
        <h2 class="text-xl font-bold text-slate-900"><?= e($templates[$template]['label']) ?></h2>
        <p class="text-sm text-slate-500">Periode: Q1 2026 &middot; Gegenereerd op <?= date('d-m-Y') ?> &middot; Alleen goedgekeurde data</p>
    </div>

    <?php foreach ($byCategory as $cat => $rows): ?>
        <?php
        $previous = null;
        if (isset($trendMetricNames[$cat])) {
            $stmt3 = $db->prepare('SELECT value FROM esg_metrics WHERE category = ? AND metric_name = ? AND period = ?');
            $stmt3->execute([$cat, $trendMetricNames[$cat], 'Q4 2025']);
            $v = $stmt3->fetchColumn();
            $previous = $v !== false ? (float) $v : null;
        }
        $currentKeyMetric = null;
        foreach ($rows as $r) {
            if (isset($trendMetricNames[$cat]) && $r['metric_name'] === $trendMetricNames[$cat]) {
                $currentKeyMetric = (float) $r['value'];
            }
        }
        $benchmark = SECTOR_BENCHMARKS[$cat] ?? null;
        ?>
        <section class="mb-10">
            <h3 class="text-lg font-semibold text-slate-900 mb-2"><?= e($cat) ?></h3>
            <?php if (!$rows): ?>
                <p class="text-sm text-slate-400 italic">Geen goedgekeurde metrics voor deze categorie in Q1 2026.</p>
            <?php else: ?>
                <table class="w-full text-sm mb-3">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-500">
                            <th class="py-2 pr-4">Metric</th>
                            <th class="py-2 pr-4 text-right">Waarde</th>
                            <th class="py-2 pr-4">Eenheid</th>
                            <th class="py-2 pr-4 text-right">Doel</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="py-2 pr-4 font-medium text-slate-800"><?= e($r['metric_name']) ?><?= $r['scope'] ? ' (' . e($r['scope']) . ')' : '' ?></td>
                                <td class="py-2 pr-4 text-right tabular-nums"><?= number_format((float) $r['value'], 2, ',', '.') ?></td>
                                <td class="py-2 pr-4 text-slate-500"><?= e($r['unit']) ?></td>
                                <td class="py-2 pr-4 text-right tabular-nums text-slate-500"><?= number_format((float) $r['target_value'], 2, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($currentKeyMetric !== null): ?>
                <div class="bg-emerald-50 border border-emerald-100 rounded-lg p-3 text-sm text-emerald-900">
                    <p class="font-medium mb-0.5">AI-toelichting (automatisch gegenereerd)</p>
                    <p><?= e(generateNarrative($cat, $currentKeyMetric, $previous, $benchmark)) ?></p>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <section class="mb-4">
        <h3 class="text-lg font-semibold text-slate-900 mb-2">Framework-voortgang</h3>
        <div class="grid grid-cols-3 gap-4">
            <?php foreach ($checklistTotals as $c): ?>
                <?php $pct = $c['totaal'] > 0 ? round(($c['gereed'] / $c['totaal']) * 100) : 0; ?>
                <div>
                    <p class="text-sm font-medium text-slate-700"><?= e($c['framework']) ?></p>
                    <p class="text-2xl font-bold text-slate-900"><?= $pct ?>%</p>
                    <p class="text-xs text-slate-400"><?= (int) $c['gereed'] ?> van <?= (int) $c['totaal'] ?> items gereed</p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<?php renderPageEnd(); ?>
