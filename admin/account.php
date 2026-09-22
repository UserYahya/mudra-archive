<?php
// admin/account.php - Change the curator's password.
// The README has always instructed the admin to change the seeded password, but
// until now there was no screen that could do it.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

send_security_headers(true);
require_admin();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = :username LIMIT 1");
    $stmt->execute([':username' => $_SESSION['admin_user'] ?? '']);
    $user = $stmt->fetch();

    if (!$user || !password_verify($current, $user['password_hash'])) {
        $error = 'Your current password is not correct.';
    } elseif (strlen($new) < 12) {
        $error = 'Choose a new password of at least 12 characters.';
    } elseif ($new !== $confirm) {
        $error = 'The two new passwords do not match.';
    } elseif (hash_equals($new, $current)) {
        $error = 'The new password must be different from the current one.';
    } else {
        $pdo->prepare("UPDATE admin_users SET password_hash = :hash WHERE id = :id")
            ->execute([':hash' => password_hash($new, PASSWORD_DEFAULT), ':id' => $user['id']]);

        // A password change invalidates other sessions' assumptions; start fresh.
        session_regenerate_id(true);
        $success = 'Password updated. Use the new password next time you sign in.';

        // The generated credential file is obsolete once the password changes.
        $seedFile = __DIR__ . '/../database/INITIAL_ADMIN_PASSWORD.txt';
        if (is_file($seedFile)) {
            @unlink($seedFile);
        }
    }
}

$cssVersion = file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="robots" content="noindex, nofollow, noarchive"/>
    <title>Account Security - <?= sanitize(SITE_NAME) ?></title>

    <link rel="icon" type="image/x-icon" href="../assets/favicon.ico"/>
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin=""/>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400..900;1,400..900&family=Source+Sans+3:ital,wght@0,300..900;1,300..900&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link href="../assets/css/style.css?v=<?= $cssVersion ?>" rel="stylesheet"/>
</head>
<body>

    <header class="app-header d-flex align-items-center px-3 px-md-4">
        <div class="container-fluid max-w-container-max d-flex justify-content-between align-items-center p-0">
            <a href="dashboard.php" class="d-flex align-items-center gap-2 text-decoration-none">
                <img src="../assets/logo.png" alt="<?= sanitize(SITE_NAME) ?> logo" class="nav-logo-img"/>
                <span class="brand-title"><?= sanitize(SITE_NAME) ?></span>
                <span class="badge bg-secondary-subtle text-dark border ms-1">Admin</span>
            </a>
            <a href="dashboard.php" class="btn btn-sm btn-outline-archival d-inline-flex align-items-center gap-1">
                <span class="material-symbols-outlined fs-6" aria-hidden="true">arrow_back</span>
                <span>Back to Dashboard</span>
            </a>
        </div>
    </header>

    <main class="flex-grow-1 py-4 px-3 container-lg" style="max-width: 560px;">
        <h1 class="font-heading fs-3 mb-3">Account Security</h1>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success py-2 px-3 small rounded-3"><?= sanitize($success) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger py-2 px-3 small rounded-3"><?= sanitize($error) ?></div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm rounded-4 p-4">
            <p class="text-muted small mb-4">
                Signed in as <strong><?= sanitize($_SESSION['admin_user'] ?? '') ?></strong>.
                Use a password of at least 12 characters that you do not use anywhere else.
            </p>

            <form method="POST" action="account.php" autocomplete="off">
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label class="form-label-archival" for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password" required
                           autocomplete="current-password" class="form-control form-control-archival"/>
                </div>

                <div class="mb-3">
                    <label class="form-label-archival" for="new_password">New password</label>
                    <input type="password" id="new_password" name="new_password" required minlength="12"
                           autocomplete="new-password" class="form-control form-control-archival"/>
                </div>

                <div class="mb-4">
                    <label class="form-label-archival" for="confirm_password">Confirm new password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="12"
                           autocomplete="new-password" class="form-control form-control-archival"/>
                </div>

                <button type="submit" class="btn btn-primary-archival w-100 py-2">Update password</button>
            </form>
        </div>
    </main>

    <footer class="mt-auto py-4 bg-white border-top text-center text-muted small">
        <p class="mb-0">&copy; <?= date('Y') ?> <?= sanitize(SITE_NAME) ?> Admin Panel.</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
