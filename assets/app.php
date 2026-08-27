<?php

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/javascript; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, max-age=0, must-revalidate');

// no-cache makes the browser revalidate on every load, but with no validator to
// revalidate against, each of those round trips returned the whole body. Tagging
// with mtime+size lets the same round trip answer 304. A redeploy changes the
// mtime, so freshness is unchanged.
$assetTag = '"' . dechex((int) filemtime(__FILE__)) . '-' . dechex((int) filesize(__FILE__)) . '"';
header('ETag: ' . $assetTag);
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $assetTag) {
    http_response_code(304);
    exit;
}
?>
(function ($) {
    'use strict';

    var $body = $('body[data-realtime-endpoint]');
    if ($body.length === 0) {
        return;
    }

    var endpoint = String($body.data('realtime-endpoint') || 'api/transactions.php');
    var senderOptionsEndpoint = String($body.data('sender-options-endpoint') || 'api/sender-options.php');
    var pollMs = parseInt(String($body.data('poll-ms') || '2500'), 10);
    var hiddenPollMs = parseInt(String($body.data('hidden-poll-ms') || '10000'), 10);
    var maxRows = parseInt(String($body.data('max-rows') || '200'), 10);
    var cursorId = parseInt(String($body.data('last-id') || '0'), 10);
    var ocrLanguage = String($body.data('ocr-language') || 'eng');
    var ocrWorkerPath = String($body.data('ocr-worker') || '');
    var ocrCorePath = String($body.data('ocr-core') || '');
    var ocrLangPath = String($body.data('ocr-lang') || '');
    var requestBusy = false;
    var syncSequence = 0;
    var uploadBusy = false;
    var timerId = null;
    var channel = null;
    var ocrWorkerPromise = null;
    var ocrProgressHandler = null;
    var pendingAliasSelection = null;
    var PAGE_SIZE = 12;
    var weeklyPage = 1;
    var armedDeleteId = null;

    if (!Number.isFinite(pollMs) || pollMs < 1000) { pollMs = 2500; }
    if (!Number.isFinite(hiddenPollMs) || hiddenPollMs < pollMs) { hiddenPollMs = 10000; }
    if (!Number.isFinite(maxRows) || maxRows < 1 || maxRows > 500) { maxRows = 200; }
    if (!Number.isFinite(cursorId) || cursorId < 0) { cursorId = 0; }

    function currentDelay() {
        return document.hidden ? hiddenPollMs : pollMs;
    }

    function scheduleNext(delay) {
        if (timerId !== null) { window.clearTimeout(timerId); }
        timerId = window.setTimeout(function () { syncTransactions(true); }, typeof delay === 'number' ? delay : currentDelay());
    }

    function setLiveStatus(text, healthy) {
        var $status = $('#live-status');
        $status.text(text);
        $status.toggleClass('live-ok', healthy === true);
        $status.toggleClass('live-warn', healthy !== true);
    }

    function showToast(message, kind) {
        var $toast = $('<div>', {
            'class': 'toast ' + (kind === 'error' ? 'toast-error' : 'toast-ok'),
            'role': kind === 'error' ? 'alert' : 'status'
        }).text(message);
        $('#toast-stack').append($toast);
        window.requestAnimationFrame(function () { $toast.addClass('toast-show'); });
        window.setTimeout(function () {
            $toast.removeClass('toast-show');
            window.setTimeout(function () { $toast.remove(); }, 220);
        }, 1600);
    }

    function setOcrProgress(label, percent, visible) {
        var safePercent = Math.max(0, Math.min(100, Math.round(Number(percent) || 0)));
        var $progress = $('#ocr-progress');
        if ($progress.length === 0) { return; }
        if (visible === false) {
            $progress.attr('hidden', true);
            return;
        }
        $progress.removeAttr('hidden');
        $('#ocr-progress-label').text(label || 'Processing OCR…');
        $('#ocr-progress-value').text(String(safePercent) + '%');
        $('#ocr-progress-bar').val(safePercent);
    }

    function readableOcrStatus(status) {
        var value = String(status || '').replace(/_/g, ' ').trim();
        if (value === '') { return 'Processing OCR…'; }
        return value.charAt(0).toUpperCase() + value.slice(1) + '…';
    }

    function getOcrWorker() {
        if (!window.Tesseract || typeof window.Tesseract.createWorker !== 'function') {
            return Promise.reject(new Error('Browser OCR runtime could not be loaded. Check the internet connection and retry.'));
        }
        if (ocrWorkerPath === '' || ocrCorePath === '' || ocrLangPath === '') {
            return Promise.reject(new Error('Browser OCR configuration is incomplete.'));
        }
        if (ocrWorkerPromise !== null) { return ocrWorkerPromise; }

        ocrWorkerPromise = window.Tesseract.createWorker(ocrLanguage, 1, {
            workerPath: ocrWorkerPath,
            corePath: ocrCorePath,
            langPath: ocrLangPath,
            logger: function (message) {
                if (typeof ocrProgressHandler !== 'function' || !message) { return; }
                var progress = typeof message.progress === 'number' ? message.progress * 100 : 0;
                ocrProgressHandler(readableOcrStatus(message.status), progress);
            }
        }).then(function (worker) {
            return worker.setParameters({preserve_interword_spaces: '1', tessedit_pageseg_mode: '6'}).then(function () { return worker; });
        }).catch(function (error) {
            ocrWorkerPromise = null;
            throw error;
        });
        return ocrWorkerPromise;
    }

    function recognizeReceipt(file, progressHandler) {
        ocrProgressHandler = progressHandler;
        return getOcrWorker().then(function (worker) { return worker.recognize(file); }).then(function (result) {
            var text = result && result.data && typeof result.data.text === 'string' ? result.data.text.trim() : '';
            if (text.length < 10 || text.length > 100000) {
                throw new Error('No usable receipt text was detected. Try a sharper screenshot.');
            }
            return text;
        }).finally(function () { ocrProgressHandler = null; });
    }

    var MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    function weekStartFor(dateStr) {
        var datePart = String(dateStr || '').slice(0, 10);
        var d = new Date(datePart + 'T00:00:00');
        if (isNaN(d.getTime())) { return null; }
        var isoDay = d.getDay() === 0 ? 7 : d.getDay();
        d.setDate(d.getDate() - (isoDay - 1));
        return d.toISOString().slice(0, 10);
    }

    function weekLabelFor(weekStartStr) {
        var start = new Date(weekStartStr + 'T00:00:00');
        var end = new Date(start.getTime());
        end.setDate(end.getDate() + 6);
        return start.getDate() + ' ' + MONTH_NAMES[start.getMonth()] + '–'
            + end.getDate() + ' ' + MONTH_NAMES[end.getMonth()] + ' ' + end.getFullYear();
    }

    function canDeleteTransactions() {
        return $('#transactions-weeks').attr('data-can-delete') === '1';
    }

    function buildRow(row) {
        var id = parseInt(String(row.id || '0'), 10);
        if (!Number.isFinite(id) || id <= 0) { return null; }
        var $tr = $('<tr>').attr('data-id', String(id));
        $('<td>').text(String(row.subid || row.alias || '—')).appendTo($tr);
        $('<td>').text(String(row.team || '')).appendTo($tr);
        $('<td>', {'class': 'right final'}).text(String(row.adjusted_amount || 'IDR 0')).appendTo($tr);
        $('<td>', {'class': 'receipt-date'}).text(String(row.receipt_date || '')).appendTo($tr);
        if (canDeleteTransactions()) {
            $('<td>', {'class': 'right'}).append(
                $('<button>', {type: 'button', 'class': 'tx-delete', 'data-id': String(id), text: 'Delete'})
            ).appendTo($tr);
        }
        return $tr;
    }

    function findOrCreateWeekGroup(weekStartStr) {
        var $weeks = $('#transactions-weeks');
        var $existing = $weeks.find('.week-group[data-week-start="' + weekStartStr + '"]');
        if ($existing.length > 0) { return $existing.find('tbody'); }

        $('#transactions-empty').remove();

        var colCount = canDeleteTransactions() ? 5 : 4;
        var $details = $('<details>', {'class': 'week-group', 'data-week-start': weekStartStr});
        var $summary = $('<summary>', {'class': 'week-summary'}).appendTo($details);
        $('<span>').text(weekLabelFor(weekStartStr)).appendTo($summary);
        $('<span>', {'class': 'count', 'data-week-count': true}).text('0 rows').appendTo($summary);
        var $table = $('<table>', {'aria-label': 'Final transaction output'}).appendTo(
            $('<div>', {'class': 'table-wrap'}).appendTo($details)
        );
        var $headRow = $('<tr>').appendTo($('<thead>').appendTo($table));
        $('<th>').text('SUBID').appendTo($headRow);
        $('<th>').text('Team').appendTo($headRow);
        $('<th>', {'class': 'right'}).text('Final').appendTo($headRow);
        $('<th>', {'class': 'receipt-date'}).text('Receipt date').appendTo($headRow);
        if (colCount === 5) { $('<th>', {'class': 'right'}).text('Delete').appendTo($headRow); }
        var $tbody = $('<tbody>').appendTo($table);

        var inserted = false;
        $weeks.find('.week-group').each(function () {
            if (String($(this).attr('data-week-start')) < weekStartStr) {
                $details.insertBefore($(this));
                inserted = true;
                return false;
            }
            return true;
        });
        if (!inserted) { $weeks.append($details); }
        return $tbody;
    }

    function updateWeekCount($tbody) {
        var $details = $tbody.closest('.week-group');
        $details.find('[data-week-count]').text(String($tbody.find('tr[data-id]').length) + ' rows');
    }

    function updateRowCount() {
        $('#row-count').text(String($('#transactions-weeks tr[data-id]').length) + ' rows');
    }

    function trimRows() {
        var $rows = $('#transactions-weeks tr[data-id]');
        if ($rows.length <= maxRows) { return; }
        var excess = $rows.slice(maxRows);
        excess.each(function () {
            var $tbody = $(this).closest('tbody');
            $(this).remove();
            updateWeekCount($tbody);
            if ($tbody.find('tr[data-id]').length === 0) { $tbody.closest('.week-group').remove(); }
        });
        if ($('#transactions-weeks .week-group').length === 0) {
            $('#transactions-weeks').append($('<div>', {'class': 'empty', id: 'transactions-empty'}).text('No transactions found.'));
        }
    }

    function insertTransaction(row) {
        var id = parseInt(String(row.id || '0'), 10);
        if (!Number.isFinite(id) || id <= 0 || $('#transactions-weeks tr[data-id="' + id + '"]').length > 0) { return false; }
        var $row = buildRow(row);
        if ($row === null) { return false; }
        var weekStart = weekStartFor(row.receipt_date) || weekStartFor(new Date().toISOString());
        var $tbody = findOrCreateWeekGroup(weekStart);

        var inserted = false;
        $tbody.find('tr[data-id]').each(function () {
            var existingId = parseInt(String($(this).attr('data-id') || '0'), 10);
            if (Number.isFinite(existingId) && id > existingId) {
                $row.insertBefore($(this));
                inserted = true;
                return false;
            }
            return true;
        });
        if (!inserted) { $tbody.append($row); }
        updateWeekCount($tbody);
        trimRows();
        updateRowCount();
        return true;
    }

    function resetDeleteButton(id) {
        $('#transactions-weeks .tx-delete[data-id="' + id + '"]').prop('disabled', false).text('Delete');
    }

    function deleteTransaction(id) {
        $.ajax({
            url: endpoint,
            method: 'POST',
            data: {csrf_token: csrfToken(), action: 'delete', id: String(id)},
            dataType: 'json',
            timeout: 15000,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        }).done(function (response) {
            if (!response || response.ok !== true) {
                resetDeleteButton(id);
                showToast('Delete failed', 'error');
                return;
            }
            var $row = $('#transactions-weeks tr[data-id="' + id + '"]');
            var $tbody = $row.closest('tbody');
            $row.remove();
            if ($tbody.length > 0) {
                updateWeekCount($tbody);
                if ($tbody.find('tr[data-id]').length === 0) { $tbody.closest('.week-group').remove(); }
            }
            if ($('#transactions-weeks .week-group').length === 0) {
                $('#transactions-weeks').append($('<div>', {'class': 'empty', id: 'transactions-empty'}).text('No transactions found.'));
            }
            updateRowCount();
            if (response.summary) { updateSummary(response.summary); }
            if (response.weekly) { updateWeekly(response.weekly); }
            showToast('Transaction deleted', 'ok');
        }).fail(function (xhr) {
            if (handleUnauthorized(xhr)) { return; }
            resetDeleteButton(id);
            var message = xhr.responseJSON && typeof xhr.responseJSON.message === 'string' && xhr.responseJSON.message !== ''
                ? xhr.responseJSON.message
                : 'Unable to delete transaction.';
            showToast(message, 'error');
        });
    }

    function csrfToken() { return String($('input[name="csrf_token"]').first().val() || ''); }

    function updateSummary(summary) {
        if (!summary || typeof summary !== 'object') { return; }
        ['week', 'month', 'year', 'all'].forEach(function (period) {
            var item = summary[period];
            if (!item || typeof item !== 'object') { return; }
            var $card = $('[data-summary-period="' + period + '"]');
            if ($card.length === 0) { return; }
            $card.find('[data-summary-label]').contents().first()[0].textContent = String(item.label || '') + ' · ';
            $card.find('[data-summary-count]').text(String(parseInt(String(item.count || 0), 10) || 0));
            $card.find('[data-summary-total]').text(String(item.total || 'IDR 0'));
            if (item.teams && typeof item.teams === 'object') {
                $card.find('[data-summary-team="XCTD"]').text(String(item.teams.XCTD || 'IDR 0'));
                $card.find('[data-summary-team="MNX"]').text(String(item.teams.MNX || 'IDR 0'));
            }
        });
    }

    function updateWeekly(weekly) {
        if (!weekly || typeof weekly !== 'object') { return; }
        $('#weekly-label').text(String(weekly.label || ''));
        $('#weekly-paid').text(String(parseInt(String(weekly.paid || 0), 10) || 0));
        $('#weekly-pending').text(String(parseInt(String(weekly.pending || 0), 10) || 0));
        $('#weekly-outstanding').text(String(parseInt(String(weekly.outstanding_weeks || 0), 10) || 0) + ' weeks');

        var rows = Array.isArray(weekly.rows) ? weekly.rows : [];
        var $body = $('#weekly-status-body');
        if ($body.length === 0) { return; }
        $body.empty();
        if (rows.length === 0) {
            $('<tr>', {id: 'weekly-empty-row'}).append(
                $('<td>', {'class': 'empty', colspan: 5}).text('No registered sender obligations.')
            ).appendTo($body);
            refreshWeeklyPage();
            return;
        }

        rows.forEach(function (row) {
            var senderId = parseInt(String(row.sender_id || 0), 10) || 0;
            var status = String(row.current_status || 'pending');
            if (['paid', 'pending', 'unpaid', 'disabled'].indexOf(status) === -1) { status = 'pending'; }
            var carry = parseInt(String(row.outstanding_weeks || 0), 10) || 0;
            var label = status.charAt(0).toUpperCase() + status.slice(1);
            var $tr = $('<tr>').attr('data-weekly-sender-id', String(senderId));
            $('<td>').text(String(row.subid || row.alias || '')).appendTo($tr);
            $('<td>').text(String(row.location || '')).appendTo($tr);
            $('<td>').text(String(row.team || '')).appendTo($tr);
            $('<td>').append($('<span>', {'class': 'status-pill ' + status}).text(label)).appendTo($tr);
            $('<td>', {'class': 'right ' + (carry > 0 ? 'carry' : 'carry zero')}).text(carry > 0 ? String(carry) + ' wk' : '—').appendTo($tr);
            $tr.appendTo($body);
        });
        refreshWeeklyPage();
    }

    function applyPagination($rows, page, pagerId, infoId) {
        var total = $rows.length;
        var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
        if (page > pages) { page = pages; }
        if (page < 1) { page = 1; }
        var start = (page - 1) * PAGE_SIZE;
        $rows.each(function (index) {
            $(this).toggleClass('page-hidden', index < start || index >= start + PAGE_SIZE);
        });
        var $pager = $('#' + pagerId);
        if ($pager.length === 0) { return page; }
        if (total <= PAGE_SIZE) {
            $pager.attr('hidden', true);
        } else {
            $pager.removeAttr('hidden');
            $pager.find('[data-dir="-1"]').prop('disabled', page <= 1);
            $pager.find('[data-dir="1"]').prop('disabled', page >= pages);
            $('#' + infoId).text('Page ' + page + ' / ' + pages);
        }
        return page;
    }

    function refreshWeeklyPage() { weeklyPage = applyPagination($('#weekly-status-body tr[data-weekly-sender-id]'), weeklyPage, 'weekly-pager', 'weekly-pager-info'); }

    function handleUnauthorized(xhr) {
        if (xhr.status === 401 || xhr.status === 403) {
            window.location.assign('login.php');
            return true;
        }
        return false;
    }

    function syncTransactions(silent) {
        if (requestBusy) { scheduleNext(); return; }
        requestBusy = true;
        syncSequence += 1;
        $.ajax({
            url: endpoint,
            method: 'GET',
            data: {after_id: cursorId, include_summary: syncSequence % 24 === 0 ? '1' : '0'},
            dataType: 'json',
            cache: false,
            timeout: 8000,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        }).done(function (response) {
            var inserted = 0;
            var rows = response && response.ok === true && Array.isArray(response.transactions) ? response.transactions : [];
            var responseCursor = cursorId;
            rows.forEach(function (row) {
                var rowId = parseInt(String(row.id || '0'), 10);
                if (Number.isFinite(rowId) && rowId > responseCursor) { responseCursor = rowId; }
                if (insertTransaction(row)) { inserted += 1; }
            });
            cursorId = responseCursor;
            if (response && response.summary) { updateSummary(response.summary); }
            if (response && response.weekly) { updateWeekly(response.weekly); }
            setLiveStatus('Live', true);
            if (inserted > 0 && (silent !== true || document.visibilityState === 'visible')) {
                showToast(inserted === 1 ? 'Live updated' : 'Live +' + inserted, 'ok');
            }
        }).fail(function (xhr) {
            if (!handleUnauthorized(xhr)) { setLiveStatus('Reconnecting…', false); }
        }).always(function () {
            requestBusy = false;
            scheduleNext();
        });
    }

    function hideAliasChoice() {
        $('#alias-choice').attr('hidden', true);
        $('#alias-choice-sender').text('');
        $('#alias-options').empty();
    }

    function resetPendingUpload(resetForm) {
        var pending = pendingAliasSelection;
        pendingAliasSelection = null;
        hideAliasChoice();
        $('#selected-sender-id').val('');
        if (resetForm === true && pending && pending.form) {
            pending.form.reset();
            $('#ocr-text').val('');
        }
        uploadBusy = false;
        if (pending && pending.$button) {
            pending.$button.prop('disabled', false).text(pending.originalText);
        } else {
            $('#upload-submit').prop('disabled', false).text('Extract & Save');
        }
        setOcrProgress('', 0, false);
    }

    function showAliasChoice(response, form, ocrText, $button, originalText) {
        var options = response && Array.isArray(response.options) ? response.options : [];
        if (options.length < 1) {
            throw new Error('Sender SUBID selection is unavailable.');
        }

        pendingAliasSelection = {
            form: form,
            ocrText: ocrText,
            $button: $button,
            originalText: originalText
        };
        $('#selected-sender-id').val('');
        $('#alias-choice-sender').text(String(response.sender_name || ''));
        var $options = $('#alias-options').empty();
        options.forEach(function (option) {
            var id = parseInt(String(option.id || '0'), 10);
            if (!Number.isFinite(id) || id <= 0) { return; }
            var subid = String(option.subid || option.alias || '');
            var team = String(option.team || '');
            var location = String(option.location || '');
            var carry = parseInt(String(option.carry_weeks || 0), 10) || 0;
            var meta = location !== '' ? team + ' · ' + location : team;
            if (carry > 0) { meta += ' · Carry ' + String(carry) + ' wk'; }
            $('<button>', {
                type: 'button',
                'class': 'alias-option',
                'data-sender-id': String(id)
            }).text(subid).append($('<small>').text(meta)).appendTo($options);
        });
        if ($options.children().length < 1) {
            pendingAliasSelection = null;
            throw new Error('Sender SUBID selection is unavailable.');
        }
        $('#alias-choice').removeAttr('hidden');
        $button.text('Choose SUBID');
        setOcrProgress('Sender detected. Choose SUBID to continue…', 100, true);
    }

    function resolveSenderBeforeUpload(form, ocrText, $button, originalText) {
        var csrfToken = String($(form).find('input[name="csrf_token"]').val() || '');
        setOcrProgress('Checking sender SUBIDs…', 100, true);
        $button.text('Checking sender…');
        $.ajax({
            url: senderOptionsEndpoint,
            method: 'POST',
            data: {csrf_token: csrfToken, ocr_text: ocrText},
            dataType: 'json',
            timeout: 15000,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        }).done(function (response) {
            if (!response || response.ok !== true) {
                $('#ajax-message').removeAttr('hidden').addClass('error').removeClass('ok').text('Unable to resolve sender SUBIDs.');
                showToast('SUBID lookup failed', 'error');
                uploadBusy = false;
                $button.prop('disabled', false).text(originalText);
                setOcrProgress('', 0, false);
                return;
            }
            if (response.requires_selection === true) {
                try {
                    showAliasChoice(response, form, ocrText, $button, originalText);
                } catch (error) {
                    var message = error && typeof error.message === 'string' ? error.message : 'Unable to resolve sender SUBIDs.';
                    $('#ajax-message').removeAttr('hidden').addClass('error').removeClass('ok').text(message);
                    showToast('Sender rejected', 'error');
                    resetPendingUpload(false);
                }
                return;
            }

            var selectedId = parseInt(String(response.selected_id || '0'), 10);
            if (!Number.isFinite(selectedId) || selectedId <= 0) {
                $('#ajax-message').removeAttr('hidden').addClass('error').removeClass('ok').text('Unable to resolve sender SUBID.');
                showToast('Sender rejected', 'error');
                uploadBusy = false;
                $button.prop('disabled', false).text(originalText);
                setOcrProgress('', 0, false);
                return;
            }
            $('#selected-sender-id').val(String(selectedId));
            $button.text('Saving…');
            submitRecognizedReceipt(form, ocrText, $button, originalText);
        }).fail(function (xhr) {
            if (handleUnauthorized(xhr)) { return; }
            var message = xhr.responseJSON && typeof xhr.responseJSON.message === 'string' && xhr.responseJSON.message !== ''
                ? xhr.responseJSON.message
                : 'Unable to resolve sender SUBIDs.';
            $('#ajax-message').removeAttr('hidden').addClass('error').removeClass('ok').text(message);
            showToast(message.indexOf('not registered') !== -1 ? 'Sender rejected' : 'SUBID lookup failed', 'error');
            uploadBusy = false;
            $button.prop('disabled', false).text(originalText);
            setOcrProgress('', 0, false);
        });
    }

    function submitRecognizedReceipt(form, ocrText, $button, originalText) {
        var $form = $(form);
        var formData = new FormData(form);
        formData.set('ocr_text', ocrText);
        setOcrProgress('Validating sender & saving…', 100, true);
        $.ajax({
            url: $form.attr('action') || 'index.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            timeout: 30000,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        }).done(function (response) {
            if (!response || response.ok !== true) {
                showToast('Save failed', 'error');
                return;
            }
            if (response.duplicate === true) {
                if (response.summary) { updateSummary(response.summary); }
                if (response.weekly) { updateWeekly(response.weekly); }
                form.reset();
                $('#ocr-text').val('');
                $('#ajax-message').attr('hidden', true).removeClass('error ok').text('');
                showToast('Receipt already saved', 'ok');
                return;
            }
            if (!response.transaction) {
                showToast('Save failed', 'error');
                return;
            }
            insertTransaction(response.transaction);
            if (response.summary) { updateSummary(response.summary); }
            if (response.weekly) { updateWeekly(response.weekly); }
            form.reset();
            $('#ocr-text').val('');
            $('#ajax-message').attr('hidden', true).removeClass('error ok').text('');
            showToast('Extracted & saved', 'ok');
            if (channel !== null) {
                channel.postMessage({type: 'transaction-saved', id: response.transaction.id});
            }
        }).fail(function (xhr) {
            if (handleUnauthorized(xhr)) { return; }
            var message = xhr.responseJSON && typeof xhr.responseJSON.message === 'string' && xhr.responseJSON.message !== ''
                ? xhr.responseJSON.message
                : 'Unable to validate or save receipt.';
            $('#ajax-message').removeAttr('hidden').addClass('error').removeClass('ok').text(message);
            showToast(message.indexOf('not registered') !== -1 ? 'Sender rejected' : 'Save failed', 'error');
        }).always(function () {
            uploadBusy = false;
            $button.prop('disabled', false).text(originalText);
            window.setTimeout(function () { setOcrProgress('', 0, false); }, 450);
        });
    }

    function processUpload(form) {
        if (uploadBusy) { return; }
        var $button = $('#upload-submit');
        var originalText = $button.text();
        var input = form.elements.receipt;
        var file = input && input.files && input.files.length > 0 ? input.files[0] : null;
        if (!file) { showToast('Select receipt image', 'error'); return; }
        if (!/^image\/(jpeg|png|webp)$/i.test(String(file.type || ''))) { showToast('Unsupported image', 'error'); return; }
        if (file.size <= 0 || file.size > (8 * 1024 * 1024)) { showToast('Image must be under 8 MB', 'error'); return; }

        uploadBusy = true;
        pendingAliasSelection = null;
        hideAliasChoice();
        $('#selected-sender-id').val('');
        $button.prop('disabled', true).text('OCR 0%');
        $('#ajax-message').attr('hidden', true).removeClass('error ok').text('');
        setOcrProgress('Loading browser OCR…', 0, true);

        recognizeReceipt(file, function (label, percent) {
            var displayPercent = Math.max(0, Math.min(99, Math.round(percent)));
            setOcrProgress(label, displayPercent, true);
            $button.text('OCR ' + String(displayPercent) + '%');
        }).then(function (ocrText) {
            $('#ocr-text').val(ocrText);
            $('#selected-sender-id').val('');
            resolveSenderBeforeUpload(form, ocrText, $button, originalText);
        }).catch(function (error) {
            var message = error && typeof error.message === 'string' && error.message !== ''
                ? error.message
                : 'Browser OCR failed. Check the internet connection and image quality.';
            $('#ajax-message').removeAttr('hidden').addClass('error').removeClass('ok').text(message);
            showToast('OCR failed', 'error');
            uploadBusy = false;
            $button.prop('disabled', false).text(originalText);
            setOcrProgress('', 0, false);
        });
    }

    $('#alias-options').on('click', '.alias-option', function () {
        if (!pendingAliasSelection) { return; }
        var selectedId = parseInt(String($(this).data('sender-id') || '0'), 10);
        if (!Number.isFinite(selectedId) || selectedId <= 0) { return; }
        var pending = pendingAliasSelection;
        pendingAliasSelection = null;
        $('#selected-sender-id').val(String(selectedId));
        hideAliasChoice();
        pending.$button.text('Saving…');
        submitRecognizedReceipt(pending.form, pending.ocrText, pending.$button, pending.originalText);
    });

    $('#alias-choice-cancel').on('click', function () {
        resetPendingUpload(true);
        $('#ajax-message').attr('hidden', true).removeClass('error ok').text('');
        showToast('Upload cancelled', 'ok');
    });

    $('#upload-form').on('submit', function (event) {
        event.preventDefault();
        processUpload(this);
    });

    $('#receipt-file').on('change', function () {
        if (this.files && this.files.length > 0) {
            var form = $('#upload-form')[0];
            if (form) { processUpload(form); }
        }
    });

    if ('BroadcastChannel' in window) {
        try {
            channel = new BroadcastChannel('bank-receipt-live-v1');
            channel.addEventListener('message', function (event) {
                if (!event.data || (event.data.type !== 'transaction-saved' && event.data.type !== 'senders-updated')) { return; }
                if (event.data.type === 'transaction-saved') { syncTransactions(false); }
            });
        } catch (error) { channel = null; }
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') { syncTransactions(true); }
        else { scheduleNext(hiddenPollMs); }
    });
    window.addEventListener('online', function () { syncTransactions(true); });

    $('.pager').on('click', '[data-pager]', function () {
        var scope = String($(this).attr('data-pager') || '');
        var dir = parseInt(String($(this).attr('data-dir') || '0'), 10) || 0;
        if (scope === 'weekly') { weeklyPage += dir; refreshWeeklyPage(); }
    });

    $('#transactions-weeks').on('click', '.tx-delete', function () {
        var $button = $(this);
        var id = parseInt(String($button.attr('data-id') || '0'), 10);
        if (!Number.isFinite(id) || id <= 0) { return; }
        if (armedDeleteId !== id) {
            armedDeleteId = id;
            $button.text('Confirm');
            window.setTimeout(function () {
                if (armedDeleteId === id) {
                    armedDeleteId = null;
                    $button.text('Delete');
                }
            }, 3000);
            return;
        }
        armedDeleteId = null;
        $button.prop('disabled', true).text('Deleting…');
        deleteTransaction(id);
    });

    refreshWeeklyPage();
    scheduleNext(600);
}(window.jQuery));
