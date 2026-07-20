<?php

declare(strict_types=1);

define('BASE', '/esg-manager');
define('DEMO_RESET_MINUTES', 30);

define('DB_HOST', 'y11ovnrne4yk4p9zbhe39tti');
define('DB_NAME', 'demos');
define('DB_USER', 'mysql');
define('DB_PASS', '23ns613Dyo1vgiAOQCt2ABFZzujOsxuyROvqNk4unUoZxWpwN9nIPrMNTt4QFkzG');

/**
 * Create a PDO database connection.
 */
function getDb(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/**
 * Initialize database tables and auto-reset demo data.
 */
function initDatabase(): void
{
    $db = getDb();

    $db->exec('CREATE TABLE IF NOT EXISTS esg_metrics (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(50) NOT NULL,
        metric_name VARCHAR(100) NOT NULL,
        value DECIMAL(10,2) DEFAULT 0,
        unit VARCHAR(20),
        target_value DECIMAL(10,2) DEFAULT 0,
        period VARCHAR(20),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS esg_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) NOT NULL UNIQUE,
        setting_value VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS esg_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(100) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        name VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Seed default user if not exists
    $stmt = $db->prepare('SELECT COUNT(*) FROM esg_users WHERE email = ?');
    $stmt->execute(['admin@demo.nl']);
    if ((int) $stmt->fetchColumn() === 0) {
        $stmt = $db->prepare('INSERT INTO esg_users (email, password, name) VALUES (?, ?, ?)');
        $stmt->execute(['admin@demo.nl', password_hash('demo123', PASSWORD_DEFAULT), 'Admin']);
    }

    // Auto-reset check
    $stmt = $db->prepare('SELECT setting_value FROM esg_settings WHERE setting_key = ?');
    $stmt->execute(['last_reset']);
    $lastReset = $stmt->fetchColumn();

    $shouldReset = false;
    if ($lastReset === false) {
        $shouldReset = true;
    } else {
        $diff = time() - (int) $lastReset;
        if ($diff >= DEMO_RESET_MINUTES * 60) {
            $shouldReset = true;
        }
    }

    if ($shouldReset) {
        seedDemoData();
        $db->exec('DELETE FROM esg_settings WHERE setting_key = "last_reset"');
        $stmt = $db->prepare('INSERT INTO esg_settings (setting_key, setting_value) VALUES ("last_reset", ?)');
        $stmt->execute([(string) time()]);
    }
}

/**
 * Seed demo data with ~20 sustainability metrics.
 */
function seedDemoData(): void
{
    $db = getDb();
    $db->exec('DELETE FROM esg_metrics');

    $metrics = [
        ['Energie', 'KWh bespaard door LED-verlichting',      12500.00, 'kWh', 15000.00, 'Q1 2026'],
        ['Energie', 'CO2 reductie zonnepanelen',               8200.00,  'kg',  10000.00, 'Q1 2026'],
        ['Energie', 'Zonnepanelen opwek',                     45000.00, 'kWh', 50000.00, 'Q1 2026'],
        ['Energie', 'Gasbesparing isolatie',                    320.00,  'm3',  400.00,   'Q1 2026'],
        ['Energie', 'Totaal CO2 besparing',                    9500.00, 'kg',  12000.00, 'Q1 2026'],
        ['Water',   'Water bespaard door regenwater',          18000.00, 'L',   25000.00, 'Q1 2026'],
        ['Water',   'Water hergebruik spoelwater',              7500.00, 'L',   10000.00, 'Q1 2026'],
        ['Water',   'Water recycling percentage',                  68.50, '%',    80.00,  'Q1 2026'],
        ['Water',   'Leidingwater reductie',                    2100.00, 'L',   3000.00,  'Q1 2026'],
        ['Afval',   'Papier en karton gerecycled',               420.00, 'kg',  500.00,   'Q1 2026'],
        ['Afval',   'Plastic en metaal gerecycled',              180.00, 'kg',  250.00,   'Q1 2026'],
        ['Afval',   'Elektronisch afval ingeleverd',              35.00, 'kg',   50.00,   'Q1 2026'],
        ['Afval',   'Stortplaats reductie',                     -15.00, '%',   -20.00,  'Q1 2026'],
        ['Afval',   'GFT-afval gescheiden',                     210.00, 'kg',  300.00,   'Q1 2026'],
        ['Mobiliteit', 'Elektrische voertuig ritten',             342.00, 'trips', 500.00, 'Q1 2026'],
        ['Mobiliteit', 'Fiets forens kilometers',               4800.00, 'km',  6000.00,  'Q1 2026'],
        ['Mobiliteit', 'Openbaar vervoer gebruikt',              185.00, 'trips', 250.00, 'Q1 2026'],
        ['Mobiliteit', 'CO2 reductie mobiliteit',               1200.00, 'kg',  1500.00,  'Q1 2026'],
        ['Mobiliteit', 'Deelauto ritten',                         78.00, 'trips', 100.00, 'Q1 2026'],
    ];

    $stmt = $db->prepare(
        'INSERT INTO esg_metrics (category, metric_name, value, unit, target_value, period) VALUES (?, ?, ?, ?, ?, ?)'
    );

    foreach ($metrics as $m) {
        $stmt->execute($m);
    }
}

/**
 * Check if a user is logged in.
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0;
}

/**
 * Redirect to login if not authenticated.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: ' . BASE . '/login.php');
        exit;
    }
}

/**
 * Get current user data from session.
 */
function getUser(): array
{
    if (!isLoggedIn()) {
        return ['id' => 0, 'email' => '', 'name' => 'Gast'];
    }
    return [
        'id'    => (int) ($_SESSION['user_id'] ?? 0),
        'email' => (string) ($_SESSION['user_email'] ?? ''),
        'name'  => (string) ($_SESSION['user_name'] ?? 'Admin'),
    ];
}
