<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/config.php';

initDatabase();

$error = '';

if (isLoggedIn()) {
    header('Location: ' . BASE . '/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Vul alle velden in.';
    } else {
        $db = getDb();
        $stmt = $db->prepare('SELECT * FROM esg_users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user !== false && password_verify($password, (string) $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']    = (int) $user['id'];
            $_SESSION['user_email'] = (string) $user['email'];
            $_SESSION['user_name']  = (string) $user['name'];
            header('Location: ' . BASE . '/index.php');
            exit;
        }

        $error = 'Ongeldige inloggegevens.';
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
</head>
<body class="h-full bg-slate-50 antialiased flex items-center justify-center px-4">

<div class="w-full max-w-sm">
    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-brand-50 mb-4">
            <svg class="w-9 h-9 text-brand-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2z"/>
                <path d="M12 6c-2 0-4 2-4 5s2 5 4 5c1 0 2.5-1 3-2.5"/>
                <path d="M12 6v-2M16 8l1.5-1M17 12h2M16 16l1.5 1"/>
                <path d="M12 18v2M8 16l-1.5 1M7 12H5M8 8L6.5 7"/>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-slate-900">ESG Manager</h1>
        <p class="text-sm text-slate-500 mt-1">Duurzaamheidsdashboard</p>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
        <?php if ($error !== ''): ?>
            <div class="mb-4 px-4 py-3 rounded-lg text-sm font-medium bg-red-50 text-red-700 border border-red-200">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <div>
                <label for="email" class="block text-sm font-medium text-slate-700 mb-1">E-mailadres</label>
                <input type="email" name="email" id="email" required autofocus
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       class="w-full px-3 py-2.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-shadow"
                       placeholder="admin@demo.nl">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Wachtwoord</label>
                <input type="password" name="password" id="password" required
                       class="w-full px-3 py-2.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-shadow"
                       placeholder="demo123">
            </div>
            <button type="submit"
                    class="w-full py-2.5 px-4 text-sm font-semibold text-white bg-brand-500 rounded-lg hover:bg-brand-600 transition-colors shadow-sm">
                Inloggen
            </button>
        </form>
    </div>

    <p class="mt-6 text-center text-xs text-slate-400">
        Demo inlog: admin@demo.nl / demo123
    </p>
</div>

</body>
</html>
