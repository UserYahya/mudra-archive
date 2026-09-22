<?php
// includes/db.php - Mudra Archive Database Connection & Auto-Initialization

require_once __DIR__ . '/config.php';

$dbDir = __DIR__ . '/../database';
if (!file_exists($dbDir)) {
    mkdir($dbDir, 0755, true);
}

$dbPath = $dbDir . '/coins.db';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Create tables if they do not exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS coins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        country TEXT NOT NULL,
        currency_name TEXT NOT NULL,
        denomination TEXT NOT NULL,
        year TEXT NOT NULL,
        mint_mark TEXT,
        ruler_or_series TEXT,
        material TEXT,
        weight_grams REAL,
        diameter_mm REAL,
        condition_grade TEXT,
        obverse_image TEXT,
        reverse_image TEXT,
        quantity INTEGER DEFAULT 1,
        acquisition_date TEXT,
        acquisition_price REAL,
        source_or_seller TEXT,
        notes TEXT,
        is_featured INTEGER DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");

    // Records failed admin sign-in attempts so the login page can rate-limit.
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        attempted_at INTEGER NOT NULL
    )");

    // Indexes for the columns the gallery filters, sorts and throttles on.
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_coins_country  ON coins (country)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_coins_currency ON coins (currency_name)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_coins_grade    ON coins (condition_grade)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_coins_featured ON coins (is_featured)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_login_ip       ON login_attempts (ip, attempted_at)");

    // Create the first admin account with a random password on a fresh install.
    // The credential is written once to a file inside database/, which Apache
    // denies over HTTP - it is never compiled into the source or the README.
    $stmtAdmins = $pdo->query("SELECT COUNT(*) FROM admin_users");
    if ((int)$stmtAdmins->fetchColumn() === 0) {
        $initialPassword = bin2hex(random_bytes(9));
        $insertAdmin = $pdo->prepare("INSERT INTO admin_users (username, password_hash) VALUES (:username, :hash)");
        $insertAdmin->execute([
            ':username' => 'Yahya',
            ':hash'     => password_hash($initialPassword, PASSWORD_DEFAULT),
        ]);

        @file_put_contents(
            $dbDir . '/INITIAL_ADMIN_PASSWORD.txt',
            "Mudra Archive initial admin credentials, generated " . date('Y-m-d H:i:s') . "\n" .
            "Username: Yahya\n" .
            "Password: {$initialPassword}\n\n" .
            "Sign in at /admin/login.php, change this password from the dashboard,\n" .
            "then delete this file.\n"
        );
    }

    // Seed sample numismatic coins if database is empty
    $stmtCount = $pdo->query("SELECT COUNT(*) FROM coins");
    if ((int)$stmtCount->fetchColumn() === 0) {
        $now = date('Y-m-d H:i:s');
        $seedCoins = [
            [
                'country' => 'Greece',
                'currency_name' => 'Drachma',
                'denomination' => 'Tetradrachm',
                'year' => 'c. 440 BC',
                'mint_mark' => 'AΘE',
                'ruler_or_series' => 'Classical Owl',
                'material' => 'Silver',
                'weight_grams' => 17.18,
                'diameter_mm' => 24.50,
                'condition_grade' => 'VF-30',
                'obverse_image' => 'sample_tetradrachm_obv.jpg',
                'reverse_image' => 'sample_tetradrachm_rev.jpg',
                'quantity' => 1,
                'acquisition_date' => '2018-01-15',
                'acquisition_price' => 2450.00,
                'source_or_seller' => "Stack's Bowers Auctions",
                'notes' => "A quintessential example of the Athenian 'Owl' tetradrachm from the mid-5th century BC. The obverse features the helmeted head of Athena, while the reverse displays the iconic owl standing right with olive sprig.",
                'is_featured' => 1
            ],
            [
                'country' => 'United States',
                'currency_name' => 'Dollar',
                'denomination' => 'Morgan Dollar',
                'year' => '1921',
                'mint_mark' => 'S',
                'ruler_or_series' => 'Liberty Head',
                'material' => 'Silver',
                'weight_grams' => 26.73,
                'diameter_mm' => 38.10,
                'condition_grade' => 'MS-63',
                'obverse_image' => 'sample_morgan_obv.jpg',
                'reverse_image' => 'sample_morgan_rev.jpg',
                'quantity' => 1,
                'acquisition_date' => '2020-05-10',
                'acquisition_price' => 180.00,
                'source_or_seller' => 'Heritage Auctions',
                'notes' => 'A highly detailed Morgan Silver Dollar featuring Liberty obverse and heraldic eagle reverse. Mild iridescent rim toning with strong mint luster.',
                'is_featured' => 1
            ],
            [
                'country' => 'United Kingdom',
                'currency_name' => 'Pound',
                'denomination' => 'Sovereign',
                'year' => '1887',
                'mint_mark' => 'M',
                'ruler_or_series' => 'Queen Victoria Jubilee',
                'material' => 'Gold',
                'weight_grams' => 7.98,
                'diameter_mm' => 22.05,
                'condition_grade' => 'EF-45',
                'obverse_image' => 'sample_sovereign_obv.jpg',
                'reverse_image' => 'sample_sovereign_rev.jpg',
                'quantity' => 1,
                'acquisition_date' => '2019-11-22',
                'acquisition_price' => 620.00,
                'source_or_seller' => 'London Coin Fair',
                'notes' => 'Victorian Gold Sovereign celebrating Queen Victoria Golden Jubilee. Reverse side depicting St. George slaying the dragon.',
                'is_featured' => 1
            ],
            [
                'country' => 'Roman Empire',
                'currency_name' => 'Denarius',
                'denomination' => 'Denarius',
                'year' => '19 BC',
                'mint_mark' => 'ROME',
                'ruler_or_series' => 'Emperor Augustus',
                'material' => 'Silver',
                'weight_grams' => 3.85,
                'diameter_mm' => 18.20,
                'condition_grade' => 'Fine',
                'obverse_image' => 'sample_denarius_obv.jpg',
                'reverse_image' => 'sample_denarius_rev.jpg',
                'quantity' => 1,
                'acquisition_date' => '2021-08-04',
                'acquisition_price' => 450.00,
                'source_or_seller' => 'CNG Coins',
                'notes' => 'Roman Silver Denarius of Emperor Augustus. Features stern profile portrait of Augustus and Latin inscription around border.',
                'is_featured' => 0
            ],
            [
                'country' => 'Bangladesh',
                'currency_name' => 'Taka',
                'denomination' => '10 Taka Commemorative',
                'year' => '2011',
                'mint_mark' => 'Dhaka',
                'ruler_or_series' => 'ICC Cricket World Cup',
                'material' => 'Silver',
                'weight_grams' => 12.00,
                'diameter_mm' => 30.00,
                'condition_grade' => 'UNC',
                'obverse_image' => 'sample_bd_obv.jpg',
                'reverse_image' => 'sample_bd_rev.jpg',
                'quantity' => 2,
                'acquisition_date' => '2022-03-12',
                'acquisition_price' => 35.00,
                'source_or_seller' => 'Bangladesh Bank Vault',
                'notes' => 'Commemorative 10 Taka silver coin issued by Bangladesh Bank for the 2011 ICC Cricket World Cup hosted in Bangladesh.',
                'is_featured' => 1
            ]
        ];

        $insertSql = "INSERT INTO coins (
            country, currency_name, denomination, year, mint_mark, ruler_or_series,
            material, weight_grams, diameter_mm, condition_grade, obverse_image,
            reverse_image, quantity, acquisition_date, acquisition_price,
            source_or_seller, notes, is_featured, created_at, updated_at
        ) VALUES (
            :country, :currency_name, :denomination, :year, :mint_mark, :ruler_or_series,
            :material, :weight_grams, :diameter_mm, :condition_grade, :obverse_image,
            :reverse_image, :quantity, :acquisition_date, :acquisition_price,
            :source_or_seller, :notes, :is_featured, :created_at, :updated_at
        )";

        $stmtInsert = $pdo->prepare($insertSql);
        foreach ($seedCoins as $c) {
            $c['created_at'] = $now;
            $c['updated_at'] = $now;
            $stmtInsert->execute($c);
        }
    }
} catch (PDOException $e) {
    // Never echo the driver message: it leaks absolute filesystem paths.
    error_log('[Mudra Archive] Database error: ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 120');
    if (IS_LOCAL_DEV) {
        die('Database error (local dev): ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
    die('The archive is temporarily unavailable. Please try again shortly.');
}
