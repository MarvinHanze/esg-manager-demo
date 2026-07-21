<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/partials.php';

initDatabase();
requireLogin();

$db = getDb();
$currentUser = getUser();
$action  = $_POST['action'] ?? $_GET['action'] ?? 'list';
$editId  = (int) ($_GET['edit'] ?? 0);
$message = '';
$msgType = 'success';

$categories = ['Energie', 'Water', 'Afval', 'Mobiliteit', 'Sociaal', 'Governance'];
$scopes     = ['', 'Scope 1', 'Scope 2', 'Scope 3'];

// ── Handle POST actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfOk()) {
        $message = 'Ongeldige aanvraag.';
        $msgType = 'error';
    } elseif ($action === 'create' || $action === 'update') {
        $category   = trim((string) ($_POST['category'] ?? ''));
        $metricName = trim((string) ($_POST['metric_name'] ?? ''));
        $value      = (float) ($_POST['value'] ?? 0);
        $unit       = trim((string) ($_POST['unit'] ?? ''));
        $target     = (float) ($_POST['target_value'] ?? 0);
        $period     = trim((string) ($_POST['period'] ?? ''));
        $scope      = trim((string) ($_POST['scope'] ?? ''));
        $scope      = $scope === '' ? null : $scope;

        if ($category === '' || $metricName === '' || $period === '') {
            $message = 'Categorie, metric naam en periode zijn verplicht.';
            $msgType = 'error';
        } else {
            if ($action === 'create') {
                $stmt = $db->prepare(
                    'INSERT INTO esg_metrics (category, metric_name, value, unit, target_value, period, scope, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, "concept")'
                );
                $stmt->execute([$category, $metricName, $value, $unit, $target, $period, $scope]);
                $newId = (int) $db->lastInsertId();
                auditLog('create', 'metric', $newId, "$category / $metricName");
                $message = 'Metric toegevoegd als concept. Dien deze in ter goedkeuring zodra de cijfers definitief zijn.';
            } else {
                $updateId = (int) ($_POST['id'] ?? 0);
                if ($updateId > 0) {
                    // Bewerken van een reeds goedgekeurde metric verplicht opnieuw te valideren.
                    $stmt = $db->prepare('SELECT status FROM esg_metrics WHERE id = ?');
                    $stmt->execute([$updateId]);
                    $existingStatus = $stmt->fetchColumn();
                    $newStatus = $existingStatus === 'goedgekeurd' ? 'concept' : $existingStatus;

                    $stmt = $db->prepare(
                        'UPDATE esg_metrics SET category=?, metric_name=?, value=?, unit=?, target_value=?, period=?, scope=?, status=? WHERE id=?'
                    );
                    $stmt->execute([$category, $metricName, $value, $unit, $target, $period, $scope, $newStatus, $updateId]);
                    auditLog('update', 'metric', $updateId, "$category / $metricName");
                    $message = $newStatus === 'concept' && $existingStatus === 'goedgekeurd'
                        ? 'Metric bijgewerkt. Status teruggezet naar concept omdat gevalideerde cijfers zijn gewijzigd.'
                        : 'Metric bijgewerkt.';
                }
            }
        }
        $editId = 0;
    } elseif ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId > 0) {
            $stmt = $db->prepare('SELECT status, category, metric_name FROM esg_metrics WHERE id = ?');
            $stmt->execute([$deleteId]);
            $row = $stmt->fetch();
            if ($row && $row['status'] === 'goedgekeurd' && $currentUser['role'] !== 'admin') {
                $message = 'Goedgekeurde metrics kunnen alleen door een beheerder worden verwijderd.';
                $msgType = 'error';
            } else {
                $stmt = $db->prepare('DELETE FROM esg_metrics WHERE id = ?');
                $stmt->execute([$deleteId]);
                auditLog('delete', 'metric', $deleteId, $row ? "{$row['category']} / {$row['metric_name']}" : null);
                $message = 'Metric verwijderd.';
            }
        }
    } elseif ($action === 'submit_for_approval') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare('UPDATE esg_metrics SET status = "ter_goedkeuring", submitted_by = ? WHERE id = ? AND status = "concept"');
            $stmt->execute([$currentUser['name'], $id]);
            auditLog('submit_for_approval', 'metric', $id);
            $message = 'Metric ingediend ter goedkeuring.';
        }
    } elseif ($action === 'approve' || $action === 'reject') {
        if (!in_array($currentUser['role'], ['compliance_officer', 'admin'], true)) {
            $message = 'Alleen een compliance officer of beheerder mag data goedkeuren of afwijzen.';
            $msgType = 'error';
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                if ($action === 'approve') {
                    $stmt = $db->prepare('UPDATE esg_metrics SET status = "goedgekeurd", approved_by = ?, approved_at = NOW() WHERE id = ? AND status = "ter_goedkeuring"');
                    $stmt->execute([$currentUser['name'], $id]);
                    auditLog('approve', 'metric', $id);
                    $message = 'Metric goedgekeurd en beschikbaar voor rapportages.';
                } else {
                    $stmt = $db->prepare('UPDATE esg_metrics SET status = "concept", approved_by = NULL, approved_at = NULL WHERE id = ? AND status = "ter_goedkeuring"');
                    $stmt->execute([$id]);
                    auditLog('reject', 'metric', $id);
                    $message = 'Metric afgewezen en teruggezet naar concept.';
                }
            }
        }
    }
}

