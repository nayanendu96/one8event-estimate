<?php

const ESTIMATE_GST_RATE = 0.18;

function estimate_parse_amount($value): float
{
    $cleaned = str_replace(',', '', trim((string) $value));
    $num = (float) $cleaned;

    return is_finite($num) ? $num : 0.0;
}

function estimate_is_calc_field_active($value): bool
{
    $raw = trim((string) $value);

    return $raw !== '' && $raw !== '~';
}

function estimate_parse_amount_or_default($value, float $defaultValue): float
{
    $raw = trim((string) $value);

    if ($raw === '' || $raw === '~') {
        return $defaultValue;
    }

    return estimate_parse_amount($raw);
}

function estimate_calculate_line_amount($unit, $qty, $rate): float
{
    $sqftRaw = trim((string) $unit);
    $qtyRaw = trim((string) $qty);
    $rateRaw = trim((string) $rate);

    if (!estimate_is_calc_field_active($sqftRaw)
        && !estimate_is_calc_field_active($qtyRaw)
        && !estimate_is_calc_field_active($rateRaw)) {
        return 0.0;
    }

    $sqft = estimate_parse_amount_or_default($unit, 1.0);
    $qtyVal = estimate_parse_amount_or_default($qty, 1.0);
    $rateVal = estimate_parse_amount_or_default($rate, 1.0);

    return $sqft * $qtyVal * $rateVal;
}

function estimate_strip_selected_item_for_manager(?array $selectedItem): ?array
{
    if (!is_array($selectedItem)) {
        return null;
    }

    $stripped = [];

    if (isset($selectedItem['id'])) {
        $stripped['id'] = (int) $selectedItem['id'];
    }

    if (isset($selectedItem['item_name'])) {
        $stripped['item_name'] = (string) $selectedItem['item_name'];
    }

    if (isset($selectedItem['unit'])) {
        $stripped['unit'] = (string) $selectedItem['unit'];
    }

    return $stripped ?: null;
}

function estimate_strip_financial_fields(array $data): array
{
    $stripped = $data;

    unset($stripped['discount']);

    if (!empty($stripped['groups']) && is_array($stripped['groups'])) {
        foreach ($stripped['groups'] as $groupIndex => $group) {
            if (empty($group['items']) || !is_array($group['items'])) {
                continue;
            }

            foreach ($group['items'] as $itemIndex => $item) {
                if (!is_array($item)) {
                    continue;
                }

                unset($item['rate'], $item['amount'], $item['amt']);

                if (array_key_exists('selectedItem', $item)) {
                    $item['selectedItem'] = estimate_strip_selected_item_for_manager($item['selectedItem']);
                }

                $stripped['groups'][$groupIndex]['items'][$itemIndex] = $item;
            }
        }
    }

    return $stripped;
}

function estimate_fetch_catalog_item($conn, int $itemId): ?array
{
    if ($itemId <= 0 || !$conn) {
        return null;
    }

    estimate_ensure_item_table($conn);

    $stmt = $conn->prepare(
        'SELECT id, item_name, unit, b2b_rate, d2c_rate, b2v_rate
         FROM estimate_item
         WHERE id = ? AND is_hidden = 0'
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return null;
    }

    return estimate_normalize_catalog_item_row($row);
}

function estimate_normalize_catalog_item_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'item_name' => (string) $row['item_name'],
        'unit' => (string) $row['unit'],
        'b2b_rate' => (string) $row['b2b_rate'],
        'd2c_rate' => (string) $row['d2c_rate'],
        'b2v_rate' => (string) ($row['b2v_rate'] ?? ''),
    ];
}

function estimate_fetch_catalog_item_by_name($conn, string $itemName): ?array
{
    $itemName = trim($itemName);

    if ($itemName === '' || !$conn) {
        return null;
    }

    estimate_ensure_item_table($conn);

    $stmt = $conn->prepare(
        'SELECT id, item_name, unit, b2b_rate, d2c_rate, b2v_rate
         FROM estimate_item
         WHERE UPPER(item_name) = UPPER(?) AND is_hidden = 0
         LIMIT 1'
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $itemName);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return null;
    }

    return estimate_normalize_catalog_item_row($row);
}

function estimate_normalize_item_name($name): string
{
    return strtoupper(trim((string) $name));
}

