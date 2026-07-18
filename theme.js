// theme.js — dark/light theme switching, mobile sidebar toggle, and dashboard sub-nav tabs for Fresh Ceylon

// Shared tab switcher for the dashboard sub-navigation bar (Farmer / Sales / Transporter)
function showDashboardTab(tabId, btn) {
    document.querySelectorAll('.tab-content').forEach(function (c) { c.classList.remove('active'); });
    document.querySelectorAll('.dashboard-tabs .tab-btn').forEach(function (b) { b.classList.remove('active'); });
    var target = document.getElementById(tabId);
    if (target) target.classList.add('active');
    if (btn) btn.classList.add('active');
    try { sessionStorage.setItem('fc-active-tab-' + window.location.pathname, tabId); } catch (e) { /* ignore */ }
}

(function () {
    var STORAGE_KEY = 'fc-theme';

    function getStoredTheme() {
        try { return localStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
    }
    function storeTheme(theme) {
        try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) { /* ignore */ }
    }
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        document.querySelectorAll('[data-theme-icon]').forEach(function (el) {
            el.textContent = theme === 'light' ? '\u{1F319}' : '\u2600\uFE0F';
        });
        document.querySelectorAll('[data-theme-label]').forEach(function (el) {
            el.textContent = theme === 'light' ? 'Dark mode' : 'Light mode';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var current = document.documentElement.getAttribute('data-theme') || getStoredTheme() || 'dark';
        applyTheme(current);

        // Restore the sub-nav tab the user was on before a form submit reloaded the page
        var tabBar = document.querySelector('.dashboard-tabs');
        if (tabBar) {
            try {
                var savedTab = sessionStorage.getItem('fc-active-tab-' + window.location.pathname);
                if (savedTab && document.getElementById(savedTab)) {
                    var savedBtn = tabBar.querySelector('[data-tab-target="' + savedTab + '"]');
                    showDashboardTab(savedTab, savedBtn);
                }
            } catch (e) { /* ignore */ }
        }

        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
                storeTheme(next);
                applyTheme(next);
            });
        });

        var navToggle = document.getElementById('mobileNavToggle');
        var sideNav = document.getElementById('sideNav');
        var backdrop = document.getElementById('sideNavBackdrop');

        function closeNav() {
            if (sideNav) sideNav.classList.remove('open');
            if (backdrop) backdrop.classList.remove('show');
        }
        function openNav() {
            if (sideNav) sideNav.classList.add('open');
            if (backdrop) backdrop.classList.add('show');
        }
        if (navToggle) {
            navToggle.addEventListener('click', function () {
                if (sideNav && sideNav.classList.contains('open')) {
                    closeNav();
                } else {
                    openNav();
                }
            });
        }
        if (backdrop) backdrop.addEventListener('click', closeNav);

        // Modal dialogs (e.g. "+ Add New Produce")
        document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var modal = document.getElementById(btn.getAttribute('data-modal-open'));
                if (modal) modal.classList.add('open');
            });
        });
        document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var modal = btn.closest('.modal-overlay');
                if (modal) modal.classList.remove('open');
            });
        });
        document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) overlay.classList.remove('open');
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.open').forEach(function (m) {
                    m.classList.remove('open');
                });
            }
        });
    });
})();