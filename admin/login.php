<?php
// admin/login.php - Mudra Archive Admin Authentication Page
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

send_security_headers(true);

$error  = '';
$notice = isset($_GET['expired']) ? 'Your session ended. Please sign in again.' : '';

if (is_admin_logged_in()) {
    header('Location: dashboard.php');
    exit();
}

$lockoutRemaining = login_lockout_remaining($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    if ($lockoutRemaining > 0) {
        $error = 'Too many failed attempts. Try again in ' . ceil($lockoutRemaining / 60) . ' minute(s).';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($username !== '' && $password !== '') {
            $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Re-hash transparently if PHP's default algorithm has moved on.
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    $pdo->prepare("UPDATE admin_users SET password_hash = :hash WHERE id = :id")
                        ->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']]);
                }

                login_clear_failures($pdo);
                admin_session_start($user['username']);
                header('Location: dashboard.php');
                exit();
            }

            // One message for both cases, so the form cannot be used to discover
            // which usernames exist.
            login_record_failure($pdo);
            $lockoutRemaining = login_lockout_remaining($pdo);
            $error = 'Invalid username or password.';
        } else {
            $error = 'Please enter both username and password.';
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
    <title>Admin Login - <?= sanitize(SITE_NAME) ?></title>

    <!-- Favicons -->
    <link rel="icon" type="image/x-icon" href="/assets/favicon.ico"/>
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png"/>

    <!-- Fonts & icon set (the icons below are Material Symbols) -->
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin=""/>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400..900;1,400..900&family=Source+Sans+3:ital,wght@0,300..900;1,300..900&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"/>

    <!-- Bootstrap 5 CSS via CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <!-- Master Custom Stylesheet -->
    <link href="/assets/css/style.css?v=<?= $cssVersion ?>" rel="stylesheet"/>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 py-5" style="background-color: var(--bg-color);">

    <main class="w-100 max-w-container-max px-3" style="max-width: 420px;">

        <div class="card border-0 shadow-lg p-4 p-md-5 rounded-4" style="background-color: #ffffff;">

            <div class="text-center mb-4">
                <img src="/assets/logo.png" alt="<?= sanitize(SITE_NAME) ?>" class="mb-3" style="height: 54px; width: auto;"/>
                <h1 class="font-heading text-primary fw-bold mb-1 fs-3"><?= sanitize(SITE_NAME) ?></h1>
                <p class="text-muted small">Curator &amp; Admin Portal</p>
            </div>

            <?php if ($notice !== ''): ?>
                <div class="alert alert-info py-2 px-3 small rounded-3 mb-4"><?= sanitize($notice) ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2 px-3 small rounded-3 mb-4 d-flex align-items-center gap-2">
                    <span class="material-symbols-outlined fs-5" aria-hidden="true">error</span>
                    <span><?= sanitize($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" autocomplete="off">
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label class="form-label-archival" for="username">Username</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 border-outline-variant">
                            <span class="material-symbols-outlined text-muted fs-5" aria-hidden="true">person</span>
                        </span>
                        <input type="text" id="username" name="username" class="form-control form-control-archival border-start-0 ps-0"
                               placeholder="Enter username" required autofocus autocomplete="username"/>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label-archival" for="password">Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 border-outline-variant">
                            <span class="material-symbols-outlined text-muted fs-5" aria-hidden="true">lock</span>
                        </span>
                        <input type="password" id="password" name="password" class="form-control form-control-archival border-start-0 ps-0"
                               placeholder="Enter password" required autocomplete="current-password"/>
                    </div>
                </div>

                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary-archival py-2" <?= $lockoutRemaining > 0 ? 'disabled' : '' ?>>
                        Sign In to Dashboard
                    </button>
                </div>

                <div class="text-center mt-3">
                    <a href="/" class="text-decoration-none text-muted small d-inline-flex align-items-center gap-1">
                        <span class="material-symbols-outlined fs-6" aria-hidden="true">arrow_back</span>
                        <span>Return to Public Gallery</span>
                    </a>
                </div>
            </form>
        </div>

    </main>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