/**
 * A selectedItem binding is only trustworthy if its cached item_name still matches the
 * row's own name. If they differ, the binding is stale/corrupted (e.g. carried over from
 * a different row during an old index-based merge bug) and must not be used for pricing.
 */
function estimate_selected_item_matches_row(?array $selectedItem, string $rowName): bool
{
    if (!is_array($selectedItem)) {
        return false;
    }

    $rowName = estimate_normalize_item_name($rowName);
    $selectedName = estimate_normalize_item_name($selectedItem['item_name'] ?? '');

    return $rowName !== '' && $selectedName !== '' && $rowName === $selectedName;
}

function estimate_resolve_catalog_item(array $item, $conn): ?array
{
    $selectedItem = $item['selectedItem'] ?? null;
    $itemName = trim((string) ($item['name'] ?? ''));

    if (is_array($selectedItem) && !empty($selectedItem['id'])
        && estimate_selected_item_matches_row($selectedItem, $itemName)) {
        $catalogItem = estimate_fetch_catalog_item($conn, (int) $selectedItem['id']);

        if ($catalogItem) {
            return $catalogItem;
        }
    }

    if ($itemName !== '') {
        return estimate_fetch_catalog_item_by_name($conn, $itemName);
    }

    return null;
}

function estimate_get_catalog_rate(array $catalogItem, string $rateMode): string
{
    if ($rateMode === 'b2v') {
        return (string) ($catalogItem['b2v_rate'] ?? '');
    }

    if ($rateMode === 'd2c') {
        return (string) ($catalogItem['d2c_rate'] ?? '');
    }

    return (string) ($catalogItem['b2b_rate'] ?? '');
}

function estimate_get_stored_item_rate(array $item, ?array $existingItem, string $rateMode): string
{
    $sources = [$item];

    if (is_array($existingItem)) {
        $sources[] = $existingItem;
    }

    // Line rate wins over catalog defaults in selectedItem (user may have edited it).
    foreach ($sources as $source) {
        if (!array_key_exists('rate', $source)) {
            continue;
        }

        $rate = trim((string) $source['rate']);

        if ($rate !== '') {
            return $rate;
        }
    }

    $rowName = trim((string) ($item['name'] ?? ($existingItem['name'] ?? '')));

    foreach ($sources as $source) {
        $selectedItem = $source['selectedItem'] ?? null;

        if (!estimate_selected_item_matches_row($selectedItem, $rowName)) {
            continue;
        }

        $modeRate = estimate_get_catalog_rate($selectedItem, $rateMode);

        if ($modeRate !== '') {
            return $modeRate;
        }
    }

    return '';
}

function estimate_preserve_selected_item(array $item, ?array $existingItem, ?array $catalogItem): ?array
{
    $rowName = trim((string) ($item['name'] ?? ($existingItem['name'] ?? '')));

    if (estimate_selected_item_matches_row($item['selectedItem'] ?? null, $rowName)) {
        return $item['selectedItem'];
    }

    if (estimate_selected_item_matches_row($existingItem['selectedItem'] ?? null, $rowName)) {
        return $existingItem['selectedItem'];
    }

    return $catalogItem;
}

function estimate_resolve_item_rate(
    array $incomingItem,
    ?array $existingItem,
    string $rateMode,
    $conn,
    bool $freezeRates = false
): string {
    $storedRate = estimate_get_stored_item_rate($incomingItem, $existingItem, $rateMode);

    if ($storedRate !== '') {
        return $storedRate;
    }

    if ($freezeRates) {
        return '';
    }

    $catalogItem = estimate_resolve_catalog_item($incomingItem, $conn);

    if ($catalogItem) {
        return estimate_get_catalog_rate($catalogItem, $rateMode);
    }

    return '';
}

function estimate_index_existing_items_by_name(array $existingItems): array
{
    $pool = [];

    foreach ($existingItems as $existingItem) {
        if (!is_array($existingItem)) {
            continue;
        }

        $name = estimate_normalize_item_name($existingItem['name'] ?? '');

        if ($name === '') {
            continue;
        }

        $pool[$name][] = $existingItem;
    }

    return $pool;
}

/**
 * Indexes every existing item across all groups by its client-assigned rowId. This is the
 * most reliable identity signal: it survives renames, duplicate names, and even a row being
 * moved to a different group, unlike name- or index-based matching.
 */
