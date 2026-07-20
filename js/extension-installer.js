(function () {
    'use strict';
    var root = document.querySelector('[data-extension-installer]');
    if (!root) return;
    var download = root.querySelector('[data-package-download]');
    var status = root.querySelector('[data-install-status]');

    window.setTimeout(function () {
        download.click();
        status.textContent = document.documentElement.lang === 'en'
            ? 'Package downloaded. Complete the browser confirmation shown below.'
            : 'Pacchetto scaricato. Completa la conferma del browser indicata qui sotto.';
    }, 450);
}());
