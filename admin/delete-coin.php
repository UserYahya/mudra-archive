<?php
// admin/delete-coin.php - Mudra Archive Coin Deletion Handler
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

send_security_headers(true);
require_admin();

// POST only. The previous version also accepted GET with ?confirm=1, so a single
// crafted image tag or link on any page could delete a coin from the collection
// while the curator was signed in.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php', true, 303);
    exit();
}

csrf_require();

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id > 0) {
    // Retrieve image filenames to clean up files from assets/uploads/
    $stmt = $pdo->prepare("SELECT obverse_image, reverse_image FROM coins WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $coin = $stmt->fetch();

    if ($coin) {
        $uploadDir = __DIR__ . '/../assets/uploads/';

        // Remove the full-size image and its generated thumbnail for both faces.
        foreach (['obverse_image', 'reverse_image'] as $field) {
            $name = safe_upload_name($coin[$field] ?? '');
            if ($name === '') {
                continue;
            }
            foreach ([$uploadDir . $name, $uploadDir . 'thumb_' . $name] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        $pdo->prepare("DELETE FROM coins WHERE id = :id")->execute([':id' => $id]);
    }
}

header('Location: dashboard.php?msg=deleted', true, 303);
exit();
