<?php

declare(strict_types=1);

require_once __DIR__ . '/assets/icons.php';

// ── Foutafhandeling: nooit ruwe stack traces / SQL-tekst naar de browser ──
// (wel loggen server-side, zodat er nog steeds te debuggen valt).
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    error_log('Onafgevangen exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8"><title>Er ging iets mis</title>'
        . '<script src="https://cdn.tailwindcss.com"></script></head>'
        . '<body class="min-h-screen flex items-center justify-center bg-slate-50">'
        . '<div class="text-center px-4"><p class="text-2xl font-bold text-slate-900 mb-2">Er is een onverwachte fout opgetreden</p>'
        . '<p class="text-slate-500 mb-4">Probeer het later opnieuw. Het technische detail is server-side gelogd.</p>'
        . '<a href="' . BASE . '/index.php" class="text-emerald-600 font-medium">Terug naar dashboard</a></div></body></html>';
    exit;
});

define('BASE', '/esg-manager');
define('DEMO_RESET_MINUTES', 30);
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

define('DB_HOST', 'y11ovnrne4yk4p9zbhe39tti');
define('DB_NAME', 'demos');
define('DB_USER', 'mysql');
define('DB_PASS', '23ns613Dyo1vgiAOQCt2ABFZzujOsxuyROvqNk4unUoZxWpwN9nIPrMNTt4QFkzG');

/**
 * Vaste sectorgemiddelde-constanten (demo-benchmarks). Bewust hardcoded en
 * niet database-gestuurd: dit zijn "vaste" referentiewaarden zoals gevraagd,
 * geen door gebruikers te muteren records.
 */
const SECTOR_BENCHMARKS = [
    'Energie'     => ['label' => 'CO2-besparing (sectorgemiddelde)',            'value' => 8000.0,  'unit' => 'kg'],
    'Water'       => ['label' => 'Waterhergebruik (sectorgemiddelde)',           'value' => 15000.0, 'unit' => 'L'],
    'Afval'       => ['label' => 'Afvalscheiding (sectorgemiddelde)',            'value' => 300.0,   'unit' => 'kg'],
    'Mobiliteit'  => ['label' => 'CO2-reductie mobiliteit (sectorgemiddelde)',   'value' => 900.0,   'unit' => 'kg'],
    'Sociaal'     => ['label' => 'Medewerkerstevredenheid (sectorgemiddelde)',   'value' => 7.2,     'unit' => 'score /10'],
    'Governance'  => ['label' => 'Onafhankelijke bestuursleden (sectorgemiddelde)', 'value' => 45.0, 'unit' => '%'],
];

/**
 * Uitleg van vakjargon voor de hz-tooltip componenten.
 */
const ESG_GLOSSARY = [
    'scope1'     => 'Scope 1: directe emissies uit bronnen die het bedrijf zelf bezit of beheert (bijv. eigen wagenpark, gasverwarming).',
    'scope2'     => 'Scope 2: indirecte emissies door de opwekking van ingekochte energie (elektriciteit, stadswarmte).',
    'scope3'     => 'Scope 3: alle overige indirecte emissies in de waardeketen (leveranciers, zakenreizen, woon-werkverkeer, afval).',
    'scopes'     => 'Scope 1 = directe emissies, Scope 2 = ingekochte energie, Scope 3 = overige waardeketen-emissies (GHG Protocol-indeling).',
    'csrd'       => 'CSRD (Corporate Sustainability Reporting Directive): EU-richtlijn die bedrijven verplicht om uitgebreid te rapporteren over duurzaamheid.',
    'gri'        => 'GRI (Global Reporting Initiative): internationale standaard voor duurzaamheidsverslaggeving, gericht op impact op mens, milieu en economie.',
    'sasb'       => 'SASB (Sustainability Accounting Standards Board): sectorspecifieke standaarden gericht op financieel materiële ESG-onderwerpen.',
    'esrs'       => 'ESRS (European Sustainability Reporting Standards): de gedetailleerde rapportagestandaarden die onder de CSRD worden gebruikt.',
    'materialiteit' => 'Dubbele materialiteit: het beoordelen van zowel de impact van het bedrijf op mens/milieu, als de impact van ESG-risico\'s op het bedrijf zelf.',
    'kpi'        => 'KPI (Key Performance Indicator): een meetbare indicator die aangeeft hoe goed een doelstelling wordt behaald.',
];

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
 * HTML-escape helper.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Start de sessie met veilige cookie-instellingen (httponly + samesite).
 * Moet vóór elk gebruik van $_SESSION aangeroepen worden; idempotent (mag
 * meerdere keren aangeroepen worden dankzij de session_status()-check).
 */
function initSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/**
 * Check whether a column already exists on a table (for safe incremental ALTERs).
 */
function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
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
        value DECIMAL(12,2) DEFAULT 0,
        unit VARCHAR(20),
        target_value DECIMAL(12,2) DEFAULT 0,
        period VARCHAR(20),
        scope VARCHAR(20) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT "concept",
        submitted_by VARCHAR(100) DEFAULT NULL,
        approved_by VARCHAR(100) DEFAULT NULL,
        approved_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Incremental column additions for environments where the table pre-existed.
    foreach ([
        'scope'        => 'ALTER TABLE esg_metrics ADD COLUMN scope VARCHAR(20) DEFAULT NULL',
        'status'       => 'ALTER TABLE esg_metrics ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT "concept"',
        'submitted_by' => 'ALTER TABLE esg_metrics ADD COLUMN submitted_by VARCHAR(100) DEFAULT NULL',
        'approved_by'  => 'ALTER TABLE esg_metrics ADD COLUMN approved_by VARCHAR(100) DEFAULT NULL',
        'approved_at'  => 'ALTER TABLE esg_metrics ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL',
    ] as $col => $sql) {
        if (!columnExists($db, 'esg_metrics', $col)) {
            $db->exec($sql);
        }
    }

    $db->exec('CREATE TABLE IF NOT EXISTS esg_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) NOT NULL UNIQUE,
        setting_value VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS esg_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(100) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        name VARCHAR(100) NOT NULL,
        role VARCHAR(30) NOT NULL DEFAULT "milieumanager"
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    if (!columnExists($db, 'esg_users', 'role')) {
        $db->exec('ALTER TABLE esg_users ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT "milieumanager"');
    }

    // Brute-force-bescherming op inloggen: mislukte pogingen per e-mailadres.
    $db->exec('CREATE TABLE IF NOT EXISTS esg_login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Onwijzigbare audit trail: alleen INSERT-statements raken deze tabel ooit aan.
    $db->exec('CREATE TABLE IF NOT EXISTS esg_audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        actor VARCHAR(150) NOT NULL,
        role VARCHAR(30) NOT NULL,
        action VARCHAR(50) NOT NULL,
        entity VARCHAR(50) NOT NULL,
        entity_id INT DEFAULT NULL,
        details VARCHAR(500) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS esg_checklist_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        framework VARCHAR(10) NOT NULL,
        item_code VARCHAR(50) NOT NULL,
        item_text VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT "open",
        notes VARCHAR(500) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Migratie: item_code was aanvankelijk VARCHAR(20), te kort voor rijen zonder
    // officiële framework-code (bv. "Dubbele materialiteitsanalyse"). Idempotent.
    $db->exec('ALTER TABLE esg_checklist_items MODIFY COLUMN item_code VARCHAR(50) NOT NULL');

    $db->exec('CREATE TABLE IF NOT EXISTS esg_custom_kpis (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        category VARCHAR(30) NOT NULL,
        unit VARCHAR(20) DEFAULT NULL,
        current_value DECIMAL(12,2) DEFAULT 0,
        previous_value DECIMAL(12,2) DEFAULT NULL,
        target_value DECIMAL(12,2) DEFAULT NULL,
        created_by VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS esg_stakeholders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        organisation VARCHAR(150) DEFAULT NULL,
        email VARCHAR(150) DEFAULT NULL,
        category VARCHAR(30) DEFAULT "Overig",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS esg_email_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        stakeholder_id INT DEFAULT NULL,
        recipient_label VARCHAR(150) DEFAULT NULL,
        subject VARCHAR(200) NOT NULL,
        body TEXT,
        sent_by VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS esg_integration_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        integration_name VARCHAR(50) NOT NULL,
        action VARCHAR(30) NOT NULL,
        status VARCHAR(20) NOT NULL,
        details VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $db->exec('CREATE TABLE IF NOT EXISTS esg_field_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(50) NOT NULL,
        note VARCHAR(500) NOT NULL,
        reported_by VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Seed default users (drie rollen) if not exists
    $demoUsers = [
        ['admin@demo.nl',      'demo123', 'Aisha admin',                'admin'],
        ['milieu@demo.nl',     'demo123', 'Milan milieumanager',        'milieumanager'],
        ['compliance@demo.nl', 'demo123', 'Carla compliance officer',   'compliance_officer'],
    ];
    foreach ($demoUsers as [$email, $pw, $name, $role]) {
        // ON DUPLICATE KEY UPDATE zorgt dat rol/naam zichzelf herstellen als een
        // eerdere deploy per ongeluk een afwijkende rol voor dit demo-account had
        // aangemaakt (data-drift-bescherming, wachtwoord blijft ongewijzigd).
        $stmt = $db->prepare(
            'INSERT INTO esg_users (email, password, name, role) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), role = VALUES(role)'
        );
        $stmt->execute([$email, password_hash($pw, PASSWORD_DEFAULT), $name, $role]);
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

        // ON DUPLICATE KEY UPDATE i.p.v. DELETE+INSERT: atomisch en dus veilig
        // als twee requests de reset-drempel tegelijk overschrijden (anders kan
        // de tweede INSERT falen op de unique constraint van setting_key nadat
        // de eerste al heeft ge-DELETE-en/INSERT, en de reset half laten slagen).
        $stmt = $db->prepare(
            'INSERT INTO esg_settings (setting_key, setting_value) VALUES ("last_reset", ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([(string) time()]);

        // Rapportagedeadline-instelling alleen initialiseren als hij nog niet bestaat
        // (bestaande, door een beheerder ingestelde deadline nooit overschrijven).
        $stmt = $db->prepare(
            'INSERT INTO esg_settings (setting_key, setting_value) VALUES ("report_deadline", ?)
             ON DUPLICATE KEY UPDATE setting_value = setting_value'
        );
        $stmt->execute([date('Y-m-d', strtotime('+18 days'))]);
    }
}

/**
 * Seed demo data: milieu-, sociale- en governance-metrics over meerdere periodes
 * (zodat trendanalyse en de template-gebaseerde "AI"-toelichting echte verschillen
 * kunnen berekenen), plus checklists, stakeholders en custom KPI's.
 */
function seedDemoData(): void
{
    $db = getDb();

    // Alles in één transactie: als het reseeden ergens halverwege faalt (bv. een
    // tijdelijk verbindingsprobleem), wordt alles teruggedraaid in plaats van dat
    // de demo-tabellen leeg/half gevuld achterblijven. last_reset wordt pas ná
    // een succesvolle seedDemoData()-aanroep bijgewerkt (zie initDatabase()), dus
    // een mislukte poging wordt door de volgende request automatisch opnieuw
    // geprobeerd (zelfde patroon als de tijdregistratie-app).
    $db->beginTransaction();
    try {
        seedDemoDataInner($db);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function seedDemoDataInner(PDO $db): void
{
    $db->exec('DELETE FROM esg_metrics');
    $db->exec('DELETE FROM esg_checklist_items');
    $db->exec('DELETE FROM esg_custom_kpis');
    $db->exec('DELETE FROM esg_stakeholders');

    // ── Actuele periode (Q1 2026) — status wisselend voor demonstratie van het goedkeuringsproces
    $metrics = [
        ['Energie', 'KWh bespaard door LED-verlichting',      12500.00, 'kWh', 15000.00, 'Q1 2026', 'Scope 2', 'goedgekeurd'],
        ['Energie', 'CO2 reductie zonnepanelen',               8200.00,  'kg',  10000.00, 'Q1 2026', 'Scope 2', 'goedgekeurd'],
        ['Energie', 'Zonnepanelen opwek',                     45000.00, 'kWh', 50000.00, 'Q1 2026', 'Scope 2', 'goedgekeurd'],
        ['Energie', 'Gasbesparing isolatie',                    320.00,  'm3',  400.00,   'Q1 2026', 'Scope 1', 'ter_goedkeuring'],
        ['Energie', 'Totaal CO2 besparing',                    9500.00, 'kg',  12000.00, 'Q1 2026', 'Scope 1', 'goedgekeurd'],
        ['Water',   'Water bespaard door regenwater',          18000.00, 'L',   25000.00, 'Q1 2026', null, 'goedgekeurd'],
        ['Water',   'Water hergebruik spoelwater',              7500.00, 'L',   10000.00, 'Q1 2026', null, 'goedgekeurd'],
        ['Water',   'Water recycling percentage',                  68.50, '%',    80.00,  'Q1 2026', null, 'concept'],
        ['Water',   'Leidingwater reductie',                    2100.00, 'L',   3000.00,  'Q1 2026', null, 'goedgekeurd'],
        ['Afval',   'Papier en karton gerecycled',               420.00, 'kg',  500.00,   'Q1 2026', null, 'goedgekeurd'],
        ['Afval',   'Plastic en metaal gerecycled',              180.00, 'kg',  250.00,   'Q1 2026', null, 'ter_goedkeuring'],
        ['Afval',   'Elektronisch afval ingeleverd',              35.00, 'kg',   50.00,   'Q1 2026', null, 'goedgekeurd'],
        ['Afval',   'Stortplaats reductie',                     -15.00, '%',   -20.00,  'Q1 2026', null, 'goedgekeurd'],
        ['Afval',   'GFT-afval gescheiden',                     210.00, 'kg',  300.00,   'Q1 2026', null, 'concept'],
        ['Mobiliteit', 'Elektrische voertuig ritten',             342.00, 'trips', 500.00, 'Q1 2026', 'Scope 3', 'goedgekeurd'],
        ['Mobiliteit', 'Fiets forens kilometers',               4800.00, 'km',  6000.00,  'Q1 2026', 'Scope 3', 'goedgekeurd'],
        ['Mobiliteit', 'Openbaar vervoer gebruikt',              185.00, 'trips', 250.00, 'Q1 2026', 'Scope 3', 'goedgekeurd'],
        ['Mobiliteit', 'CO2 reductie mobiliteit',               1200.00, 'kg',  1500.00,  'Q1 2026', 'Scope 3', 'goedgekeurd'],
        ['Mobiliteit', 'Deelauto ritten',                         78.00, 'trips', 100.00, 'Q1 2026', 'Scope 3', 'ter_goedkeuring'],
        ['Sociaal', 'Medewerkerstevredenheid score',                7.60, 'score /10', 8.00, 'Q1 2026', null, 'goedgekeurd'],
        ['Sociaal', 'Verzuimpercentage',                            3.80, '%',   3.00,   'Q1 2026', null, 'goedgekeurd'],
        ['Sociaal', 'Diversiteit vrouw/man in leidinggevende rollen', 42.00, '%', 50.00, 'Q1 2026', null, 'concept'],
        ['Sociaal', 'Trainingsuren per medewerker',                 14.50, 'uur', 20.00,  'Q1 2026', null, 'goedgekeurd'],
        ['Governance', 'Onafhankelijke bestuursleden',              38.00, '%',   45.00,  'Q1 2026', null, 'goedgekeurd'],
        ['Governance', 'Voltooide anti-corruptietrainingen',        88.00, '%',   100.00, 'Q1 2026', null, 'goedgekeurd'],
        ['Governance', 'Klokkenluidersmeldingen afgehandeld',      100.00, '%',   100.00, 'Q1 2026', null, 'goedgekeurd'],
        ['Governance', 'Data-privacy incidenten',                     2.00, 'aantal', 0.00, 'Q1 2026', null, 'ter_goedkeuring'],
    ];

    $stmt = $db->prepare(
        'INSERT INTO esg_metrics (category, metric_name, value, unit, target_value, period, scope, status, submitted_by, approved_by, approved_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($metrics as $m) {
        [$cat, $name, $val, $unit, $target, $period, $scope, $status] = $m;
        $submittedBy = $status !== 'concept' ? 'Milan milieumanager' : null;
        $approvedBy  = $status === 'goedgekeurd' ? 'Carla compliance officer' : null;
        $approvedAt  = $status === 'goedgekeurd' ? date('Y-m-d H:i:s', strtotime('-3 days')) : null;
        $stmt->execute([$cat, $name, $val, $unit, $target, $period, $scope, $status, $submittedBy, $approvedBy, $approvedAt]);
    }

    // ── Historische periodes (t.b.v. trendanalyse / AI-toelichting / grafieken) — direct goedgekeurd
    $history = [
        // categorie, metric_name, waarde, eenheid, doel, periode
        ['Energie', 'Totaal CO2 besparing', 7100.00, 'kg', 12000.00, 'Q3 2025'],
        ['Energie', 'Totaal CO2 besparing', 8300.00, 'kg', 12000.00, 'Q4 2025'],
        ['Water', 'Water recycling percentage', 61.00, '%', 80.00, 'Q3 2025'],
        ['Water', 'Water recycling percentage', 64.20, '%', 80.00, 'Q4 2025'],
        ['Afval', 'Stortplaats reductie', -9.00, '%', -20.00, 'Q3 2025'],
        ['Afval', 'Stortplaats reductie', -12.00, '%', -20.00, 'Q4 2025'],
        ['Sociaal', 'Medewerkerstevredenheid score', 7.10, 'score /10', 8.00, 'Q3 2025'],
        ['Sociaal', 'Medewerkerstevredenheid score', 7.30, 'score /10', 8.00, 'Q4 2025'],
        ['Governance', 'Onafhankelijke bestuursleden', 33.00, '%', 45.00, 'Q3 2025'],
        ['Governance', 'Onafhankelijke bestuursleden', 35.50, '%', 45.00, 'Q4 2025'],
    ];
    foreach ($history as $h) {
        [$cat, $name, $val, $unit, $target, $period] = $h;
        $stmt->execute([$cat, $name, $val, $unit, $target, $period, null, 'goedgekeurd', 'Milan milieumanager', 'Carla compliance officer', date('Y-m-d H:i:s', strtotime('-90 days'))]);
    }

    // ── Framework-checklists (CSRD / GRI / SASB)
    $checklist = [
        ['CSRD', 'ESRS E1', 'Klimaatverandering: broeikasgasemissies (scope 1/2/3) gerapporteerd', 'gereed'],
        ['CSRD', 'ESRS E3', 'Water- en mariene hulpbronnen in kaart gebracht', 'in_uitvoering'],
        ['CSRD', 'ESRS E5', 'Circulaire economie: afvalstromen en materiaalgebruik', 'in_uitvoering'],
        ['CSRD', 'ESRS S1', 'Eigen personeel: arbeidsomstandigheden en gelijke behandeling', 'gereed'],
        ['CSRD', 'ESRS G1', 'Ondernemingsgedrag: anti-corruptiebeleid', 'open'],
        ['CSRD', 'Dubbele materialiteitsanalyse', 'Materialiteitsanalyse uitgevoerd en gedocumenteerd', 'gereed'],
        ['GRI', 'GRI 302', 'Energieverbruik binnen de organisatie', 'gereed'],
        ['GRI', 'GRI 303', 'Water en effluenten', 'in_uitvoering'],
        ['GRI', 'GRI 305', 'Emissies (scope 1, 2 en 3)', 'gereed'],
        ['GRI', 'GRI 401', 'Werkgelegenheid en personeelsverloop', 'open'],
        ['GRI', 'GRI 405', 'Diversiteit en gelijke kansen', 'in_uitvoering'],
        ['GRI', 'GRI 205', 'Anti-corruptiebeleid en -training', 'open'],
        ['SASB', 'Energiebeheer', 'Sectorspecifieke energie-indicatoren gerapporteerd', 'gereed'],
        ['SASB', 'Personeelsbeheer', 'Materiële arbeidsindicatoren voor de sector', 'in_uitvoering'],
        ['SASB', 'Databeveiliging & privacy', 'Incidentregistratie en mitigatie', 'open'],
        ['SASB', 'Bedrijfsethiek', 'Klokkenluidersregeling gedocumenteerd', 'gereed'],
    ];
    $stmt2 = $db->prepare('INSERT INTO esg_checklist_items (framework, item_code, item_text, status) VALUES (?, ?, ?, ?)');
    foreach ($checklist as $c) {
        $stmt2->execute($c);
    }

    // ── Voorbeeld custom KPI's
    $kpis = [
        ['CO2-uitstoot per FTE', 'Energie', 'kg/FTE', 410.00, 460.00, 350.00, 'Aisha admin'],
        ['Werknemerstevredenheid (NPS)', 'Sociaal', 'score /10', 7.60, 7.30, 8.50, 'Milan milieumanager'],
        ['% vrouwen in management', 'Governance', '%', 42.00, 38.00, 50.00, 'Carla compliance officer'],
    ];
    $stmt3 = $db->prepare(
        'INSERT INTO esg_custom_kpis (name, category, unit, current_value, previous_value, target_value, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($kpis as $k) {
        $stmt3->execute($k);
    }

    // ── Voorbeeld stakeholders
    $stakeholders = [
        ['Groenfonds Investeringen', 'Groenfonds BV', 'contact@groenfonds-demo.nl', 'Investeerder'],
        ['Omgevingsdienst Noord', 'Provincie', 'info@omgevingsdienst-demo.nl', 'Toezichthouder'],
        ['Stichting Duurzaam Werk', 'NGO', 'contact@duurzaamwerk-demo.nl', 'NGO'],
        ['Ondernemingsraad', 'Intern', 'or@bedrijf-demo.nl', 'Medewerkers'],
    ];
    $stmt4 = $db->prepare('INSERT INTO esg_stakeholders (name, organisation, email, category) VALUES (?, ?, ?, ?)');
    foreach ($stakeholders as $s) {
        $stmt4->execute($s);
    }
}

/**
 * Brute-force-bescherming: is dit e-mailadres momenteel geblokkeerd na te veel
 * mislukte inlogpogingen? Retourneert het aantal resterende minuten (0 = niet
 * geblokkeerd).
 */
function loginLockoutMinutesLeft(string $email): int
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT COUNT(*), MAX(created_at) FROM esg_login_attempts
         WHERE email = ? AND created_at >= (NOW() - INTERVAL ? MINUTE)'
    );
    $stmt->execute([$email, LOGIN_LOCKOUT_MINUTES]);
    [$count, $lastAttempt] = $stmt->fetch(PDO::FETCH_NUM);
    $count = (int) $count;
    if ($count < LOGIN_MAX_ATTEMPTS || $lastAttempt === null) {
        return 0;
    }
    $elapsedMinutes = (time() - strtotime((string) $lastAttempt)) / 60;
    $remaining = (int) ceil(LOGIN_LOCKOUT_MINUTES - $elapsedMinutes);
    return max(0, $remaining);
}

/**
 * Registreer een mislukte inlogpoging voor dit e-mailadres.
 */
function recordFailedLogin(string $email): void
{
    $db = getDb();
    $stmt = $db->prepare('INSERT INTO esg_login_attempts (email) VALUES (?)');
    $stmt->execute([$email]);
}

/**
 * Wis mislukte pogingen na een geslaagde login.
 */
function clearFailedLogins(string $email): void
{
    $db = getDb();
    $stmt = $db->prepare('DELETE FROM esg_login_attempts WHERE email = ?');
    $stmt->execute([$email]);
}

/**
 * Onwijzigbare audit trail: enige toegestane operatie op esg_audit_log is INSERT.
 * Er bestaat bewust geen updateAuditLog()/deleteAuditLog() functie.
 */
function auditLog(string $action, string $entity, ?int $entityId = null, ?string $details = null): void
{
    $db = getDb();
    $user = getUser();
    $stmt = $db->prepare(
        'INSERT INTO esg_audit_log (actor, role, action, entity, entity_id, details) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$user['name'] !== '' ? $user['name'] : $user['email'], $user['role'], $action, $entity, $entityId, $details]);
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
 * RBAC: sta alleen de opgegeven rollen toe, anders 403.
 */
function requireRole(array $allowedRoles): void
{
    requireLogin();
    if (!in_array(currentRole(), $allowedRoles, true)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8"><title>Geen toegang</title>'
            . '<script src="https://cdn.tailwindcss.com"></script></head>'
            . '<body class="min-h-screen flex items-center justify-center bg-slate-50">'
            . '<div class="text-center"><p class="text-2xl font-bold text-slate-900 mb-2">403 — Geen toegang</p>'
            . '<p class="text-slate-500 mb-4">Deze pagina vereist een andere rol (least-privilege RBAC).</p>'
            . '<a href="' . BASE . '/index.php" class="text-emerald-600 font-medium">Terug naar dashboard</a></div></body></html>';
        exit;
    }
}

/**
 * Get current user data from session.
 */
function getUser(): array
{
    if (!isLoggedIn()) {
        return ['id' => 0, 'email' => '', 'name' => 'Gast', 'role' => 'gast'];
    }
    return [
        'id'    => (int) ($_SESSION['user_id'] ?? 0),
        'email' => (string) ($_SESSION['user_email'] ?? ''),
        'name'  => (string) ($_SESSION['user_name'] ?? 'Admin'),
        'role'  => (string) ($_SESSION['user_role'] ?? 'milieumanager'),
    ];
}

function currentRole(): string
{
    return (string) ($_SESSION['user_role'] ?? 'milieumanager');
}

/**
 * Nederlandse label + badge-kleur per rol.
 */
function roleLabel(string $role): string
{
    return match ($role) {
        'admin'              => 'Beheerder',
        'milieumanager'      => 'Milieumanager',
        'compliance_officer' => 'Compliance officer',
        default              => ucfirst($role),
    };
}

function roleBadgeClass(string $role): string
{
    return match ($role) {
        'admin'              => 'hz-badge--red',
        'milieumanager'      => 'hz-badge--green',
        'compliance_officer' => 'hz-badge--orange',
        default              => 'hz-badge--gray',
    };
}
