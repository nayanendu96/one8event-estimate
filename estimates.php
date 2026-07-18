<?php
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/auth.php';

if (estimate_is_manager()) {
    header('Location: index.php');
    exit;
}

$conn = estimate_db_connect();

if (!$conn) {
    die('Database connection failed.');
}

estimate_ensure_table($conn);

$estimates = [];
$result = $conn->query(
    'SELECT id, company_name, project_type, list_phone, data, grand_total, is_locked, created_at, updated_at
     FROM estimates
     WHERE is_deleted = 0
     ORDER BY updated_at DESC'
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
<link href="css/style.css" rel="stylesheet" type="text/css" />
</head>
<body>
<div class="page estimatesPage">
    <div class="estimatesHeader">
        <h1>All Estimates</h1>
        <div class="estimatesActions">
            <a class="estimateActionBtn" href="index.php">New Estimate</a>
            <a class="estimateActionBtn" href="items.php">All Items</a>
        </div>
    </div>

    <?php if (empty($estimates)) : ?>
        <p class="estimatesEmpty">No estimates saved yet.</p>
    <?php else : ?>
        <div class="estimatesTableWrap">
            <table class="estimatesTable">
                <thead>
                    <tr>
                        <th>Company</th>
                        <th>Project Owner</th>
                        <th>Phone</th>
                        <th>Project Type</th>
                        <th>Grand Total</th>
                        <th>Status</th>
                        <th>Last Updated</th>
                        <th>Copy URL</th>
                        <th>Copy Estimate</th>
                        <th>&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($estimates as $estimate) :
                        $company = trim((string) $estimate['company_name']);
                        $projectOwner = estimate_extract_project_owner($estimate['data'] ?? '');
                        $listPhone = trim((string) ($estimate['list_phone'] ?? ''));
                        $projectType = trim((string) $estimate['project_type']);
                        $grandTotal = (float) $estimate['grand_total'];
                        $isLocked = (int) $estimate['is_locked'] === 1;
                        $id = htmlspecialchars($estimate['id'], ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr>
                            <td><?php echo $company !== '' ? htmlspecialchars($company, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                            <td><?php echo $projectOwner !== '' ? htmlspecialchars($projectOwner, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                            <td>
                                <input
                                    type="text"
                                    class="estimateListPhoneInput"
                                    data-id="<?php echo $id; ?>"
                                    value="<?php echo htmlspecialchars($listPhone, ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Add phone"
                                    maxlength="32"
                                    autocomplete="off"
                                >
                            </td>
                            <td><?php echo $projectType !== '' ? htmlspecialchars($projectType, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                            <td><?php echo number_format($grandTotal, 0, '.', ','); ?></td>
                            <td>
                                <span class="estimateStatusBadge<?php echo $isLocked ? ' is-locked' : ' is-unlocked'; ?>">
                                    <?php echo $isLocked ? 'Locked' : 'Unlocked'; ?>
                                </span>
                            </td>
                            <td><?php echo estimate_format_datetime($estimate['updated_at']); ?></td>
                            <td>
                                <?php $shareUrl = estimate_share_url($estimate['id']); ?>
                                <button type="button" class="estimateCopyUrlBtn" data-url="<?php echo htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8'); ?>" title="Copy share link">Copy URL</button>
                            </td>
                            <td>
                                <form class="estimateCopyForm" method="post" action="api/copy_estimate.php">
                                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                                    <button type="submit" class="estimateCopyEstimateBtn" title="Create a duplicate of this estimate">Copy Estimate</button>
                                </form>
                            </td>
                            <td class="estimatesRowActions">
                                <a class="estimateOpenLink" href="index.php?id=<?php echo $id; ?>&amp;view=1">Open</a>
                                <?php if ($isLocked) : ?>
                                    <form class="estimateIconForm" method="post" action="api/toggle_lock.php">
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <input type="hidden" name="locked" value="0">
                                        <input type="hidden" name="redirect" value="estimates.php">
                                        <button type="submit" class="estimateIconBtn is-unlock" title="Unlock">Unlock</button>
                                    </form>
                                <?php else : ?>
                                    <form class="estimateIconForm" method="post" action="api/toggle_lock.php">
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <input type="hidden" name="locked" value="1">
                                        <input type="hidden" name="redirect" value="estimates.php">
                                        <button type="submit" class="estimateIconBtn is-lock" title="Lock">Lock</button>
                                    </form>
                                    <form class="estimateIconForm" method="post" action="api/delete_estimate.php" onsubmit="return confirm('Hide this estimate from the list?');">
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <input type="hidden" name="redirect" value="estimates.php">
                                        <button type="submit" class="estimateIconBtn is-delete" title="Delete">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<script>
(function () {
    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            var input = document.createElement('textarea');
            input.value = text;
            input.setAttribute('readonly', '');
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.select();

            try {
                document.execCommand('copy') ? resolve() : reject();
            } catch (err) {
                reject(err);
            } finally {
                document.body.removeChild(input);
            }
        });
    }

    document.querySelectorAll('.estimateCopyUrlBtn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var url = btn.getAttribute('data-url');
            if (!url) {
                return;
            }

            copyText(url).then(function () {
                var original = btn.textContent;
                btn.textContent = 'Copied!';
                btn.classList.add('is-copied');
                window.setTimeout(function () {
                    btn.textContent = original;
                    btn.classList.remove('is-copied');
                }, 2000);
            }).catch(function () {
                window.prompt('Copy this URL:', url);
            });
        });
    });

    document.querySelectorAll('.estimateListPhoneInput').forEach(function (input) {
        var lastSaved = input.value;

        function savePhone() {
            var id = input.getAttribute('data-id');
            var phone = input.value.trim();

            if (phone === lastSaved) {
                return;
            }

            input.classList.remove('is-saved', 'is-error');
            input.classList.add('is-saving');

            fetch('api/update_list_phone.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id, phone: phone })
            }).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) {
                        throw new Error(data.error || 'Save failed');
                    }
                    return data;
                });
            }).then(function () {
                lastSaved = phone;
                input.classList.remove('is-saving');
                input.classList.add('is-saved');
                window.setTimeout(function () {
                    input.classList.remove('is-saved');
                }, 1500);
            }).catch(function () {
                input.classList.remove('is-saving');
                input.classList.add('is-error');
                input.value = lastSaved;
                window.setTimeout(function () {
                    input.classList.remove('is-error');
                }, 2000);
            });
        }

        input.addEventListener('blur', savePhone);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                input.blur();
            }
        });
    });
})();
</script>
</body>
</html>
