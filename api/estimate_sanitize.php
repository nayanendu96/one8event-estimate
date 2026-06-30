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
        'SELECT id, item_name, unit, b2b_rate, d2c_rate
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

    return [
        'id' => (int) $row['id'],
        'item_name' => (string) $row['item_name'],
        'unit' => (string) $row['unit'],
        'b2b_rate' => (string) $row['b2b_rate'],
        'd2c_rate' => (string) $row['d2c_rate'],
    ];
}

function estimate_get_catalog_rate(array $catalogItem, string $rateMode): string
{
    if ($rateMode === 'd2c') {
        return (string) ($catalogItem['d2c_rate'] ?? '');
    }

    return (string) ($catalogItem['b2b_rate'] ?? '');
}

function estimate_resolve_item_rate(array $incomingItem, ?array $existingItem, string $rateMode, $conn): string
{
    $selectedItem = $incomingItem['selectedItem'] ?? null;

    if (is_array($selectedItem) && !empty($selectedItem['id'])) {
        $catalogItem = estimate_fetch_catalog_item($conn, (int) $selectedItem['id']);

        if ($catalogItem) {
            return estimate_get_catalog_rate($catalogItem, $rateMode);
        }
    }

    if (is_array($existingItem) && array_key_exists('rate', $existingItem)) {
        return (string) $existingItem['rate'];
    }

    return '';
}

function estimate_rebuild_financial_fields(array $incoming, ?array $existing, $conn): array
{
    $merged = $incoming;
    $rateMode = isset($incoming['rateMode']) && $incoming['rateMode'] === 'd2c' ? 'd2c' : 'b2b';
    $merged['rateMode'] = $rateMode;

    if (empty($merged['groups']) || !is_array($merged['groups'])) {
        $merged['groups'] = [];
    }

    $existingGroups = is_array($existing['groups'] ?? null) ? $existing['groups'] : [];

    foreach ($merged['groups'] as $groupIndex => $group) {
        if (empty($group['items']) || !is_array($group['items'])) {
            $merged['groups'][$groupIndex]['items'] = [];
            continue;
        }

        $existingItems = is_array($existingGroups[$groupIndex]['items'] ?? null)
            ? $existingGroups[$groupIndex]['items']
            : [];

        foreach ($group['items'] as $itemIndex => $item) {
            if (!is_array($item)) {
                continue;
            }

            $existingItem = is_array($existingItems[$itemIndex] ?? null)
                ? $existingItems[$itemIndex]
                : null;

            $selectedItem = $item['selectedItem'] ?? null;

            if (is_array($selectedItem) && !empty($selectedItem['id'])) {
                $catalogItem = estimate_fetch_catalog_item($conn, (int) $selectedItem['id']);

                if ($catalogItem) {
                    $item['selectedItem'] = $catalogItem;
                } elseif (is_array($existingItem['selectedItem'] ?? null)) {
                    $item['selectedItem'] = $existingItem['selectedItem'];
                }
            } elseif (is_array($existingItem['selectedItem'] ?? null)) {
                $item['selectedItem'] = $existingItem['selectedItem'];
            }

            $item['rate'] = estimate_resolve_item_rate($item, $existingItem, $rateMode, $conn);
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
