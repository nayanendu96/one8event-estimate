<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/estimate_sanitize.php';

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
$data = isset($payload['data']) ? $payload['data'] : null;
$editToken = isset($payload['edit_token']) ? (string) $payload['edit_token'] : '';
$expectedUpdatedAt = isset($payload['expected_updated_at']) ? trim((string) $payload['expected_updated_at']) : '';

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing estimate data']);
    exit;
}

$companyName = isset($payload['company_name']) ? trim((string) $payload['company_name']) : '';
$projectType = isset($payload['project_type']) ? trim((string) $payload['project_type']) : '';
$grandTotal = isset($payload['grand_total']) ? (float) $payload['grand_total'] : 0;

$conn = estimate_db_connect();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

estimate_ensure_table($conn);

if (!estimate_has_items($data)) {
    if ($id !== '') {
        estimate_soft_delete($conn, $id);
    }

    $conn->close();
    echo json_encode([
        'success' => true,
        'skipped' => true,
        'deleted' => $id !== '',
    ]);
    exit;
}

$isNew = $id === '';

if ($isNew) {
    $id = bin2hex(random_bytes(8));
} elseif (!estimate_verify_edit_token($conn, $id, $editToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Edit permission required. Open in view mode or take edit control first.']);
    $conn->close();
    exit;
}

$lockStmt = $conn->prepare('SELECT is_locked, updated_at FROM estimates WHERE id = ? AND is_deleted = 0');

if ($lockStmt) {
    $lockStmt->bind_param('s', $id);
    $lockStmt->execute();
    $lockResult = $lockStmt->get_result();
    $lockRow = $lockResult ? $lockResult->fetch_assoc() : null;
    $lockStmt->close();

    if (!$isNew && !$lockRow) {
        http_response_code(404);
        echo json_encode(['error' => 'Estimate not found']);
        $conn->close();
        exit;
    }

    if ($lockRow && (int) $lockRow['is_locked'] === 1) {
        http_response_code(403);
        echo json_encode(['error' => 'Estimate is locked']);
        $conn->close();
        exit;
    }

    if (!$isNew && $expectedUpdatedAt !== '' && $lockRow
        && estimate_normalize_updated_at($lockRow['updated_at']) !== estimate_normalize_updated_at($expectedUpdatedAt)) {
        http_response_code(409);
        echo json_encode([
            'error' => 'This estimate was updated elsewhere. Reload to get the latest version.',
            'conflict' => true,
            'updated_at' => estimate_normalize_updated_at($lockRow['updated_at']),
        ]);
        $conn->close();
        exit;
    }
}

if (estimate_is_manager()) {
    $existingData = null;

    if (!$isNew) {
        $existingStmt = $conn->prepare('SELECT data FROM estimates WHERE id = ? AND is_deleted = 0');

        if ($existingStmt) {
            $existingStmt->bind_param('s', $id);
            $existingStmt->execute();
            $existingResult = $existingStmt->get_result();
            $existingRow = $existingResult ? $existingResult->fetch_assoc() : null;
            $existingStmt->close();

            if ($existingRow) {
                $decodedExisting = json_decode($existingRow['data'], true);
                $existingData = is_array($decodedExisting) ? $decodedExisting : null;
            }
        }
    }

    if (is_array($existingData)) {
        $data['discount'] = $existingData['discount'] ?? '0';
        $data['discountVisible'] = $existingData['discountVisible'] ?? false;
    }

    $data = estimate_rebuild_financial_fields($data, $existingData, $conn);
    $grandTotal = estimate_calculate_grand_total($data);
}

$jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);

if ($jsonData === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Could not encode estimate data']);
    $conn->close();
    exit;
}

if ($isNew) {
    $insert = $conn->prepare(
        'INSERT INTO estimates (id, data, company_name, project_type, grand_total)
         VALUES (?, ?, ?, ?, ?)'
    );

    if (!$insert) {
        http_response_code(500);
        echo json_encode(['error' => 'Insert preparation failed']);
        $conn->close();
        exit;
    }

    $insert->bind_param('ssssd', $id, $jsonData, $companyName, $projectType, $grandTotal);
    $insert->execute();
    $insert->close();
} else {
    $stmt = $conn->prepare(
        'UPDATE estimates
         SET data = ?, company_name = ?, project_type = ?, grand_total = ?
         WHERE id = ? AND is_deleted = 0'
    );

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['error' => 'Query preparation failed']);
        $conn->close();
        exit;
    }

    $stmt->bind_param('sssds', $jsonData, $companyName, $projectType, $grandTotal, $id);
    $stmt->execute();
    $stmt->close();
}

$lockResult = estimate_acquire_edit_lock($conn, $id, $editToken);

if (!$lockResult['success']) {
    http_response_code(500);
    echo json_encode(['error' => 'Saved but could not refresh edit lock']);
    $conn->close();
    exit;
}

$updatedStmt = $conn->prepare(
    'SELECT updated_at FROM estimates WHERE id = ? AND is_deleted = 0'
);

$updatedAt = '';

if ($updatedStmt) {
    $updatedStmt->bind_param('s', $id);
    $updatedStmt->execute();
    $updatedResult = $updatedStmt->get_result();
    $updatedRow = $updatedResult ? $updatedResult->fetch_assoc() : null;
    $updatedStmt->close();

    if ($updatedRow) {
        $updatedAt = estimate_normalize_updated_at($updatedRow['updated_at']);
    }
}

$conn->close();

echo json_encode([
    'success' => true,
    'id' => $id,
    'edit_token' => $lockResult['edit_token'],
    'updated_at' => $updatedAt,
]);
