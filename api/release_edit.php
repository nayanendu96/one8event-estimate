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

if ($id === '' || $token === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing estimate id or edit token']);
    exit;
}

$conn = estimate_db_connect();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

estimate_release_edit_lock($conn, $id, $token);
$conn->close();

echo json_encode(['success' => true]);
