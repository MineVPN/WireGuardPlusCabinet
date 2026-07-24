/*
 * WGPlus — общий JS панели.
 * Без зависимостей и сборки: обычный скрипт в <head> с defer.
 */

(function () {
    'use strict';

    /**
     * Показать уведомление вверху страницы.
     * Совместимо со старым вызовом Notice('текст') — тип по умолчанию info.
     *
     * @param {string} text
     * @param {'success'|'error'|'info'} [type]
     */
    function Notice(text, type) {
        var box = document.getElementById('notice');
        if (!box) return;
        box.textContent = text;
        box.className = 'notice notice--' + (type || 'info');
        box.classList.remove('hidden');
    }

    // Экспортируем глобально — страницы вызывают Notice() из инлайн-скриптов.
    window.Notice = Notice;

    /** Показать имя выбранного файла в зоне загрузки. */
    function initDropzone() {
        var zone  = document.getElementById('dropzone');
        var input = document.getElementById('config_file');
        var label = document.getElementById('dropzone-text');
        if (!zone || !input || !label) return;

        function show(files) {
            if (files && files.length) {
                label.innerHTML = 'Выбран файл: <strong>' + files[0].name + '</strong>';
            }
        }

        ['dragenter', 'dragover'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) {
                e.preventDefault();
                zone.classList.add('is-over');
            });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) {
                e.preventDefault();
                zone.classList.remove('is-over');
            });
        });
        zone.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                show(input.files);
            }
        });
        input.addEventListener('change', function () { show(input.files); });
    }

    document.addEventListener('DOMContentLoaded', initDropzone);
})();
