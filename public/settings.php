<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/partials.php';

initDatabase();
requireRole(['admin']);

$db = getDb();
$message = '';
$msgType = 'success';

$validRoles = ['admin', 'milieumanager', 'compliance_officer'];
$mockIntegrations = ['Financieel systeem', 'HR-systeem', 'EHS-systeem'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfOk()) {
        $message = 'Ongeldige aanvraag.';
        $msgType = 'error';
    } elseif (($_POST['action'] ?? '') === 'update_role') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $role   = trim((string) ($_POST['role'] ?? ''));
        if ($userId > 0 && in_array($role, $validRoles, true)) {
            $stmt = $db->prepare('UPDATE esg_users SET role = ? WHERE id = ?');
            $stmt->execute([$role, $userId]);
            auditLog('update_role', 'user', $userId, $role);
            $message = 'Rol bijgewerkt.';
        }
    } elseif (($_POST['action'] ?? '') === 'set_deadline') {
        $deadline = trim((string) ($_POST['deadline'] ?? ''));
        if ($deadline !== '') {
            $stmt = $db->prepare('SELECT COUNT(*) FROM esg_settings WHERE setting_key = "report_deadline"');
            $stmt->execute();
            if ((int) $stmt->fetchColumn() > 0) {
                $db->prepare('UPDATE esg_settings SET setting_value = ? WHERE setting_key = "report_deadline"')->execute([$deadline]);
            } else {
                $db->prepare('INSERT INTO esg_settings (setting_key, setting_value) VALUES ("report_deadline", ?)')->execute([$deadline]);
            }
            auditLog('update', 'setting', null, 'report_deadline=' . $deadline);
            $message = 'Rapportagedeadline bijgewerkt.';
        }
    } elseif (($_POST['action'] ?? '') === 'simulate_sync') {
        $integration = trim((string) ($_POST['integration'] ?? ''));
        if (in_array($integration, $mockIntegrations, true)) {
            // MOCK: geen echte externe API-aanroep. Gesimuleerd resultaat op basis van een vaste reeks.
            $recordsSynced = random_int(40, 260);
            $details = "$recordsSynced posten succesvol gesynchroniseerd (mock, geen echte koppeling)";
            $stmt = $db->prepare('INSERT INTO esg_integration_log (integration_name, action, status, details) VALUES (?, "sync", "success", ?)');
            $stmt->execute([$integration, $details]);
            auditLog('simulate_sync', 'integration', null, "$integration: $details");
            $message = "Simulatie voltooid voor $integration: $details";
        }
    }
}

$users = $db->query('SELECT id, email, name, role FROM esg_users ORDER BY role, name')->fetchAll();
$stmt = $db->prepare('SELECT setting_value FROM esg_settings WHERE setting_key = ?');
$stmt->execute(['report_deadline']);
$deadline = $stmt->fetchColumn() ?: '';
$integrationLog = $db->query('SELECT * FROM esg_integration_log ORDER BY id DESC LIMIT 15')->fetchAll();
$auditLogRows = $db->query('SELECT * FROM esg_audit_log ORDER BY id DESC LIMIT 40')->fetchAll();

renderPageStart('Instellingen', 'settings');
renderFlash($message, $msgType);
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-slate-900">Instellingen &amp; beheer</h1>
    <p class="text-sm text-slate-500 mt-1">Alleen zichtbaar voor de rol Beheerder (RBAC, least-privilege).</p>
</div>

