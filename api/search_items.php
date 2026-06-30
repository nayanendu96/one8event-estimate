<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($query === '') {
    echo '[]';
    exit;
}

$conn = estimate_db_connect();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

estimate_ensure_item_table($conn);

$like = '%' . $query . '%';
$stmt = $conn->prepare(
    'SELECT id, item_name, unit, b2b_rate, d2c_rate
     FROM estimate_item
     WHERE item_name LIKE ? AND is_hidden = 0
     ORDER BY item_name ASC
     LIMIT 15'
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Query preparation failed']);
    $conn->close();
    exit;
}

$stmt->bind_param('s', $like);
$stmt->execute();
$result = $stmt->get_result();

$items = [];

while ($row = $result->fetch_assoc()) {
    $item = [
        'id' => (int) $row['id'],
        'item_name' => $row['item_name'],
        'unit' => $row['unit'],
    ];

    if (!estimate_is_manager()) {
        $item['b2b_rate'] = $row['b2b_rate'];
        $item['d2c_rate'] = $row['d2c_rate'];
    }

    $items[] = $item;
}

$stmt->close();
$conn->close();

echo json_encode($items, JSON_UNESCAPED_UNICODE);
