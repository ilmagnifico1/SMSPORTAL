document.addEventListener('DOMContentLoaded', function () {
    var tr = function (key) { return typeof window.smsTranslate === 'function' ? window.smsTranslate(key) : key; };
    document.querySelectorAll('textarea').forEach(function (textarea) {
        textarea.addEventListener('input', function () {
            var counter = document.getElementById('ctr');
            if (!counter) return;
            var length = textarea.value.length;
            counter.textContent = length > 249 ? length + ' - ' + tr('message_too_long') : String(length);
            counter.style.color = length > 249 ? '#f00' : (length > 160 ? '#8e36de' : '#000');
        });
    });
});