<!-- ── RBAC ─────────────────────────────────────────────────────── -->
<div class="hz-card mb-8">
    <div class="hz-card__header"><h2 class="text-base font-semibold text-slate-900">Rollenbeheer (RBAC)</h2></div>
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-slate-200 text-left text-slate-500">
                <th class="py-2 pr-4">Naam</th>
                <th class="py-2 pr-4">E-mail</th>
                <th class="py-2 pr-4">Rol</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
        <?php foreach ($users as $u): ?>
            <tr>
                <td class="py-2 pr-4 font-medium text-slate-800"><?= e($u['name']) ?></td>
                <td class="py-2 pr-4 text-slate-500"><?= e($u['email']) ?></td>
                <td class="py-2 pr-4">
                    <form method="post" class="flex items-center gap-2">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_role">
                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                        <select name="role" onchange="this.form.requestSubmit()" class="text-xs border border-slate-300 rounded-md px-2 py-1">
                            <?php foreach ($validRoles as $r): ?>
                                <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(roleLabel($r)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ── Rapportagedeadline ───────────────────────────────────────── -->
<div class="hz-card mb-8">
    <div class="hz-card__header"><h2 class="text-base font-semibold text-slate-900">Rapportagedeadline (voor alerts op dashboard)</h2></div>
    <form method="post" class="flex items-end gap-3">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="set_deadline">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Deadline</label>
            <input type="date" name="deadline" value="<?= e($deadline) ?>" class="px-3 py-2 text-sm border border-slate-300 rounded-lg">
        </div>
        <button type="submit" class="hz-btn hz-btn--primary">Opslaan</button>
    </form>
</div>

<!-- ── Mock integraties ─────────────────────────────────────────── -->
<div class="hz-card mb-8">
    <div class="hz-card__header">
        <h2 class="text-base font-semibold text-slate-900">Systeemkoppelingen</h2>
        <span class="hz-badge hz-badge--orange">MOCK — geen echte koppeling</span>
    </div>
    <p class="text-sm text-slate-500 mb-4">Simuleert een import/synchronisatie met externe systemen. Er wordt geen enkele externe API aangeroepen; het resultaat is een gesimuleerd aantal posten.</p>
    <div class="flex flex-wrap gap-3 mb-4">
        <?php foreach ($mockIntegrations as $integration): ?>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="simulate_sync">
                <input type="hidden" name="integration" value="<?= e($integration) ?>">
                <button type="submit" class="hz-btn hz-btn--secondary">Simuleer sync — <?= e($integration) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-slate-200 text-left text-slate-500">
                <th class="py-2 pr-4">Systeem</th>
                <th class="py-2 pr-4">Status</th>
                <th class="py-2 pr-4">Details</th>
                <th class="py-2 pr-4 text-right">Datum</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
        <?php if (!$integrationLog): ?>
            <tr><td colspan="4" class="py-6 text-center text-slate-400">Nog geen simulaties uitgevoerd.</td></tr>
        <?php endif; ?>
        <?php foreach ($integrationLog as $log): ?>
            <tr>
                <td class="py-2 pr-4 font-medium text-slate-800"><?= e($log['integration_name']) ?></td>
                <td class="py-2 pr-4"><span class="hz-badge hz-badge--green"><?= e($log['status']) ?></span></td>
                <td class="py-2 pr-4 text-slate-500"><?= e((string) $log['details']) ?></td>
                <td class="py-2 pr-4 text-right text-xs text-slate-400"><?= e($log['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ── Audit trail ──────────────────────────────────────────────── -->
<div class="hz-card">
    <div class="hz-card__header">
        <h2 class="text-base font-semibold text-slate-900">Audit trail</h2>
        <span class="hz-badge hz-badge--gray">Alleen-lezen — onwijzigbaar (alleen INSERT)</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-slate-500">
                    <th class="py-2 pr-4">Tijdstip</th>
                    <th class="py-2 pr-4">Gebruiker</th>
                    <th class="py-2 pr-4">Rol</th>
                    <th class="py-2 pr-4">Actie</th>
                    <th class="py-2 pr-4">Entiteit</th>
                    <th class="py-2 pr-4">Details</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php foreach ($auditLogRows as $a): ?>
                <tr>
                    <td class="py-2 pr-4 text-xs text-slate-400 whitespace-nowrap"><?= e($a['created_at']) ?></td>
                    <td class="py-2 pr-4"><?= e($a['actor']) ?></td>
                    <td class="py-2 pr-4"><span class="hz-badge <?= roleBadgeClass($a['role']) ?>"><?= e(roleLabel($a['role'])) ?></span></td>
                    <td class="py-2 pr-4"><?= e($a['action']) ?></td>
                    <td class="py-2 pr-4 text-slate-500"><?= e($a['entity']) ?><?= $a['entity_id'] ? ' #' . (int) $a['entity_id'] : '' ?></td>
                    <td class="py-2 pr-4 text-slate-500 max-w-xs truncate" title="<?= e((string) $a['details']) ?>"><?= e((string) $a['details']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php renderPageEnd(); ?>
