<?php

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/javascript; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, max-age=0, must-revalidate');
?>
(function () {
    'use strict';

    var script = document.currentScript;
    var swUrl = script && script.dataset && script.dataset.sw ? String(script.dataset.sw) : 'sw.php';
    var installPrompt = null;

    function installButtons() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-pwa-install]'));
    }

    function setInstallVisible(visible) {
        installButtons().forEach(function (button) {
            button.hidden = visible !== true;
        });
    }

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(swUrl).catch(function () {
                // Registration failure is non-fatal. The online app remains usable.
            });
        });
    }

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        installPrompt = event;
        setInstallVisible(true);
    });

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        var button = target.closest('[data-pwa-install]');
        if (button === null || installPrompt === null) {
            return;
        }

        event.preventDefault();
        installPrompt.prompt();
        installPrompt.userChoice.finally(function () {
            installPrompt = null;
            setInstallVisible(false);
        });
    });

    window.addEventListener('appinstalled', function () {
        installPrompt = null;
        setInstallVisible(false);
        removePwaGate();
    });

    function isStandaloneMode() {
        try {
            if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) { return true; }
        } catch (error) {}
        try { if (window.navigator && window.navigator.standalone === true) { return true; } } catch (error) {}
        return false;
    }

    function isMobileBrowser() {
        var coarse = false;
        try { coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches; } catch (error) {}
        var ua = String(window.navigator && window.navigator.userAgent ? window.navigator.userAgent : '');
        return coarse === true || /Android|iPhone|iPad|iPod|Opera Mini|IEMobile|BlackBerry|Mobile|webOS/i.test(ua);
    }

    function removePwaGate() {
        var gate = document.getElementById('pwa-gate');
        if (gate && gate.parentNode) { gate.parentNode.removeChild(gate); }
        if (document.body) { document.body.classList.remove('pwa-gate-locked'); }
    }

    function showPwaGate() {
        var body = document.body;
        if (!body || body.hasAttribute('data-pwa-gate') !== true) { return; }
        if (isStandaloneMode() || !isMobileBrowser()) { return; }
        if (document.getElementById('pwa-gate') !== null) { return; }

        var gate = document.createElement('div');
        gate.id = 'pwa-gate';
        gate.className = 'pwa-gate';
        gate.setAttribute('role', 'dialog');
        gate.setAttribute('aria-modal', 'true');
        gate.setAttribute('aria-label', 'Install the application');

        var card = document.createElement('div');
        card.className = 'pwa-gate-card';

        var heading = document.createElement('h2');
        heading.textContent = 'Install the app';
        card.appendChild(heading);

        var intro = document.createElement('p');
        intro.textContent = 'Browser access is disabled. Install the app on this device to continue.';
        card.appendChild(intro);

        var steps = document.createElement('div');
        steps.className = 'pwa-gate-steps';
        var stepAndroid = document.createElement('span');
        var androidTitle = document.createElement('b');
        androidTitle.textContent = 'Android · Chrome';
        stepAndroid.appendChild(androidTitle);
        stepAndroid.appendChild(document.createTextNode('Open the menu and tap “Add to Home screen” or “Install app”.'));
        var stepIos = document.createElement('span');
        var iosTitle = document.createElement('b');
        iosTitle.textContent = 'iPhone / iPad · Safari';
        stepIos.appendChild(iosTitle);
        stepIos.appendChild(document.createTextNode('Tap Share, then “Add to Home Screen”, then open the app from the home screen icon.'));
        steps.appendChild(stepAndroid);
        steps.appendChild(stepIos);
        card.appendChild(steps);

        var installButton = document.createElement('button');
        installButton.type = 'button';
        installButton.className = 'pwa-gate-btn';
        installButton.textContent = 'Install App';
        installButton.addEventListener('click', function () {
            if (installPrompt !== null) { installPrompt.prompt(); }
        });
        card.appendChild(installButton);

        var dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'pwa-gate-dismiss';
        dismiss.textContent = 'Continue in browser anyway';
        dismiss.addEventListener('click', function () {
            try { window.sessionStorage.setItem('pwa-gate-dismissed', '1'); } catch (error) {}
            removePwaGate();
        });
        card.appendChild(dismiss);

        gate.appendChild(card);
        body.appendChild(gate);
        body.classList.add('pwa-gate-locked');
    }

    function runPwaGate() {
        try {
            if (window.sessionStorage && window.sessionStorage.getItem('pwa-gate-dismissed') === '1') { return; }
        } catch (error) {}
        showPwaGate();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', runPwaGate);
    } else {
        runPwaGate();
    }
}());
