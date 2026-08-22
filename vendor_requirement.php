<?php
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/auth.php';
require_once __DIR__ . '/api/estimate_sanitize.php';

$conn = estimate_db_connect();

if (!$conn) {
    die('Database connection failed.');
}

$estimateData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['data'])) {
    $decoded = json_decode((string) $_POST['data'], true);
    $estimateData = is_array($decoded) ? $decoded : [];
}

if (empty($estimateData['groups']) || !is_array($estimateData['groups'])) {
    header('Location: ' . estimate_app_url(estimate_is_manager() ? 'manager_estimates.php' : 'estimates.php'));
    exit;
}

$categories = [];
$categoryResult = $conn->query('SELECT id, name FROM estimate_category ORDER BY id ASC');

if ($categoryResult) {
    while ($row = $categoryResult->fetch_assoc()) {
        $categories[] = $row;
    }
}

$conn->close();

function vr_renderCategorySelect(array $categories, $selectedId = '')
{
    $html = '<select class="categorySelect">';
    $html .= '<option value="">Select Category</option>';

    foreach ($categories as $category) {
        $id = (int) $category['id'];
        $name = htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8');
        $selected = ((string) $selectedId === (string) $id) ? ' selected' : '';
        $html .= '<option value="' . $id . '"' . $selected . '>' . $name . '</option>';
    }

    $html .= '</select>';

    return $html;
}

function vr_renderItemRow()
{
    return '
                <tr class="item-row">
                    <td>
                        <div class="itemRowLead">
                            <span class="itemDragHandle no-print" draggable="true" title="Drag to reorder row" aria-label="Drag to reorder row"></span>
                            <div class="inpBx itemNameWrap">
                                <textarea rows="1" placeholder="" class="txtfld itemNameInput" autocomplete="off"></textarea>
                                <div class="itemSuggestList"></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="inpBx">
                            <textarea rows="1" placeholder="" class="txtfld itemDescriptionInput"></textarea>
                        </div>
                    </td>
                    <td>
                        <div class="inpBx">
                            <input type="text" placeholder="" class="txtfld"/>
                        </div>
                    </td>
                    <td>
                        <div class="inpBx">
                            <input type="text" placeholder="" class="txtfld itemSqftInput"/>
                        </div>
                    </td>
                    <td>
                        <div class="inpBx">
                            <input type="text" placeholder="" class="txtfld itemQtyInput"/>
                        </div>
                    </td>
                    <td>
                        <div class="inpBx">
                            <input type="text" placeholder="Rate" class="txtfld itemRateInput"/>
                        </div>
                    </td>
                    <td>
                        <div class="inpBx">
                            <input type="text" placeholder="Amt" class="txtfld itemAmtInput" readonly/>
                        </div>
                    </td>
                    <td>&nbsp;</td>
                    <td class="no-print"><span class="closeBtn" title="Delete Row">Close</span></td>
                </tr>';
}

