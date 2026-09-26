<?php

if (!function_exists('nav_e')) {
    function nav_e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$username = $_SESSION['username'] ?? '';
$role = $_SESSION['role'] ?? '';
$importPages = ['attendance_manage.php', 'import_roster_excel_attendance.php', 'import_zoom.php', 'zoom_manual_match.php', 'import_bestplus.php', 'bestplus_dashboard.php', 'bestplus_ready.php', 'manual_match_pro.php'];
$systemPages = ['users_admin.php', 'audit_dashboard.php', 'settings_admin.php', 'backup_database.php', 'restore_database.php'];
$historicalPages = ['historical_dashboard.php'];
$importOpen = in_array($currentPage, $importPages, true);
$systemOpen = in_array($currentPage, $systemPages, true);
$historicalOpen = in_array($currentPage, $historicalPages, true);
?>

<style>
    html,
    body {
        background: #f4f6f9;
        max-width: 100%;
        overflow-x: hidden;
    }
    *,
    *::before,
    *::after {
        box-sizing: border-box;
    }
    .app-shell {
        display: flex;
        min-height: 100vh;
        width: 100%;
    }
    .app-main {
        flex: 1;
        min-width: 0;
        overflow-x: hidden;
    }
    .app-page {
        max-width: 100%;
        overflow-x: hidden;
        padding: 5.75rem 1.5rem 1.5rem;
        width: 100%;
    }
    .app-page > .row,
    .app-page form.row {
        margin-left: 0;
        margin-right: 0;
        max-width: 100%;
    }
    .app-page .card,
    .app-page .form-control,
    .app-page .form-select,
    .app-page .btn {
        max-width: 100%;
    }
    .table-wrap,
    .table-responsive {
        max-width: 100%;
        overflow-x: auto;
        width: 100%;
        -webkit-overflow-scrolling: touch;
    }
    .app-page table {
        max-width: 100%;
        width: 100%;
    }
    .page-header {
        background: #ffffff;
        border: 0;
        border-radius: 16px;
        box-shadow: 0 8px 22px rgba(0,0,0,.06);
        margin-bottom: 1.5rem;
        padding: 1rem 1.25rem;
    }
    .page-title {
        color: #1f2937;
        font-size: 1.4rem;
        font-weight: 700;
        line-height: 1.2;
        margin: 0;
    }
    .page-subtitle {
        color: #6c757d;
        display: block;
        font-size: .9rem;
        margin-top: .25rem;
    }
    .esol-topbar {
        background: #1f3f77;
        box-shadow: 0 8px 22px rgba(0,0,0,.08);
        min-height: 64px;
        z-index: 1040;
    }
    .esol-brand {
        gap: .4rem !important;
    }
    .esol-brand img {
        flex: 0 0 auto;
        height: 46px;
        width: auto;
    }
    .esol-sidebar {
        background: #173465;
        bottom: 0;
        left: 0;
        padding: 4.75rem .55rem .7rem;
        position: fixed;
        top: 0;
        width: 200px;
        z-index: 1030;
    }
    .esol-sidebar-inner {
        display: flex;
        flex-direction: column;
        gap: .28rem;
        height: 100%;
        overflow-y: auto;
        padding-right: 0;
    }
    .esol-sidebar-link,
    .esol-sidebar-toggle {
        align-items: center;
        border: 1px solid rgba(255,255,255,.18);
        border-radius: 10px;
        color: rgba(255,255,255,.88);
        display: flex;
        font-size: .84rem;
        font-weight: 600;
        gap: .28rem;
        padding: .42rem .5rem;
        text-decoration: none;
        width: 100%;
    }
    .esol-sidebar-link:hover,
    .esol-sidebar-toggle:hover,
    .esol-sidebar-link.active {
        background: rgba(255,255,255,.16);
        color: #ffffff;
    }
    .esol-sidebar-link.active {
        border-color: rgba(255,255,255,.5);
    }
    .esol-sidebar-toggle {
        background: rgba(255,255,255,.08);
    }
    .esol-sidebar-subnav {
        display: grid;
        gap: .24rem;
        padding: .28rem 0 .08rem .38rem;
    }
    .esol-sidebar-subnav .esol-sidebar-link {
        font-size: .78rem;
        padding: .36rem .45rem;
    }
    .esol-offcanvas {
        background: #173465;
        color: #ffffff;
    }
    @media (min-width: 992px) {
        .app-page {
            margin-left: 200px;
            max-width: calc(100% - 200px);
            width: calc(100% - 200px);
        }
    }
    @media (max-width: 991.98px) {
        .app-page {
            padding: 5.25rem 1rem 1rem;
        }
    }
</style>

<nav class="navbar navbar-dark fixed-top esol-topbar px-3">
    <div class="container-fluid gap-2 px-0">
        <button class="btn btn-outline-light btn-sm d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#esolSidebarMobile" aria-controls="esolSidebarMobile" aria-label="Open navigation">
            ☰
        </button>

        <a class="esol-brand d-flex align-items-center gap-2 text-white text-decoration-none me-auto" href="dashboard.php">
            <img src="assets/img/demo_logo.svg" alt="Demo Learning Center" style="background:white;padding:2px;border-radius:8px;">
            <span class="d-flex flex-column lh-sm">
                <span class="fw-semibold small">Demo Learning Center</span>
                <span class="text-white-50 small">Demo Student Hub</span>
                <span class="text-white-50 small">Portfolio Demonstration</span>
            </span>
        </a>

        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-end">
            <span class="text-white-50 small">Logged as</span>
            <span class="text-white small fw-semibold"><?= nav_e($username) ?></span>
            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle"><?= nav_e($role) ?></span>
            <a href="logout.php" class="btn btn-danger btn-sm">Logout</a>
        </div>
    </div>
</nav>

<aside class="esol-sidebar d-none d-lg-flex">
    <div class="esol-sidebar-inner">
        <a href="students_admin.php" class="esol-sidebar-link <?= $currentPage === 'students_admin.php' ? 'active' : '' ?>">👥 Students</a>
        <a href="rosters_admin.php" class="esol-sidebar-link <?= $currentPage === 'rosters_admin.php' ? 'active' : '' ?>">📋 Rosters</a>
        <a href="attendance_view.php" class="esol-sidebar-link <?= $currentPage === 'attendance_view.php' ? 'active' : '' ?>">✅ Attendance</a>
        <?php if (in_array(($_SESSION['role'] ?? ''), ['admin', 'staff'], true)): ?>
            <a href="attendance_manage.php" class="esol-sidebar-link <?= $currentPage === 'attendance_manage.php' ? 'active' : '' ?>">📋 Manage Attendance</a>
        <?php endif; ?>
        <?php if (in_array(($_SESSION['role'] ?? ''), ['admin', 'staff'], true)): ?>
            <a href="bestplus_ready.php" class="esol-sidebar-link <?= $currentPage === 'bestplus_ready.php' ? 'active' : '' ?>">🧪 Ready for Testing</a>
        <?php endif; ?>
        <button class="esol-sidebar-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#historicalNav" aria-expanded="<?= $historicalOpen ? 'true' : 'false' ?>" aria-controls="historicalNav">
            Historical
        </button>
        <div class="collapse <?= $historicalOpen ? 'show' : '' ?>" id="historicalNav">
            <div class="esol-sidebar-subnav">
                <a href="historical_dashboard.php" class="esol-sidebar-link <?= $currentPage === 'historical_dashboard.php' ? 'active' : '' ?>">Analytics</a>
            </div>
        </div>
        <a href="outreach.php" class="esol-sidebar-link <?= $currentPage === 'outreach.php' ? 'active' : '' ?>">💬 Outreach</a>

        <button class="esol-sidebar-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#importActionsNav" aria-expanded="<?= $importOpen ? 'true' : 'false' ?>" aria-controls="importActionsNav">
            Import Actions
        </button>
        <div class="collapse <?= $importOpen ? 'show' : '' ?>" id="importActionsNav">
            <div class="esol-sidebar-subnav">
                <?php if (in_array(($_SESSION['role'] ?? ''), ['admin', 'staff'], true)): ?>
                    <a href="attendance_manage.php" class="esol-sidebar-link <?= $currentPage === 'attendance_manage.php' ? 'active' : '' ?>">Manage Attendance</a>
                    <a href="import_roster_excel_attendance.php" class="esol-sidebar-link <?= $currentPage === 'import_roster_excel_attendance.php' ? 'active' : '' ?>">Import Excel Attendance</a>
                <?php endif; ?>
                <a href="import_zoom.php" class="esol-sidebar-link <?= $currentPage === 'import_zoom.php' ? 'active' : '' ?>">Import Zoom</a>
                <?php if (in_array(($_SESSION['role'] ?? ''), ['admin', 'staff'], true)): ?>
                    <a href="zoom_manual_match.php" class="esol-sidebar-link <?= $currentPage === 'zoom_manual_match.php' ? 'active' : '' ?>">🔗 Zoom Match</a>
                <?php endif; ?>
                <a href="import_bestplus.php" class="esol-sidebar-link <?= $currentPage === 'import_bestplus.php' ? 'active' : '' ?>">Import BEST</a>
                <a href="bestplus_dashboard.php" class="esol-sidebar-link <?= $currentPage === 'bestplus_dashboard.php' ? 'active' : '' ?>">📝 BEST Plus</a>
                <?php if (in_array(($_SESSION['role'] ?? ''), ['admin', 'staff'], true)): ?>
                    <a href="bestplus_ready.php" class="esol-sidebar-link <?= $currentPage === 'bestplus_ready.php' ? 'active' : '' ?>">🧪 Ready for Testing</a>
                <?php endif; ?>
                <a href="manual_match_pro.php" class="esol-sidebar-link <?= $currentPage === 'manual_match_pro.php' ? 'active' : '' ?>">Manual Match</a>
            </div>
        </div>

        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <button class="esol-sidebar-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#systemToolsNav" aria-expanded="<?= $systemOpen ? 'true' : 'false' ?>" aria-controls="systemToolsNav">
                System Tools
            </button>
            <div class="collapse <?= $systemOpen ? 'show' : '' ?>" id="systemToolsNav">
                <div class="esol-sidebar-subnav">
                    <a href="users_admin.php" class="esol-sidebar-link <?= $currentPage === 'users_admin.php' ? 'active' : '' ?>">🔐 Users</a>
                    <a href="audit_dashboard.php" class="esol-sidebar-link <?= $currentPage === 'audit_dashboard.php' ? 'active' : '' ?>">🛡️ Audit Logs</a>
                    <a href="settings_admin.php" class="esol-sidebar-link <?= $currentPage === 'settings_admin.php' ? 'active' : '' ?>">⚙️ Settings</a>
                    <a href="backup_database.php" class="esol-sidebar-link <?= $currentPage === 'backup_database.php' ? 'active' : '' ?>">💾 Backup</a>
                    <a href="restore_database.php" class="esol-sidebar-link <?= $currentPage === 'restore_database.php' ? 'active' : '' ?>">♻️ Restore</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</aside>

<div class="offcanvas offcanvas-start esol-offcanvas" tabindex="-1" id="esolSidebarMobile" aria-labelledby="esolSidebarMobileLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="esolSidebarMobileLabel">Navigation</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <div class="esol-sidebar-inner">
            <a href="students_admin.php" class="esol-sidebar-link <?= $currentPage === 'students_admin.php' ? 'active' : '' ?>">👥 Students</a>
            <a href="rosters_admin.php" class="esol-sidebar-link <?= $currentPage === 'rosters_admin.php' ? 'active' : '' ?>">📋 Rosters</a>
            <a href="attendance_view.php" class="esol-sidebar-link <?= $currentPage === 'attendance_view.php' ? 'active' : '' ?>">✅ Attendance</a>
            <?php if (in_array(($_SESSION['role'] ?? ''), ['admin', 'staff'], true)): ?>
                <a href="attendance_manage.php" class="esol-sidebar-link <?= $currentPage === 'attendance_manage.php' ? 'active' : '' ?>">📋 Manage Attendance</a>
            <?php endif; ?>
            <?php if (in_array(($_SESSION['role'] ?? ''), ['admin', 'staff'], true)): ?>
                <a href="bestplus_ready.php" class="esol-sidebar-link <?= $currentPage === 'bestplus_ready.php' ? 'active' : '' ?>">🧪 Ready for Testing</a>
            <?php endif; ?>
            <button class="esol-sidebar-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#historicalMobileNav" aria-expanded="<?= $historicalOpen ? 'true' : 'false' ?>" aria-controls="historicalMobileNav">
                Historical
            </button>
            <div class="collapse <?= $historicalOpen ? 'show' : '' ?>" id="historicalMobileNav">
                <div class="esol-sidebar-subnav">
                    <a href="historical_dashboard.php" class="esol-sidebar-link <?= $currentPage === 'historical_dashboard.php' ? 'active' : '' ?>">Analytics</a>
                </div>
            </div>
            <a href="outreach.php" class="esol-sidebar-link <?= $currentPage === 'outreach.php' ? 'active' : '' ?>">💬 Outreach</a>

            <button class="esol-sidebar-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#importActionsMobileNav" aria-expanded="<?= $importOpen ? 'true' : 'false' ?>" aria-controls="importActionsMobileNav">
                Import Actions
            </button>
            <div class="collapse <?= $importOpen ? 'show' : '' ?>" id="importActionsMobileNav">
                <div class="esol-sidebar-subnav">
                    <?php if (in_array(($_SESSION['role'] ?? ''), ['admin', 'staff'], true)): ?>
                        <a href="attendance_manage.php" class="esol-sidebar-link <?= $currentPage === 'attendance_manage.php' ? 'active' : '' ?>">Manage Attendance</a>
                        <a href="import_roster_excel_attendance.php" class="esol-sidebar-link <?= $currentPage === 'import_roster_excel_attendance.php' ? 'active' : '' ?>">Import Excel Attendance</a>
                    <?php endif; ?>
                    <a href="import_zoom.php" class="esol-sidebar-link <?= $currentPage === 'import_zoom.php' ? 'active' : '' ?>">Import Zoom</a>
                    <?php if (in_array(($_SESSION['role'] ?? ''), ['admin', 'staff'], true)): ?>
                        <a href="zoom_manual_match.php" class="esol-sidebar-link <?= $currentPage === 'zoom_manual_match.php' ? 'active' : '' ?>">🔗 Zoom Match</a>
                    <?php endif; ?>
                    <a href="import_bestplus.php" class="esol-sidebar-link <?= $currentPage === 'import_bestplus.php' ? 'active' : '' ?>">Import BEST</a>
                    <a href="bestplus_dashboard.php" class="esol-sidebar-link <?= $currentPage === 'bestplus_dashboard.php' ? 'active' : '' ?>">📝 BEST Plus</a>
                    <?php if (in_array(($_SESSION['role'] ?? ''), ['admin', 'staff'], true)): ?>
                        <a href="bestplus_ready.php" class="esol-sidebar-link <?= $currentPage === 'bestplus_ready.php' ? 'active' : '' ?>">🧪 Ready for Testing</a>
                    <?php endif; ?>
                    <a href="manual_match_pro.php" class="esol-sidebar-link <?= $currentPage === 'manual_match_pro.php' ? 'active' : '' ?>">Manual Match</a>
                </div>
            </div>

            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                <button class="esol-sidebar-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#systemToolsMobileNav" aria-expanded="<?= $systemOpen ? 'true' : 'false' ?>" aria-controls="systemToolsMobileNav">
                    System Tools
                </button>
                <div class="collapse <?= $systemOpen ? 'show' : '' ?>" id="systemToolsMobileNav">
                    <div class="esol-sidebar-subnav">
                        <a href="users_admin.php" class="esol-sidebar-link <?= $currentPage === 'users_admin.php' ? 'active' : '' ?>">🔐 Users</a>
                        <a href="audit_dashboard.php" class="esol-sidebar-link <?= $currentPage === 'audit_dashboard.php' ? 'active' : '' ?>">🛡️ Audit Logs</a>
                        <a href="settings_admin.php" class="esol-sidebar-link <?= $currentPage === 'settings_admin.php' ? 'active' : '' ?>">⚙️ Settings</a>
                        <a href="backup_database.php" class="esol-sidebar-link <?= $currentPage === 'backup_database.php' ? 'active' : '' ?>">💾 Backup</a>
                        <a href="restore_database.php" class="esol-sidebar-link <?= $currentPage === 'restore_database.php' ? 'active' : '' ?>">♻️ Restore</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
