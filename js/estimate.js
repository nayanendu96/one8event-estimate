(function () {
    'use strict';

    var categories = window.estimateCategories || [];
    var estimateId = window.estimateId || '';
    var estimateData = window.estimateData || {};
    var estimateAppBase = window.estimateAppBase || '';
    var isLocked = !!window.estimateLocked;
    var isManager = !!window.estimateIsManager;
    var savedDiscount = '0';
    var GST_RATE = 0.18;
    var rateMode = 'b2b';
    var discountEditing = false;
    var isRestoring = false;
    var saveTimer = null;
    var saveStatusTimer = null;
    var saveRequestId = 0;
    var saveInFlight = false;
    var saveQueuedTable = null;
    var saveStatusEl = null;
    var hasUnsavedChanges = false;
    var isSpectator = !!window.estimateSpectator;
    var editToken = window.estimateEditToken || '';
    var expectedUpdatedAt = window.estimateUpdatedAt || '';
    var livePollTimer = null;
    var editHeartbeatTimer = null;
    var LIVE_POLL_MS = 5000;
    var EDIT_HEARTBEAT_MS = 60000;
    var DEFAULT_DOCUMENT_TITLE = 'Estimate by ONE8 EVENT X';

    var ones = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'
    ];
    var tens = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'
    ];

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function escapeAttr(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function parseAmount(value) {
        var num = parseFloat(String(value || '').replace(/,/g, '').trim());

        return isNaN(num) ? 0 : num;
    }

    function uppercaseInput(input) {
        if (!input || input.readOnly) {
            return;
        }

        var value = input.value;
        var upper = value.toUpperCase();

        if (value === upper) {
            return;
        }

        var start = input.selectionStart;
        var end = input.selectionEnd;

        input.value = upper;

        if (start !== null && end !== null) {
            input.setSelectionRange(start, end);
        }
    }

    function formatAmount(num) {
        return String(Math.round(num));
    }

    function twoDigits(num) {
        if (num < 20) {
            return ones[num];
        }

        return tens[Math.floor(num / 10)] + (num % 10 ? ' ' + ones[num % 10] : '');
    }

    function threeDigits(num) {
        var result = '';

        if (num >= 100) {
            result += ones[Math.floor(num / 100)] + ' Hundred';
            num %= 100;

            if (num) {
                result += ' ';
            }
        }

        if (num) {
            result += twoDigits(num);
        }

        return result;
    }

    function numberToWordsIndian(num) {
        num = Math.round(Math.abs(num));

        if (num === 0) {
            return 'Zero Rupees And Zero Paisa Only.';
        }

        var parts = [];
        var crore = Math.floor(num / 10000000);

        num %= 10000000;

        var lakh = Math.floor(num / 100000);

        num %= 100000;

        var thousand = Math.floor(num / 1000);

        num %= 1000;

        if (crore) {
            parts.push(threeDigits(crore) + ' Crore');
        }

        if (lakh) {
            parts.push(twoDigits(lakh) + ' Lakh');
        }

        if (thousand) {
            parts.push(twoDigits(thousand) + ' Thousand');
        }

        if (num) {
            parts.push(threeDigits(num));
        }

        return parts.join(' ') + ' Rupees And Zero Paisa Only.';
    }

    function buildCategorySelect() {
        var options = '<option value="">Select Category</option>';

        categories.forEach(function (category) {
            options += '<option value="' + category.id + '">' + escapeHtml(category.name) + '</option>';
        });

        return '<select class="categorySelect">' + options + '</select>';
    }

    function resizeItemNameField(field) {
        if (!field || (!field.classList.contains('itemNameInput') && !field.classList.contains('itemDescriptionInput'))) {
            return;
        }

        field.style.height = 'auto';
        field.style.height = field.scrollHeight + 'px';
    }

    function resizeAllItemNameFields(root) {
        var scope = root || document;

        scope.querySelectorAll('.itemNameInput, .itemDescriptionInput').forEach(resizeItemNameField);
    }

    function buildItemRow() {
        var rateCell = isManager
            ? '<td class="manager-hidden">&nbsp;</td>' +
              '<td class="manager-hidden">&nbsp;</td>' +
              '<td class="manager-hidden">&nbsp;</td>'
            : '<td class="manager-hidden"><div class="inpBx"><input type="text" placeholder="" class="txtfld itemRateInput"/></div></td>' +
              '<td class="manager-hidden"><div class="inpBx"><input type="text" placeholder="" class="txtfld itemAmtInput" readonly/></div></td>' +
              '<td class="manager-hidden">&nbsp;</td>';

        return (
            '<tr class="item-row">' +
                '<td><div class="itemRowLead">' +
                    '<span class="itemDragHandle no-print" draggable="true" title="Drag to reorder row" aria-label="Drag to reorder row"></span>' +
                    '<div class="inpBx itemNameWrap">' +
                    '<textarea rows="1" placeholder="" class="txtfld itemNameInput" autocomplete="off"></textarea>' +
                    '<div class="itemSuggestList"></div>' +
                '</div></div></td>' +
                '<td><div class="inpBx"><textarea rows="1" placeholder="" class="txtfld itemDescriptionInput"></textarea></div></td>' +
                '<td><div class="inpBx"><input type="text" placeholder="" class="txtfld"/></div></td>' +
                '<td><div class="inpBx"><input type="text" placeholder="" class="txtfld itemSqftInput"/></div></td>' +
                '<td><div class="inpBx"><input type="text" placeholder="" class="txtfld itemQtyInput"/></div></td>' +
                rateCell +
                '<td class="no-print"><span class="closeBtn" title="Delete Row">Close</span></td>' +
            '</tr>'
        );
    }

    function getRowAllFields(row) {
        var cells = row.querySelectorAll('td');

        return {
            name: row.querySelector('.itemNameInput'),
            description: row.querySelector('.itemDescriptionInput'),
            size: cells[2] ? cells[2].querySelector('.txtfld') : null,
            sqft: row.querySelector('.itemSqftInput'),
            qty: row.querySelector('.itemQtyInput'),
            rate: row.querySelector('.itemRateInput'),
            amt: row.querySelector('.itemAmtInput')
        };
    }

    function getRowFields(row) {
        return {
            name: row.querySelector('.itemNameInput'),
            sqft: row.querySelector('.itemSqftInput'),
            qty: row.querySelector('.itemQtyInput'),
            rate: row.querySelector('.itemRateInput'),
            amt: row.querySelector('.itemAmtInput')
        };
    }

    function getRowAmount(row) {
        var amtInput = row.querySelector('.itemAmtInput');

        return amtInput ? parseAmount(amtInput.value) : 0;
    }

    function isCalcFieldActive(value) {
        var raw = String(value || '').trim();

        return raw !== '' && raw !== '~';
    }

    function parseAmountOrDefault(value, defaultValue) {
        var raw = String(value || '').trim();

        if (raw === '' || raw === '~') {
            return defaultValue;
        }

        var num = parseAmount(raw);

        return num;
    }

    function calculateRowAmount(row) {
        var sqftInput = row.querySelector('.itemSqftInput');
        var qtyInput = row.querySelector('.itemQtyInput');
        var rateInput = row.querySelector('.itemRateInput');
        var amtInput = row.querySelector('.itemAmtInput');

        if (!sqftInput || !qtyInput || !rateInput || !amtInput) {
            return 0;
        }

        var sqftRaw = String(sqftInput.value || '').trim();
        var qtyRaw = String(qtyInput.value || '').trim();
        var rateRaw = String(rateInput.value || '').trim();

        if (!isCalcFieldActive(sqftRaw) && !isCalcFieldActive(qtyRaw) && !isCalcFieldActive(rateRaw)) {
            amtInput.value = '';
            return 0;
        }

        var sqft = parseAmountOrDefault(sqftInput.value, 1);
        var qty = parseAmountOrDefault(qtyInput.value, 1);
        var rate = parseAmountOrDefault(rateInput.value, 1);
        var amount = sqft * qty * rate;

        amtInput.value = formatAmount(amount);
        return amount;
    }

    function updateGroupSubtotal(group) {
        var total = 0;

        group.querySelectorAll('tr.item-row').forEach(function (row) {
            total += getRowAmount(row);
        });

        var subtotalEl = group.querySelector('.group-subtotal');

        if (subtotalEl) {
            subtotalEl.textContent = formatAmount(total);
        }

        return total;
    }

    function updateDiscountRowVisibility(table, discount) {
        var discountRow = table.querySelector('.discount-row');
        var subtotalRow = table.querySelector('.subtotal-row');
        var wordsEl = table.querySelector('.amount-in-words');
        var addDiscountBtn = table.querySelector('.addDiscountBtn');
        var hasDiscount = discount > 0 || discountEditing;

        if (discountRow) {
            discountRow.classList.toggle('is-hidden', !hasDiscount);
        }

        if (subtotalRow) {
            subtotalRow.classList.toggle('is-hidden', !hasDiscount);
        }

        if (wordsEl) {
            wordsEl.rowSpan = hasDiscount ? 5 : 3;
        }

        if (addDiscountBtn) {
            addDiscountBtn.classList.toggle('is-hidden', hasDiscount);
        }
    }

    function showDiscountRow(table) {
        var discountRow = table.querySelector('.discount-row');
        var discountInput = table.querySelector('.discountInput');

        discountEditing = true;
        updateDiscountRowVisibility(table, parseAmount(discountInput ? discountInput.value : 0));

        if (discountInput) {
            discountInput.focus();
            discountInput.select();
        }
    }

    function calculateEstimateTotals(table) {
        var estimateTotal = 0;

        table.querySelectorAll('tbody.estimate-group').forEach(function (group) {
            group.querySelectorAll('tr.item-row').forEach(function (row) {
                calculateRowAmount(row);
            });
            estimateTotal += updateGroupSubtotal(group);
        });

        var discountInput = table.querySelector('.discountInput');
        var discount = discountInput ? parseAmount(discountInput.value) : parseAmount(savedDiscount);
        var subTotal = Math.max(0, estimateTotal - discount);
        var gst = Math.round(subTotal * GST_RATE);

        return {
            estimateTotal: estimateTotal,
            discount: discount,
            subTotal: subTotal,
            gst: gst,
            grandTotal: subTotal + gst
        };
    }

    function updateFooterTotals(table, shouldSave) {
        var totals = calculateEstimateTotals(table);

        var totalEl = table.querySelector('.estimate-total');
        var subTotalEl = table.querySelector('.estimate-subtotal');
        var gstEl = table.querySelector('.estimate-gst');
        var grandTotalEl = table.querySelector('.estimate-grand-total');
        var wordsEl = table.querySelector('.amount-in-words');

        if (totalEl) {
            totalEl.textContent = formatAmount(totals.estimateTotal);
        }

        if (subTotalEl) {
            subTotalEl.textContent = formatAmount(totals.subTotal);
        }

        if (gstEl) {
            gstEl.textContent = formatAmount(totals.gst);
        }

        if (grandTotalEl) {
            grandTotalEl.textContent = formatAmount(totals.grandTotal);
        }

        if (wordsEl) {
            wordsEl.textContent = numberToWordsIndian(totals.grandTotal);
        }

        updateDiscountRowVisibility(table, totals.discount);

        if (shouldSave !== false) {
            scheduleSave(table);
        }
    }

    function stripSelectedItem(item) {
        if (!item) {
            return null;
        }

        var stripped = {};

        if (item.id) {
            stripped.id = item.id;
        }

        if (item.item_name) {
            stripped.item_name = item.item_name;
        }

        if (item.unit) {
            stripped.unit = item.unit;
        }

        return Object.keys(stripped).length ? stripped : null;
    }

    function collectState(table) {
        var groups = [];

        table.querySelectorAll('tbody.estimate-group').forEach(function (group) {
            var select = group.querySelector('.categorySelect');
            var items = [];

            group.querySelectorAll('tr.item-row').forEach(function (row) {
                var fields = getRowAllFields(row);
                var item = {
                    name: fields.name ? fields.name.value : '',
                    description: fields.description ? fields.description.value : '',
                    size: fields.size ? fields.size.value : '',
                    qty: fields.qty ? fields.qty.value : '',
                    unit: fields.sqft ? fields.sqft.value : ''
                };

                if (!isManager) {
                    item.rate = fields.rate ? fields.rate.value : '';
                }

                if (row._selectedItem) {
                    item.selectedItem = isManager
                        ? stripSelectedItem(row._selectedItem)
                        : row._selectedItem;
                }

                items.push(item);
            });

            groups.push({
                categoryId: select ? select.value : '',
                items: items
            });
        });

        var discountInput = table.querySelector('.discountInput');
        var discount = discountInput ? discountInput.value : savedDiscount;
        var projectTypeInput = document.querySelector('.projectTypeInput');
        var companyNameInput = document.querySelector('.companyNameInput');
        var projectOwnerInput = document.querySelector('.projectOwnerInput');
        var eventDateInput = document.querySelector('.eventDateInput');

        var state = {
            rateMode: rateMode,
            header: {
                projectType: projectTypeInput ? projectTypeInput.value : '',
                companyName: companyNameInput ? companyNameInput.value : '',
                projectOwner: projectOwnerInput ? projectOwnerInput.value : '',
                eventDate: eventDateInput ? eventDateInput.value : ''
            },
            groups: groups
        };

        if (!isManager) {
            state.discount = discount;
            state.discountVisible = discountEditing || parseAmount(discount) > 0;
        }

        return state;
    }

    function hasSavableItems(state) {
        if (!state || !Array.isArray(state.groups)) {
            return false;
        }

        return state.groups.some(function (group) {
            if (!Array.isArray(group.items)) {
                return false;
            }

            return group.items.some(function (item) {
                return ['name', 'description', 'size', 'qty', 'unit', 'rate'].some(function (field) {
                    return String(item[field] || '').trim() !== '';
                });
            });
        });
    }

    function fillItemRow(row, itemData) {
        var fields = getRowAllFields(row);
        var data = itemData || {};

        if (fields.name) {
            fields.name.value = data.name || '';
            resizeItemNameField(fields.name);
        }

        if (fields.description) {
            fields.description.value = data.description || '';
            resizeItemNameField(fields.description);
        }

        if (fields.size) {
            fields.size.value = data.size || '';
        }

        if (fields.sqft) {
            fields.sqft.value = data.unit || '';
        }

        if (fields.qty) {
            fields.qty.value = data.qty || '';
        }

        if (fields.rate && !isManager) {
            fields.rate.value = data.rate || '';
        }

        if (data.selectedItem) {
            row._selectedItem = isManager
                ? stripSelectedItem(data.selectedItem)
                : data.selectedItem;
        } else {
            row._selectedItem = null;
        }
    }

    function buildEstimateGroupFromData(groupData) {
        var items = groupData && Array.isArray(groupData.items) && groupData.items.length
            ? groupData.items
            : [{}];
        var rowsHtml = items.map(function () {
            return buildItemRow();
        }).join('');

        var tbody = document.createElement('tbody');
        tbody.className = 'estimate-group';
        tbody.innerHTML =
            '<tr>' +
                '<th colspan="7">' + buildGroupHeaderHtml() + '</th>' +
                '<th class="manager-hidden"><div class="secHdrT2"><span class="tclr02 group-subtotal">0</span></div></th>' +
                '<th class="no-print"><span class="closeBtnGroup" title="Delete Group">Close</span></th>' +
            '</tr>' +
            rowsHtml +
            '<tr class="add-row-tr no-print">' +
                '<td colspan="9">' +
                    '<div class="adRowBtnBx">' +
                        '<span class="addRowBtn">Add Row</span>' +
                    '</div>' +
                '</td>' +
            '</tr>';

        var select = tbody.querySelector('.categorySelect');

        if (select && groupData && groupData.categoryId) {
            select.value = groupData.categoryId;
        }

        var itemRows = tbody.querySelectorAll('tr.item-row');

        items.forEach(function (item, index) {
            fillItemRow(itemRows[index], item);
        });

        return tbody;
    }

    function applyState(data, table) {
        if (!data || !Object.keys(data).length) {
            return;
        }

        isRestoring = true;

        try {
            if (data.header) {
                var projectTypeInput = document.querySelector('.projectTypeInput');
                var companyNameInput = document.querySelector('.companyNameInput');
                var projectOwnerInput = document.querySelector('.projectOwnerInput');
                var eventDateInput = document.querySelector('.eventDateInput');

                if (projectTypeInput) {
                    projectTypeInput.value = data.header.projectType || '';
                }

                if (companyNameInput) {
                    companyNameInput.value = data.header.companyName || '';
                }

                if (projectOwnerInput) {
                    projectOwnerInput.value = data.header.projectOwner || '';
                }

                if (eventDateInput) {
                    eventDateInput.value = data.header.eventDate || '';
                }
            }

            discountEditing = !!data.discountVisible;
            savedDiscount = data.discount || '0';

            var discountInput = table.querySelector('.discountInput');

            if (discountInput) {
                discountInput.value = data.discount || '0';
            }

            if (data.groups && data.groups.length) {
                table.querySelectorAll('tbody.estimate-group').forEach(function (group) {
                    group.remove();
                });

                var addGroupRow = getAddGroupRow(table);

                data.groups.forEach(function (groupData) {
                    table.insertBefore(buildEstimateGroupFromData(groupData), addGroupRow);
                });
            }

            setRateMode(data.rateMode || 'b2b', table);
            updateFooterTotals(table, false);
            updateDocumentTitle();
        } finally {
            isRestoring = false;
            hasUnsavedChanges = false;
        }
    }

    function isReadOnly() {
        return isLocked || isSpectator;
    }

    function getEditStorageKey(id) {
        return 'estimateEditToken:' + id;
    }

    function storeEditToken(id, token) {
        if (!id || !token) {
            return;
        }

        try {
            sessionStorage.setItem(getEditStorageKey(id), token);
        } catch (err) {}
    }

    function getStoredEditToken(id) {
        if (!id) {
            return '';
        }

        try {
            return sessionStorage.getItem(getEditStorageKey(id)) || '';
        } catch (err) {
            return '';
        }
    }

    function clearStoredEditToken(id) {
        if (!id) {
            return;
        }

        try {
            sessionStorage.removeItem(getEditStorageKey(id));
        } catch (err) {}
    }

    function hideSpectatorBanner() {
        var banner = document.querySelector('.estimateSpectatorBanner');

        if (banner) {
            banner.classList.add('is-hidden');
        }
    }

    function showLiveStatus(message) {
        var el = document.querySelector('.estimateLiveStatus');

        if (!el) {
            return;
        }

        el.textContent = message;
        el.classList.remove('is-hidden');
    }

    function stopLivePolling() {
        if (livePollTimer) {
            clearInterval(livePollTimer);
            livePollTimer = null;
        }
    }

    function stopEditHeartbeat() {
        if (editHeartbeatTimer) {
            clearInterval(editHeartbeatTimer);
            editHeartbeatTimer = null;
        }
    }

    function startEditHeartbeat(table) {
        stopEditHeartbeat();

        if (!estimateId || !editToken || isSpectator) {
            return;
        }

        editHeartbeatTimer = setInterval(function () {
            fetch(getAppPath('api/acquire_edit.php'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    id: estimateId,
                    edit_token: editToken
                })
            })
                .then(function (response) {
                    return response.text().then(function (text) {
                        var result = null;

                        try {
                            result = text ? JSON.parse(text) : null;
                        } catch (err) {
                            result = null;
                        }

                        return { ok: response.ok, result: result };
                    });
                })
                .then(function (payload) {
                    if (!payload.ok || !payload.result || !payload.result.success) {
                        return;
                    }

                    if (payload.result.edit_token) {
                        editToken = payload.result.edit_token;
                        storeEditToken(estimateId, editToken);
                    }
                })
                .catch(function () {});
        }, EDIT_HEARTBEAT_MS);
    }

    function releaseEditLock() {
        if (!estimateId || !editToken || isSpectator) {
            return;
        }

        var payload = JSON.stringify({
            id: estimateId,
            edit_token: editToken
        });

        if (navigator.sendBeacon) {
            navigator.sendBeacon(getAppPath('api/release_edit.php'), new Blob([payload], { type: 'application/json' }));
            return;
        }

        fetch(getAppPath('api/release_edit.php'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: payload,
            keepalive: true
        }).catch(function () {});
    }

    function applyRemoteEstimate(data, updatedAt, table) {
        if (!data) {
            return;
        }

        isRestoring = true;

        try {
            applyState(data, table);

            if (updatedAt) {
                expectedUpdatedAt = updatedAt;
            }
        } finally {
            isRestoring = false;
            hasUnsavedChanges = false;
        }

        showLiveStatus('Updated with latest changes.');
    }

    function pollLiveEstimate(table) {
        if (!estimateId || !isSpectator) {
            return;
        }

        fetch(getAppPath('api/get_estimate.php?id=' + encodeURIComponent(estimateId)), {
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    var result = null;

                    try {
                        result = text ? JSON.parse(text) : null;
                    } catch (err) {
                        result = null;
                    }

                    return { ok: response.ok, result: result };
                });
            })
            .then(function (payload) {
                if (!payload.ok || !payload.result || !payload.result.success) {
                    return;
                }

                if (payload.result.is_locked && !isLocked) {
                    isLocked = true;
                    setLockedUI(true);
                }

                if (payload.result.updated_at && payload.result.updated_at !== expectedUpdatedAt) {
                    applyRemoteEstimate(payload.result.data, payload.result.updated_at, table);
                }
            })
            .catch(function () {});
    }

    function startLivePolling(table) {
        stopLivePolling();

        if (!estimateId || !isSpectator) {
            return;
        }

        pollLiveEstimate(table);
        livePollTimer = setInterval(function () {
            pollLiveEstimate(table);
        }, LIVE_POLL_MS);
    }

    function enableEditMode(table) {
        isSpectator = false;
        hasUnsavedChanges = false;
        hideSpectatorBanner();
        stopLivePolling();
        setLockedUI(isLocked);
        startEditHeartbeat(table);
        setSaveStatus('hidden');
    }

    function isForceViewMode() {
        return /(?:^|[?&])view=1(?:&|$)/.test(window.location.search);
    }

    function tryRestoreEditSession(table) {
        if (!estimateId || !isSpectator || isLocked) {
            if (isSpectator && estimateId && !isLocked) {
                startLivePolling(table);
            }

            return;
        }

        if (isForceViewMode()) {
            startLivePolling(table);
            return;
        }

        var storedToken = getStoredEditToken(estimateId) || editToken;

        if (!storedToken) {
            startLivePolling(table);
            return;
        }

        fetch(getAppPath('api/acquire_edit.php'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                id: estimateId,
                edit_token: storedToken
            })
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    var result = null;

                    try {
                        result = text ? JSON.parse(text) : null;
                    } catch (err) {
                        result = null;
                    }

                    return { ok: response.ok, status: response.status, result: result };
                });
            })
            .then(function (payload) {
                if (payload.ok && payload.result && payload.result.success) {
                    editToken = payload.result.edit_token || storedToken;
                    storeEditToken(estimateId, editToken);
                    enableEditMode(table);
                    return;
                }

                if (payload.status === 423) {
                    clearStoredEditToken(estimateId);
                }

                startLivePolling(table);
            })
            .catch(function () {
                startLivePolling(table);
            });
    }

    function startEditingEstimate(table) {
        if (!estimateId || isLocked) {
            return;
        }

        fetch(getAppPath('api/acquire_edit.php'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                id: estimateId,
                edit_token: ''
            })
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    var result = null;

                    try {
                        result = text ? JSON.parse(text) : null;
                    } catch (err) {
                        result = null;
                    }

                    return { ok: response.ok, status: response.status, result: result };
                });
            })
            .then(function (payload) {
                if (!payload.ok || !payload.result || !payload.result.success) {
                    var message = payload.result && payload.result.error
                        ? payload.result.error
                        : 'Could not start editing. Someone else may be editing this estimate.';

                    showLiveStatus(message);
                    return;
                }

                editToken = payload.result.edit_token || '';
                storeEditToken(estimateId, editToken);
                enableEditMode(table);
            })
            .catch(function () {
                showLiveStatus('Could not start editing. Please try again.');
            });
    }

    function getAppPath(path) {
        var base = String(estimateAppBase || '').replace(/\/+$/, '');
        var cleanPath = String(path || '').replace(/^\/+/, '');

        if (!base) {
            return '/' + cleanPath;
        }

        return base + '/' + cleanPath;
    }

    function getEstimatePageUrl(id) {
        return id
            ? getAppPath('index.php?id=' + encodeURIComponent(id))
            : getAppPath('index.php');
    }

    function getSaveStatusEl() {
        if (!saveStatusEl) {
            saveStatusEl = document.querySelector('.estimateSaveStatus');
        }

        return saveStatusEl;
    }

    function setSaveStatus(state, message) {
        var el = getSaveStatusEl();

        if (!el) {
            return;
        }

        if (saveStatusTimer) {
            clearTimeout(saveStatusTimer);
            saveStatusTimer = null;
        }

        el.classList.remove('is-saving', 'is-saved', 'is-error', 'is-hidden');

        if (state === 'hidden' || state === 'idle') {
            el.classList.add('is-hidden');
            el.textContent = '';
            return;
        }

        if (state === 'saving') {
            el.classList.add('is-saving');
            el.textContent = message || 'Saving...';
            return;
        }

        if (state === 'saved') {
            el.classList.add('is-saved');
            el.textContent = message || 'Saved';
            saveStatusTimer = setTimeout(function () {
                setSaveStatus('hidden');
            }, 2500);
            return;
        }

        if (state === 'error') {
            el.classList.add('is-error');
            el.textContent = message || 'Save failed. Your changes may not be saved.';
        }
    }

    function getSaveErrorMessage(status, result) {
        if (status === 504) {
            return 'Save failed — server timeout (504). Please try again.';
        }

        if (status >= 500) {
            return 'Save failed — server error (' + status + '). Please try again.';
        }

        if (result && result.error) {
            return 'Save failed — ' + result.error;
        }

        return 'Save failed. Your changes may not be saved.';
    }

    function updateEstimateUrl(id) {
        history.replaceState(null, '', getEstimatePageUrl(id));
    }

    function handleSaveResponse(result) {
        if (!result || !result.success) {
            return;
        }

        if (result.deleted) {
            clearStoredEditToken(estimateId);
            estimateId = '';
            editToken = '';
            updateEstimateUrl('');
            return;
        }

        if (result.skipped) {
            return;
        }

        if (result.id) {
            estimateId = result.id;
            updateEstimateUrl(estimateId);
        }

        if (result.edit_token) {
            editToken = result.edit_token;
            storeEditToken(estimateId, editToken);
            startEditHeartbeat(document.querySelector('.frmTable table'));
        }

        if (result.updated_at) {
            expectedUpdatedAt = result.updated_at;
        }
    }

    function saveEstimate(table, isConflictRetry) {
        if (isRestoring || isReadOnly()) {
            return;
        }

        if (!hasUnsavedChanges && estimateId) {
            setSaveStatus('hidden');
            return;
        }

        if (saveInFlight) {
            saveQueuedTable = table;
            return;
        }

        var state = collectState(table);
        var hasItems = hasSavableItems(state);

        if (!hasItems && !estimateId) {
            setSaveStatus('hidden');
            return;
        }

        var grandTotalEl = table.querySelector('.estimate-grand-total');
        var grandTotal = grandTotalEl
            ? parseAmount(grandTotalEl.textContent)
            : calculateEstimateTotals(table).grandTotal;
        var requestId = ++saveRequestId;

        saveInFlight = true;
        setSaveStatus('saving', 'Saving...');

        var payload = {
            id: estimateId,
            data: state,
            company_name: state.header.companyName,
            project_type: state.header.projectType,
            edit_token: editToken,
            expected_updated_at: expectedUpdatedAt
        };

        if (!isManager) {
            payload.grand_total = grandTotal;
        }

        fetch(getAppPath('api/save_estimate.php'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    var result = null;

                    try {
                        result = text ? JSON.parse(text) : null;
                    } catch (err) {
                        result = null;
                    }

                    return {
                        ok: response.ok,
                        status: response.status,
                        result: result
                    };
                });
            })
            .then(function (payload) {
                if (requestId !== saveRequestId) {
                    return;
                }

                if (!payload.ok || !payload.result || !payload.result.success) {
                    if (payload.status === 409) {
                        hasUnsavedChanges = true;

                        if (!isConflictRetry && payload.result.updated_at) {
                            expectedUpdatedAt = payload.result.updated_at;
                            saveInFlight = false;
                            saveEstimate(table, true);
                            return;
                        }

                        if (isSpectator) {
                            pollLiveEstimate(table);
                        }
                    }

                    saveInFlight = false;
                    flushQueuedSave();
                    setSaveStatus('error', getSaveErrorMessage(payload.status, payload.result));
                    return;
                }

                handleSaveResponse(payload.result);

                if (payload.result.skipped) {
                    hasUnsavedChanges = false;
                    saveInFlight = false;
                    flushQueuedSave();
                    setSaveStatus('hidden');
                    return;
                }

                hasUnsavedChanges = false;
                saveInFlight = false;
                flushQueuedSave();
                setSaveStatus('saved', 'Saved');
            })
            .catch(function () {
                if (requestId !== saveRequestId) {
                    return;
                }

                saveInFlight = false;
                flushQueuedSave();
                setSaveStatus('error', 'Save failed — network error. Please check your connection.');
            });
    }

    function flushQueuedSave() {
        if (!saveQueuedTable) {
            return;
        }

        var table = saveQueuedTable;
        saveQueuedTable = null;

        if (hasUnsavedChanges) {
            saveEstimate(table);
        }
    }

    function scheduleSave(table) {
        if (isRestoring || isReadOnly()) {
            return;
        }

        hasUnsavedChanges = true;

        if (saveTimer) {
            clearTimeout(saveTimer);
        }

        setSaveStatus('saving', 'Saving...');

        saveTimer = setTimeout(function () {
            saveEstimate(table);
        }, 500);
    }

    function getActiveItemRate(item) {
        if (isManager || !item) {
            return '';
        }

        if (rateMode === 'd2c') {
            return item.d2c_rate || '';
        }

        return item.b2b_rate || '';
    }

    function updateRateToggleUI() {
        document.querySelectorAll('.rateToggleBtn').forEach(function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-rate-mode') === rateMode);
        });
    }

    function refreshSelectedItemRates(table) {
        if (isManager || !table) {
            return;
        }

        table.querySelectorAll('tr.item-row').forEach(function (row) {
            if (!row._selectedItem) {
                return;
            }

            var rateInput = row.querySelector('.itemRateInput');

            if (rateInput) {
                rateInput.value = getActiveItemRate(row._selectedItem);
            }

            calculateRowAmount(row);
        });

        updateFooterTotals(table);
    }

    function setRateMode(mode, table) {
        rateMode = mode === 'd2c' ? 'd2c' : 'b2b';
        updateRateToggleUI();
        refreshSelectedItemRates(table);
        scheduleSave(table);
    }

    function recalculateFromRow(row, table) {
        calculateRowAmount(row);
        updateFooterTotals(table);
    }

    var suggestTimer = null;
    var activeSuggestInput = null;
    var suggestRequestId = 0;

    function closeAllSuggestLists() {
        document.querySelectorAll('.itemSuggestList.is-open').forEach(function (list) {
            list.classList.remove('is-open');
            list.innerHTML = '';
        });
        activeSuggestInput = null;
    }

    function applyItemSelection(row, item, table) {
        var fields = getRowFields(row);

        if (!fields.name) {
            return;
        }

        fields.name.value = (item.item_name || '').toUpperCase();
        resizeItemNameField(fields.name);

        if (!isManager && fields.rate) {
            fields.rate.value = getActiveItemRate(item);
        }

        row._selectedItem = isManager ? stripSelectedItem(item) : item;

        recalculateFromRow(row, table);
    }

    function renderSuggestList(listEl, items) {
        if (!items.length) {
            listEl.classList.remove('is-open');
            listEl.innerHTML = '';
            return;
        }

        var html = '';

        items.forEach(function (item) {
            html +=
                '<button type="button" class="itemSuggestOption" data-item-id="' + item.id + '" title="' + escapeAttr(item.item_name) + '">' +
                    escapeHtml(item.item_name) +
                '</button>';
        });

        listEl.innerHTML = html;
        listEl.classList.add('is-open');

        listEl.querySelectorAll('.itemSuggestOption').forEach(function (option, index) {
            option._itemData = items[index];
        });
    }

    function fetchItemSuggestions(input) {
        var query = input.value.trim();
        var wrap = input.closest('.itemNameWrap');
        var listEl = wrap ? wrap.querySelector('.itemSuggestList') : null;
        var requestId = ++suggestRequestId;

        if (!listEl) {
            return;
        }

        if (query === '') {
            listEl.classList.remove('is-open');
            listEl.innerHTML = '';
            return;
        }

        fetch('api/search_items.php?q=' + encodeURIComponent(query))
            .then(function (response) {
                return response.json();
            })
            .then(function (items) {
                if (requestId !== suggestRequestId || activeSuggestInput !== input) {
                    return;
                }

                if (!Array.isArray(items)) {
                    renderSuggestList(listEl, []);
                    return;
                }

                renderSuggestList(listEl, items);
            })
            .catch(function () {
                if (requestId === suggestRequestId) {
                    renderSuggestList(listEl, []);
                }
            });
    }

    function handleItemNameInput(input) {
        if (isReadOnly()) {
            return;
        }

        resizeItemNameField(input);

        var row = input.closest('tr.item-row');

        if (row) {
            row._selectedItem = null;
        }

        activeSuggestInput = input;

        if (suggestTimer) {
            clearTimeout(suggestTimer);
        }

        suggestTimer = setTimeout(function () {
            fetchItemSuggestions(input);
        }, 250);
    }

    function buildGroupHeaderHtml() {
        return '<div class="secHdrT1">' +
            '<span class="groupDragHandle no-print" draggable="true" title="Drag to reorder group" aria-label="Drag to reorder group"></span>' +
            buildCategorySelect() +
            '</div>';
    }

    function buildEstimateGroup() {
        var tbody = document.createElement('tbody');
        tbody.className = 'estimate-group';
        tbody.innerHTML =
            '<tr>' +
                '<th colspan="7">' + buildGroupHeaderHtml() + '</th>' +
                '<th class="manager-hidden"><div class="secHdrT2"><span class="tclr02 group-subtotal">0</span></div></th>' +
                '<th class="no-print"><span class="closeBtnGroup" title="Delete Group">Close</span></th>' +
            '</tr>' +
            buildItemRow() +
            '<tr class="add-row-tr no-print">' +
                '<td colspan="9">' +
                    '<div class="adRowBtnBx">' +
                        '<span class="addRowBtn">Add Row</span>' +
                    '</div>' +
                '</td>' +
            '</tr>';

        return tbody;
    }

    function getAddGroupRow(table) {
        return table.querySelector('tbody.add-group-row');
    }

    function addGroup(table) {
        var addGroupRow = getAddGroupRow(table);

        if (!addGroupRow) {
            return;
        }

        table.insertBefore(buildEstimateGroup(), addGroupRow);
        updateFooterTotals(table);
    }

    function addRow(groupTbody, table) {
        var addRowTr = groupTbody.querySelector('tr.add-row-tr');

        if (!addRowTr) {
            return;
        }

        addRowTr.insertAdjacentHTML('beforebegin', buildItemRow());
        updateFooterTotals(table);
    }

    function removeRow(row, table) {
        var groupTbody = row.closest('tbody.estimate-group');

        if (!groupTbody) {
            return;
        }

        var itemRows = groupTbody.querySelectorAll('tr.item-row');

        if (itemRows.length <= 1) {
            return;
        }

        row.remove();
        updateFooterTotals(table);
    }

    function removeGroup(groupTbody, table) {
        var groups = table.querySelectorAll('tbody.estimate-group');

        if (groups.length <= 1) {
            return;
        }

        groupTbody.remove();
        updateFooterTotals(table);
    }

    var draggedGroup = null;
    var draggedItemRow = null;

    function clearGroupDropIndicators(table) {
        table.querySelectorAll('tbody.estimate-group').forEach(function (group) {
            group.classList.remove('is-drop-before', 'is-drop-after');
        });

        var addGroupRow = getAddGroupRow(table);

        if (addGroupRow) {
            addGroupRow.classList.remove('is-drop-target');
        }
    }

    function finishGroupDrag(table) {
        if (draggedGroup) {
            draggedGroup.classList.remove('is-dragging');
            draggedGroup = null;
        }

        clearGroupDropIndicators(table);
    }

    function clearItemDropIndicators(group) {
        if (!group) {
            return;
        }

        group.querySelectorAll('tr.item-row').forEach(function (row) {
            row.classList.remove('is-drop-before', 'is-drop-after');
        });

        var addRowTr = group.querySelector('tr.add-row-tr');

        if (addRowTr) {
            addRowTr.classList.remove('is-drop-target');
        }
    }

    function finishItemDrag() {
        if (!draggedItemRow) {
            return;
        }

        var group = draggedItemRow.closest('tbody.estimate-group');

        draggedItemRow.classList.remove('is-dragging');
        clearItemDropIndicators(group);
        draggedItemRow = null;
    }

    function reorderItemRow(dragged, target, insertBefore, group) {
        var rows = Array.prototype.slice.call(group.querySelectorAll('tr.item-row'));
        var fromIndex = rows.indexOf(dragged);
        var toIndex = rows.indexOf(target);

        if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) {
            return false;
        }

        rows.splice(fromIndex, 1);
        toIndex = rows.indexOf(target);

        if (!insertBefore) {
            toIndex += 1;
        }

        rows.splice(toIndex, 0, dragged);

        var addRowTr = group.querySelector('tr.add-row-tr');

        rows.forEach(function (row) {
            group.insertBefore(row, addRowTr);
        });

        return true;
    }

    function moveItemRowToEnd(dragged, group) {
        var addRowTr = group.querySelector('tr.add-row-tr');

        if (!addRowTr || !dragged) {
            return false;
        }

        group.insertBefore(dragged, addRowTr);
        return true;
    }

    function reorderEstimateGroup(dragged, target, insertBefore, table) {
        var groups = Array.prototype.slice.call(table.querySelectorAll('tbody.estimate-group'));
        var fromIndex = groups.indexOf(dragged);
        var toIndex = groups.indexOf(target);

        if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) {
            return false;
        }

        groups.splice(fromIndex, 1);
        toIndex = groups.indexOf(target);

        if (!insertBefore) {
            toIndex += 1;
        }

        groups.splice(toIndex, 0, dragged);

        var addGroupRow = getAddGroupRow(table);

        groups.forEach(function (group) {
            table.insertBefore(group, addGroupRow);
        });

        return true;
    }

    function moveEstimateGroupToEnd(dragged, table) {
        var addGroupRow = getAddGroupRow(table);

        if (!addGroupRow || !dragged) {
            return false;
        }

        table.insertBefore(dragged, addGroupRow);
        return true;
    }

    function initGroupDragDrop(table) {
        table.addEventListener('dragstart', function (event) {
            if (isReadOnly()) {
                event.preventDefault();
                return;
            }

            var groupHandle = event.target.closest('.groupDragHandle');

            if (groupHandle) {
                draggedGroup = groupHandle.closest('tbody.estimate-group');

                if (!draggedGroup) {
                    return;
                }

                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', 'estimate-group');
                draggedGroup.classList.add('is-dragging');
                return;
            }

            var itemHandle = event.target.closest('.itemDragHandle');

            if (!itemHandle) {
                return;
            }

            draggedItemRow = itemHandle.closest('tr.item-row');

            if (!draggedItemRow) {
                return;
            }

            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', 'estimate-item-row');
            draggedItemRow.classList.add('is-dragging');
        });

        table.addEventListener('dragover', function (event) {
            if (isReadOnly()) {
                return;
            }

            if (draggedItemRow) {
                var sourceGroup = draggedItemRow.closest('tbody.estimate-group');
                var targetRow = event.target.closest('tr.item-row');
                var addRowTr = event.target.closest('tr.add-row-tr');
                var targetGroup = targetRow
                    ? targetRow.closest('tbody.estimate-group')
                    : (addRowTr ? addRowTr.closest('tbody.estimate-group') : null);

                if (!sourceGroup || !targetGroup || sourceGroup !== targetGroup) {
                    return;
                }

                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                clearItemDropIndicators(sourceGroup);

                if (addRowTr) {
                    addRowTr.classList.add('is-drop-target');
                    return;
                }

                if (!targetRow || targetRow === draggedItemRow) {
                    return;
                }

                var rowRect = targetRow.getBoundingClientRect();
                var insertBefore = event.clientY < rowRect.top + rowRect.height / 2;

                targetRow.classList.add(insertBefore ? 'is-drop-before' : 'is-drop-after');
                return;
            }

            if (!draggedGroup) {
                return;
            }

            var targetGroup = event.target.closest('tbody.estimate-group');
            var addGroupRow = event.target.closest('tbody.add-group-row');

            if (!targetGroup && !addGroupRow) {
                return;
            }

            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            clearGroupDropIndicators(table);

            if (addGroupRow) {
                addGroupRow.classList.add('is-drop-target');
                return;
            }

            if (targetGroup === draggedGroup) {
                return;
            }

            var rect = targetGroup.getBoundingClientRect();
            var insertBefore = event.clientY < rect.top + rect.height / 2;

            targetGroup.classList.add(insertBefore ? 'is-drop-before' : 'is-drop-after');
        });

        table.addEventListener('drop', function (event) {
            if (isReadOnly()) {
                return;
            }

            if (draggedItemRow) {
                event.preventDefault();

                var sourceGroup = draggedItemRow.closest('tbody.estimate-group');
                var targetRow = event.target.closest('tr.item-row');
                var addRowTr = event.target.closest('tr.add-row-tr');
                var moved = false;

                if (addRowTr && addRowTr.closest('tbody.estimate-group') === sourceGroup) {
                    moved = moveItemRowToEnd(draggedItemRow, sourceGroup);
                } else if (targetRow && targetRow !== draggedItemRow && targetRow.closest('tbody.estimate-group') === sourceGroup) {
                    var rowRect = targetRow.getBoundingClientRect();
                    var insertBefore = event.clientY < rowRect.top + rowRect.height / 2;
                    moved = reorderItemRow(draggedItemRow, targetRow, insertBefore, sourceGroup);
                }

                finishItemDrag();

                if (moved) {
                    scheduleSave(table);
                }

                return;
            }

            if (!draggedGroup) {
                return;
            }

            event.preventDefault();

            var targetGroup = event.target.closest('tbody.estimate-group');
            var addGroupRow = event.target.closest('tbody.add-group-row');
            var moved = false;

            if (addGroupRow) {
                moved = moveEstimateGroupToEnd(draggedGroup, table);
            } else if (targetGroup && targetGroup !== draggedGroup) {
                var rect = targetGroup.getBoundingClientRect();
                var insertBefore = event.clientY < rect.top + rect.height / 2;
                moved = reorderEstimateGroup(draggedGroup, targetGroup, insertBefore, table);
            }

            finishGroupDrag(table);

            if (moved) {
                scheduleSave(table);
            }
        });

        table.addEventListener('dragend', function () {
            finishGroupDrag(table);
            finishItemDrag();
        });
    }

    function sanitizeTitlePart(text) {
        return String(text || '')
            .trim()
            .replace(/[\\/:*?"<>|]/g, '')
            .replace(/\s+/g, ' ');
    }

    function getDocumentTitle() {
        var companyInput = document.querySelector('.companyNameInput');
        var companyName = companyInput ? sanitizeTitlePart(companyInput.value) : '';

        if (!companyName) {
            return DEFAULT_DOCUMENT_TITLE;
        }

        return DEFAULT_DOCUMENT_TITLE + ' ' + companyName;
    }

    function updateDocumentTitle() {
        document.title = getDocumentTitle();
    }

    function bindPrintTitle() {
        window.addEventListener('beforeprint', function () {
            updateDocumentTitle();
        });
    }

    function setLockedUI(locked) {
        isLocked = !!locked;
        document.body.classList.toggle('estimate-locked', isLocked);
        document.body.classList.toggle('estimate-spectator', isSpectator);

        var readOnly = isReadOnly();

        document.querySelectorAll('.txtfld:not(.itemAmtInput)').forEach(function (input) {
            input.readOnly = readOnly;
        });

        document.querySelectorAll('.categorySelect').forEach(function (select) {
            select.disabled = readOnly;
        });

        document.querySelectorAll('.rateToggleBtn').forEach(function (button) {
            button.disabled = readOnly;
        });

        document.querySelectorAll('.estimateStartEditBtn').forEach(function (button) {
            button.disabled = isLocked;
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.addEventListener('input', function (event) {
            if (event.target.matches('.txtfld')) {
                uppercaseInput(event.target);
            }

            if (event.target.matches('.itemNameInput, .itemDescriptionInput')) {
                resizeItemNameField(event.target);
            }

            if (event.target.matches('.companyNameInput')) {
                updateDocumentTitle();
            }

            if (event.target.matches('.projectTypeInput, .projectOwnerInput, .eventDateInput, .companyNameInput')) {
                var tableEl = document.querySelector('.frmTable table');

                if (tableEl) {
                    scheduleSave(tableEl);
                }
            }
        }, true);

        bindPrintTitle();
        updateDocumentTitle();

        var printBtn = document.querySelector('.estimatePrintBtn');

        if (printBtn) {
            printBtn.addEventListener('click', function () {
                updateDocumentTitle();
                resizeAllItemNameFields(document);
                window.print();
            });
        }

        var table = document.querySelector('.frmTable table');

        if (!table) {
            return;
        }

        if (!isManager) {
            document.querySelectorAll('.rateToggleBtn').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (isReadOnly()) {
                        return;
                    }

                    setRateMode(button.getAttribute('data-rate-mode'), table);
                });
            });

            updateRateToggleUI();
        }

        applyState(estimateData, table);
        resizeAllItemNameFields(table);
        setLockedUI(isLocked);

        table.addEventListener('change', function (event) {
            if (isReadOnly()) {
                return;
            }

            if (event.target.matches('.categorySelect')) {
                updateFooterTotals(table);
            }
        });

        table.addEventListener('input', function (event) {
            if (isReadOnly()) {
                return;
            }

            if (event.target.matches('.discountInput')) {
                updateFooterTotals(table);
                return;
            }

            if (event.target.matches('.itemNameInput')) {
                handleItemNameInput(event.target);
            }

            var row = event.target.closest('tr.item-row');

            if (row && event.target.matches('.itemSqftInput, .itemQtyInput, .itemRateInput')) {
                recalculateFromRow(row, table);
                return;
            }

            if (row && event.target.matches('.txtfld')) {
                updateFooterTotals(table);
            }
        });

        table.addEventListener('focusout', function (event) {
            if (isReadOnly()) {
                return;
            }

            if (!event.target.matches('.discountInput')) {
                return;
            }

            if (parseAmount(event.target.value) === 0) {
                discountEditing = false;
                updateFooterTotals(table);
            }
        });

        table.addEventListener('focusin', function (event) {
            if (isReadOnly()) {
                return;
            }

            if (event.target.matches('.discountInput')) {
                discountEditing = true;
                updateDiscountRowVisibility(table, parseAmount(event.target.value));
                return;
            }

            if (event.target.matches('.itemNameInput') && event.target.value.trim() !== '') {
                activeSuggestInput = event.target;
                fetchItemSuggestions(event.target);
            }
        });

        table.addEventListener('mousedown', function (event) {
            if (isReadOnly()) {
                return;
            }

            var option = event.target.closest('.itemSuggestOption');

            if (!option) {
                return;
            }

            event.preventDefault();

            var wrap = option.closest('.itemNameWrap');
            var row = option.closest('tr.item-row');
            var input = wrap ? wrap.querySelector('.itemNameInput') : null;
            var listEl = wrap ? wrap.querySelector('.itemSuggestList') : null;

            if (row && option._itemData) {
                applyItemSelection(row, option._itemData, table);
            }

            if (listEl) {
                listEl.classList.remove('is-open');
                listEl.innerHTML = '';
            }

            if (input) {
                input.focus();
            }

            activeSuggestInput = null;
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('.itemNameWrap')) {
                closeAllSuggestLists();
            }
        });

        table.addEventListener('click', function (event) {
            if (isReadOnly()) {
                return;
            }

            var addDiscountBtn = event.target.closest('.addDiscountBtn');

            if (addDiscountBtn) {
                showDiscountRow(table);
                return;
            }

            var addGroupBtn = event.target.closest('.addGrpBtn');

            if (addGroupBtn) {
                addGroup(table);
                return;
            }

            var addRowBtn = event.target.closest('.addRowBtn');

            if (addRowBtn) {
                var groupForRow = addRowBtn.closest('tbody.estimate-group');
                addRow(groupForRow, table);
                return;
            }

            var closeRowBtn = event.target.closest('.closeBtn');

            if (closeRowBtn) {
                removeRow(closeRowBtn.closest('tr.item-row'), table);
                return;
            }

            var closeGroupBtn = event.target.closest('.closeBtnGroup');

            if (closeGroupBtn) {
                removeGroup(closeGroupBtn.closest('tbody.estimate-group'), table);
            }
        });

        updateFooterTotals(table, false);
        initGroupDragDrop(table);
        tryRestoreEditSession(table);

        if (!isSpectator && estimateId && editToken) {
            storeEditToken(estimateId, editToken);
            startEditHeartbeat(table);
        }

        var startEditBtn = document.querySelector('.estimateStartEditBtn');

        if (startEditBtn) {
            startEditBtn.addEventListener('click', function () {
                startEditingEstimate(table);
            });
        }

        window.addEventListener('beforeunload', function () {
            if (saveTimer) {
                clearTimeout(saveTimer);
                saveTimer = null;
            }

            releaseEditLock();
        });
    });
})();
