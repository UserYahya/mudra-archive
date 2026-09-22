<?php
// admin/dashboard.php - Mudra Archive Admin Collection Management Dashboard
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

send_security_headers(true);
require_admin();

$msg = isset($_GET['msg']) ? trim($_GET['msg']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build Query
$sql = "SELECT * FROM coins";
$params = [];

if ($search !== '') {
    $sql .= " WHERE (country LIKE :search OR currency_name LIKE :search OR denomination LIKE :search OR ruler_or_series LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$coins = $stmt->fetchAll();

// Statistics
$totalCoins = (int)$pdo->query("SELECT SUM(quantity) FROM coins")->fetchColumn() ?: 0;
$totalValue = (float)$pdo->query("SELECT SUM(acquisition_price * quantity) FROM coins")->fetchColumn() ?: 0.0;
$silverCount = (int)$pdo->query("SELECT COUNT(*) FROM coins WHERE LOWER(material) = 'silver'")->fetchColumn() ?: 0;
$goldCount = (int)$pdo->query("SELECT COUNT(*) FROM coins WHERE LOWER(material) = 'gold'")->fetchColumn() ?: 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Admin Dashboard - Mudra Archive</title>
    
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
    <!-- Master Custom Stylesheet -->
    <link href="../assets/css/style.css?v=<?= file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time() ?>" rel="stylesheet"/>
</head>
<body>

    <!-- Header Navigation -->
    <header class="app-header d-flex align-items-center px-3 px-md-4">
        <div class="container-fluid max-w-container-max d-flex justify-content-between align-items-center p-0">
            <a href="dashboard.php" class="d-flex align-items-center gap-2 text-decoration-none">
                <img src="../assets/logo.png" alt="Mudra Archive Logo" class="nav-logo-img"/>
                <span class="brand-title">Mudra Archive</span>
                <span class="badge bg-secondary-subtle text-dark border ms-1">Admin</span>
            </a>

            <div class="d-flex align-items-center gap-3">
                <a href="/" target="_blank" rel="noopener" class="btn btn-sm btn-outline-archival d-none d-md-inline-flex align-items-center gap-1">
                    <span class="material-symbols-outlined fs-5">public</span>
                    <span>View Public Gallery</span>
                </a>

                <div class="dropdown">
                    <button class="btn btn-light btn-sm dropdown-toggle d-flex align-items-center gap-2 border" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="material-symbols-outlined text-primary fs-5">account_circle</span>
                        <span class="fw-bold"><?= sanitize($_SESSION['admin_user'] ?? 'Yahya') ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><a class="dropdown-item d-flex align-items-center gap-2" href="/" target="_blank" rel="noopener"><span class="material-symbols-outlined fs-5" aria-hidden="true">public</span> Public Site</a></li>
                        <li><a class="dropdown-item d-flex align-items-center gap-2" href="account.php"><span class="material-symbols-outlined fs-5" aria-hidden="true">key</span> Change Password</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="logout.php" class="m-0">
                                <?= csrf_field() ?>
                                <button type="submit" class="dropdown-item text-danger d-flex align-items-center gap-2">
                                    <span class="material-symbols-outlined fs-5" aria-hidden="true">logout</span> Sign Out
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content Canvas -->
    <main class="flex-grow-1 py-4 px-3 px-md-4 container-lg">

        <!-- Notification Alerts -->
        <?php if ($msg === 'added'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <strong>Success!</strong> New coin entry was successfully added to the catalog.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($msg === 'updated'): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <strong>Updated!</strong> Coin details have been updated successfully.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($msg === 'deleted'): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <strong>Deleted!</strong> Coin entry was removed from the database.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Dashboard Stats Overview -->
        <div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
            <div class="col">
                <div class="light-well-card">
                    <div class="form-label-archival">Total Collection</div>
                    <div class="fs-3 font-heading fw-bold text-primary"><?= number_format($totalCoins) ?> <span class="fs-6 fw-normal text-muted">items</span></div>
                </div>
            </div>
            <div class="col">
                <div class="light-well-card">
                    <div class="form-label-archival">Estimated Value</div>
                    <div class="fs-3 font-heading fw-bold text-success"><?= format_currency($totalValue) ?></div>
                </div>
            </div>
            <div class="col">
                <div class="light-well-card">
                    <div class="form-label-archival">Silver Holdings</div>
                    <div class="fs-3 font-heading fw-bold text-secondary"><?= $silverCount ?> <span class="fs-6 fw-normal text-muted">coins</span></div>
                </div>
            </div>
            <div class="col">
                <div class="light-well-card">
                    <div class="form-label-archival">Gold Holdings</div>
                    <div class="fs-3 font-heading fw-bold text-warning"><?= $goldCount ?> <span class="fs-6 fw-normal text-muted">coins</span></div>
                </div>
            </div>
        </div>

        <!-- Action Header & Search -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <h2 class="font-heading mb-0">Collection Catalog</h2>

            <div class="d-flex align-items-center gap-2">
                <form method="GET" action="dashboard.php" class="d-flex gap-2">
                    <input type="text" name="search" class="form-control form-control-archival" placeholder="Filter records..." value="<?= sanitize($search) ?>"/>
                    <button type="submit" class="btn btn-outline-archival px-3">Search</button>
                    <?php if ($search !== ''): ?>
                        <a href="dashboard.php" class="btn btn-outline-secondary">Clear</a>
                    <?php endif; ?>
                </form>

                <a href="add-coin.php" class="btn btn-primary-archival d-inline-flex align-items-center gap-1 text-nowrap">
                    <span class="material-symbols-outlined fs-5">add</span>
                    <span>Add Coin</span>
                </a>
            </div>
        </div>

        <!-- Coin Management Table -->
        <?php if (count($coins) > 0): ?>
            <div class="table-responsive bg-white rounded-3 border shadow-sm mb-4">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 70px;">Image</th>
                            <th>Denomination & Country</th>
                            <th>Year / Era</th>
                            <th>Material</th>
                            <th>Grade</th>
                            <th>Cost</th>
                            <th class="text-end" style="width: 140px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($coins as $c): ?>
                            <?php
                                $obvImg = !empty($c['obverse_image']) && file_exists(__DIR__ . '/../assets/uploads/' . $c['obverse_image'])
                                    ? '../assets/uploads/' . $c['obverse_image']
                                    : '../assets/logo.png';
                            ?>
                            <tr>
                                <td>
                                    <img src="<?= sanitize($obvImg) ?>" alt="<?= sanitize($c['denomination']) ?>" class="rounded border" style="width: 48px; height: 48px; object-fit: contain; background: #ffffff;"/>
                                </td>
                                <td>
                                    <div class="fw-bold font-heading text-dark"><?= sanitize($c['denomination']) ?></div>
                                    <div class="small text-muted"><?= sanitize($c['country']) ?> &bull; <?= sanitize($c['currency_name']) ?></div>
                                </td>
                                <td><span class="small font-monospace"><?= sanitize($c['year']) ?></span></td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-dark border"><?= sanitize($c['material'] ?: 'N/A') ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-warning-subtle text-dark border"><?= sanitize($c['condition_grade'] ?: 'N/A') ?></span>
                                </td>
                                <td>
                                    <span class="small text-muted"><?= format_currency($c['acquisition_price']) ?></span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="<?= sanitize(coin_path($c)) ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary" title="View public page"><span class="material-symbols-outlined fs-6" aria-hidden="true">visibility</span></a>
                                        <a href="edit-coin.php?id=<?= (int)$c['id'] ?>" class="btn btn-outline-primary" title="Edit record"><span class="material-symbols-outlined fs-6" aria-hidden="true">edit</span></a>
                                    </div>
                                    <!-- Deletion posts a CSRF-protected form; it used to be a
                                         plain GET link, which any other site could trigger. -->
                                    <form method="POST" action="delete-coin.php" class="d-inline-block ms-1"
                                          onsubmit="return confirm('Delete <?= sanitize(addslashes($c['denomination'])) ?> (<?= sanitize(addslashes($c['country'])) ?>)? This cannot be undone.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>"/>
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete record">
                                            <span class="material-symbols-outlined fs-6" aria-hidden="true">delete</span>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-5 bg-white border rounded p-4">
                <span class="material-symbols-outlined text-muted display-4 mb-3">folder_open</span>
                <h4 class="font-heading">No Coins in Catalog</h4>
                <p class="text-muted">Get started by adding your first coin to the archive.</p>
                <a href="add-coin.php" class="btn btn-primary-archival mt-2">Add New Coin</a>
            </div>
        <?php endif; ?>

    </main>

    <!-- Footer -->
    <footer class="mt-auto py-4 bg-white border-top text-center text-muted small">
        <div class="container">
            <p class="mb-1">&copy; <?= date('Y') ?> Mudra Archive Admin Panel.</p>
        </div>
    </footer>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