function vr_renderEstimateGroup(array $categories)
{
    return '
            <tbody class="estimate-group">
                <tr>
                    <th colspan="7">
                        <div class="secHdrT1"><span class="groupDragHandle no-print" draggable="true" title="Drag to reorder group" aria-label="Drag to reorder group"></span>' . vr_renderCategorySelect($categories) . '</div>
                    </th>
                    <th><div class="secHdrT2"><span class="tclr02 group-subtotal">0</span></div></th>
                    <th class="no-print"><span class="closeBtnGroup" title="Delete Group">Close</span></th>
                </tr>' . vr_renderItemRow() . '
                <tr class="add-row-tr no-print">
                    <td colspan="9">
                        <div class="adRowBtnBx">
                            <span class="addRowBtn">Add Row</span>
                        </div>
                    </td>
                </tr>
            </tbody>';
}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>Vendor Requirement - ONE8 EVENT</title>
<meta name="HandheldFriendly" content="true">
<meta name="viewport" content="width=device-width, initial-scale=0.666667, maximum-scale=0.666667, user-scalable=0">
<meta name="viewport" content="width=device-width">
<link href="css/style.css" rel="stylesheet" type="text/css" />
<script>
    var estimateCategories = <?php echo json_encode($categories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var estimateId = '';
    var estimateData = <?php echo json_encode($estimateData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var estimateLocked = false;
    var estimateIsManager = false;
    var estimateAppBase = <?php echo json_encode(estimate_app_base_path(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var estimateSpectator = false;
    var estimateUpdatedAt = '';
    var estimateEditToken = '';
    var estimateAppConfig = {
        mode: 'vendor',
        rateMode: 'b2v',
        enableSave: false,
        enablePrint: false,
        enableRateToggle: false,
        enableVendorCheckbox: false,
        searchItemsMode: 'b2v',
        documentTitle: 'Vendor Requirement by ONE8 EVENT X'
    };
</script>
<script src="js/estimate.js" defer></script>
</head>
<body class="vendor-requirement-page">
<header>
    <div class="page">
        <div class="hdRow">
            <div class="hdClm01">
                <div class="rateToggle no-print vendorRateBadge" role="status" aria-label="Rate type">
                    <span class="rateToggleBtn is-active">B2V Rate</span>
                </div>
                <div class="estimateNav no-print">
                    <button type="button" class="estimateActionBtn estimateExportVendorBtn">Export Excel</button>
                    <button type="button" class="estimateActionBtn" onclick="window.close();">Close</button>
                </div>
            </div>
            <div class="hdClm02">
                <span class="tclr01">ONE</span><span class="tclr02">8</span> EVENT
            </div>
            <div class="hdClm03">
                <img height="20" src="image/front_print_size-01.png" alt="">
            </div>
        </div>

        <div class="hData">
            <table>
                <tr>
                    <th style="border-right: none;">PROJECT TYPE</th>
                    <td style="border-left: none; border-right: none;"><input type="text" placeholder="" class="txtfld projectTypeInput"></td>
                    <th style="border-right: none; border-left: none;">COMPANY NAME</th>
                    <td style="border-left: none;"><input type="text" placeholder="" class="txtfld companyNameInput"></td>
                </tr>
                <tr>
                    <th style="border-right: none;">PROJECT OWNER</th>
                    <td style="border-left: none; border-right: none;"><input type="text" placeholder="" class="txtfld projectOwnerInput"></td>
                    <th style="border-right: none; border-left: none;">EVENT DATE</th>
                    <td style="border-left: none;"><input type="text" placeholder="" class="txtfld eventDateInput"></td>
                </tr>
            </table>
        </div>
    </div>
</header>
<section class="formArea">
<div class="page">
    <p class="vendorRequirementNote no-print">Edit freely — nothing is saved. Export to Excel when ready; further changes happen in Excel.</p>
    <div class="frmTable">
        <table>
            <thead>
                <tr>
                    <th style="width: 33%;">ITEM NAME</th>
                    <th style="width: 20%;">DESCRIPTION</th>
                    <th style="width: 9%;">SIZE</th>
                    <th style="width: 5%;">SQFT</th>
                    <th style="width: 4%;">QTY</th>
                    <th style="width: 6%;">RATE</th>
                    <th style="width: 8%;">AMT</th>
                    <th style="width: 10%;">SUB TOTAL</th>
                    <th class="no-print">&nbsp;</th>
                </tr>
            </thead>
            <?php echo vr_renderEstimateGroup($categories); ?>

            <tbody class="add-group-row no-print">
                <tr>
                    <th colspan="9">
                        <div class="adGroupBtnBx">
                            <span class="addGrpBtn"><span>Add Group</span></span>
                        </div>
                    </th>
                </tr>
            </tbody>

            <tbody class="tbdy-footer">
                <tr>
                    <th colspan="4">VENDOR REQUIREMENT AMOUNT IN WORDS</th>
                    <th colspan="5">AMOUNTS</th>
                </tr>
                <tr>
                    <th colspan="4" rowspan="3" class="amount-in-words">Zero Rupees And Zero Paisa Only.</th>
                    <th colspan="3" class="amount-label">TOTAL</th>
                    <th colspan="2" class="amount-value estimate-total">0</th>
                </tr>
                <tr class="discount-row is-hidden">
                    <th colspan="3" class="amount-label">DISCOUNT</th>
                    <th colspan="2" class="amount-value">
                        <input type="text" class="txtfld discountInput" value="0"/>
                    </th>
                </tr>
                <tr class="subtotal-row is-hidden">
                    <th colspan="3" class="amount-label">SUB TOTAL</th>
                    <th colspan="2" class="amount-value estimate-subtotal">0</th>
                </tr>
                <tr>
                    <th colspan="3" class="amount-label">GST 18%</th>
                    <th colspan="2" class="amount-value estimate-gst">0</th>
                </tr>
                <tr>
                    <th colspan="3" class="amount-label">GRAND TOTAL</th>
                    <th colspan="2" class="amount-value estimate-grand-total">0</th>
                </tr>
            </tbody>
        </table>
    </div>
</div>
</section>
</body>
</html>
