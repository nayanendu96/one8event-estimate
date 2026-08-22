<?php
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/auth.php';

if (!estimate_is_manager()) {
    header('Location: estimates.php');
    exit;
}

$conn = estimate_db_connect();

if (!$conn) {
    die('Database connection failed.');
}

estimate_ensure_table($conn);

$estimates = [];
$result = $conn->query(
    'SELECT id, company_name, created_at, updated_at
     FROM estimates
     WHERE is_deleted = 0
     ORDER BY COALESCE(created_at, updated_at) DESC'
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $estimates[] = $row;
    }
}

$conn->close();
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>All Estimates - ONE8 EVENT</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/style.css?v=4" rel="stylesheet" type="text/css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body>
<div class="page estimatesPage estimatesPage--manager">
    <div class="estimatesHeader">
        <h1>All Estimates</h1>
        <div class="estimatesActions">
            <a class="estimateActionBtn" href="index.php">Create New Estimate</a>
        </div>
    </div>

    <?php if (empty($estimates)) : ?>
        <p class="estimatesEmpty">No estimates saved yet.</p>
    <?php else : ?>
    <div class="estimatesTableWrap">
        <table class="estimatesTable estimatesTable--manager">
            <thead>
                <tr>
                    <th>Company</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($estimates as $estimate) :
                    $company = trim((string) $estimate['company_name']);
                    $id = htmlspecialchars($estimate['id'], ENT_QUOTES, 'UTF-8');
                    $dateValue = $estimate['created_at'] ?? $estimate['updated_at'] ?? '';
                    ?>
                    <tr>
                        <td><?php echo $company !== '' ? htmlspecialchars($company, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        <td><?php echo estimate_format_datetime($dateValue); ?></td>
                        <td class="estimatesRowActions">
                            <div class="estimatesRowActionsInner">
                                <span class="estimateIconWrap">
                                    <a class="estimateIconBtn is-open" href="index.php?id=<?php echo $id; ?>&amp;view=1" title="Open" aria-label="Open">
                                        <i class="fa-solid fa-up-right-from-square" aria-hidden="true"></i>
                                        <span class="managerEstimateActionLabel">Open</span>
                                    </a>
                                </span>
                                <form class="estimateIconForm" method="post" action="api/copy_estimate.php">
                                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                                    <input type="hidden" name="redirect" value="manager_estimates.php">
                                    <button type="submit" class="estimateIconBtn is-copy-estimate" title="Copy" aria-label="Copy">
                                        <i class="fa-solid fa-copy fa-fw" aria-hidden="true"></i>
                                        <span class="managerEstimateActionLabel">Copy</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
