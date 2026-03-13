<?php
/**
 * Schedule App — User Manual
 * Included as a tab inside index.php via showTab('manual').
 * Can also be accessed standalone: manual.php
 * Shows role-appropriate content — employees see a trimmed version.
 */
if (!defined('APP_VERSION')) {
    require_once __DIR__ . '/auth_user_management.php';
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit;
    }
}

$manualRole = $_SESSION['user_role'] ?? 'employee';
$isStaff    = in_array($manualRole, ['admin', 'manager', 'supervisor']);
?>
<style>
/* ============================================================
   USER MANUAL — fixed light-mode palette, immune to all themes.
   All colors are hard-coded with !important so theme CSS
   injected by ThemeSelector cannot override them.
   ============================================================ */

/* Outer shell — white card that resets colour for everything inside */
.man-doc-shell {
    background: #ffffff !important;
    color: #1e293b !important;
    border-radius: 12px !important;
    padding: 24px 28px 48px !important;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08) !important;
}
/* Blanket reset so theme body/color rules can't bleed in */
.man-doc-shell, .man-doc-shell * {
    color: inherit !important;
    background-color: transparent !important;
}

.man-wrap {
    max-width: 960px !important;
    margin: 0 auto !important;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
    font-size: 14px !important;
    line-height: 1.7 !important;
    color: #1e293b !important;
}
.man-header {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    margin-bottom: 32px !important;
    padding-bottom: 18px !important;
    border-bottom: 2px solid #e2e8f0 !important;
}
.man-header h1 {
    margin: 0 !important;
    font-size: 22px !important;
    font-weight: 700 !important;
    color: #0f172a !important;
}
.man-header .man-sub {
    font-size: 13px !important;
    color: #64748b !important;
    margin-top: 2px !important;
}
.man-toc {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 10px !important;
    padding: 18px 22px !important;
    margin-bottom: 36px !important;
}
.man-toc h3 {
    margin: 0 0 12px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    letter-spacing: 0.8px !important;
    text-transform: uppercase !important;
    color: #64748b !important;
}
.man-toc ol {
    margin: 0 !important;
    padding-left: 20px !important;
    columns: 2 !important;
    column-gap: 32px !important;
}
.man-toc li { margin-bottom: 5px !important; }
.man-toc a {
    color: #2563eb !important;
    text-decoration: none !important;
    font-size: 13px !important;
}
.man-toc a:hover { text-decoration: underline !important; }
.man-section {
    margin-bottom: 40px !important;
    scroll-margin-top: 80px !important;
}
.man-section h2 {
    font-size: 17px !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    margin: 0 0 14px !important;
    padding-bottom: 8px !important;
    border-bottom: 1px solid #e2e8f0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}
.man-section h3 {
    font-size: 14px !important;
    font-weight: 700 !important;
    color: #1e293b !important;
    margin: 20px 0 8px !important;
}
.man-section p  { margin: 0 0 10px !important; color: #334155 !important; }
.man-section ul,
.man-section ol { margin: 0 0 12px !important; padding-left: 20px !important; color: #334155 !important; }
.man-section li { margin-bottom: 4px !important; }
.man-table {
    width: 100% !important;
    border-collapse: collapse !important;
    margin: 10px 0 18px !important;
    font-size: 13px !important;
}
.man-table th {
    text-align: left !important;
    padding: 8px 12px !important;
    background: #f1f5f9 !important;
    border: 1px solid #e2e8f0 !important;
    color: #475569 !important;
    font-weight: 600 !important;
    font-size: 11px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}
.man-table td {
    padding: 8px 12px !important;
    border: 1px solid #e2e8f0 !important;
    color: #334155 !important;
    vertical-align: top !important;
}
.man-table tr:nth-child(even) td {
    background: #f8fafc !important;
}
.man-badge {
    display: inline-block !important;
    padding: 2px 8px !important;
    border-radius: 4px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    margin-right: 4px !important;
}
.man-badge-blue  { background: #dbeafe !important; color: #1d4ed8 !important; }
.man-badge-green { background: #dcfce7 !important; color: #15803d !important; }
.man-badge-amber { background: #fef3c7 !important; color: #92400e !important; }
.man-badge-red   { background: #fee2e2 !important; color: #b91c1c !important; }
.man-badge-gray  { background: #f1f5f9 !important; color: #475569 !important; }
.man-note {
    background: #eff6ff !important;
    border-left: 3px solid #2563eb !important;
    border-radius: 0 6px 6px 0 !important;
    padding: 10px 14px !important;
    margin: 12px 0 !important;
    font-size: 13px !important;
    color: #1e3a5f !important;
}
.man-warn {
    background: #fffbeb !important;
    border-left: 3px solid #f59e0b !important;
    border-radius: 0 6px 6px 0 !important;
    padding: 10px 14px !important;
    margin: 12px 0 !important;
    font-size: 13px !important;
    color: #78350f !important;
}
.man-footer {
    padding-top: 20px !important;
    border-top: 1px solid #e2e8f0 !important;
    font-size: 12px !important;
    color: #94a3b8 !important;
    text-align: center !important;
}
.man-section strong { color: #0f172a !important; }
@media(max-width:700px) { .man-toc ol { columns: 1 !important; } }

/* ── Dark-mode overrides ─────────────────────────────────────────────────── */
/* Uses [data-theme="dark"] on <body> (stamped by applyTheme() in script.js).*/
/* Nested selectors give specificity 0,3,0 — beats every 0,1,0 rule above,  */
/* including the blanket "color: inherit !important" wildcard reset.          */

/* Shell — dark card */
[data-theme="dark"] .man-doc-shell {
    background-color: #1e293b !important;
    color: #f1f5f9 !important;
    box-shadow: 0 2px 12px rgba(0,0,0,0.4) !important;
}
/* Blanket inherit so unaddressed children pick up white from the shell */
[data-theme="dark"] .man-doc-shell * {
    color: inherit !important;
}
/* Wrap + all headings */
[data-theme="dark"] .man-doc-shell .man-wrap,
[data-theme="dark"] .man-doc-shell h1,
[data-theme="dark"] .man-doc-shell h2,
[data-theme="dark"] .man-doc-shell h3 {
    color: #f1f5f9 !important;
}
/* Header rule */
[data-theme="dark"] .man-doc-shell .man-header {
    border-bottom-color: #334155 !important;
}
[data-theme="dark"] .man-doc-shell .man-header h1 { color: #f1f5f9 !important; }
[data-theme="dark"] .man-doc-shell .man-header .man-sub { color: #94a3b8 !important; }

/* Table of contents */
[data-theme="dark"] .man-doc-shell .man-toc {
    background: #0f172a !important;
    border-color: #334155 !important;
}
[data-theme="dark"] .man-doc-shell .man-toc h3 { color: #94a3b8 !important; }
[data-theme="dark"] .man-doc-shell .man-toc a { color: #7dd3fc !important; }
[data-theme="dark"] .man-doc-shell .man-toc a:hover { color: #38bdf8 !important; }

/* Section content */
[data-theme="dark"] .man-doc-shell .man-section h2 {
    color: #f1f5f9 !important;
    border-bottom-color: #334155 !important;
}
[data-theme="dark"] .man-doc-shell .man-section h3 { color: #e2e8f0 !important; }
[data-theme="dark"] .man-doc-shell .man-section p  { color: #cbd5e1 !important; }
[data-theme="dark"] .man-doc-shell .man-section ul,
[data-theme="dark"] .man-doc-shell .man-section ol { color: #cbd5e1 !important; }
[data-theme="dark"] .man-doc-shell .man-section li { color: #cbd5e1 !important; }
[data-theme="dark"] .man-doc-shell .man-section strong { color: #f1f5f9 !important; }

/* Tables */
[data-theme="dark"] .man-doc-shell .man-table th {
    background: #0f172a !important;
    color: #94a3b8 !important;
    border-color: #334155 !important;
}
[data-theme="dark"] .man-doc-shell .man-table td {
    color: #cbd5e1 !important;
    border-color: #334155 !important;
}
[data-theme="dark"] .man-doc-shell .man-table tr:nth-child(even) td {
    background: #1e293b !important;
}

/* Badges */
[data-theme="dark"] .man-doc-shell .man-badge-blue   { background: #1e3a5f !important; color: #7dd3fc !important; }
[data-theme="dark"] .man-doc-shell .man-badge-green  { background: #14532d !important; color: #86efac !important; }
[data-theme="dark"] .man-doc-shell .man-badge-amber  { background: #3f2a00 !important; color: #fcd34d !important; }
[data-theme="dark"] .man-doc-shell .man-badge-red    { background: #4c0519 !important; color: #fda4af !important; }
[data-theme="dark"] .man-doc-shell .man-badge-gray   { background: #1e293b !important; color: #94a3b8 !important; }

/* Callout boxes */
[data-theme="dark"] .man-doc-shell .man-note {
    background: #1e3a5f !important;
    border-left-color: #3b82f6 !important;
    color: #bfdbfe !important;
}
[data-theme="dark"] .man-doc-shell .man-warn {
    background: #3f2a00 !important;
    border-left-color: #f59e0b !important;
    color: #fde68a !important;
}

/* Footer */
[data-theme="dark"] .man-doc-shell .man-footer {
    border-top-color: #334155 !important;
    color: #64748b !important;
}
</style>

<div class="man-doc-shell">
<div class="man-wrap">

    <!-- Header -->
    <div class="man-header">
        <span style="font-size:32px;">📖</span>
        <div>
            <h1>Schedule App — User Manual</h1>
            <div class="man-sub">
                Last updated <?php echo date('F Y'); ?> &nbsp;·&nbsp;
                App v<?php echo defined('APP_VERSION') ? APP_VERSION : '4.0'; ?> &nbsp;·&nbsp;
                <?php if ($isStaff): ?>
                    <span class="man-badge man-badge-amber">Staff Guide</span>
                <?php else: ?>
                    <span class="man-badge man-badge-blue">Employee Guide</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($isStaff): ?>
    <!-- ═══════════════════════════════════════════════════════
         STAFF MANUAL  (Admin / Manager / Supervisor)
    ════════════════════════════════════════════════════════ -->

    <div class="man-toc">
        <h3>Contents</h3>
        <ol>
            <li><a href="#s-roles">Roles &amp; Permissions</a></li>
            <li><a href="#s-login">Logging In</a></li>
            <li><a href="#s-nav">Navigation</a></li>
            <li><a href="#s-schedule">Schedule Tab</a></li>
            <li><a href="#s-bulk">Bulk Schedule Changes</a></li>
            <li><a href="#s-heatmap">Heatmap</a></li>
            <li><a href="#s-employees">Managing Employees</a></li>
            <li><a href="#s-settings">Settings</a></li>
            <li><a href="#s-backups">Backups</a></li>
            <li><a href="#s-activitylog">Activity Log</a></li>
            <li><a href="#s-profile">My Profile</a></li>
            <li><a href="#s-mobile">Mobile View</a></li>
        </ol>
    </div>

    <div class="man-section" id="s-roles">
        <h2>1 &nbsp; Roles &amp; Permissions</h2>
        <p>Every user is assigned one of four roles. Admin, Manager, and Supervisor have identical access. Employee access is read-only with limited editing.</p>
        <table class="man-table">
            <thead><tr><th>Role</th><th>What they can do</th></tr></thead>
            <tbody>
                <tr><td><span class="man-badge man-badge-red">Admin</span></td><td>Full access — schedules, employees, users, backups, all teams, all profiles.</td></tr>
                <tr><td><span class="man-badge man-badge-amber">Manager</span></td><td>Identical to Admin.</td></tr>
                <tr><td><span class="man-badge man-badge-blue">Supervisor</span></td><td>Identical to Admin and Manager.</td></tr>
                <tr><td><span class="man-badge man-badge-gray">Employee</span></td><td>View all schedules and heatmap. Edit their own schedule only. View their own profile.</td></tr>
            </tbody>
        </table>
        <div class="man-note">Roles are assigned in <strong>Settings → User Management</strong>. Changes take effect on next login.</div>
    </div>

    <div class="man-section" id="s-login">
        <h2>2 &nbsp; Logging In</h2>
        <h3>Google SSO (standard)</h3>
        <p>Use the <strong>Sign in with Google</strong> button with your company Google account. No password needed — authentication is handled entirely by Google.</p>
        <h3>Username &amp; Password</h3>
        <p>For users without Google SSO access only. Accounts are created manually in Settings via <strong>Add User</strong>. After a successful login, you are redirected to the app immediately.</p>
        <h3>Session timeout</h3>
        <p>Sessions expire after 30 minutes of inactivity and redirect to the login page.</p>
    </div>

    <div class="man-section" id="s-nav">
        <h2>3 &nbsp; Navigation</h2>
        <h3>Desktop sidebar</h3>
        <p>On desktop, all navigation is in the left sidebar. Sections include the main tabs (Schedule, Heatmap, Bulk Changes, User Manual), Management tools (Activity Log, CSV Import/Export), and Admin tools (Backups, Setup Checklist). To access your profile, click your name or avatar at the top of the sidebar to open the account dropdown.</p>
        <h3>Mobile bottom navigation</h3>
        <p>On mobile devices the sidebar is hidden. Use the <strong>bottom navigation bar</strong> to switch between Schedule, Heatmap, Bulk, Settings, and Help tabs. See the <a href="#s-mobile">Mobile View</a> section for full details.</p>
    </div>

    <div class="man-section" id="s-schedule">
        <h2>4 &nbsp; Schedule Tab</h2>
        <p>The main view — a monthly grid with one row per employee and one column per day. Today's column is highlighted. Your own row (if your account is linked to an employee record) is marked with a <strong>YOU</strong> badge.</p>
        <h3>Employee details card</h3>
        <p>Hover over any employee's name cell to see a quick-view card with their team, shift, hours, skills, supervisor, and linked user account.</p>
        <h3>Filters</h3>
        <p>Filter the employee list by Shift, Level, Supervisor, Skills (MH/MA/Win), Team, or search by name/email. Filters persist across page navigation within the same session.</p>
        <h3>Schedule status types</h3>
        <table class="man-table">
            <thead><tr><th>Status</th><th>Meaning</th></tr></thead>
            <tbody>
                <tr><td><span class="man-badge man-badge-green">ON</span></td><td>Working their normal shift.</td></tr>
                <tr><td><span class="man-badge man-badge-gray">OFF</span></td><td>Off — outside normal schedule or day off.</td></tr>
                <tr><td><span class="man-badge man-badge-blue">PTO</span></td><td>Paid time off.</td></tr>
                <tr><td><span class="man-badge man-badge-red">SICK</span></td><td>Sick day.</td></tr>
                <tr><td><span class="man-badge man-badge-amber">HOLIDAY</span></td><td>Company holiday.</td></tr>
                <tr><td><span class="man-badge man-badge-blue">CUSTOM HOURS</span></td><td>Working different hours than their base shift. The custom hours are stored as a note on the day.</td></tr>
            </tbody>
        </table>
        <h3>Editing a day</h3>
        <p>Click any cell to open the edit panel. Change status, enter custom hours, add a comment, then save. Cells with a comment attached show a visual indicator.</p>
        <h3>Shift assignments</h3>
        <p><strong>Change Now</strong> — updates immediately. <strong>Change on Start Date</strong> — updates from a future date.</p>
        <h3>Weekly schedule</h3>
        <p>Each employee has a base weekly schedule (which days they work). Set when adding or editing the employee. Days that fall outside the base schedule default to OFF unless overridden.</p>
    </div>

    <div class="man-section" id="s-bulk">
        <h2>5 &nbsp; Bulk Schedule Changes</h2>
        <p>Desktop: Sidebar <strong>Management → Bulk Changes</strong>. Mobile: <strong>Bulk</strong> tab in the bottom nav bar. Apply the same change to multiple employees across a date range.</p>
        <ol>
            <li>Set a Start Date and End Date.</li>
            <li>Select one or more employees.</li>
            <li>Choose a Status (PTO, Sick, Off, Holiday, Custom Hours, On).</li>
            <li>Optionally enter custom hours, a comment, or a new shift assignment.</li>
            <li>Check <strong>Skip days off</strong> to only affect scheduled working days.</li>
            <li>Click <strong>Apply Bulk Changes</strong>.</li>
        </ol>
        <div class="man-warn">Bulk changes cannot be undone from the UI. Create a backup before large bulk operations.</div>
        <h3>CSV import/export</h3>
        <p>Download the schedule as CSV or upload a CSV to import changes in bulk. Validation errors are shown before any changes are committed.</p>
    </div>

    <div class="man-section" id="s-heatmap">
        <h2>6 &nbsp; Heatmap</h2>
        <p>Shows employee coverage density across days and times. Darker cells mean more employees are working during that period. Filter by team, level, supervisor, shift, date range, time range, and day of week. Use it to spot coverage gaps or plan time-off approvals.</p>
    </div>

    <div class="man-section" id="s-employees">
        <h2>7 &nbsp; Managing Employees</h2>
        <h3>Adding an employee</h3>
        <p>Sidebar: <strong>Add Employee</strong>. Fill in name, email, team, shift, default hours, weekly schedule, skills, and supervisor.</p>
        <h3>Editing an employee</h3>
        <p>Click the ⚙️ gear icon on any employee's name cell in the schedule grid. Update any field including hire date, level, notes, and active/inactive status.</p>
        <h3>Skills</h3>
        <p><strong>MH</strong> — Managed Hosting &nbsp; <strong>MA</strong> — Managed Applications &nbsp; <strong>Win</strong> — Windows. Skill badges are displayed directly on each employee's name cell in the schedule grid and are usable as a filter.</p>
        <h3>Supervisor assignment</h3>
        <p>Assign a supervisor to each employee to enable the Supervisor filter on the schedule tab and to display "Reports to" on the employee hover card.</p>
        <h3>Setup Checklist</h3>
        <p>Sidebar: <strong>Admin → Setup Checklist</strong>. Step-by-step guide for correctly configuring a new employee.</p>
    </div>

    <div class="man-section" id="s-settings">
        <h2>8 &nbsp; Settings</h2>
        <h3>Scheduled Messages</h3>
        <p>Create announcements that scroll in the ticker at the top of every page. Each message can have text, optional start/end dates, and a work anniversaries flag. The global <strong>Anniversaries toggle</strong> (🎉) shows today's work anniversaries in the ticker regardless of individual message settings.</p>
        <h3>Email Export Tools</h3>
        <p><strong>Export All to TXT</strong> downloads a file of all user emails. <strong>Copy All Emails</strong> copies them to clipboard as a semicolon-separated list.</p>
        <h3>User Management</h3>
        <p>Lists all user accounts. Search and filter by Role, Level, Status, or Employee Link. Actions: Edit, Delete, View Profile, Link to Employee.</p>
        <h3>Add User</h3>
        <p>Creates a username/password account. Only for users without Google SSO access.</p>
        <div class="man-note">Deleting a user account does not delete their employee record. Employee records are managed separately on the Schedule tab.</div>
    </div>

    <div class="man-section" id="s-backups">
        <h2>9 &nbsp; Backups</h2>
        <p>Sidebar: <strong>Admin → Backups</strong>.</p>
        <h3>Creating a backup</h3>
        <p>Click <strong>Create New Backup</strong>. A snapshot of all employees and schedule overrides is saved instantly. Add a description to identify it later.</p>
        <h3>Restoring a backup</h3>
        <p>Click <strong>Restore</strong> on any backup. The app auto-creates a pre-restore snapshot of current data first so you can recover if needed.</p>
        <div class="man-warn">Restoring replaces all current employee and schedule data. It cannot be undone except by restoring the pre-restore snapshot that was auto-created.</div>
        <table class="man-table">
            <thead><tr><th>Type</th><th>When created</th></tr></thead>
            <tbody>
                <tr><td>Manual</td><td>Created by clicking "Create New Backup".</td></tr>
                <tr><td>Pre-Restore</td><td>Auto-created before a restore operation.</td></tr>
                <tr><td>Pre-Cleanup</td><td>Auto-created before running the cleanup tool.</td></tr>
                <tr><td>JSON Import</td><td>Created when a JSON or ZIP backup file is uploaded.</td></tr>
            </tbody>
        </table>
        <h3>Upload / Download</h3>
        <p><strong>Upload Backup</strong> restores from a .json or .zip file. <strong>Download Live Data</strong> exports the current schedule as JSON for off-site storage or moving between environments.</p>
    </div>

    <div class="man-section" id="s-activitylog">
        <h2>10 &nbsp; Activity Log</h2>
        <p>Sidebar: <strong>Management → Activity Log</strong>. Records every significant action — who did it, what changed, and when. Tracks employee add/edit/delete, schedule changes, shift changes, bulk changes, CSV uploads, backup operations, MOTD changes, and profile updates.</p>
    </div>

    <div class="man-section" id="s-profile">
        <h2>11 &nbsp; My Profile</h2>
        <p>Sidebar bottom (desktop) or <strong>Settings</strong> tab in the bottom nav (mobile). View your name, email, and profile photo. Admins and Managers can view any user's profile via <strong>View Profile</strong> in User Management.</p>
        <h3>Profile photo</h3>
        <p>Profile photos are automatically synced from your Google account. No manual upload is required or supported — the photo displayed is always your current Google profile picture.</p>
        <h3>Password</h3>
        <p>Password changes only apply to username/password accounts (non-Google SSO). If you signed in with Google, your password is managed through Google and cannot be changed here.</p>
        <div class="man-note">If your account is not linked to an employee record, ask an Admin to link it in Settings → User Management.</div>
    </div>

    <div class="man-section" id="s-mobile">
        <h2>12 &nbsp; Mobile View</h2>
        <p>When accessed on a phone or narrow screen, the app switches to a purpose-built mobile layout.</p>
        <h3>Bottom navigation bar</h3>
        <p>The sidebar is hidden on mobile. Use the fixed bar at the bottom of the screen to switch between <strong>Schedule</strong>, <strong>Heatmap</strong>, <strong>Bulk</strong>, <strong>Settings</strong>, and <strong>Help</strong> tabs.</p>
        <h3>Filter strip &amp; drawer</h3>
        <p>A compact filter bar appears at the top of the page with a search box and a <strong>⚙️ Filters</strong> button. Tapping Filters opens a drawer where you can select Month, Team, and Shift, then tap <strong>Apply</strong>. Tap <strong>Clear</strong> to reset all filters.</p>
        <h3>Schedule grid</h3>
        <p>The schedule table scrolls horizontally. The employee name column stays fixed (sticky) on the left while you scroll through the day columns. Tap any editable cell to open the edit panel as normal.</p>
        <div class="man-note">All editing and management functions work identically on mobile — only the navigation and filter layout changes.</div>
    </div>

    <?php else: ?>
    <!-- ═══════════════════════════════════════════════════════
         EMPLOYEE MANUAL
    ════════════════════════════════════════════════════════ -->

    <div class="man-toc">
        <h3>Contents</h3>
        <ol>
            <li><a href="#e-login">Logging In</a></li>
            <li><a href="#e-nav">Getting Around</a></li>
            <li><a href="#e-schedule">Viewing the Schedule</a></li>
            <li><a href="#e-own">Editing Your Own Schedule</a></li>
            <li><a href="#e-heatmap">Heatmap</a></li>
            <li><a href="#e-profile">My Profile</a></li>
            <li><a href="#e-mobile">Mobile View</a></li>
        </ol>
    </div>

    <div class="man-section" id="e-login">
        <h2>1 &nbsp; Logging In</h2>
        <p>Use the <strong>Sign in with Google</strong> button with your company Google account. Authentication is handled by Google — no separate app password is needed. After signing in you are taken directly to the schedule.</p>
        <p>Sessions expire after 30 minutes of inactivity. You will be redirected to the login page and must sign in again.</p>
    </div>

    <div class="man-section" id="e-nav">
        <h2>2 &nbsp; Getting Around</h2>
        <h3>Desktop</h3>
        <p>Use the left sidebar to switch between tabs: <strong>Schedule</strong>, <strong>Heatmap</strong>, and <strong>User Manual</strong>. To access your profile, click your name or avatar at the top of the sidebar to open the account dropdown.</p>
        <h3>Mobile</h3>
        <p>On a phone, the sidebar is hidden. Use the <strong>bottom navigation bar</strong> to switch between Schedule, Heatmap, Settings, and Help. See the <a href="#e-mobile">Mobile View</a> section for more.</p>
    </div>

    <div class="man-section" id="e-schedule">
        <h2>3 &nbsp; Viewing the Schedule</h2>
        <p>The Schedule tab shows a monthly grid with one row per employee and one column per day. You can see the full schedule for all teams. Today's column is highlighted, and your own row is marked with a <strong>YOU</strong> badge so it's easy to find.</p>
        <h3>Navigating months</h3>
        <p>Use the month and year selectors at the top of the page to move between months.</p>
        <h3>Employee details</h3>
        <p>Hover over any employee's name to see a quick-view card showing their team, shift, hours, skills, and supervisor.</p>
        <h3>Filters</h3>
        <p>Use the filter bar to narrow the view by shift, team, level, supervisor, or skills. Type in the search box to find a specific employee by name or email.</p>
        <h3>Schedule status types</h3>
        <table class="man-table">
            <thead><tr><th>Status</th><th>Meaning</th></tr></thead>
            <tbody>
                <tr><td><span class="man-badge man-badge-green">ON</span></td><td>Working their normal shift.</td></tr>
                <tr><td><span class="man-badge man-badge-gray">OFF</span></td><td>Off for the day.</td></tr>
                <tr><td><span class="man-badge man-badge-blue">PTO</span></td><td>Paid time off.</td></tr>
                <tr><td><span class="man-badge man-badge-red">SICK</span></td><td>Sick day.</td></tr>
                <tr><td><span class="man-badge man-badge-amber">HOLIDAY</span></td><td>Company holiday.</td></tr>
                <tr><td><span class="man-badge man-badge-blue">CUSTOM HOURS</span></td><td>Working different hours than their normal shift for that day.</td></tr>
            </tbody>
        </table>
    </div>

    <div class="man-section" id="e-own">
        <h2>4 &nbsp; Editing Your Own Schedule</h2>
        <p>If your user account is linked to your employee record, you can edit your own schedule days. Your row is marked with a <strong>YOU</strong> badge. Click any cell in your row to open the edit panel — you can change the status, set custom hours, or add a comment, then save.</p>
        <div class="man-note">You can only edit your own row. Changes to other employees' schedules must be made by a Supervisor, Manager, or Admin.</div>
        <p>If you cannot edit your own row, your account may not be linked to your employee record yet. Contact your supervisor or an Admin to have it linked in Settings.</p>
    </div>

    <div class="man-section" id="e-heatmap">
        <h2>5 &nbsp; Heatmap</h2>
        <p>The Heatmap tab shows a visual overview of team coverage across days and times. Darker cells mean more employees are working during that window. Use it to see when the team is most or least staffed.</p>
        <p>You can filter the heatmap by team, shift, level, date range, and time range.</p>
    </div>

    <div class="man-section" id="e-profile">
        <h2>6 &nbsp; My Profile</h2>
        <p>Click your name or avatar in the sidebar to open the account dropdown, then select <strong>My Profile</strong>. On mobile, use the <strong>Settings</strong> tab in the bottom nav bar.</p>
        <h3>Profile photo</h3>
        <p>Your profile photo is automatically synced from your Google account — it shows your current Google profile picture. No manual upload is needed.</p>
        <h3>Name &amp; email</h3>
        <p>You can update your display name and email address from the profile page.</p>
        <h3>Password</h3>
        <p>Password changes only apply if you have a username/password account. If you sign in with Google, your password is managed through your Google account and cannot be changed here.</p>
        <div class="man-note">Your role and team are set by an Admin and cannot be changed from your profile.</div>
    </div>

    <div class="man-section" id="e-mobile">
        <h2>7 &nbsp; Mobile View</h2>
        <p>On a phone or narrow screen the app switches to a mobile-friendly layout designed for touch.</p>
        <h3>Bottom navigation bar</h3>
        <p>The sidebar is replaced by a fixed bar at the bottom of the screen. Tap <strong>Schedule</strong>, <strong>Heatmap</strong>, <strong>Settings</strong>, or <strong>Help</strong> to switch views.</p>
        <h3>Filters</h3>
        <p>A compact bar at the top of the page has a search box. Tap <strong>⚙️ Filters</strong> to open a drawer where you can pick the Month, Team, and Shift. Tap <strong>Apply</strong> to update the view, or <strong>Clear</strong> to reset.</p>
        <h3>Schedule grid</h3>
        <p>The schedule table scrolls left and right. The employee name column stays fixed on the left so you always know which row you're looking at. Tap any cell in your own row to edit it as normal.</p>
    </div>

    <?php endif; ?>

    <div class="man-footer">
        Schedule App v<?php echo defined('APP_VERSION') ? APP_VERSION : '4.0'; ?> &nbsp;·&nbsp; For internal use. Store in Waypoint for team reference.
    </div>

</div><!-- /.man-wrap -->
</div><!-- /.man-doc-shell -->