function estimate_index_existing_items_by_row_id(array $existingGroups): array
{
    $pool = [];

    foreach ($existingGroups as $existingGroup) {
        if (empty($existingGroup['items']) || !is_array($existingGroup['items'])) {
            continue;
        }

        foreach ($existingGroup['items'] as $existingItem) {
            if (!is_array($existingItem)) {
                continue;
            }

            $rowId = trim((string) ($existingItem['rowId'] ?? ''));

            if ($rowId === '') {
                continue;
            }

            $pool[$rowId] = $existingItem;
        }
    }

    return $pool;
}

/**
 * Finds the existing item that corresponds to the incoming item.
 *
 * Preferred match: the client-assigned rowId, which uniquely identifies a row regardless
 * of edits, reordering, or moving it to a different group.
 *
 * Fallback (for rows saved before rowId existed): match by name, consumed in order via a
 * per-name queue, rather than raw array index -- a row added/removed/reordered anywhere in
 * the group shifts every index after it, so matching by index alone would graft an
 * unrelated item's rate/catalog binding onto the wrong row.
 */
function estimate_match_existing_item(
    array $item,
    int $itemIndex,
    array $existingItems,
    array &$existingPool,
    array $existingByRowId
): ?array {
    $rowId = trim((string) ($item['rowId'] ?? ''));

    if ($rowId !== '' && isset($existingByRowId[$rowId])) {
        return $existingByRowId[$rowId];
    }

    $itemName = estimate_normalize_item_name($item['name'] ?? '');

    if ($itemName !== '') {
        if (!empty($existingPool[$itemName])) {
            return array_shift($existingPool[$itemName]);
        }

        return null;
    }

    // No name to match on (blank custom row): fall back to position, but only when the
    // existing row at that position is also unnamed, to avoid pulling in an unrelated item.
    $positional = is_array($existingItems[$itemIndex] ?? null) ? $existingItems[$itemIndex] : null;

    if ($positional && estimate_normalize_item_name($positional['name'] ?? '') === '') {
        return $positional;
    }

    return null;
}

function estimate_rebuild_financial_fields(array $incoming, ?array $existing, $conn, bool $freezeRates = false): array
{
    $merged = $incoming;
    $incomingMode = isset($incoming['rateMode']) ? (string) $incoming['rateMode'] : 'b2b';
    $rateMode = in_array($incomingMode, ['d2c', 'b2v'], true) ? $incomingMode : 'b2b';
    $merged['rateMode'] = $rateMode;

    if (empty($merged['groups']) || !is_array($merged['groups'])) {
        $merged['groups'] = [];
    }

    $existingGroups = is_array($existing['groups'] ?? null) ? $existing['groups'] : [];
    $existingByRowId = estimate_index_existing_items_by_row_id($existingGroups);

    foreach ($merged['groups'] as $groupIndex => $group) {
        if (empty($group['items']) || !is_array($group['items'])) {
            $merged['groups'][$groupIndex]['items'] = [];
            continue;
        }

        $existingItems = is_array($existingGroups[$groupIndex]['items'] ?? null)
            ? $existingGroups[$groupIndex]['items']
            : [];

        $existingPool = estimate_index_existing_items_by_name($existingItems);

        foreach ($group['items'] as $itemIndex => $item) {
            if (!is_array($item)) {
                continue;
            }

            $existingItem = estimate_match_existing_item($item, $itemIndex, $existingItems, $existingPool, $existingByRowId);

            $catalogItem = estimate_resolve_catalog_item($item, $conn);
            $item['selectedItem'] = estimate_preserve_selected_item($item, $existingItem, $catalogItem);

            $item['rate'] = estimate_resolve_item_rate(
                $item,
                $existingItem,
                $rateMode,
                $conn,
                $freezeRates
            );
            $merged['groups'][$groupIndex]['items'][$itemIndex] = $item;
        }
    }

    return $merged;
}

function estimate_calculate_estimate_total(array $data): float
{
    $total = 0.0;

    if (empty($data['groups']) || !is_array($data['groups'])) {
        return $total;
    }

    foreach ($data['groups'] as $group) {
        if (empty($group['items']) || !is_array($group['items'])) {
            continue;
        }

        foreach ($group['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $total += estimate_calculate_line_amount(
                $item['unit'] ?? '',
                $item['qty'] ?? '',
                $item['rate'] ?? ''
            );
        }
    }

    return $total;
}

