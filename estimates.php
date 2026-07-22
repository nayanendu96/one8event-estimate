<?php
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/auth.php';

if (estimate_is_manager()) {
    header('Location: manager_estimates.php');
    exit;
}

$conn = estimate_db_connect();

if (!$conn) {
    die('Database connection failed.');
}

estimate_ensure_table($conn);

$activeTab = isset($_GET['tab']) ? estimate_workflow_status_normalize((string) $_GET['tab']) : 'open';
$tabCounts = ['open' => 0, 'accepted' => 0, 'rejected' => 0];
$allEstimates = [];
$estimates = [];

$result = $conn->query(
    'SELECT id, company_name, project_type, list_phone, workflow_status, data, grand_total, is_locked, created_at, updated_at
     FROM estimates
     WHERE is_deleted = 0
     ORDER BY updated_at DESC'
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $allEstimates[] = $row;
    }
}

foreach ($allEstimates as $estimateRow) {
    $status = estimate_workflow_status_normalize($estimateRow['workflow_status'] ?? 'open');
    $tabCounts[$status]++;
}

foreach ($allEstimates as $estimateRow) {
    $status = estimate_workflow_status_normalize($estimateRow['workflow_status'] ?? 'open');
    if ($status === $activeTab) {
        $estimates[] = $estimateRow;
    }
}

