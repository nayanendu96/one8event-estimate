<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$id = isset($payload['id']) ? preg_replace('/[^a-f0-9]/', '', strtolower((string) $payload['id'])) : '';
$token = isset($payload['edit_token']) ? (string) $payload['edit_token'] : '';

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

$lockStmt = $conn->prepare('SELECT is_locked FROM estimates WHERE id = ? AND is_deleted = 0');

if ($lockStmt) {
    $lockStmt->bind_param('s', $id);
    $lockStmt->execute();
    $lockResult = $lockStmt->get_result();
    $lockRow = $lockResult ? $lockResult->fetch_assoc() : null;
    $lockStmt->close();

    if (!$lockRow) {
        http_response_code(404);
        echo json_encode(['error' => 'Estimate not found']);
        $conn->close();
        exit;
    }

    if ((int) $lockRow['is_locked'] === 1) {
        http_response_code(403);
        echo json_encode(['error' => 'Estimate is locked']);
        $conn->close();
        exit;
    }
}

$result = estimate_acquire_edit_lock($conn, $id, $token);
$conn->close();

if (!$result['success']) {
    http_response_code(423);
    echo json_encode($result);
    exit;
}

echo json_encode($result);
