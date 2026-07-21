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
$stakeholderCategories = ['Investeerder', 'Toezichthouder', 'NGO', 'Medewerkers', 'Klant', 'Overig'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfOk()) {
        $message = 'Ongeldige aanvraag.';
        $msgType = 'error';
    } elseif (($_POST['action'] ?? '') === 'create_stakeholder') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $org  = trim((string) ($_POST['organisation'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $cat  = trim((string) ($_POST['category'] ?? 'Overig'));
        if ($name === '') {
            $message = 'Naam is verplicht.';
            $msgType = 'error';
        } else {
            $stmt = $db->prepare('INSERT INTO esg_stakeholders (name, organisation, email, category) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, $org, $email, $cat]);
            auditLog('create', 'stakeholder', (int) $db->lastInsertId(), $name);
            $message = 'Stakeholder toegevoegd.';
        }
    } elseif (($_POST['action'] ?? '') === 'delete_stakeholder') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare('DELETE FROM esg_stakeholders WHERE id = ?');
            $stmt->execute([$id]);
            auditLog('delete', 'stakeholder', $id);
            $message = 'Stakeholder verwijderd.';
        }
    } elseif (($_POST['action'] ?? '') === 'send_message') {
        $stakeholderId = (int) ($_POST['stakeholder_id'] ?? 0);
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body    = trim((string) ($_POST['body'] ?? ''));
        if ($subject === '' || $body === '') {
            $message = 'Onderwerp en bericht zijn verplicht.';
            $msgType = 'error';
        } else {
            $recipientLabel = 'Alle stakeholders';
            if ($stakeholderId > 0) {
                $stmt = $db->prepare('SELECT name FROM esg_stakeholders WHERE id = ?');
                $stmt->execute([$stakeholderId]);
                $recipientLabel = (string) $stmt->fetchColumn();
            }
            $stmt = $db->prepare(
                'INSERT INTO esg_email_log (stakeholder_id, recipient_label, subject, body, sent_by) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$stakeholderId > 0 ? $stakeholderId : null, $recipientLabel, $subject, $body, $currentUser['name']]);
            auditLog('send_message', 'email_log', (int) $db->lastInsertId(), $recipientLabel);
            $message = 'Bericht gelogd richting "' . $recipientLabel . '" (simulatie — er is geen echte e-mail verzonden).';
        }
    }
}

$stakeholders = $db->query('SELECT * FROM esg_stakeholders ORDER BY category, name')->fetchAll();
$emailLog = $db->query('SELECT * FROM esg_email_log ORDER BY id DESC LIMIT 20')->fetchAll();

renderPageStart('Stakeholders', 'stakeholders');
renderFlash($message, $msgType);
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-slate-900">Stakeholdercommunicatie</h1>
    <p class="text-sm text-slate-500 mt-1">Gerichte communicatie met stakeholders. Berichten worden gelogd in een interne tabel — er wordt <strong>geen</strong> echte e-mail verzonden.</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <!-- Stakeholders lijst -->
    <div class="lg:col-span-1">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold text-slate-900">Stakeholders</h2>
            <button onclick="document.getElementById('stakeholderModal').style.display='flex'" class="hz-btn hz-btn--secondary !py-1 !px-2 text-xs">+ Nieuw</button>
        </div>
        <div class="space-y-2">
            <?php foreach ($stakeholders as $s): ?>
                <div class="hz-card !p-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="font-medium text-slate-900 text-sm"><?= e($s['name']) ?></p>
                            <p class="text-xs text-slate-500"><?= e((string) $s['organisation']) ?></p>
                            <span class="hz-badge hz-badge--gray mt-1"><?= e($s['category']) ?></span>
                        </div>
                        <form method="post" data-hz-confirm="Stakeholder verwijderen?">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_stakeholder">
                            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <button type="submit" class="text-slate-300 hover:text-red-500">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Bericht opstellen -->
    <div class="lg:col-span-2">
        <h2 class="text-base font-semibold text-slate-900 mb-3">Nieuw bericht (simulatie)</h2>
        <form method="post" class="hz-card space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="send_message">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Ontvanger</label>
                <select name="stakeholder_id" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                    <option value="0">Alle stakeholders</option>
                    <?php foreach ($stakeholders as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?> (<?= e($s['category']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Onderwerp <span class="text-red-500">*</span></label>
                <input type="text" name="subject" required placeholder="bijv. Update Q1 2026 duurzaamheidscijfers" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Bericht <span class="text-red-500">*</span></label>
                <textarea name="body" required rows="4" placeholder="Toelichting op de voortgang..." class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg"></textarea>
            </div>
            <button type="submit" class="hz-btn hz-btn--primary">Versturen (simulatie)</button>
        </form>
    </div>
</div>

<!-- Email log -->
<h2 class="text-lg font-semibold text-slate-900 mb-3">Verzendlog (intern, geen echte e-mail)</h2>
<div class="hz-card !p-0 overflow-hidden">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-slate-200 bg-slate-50">
                <th class="px-6 py-3 text-left font-semibold text-slate-600">Ontvanger</th>
                <th class="px-6 py-3 text-left font-semibold text-slate-600">Onderwerp</th>
                <th class="px-6 py-3 text-left font-semibold text-slate-600">Verzonden door</th>
                <th class="px-6 py-3 text-right font-semibold text-slate-600">Datum</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
        <?php if (!$emailLog): ?>
            <tr><td colspan="4" class="px-6 py-8 text-center text-slate-400">Nog geen berichten verzonden.</td></tr>
        <?php endif; ?>
        <?php foreach ($emailLog as $log): ?>
            <tr>
                <td class="px-6 py-3"><?= e((string) $log['recipient_label']) ?></td>
                <td class="px-6 py-3 font-medium text-slate-800"><?= e($log['subject']) ?></td>
                <td class="px-6 py-3 text-slate-500"><?= e((string) $log['sent_by']) ?></td>
                <td class="px-6 py-3 text-right text-xs text-slate-400"><?= e($log['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Nieuwe stakeholder modal -->
<div id="stakeholderModal" class="fixed inset-0 z-50 hidden items-center justify-center modal-backdrop" style="display:none;">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
            <h2 class="text-lg font-semibold text-slate-900">Nieuwe stakeholder</h2>
            <button onclick="document.getElementById('stakeholderModal').style.display='none'" class="p-1 rounded-md text-slate-400 hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="post" class="p-6 space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_stakeholder">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Naam <span class="text-red-500">*</span></label>
                <input type="text" name="name" required class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Organisatie</label>
                <input type="text" name="organisation" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">E-mail</label>
                <input type="email" name="email" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Categorie</label>
                <select name="category" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                    <?php foreach ($stakeholderCategories as $cat): ?><option value="<?= e($cat) ?>"><?= e($cat) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200">
                <button type="button" onclick="document.getElementById('stakeholderModal').style.display='none'" class="hz-btn hz-btn--secondary">Annuleren</button>
                <button type="submit" class="hz-btn hz-btn--primary">Opslaan</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('stakeholderModal').addEventListener('click', function (e) { if (e.target === this) this.style.display = 'none'; });
</script>

<?php renderPageEnd(); ?>
