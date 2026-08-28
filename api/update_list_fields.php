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
$field = isset($payload['field']) ? trim((string) $payload['field']) : '';
$value = isset($payload['value']) ? trim((string) $payload['value']) : '';

if ($id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid estimate id']);
    exit;
}

$allowedFields = ['project_type', 'project_owner'];

if (!in_array($field, $allowedFields, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid field']);
    exit;
}

if (strlen($value) > 255) {
    http_response_code(400);
    echo json_encode(['error' => 'Value is too long']);
    exit;
}

$conn = estimate_db_connect();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

estimate_ensure_table($conn);

$select = $conn->prepare('SELECT data, project_type FROM estimates WHERE id = ? AND is_deleted = 0');

if (!$select) {
    http_response_code(500);
    echo json_encode(['error' => 'Query preparation failed']);
    $conn->close();
    exit;
}

$select->bind_param('s', $id);
$select->execute();
$result = $select->get_result();
$row = $result ? $result->fetch_assoc() : null;
$select->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Estimate not found']);
    $conn->close();
    exit;
}

$data = json_decode((string) $row['data'], true);

if (!is_array($data)) {
    $data = [];
}

if (!isset($data['header']) || !is_array($data['header'])) {
    $data['header'] = [];
}

$projectType = trim((string) ($row['project_type'] ?? ''));

if ($field === 'project_type') {
    $projectType = $value;
    $data['header']['projectType'] = $value;
} else {
    $data['header']['projectOwner'] = $value;
}

$jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);

if ($jsonData === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Could not encode estimate data']);
    $conn->close();
    exit;
}

$stmt = $conn->prepare(
    'UPDATE estimates SET data = ?, project_type = ? WHERE id = ? AND is_deleted = 0'
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Query preparation failed']);
    $conn->close();
    exit;
}

$stmt->bind_param('sss', $jsonData, $projectType, $id);
$stmt->execute();
$updated = $stmt->affected_rows > 0;
$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'updated' => $updated,
    'field' => $field,
    'value' => $value,
]);