// ── Fetch data ───────────────────────────────────────────────────────
$search       = trim((string) ($_GET['q'] ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));
$where  = [];
$params = [];
if ($search !== '') {
    $where[] = '(category LIKE ? OR metric_name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($filterStatus !== '') {
    $where[] = 'status = ?';
    $params[] = $filterStatus;
}
$sql = 'SELECT * FROM esg_metrics';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY category, metric_name';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$metrics = $stmt->fetchAll();

// ── Edit data ────────────────────────────────────────────────────────
$editData = null;
if ($editId > 0) {
    $stmt = $db->prepare('SELECT * FROM esg_metrics WHERE id = ?');
    $stmt->execute([$editId]);
    $editData = $stmt->fetch() ?: null;
}

renderPageStart('Data-invoer', 'metrics');
renderFlash($message, $msgType);
?>

<div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Data-invoer & metrics</h1>
        <p class="text-sm text-slate-500 mt-1">Registreer meetgegevens via de wizard en dien ze in voor goedkeuring door de compliance officer.</p>
    </div>
    <button onclick="openWizard()" class="hz-btn hz-btn--primary shrink-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
        Nieuwe metric (wizard)
    </button>
</div>

<!-- ── Toolbar ────────────────────────────────────────────────── -->
<form method="get" class="flex flex-wrap items-center gap-2 mb-6">
    <div class="relative flex-1 min-w-[200px]">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <circle cx="11" cy="11" r="8"/><path stroke-linecap="round" d="M21 21l-4.35-4.35"/>
        </svg>
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Zoek metrics..."
               class="w-full pl-10 pr-4 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-500 outline-none">
    </div>
    <select name="status" class="px-3 py-2 text-sm border border-slate-300 rounded-lg">
        <option value="">Alle statussen</option>
        <option value="concept" <?= $filterStatus === 'concept' ? 'selected' : '' ?>>Concept</option>
        <option value="ter_goedkeuring" <?= $filterStatus === 'ter_goedkeuring' ? 'selected' : '' ?>>Ter goedkeuring</option>
        <option value="goedgekeurd" <?= $filterStatus === 'goedgekeurd' ? 'selected' : '' ?>>Goedgekeurd</option>
    </select>
    <button type="submit" class="hz-btn hz-btn--secondary">Filter</button>
</form>

