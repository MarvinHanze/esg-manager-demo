<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/partials.php';
initSession();

initDatabase();

$error = '';

if (isLoggedIn()) {
    header('Location: ' . BASE . '/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfOk()) {
        $error = 'Ongeldige aanvraag. Ververs de pagina en probeer opnieuw.';
    } else {
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $error = 'Vul alle velden in.';
        } elseif (($lockedMinutes = loginLockoutMinutesLeft($email)) > 0) {
            $error = "Te veel mislukte inlogpogingen. Probeer het over $lockedMinutes minuten opnieuw.";
        } else {
            $db = getDb();
            $stmt = $db->prepare('SELECT * FROM esg_users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user !== false && password_verify($password, (string) $user['password'])) {
                clearFailedLogins($email);
                session_regenerate_id(true);
                $_SESSION['user_id']    = (int) $user['id'];
                $_SESSION['user_email'] = (string) $user['email'];
                $_SESSION['user_name']  = (string) $user['name'];
                $_SESSION['user_role']  = (string) $user['role'];
                header('Location: ' . BASE . '/index.php');
                exit;
            }

            recordFailedLogin($email);
            $error = 'Ongeldige inloggegevens.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inloggen — ESG Manager</title>
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
        body.esg-body {
            background:
                radial-gradient(circle at 10% 10%, rgba(16,185,129,.14) 0%, transparent 40%),
                radial-gradient(circle at 90% 25%, rgba(5,150,105,.16) 0%, transparent 45%),
                radial-gradient(circle at 30% 90%, rgba(16,185,129,.12) 0%, transparent 50%),
                linear-gradient(160deg, #f0fdf6 0%, #ecfdf5 45%, #e6f7ef 100%);
            position: relative;
            overflow-x: hidden;
        }
        .esg-blob { position: absolute; border-radius: 42% 58% 63% 37% / 41% 44% 56% 59%; filter: blur(1px); opacity: .55; z-index: 0; }
        .esg-blob--a { width: 300px; height: 300px; background: radial-gradient(circle, rgba(16,185,129,.35), transparent 70%); top: -100px; left: -100px; }
        .esg-blob--b { width: 260px; height: 260px; background: radial-gradient(circle, rgba(5,150,105,.3), transparent 70%); bottom: -90px; right: -90px; border-radius: 58% 42% 37% 63% / 59% 56% 44% 41%; }
        .esg-leaf-icon { position: absolute; opacity: .16; color: #059669; z-index: 0; }
        .esg-card {
            position: relative; z-index: 1;
            backdrop-filter: blur(6px);
        }
        .esg-eco-row { display:flex; justify-content:center; gap:1.5rem; margin-top:1.25rem; }
        .esg-eco-row span { display:flex; align-items:center; gap:.35rem; font-size:.72rem; color:#047857; font-weight:600; }
        .esg-eco-row svg { width:14px; height:14px; }
    </style>
</head>
<body class="h-full esg-body antialiased flex items-center justify-center px-4">

<div class="esg-blob esg-blob--a"></div>
<div class="esg-blob esg-blob--b"></div>
<svg class="esg-leaf-icon" style="top:8%;right:12%;width:70px;height:70px;" viewBox="0 0 24 24" fill="currentColor"><path d="M17 8C8 10 5.9 16.17 3.82 21.34l1.89.66l1.15-2.6c.86.16 1.62.29 2.34.29c1.7 0 3.02-.6 4.5-1.7c1.3-1 3.2-2.5 3.2-4.99C17 11.5 17 8 17 8z"/></svg>
<svg class="esg-leaf-icon" style="bottom:10%;left:8%;width:56px;height:56px;" viewBox="0 0 24 24" fill="currentColor"><path d="M6 3C6 3 4 12 8 16c2.5 2.5 6 2 8-2c2.5-4.5 1-9 1-9S12 6 6 3z"/></svg>

<div class="w-full max-w-sm esg-card">
    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gradient-to-br from-emerald-400 to-emerald-600 mb-4 shadow-lg shadow-emerald-500/25">
            <svg class="w-9 h-9 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2z"/>
                <path d="M12 6c-2 0-4 2-4 5s2 5 4 5c1 0 2.5-1 3-2.5"/>
                <path d="M12 6v-2M16 8l1.5-1M17 12h2M16 16l1.5 1"/>
                <path d="M12 18v2M8 16l-1.5 1M7 12H5M8 8L6.5 7"/>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-slate-900">ESG Manager</h1>
        <p class="text-sm text-emerald-700 mt-1">Duurzaamheidsdashboard</p>
    </div>

    <div class="bg-white/90 rounded-3xl shadow-xl shadow-emerald-900/5 border border-emerald-100 p-6">
        <?php if ($error !== ''): ?>
            <div class="mb-4 px-4 py-3 rounded-lg text-sm font-medium bg-red-50 text-red-700 border border-red-200">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <?= csrfField() ?>
            <div>
                <label for="email" class="block text-sm font-medium text-slate-700 mb-1">E-mailadres</label>
                <input type="email" name="email" id="email" required autofocus
                       value="<?= htmlspecialchars($_POST['email'] ?? 'admin@demo.nl') ?>"
                       class="w-full px-3 py-2.5 text-sm border border-emerald-200 rounded-xl focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-shadow"
                       placeholder="admin@demo.nl">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Wachtwoord</label>
                <input type="password" name="password" id="password" required value="demo123"
                       class="w-full px-3 py-2.5 text-sm border border-emerald-200 rounded-xl focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-shadow"
                       placeholder="demo123">
            </div>
            <button type="submit"
                    class="w-full py-2.5 px-4 text-sm font-semibold text-white bg-gradient-to-r from-emerald-500 to-emerald-600 rounded-xl hover:from-emerald-600 hover:to-emerald-700 transition-colors shadow-sm">
                Inloggen
            </button>
        </form>
        <div class="esg-eco-row">
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M2 12h20"/></svg>CO₂</span>
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h7l-1 8 10-12h-7z"/></svg>Energie</span>
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C8 8 5 11.5 5 15a7 7 0 0014 0c0-3.5-3-7-7-13z"/></svg>Water</span>
        </div>
    </div>

    <div class="mt-6 text-center text-xs text-emerald-700/70 space-y-0.5">
        <p>Demo-accounts (wachtwoord voor alle drie: <strong>demo123</strong>)</p>
        <p>admin@demo.nl &middot; milieu@demo.nl &middot; compliance@demo.nl</p>
    </div>
</div>

</body>
</html>
