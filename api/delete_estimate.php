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
$redirect = isset($_POST['redirect']) ? trim((string) $_POST['redirect']) : 'estimates.php';

if ($id === '') {
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

$check = $conn->prepare('SELECT is_locked, is_deleted FROM estimates WHERE id = ?');

if (!$check) {
    http_response_code(500);
    $conn->close();
    exit('Query preparation failed');
}

$check->bind_param('s', $id);
$check->execute();
$result = $check->get_result();
$row = $result ? $result->fetch_assoc() : null;
$check->close();

if (!$row || (int) $row['is_deleted'] === 1) {
    http_response_code(404);
    $conn->close();
    exit('Estimate not found');
}

if ((int) $row['is_locked'] === 1) {
    http_response_code(403);
    $conn->close();
    exit('Locked estimates cannot be deleted');
}

if (!estimate_soft_delete($conn, $id)) {
    http_response_code(403);
    $conn->close();
    exit('Estimate could not be deleted');
}

$conn->close();

header('Location: ' . $redirect);
exit;
