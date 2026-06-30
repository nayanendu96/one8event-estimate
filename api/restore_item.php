<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (!estimate_is_admin()) {
    http_response_code(403);
    exit('Forbidden');
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$redirect = isset($_POST['redirect']) ? trim((string) $_POST['redirect']) : 'items.php?show_hidden=1';

if ($id <= 0) {
    http_response_code(400);
    exit('Invalid request');
}

$redirect = estimate_app_url($redirect);

$conn = estimate_db_connect();

if (!$conn) {
    http_response_code(500);
    exit('Database connection failed');
}

estimate_ensure_item_table($conn);

$stmt = $conn->prepare(
    'UPDATE estimate_item
     SET is_hidden = 0
     WHERE id = ? AND is_hidden = 1'
);

if (!$stmt) {
    http_response_code(500);
    $conn->close();
    exit('Query preparation failed');
}

$stmt->bind_param('i', $id);
$stmt->execute();
$restored = $stmt->affected_rows > 0;
$stmt->close();
$conn->close();

if (!$restored) {
    http_response_code(404);
    exit('Item not found');
}

header('Location: ' . $redirect);
exit;
