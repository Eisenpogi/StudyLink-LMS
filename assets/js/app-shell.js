(function () {
    var menuButton = document.querySelector('[data-portal-menu]');
    var sidebar = document.querySelector('[data-portal-sidebar]');
    var backdrop = document.querySelector('[data-portal-backdrop]');
    var navGroups = Array.prototype.slice.call(document.querySelectorAll('[data-admin-nav-group]'));
    var profileTrigger = document.querySelector('[data-admin-profile-trigger]');
    var profileMenu = document.querySelector('[data-admin-profile-menu]');
    var logoutTrigger = document.querySelector('[data-portal-logout-trigger]');
    var logoutDialog = document.querySelector('[data-portal-logout-dialog]');
    var logoutCancel = document.querySelector('[data-portal-logout-cancel]');

    function openSidebar() {
        if (!sidebar || !backdrop) return;
        sidebar.classList.add('is-open');
        backdrop.hidden = false;
        document.body.classList.add('portal-sidebar-open');
        if (menuButton) {
            menuButton.setAttribute('aria-expanded', 'true');
            menuButton.setAttribute('aria-label', 'Close navigation');
        }
    }

    function closeSidebar() {
        if (!sidebar || !backdrop) return;
        sidebar.classList.remove('is-open');
        backdrop.hidden = true;
        document.body.classList.remove('portal-sidebar-open');
        if (menuButton) {
            menuButton.setAttribute('aria-expanded', 'false');
            menuButton.setAttribute('aria-label', 'Open navigation');
        }
    }

    function setNavGroupState(group, isOpen) {
        var toggle = group.querySelector('[data-admin-nav-toggle]');
        var items = group.querySelector('[data-admin-nav-items]');

        if (!toggle || !items) return;

        group.classList.toggle('is-open', isOpen);
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        items.hidden = !isOpen;
    }

    function toggleNavGroup(group) {
        var willOpen = !group.classList.contains('is-open');

        navGroups.forEach(function (otherGroup) {
            setNavGroupState(otherGroup, otherGroup === group && willOpen);
        });
    }

    function toggleProfile() {
        if (!profileMenu || !profileTrigger) return;
        var willOpen = profileMenu.hidden;
        profileMenu.hidden = !willOpen;
        profileTrigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    }

    function closeProfile() {
        if (!profileMenu || !profileTrigger || profileMenu.hidden) return;
        profileMenu.hidden = true;
        profileTrigger.setAttribute('aria-expanded', 'false');
    }

    function openLogout() {
        if (!logoutDialog) return;
        closeProfile();
        logoutDialog.hidden = false;
        document.body.classList.add('portal-dialog-open');
        if (logoutCancel) logoutCancel.focus();
    }

    function closeLogout() {
        if (!logoutDialog) return;
        logoutDialog.hidden = true;
        document.body.classList.remove('portal-dialog-open');
        if (logoutTrigger) logoutTrigger.focus();
    }

    navGroups.forEach(function (group) {
        var toggle = group.querySelector('[data-admin-nav-toggle]');
        var items = group.querySelector('[data-admin-nav-items]');
        var isOpen = group.classList.contains('is-open');

        if (!toggle || !items) return;

        setNavGroupState(group, isOpen);
        toggle.addEventListener('click', function () {
            toggleNavGroup(group);
        });
    });

    if (menuButton) {
        menuButton.addEventListener('click', function () {
            if (sidebar && sidebar.classList.contains('is-open')) closeSidebar();
            else openSidebar();
        });
    }
    if (backdrop) backdrop.addEventListener('click', closeSidebar);
    if (profileTrigger) profileTrigger.addEventListener('click', toggleProfile);
    if (logoutTrigger) logoutTrigger.addEventListener('click', openLogout);
    if (logoutCancel) logoutCancel.addEventListener('click', closeLogout);
    if (logoutDialog) {
        logoutDialog.addEventListener('click', function (event) {
            if (event.target === logoutDialog) closeLogout();
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        closeSidebar();
        closeProfile();
        if (logoutDialog && !logoutDialog.hidden) closeLogout();
    });

    document.addEventListener('click', function (event) {
        if (profileMenu && profileTrigger && !profileMenu.hidden &&
            !profileMenu.contains(event.target) && !profileTrigger.contains(event.target)) {
            closeProfile();
        }
    });

    if (sidebar) {
        sidebar.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 900) closeSidebar();
            });
        });
    }
})();
