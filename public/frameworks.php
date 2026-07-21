<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/partials.php';

initDatabase();
requireLogin();

$db = getDb();
$message = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfOk()) {
        $message = 'Ongeldige aanvraag.';
        $msgType = 'error';
    } elseif (($_POST['action'] ?? '') === 'update_status') {
        $id     = (int) ($_POST['id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? ''));
        $notes  = trim((string) ($_POST['notes'] ?? ''));
        if ($id > 0 && in_array($status, ['open', 'in_uitvoering', 'gereed'], true)) {
            $stmt = $db->prepare('UPDATE esg_checklist_items SET status = ?, notes = ? WHERE id = ?');
            $stmt->execute([$status, $notes !== '' ? $notes : null, $id]);
            auditLog('update', 'checklist_item', $id, $status);
            $message = 'Checklistitem bijgewerkt.';
        }
    }
}

$framework = $_GET['framework'] ?? 'CSRD';
if (!in_array($framework, ['CSRD', 'GRI', 'SASB'], true)) {
    $framework = 'CSRD';
}

$stmt = $db->prepare('SELECT * FROM esg_checklist_items WHERE framework = ? ORDER BY id');
$stmt->execute([$framework]);
$items = $stmt->fetchAll();

$totals = $db->query("SELECT framework, SUM(status='gereed') AS gereed, COUNT(*) AS totaal FROM esg_checklist_items GROUP BY framework")->fetchAll();
$totalsByFramework = [];
foreach ($totals as $t) {
    $totalsByFramework[$t['framework']] = $t;
}

renderPageStart('Frameworks', 'frameworks');
renderFlash($message, $msgType);
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-slate-900">Framework-overzichten</h1>
    <p class="text-sm text-slate-500 mt-1">Standaard checklists die aansluiten op <?= tooltip('CSRD', 'csrd') ?>, <?= tooltip('GRI', 'gri') ?> en <?= tooltip('SASB', 'sasb') ?>. Gebruik dit om per rapportagekader de voortgang te bewaken.</p>
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <?php foreach (['CSRD', 'GRI', 'SASB'] as $fw): ?>
        <?php
        $t = $totalsByFramework[$fw] ?? ['gereed' => 0, 'totaal' => 0];
        $pct = $t['totaal'] > 0 ? round(($t['gereed'] / $t['totaal']) * 100) : 0;
        ?>
        <a href="<?= BASE ?>/frameworks.php?framework=<?= $fw ?>" class="hz-card block hover:shadow-md transition-shadow <?= $framework === $fw ? 'ring-2 ring-brand-500' : '' ?>">
            <div class="flex items-center justify-between mb-2">
                <p class="font-semibold text-slate-900"><?= $fw ?></p>
                <span class="text-sm text-slate-500"><?= (int) $t['gereed'] ?>/<?= (int) $t['totaal'] ?></span>
            </div>
            <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                <div class="h-full bg-brand-500 rounded-full" style="width: <?= $pct ?>%"></div>
            </div>
            <p class="text-xs text-slate-400 mt-1"><?= $pct ?>% gereed</p>
        </a>
    <?php endforeach; ?>
</div>

<div class="hz-card !p-0 overflow-hidden">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-slate-200 bg-slate-50">
                <th class="px-6 py-3 text-left font-semibold text-slate-600">Code</th>
                <th class="px-6 py-3 text-left font-semibold text-slate-600">Onderwerp</th>
                <th class="px-6 py-3 text-left font-semibold text-slate-600">Status</th>
                <th class="px-6 py-3 text-left font-semibold text-slate-600">Notitie</th>
                <th class="px-6 py-3 text-right font-semibold text-slate-600">Bijgewerkt</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
        <?php foreach ($items as $item): ?>
            <tr>
                <td class="px-6 py-3 font-medium text-slate-900 whitespace-nowrap"><?= e($item['item_code']) ?></td>
                <td class="px-6 py-3 text-slate-700"><?= e($item['item_text']) ?></td>
                <td class="px-6 py-3">
                    <form method="post" class="flex items-center gap-2">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                        <select name="status" onchange="this.form.requestSubmit()" class="text-xs border border-slate-300 rounded-md px-2 py-1">
                            <option value="open" <?= $item['status'] === 'open' ? 'selected' : '' ?>>Open</option>
                            <option value="in_uitvoering" <?= $item['status'] === 'in_uitvoering' ? 'selected' : '' ?>>In uitvoering</option>
                            <option value="gereed" <?= $item['status'] === 'gereed' ? 'selected' : '' ?>>Gereed</option>
                        </select>
                        <input type="hidden" name="notes" value="<?= e((string) $item['notes']) ?>">
                    </form>
                </td>
                <td class="px-6 py-3 text-slate-500 max-w-xs truncate" title="<?= e((string) $item['notes']) ?>"><?= e((string) $item['notes']) ?: '—' ?></td>
                <td class="px-6 py-3 text-right text-xs text-slate-400"><?= e($item['updated_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php renderPageEnd(); ?>
