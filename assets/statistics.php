<?php

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/javascript; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, max-age=0, must-revalidate');
?>
(function ($) {
    'use strict';
    var $body = $('body[data-reporting-endpoint]');
    if ($body.length === 0) { return; }
    var endpoint = String($body.data('reporting-endpoint') || 'api/reporting.php');
    var ns = 'http://www.w3.org/2000/svg';
    var timer = null;

    function numberValue(raw) {
        var value = Number(String(raw || '0'));
        return Number.isFinite(value) && value >= 0 ? value : 0;
    }
    function clearSvg(id) { var node = document.getElementById(id); if (node) { while (node.firstChild) { node.removeChild(node.firstChild); } } return node; }
    function svgEl(name, attrs) { var node = document.createElementNS(ns, name); Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, String(attrs[key])); }); return node; }

    function renderChanges(changes) {
        ['week','month','year'].forEach(function (period) {
            var item = changes && changes[period] ? changes[period] : null;
            if (!item) { return; }
            var $card = $('[data-change-period="' + period + '"]');
            $card.find('[data-change-current]').text(String(item.current || 'IDR 0'));
            $card.find('[data-change-previous]').text(String(item.previous || 'IDR 0'));
            var direction = ['up','down','flat'].indexOf(item.direction) >= 0 ? item.direction : 'flat';
            var mark = direction === 'up' ? '▲' : (direction === 'down' ? '▼' : '■');
            var percent = Number(item.percent || 0);
            $card.find('[data-change-delta]').removeClass('up down flat').addClass(direction).text(mark + ' ' + (Number.isFinite(percent) ? percent.toFixed(1) : '0.0') + '%');
        });
    }

    function renderComparison(current) {
        if (!current || !current.teams) { return; }
        var x = numberValue(current.teams.XCTD && current.teams.XCTD.total_raw);
        var m = numberValue(current.teams.MNX && current.teams.MNX.total_raw);
        var max = Math.max(x, m, 1);
        $('#month-label').text(String(current.label || ''));
        $('#compare-xctd').text(String((current.teams.XCTD && current.teams.XCTD.total) || 'IDR 0'));
        $('#compare-mnx').text(String((current.teams.MNX && current.teams.MNX.total) || 'IDR 0'));
        $('#bar-xctd').attr('width', String((x / max) * 100));
        $('#bar-mnx').attr('width', String((m / max) * 100));
        $('#donut-total').text(String(current.total || 'IDR 0'));

        var total = x + m;
        var xPct = total > 0 ? (x / total) * 100 : 0;
        var mPct = total > 0 ? (m / total) * 100 : 0;
        $('#share-xctd').text(xPct.toFixed(1) + '%');
        $('#share-mnx').text(mPct.toFixed(1) + '%');
        var svg = clearSvg('donut-chart');
        if (!svg) { return; }
        var radius = 42, circumference = 2 * Math.PI * radius;
        svg.appendChild(svgEl('circle', {cx:60, cy:60, r:radius, fill:'none', stroke:'#e2e8f0', 'stroke-width':14}));
        var xCircle = svgEl('circle', {cx:60, cy:60, r:radius, fill:'none', stroke:'#2563eb', 'stroke-width':14, 'stroke-linecap':'butt', transform:'rotate(-90 60 60)'});
        xCircle.setAttribute('stroke-dasharray', String(circumference * (xPct / 100)) + ' ' + String(circumference));
        svg.appendChild(xCircle);
        var mCircle = svgEl('circle', {cx:60, cy:60, r:radius, fill:'none', stroke:'#0f766e', 'stroke-width':14, 'stroke-linecap':'butt', transform:'rotate(' + String(-90 + (xPct * 3.6)) + ' 60 60)'});
        mCircle.setAttribute('stroke-dasharray', String(circumference * (mPct / 100)) + ' ' + String(circumference));
        svg.appendChild(mCircle);
        var center = svgEl('text', {x:60, y:64, 'text-anchor':'middle', 'font-size':10, fill:'#64748b'}); center.textContent = String(Math.round(total > 0 ? 100 : 0)) + '%'; svg.appendChild(center);
    }

    function formatIdr(raw) {
        return 'IDR ' + numberValue(raw).toLocaleString('en-US');
    }

    function renderWeeklyTable(rows) {
        var $body=$('#weekly-history-body').empty();
        if (!Array.isArray(rows)) { return; }
        var sumX=0,sumM=0,sumT=0,sumC=0;
        var $table=$('<table>');
        $('<thead>').append($('<tr>').append(
            $('<th>').text('Week'),
            $('<th>',{'class':'wh-num'}).text('XCTD'),
            $('<th>',{'class':'wh-num'}).text('MNX'),
            $('<th>',{'class':'wh-num'}).text('Total'),
            $('<th>',{'class':'wh-num'}).text('Rcpt')
        )).appendTo($table);
        var $tbody=$('<tbody>');
        rows.slice().reverse().forEach(function(r){
            var x=numberValue(r.XCTD_raw),m=numberValue(r.MNX_raw),t=numberValue(r.total_raw);
            var c=parseInt(String(r.count||0),10)||0;
            sumX+=x;sumM+=m;sumT+=t;sumC+=c;
            $('<tr>').append(
                $('<td>').text(String(r.label||'')),
                $('<td>',{'class':'wh-num'}).text(formatIdr(r.XCTD_raw)),
                $('<td>',{'class':'wh-num'}).text(formatIdr(r.MNX_raw)),
                $('<td>',{'class':'wh-num wh-total'}).text(formatIdr(r.total_raw)),
                $('<td>',{'class':'wh-num wh-count'}).text(String(c))
            ).appendTo($tbody);
        });
        $tbody.appendTo($table);
        $('<tfoot>').append($('<tr>',{'class':'wh-foot'}).append(
            $('<td>').text('Total'),
            $('<td>',{'class':'wh-num'}).text(formatIdr(sumX)),
            $('<td>',{'class':'wh-num'}).text(formatIdr(sumM)),
            $('<td>',{'class':'wh-num wh-total'}).text(formatIdr(sumT)),
            $('<td>',{'class':'wh-num wh-count'}).text(String(sumC))
        )).appendTo($table);
        $table.appendTo($body);
    }

    function render(report) { if (!report) { return; } renderChanges(report.changes||{}); renderComparison(report.current_month||{}); renderWeeklyTable(report.weekly_history||[]); }
    function load() {
        $.ajax({url:endpoint,method:'GET',dataType:'json',cache:false,timeout:10000,headers:{'X-Requested-With':'XMLHttpRequest'}}).done(function(response){if(response&&response.ok===true&&response.report){render(response.report);$('#report-live').text('Live');}}).fail(function(xhr){if(xhr.status===401){window.location.assign('login.php');return;}$('#report-live').text('Reconnecting…');}).always(function(){window.clearTimeout(timer);timer=window.setTimeout(load,15000);});
    }

    try { var initial=JSON.parse(String($('#initial-report').text()||'{}')); render(initial); } catch(error) {}
    if ('BroadcastChannel' in window) { try { var channel=new BroadcastChannel('bank-receipt-live-v1'); channel.addEventListener('message',function(event){if(event.data&&event.data.type==='transaction-saved'){load();}}); } catch(error) {} }
    document.addEventListener('visibilitychange',function(){if(document.visibilityState==='visible'){load();}});
    timer=window.setTimeout(load,12000);
}(window.jQuery));
