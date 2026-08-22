<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!estimate_is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$id = isset($payload['id']) ? preg_replace('/[^a-f0-9]/', '', strtolower((string) $payload['id'])) : '';
$status = isset($payload['status']) ? estimate_workflow_status_normalize($payload['status']) : '';

if ($id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid estimate id']);
    exit;
}

$conn = estimate_db_connect();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

estimate_ensure_table($conn);

$stmt = $conn->prepare('UPDATE estimates SET workflow_status = ? WHERE id = ? AND is_deleted = 0');

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Query preparation failed']);
    $conn->close();
    exit;
}

$stmt->bind_param('ss', $status, $id);
$stmt->execute();
$updated = $stmt->affected_rows > 0;
$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'updated' => $updated,
    'status' => $status,
    'label' => estimate_workflow_status_label($status),
]);
