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

$id = isset($_POST['id']) ? preg_replace('/[^a-f0-9]/', '', strtolower((string) $_POST['id'])) : '';
$locked = isset($_POST['locked']) ? (int) $_POST['locked'] : -1;
$redirect = isset($_POST['redirect']) ? trim((string) $_POST['redirect']) : 'estimates.php';

if ($id === '' || ($locked !== 0 && $locked !== 1)) {
    http_response_code(400);
    exit('Invalid request');
}

$redirect = estimate_app_url($redirect);

$conn = estimate_db_connect();

if (!$conn) {
    http_response_code(500);
    exit('Database connection failed');
}

estimate_ensure_table($conn);

$stmt = $conn->prepare('UPDATE estimates SET is_locked = ? WHERE id = ? AND is_deleted = 0');

if (!$stmt) {
    http_response_code(500);
    $conn->close();
    exit('Query preparation failed');
}

$stmt->bind_param('is', $locked, $id);
$stmt->execute();
$stmt->close();
$conn->close();

header('Location: ' . $redirect);
exit;
