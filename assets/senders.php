<?php

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/javascript; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, max-age=0, must-revalidate');

// See assets/app.php: no-cache without a validator turns every revalidation into
// a full download. mtime+size lets it answer 304 instead.
$assetTag = '"' . dechex((int) filemtime(__FILE__)) . '-' . dechex((int) filesize(__FILE__)) . '"';
header('ETag: ' . $assetTag);
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $assetTag) {
    http_response_code(304);
    exit;
}
?>
(function ($) {
    'use strict';
    var $body = $('body[data-senders-endpoint]');
    if ($body.length === 0) { return; }
    var endpoint = String($body.data('senders-endpoint') || 'api/senders.php');

    function toast(message, error) {
        var $item = $('<div>', {'class': 'toast' + (error ? ' error' : ''), 'role': error ? 'alert' : 'status'}).text(message);
        $('#toast-stack').append($item);
        window.requestAnimationFrame(function () { $item.addClass('show'); });
        window.setTimeout(function () { $item.removeClass('show'); window.setTimeout(function () { $item.remove(); }, 220); }, 1600);
    }
    function showError(message) { $('#sender-message').removeAttr('hidden').addClass('error').text(message); toast(message, true); }
    function csrfToken() { return String($('input[name="csrf_token"]').first().val() || ''); }

    var BANK_CODES = ['BCA', 'MANDIRI', 'BRI', 'SEABANK', 'BNI', 'CIMB', 'PERMATA', 'DANAMON', 'BSI', 'BTN', 'MAYBANK', 'OCBC', 'JAGO', 'PANIN'];

    function fillBankOptions($select) {
        $select.empty();
        BANK_CODES.forEach(function (code) { $('<option>', {value: code, text: code}).appendTo($select); });
    }

    function buildAccountsRow(id, accounts) {
        var $row = $('<div>', {'class': 'accounts-row'});
        var $list = $('<div>', {'class': 'accounts-list'}).appendTo($row);
        (Array.isArray(accounts) ? accounts : []).forEach(function (account) {
            var accountId = parseInt(String(account.id || 0), 10);
            if (!Number.isFinite(accountId) || accountId <= 0) { return; }
            var number = String(account.account_number || '');
            var $chip = $('<span>', {'class': 'account-chip', 'data-account-id': String(accountId)}).appendTo($list);
            $chip.append(document.createTextNode(String(account.bank_code || '') + ' •••' + number.slice(-4)));
            $('<button>', {type: 'button', 'class': 'account-remove', 'data-account-id': String(accountId), 'aria-label': 'Remove account', text: '×'}).appendTo($chip);
        });

        var $form = $('<form>', {'class': 'account-add-form', autocomplete: 'off'}).appendTo($row);
        $('<input>', {type: 'hidden', name: 'csrf_token', value: csrfToken()}).appendTo($form);
        $('<input>', {type: 'hidden', name: 'action', value: 'add_account'}).appendTo($form);
        $('<input>', {type: 'hidden', name: 'sender_id', value: String(id)}).appendTo($form);
        var $bank = $('<select>', {'class': 'select account-bank', name: 'bank_code'}).appendTo($form);
        fillBankOptions($bank);
        $('<input>', {'class': 'input account-number', name: 'account_number', maxlength: 30, placeholder: 'Account number'}).appendTo($form);
        $('<button>', {'class': 'btn secondary', type: 'submit', text: '+ Account'}).appendTo($form);
        return $row;
    }

    function render(senders) {
        if (!Array.isArray(senders)) { return; }
        var $tbody = $('#senders-body').empty();
        senders.forEach(function (sender) {
            var id = parseInt(String(sender.id || 0), 10);
            if (!Number.isFinite(id) || id <= 0) { return; }
            var $tr = $('<tr>').attr('data-sender-id', String(id));
            var $td = $('<td>', {colspan: 7}).appendTo($tr);
            var $form = $('<form>', {'class': 'row-form sender-row-form', autocomplete: 'off'}).appendTo($td);
            $('<input>', {type: 'hidden', name: 'csrf_token', value: csrfToken()}).appendTo($form);
            $('<input>', {type: 'hidden', name: 'action', value: 'update'}).appendTo($form);
            $('<input>', {type: 'hidden', name: 'sender_id', value: String(id)}).appendTo($form);
            $('<input>', {'class': 'input', name: 'sender_name', maxlength: 100, required: true, value: String(sender.display_name || '')}).appendTo($form);
            $('<input>', {'class': 'input', name: 'subid', maxlength: 100, value: String(sender.subid || sender.alias || ''), placeholder: 'SUBID', required: true}).appendTo($form);
            $('<input>', {'class': 'input', name: 'location', maxlength: 120, value: String(sender.location || ''), placeholder: 'Location', required: true}).appendTo($form);
            var $team = $('<select>', {'class': 'select', name: 'team'}).appendTo($form);
            $('<option>', {value: 'XCTD', text: 'XCTD', selected: sender.team === 'XCTD'}).appendTo($team);
            $('<option>', {value: 'MNX', text: 'MNX', selected: sender.team === 'MNX'}).appendTo($team);
            var $status = $('<select>', {'class': 'select', name: 'is_active'}).appendTo($form);
            $('<option>', {value: '1', text: 'active', selected: Number(sender.is_active) === 1}).appendTo($status);
            $('<option>', {value: '0', text: 'disabled', selected: Number(sender.is_active) !== 1}).appendTo($status);
            $('<button>', {'class': 'btn secondary', type: 'submit', text: 'Save'}).appendTo($form);
            $('<button>', {'class': 'btn danger sender-delete', type: 'button', text: 'Delete'}).appendTo($form);
            $td.append(buildAccountsRow(id, sender.accounts));
            $tbody.append($tr);
        });
        $('#sender-count').text(String(senders.length) + ' records');
        applySendersPage();
    }

    var PAGE_SIZE = 12;
    var sendersPage = 1;

    function applySendersPage() {
        var $rows = $('#senders-body tr[data-sender-id]');
        var total = $rows.length;
        var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
        if (sendersPage > pages) { sendersPage = pages; }
        if (sendersPage < 1) { sendersPage = 1; }
        var start = (sendersPage - 1) * PAGE_SIZE;
        $rows.each(function (index) {
            $(this).toggleClass('page-hidden', index < start || index >= start + PAGE_SIZE);
        });
        var $pager = $('#senders-pager');
        if ($pager.length === 0) { return; }
        if (total <= PAGE_SIZE) {
            $pager.attr('hidden', true);
        } else {
            $pager.removeAttr('hidden');
            $pager.find('[data-dir="-1"]').prop('disabled', sendersPage <= 1);
            $pager.find('[data-dir="1"]').prop('disabled', sendersPage >= pages);
            $('#senders-pager-info').text('Page ' + sendersPage + ' / ' + pages);
        }
    }

    function request(data, successMessage) {
        $('#sender-message').attr('hidden', true).removeClass('error').text('');
        return $.ajax({url: endpoint, method: 'POST', data: data, dataType: 'json', timeout: 20000, headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .done(function (response) {
                if (!response || response.ok !== true) { showError('Unable to update sender.'); return; }
                render(response.senders || []);
                toast(String(response.message || successMessage || 'Saved'), false);
                if ('BroadcastChannel' in window) {
                    try { var channel = new BroadcastChannel('bank-receipt-live-v1'); channel.postMessage({type: 'senders-updated'}); channel.close(); } catch (error) {}
                }
            }).fail(function (xhr) {
                if (xhr.status === 401) { window.location.assign('login.php'); return; }
                var message = xhr.responseJSON && typeof xhr.responseJSON.message === 'string' ? xhr.responseJSON.message : 'Unable to update sender.';
                showError(message);
            });
    }

    $('#sender-create-form').on('submit', function (event) {
        event.preventDefault();
        var $form = $(this);
        request($form.serialize(), 'Sender added.').done(function (response) { if (response && response.ok === true) { $form[0].reset(); } });
    });
    $('#senders-body').on('submit', '.sender-row-form', function (event) { event.preventDefault(); request($(this).serialize(), 'Sender updated.'); });
    $('#senders-body').on('click', '.sender-delete', function () {
        var $button = $(this), $form = $button.closest('form'), senderId = String($form.find('input[name="sender_id"]').val() || '');
        if ($button.data('armed') !== true) {
            $button.data('armed', true).text('Confirm');
            window.setTimeout(function () { $button.data('armed', false).text('Delete'); }, 3000);
            return;
        }
        $button.data('armed', false).text('Delete');
        request({csrf_token: csrfToken(), action: 'delete', sender_id: senderId}, 'Sender deleted.');
    });

    $('#senders-body').on('submit', '.account-add-form', function (event) {
        event.preventDefault();
        request($(this).serialize(), 'Bank account added.');
    });
    $('#senders-body').on('click', '.account-remove', function () {
        var senderId = String($(this).closest('tr').attr('data-sender-id') || '');
        var accountId = String($(this).attr('data-account-id') || '');
        request({csrf_token: csrfToken(), action: 'delete_account', sender_id: senderId, account_id: accountId}, 'Bank account removed.');
    });

    $('#senders-pager').on('click', '[data-pager]', function () {
        var dir = parseInt(String($(this).attr('data-dir') || '0'), 10) || 0;
        sendersPage += dir;
        applySendersPage();
    });

    $('.account-bank').each(function () { fillBankOptions($(this)); });

    applySendersPage();
}(window.jQuery));
