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
$redirect = isset($_POST['redirect']) ? trim((string) $_POST['redirect']) : '';

if ($id === '') {
    http_response_code(400);
    exit('Invalid request');
}

$conn = estimate_db_connect();

if (!$conn) {
    http_response_code(500);
    exit('Database connection failed');
}

estimate_ensure_table($conn);

$stmt = $conn->prepare(
    'SELECT data, company_name, project_type, grand_total
     FROM estimates
     WHERE id = ? AND is_deleted = 0'
);

if (!$stmt) {
    http_response_code(500);
    $conn->close();
    exit('Query preparation failed');
}

$stmt->bind_param('s', $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    http_response_code(404);
    $conn->close();
    exit('Estimate not found');
}

$newId = bin2hex(random_bytes(8));
$companyName = trim((string) $row['company_name']);
$projectType = trim((string) $row['project_type']);
$grandTotal = (float) $row['grand_total'];
$data = (string) $row['data'];

if ($companyName !== '') {
    $companyName .= ' (Copy)';
}

$insert = $conn->prepare(
    'INSERT INTO estimates (id, data, company_name, project_type, grand_total, is_locked)
     VALUES (?, ?, ?, ?, ?, 0)'
);

if (!$insert) {
    http_response_code(500);
    $conn->close();
    exit('Insert preparation failed');
}

$insert->bind_param('ssssd', $newId, $data, $companyName, $projectType, $grandTotal);
$insert->execute();
$insert->close();

$lockResult = estimate_acquire_edit_lock($conn, $newId);
$conn->close();

if ($redirect !== '') {
    header('Location: ' . estimate_app_url($redirect));
} else {
    $url = 'index.php?id=' . rawurlencode($newId);
    if (!empty($lockResult['edit_token'])) {
        $url .= '&edit=' . rawurlencode($lockResult['edit_token']);
    }
    header('Location: ' . estimate_app_url($url));
}

exit;
