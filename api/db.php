<?php

date_default_timezone_set('Asia/Kolkata');

$db_host = '';
$db_name = '';
$db_user = '';
$db_pass = '';

function estimate_is_local_environment(): bool
{
    $env = getenv('ESTIMATE_ENV');

    if ($env === 'local') {
        return true;
    }

    if ($env === 'production' || $env === 'prod') {
        return false;
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

    if ($host !== '' && preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $host)) {
        return true;
    }

    foreach (['DOCUMENT_ROOT', 'SCRIPT_FILENAME'] as $key) {
        $path = str_replace('\\', '/', (string) realpath((string) ($_SERVER[$key] ?? '')));

        if ($path !== '' && (stripos($path, '/xampp/') !== false || stripos($path, '/wamp/') !== false)) {
            return true;
        }
    }

    return false;
}

$db_config = estimate_is_local_environment()
    ? __DIR__ . '/db.local.php'
    : __DIR__ . '/db.prod.php';

if (!is_readable($db_config)) {
    trigger_error(
        'Database config not found: ' . basename($db_config),
        E_USER_ERROR
    );
}

require $db_config;

function estimate_db_connect()
{
    global $db_host, $db_name, $db_user, $db_pass;

    mysqli_report(MYSQLI_REPORT_OFF);

    $conn = mysqli_init();
    if (!$conn) {
        return null;
    }

    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);

    $ok = @$conn->real_connect($db_host, $db_user, $db_pass, $db_name);

    if (!$ok || $conn->connect_error) {
        return null;
    }

    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+05:30'");

    return $conn;
}

function estimate_format_datetime($value, $format = 'd M Y, h:i A')
{
    if (!$value) {
        return '';
    }

    try {
        $date = new DateTimeImmutable((string) $value, new DateTimeZone('Asia/Kolkata'));
    } catch (Exception $e) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    return htmlspecialchars($date->format($format), ENT_QUOTES, 'UTF-8');
}

function estimate_normalize_updated_at($value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('Asia/Kolkata'));
    } catch (Exception $e) {
        return $value;
    }

    return $date->format('Y-m-d H:i:s');
}

function estimate_app_base_path()
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $appRoot = realpath(dirname(__DIR__));

    if ($documentRoot && $appRoot) {
        $documentRoot = str_replace('\\', '/', $documentRoot);
        $appRoot = str_replace('\\', '/', $appRoot);

        if (strpos($appRoot, $documentRoot) === 0) {
            $base = substr($appRoot, strlen($documentRoot));
            $base = rtrim($base, '/');

            return $base;
        }
    }

    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $scriptDir = rtrim(dirname($scriptName), '/');

    if (substr($scriptDir, -4) === '/api') {
        $base = rtrim(dirname($scriptDir), '/');
    } else {
        $base = $scriptDir;
    }

    if ($base === '/' || $base === '.') {
        $base = '';
    }

    return $base;
}

function estimate_app_url($path)
{
    $path = ltrim(trim((string) $path), '/');

    if ($path === '' || strpos($path, '://') !== false || strpos($path, '..') !== false) {
        $path = 'estimates.php';
    }

    $base = estimate_app_base_path();

    if ($base === '') {
        return '/' . $path;
    }

    return $base . '/' . $path;
}

function estimate_share_url($id)
{
    $path = estimate_app_url('index.php?id=' . rawurlencode((string) $id) . '&view=1');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host . $path;
}

function estimate_edit_lock_minutes(): int
{
    return 3;
}

function estimate_ensure_edit_lock_columns($conn): bool
{
    $tokenCheck = $conn->query("SHOW COLUMNS FROM estimates LIKE 'edit_token'");

    if ($tokenCheck && $tokenCheck->num_rows === 0) {
        $conn->query(
            'ALTER TABLE estimates
             ADD COLUMN edit_token VARCHAR(64) DEFAULT NULL AFTER is_deleted,
             ADD COLUMN edit_token_expires DATETIME DEFAULT NULL AFTER edit_token'
        );
    }

    return true;
}

function estimate_normalize_edit_token(string $token): string
{
    return preg_replace('/[^a-f0-9]/', '', strtolower($token));
}

