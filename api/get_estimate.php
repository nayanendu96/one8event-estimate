<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/estimate_sanitize.php';

$id = isset($_GET['id']) ? preg_replace('/[^a-f0-9]/', '', strtolower((string) $_GET['id'])) : '';

if ($id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing estimate id']);
    exit;
}

$conn = estimate_db_connect();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

estimate_ensure_table($conn);

$stmt = $conn->prepare(
    'SELECT data, is_locked, updated_at, edit_token, edit_token_expires
     FROM estimates
     WHERE id = ? AND is_deleted = 0'
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Query preparation failed']);
    $conn->close();
    exit;
}

$stmt->bind_param('s', $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    $conn->close();
    http_response_code(404);
    echo json_encode(['error' => 'Estimate not found']);
    exit;
}

$decoded = json_decode($row['data'], true);
$data = is_array($decoded) ? $decoded : [];
$isLocked = (int) $row['is_locked'] === 1;

if (estimate_is_manager()) {
    $data = estimate_strip_financial_fields($data);
} elseif (!empty($data)) {
    $data = estimate_rebuild_financial_fields($data, $data, $conn, $isLocked);
}

if (!empty($data)) {
    $data = estimate_enrich_b2v_rates($data, $conn);
}

$conn->close();

echo json_encode([
    'success' => true,
    'id' => $id,
    'data' => $data,
    'is_locked' => (int) $row['is_locked'] === 1,
    'updated_at' => estimate_normalize_updated_at($row['updated_at']),
    'is_being_edited' => estimate_is_edit_lock_active($row['edit_token'] ?? null, $row['edit_token_expires'] ?? null),
]);
