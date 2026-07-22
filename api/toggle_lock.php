<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/estimate_sanitize.php';

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

if ($locked === 1) {
    $fetchStmt = $conn->prepare('SELECT data FROM estimates WHERE id = ? AND is_deleted = 0');

    if (!$fetchStmt) {
        http_response_code(500);
        $conn->close();
        exit('Query preparation failed');
    }

    $fetchStmt->bind_param('s', $id);
    $fetchStmt->execute();
    $fetchResult = $fetchStmt->get_result();
    $fetchRow = $fetchResult ? $fetchResult->fetch_assoc() : null;
    $fetchStmt->close();

    if (!$fetchRow) {
        http_response_code(404);
        $conn->close();
        exit('Estimate not found');
    }

    $decoded = json_decode($fetchRow['data'], true);
    $data = is_array($decoded) ? $decoded : [];
    $data = estimate_rebuild_financial_fields($data, $data, $conn, true);
    $grandTotal = estimate_calculate_grand_total($data);
    $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);

    if ($jsonData === false) {
        http_response_code(500);
        $conn->close();
        exit('Could not encode estimate data');
    }

    $stmt = $conn->prepare(
        'UPDATE estimates
         SET is_locked = 1, data = ?, grand_total = ?
         WHERE id = ? AND is_deleted = 0'
    );

    if (!$stmt) {
        http_response_code(500);
        $conn->close();
        exit('Query preparation failed');
    }

    $stmt->bind_param('sds', $jsonData, $grandTotal, $id);
    $stmt->execute();
    $stmt->close();
} else {
    $stmt = $conn->prepare('UPDATE estimates SET is_locked = 0 WHERE id = ? AND is_deleted = 0');

    if (!$stmt) {
        http_response_code(500);
        $conn->close();
        exit('Query preparation failed');
    }

    $stmt->bind_param('s', $id);
    $stmt->execute();
    $stmt->close();
}

$conn->close();

header('Location: ' . $redirect);
exit;