function estimate_is_edit_lock_active(?string $token, ?string $expiresAt): bool
{
    if ($token === null || $token === '' || $expiresAt === null || $expiresAt === '') {
        return false;
    }

    try {
        $expires = new DateTimeImmutable((string) $expiresAt, new DateTimeZone('Asia/Kolkata'));
    } catch (Exception $e) {
        return false;
    }

    return $expires > new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
}

function estimate_fetch_edit_lock($conn, string $id): ?array
{
    $stmt = $conn->prepare(
        'SELECT edit_token, edit_token_expires
         FROM estimates
         WHERE id = ? AND is_deleted = 0'
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function estimate_verify_edit_token($conn, string $id, string $token): bool
{
    $token = estimate_normalize_edit_token($token);

    if ($token === '') {
        return false;
    }

    $row = estimate_fetch_edit_lock($conn, $id);

    if (!$row) {
        return false;
    }

    return estimate_normalize_edit_token((string) ($row['edit_token'] ?? '')) === $token
        && estimate_is_edit_lock_active($row['edit_token'] ?? null, $row['edit_token_expires'] ?? null);
}

function estimate_acquire_edit_lock($conn, string $id, string $token = ''): array
{
    $token = estimate_normalize_edit_token($token);
    $row = estimate_fetch_edit_lock($conn, $id);

    if (!$row) {
        return ['success' => false, 'error' => 'Estimate not found'];
    }

    $currentToken = estimate_normalize_edit_token((string) ($row['edit_token'] ?? ''));
    $lockActive = estimate_is_edit_lock_active($row['edit_token'] ?? null, $row['edit_token_expires'] ?? null);

    if ($lockActive && $currentToken !== '' && $currentToken !== $token) {
        return ['success' => false, 'error' => 'Someone else is editing this estimate'];
    }

    $newToken = $token !== '' ? $token : bin2hex(random_bytes(16));
    $minutes = estimate_edit_lock_minutes();

    $stmt = $conn->prepare(
        'UPDATE estimates
         SET edit_token = ?, edit_token_expires = DATE_ADD(NOW(), INTERVAL ? MINUTE)
         WHERE id = ? AND is_deleted = 0'
    );

    if (!$stmt) {
        return ['success' => false, 'error' => 'Could not acquire edit lock'];
    }

    $stmt->bind_param('sis', $newToken, $minutes, $id);
    $stmt->execute();
    $updated = $stmt->affected_rows > 0;
    $stmt->close();

    if (!$updated) {
        return ['success' => false, 'error' => 'Could not acquire edit lock'];
    }

    return [
        'success' => true,
        'edit_token' => $newToken,
        'expires_in' => $minutes * 60,
    ];
}

function estimate_refresh_edit_lock($conn, string $id, string $token): bool
{
    if (!estimate_verify_edit_token($conn, $id, $token)) {
        return false;
    }

    $token = estimate_normalize_edit_token($token);
    $minutes = estimate_edit_lock_minutes();

    $stmt = $conn->prepare(
        'UPDATE estimates
         SET edit_token_expires = DATE_ADD(NOW(), INTERVAL ? MINUTE)
         WHERE id = ? AND edit_token = ? AND is_deleted = 0'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('iss', $minutes, $id, $token);
    $stmt->execute();
    $updated = $stmt->affected_rows > 0;
    $stmt->close();

    return $updated;
}

function estimate_release_edit_lock($conn, string $id, string $token): void
{
    $token = estimate_normalize_edit_token($token);

    if ($token === '') {
        return;
    }

    $stmt = $conn->prepare(
        'UPDATE estimates
         SET edit_token = NULL, edit_token_expires = NULL
         WHERE id = ? AND edit_token = ? AND is_deleted = 0'
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('ss', $id, $token);
    $stmt->execute();
    $stmt->close();
}

function estimate_has_items(array $data)
{
    if (empty($data['groups']) || !is_array($data['groups'])) {
        return false;
    }

    foreach ($data['groups'] as $group) {
        if (empty($group['items']) || !is_array($group['items'])) {
            continue;
        }

        foreach ($group['items'] as $item) {
            foreach (['name', 'description', 'size', 'qty', 'unit', 'rate'] as $field) {
                if (trim((string) ($item[$field] ?? '')) !== '') {
                    return true;
                }
            }
        }
    }

    return false;
}

function estimate_ensure_table($conn)
{
    $sql = 'CREATE TABLE IF NOT EXISTS estimates (
        id VARCHAR(32) NOT NULL PRIMARY KEY,
        data JSON NOT NULL,
        company_name VARCHAR(255) DEFAULT NULL,
        project_type VARCHAR(255) DEFAULT NULL,
        grand_total DECIMAL(15,2) DEFAULT 0,
        is_locked TINYINT(1) NOT NULL DEFAULT 0,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_updated (updated_at DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    if (!$conn->query($sql)) {
        return false;
    }

    $columnCheck = $conn->query("SHOW COLUMNS FROM estimates LIKE 'is_locked'");

    if ($columnCheck && $columnCheck->num_rows === 0) {
        $conn->query('ALTER TABLE estimates ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER grand_total');
    }

    $deletedCheck = $conn->query("SHOW COLUMNS FROM estimates LIKE 'is_deleted'");

    if ($deletedCheck && $deletedCheck->num_rows === 0) {
        $conn->query('ALTER TABLE estimates ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER is_locked');
    }

    estimate_ensure_edit_lock_columns($conn);

    $listPhoneCheck = $conn->query("SHOW COLUMNS FROM estimates LIKE 'list_phone'");

    if ($listPhoneCheck && $listPhoneCheck->num_rows === 0) {
        $conn->query('ALTER TABLE estimates ADD COLUMN list_phone VARCHAR(32) DEFAULT NULL AFTER project_type');
    }

    $workflowStatusCheck = $conn->query("SHOW COLUMNS FROM estimates LIKE 'workflow_status'");

    if ($workflowStatusCheck && $workflowStatusCheck->num_rows === 0) {
        $conn->query("ALTER TABLE estimates ADD COLUMN workflow_status VARCHAR(16) NOT NULL DEFAULT 'open' AFTER list_phone");
    }

    $createdAtCheck = $conn->query("SHOW COLUMNS FROM estimates LIKE 'created_at'");

    if ($createdAtCheck && $createdAtCheck->num_rows === 0) {
        $conn->query('ALTER TABLE estimates ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER is_deleted');
    }

    $updatedAtCheck = $conn->query("SHOW COLUMNS FROM estimates LIKE 'updated_at'");

    if ($updatedAtCheck && $updatedAtCheck->num_rows === 0) {
        $conn->query('ALTER TABLE estimates ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
    }

    return true;
}

function estimate_workflow_status_normalize($value): string
{
    $value = strtolower(trim((string) $value));

    if (in_array($value, ['open', 'accepted', 'rejected'], true)) {
        return $value;
    }

    return 'open';
}

function estimate_workflow_status_label(string $status): string
{
    $labels = [
        'open' => 'Open',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
    ];

    return $labels[$status] ?? 'Open';
}

function estimate_extract_project_owner($dataJson): string
{
    $data = json_decode((string) $dataJson, true);

    if (!is_array($data)) {
        return '';
    }

    return trim((string) ($data['header']['projectOwner'] ?? ''));
}

function estimate_ensure_item_table($conn)
{
    $hiddenCheck = $conn->query("SHOW COLUMNS FROM estimate_item LIKE 'is_hidden'");

    if ($hiddenCheck && $hiddenCheck->num_rows === 0) {
        $conn->query('ALTER TABLE estimate_item ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER d2c_rate');
    }

    $b2vCheck = $conn->query("SHOW COLUMNS FROM estimate_item LIKE 'b2v_rate'");

    if ($b2vCheck && $b2vCheck->num_rows === 0) {
        $conn->query('ALTER TABLE estimate_item ADD COLUMN b2v_rate VARCHAR(64) DEFAULT NULL AFTER d2c_rate');
    }

    return true;
}

function estimate_soft_delete($conn, $id)
{
    $stmt = $conn->prepare(
        'UPDATE estimates
         SET is_deleted = 1
         WHERE id = ? AND is_locked = 0 AND is_deleted = 0'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $id);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    return $deleted;
}
