(function () {
    'use strict';

    var STORAGE_KEY = 'sidebar-collapsed';
    var sidebar = document.getElementById('sidebar');

    if (!sidebar) {
        return;
    }

    // Sync sidebar class with html class (set in <head> for instant rendering)
    if (localStorage.getItem(STORAGE_KEY) === '1') {
        sidebar.classList.add('sidebar-collapsed');
    }

    // Global toggle function called by onclick handler
    window.toggleSidebar = function () {
        sidebar.classList.toggle('sidebar-collapsed');
        var collapsed = sidebar.classList.contains('sidebar-collapsed');
        document.documentElement.classList.toggle('sidebar-is-collapsed', collapsed);
        localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
    };
})();