function estimate_calculate_grand_total(array $data): float
{
    $estimateTotal = estimate_calculate_estimate_total($data);
    $discount = estimate_parse_amount($data['discount'] ?? '0');
    $subTotal = max(0.0, $estimateTotal - $discount);
    $gst = round($subTotal * ESTIMATE_GST_RATE);

    return $subTotal + $gst;
}

/**
 * Attach hidden B2V catalog rates to line items for vendor-requirement export.
 * Not persisted on save — enriched on each estimate load / poll only.
 */
function estimate_enrich_b2v_rates(array $data, $conn): array
{
    if (empty($data['groups']) || !is_array($data['groups']) || !$conn) {
        return $data;
    }

    foreach ($data['groups'] as $groupIndex => $group) {
        if (empty($group['items']) || !is_array($group['items'])) {
            continue;
        }

        foreach ($group['items'] as $itemIndex => $item) {
            if (!is_array($item)) {
                continue;
            }

            $catalogItem = estimate_resolve_catalog_item($item, $conn);
            $data['groups'][$groupIndex]['items'][$itemIndex]['b2vRate'] = $catalogItem
                ? estimate_get_catalog_rate($catalogItem, 'b2v')
                : '';
        }
    }

    return $data;
}

/**
 * Prepare vendor requirement data: line rates must be B2V catalog only, never estimate B2B/B2C.
 */
function vendor_requirement_prepare_data(array $data, $conn): array
{
    $data['rateMode'] = 'b2v';
    $data['discount'] = '0';
    $data['discountVisible'] = false;

    if (empty($data['groups']) || !is_array($data['groups'])) {
        $data['groups'] = [];

        return $data;
    }

    foreach ($data['groups'] as $groupIndex => $group) {
        if (empty($group['items']) || !is_array($group['items'])) {
            continue;
        }

        foreach ($group['items'] as $itemIndex => $item) {
            if (!is_array($item)) {
                continue;
            }

            $catalogItem = estimate_resolve_catalog_item($item, $conn);
            $b2v = $catalogItem ? trim(estimate_get_catalog_rate($catalogItem, 'b2v')) : '';

            unset($data['groups'][$groupIndex]['items'][$itemIndex]['b2vRate']);

            $data['groups'][$groupIndex]['items'][$itemIndex]['rate'] = $b2v;

            if (!empty($item['selectedItem']) && is_array($item['selectedItem'])) {
                $selected = $item['selectedItem'];
                $data['groups'][$groupIndex]['items'][$itemIndex]['selectedItem'] = [
                    'id' => isset($selected['id']) ? (int) $selected['id'] : 0,
                    'item_name' => (string) ($selected['item_name'] ?? ($item['name'] ?? '')),
                    'unit' => (string) ($selected['unit'] ?? ''),
                    'b2v_rate' => $b2v,
                ];
            } elseif ($catalogItem) {
                $data['groups'][$groupIndex]['items'][$itemIndex]['selectedItem'] = [
                    'id' => (int) $catalogItem['id'],
                    'item_name' => (string) $catalogItem['item_name'],
                    'unit' => (string) $catalogItem['unit'],
                    'b2v_rate' => $b2v,
                ];
            }
        }
    }

    return $data;
}