$conn->close();
$estimatesPageUrl = 'estimates.php';
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>All Estimates - ONE8 EVENT</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/style.css?v=3" rel="stylesheet" type="text/css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body data-estimates-tab="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>">
<div class="page estimatesPage">
    <div class="estimatesHeader">
        <h1>All Estimates</h1>
        <div class="estimatesActions">
            <a class="estimateActionBtn" href="index.php">New Estimate</a>
            <a class="estimateActionBtn" href="items.php">All Items</a>
        </div>
    </div>

    <?php if (empty($allEstimates)) : ?>
        <p class="estimatesEmpty">No estimates saved yet.</p>
    <?php else : ?>
        <nav class="estimatesTabs" aria-label="Estimate status tabs">
            <?php foreach (['open', 'accepted', 'rejected'] as $tabKey) :
                $tabLabel = estimate_workflow_status_label($tabKey);
                $tabHref = $estimatesPageUrl . '?tab=' . rawurlencode($tabKey);
                $isTabActive = $activeTab === $tabKey;
                ?>
                <a
                    class="estimatesTab estimatesTab--<?php echo $tabKey; ?><?php echo $isTabActive ? ' is-active' : ''; ?>"
                    href="<?php echo htmlspecialchars($tabHref, ENT_QUOTES, 'UTF-8'); ?>"
                    aria-current="<?php echo $isTabActive ? 'page' : 'false'; ?>"
                >
                    <?php echo htmlspecialchars($tabLabel, ENT_QUOTES, 'UTF-8'); ?>
                    <span class="estimatesTabCount" data-tab-count="<?php echo $tabKey; ?>"><?php echo (int) $tabCounts[$tabKey]; ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if (empty($estimates)) : ?>
            <p class="estimatesEmpty">No <?php echo htmlspecialchars(strtolower(estimate_workflow_status_label($activeTab)), ENT_QUOTES, 'UTF-8'); ?> estimates.</p>
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
                        <th>Lock</th>
                        <th>Status</th>
                        <th>Last Updated</th>
                        <th>Actions</th>
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
                        $workflowStatus = estimate_workflow_status_normalize($estimate['workflow_status'] ?? 'open');
                        $id = htmlspecialchars($estimate['id'], ENT_QUOTES, 'UTF-8');
                        $tabRedirect = $estimatesPageUrl . '?tab=' . rawurlencode($activeTab);
                        ?>
                        <tr data-workflow-status="<?php echo htmlspecialchars($workflowStatus, ENT_QUOTES, 'UTF-8'); ?>">
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
                                <span class="estimateStatusBadge estimateLockBadge<?php echo $isLocked ? ' is-locked' : ' is-unlocked'; ?>" title="Edit lock">
                                    <?php echo $isLocked ? 'Locked' : 'Unlocked'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="estimateWorkflowToggle" data-id="<?php echo $id; ?>" role="group" aria-label="Estimate status">
                                    <?php foreach (['open', 'accepted', 'rejected'] as $statusOption) :
                                        $isActive = $workflowStatus === $statusOption;
                                        $statusLabel = estimate_workflow_status_label($statusOption);
                                        ?>
                                        <button
                                            type="button"
                                            class="estimateWorkflowPill estimateWorkflowPill--<?php echo $statusOption; ?><?php echo $isActive ? ' is-active' : ''; ?>"
                                            data-status="<?php echo $statusOption; ?>"
                                            title="<?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>"
                                            aria-pressed="<?php echo $isActive ? 'true' : 'false'; ?>"
                                        ><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></button>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td><?php echo estimate_format_datetime($estimate['updated_at']); ?></td>
                            <td class="estimatesRowActions">
                                <div class="estimatesRowActionsInner">
                                <?php $shareUrl = estimate_share_url($estimate['id']); ?>
                                <span class="estimateIconWrap">
                                    <a class="estimateIconBtn is-open" href="index.php?id=<?php echo $id; ?>&amp;view=1" title="Open" aria-label="Open">
                                        <i class="fa-solid fa-up-right-from-square" aria-hidden="true"></i>
                                    </a>
                                </span>
                                <span class="estimateIconWrap">
                                    <button type="button" class="estimateIconBtn is-copy-url estimateCopyUrlBtn" data-url="<?php echo htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8'); ?>" title="Copy URL" aria-label="Copy URL">
                                        <i class="fa-solid fa-link estimateIconDefault" aria-hidden="true"></i>
                                        <i class="fa-solid fa-check estimateIconCheck" aria-hidden="true"></i>
                                    </button>
                                </span>
                                <form class="estimateIconForm" method="post" action="api/copy_estimate.php">
                                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                                    <button type="submit" class="estimateIconBtn is-copy-estimate" title="Copy Estimate" aria-label="Copy Estimate">
                                        <i class="fa-solid fa-copy fa-fw" aria-hidden="true"></i>
                                    </button>
                                </form>
                                <?php if ($isLocked) : ?>
                                    <form class="estimateIconForm" method="post" action="api/toggle_lock.php">
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <input type="hidden" name="locked" value="0">
                                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($tabRedirect, ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="estimateIconBtn is-unlock" title="Unlock" aria-label="Unlock">
                                            <i class="fa-solid fa-lock-open fa-fw" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                <?php else : ?>
                                    <form class="estimateIconForm" method="post" action="api/toggle_lock.php">
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <input type="hidden" name="locked" value="1">
                                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($tabRedirect, ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="estimateIconBtn is-lock" title="Lock" aria-label="Lock">
                                            <i class="fa-solid fa-lock fa-fw" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                    <form class="estimateIconForm" method="post" action="api/delete_estimate.php" onsubmit="return confirm('Hide this estimate from the list?');">
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($tabRedirect, ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="estimateIconBtn is-delete" title="Delete" aria-label="Delete">
                                            <i class="fa-solid fa-trash-can fa-fw" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<script>
(function () {
    var currentTab = document.body.getAttribute('data-estimates-tab') || 'open';

    function updateTabCount(tab, delta) {
        var countEl = document.querySelector('.estimatesTabCount[data-tab-count="' + tab + '"]');
        if (!countEl) {
            return;
        }

        var count = parseInt(countEl.textContent, 10) || 0;
        countEl.textContent = Math.max(0, count + delta);
    }

    function showTabEmptyMessage() {
        var table = document.querySelector('.estimatesTable');
        if (table && table.querySelector('tbody tr')) {
            return;
        }

        var wrap = document.querySelector('.estimatesTableWrap');
        if (wrap) {
            wrap.remove();
        }

        if (document.querySelector('.estimatesTabEmptyMessage')) {
            return;
        }

        var labels = { open: 'open', accepted: 'accepted', rejected: 'rejected' };
        var message = document.createElement('p');
        message.className = 'estimatesEmpty estimatesTabEmptyMessage';
        message.textContent = 'No ' + (labels[currentTab] || 'open') + ' estimates.';
        var tabs = document.querySelector('.estimatesTabs');
        if (tabs && tabs.parentNode) {
            tabs.parentNode.insertBefore(message, tabs.nextSibling);
        }
    }

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
                btn.classList.add('is-copied');
                window.setTimeout(function () {
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

    document.querySelectorAll('.estimateWorkflowToggle').forEach(function (group) {
        group.addEventListener('click', function (event) {
            var pill = event.target.closest('.estimateWorkflowPill');
            if (!pill || !group.contains(pill) || pill.classList.contains('is-active') || group.classList.contains('is-saving')) {
                return;
            }

            var id = group.getAttribute('data-id');
            var status = pill.getAttribute('data-status');
            if (!id || !status) {
                return;
            }

            var previous = group.querySelector('.estimateWorkflowPill.is-active');
            group.classList.add('is-saving');
            group.classList.remove('is-error');

            fetch('api/update_workflow_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id, status: status })
            }).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) {
                        throw new Error(data.error || 'Save failed');
                    }
                    return data;
                });
            }).then(function (data) {
                group.querySelectorAll('.estimateWorkflowPill').forEach(function (item) {
                    var isActive = item === pill;
                    item.classList.toggle('is-active', isActive);
                    item.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                });
                group.classList.remove('is-saving');

                if (data.status && data.status !== currentTab) {
                    var row = group.closest('tr');
                    if (row) {
                        row.remove();
                        updateTabCount(currentTab, -1);
                        updateTabCount(data.status, 1);
                        showTabEmptyMessage();
                    }
                }
            }).catch(function () {
                group.classList.remove('is-saving');
                group.classList.add('is-error');
                if (previous) {
                    group.querySelectorAll('.estimateWorkflowPill').forEach(function (item) {
                        var isActive = item === previous;
                        item.classList.toggle('is-active', isActive);
                        item.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                    });
                }
                window.setTimeout(function () {
                    group.classList.remove('is-error');
                }, 2000);
            });
        });
    });
})();
</script>
</body>
</html>