<!-- ── Data table ─────────────────────────────────────────────── -->
<div class="hz-card !p-0 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 bg-slate-50">
                    <th class="px-6 py-3 text-left font-semibold text-slate-600">Categorie</th>
                    <th class="px-6 py-3 text-left font-semibold text-slate-600">Metric</th>
                    <th class="px-6 py-3 text-right font-semibold text-slate-600">Waarde</th>
                    <th class="px-6 py-3 text-right font-semibold text-slate-600">Doel</th>
                    <th class="px-6 py-3 text-right font-semibold text-slate-600">Voortgang</th>
                    <th class="px-6 py-3 text-left font-semibold text-slate-600">Status</th>
                    <th class="px-6 py-3 text-right font-semibold text-slate-600">Acties</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php if (empty($metrics)): ?>
                <tr><td colspan="7" class="px-6 py-12 text-center text-slate-400">Geen metrics gevonden.</td></tr>
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
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium <?= categoryBadgeClass($m['category']) ?>">
                                <?= e($m['category']) ?>
                            </span>
                            <?php if ($m['scope']): ?>
                                <div class="mt-1"><?= tooltip($m['scope'], 'scope' . substr((string) $m['scope'], -1)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 font-medium text-slate-900"><?= e($m['metric_name']) ?> <span class="text-slate-400 font-normal">(<?= e($m['unit']) ?>)</span>
                            <p class="text-xs text-slate-400 font-normal"><?= e($m['period']) ?></p>
                        </td>
                        <td class="px-6 py-4 text-right tabular-nums"><?= number_format((float) $m['value'], 2, ',', '.') ?></td>
                        <td class="px-6 py-4 text-right tabular-nums text-slate-500"><?= number_format((float) $m['target_value'], 2, ',', '.') ?></td>
                        <td class="px-6 py-4 w-32">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 h-2 bg-slate-100 rounded-full overflow-hidden">
                                    <div class="h-full <?= $barColor ?> rounded-full" style="width: <?= $progress ?>%"></div>
                                </div>
                                <span class="text-xs text-slate-500 tabular-nums w-10 text-right"><?= $progress ?>%</span>
                            </div>
                        </td>
                        <td class="px-6 py-4"><?= statusBadge((string) $m['status']) ?></td>
                        <td class="px-6 py-4">
                            <div class="flex items-center justify-end gap-1.5 flex-wrap">
                                <?php if ($m['status'] === 'concept'): ?>
                                <form method="post" class="inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="submit_for_approval">
                                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                    <button type="submit" class="hz-btn hz-btn--outline !py-1 !px-2 text-xs">Indienen</button>
                                </form>
                                <?php elseif ($m['status'] === 'ter_goedkeuring' && in_array($currentUser['role'], ['compliance_officer', 'admin'], true)): ?>
                                <form method="post" class="inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                    <button type="submit" class="hz-btn hz-btn--primary !py-1 !px-2 text-xs">Goedkeuren</button>
                                </form>
                                <form method="post" class="inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                    <button type="submit" class="hz-btn hz-btn--secondary !py-1 !px-2 text-xs">Afwijzen</button>
                                </form>
                                <?php endif; ?>
                                <a href="<?= BASE ?>/metrics.php?edit=<?= (int) $m['id'] ?>"
                                   class="hz-icon-btn" title="Bewerk">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </a>
                                <form method="post" class="inline" data-hz-confirm="Weet je zeker dat je deze metric wilt verwijderen?">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                    <button type="submit" class="hz-icon-btn" title="Verwijder">
                                        <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
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

<!-- ── Quick-edit modal (bestaande metric) ───────────────────────── -->
<div id="editModal" class="fixed inset-0 z-50 hidden items-center justify-center modal-backdrop" style="display:none;">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg mx-4 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
            <h2 class="text-lg font-semibold text-slate-900">Metric bewerken</h2>
            <button onclick="closeEditModal()" class="p-1 rounded-md text-slate-400 hover:text-slate-600 hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="post" class="p-6 space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $editData ? (int) $editData['id'] : 0 ?>">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Categorie <span class="text-red-500">*</span></label>
                <select name="category" required class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat) ?>" <?= $editData && $editData['category'] === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Metric naam <span class="text-red-500">*</span></label>
                <input type="text" name="metric_name" required value="<?= $editData ? e($editData['metric_name']) : '' ?>"
                       class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Waarde</label>
                    <input type="number" step="0.01" name="value" value="<?= $editData ? e((string) $editData['value']) : '0' ?>"
                           class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Eenheid</label>
                    <input type="text" name="unit" value="<?= $editData ? e($editData['unit']) : '' ?>"
                           class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Doelwaarde</label>
                    <input type="number" step="0.01" name="target_value" value="<?= $editData ? e((string) $editData['target_value']) : '0' ?>"
                           class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Periode <span class="text-red-500">*</span></label>
                    <input type="text" name="period" required value="<?= $editData ? e($editData['period']) : '' ?>"
                           class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Scope (optioneel, alleen relevant bij Energie)</label>
                <select name="scope" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                    <option value="">Geen</option>
                    <?php foreach (['Scope 1', 'Scope 2', 'Scope 3'] as $sc): ?>
                        <option value="<?= e($sc) ?>" <?= $editData && $editData['scope'] === $sc ? 'selected' : '' ?>><?= e($sc) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200">
                <button type="button" onclick="closeEditModal()" class="hz-btn hz-btn--secondary">Annuleren</button>
                <button type="submit" class="hz-btn hz-btn--primary">Opslaan</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Wizard modal (nieuwe metric) ───────────────────────────────── -->
