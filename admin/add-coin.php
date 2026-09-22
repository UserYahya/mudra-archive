<?php
// admin/add-coin.php - Mudra Archive Add New Coin Form
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/countries.php';

send_security_headers(true);
require_admin();

$error = '';
$standardCountries = get_standard_countries_list();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // An oversized upload discards $_POST entirely, including the token, so that
    // case is detected first and reported; every real submission must carry a
    // valid CSRF token before anything is written to the database.
    $postWasDiscarded = empty($_POST) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0;
    if (!$postWasDiscarded) {
        csrf_require();
    }

    if ($postWasDiscarded) {
        $error = 'The uploaded file exceeds the server maximum upload limit (post_max_size). Automatic compression has now been enabled below to shrink your photos before uploading.';
    } else {
        $country = trim($_POST['country'] ?? '');
        $currency_name = trim($_POST['currency_name'] ?? '');
        $denomination = trim($_POST['denomination'] ?? '');
        $year = trim($_POST['year'] ?? '');
        $mint_mark = trim($_POST['mint_mark'] ?? '');
        $ruler_or_series = trim($_POST['ruler_or_series'] ?? '');
        $material = trim($_POST['material'] ?? '');
        $weight_grams = isset($_POST['weight_grams']) && $_POST['weight_grams'] !== '' ? (float)$_POST['weight_grams'] : null;
        $diameter_mm = isset($_POST['diameter_mm']) && $_POST['diameter_mm'] !== '' ? (float)$_POST['diameter_mm'] : null;
        $condition_grade = trim($_POST['condition_grade'] ?? '');
        $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
        $acquisition_date = trim($_POST['acquisition_date'] ?? '');
        $acquisition_price = isset($_POST['acquisition_price']) && $_POST['acquisition_price'] !== '' ? (float)$_POST['acquisition_price'] : null;
        $source_or_seller = trim($_POST['source_or_seller'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $is_featured = isset($_POST['is_featured']) ? 1 : 0;

        if ($country === '' || $currency_name === '' || $denomination === '' || $year === '') {
            $error = 'Country, Currency Name, Denomination, and Year are required fields.';
        } else {
            // Upload images
            $obverse_image = upload_coin_image('obverse_image', __DIR__ . '/../assets/uploads/');
            $reverse_image = upload_coin_image('reverse_image', __DIR__ . '/../assets/uploads/');

            $now = date('Y-m-d H:i:s');

            $sql = "INSERT INTO coins (
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

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':country' => $country,
                ':currency_name' => $currency_name,
                ':denomination' => $denomination,
                ':year' => $year,
                ':mint_mark' => $mint_mark,
                ':ruler_or_series' => $ruler_or_series,
                ':material' => $material,
                ':weight_grams' => $weight_grams,
                ':diameter_mm' => $diameter_mm,
                ':condition_grade' => $condition_grade,
                ':obverse_image' => $obverse_image,
                ':reverse_image' => $reverse_image,
                ':quantity' => $quantity,
                ':acquisition_date' => $acquisition_date,
                ':acquisition_price' => $acquisition_price,
                ':source_or_seller' => $source_or_seller,
                ':notes' => $notes,
                ':is_featured' => $is_featured,
                ':created_at' => $now,
                ':updated_at' => $now
            ]);

            header('Location: dashboard.php?msg=added');
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Add Coin Entry - Mudra Archive</title>
    
    <!-- Favicons -->
    <link rel="icon" type="image/x-icon" href="../assets/favicon.ico"/>
    <link rel="apple-touch-icon" sizes="180x180" href="../assets/apple-touch-icon.png"/>

    <meta name="robots" content="noindex, nofollow, noarchive"/>

    <!-- Fonts & the Material Symbols icon set used throughout this page -->
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin=""/>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400..900;1,400..900&family=Source+Sans+3:ital,wght@0,300..900;1,300..900&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"/>

    <!-- Bootstrap 5 CSS via CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <!-- Cropper.js CSS for Interactive Coin Cropping -->
    <link href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css" rel="stylesheet"/>
    <!-- Master Custom Stylesheet -->
    <link href="../assets/css/style.css?v=<?= file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time() ?>" rel="stylesheet"/>
</head>
<body>

    <!-- Header Navigation -->
    <header class="app-header d-flex align-items-center px-3 px-md-4">
        <div class="container-fluid max-w-container-max d-flex justify-content-between align-items-center p-0">
            <div class="d-flex align-items-center gap-3">
                <a href="dashboard.php" class="btn btn-sm btn-outline-archival d-inline-flex align-items-center gap-1">
                    <span class="material-symbols-outlined fs-5">arrow_back</span>
                    <span>Back to Dashboard</span>
                </a>
                <span class="brand-title">Catalog Entry</span>
            </div>

            <button type="submit" form="addCoinForm" class="btn btn-primary-archival">Save Coin</button>
        </div>
    </header>

    <!-- Main Content Canvas -->
    <main class="flex-grow-1 py-4 py-md-5 px-3 px-md-4 container-lg max-w-container-max" style="max-width: 840px;">

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger mb-4"><?= sanitize($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="add-coin.php" enctype="multipart/form-data" id="addCoinForm">
            <?= csrf_field() ?>
            
            <!-- Photo Upload Section -->
            <div class="card border-0 shadow-sm p-4 rounded-3 mb-4" style="background-color: var(--surface-bright);">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h4 class="font-heading text-primary mb-0 d-flex align-items-center gap-2">
                        <span class="material-symbols-outlined text-primary">photo_camera</span>
                        <span>Artifact Imaging</span>
                    </h4>
                    <div class="d-flex align-items-center gap-3">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="autoCropToggle" checked>
                            <label class="form-check-label font-label-archival small fw-bold text-dark" for="autoCropToggle">Auto-crop Coin (Square / কিনারা বরাবর ক্রপ)</label>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1 py-1 px-2" id="btnConfigureApiKey" title="Configure Gemini AI Key">
                            <span class="material-symbols-outlined fs-6">key</span>
                            <span class="small">AI Key</span>
                        </button>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label-archival">Obverse Image (Front)</label>
                        <input type="file" name="obverse_image" accept="image/*" class="form-control form-control-archival"/>
                        <span class="small text-muted">Auto-compresses and crops coin edges</span>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label-archival">Reverse Image (Back)</label>
                        <input type="file" name="reverse_image" accept="image/*" class="form-control form-control-archival"/>
                        <span class="small text-muted">Auto-compresses and crops coin edges</span>
                    </div>
                </div>

                <!-- Gemini AI Autofill Card (Visible when both images selected) -->
                <div id="geminiAiAutofillCard" class="d-none mt-4 p-3 rounded-3 border border-primary-subtle bg-primary-subtle bg-opacity-25">
                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                        <div>
                            <div class="d-flex align-items-center gap-2 text-primary fw-bold">
                                <span class="material-symbols-outlined">auto_awesome</span>
                                <span>Gemini AI Smart Catalog Autofill</span>
                            </div>
                            <div class="small text-muted">Analyze obverse & reverse inscriptions, ruler, mint, year, and metal to auto-fill catalog fields.</div>
                        </div>
                        <button type="button" id="btnGeminiAutofill" class="btn btn-primary-archival d-inline-flex align-items-center gap-2 shadow-sm text-nowrap">
                            <span class="material-symbols-outlined fs-5">auto_awesome</span>
                            <span>Autofill with Gemini AI</span>
                        </button>
                    </div>
                    <div id="geminiAiStatus" class="d-none"></div>
                </div>
            </div>

            <!-- Numismatic Details Section -->
            <div class="card border-0 shadow-sm p-4 rounded-3 mb-4" style="background-color: var(--surface-bright);">
                <h4 class="font-heading text-primary mb-4 d-flex align-items-center gap-2">
                    <span class="material-symbols-outlined text-primary">account_balance</span>
                    <span>Numismatic Details</span>
                </h4>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label-archival">Country / Issuing Authority *</label>
                        <select name="country" class="form-select form-select-archival" required>
                            <option value="">Select Country or Region...</option>
                            <?php foreach ($standardCountries as $code => $countryName): ?>
                                <option value="<?= sanitize($countryName) ?>"><?= sanitize($countryName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label-archival">Currency Name *</label>
                        <input type="text" name="currency_name" class="form-control form-control-archival" placeholder="e.g. Taka, Dollar, Sovereign, Drachma" required/>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label-archival">Denomination *</label>
                        <input type="text" name="denomination" class="form-control form-control-archival" placeholder="e.g. 10 Taka, Morgan Dollar, Tetradrachm" required/>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label-archival">Year / Era *</label>
                        <input type="text" name="year" class="form-control form-control-archival" placeholder="e.g. 1921, 2011, c. 440 BC" required/>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label-archival">Ruler / Series</label>
                        <input type="text" name="ruler_or_series" class="form-control form-control-archival" placeholder="e.g. Queen Victoria, Liberty Head, Augustus"/>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label-archival">Mint Mark</label>
                        <input type="text" name="mint_mark" class="form-control form-control-archival" placeholder="e.g. S, O, AΘE, Dhaka"/>
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="form-label-archival">Material</label>
                        <input type="text" name="material" class="form-control form-control-archival" placeholder="e.g. Silver, Gold, Bronze, Copper"/>
                    </div>

                    <div class="col-6 col-md-4">
                        <label class="form-label-archival">Weight (grams)</label>
                        <input type="number" step="0.01" name="weight_grams" class="form-control form-control-archival" placeholder="e.g. 26.73"/>
                    </div>

                    <div class="col-6 col-md-4">
                        <label class="form-label-archival">Diameter (mm)</label>
                        <input type="number" step="0.01" name="diameter_mm" class="form-control form-control-archival" placeholder="e.g. 38.10"/>
                    </div>

                    <div class="col-6 col-md-6">
                        <label class="form-label-archival">Condition / Grade</label>
                        <input type="text" name="condition_grade" class="form-control form-control-archival" placeholder="e.g. MS-63, VF-30, UNC, Fine"/>
                    </div>

                    <div class="col-6 col-md-6">
                        <label class="form-label-archival">Quantity</label>
                        <input type="number" name="quantity" class="form-control form-control-archival" value="1" min="1"/>
                    </div>
                </div>
            </div>

            <!-- Acquisition & Private Info Section -->
            <div class="card border-0 shadow-sm p-4 rounded-3 mb-4" style="background-color: var(--surface-bright);">
                <h4 class="font-heading text-primary mb-4 d-flex align-items-center gap-2">
                    <span class="material-symbols-outlined text-primary">receipt_long</span>
                    <span>Acquisition & Private Records</span>
                </h4>

                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label-archival">Acquisition Date</label>
                        <input type="date" name="acquisition_date" class="form-control form-control-archival"/>
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="form-label-archival">Acquisition Price ($ USD)</label>
                        <input type="number" step="0.01" name="acquisition_price" class="form-control form-control-archival" placeholder="e.g. 180.00"/>
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="form-label-archival">Source / Seller</label>
                        <input type="text" name="source_or_seller" class="form-control form-control-archival" placeholder="e.g. Heritage Auctions, Private Collector"/>
                    </div>

                    <div class="col-12">
                        <label class="form-label-archival">Historical Notes & Catalog Description</label>
                        <textarea name="notes" rows="4" class="form-control form-control-archival" placeholder="Enter provenance, strike quality, toning details, and history..."></textarea>
                    </div>

                    <div class="col-12">
                        <div class="form-check mt-2">
                            <input type="checkbox" name="is_featured" value="1" id="is_featured" class="form-check-input"/>
                            <label class="form-check-label font-label-archival" for="is_featured">Feature this coin on main showcase</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mb-5">
                <a href="dashboard.php" class="btn btn-outline-secondary px-4">Cancel</a>
                <button type="submit" class="btn btn-primary-archival px-5">Save Coin Entry</button>
            </div>

        </form>

    </main>

    <!-- Cropper.js for Interactive Manual Crop Adjustments -->
    <script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
    <!-- Client-Side Image Compressor & Auto-Cropper -->
    <script src="../assets/js/compressor.js?v=<?= time() ?>"></script>
    <!-- Gemini AI Smart Autofill -->
    <script src="../assets/js/gemini-autofill.js?v=<?= time() ?>"></script>
    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
