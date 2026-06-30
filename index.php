<?php
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/auth.php';
require_once __DIR__ . '/api/estimate_sanitize.php';

$isManager = estimate_is_manager();

$conn = estimate_db_connect();

if (!$conn) {
    die('Database connection failed.');
}

estimate_ensure_table($conn);

$estimateId = isset($_GET['id']) ? preg_replace('/[^a-f0-9]/', '', strtolower((string) $_GET['id'])) : '';
$estimateData = [];
$estimateLocked = false;
$estimateUpdatedAt = '';
$isSpectator = false;
$editTokenFromUrl = isset($_GET['edit']) ? estimate_normalize_edit_token((string) $_GET['edit']) : '';
$forceView = isset($_GET['view']) && (string) $_GET['view'] === '1';

if ($estimateId !== '') {
    $stmt = $conn->prepare(
        'SELECT data, is_locked, updated_at, edit_token, edit_token_expires
         FROM estimates
         WHERE id = ? AND is_deleted = 0'
    );

    if ($stmt) {
        $stmt->bind_param('s', $estimateId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            header('Location: index.php');
            exit;
        }

        $decoded = json_decode($row['data'], true);
        $estimateData = is_array($decoded) ? $decoded : [];
        $estimateLocked = (int) $row['is_locked'] === 1;
        $estimateUpdatedAt = estimate_normalize_updated_at($row['updated_at'] ?? '');

        if ($forceView || $editTokenFromUrl === '') {
            $isSpectator = true;
        } else {
            $isSpectator = !estimate_verify_edit_token($conn, $estimateId, $editTokenFromUrl);
        }
    }
}

$categories = [];
$categoryResult = $conn->query('SELECT id, name FROM estimate_category ORDER BY id ASC');

if ($categoryResult) {
    while ($row = $categoryResult->fetch_assoc()) {
        $categories[] = $row;
    }
}

if ($isManager && !empty($estimateData)) {
    $estimateData = estimate_strip_financial_fields($estimateData);
}

function renderCategorySelect(array $categories, $selectedId = '')
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

function renderItemRow($isManager = false)
{
    $rateCells = $isManager
        ? '<td class="manager-hidden">&nbsp;</td>
                    <td class="manager-hidden">&nbsp;</td>
                    <td class="manager-hidden">&nbsp;</td>'
        : '<td class="manager-hidden">
                        <div class="inpBx">
                            <input type="text" placeholder="Rate" class="txtfld itemRateInput"/>
                        </div>
                    </td>
                    <td class="manager-hidden">
                        <div class="inpBx">
                            <input type="text" placeholder="Amt" class="txtfld itemAmtInput" readonly/>
                        </div>
                    </td>
                    <td class="manager-hidden">&nbsp;</td>';

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
                    ' . $rateCells . '
                    <td class="no-print"><span class="closeBtn" title="Delete Row">Close</span></td>
                </tr>';
}