function vendor_requirement_export_xlsx(array $data, array $categoryMap): void
{
    require_once __DIR__ . '/lib/simple_xlsx.php';

    unset($categoryMap);

    $header = is_array($data['header'] ?? null) ? $data['header'] : [];
    $companyName = trim((string) ($header['companyName'] ?? ''));
    $projectType = trim((string) ($header['projectType'] ?? ''));
    $projectOwner = trim((string) ($header['projectOwner'] ?? ''));
    $eventDate = trim((string) ($header['eventDate'] ?? ''));

    $estimateTotal = estimate_calculate_estimate_total($data);
    $discount = estimate_parse_amount($data['discount'] ?? '0');
    $subTotal = max(0.0, $estimateTotal - $discount);
    $gst = round($subTotal * ESTIMATE_GST_RATE);
    $grandTotal = $subTotal + $gst;

    $lastCol = 6;

    $writer = new SimpleXlsxWriter();
    $writer->setColumnWidths([
        0 => 28,
        1 => 22,
        2 => 12,
        3 => 8,
        4 => 6,
        5 => 12,
        6 => 14,
    ]);

    $writer->addRow([
        0 => ['value' => 'ONE8 EVENT — VENDOR REQUIREMENT', 'style' => 0],
    ]);
    $writer->mergeLastRow(0, $lastCol);

    $writer->addRow([
        0 => ['value' => 'Project Type', 'style' => 1],
        1 => ['value' => $projectType, 'style' => 2],
        2 => ['value' => 'Company Name', 'style' => 1],
        3 => ['value' => $companyName, 'style' => 2],
        4 => ['value' => 'Project Owner', 'style' => 1],
        5 => ['value' => $projectOwner, 'style' => 2],
        6 => ['value' => 'Event Date: ' . $eventDate, 'style' => 2],
    ]);

    $writer->addRow([]);

    $writer->addRow([
        0 => ['value' => 'ITEM NAME', 'style' => 3],
        1 => ['value' => 'DESCRIPTION', 'style' => 3],
        2 => ['value' => 'SIZE', 'style' => 3],
        3 => ['value' => 'SQFT', 'style' => 3],
        4 => ['value' => 'QTY', 'style' => 3],
        5 => ['value' => 'RATE (B2V)', 'style' => 3],
        6 => ['value' => 'AMOUNT', 'style' => 3],
    ]);

    $groups = is_array($data['groups'] ?? null) ? $data['groups'] : [];

    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $items = is_array($group['items'] ?? null) ? $group['items'] : [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            $description = trim((string) ($item['description'] ?? ''));
            $size = trim((string) ($item['size'] ?? ''));
            $sqft = trim((string) ($item['unit'] ?? ''));
            $qty = trim((string) ($item['qty'] ?? ''));
            $rate = trim((string) ($item['rate'] ?? ''));
            $amount = estimate_calculate_line_amount($item['unit'] ?? '', $item['qty'] ?? '', $item['rate'] ?? '');

            if ($name === '' && $description === '' && $size === '' && $sqft === '' && $qty === '' && $rate === '') {
                continue;
            }

            $rateCell = $rate !== ''
                ? ['number' => estimate_parse_amount($rate), 'style' => 5]
                : ['value' => '', 'style' => 4];

            $amountCell = $amount > 0
                ? ['number' => $amount, 'style' => 5]
                : ['value' => '', 'style' => 5];

            $writer->addRow([
                0 => ['value' => $name, 'style' => 4],
                1 => ['value' => $description, 'style' => 4],
                2 => ['value' => $size, 'style' => 4],
                3 => ['value' => $sqft, 'style' => 4],
                4 => ['value' => $qty, 'style' => 4],
                5 => $rateCell,
                6 => $amountCell,
            ]);
        }
    }

    $writer->addRow([]);

    $writer->addRow([
        0 => ['value' => 'TOTAL', 'style' => 6],
        6 => ['number' => $estimateTotal, 'style' => 7],
    ]);
    $writer->mergeLastRow(0, 5);

    if ($discount > 0) {
        $writer->addRow([
            0 => ['value' => 'DISCOUNT', 'style' => 6],
            6 => ['number' => $discount, 'style' => 7],
        ]);
        $writer->mergeLastRow(0, 5);

        $writer->addRow([
            0 => ['value' => 'SUB TOTAL', 'style' => 6],
            6 => ['number' => $subTotal, 'style' => 7],
        ]);
        $writer->mergeLastRow(0, 5);
    }

    $writer->addRow([
        0 => ['value' => 'GST 18%', 'style' => 6],
        6 => ['number' => $gst, 'style' => 7],
    ]);
    $writer->mergeLastRow(0, 5);

    $writer->addRow([
        0 => ['value' => 'GRAND TOTAL', 'style' => 6],
        6 => ['number' => $grandTotal, 'style' => 7],
    ]);
    $writer->mergeLastRow(0, 5);

    $safeCompany = preg_replace('/[^\w\- ]+/u', '', $companyName) ?: 'vendor-requirement';
    $filename = 'Vendor-Requirement-' . $safeCompany . '-' . date('Y-m-d') . '.xlsx';

    $writer->output($filename);
}
