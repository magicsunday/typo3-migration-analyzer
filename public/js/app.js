(function () {
    'use strict';

    // --- Sidebar ---

    var STORAGE_KEY = 'sidebar-collapsed';
    var sidebar = document.getElementById('sidebar');

    if (sidebar) {
        if (localStorage.getItem(STORAGE_KEY) === '1') {
            sidebar.classList.add('sidebar-collapsed');
        }

        var sidebarToggle = document.getElementById('sidebar-toggle');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function () {
                sidebar.classList.toggle('sidebar-collapsed');
                var collapsed = sidebar.classList.contains('sidebar-collapsed');
                document.documentElement.classList.toggle('sidebar-is-collapsed', collapsed);
                localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
            });
        }

        var sidebarMobileToggle = document.getElementById('sidebar-mobile-toggle');
        if (sidebarMobileToggle) {
            sidebarMobileToggle.addEventListener('click', function () {
                sidebar.classList.toggle('d-none');
            });
        }
    }

    // --- Migration version picker ---

    var sourceSelect = document.getElementById('migration-source');
    var targetSelect = document.getElementById('migration-target');

    if (sourceSelect && targetSelect) {
        var allVersions = JSON.parse(sourceSelect.dataset.versions || '[]');
        var initialTarget = parseInt(targetSelect.dataset.selected, 10) || null;

        function updateTargetOptions() {
            var source = parseInt(sourceSelect.value, 10);
            var previousTarget = parseInt(targetSelect.value, 10) || initialTarget;

            targetSelect.innerHTML = '';

            allVersions.forEach(function (v) {
                if (v <= source) {
                    return;
                }

                var option = document.createElement('option');
                option.value = v;
                option.textContent = v;

                if (v === previousTarget) {
                    option.selected = true;
                }

                targetSelect.appendChild(option);
            });

            if (!targetSelect.value && targetSelect.options.length > 0) {
                targetSelect.options[0].selected = true;
            }
        }

        function applyMigrationPath() {
            var source = sourceSelect.value;
            var target = targetSelect.value;

            window.location.href = window.location.pathname
                + '?migration_source=' + source
                + '&migration_target=' + target;
        }

        sourceSelect.addEventListener('change', function () {
            updateTargetOptions();
            applyMigrationPath();
        });

        targetSelect.addEventListener('change', applyMigrationPath);

        updateTargetOptions();
    }

    // --- Syntax highlighting ---

    if (typeof hljs !== 'undefined') {
        document.querySelectorAll('pre code.hljs-code').forEach(function (el) {
            if (!el.dataset.highlighted) {
                hljs.highlightElement(el);
            }
        });
    }
})();