function renderEstimateGroup(array $categories, $isManager = false)
{
    return '
            <tbody class="estimate-group">
                <tr>
                    <th colspan="7">
                        <div class="secHdrT1"><span class="groupDragHandle no-print" draggable="true" title="Drag to reorder group" aria-label="Drag to reorder group"></span>' . renderCategorySelect($categories) . '</div>
                    </th>
                    <th class="manager-hidden"><div class="secHdrT2"><span class="tclr02 group-subtotal">0</span></div></th>
                    <th class="no-print"><span class="closeBtnGroup" title="Delete Group">Close</span></th>
                </tr>' . renderItemRow($isManager) . '
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

<title>Estimate By ONE8 EVENT X</title>
<meta name="HandheldFriendly" content="true">
<meta name="viewport" content="width=device-width, initial-scale=0.666667, maximum-scale=0.666667, user-scalable=0">
<meta name="viewport" content="width=device-width">

<link href="css/style.css" rel="stylesheet" type="text/css" />
<link href="css/print.css" rel="stylesheet" media="print" />
<style media="print">
  .frmTable table {
    border-collapse: separate !important;
    border-spacing: 0 !important;
  }
  .frmTable thead tr th {
    background-color: #ff5400 !important;
    background-image: linear-gradient(#ff5400, #ff5400) !important;
    -webkit-box-shadow: inset 0 0 0 9999px #ff5400 !important;
    box-shadow: inset 0 0 0 9999px #ff5400 !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
</style>
<script>
    var estimateCategories = <?php echo json_encode($categories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var estimateId = <?php echo json_encode($estimateId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var estimateData = <?php echo json_encode($estimateData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var estimateLocked = <?php echo $estimateLocked ? 'true' : 'false'; ?>;
    var estimateIsManager = <?php echo $isManager ? 'true' : 'false'; ?>;
    var estimateAppBase = <?php echo json_encode(estimate_app_base_path(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var estimateSpectator = <?php echo ($isSpectator || $estimateLocked) ? 'true' : 'false'; ?>;
    var estimateUpdatedAt = <?php echo json_encode($estimateUpdatedAt, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var estimateEditToken = <?php echo json_encode($editTokenFromUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="js/estimate.js" defer></script>

</head>
<body<?php
    $bodyClasses = [];
    if ($estimateLocked) {
        $bodyClasses[] = 'estimate-locked';
    }
    if ($isManager) {
        $bodyClasses[] = 'manager-view';
    }
    if ($isSpectator || $estimateLocked) {
        $bodyClasses[] = 'estimate-spectator';
    }
    echo $bodyClasses ? ' class="' . implode(' ', $bodyClasses) . '"' : '';
?>>
<?php if ($isSpectator && !$estimateLocked && $estimateId !== '') : ?>
<div class="estimateSpectatorBanner no-print">
    <span class="estimateSpectatorText">View only — changes here will not be saved. Live updates from the editor appear automatically.</span>
    <button type="button" class="estimateActionBtn estimateStartEditBtn">Edit estimate</button>
    <span class="estimateLiveStatus is-hidden no-print" aria-live="polite"></span>
</div>
<?php elseif ($isSpectator && $estimateLocked && $estimateId !== '') : ?>
<div class="estimateSpectatorBanner no-print">
    <span class="estimateSpectatorText">View only — this estimate is locked.</span>
    <span class="estimateLiveStatus is-hidden no-print" aria-live="polite"></span>
</div>
<?php endif; ?>
<?php if ($estimateLocked) : ?>
<div class="estimateLockedBanner no-print">
    This estimate is locked and cannot be edited.<?php if (!$isManager) : ?> Unlock it from <a href="estimates.php">All Estimates</a>.<?php endif; ?>
</div>
<?php endif; ?>
<header>
    <div class="page">

        <div class="hdRow">
            <div class="hdClm01">
                <?php if (!$isManager) : ?>
                <div class="rateToggle no-print" role="group" aria-label="Rate type">
                    <button type="button" class="rateToggleBtn is-active" data-rate-mode="b2b">B2B</button>
                    <button type="button" class="rateToggleBtn" data-rate-mode="d2c">B2C</button>
                </div>
                <?php endif; ?>
                <div class="estimateNav no-print">
                    <button type="button" class="estimateIconBtn is-print estimatePrintBtn" title="Print" aria-label="Print"></button>
                    <?php if ($estimateId !== '' && !$estimateLocked && !$isManager) : ?>
                    <form class="estimateIconForm" method="post" action="api/toggle_lock.php" onsubmit="return confirm('Lock this estimate? It cannot be edited after locking.');">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($estimateId, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="locked" value="1">
                        <input type="hidden" name="redirect" value="index.php?id=<?php echo htmlspecialchars($estimateId, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="estimateIconBtn is-lock" title="Lock">Lock</button>
                    </form>
                    <?php endif; ?>
                    <a class="estimateActionBtn" href="index.php">New Estimate</a>
                    <?php if (!$isManager) : ?>
                    <a class="estimateActionBtn" href="estimates.php">All Estimates</a>
                    <?php endif; ?>
                </div>
                <div class="estimateSaveStatus is-hidden no-print" role="status" aria-live="polite"></div>
            </div>
            <div class="hdClm02">
                <span class="tclr01">ONE</span><span class="tclr02">8</span> EVENT
            </div>
            <div class="hdClm03">
                <img height="20" src="image/front_print_size-01.png">
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

    <div class="frmTable">
        <table>
            <thead>
                <tr>
                    <th style="width: 33%;">ITEM NAME</th>
                    <th style="width: 20%;">DESCRIPTION</th>
                    <th style="width: 9%;">SIZE</th>
                    <th style="width: 5%;">SQFT</th>
                    <th style="width: 4%;">QTY</th>
                    <th class="manager-hidden" style="width: 6%;">RATE</th>
                    <th class="manager-hidden" style="width: 8%;">AMT</th>
                    <th class="manager-hidden" style="width: 10%;">SUB TOTAL</th>
                    <th class="no-print">&nbsp;</th>
                </tr>
            </thead>
            <?php echo renderEstimateGroup($categories, $isManager); ?>

            <tbody class="add-group-row no-print">
                <tr>
                    <th colspan="9">
                        <div class="adGroupBtnBx">
                            <span class="addGrpBtn"><span>Add Group</span></span>
                        </div>
                    </th>
                </tr>
            </tbody>

            <!-- <tbody>
                <tr>
                    <th colspan="7">
                        <div class="secHdrT1">
                            <select>
                                <option>Select Category</option>
                                <option>HOTEL & BANQUET</option>
                                <option>STAGE & FABRICATION</option>
                            </select>
                        </div>
                    </th>
                    <th><div class="secHdrT2"><span class="tclr02">89300</span></div></th>
                    <th><span class="closeBtnGroup" title="Delete Group">Close</span></th>
                </tr>
                <tr>
                    <td>STAGE WITH FLEX FINISH</td>
                    <td>CUSTIMIZE</td>
                    <td>20'X10'X1.5'</td>
                    <td>200</td>
                    <td>1</td>
                    <td>150</td>
                    <td>30000</td>
                    <td>&nbsp;</td>
                    <td><span class="closeBtn">Close</span></td>
                </tr>
                <tr>
                    <td>STAGE WITH FLEX FINISH</td>
                    <td>CUSTIMIZE</td>
                    <td>20'X10'X1.5'</td>
                    <td>200</td>
                    <td>1</td>
                    <td>150</td>
                    <td>30000</td>
                    <td>&nbsp;</td>
                    <td><span class="closeBtn">Close</span></td>
                </tr>
                <tr>
                    <td colspan="9">
                        <div class="adRowBtnBx">
                            <span class="addRowBtn">Add Row</span>
                        </div>
                    </td>
                </tr>
            </tbody>

            <tbody>
                <tr>
                    <th colspan="9">
                        <div class="adGroupBtnBx">
                            <span class="addGrpBtn"><span>Add Group</span></span>
                        </div>
                    </th>                    
                </tr>                
            </tbody> -->

            <tbody class="manager-hidden tbdy-footer">
                <tr>
                    <th colspan="4">
                        ESTIMATE AMOUNT IN WORDS
                    </th>
                    <th colspan="5">
                        AMOUNTS
                    </th>
                </tr>
                <tr>
                    <th colspan="4" rowspan="3" class="amount-in-words">
                        Zero Rupees And Zero Paisa Only.
                    </th>
                    <th colspan="3" class="amount-label">
                        TOTAL
                        <span class="addDiscountBtn no-print">Add Discount</span>
                    </th>
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

<footer>
    <div class="page">
    <div class="ftrData">
    <table>
        <tr>
            <td>
                <div class="bankDtls">
                    <h4>BANK DETAILS</h4>
                    <table style="font-weight:bold">
                        <tr style="border: 1px solid #000;">
                            <td>BANK NAME</td>
                            <td>AXIS BANK</td>
                        </tr>
                        <tr>
                            <td>ACCOUNT NO</td>
                            <td>925020040474333</td>
                        </tr>
                        <tr>
                            <td>IFSC CODE</td>
                            <td>UTIB0000259</td>
                        </tr>
                        <tr>
                            <td>ACCOUNT HOLDER'S NAME</td>
                            <td>Woan Eight Event Private Limited</td>
                        </tr>

                        
                    </table>
                </div>
            </td>
            <td>
                <div class="signBx">
                    <h4>FOR : Woan Eight Event Private Limited</h4>
                    <img height="60px" src="image/stamp.jpg"/><br/>
                    <p>AUTHORIZED SIGNATORY</p>
                </div>
            </td>
        </tr>
        <tr>
            <td colspan="2" class="termsCell">
                <div class="termsBox">
                    <h4>TERMS &amp; CONDITIONS</h4>
                    <ol class="termsList">
                        <li>Estimate Validity: The estimate provided is valid for 15 days from the date of issue. </li>
                        <li>Payment Terms: A 70% advance payment is required upon confirmation of the booking. The 
                        remaining 30% is due on the event day. </li>
                        <li>Additional Requirements: Any additional requirements or services beyond the initial scope 
                        will incur extra charges, which will be included in the final billing. </li>
                        <li>Delays: Any delays in approvals or bookings, due to seasonal or other factors, may result in 
                        revised rates. </li>
                        <li>Deliverables: All materials, designs, and other deliverables will be provided after full 
                        payment. Post-payment, we will also offer support for all deliverables. </li>
                        <li>TDS: All payments are subject to a 2% TDS deduction. If a company wishes to deduct more 
                        than 2% TDS, prior written notice must be given to us. </li>
                        <li>GST: We do not withhold any GST payment. Once the total payment is made, no further 
                        GST payments are pending from us.</li>
                       
                    </ol>
                </div>
            </td>
        </tr>
    </table>
    </div>
    </div>
</footer>


</body>
</html>
<?php $conn->close(); ?>
