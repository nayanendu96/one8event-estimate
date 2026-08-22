<?php
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/auth.php';

if (estimate_is_manager()) {
    header('Location: index.php');
    exit;
}

$showHidden = isset($_GET['show_hidden']) && $_GET['show_hidden'] === '1';
$searchQuery = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

$conn = estimate_db_connect();

if (!$conn) {
    die('Database connection failed.');
}

estimate_ensure_item_table($conn);

$items = [];
$sql = 'SELECT id, item_name, unit, b2b_rate, d2c_rate, b2v_rate, is_hidden
        FROM estimate_item';
$params = [];
$types = '';

if (!$showHidden) {
    $sql .= ' WHERE is_hidden = 0';
}

if ($searchQuery !== '') {
    $sql .= $showHidden ? ' WHERE' : ' AND';
    $sql .= ' item_name LIKE ?';
    $params[] = '%' . $searchQuery . '%';
    $types .= 's';
}

$sql .= ' ORDER BY item_name ASC';

if ($params) {
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }

        $stmt->close();
    }
} else {
    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
    }
}

$conn->close();

function itemsPageUrl($showHidden, $searchQuery)
{
    $params = [];

    if ($showHidden) {
        $params['show_hidden'] = '1';
    }

    if ($searchQuery !== '') {
        $params['q'] = $searchQuery;
    }

    if (empty($params)) {
        return 'items.php';
    }

    return 'items.php?' . http_build_query($params);
}

$redirectTarget = itemsPageUrl($showHidden, $searchQuery);
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>All Items - ONE8 EVENT</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/style.css" rel="stylesheet" type="text/css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body>
<div class="page estimatesPage itemsPage">
    <div class="estimatesHeader">
        <h1>All Items</h1>
        <div class="estimatesActions">
            <a class="estimateActionBtn" href="index.php">New Estimate</a>
            <a class="estimateActionBtn" href="estimates.php">All Estimates</a>
        </div>
    </div>

    <div class="itemsToolbar">
        <form class="itemsSearchForm" method="get" action="items.php">
            <?php if ($showHidden) : ?>
                <input type="hidden" name="show_hidden" value="1">
            <?php endif; ?>
            <input type="search" name="q" class="itemsSearchInput" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search items...">
            <button type="submit" class="estimateActionBtn">Search</button>
            <?php if ($searchQuery !== '') : ?>
                <a class="estimateActionBtn" href="<?php echo htmlspecialchars(itemsPageUrl($showHidden, ''), ENT_QUOTES, 'UTF-8'); ?>">Clear</a>
            <?php endif; ?>
        </form>
        <div class="itemsToolbarLinks">
            <?php if ($showHidden) : ?>
                <a class="estimateActionBtn" href="<?php echo htmlspecialchars(itemsPageUrl(false, $searchQuery), ENT_QUOTES, 'UTF-8'); ?>">Hide Hidden Items</a>
            <?php else : ?>
                <a class="estimateActionBtn" href="<?php echo htmlspecialchars(itemsPageUrl(true, $searchQuery), ENT_QUOTES, 'UTF-8'); ?>">Show Hidden Items</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="estimatesTableWrap">
        <table class="estimatesTable itemsTable">
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Unit</th>
                    <th>B2B Rate</th>
                    <th>D2C Rate</th>
                    <th>B2V Rate</th>
                    <th>&nbsp;</th>
                </tr>
            </thead>
            <tbody>
                <tr class="itemsNewRow">
                    <td>
                        <input form="item-form-new" type="text" name="item_name" class="itemsInput" placeholder="New item name" required>
                    </td>
                    <td>
                        <input form="item-form-new" type="text" name="unit" class="itemsInput" placeholder="Unit">
                    </td>
                    <td>
                        <input form="item-form-new" type="text" name="b2b_rate" class="itemsInput" placeholder="B2B">
                    </td>
                    <td>
                        <input form="item-form-new" type="text" name="d2c_rate" class="itemsInput" placeholder="D2C">
                    </td>
                    <td>
                        <input form="item-form-new" type="text" name="b2v_rate" class="itemsInput" placeholder="B2V">
                    </td>
                    <td class="estimatesRowActions">
                        <form id="item-form-new" method="post" action="api/save_item.php">
                            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectTarget, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="estimateActionBtn itemsAddBtn">Add Item</button>
                        </form>
                    </td>
                </tr>
                <?php if (empty($items)) : ?>
                    <tr>
                        <td colspan="6" class="itemsEmptyCell">No items found.</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($items as $item) :
                        $id = (int) $item['id'];
                        $isHidden = (int) $item['is_hidden'] === 1;
                        $formId = 'item-form-' . $id;
                        ?>
                        <tr class="<?php echo $isHidden ? 'itemsRowHidden' : ''; ?>">
                            <td>
                                <input form="<?php echo $formId; ?>" type="text" name="item_name" class="itemsInput" value="<?php echo htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            </td>
                            <td>
                                <input form="<?php echo $formId; ?>" type="text" name="unit" class="itemsInput" value="<?php echo htmlspecialchars((string) $item['unit'], ENT_QUOTES, 'UTF-8'); ?>">
                            </td>
                            <td>
                                <input form="<?php echo $formId; ?>" type="text" name="b2b_rate" class="itemsInput" value="<?php echo htmlspecialchars((string) $item['b2b_rate'], ENT_QUOTES, 'UTF-8'); ?>">
                            </td>
                            <td>
                                <input form="<?php echo $formId; ?>" type="text" name="d2c_rate" class="itemsInput" value="<?php echo htmlspecialchars((string) $item['d2c_rate'], ENT_QUOTES, 'UTF-8'); ?>">
                            </td>
                            <td>
                                <input form="<?php echo $formId; ?>" type="text" name="b2v_rate" class="itemsInput" value="<?php echo htmlspecialchars((string) ($item['b2v_rate'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </td>
                            <td class="estimatesRowActions">
                                <div class="estimatesRowActionsInner">
                                <?php if ($isHidden) : ?>
                                    <span class="estimateStatusBadge is-hidden">Hidden</span>
                                    <form class="estimateIconForm" method="post" action="api/restore_item.php">
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectTarget, ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="estimateActionBtn">Restore</button>
                                    </form>
                                <?php else : ?>
                                    <form id="<?php echo $formId; ?>" class="estimateIconForm" method="post" action="api/save_item.php">
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectTarget, ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="estimateActionBtn">Save</button>
                                    </form>
                                    <form class="estimateIconForm" method="post" action="api/hide_item.php" onsubmit="return confirm('Hide this item from the list? It will no longer appear in autocomplete.');">
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectTarget, ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="estimateIconBtn is-delete" title="Hide" aria-label="Hide">
                                            <i class="fa-solid fa-trash-can fa-fw" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
