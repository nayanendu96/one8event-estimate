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
$itemName = isset($_POST['item_name']) ? trim((string) $_POST['item_name']) : '';
$unit = isset($_POST['unit']) ? trim((string) $_POST['unit']) : '';
$b2bRate = isset($_POST['b2b_rate']) ? trim((string) $_POST['b2b_rate']) : '';
$d2cRate = isset($_POST['d2c_rate']) ? trim((string) $_POST['d2c_rate']) : '';
$b2vRate = isset($_POST['b2v_rate']) ? trim((string) $_POST['b2v_rate']) : '';
$redirect = isset($_POST['redirect']) ? trim((string) $_POST['redirect']) : 'items.php';

if ($itemName === '') {
    http_response_code(400);
    exit('Item name is required');
}

$redirect = estimate_app_url($redirect);

$conn = estimate_db_connect();

if (!$conn) {
    http_response_code(500);
    exit('Database connection failed');
}

estimate_ensure_item_table($conn);

if ($id > 0) {
    $stmt = $conn->prepare(
        'UPDATE estimate_item
         SET item_name = ?, unit = ?, b2b_rate = ?, d2c_rate = ?, b2v_rate = ?
         WHERE id = ? AND is_hidden = 0'
    );

    if (!$stmt) {
        http_response_code(500);
        $conn->close();
        exit('Query preparation failed');
    }

    $stmt->bind_param('sssssi', $itemName, $unit, $b2bRate, $d2cRate, $b2vRate, $id);
    $stmt->execute();
    $updated = $stmt->affected_rows > 0;
    $stmt->close();

    if (!$updated) {
        http_response_code(404);
        $conn->close();
        exit('Item not found');
    }
} else {
    $stmt = $conn->prepare(
        'INSERT INTO estimate_item (item_name, unit, b2b_rate, d2c_rate, b2v_rate, is_hidden)
         VALUES (?, ?, ?, ?, ?, 0)'
    );

    if (!$stmt) {
        http_response_code(500);
        $conn->close();
        exit('Query preparation failed');
    }

    $stmt->bind_param('sssss', $itemName, $unit, $b2bRate, $d2cRate, $b2vRate);
    $stmt->execute();
    $stmt->close();
}

$conn->close();

header('Location: ' . $redirect);
exit;
