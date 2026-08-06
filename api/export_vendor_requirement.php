<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/estimate_sanitize.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$raw = isset($_POST['data']) ? (string) $_POST['data'] : '';

if ($raw === '') {
    $payload = json_decode(file_get_contents('php://input'), true);
    $data = is_array($payload) && isset($payload['data']) ? $payload['data'] : $payload;
} else {
    $data = json_decode($raw, true);
}

if (!is_array($data) || empty($data['groups'])) {
    http_response_code(400);
    exit('Invalid vendor requirement data');
}

$conn = estimate_db_connect();

if (!$conn) {
    http_response_code(500);
    exit('Database connection failed');
}

$categories = [];
$categoryResult = $conn->query('SELECT id, name FROM estimate_category ORDER BY id ASC');

if ($categoryResult) {
    while ($categoryRow = $categoryResult->fetch_assoc()) {
        $categories[(string) $categoryRow['id']] = (string) $categoryRow['name'];
    }
}

$conn->close();

$data['rateMode'] = 'b2v';

vendor_requirement_export_xlsx($data, $categories);