<div id="wizardModal" class="fixed inset-0 z-50 hidden items-center justify-center modal-backdrop" style="display:none;">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-xl mx-4 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
            <h2 class="text-lg font-semibold text-slate-900">Nieuwe metric — stapsgewijs invoeren</h2>
            <button onclick="closeWizard()" class="p-1 rounded-md text-slate-400 hover:text-slate-600 hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- Voortgangsindicator -->
        <div class="px-6 pt-4">
            <div class="flex items-center gap-2 mb-4">
                <?php for ($i = 1; $i <= 3; $i++): ?>
                    <div class="flex-1 h-1.5 rounded-full bg-slate-200 wizard-step-bar" data-step-bar="<?= $i ?>"></div>
                <?php endfor; ?>
            </div>
            <p class="text-xs text-slate-500 mb-2" id="wizard-step-label">Stap 1 van 3 — Categorie & periode</p>
        </div>

        <form method="post" class="p-6 pt-2 space-y-4" id="wizardForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">

            <div data-step="1">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Categorie <span class="text-red-500">*</span></label>
                    <select name="category" id="wz-category" required class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4" id="wz-scope-wrap">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Scope (Energie: <?= tooltip('scope 1/2/3', 'scopes') ?>)</label>
                    <select name="scope" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                        <option value="">Geen</option>
                        <option value="Scope 1">Scope 1 — directe emissies</option>
                        <option value="Scope 2">Scope 2 — ingekochte energie</option>
                        <option value="Scope 3">Scope 3 — waardeketen</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Periode <span class="text-red-500">*</span></label>
                    <input type="text" name="period" required placeholder="bijv. Q2 2026" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                </div>
            </div>

            <div data-step="2" style="display:none;">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Metric naam <span class="text-red-500">*</span></label>
                    <input type="text" name="metric_name" id="wz-name" required placeholder="bijv. KWh bespaard" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Waarde <span class="text-red-500">*</span></label>
                        <input type="number" step="0.01" name="value" id="wz-value" required value="0" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Eenheid</label>
                        <input type="text" name="unit" id="wz-unit" placeholder="bijv. kWh, kg, L" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                    </div>
                </div>
            </div>

            <div data-step="3" style="display:none;">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Doelwaarde</label>
                    <input type="number" step="0.01" name="target_value" id="wz-target" value="0" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                </div>
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-4 text-sm">
                    <p class="font-semibold text-slate-700 mb-2">Controleer je gegevens</p>
                    <dl class="grid grid-cols-2 gap-y-1 text-slate-600">
                        <dt>Metric</dt><dd id="wz-review-name" class="text-right font-medium">—</dd>
                        <dt>Waarde</dt><dd id="wz-review-value" class="text-right font-medium">—</dd>
                    </dl>
                    <p class="text-xs text-slate-400 mt-2">De metric wordt opgeslagen als <strong>concept</strong> en moet nog ingediend worden ter goedkeuring.</p>
                </div>
            </div>

            <div class="flex items-center justify-between gap-3 pt-4 border-t border-slate-200">
                <button type="button" id="wz-back" onclick="wizardBack()" class="hz-btn hz-btn--secondary" style="visibility:hidden;">Terug</button>
                <div class="flex gap-2">
                    <button type="button" onclick="closeWizard()" class="hz-btn hz-btn--secondary">Annuleren</button>
                    <button type="button" id="wz-next" onclick="wizardNext()" class="hz-btn hz-btn--primary">Volgende</button>
                    <button type="submit" id="wz-submit" class="hz-btn hz-btn--primary" style="display:none;">Opslaan als concept</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// ── Quick-edit modal ──────────────────────────────────────────────
function closeEditModal() { document.getElementById('editModal').style.display = 'none'; }
<?php if ($editData !== null): ?>
document.getElementById('editModal').style.display = 'flex';
<?php endif; ?>
document.getElementById('editModal').addEventListener('click', function (e) { if (e.target === this) { closeEditModal(); window.location.href = '<?= BASE ?>/metrics.php'; } });

// ── Wizard ────────────────────────────────────────────────────────
var wizardStep = 1;
var stepLabels = { 1: 'Stap 1 van 3 — Categorie & periode', 2: 'Stap 2 van 3 — Metric & waarde', 3: 'Stap 3 van 3 — Doel & controle' };

function openWizard() {
    wizardStep = 1;
    document.getElementById('wizardForm').reset();
    document.getElementById('wz-scope-wrap').style.display = '';
    updateWizardView();
    document.getElementById('wizardModal').style.display = 'flex';
}
function closeWizard() { document.getElementById('wizardModal').style.display = 'none'; }

function updateWizardView() {
    document.querySelectorAll('[data-step]').forEach(function (el) {
        el.style.display = (parseInt(el.getAttribute('data-step'), 10) === wizardStep) ? '' : 'none';
    });
    document.querySelectorAll('[data-step-bar]').forEach(function (el) {
        var step = parseInt(el.getAttribute('data-step-bar'), 10);
        el.classList.toggle('bg-brand-500', step <= wizardStep);
        el.classList.toggle('bg-slate-200', step > wizardStep);
    });
    document.getElementById('wizard-step-label').textContent = stepLabels[wizardStep];
    document.getElementById('wz-back').style.visibility = wizardStep === 1 ? 'hidden' : 'visible';
    document.getElementById('wz-next').style.display = wizardStep === 3 ? 'none' : '';
    document.getElementById('wz-submit').style.display = wizardStep === 3 ? '' : 'none';

    if (wizardStep === 3) {
        document.getElementById('wz-review-name').textContent = document.getElementById('wz-name').value || '—';
        var val = document.getElementById('wz-value').value || '0';
        var unit = document.getElementById('wz-unit').value || '';
        document.getElementById('wz-review-value').textContent = val + ' ' + unit;
    }
}

function wizardNext() {
    if (wizardStep === 1) {
        var cat = document.getElementById('wz-category').value;
        var period = document.querySelector('[data-step="1"] input[name="period"]');
        if (!cat || !period.value.trim()) { period.reportValidity ? period.reportValidity() : alert('Vul de verplichte velden in.'); return; }
    }
    if (wizardStep === 2) {
        var name = document.getElementById('wz-name');
        if (!name.value.trim()) { name.reportValidity ? name.reportValidity() : alert('Metric naam is verplicht.'); return; }
    }
    wizardStep = Math.min(3, wizardStep + 1);
    updateWizardView();
}
function wizardBack() { wizardStep = Math.max(1, wizardStep - 1); updateWizardView(); }

document.getElementById('wz-category').addEventListener('change', function () {
    document.getElementById('wz-scope-wrap').style.display = this.value === 'Energie' ? '' : 'none';
});
document.getElementById('wizardModal').addEventListener('click', function (e) { if (e.target === this) closeWizard(); });
</script>

<?php renderPageEnd(); ?>
