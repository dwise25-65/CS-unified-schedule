const {
  Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
  HeadingLevel, AlignmentType, BorderStyle, WidthType, ShadingType,
  VerticalAlign, PageNumber, Header, Footer, PageBreak
} = require('docx');
const fs = require('fs');

// ── Colours ──────────────────────────────────────────────────────────────────
const C = {
  darkBlue:   '1F3864',
  midBlue:    '2E75B6',
  lightBlue:  'D5E8F0',
  paleBlue:   'EBF3FB',
  green:      '375623',
  greenLight: 'E2EFDA',
  amber:      '7F6000',
  amberLight: 'FFF2CC',
  red:        '9C0006',
  redLight:   'FFC7CE',
  grey:       'F2F2F2',
  midGrey:    'D9D9D9',
  darkGrey:   '595959',
  white:      'FFFFFF',
  black:      '000000',
};

// ── Border helpers ────────────────────────────────────────────────────────────
const b = (color = C.midGrey, size = 4) => ({ style: BorderStyle.SINGLE, size, color });
const borders = (color = C.midGrey) => ({ top: b(color), bottom: b(color), left: b(color), right: b(color) });
const noBorder = () => ({ style: BorderStyle.NONE, size: 0, color: 'FFFFFF' });
const noBorders = () => ({ top: noBorder(), bottom: noBorder(), left: noBorder(), right: noBorder() });

// ── Cell factories ────────────────────────────────────────────────────────────
function cell(text, w, opts = {}) {
  return new TableCell({
    width: { size: w, type: WidthType.DXA },
    borders: opts.borders || borders(opts.borderColor),
    shading: opts.fill ? { fill: opts.fill, type: ShadingType.CLEAR } : undefined,
    verticalAlign: VerticalAlign.TOP,
    margins: { top: 80, bottom: 80, left: 120, right: 120 },
    children: [new Paragraph({
      children: [new TextRun({
        text: String(text),
        bold: opts.bold || false,
        color: opts.color || C.black,
        size: opts.size || 18,
        font: 'Arial',
      })],
      alignment: opts.align || AlignmentType.LEFT,
    })],
  });
}

function headerCell(text, w, fill = C.darkBlue) {
  return cell(text, w, { bold: true, fill, color: C.white, borders: borders(fill), size: 18 });
}

// ── Paragraph helpers ─────────────────────────────────────────────────────────
function h1(text) {
  return new Paragraph({
    heading: HeadingLevel.HEADING_1,
    children: [new TextRun({ text, font: 'Arial', size: 36, bold: true, color: C.darkBlue })],
    spacing: { before: 320, after: 160 },
  });
}
function h2(text) {
  return new Paragraph({
    heading: HeadingLevel.HEADING_2,
    children: [new TextRun({ text, font: 'Arial', size: 26, bold: true, color: C.midBlue })],
    spacing: { before: 240, after: 100 },
  });
}
function h3(text) {
  return new Paragraph({
    children: [new TextRun({ text, font: 'Arial', size: 22, bold: true, color: C.darkGrey })],
    spacing: { before: 200, after: 80 },
  });
}
function para(text, opts = {}) {
  return new Paragraph({
    children: [new TextRun({ text, font: 'Arial', size: opts.size || 18, color: opts.color || C.black, bold: opts.bold || false, italics: opts.italic || false })],
    spacing: { before: opts.before || 60, after: opts.after || 60 },
    alignment: opts.align || AlignmentType.LEFT,
  });
}
function spacer(n = 1) {
  return Array.from({ length: n }, () => new Paragraph({ children: [new TextRun({ text: '' })], spacing: { before: 60, after: 60 } }));
}

// ── Status badge cell ─────────────────────────────────────────────────────────
function statusCell(w) {
  return new TableCell({
    width: { size: w, type: WidthType.DXA },
    borders: borders(),
    shading: { fill: C.grey, type: ShadingType.CLEAR },
    margins: { top: 80, bottom: 80, left: 120, right: 120 },
    children: [new Paragraph({
      children: [new TextRun({ text: '☐ Pass   ☐ Fail   ☐ N/A', font: 'Arial', size: 16, color: C.darkGrey })],
    })],
  });
}
function notesCell(w) {
  return new TableCell({
    width: { size: w, type: WidthType.DXA },
    borders: borders(),
    margins: { top: 80, bottom: 80, left: 120, right: 120 },
    children: [new Paragraph({ children: [new TextRun({ text: '', font: 'Arial', size: 18 })] })],
  });
}

// ── Test row ──────────────────────────────────────────────────────────────────
// Cols: ID(900) | Test Case(3200) | Steps(3000) | Expected(1960) | Status(1200) | Notes(1100) = 11360
const COL = [900, 3200, 3000, 1960, 1200, 1100];
const TOTAL = COL.reduce((a, b) => a + b, 0); // 11360

function testRow(id, testCase, steps, expected, fill) {
  return new TableRow({
    children: [
      cell(id, COL[0], { fill: fill || C.white, bold: true, size: 16 }),
      cell(testCase, COL[1], { fill: fill || C.white }),
      cell(steps, COL[2], { fill: fill || C.white, color: C.darkGrey }),
      cell(expected, COL[3], { fill: fill || C.white }),
      statusCell(COL[4]),
      notesCell(COL[5]),
    ],
  });
}

function sectionRow(label) {
  return new TableRow({
    children: [
      new TableCell({
        columnSpan: 6,
        width: { size: TOTAL, type: WidthType.DXA },
        borders: borders(C.midBlue),
        shading: { fill: C.lightBlue, type: ShadingType.CLEAR },
        margins: { top: 80, bottom: 80, left: 120, right: 120 },
        children: [new Paragraph({
          children: [new TextRun({ text: label, font: 'Arial', size: 20, bold: true, color: C.darkBlue })],
        })],
      }),
    ],
  });
}

function tableHeader() {
  return new TableRow({
    tableHeader: true,
    children: [
      headerCell('ID', COL[0]),
      headerCell('Test Case', COL[1]),
      headerCell('Steps to Execute', COL[2]),
      headerCell('Expected Result', COL[3]),
      headerCell('Pass/Fail', COL[4]),
      headerCell('Notes / Defect', COL[5]),
    ],
  });
}

// ── Cover page ────────────────────────────────────────────────────────────────
function coverPage() {
  return [
    // coloured top bar
    new Table({
      width: { size: TOTAL, type: WidthType.DXA },
      columnWidths: [TOTAL],
      rows: [new TableRow({ children: [new TableCell({
        width: { size: TOTAL, type: WidthType.DXA },
        borders: noBorders(),
        shading: { fill: C.darkBlue, type: ShadingType.CLEAR },
        margins: { top: 400, bottom: 400, left: 400, right: 400 },
        children: [
          new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ text: 'EMPLOYEE SCHEDULING SYSTEM', font: 'Arial', size: 48, bold: true, color: C.white })] }),
          new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ text: 'User Acceptance Testing (UAT) Test Plan', font: 'Arial', size: 30, color: 'BDD7EE' })] }),
        ],
      })] })],
    }),
    ...spacer(2),
    // meta table
    new Table({
      width: { size: 7200, type: WidthType.DXA },
      columnWidths: [2400, 4800],
      rows: [
        ['Project', 'Employee Scheduling System'],
        ['Version', '1.0'],
        ['Date', new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })],
        ['Prepared by', 'Darren / dwise@liquidweb.com'],
        ['Environment', 'Staging / UAT'],
        ['Test Type', 'User Acceptance Testing'],
      ].map(([k, v]) => new TableRow({ children: [
        cell(k, 2400, { fill: C.lightBlue, bold: true }),
        cell(v, 4800),
      ]})),
    }),
    ...spacer(2),
    new Paragraph({ children: [new PageBreak()] }),
  ];
}

// ── Summary table (at end) ────────────────────────────────────────────────────
function summaryTable() {
  return [
    h2('UAT Sign-Off Summary'),
    ...spacer(1),
    new Table({
      width: { size: TOTAL, type: WidthType.DXA },
      columnWidths: [2800, 1400, 1400, 1400, 4360],
      rows: [
        new TableRow({ children: [
          headerCell('Test Area', 2800),
          headerCell('Total', 1400),
          headerCell('Pass', 1400),
          headerCell('Fail', 1400),
          headerCell('Comments', 4360),
        ]}),
        ...['Authentication & Login','Schedule View','Schedule Editing','Bulk Schedule Changes',
          'Heatmap','Employee Management','User Management','Settings & MOTD',
          'Backups & Restore','Activity Log','Profile','User Manual','Logout'].map(area =>
          new TableRow({ children: [
            cell(area, 2800),
            cell('', 1400, { align: AlignmentType.CENTER }),
            cell('', 1400, { align: AlignmentType.CENTER }),
            cell('', 1400, { align: AlignmentType.CENTER }),
            cell('', 4360),
          ]}),
        ),
        new TableRow({ children: [
          cell('TOTAL', 2800, { bold: true, fill: C.grey }),
          cell('', 1400, { bold: true, fill: C.grey, align: AlignmentType.CENTER }),
          cell('', 1400, { bold: true, fill: C.greenLight, align: AlignmentType.CENTER }),
          cell('', 1400, { bold: true, fill: C.redLight, align: AlignmentType.CENTER }),
          cell('', 4360, { fill: C.grey }),
        ]}),
      ],
    }),
    ...spacer(2),
    new Table({
      width: { size: TOTAL, type: WidthType.DXA },
      columnWidths: [2000, 4680, 4680],
      rows: [
        new TableRow({ children: [
          headerCell('Role', 2000),
          headerCell('Name', 4680),
          headerCell('Signature & Date', 4680),
        ]}),
        ...['UAT Lead','Business Owner','IT / Dev Lead','QA Approver'].map(r =>
          new TableRow({ children: [
            cell(r, 2000, { bold: true, fill: C.paleBlue }),
            cell('', 4680),
            cell('', 4680),
          ]}),
        ),
      ],
    }),
  ];
}

// ── Main test table ───────────────────────────────────────────────────────────
const rows = [
  // ── 1. AUTHENTICATION ──────────────────────────────────────────────────────
  sectionRow('1. Authentication & Login'),
  testRow('TC-001', 'Google SSO Login', '1. Navigate to app URL\n2. Click "Sign in with Google"\n3. Authenticate with valid Google account', 'User is logged in and redirected to schedule. Role and name shown in sidebar.'),
  testRow('TC-002', 'Local Password Login', '1. Navigate to app URL\n2. Enter username and password for a local (non-Google) account\n3. Click Login', 'User is authenticated and lands on schedule page.'),
  testRow('TC-003', 'Invalid Credentials', '1. Enter incorrect username or password\n2. Click Login', 'Error message shown: "Invalid username or password." User stays on login page.'),
  testRow('TC-004', 'Logout', '1. Click "Logout" in the sidebar\n2. Observe redirect', 'Session is cleared. User is redirected to login page and cannot navigate back without re-authenticating.'),
  testRow('TC-005', 'Session Expiry', '1. Log in\n2. Leave session idle for timeout period\n3. Attempt to navigate', 'Session expires; user is redirected to login with timeout message.'),

  // ── 2. SCHEDULE VIEW ──────────────────────────────────────────────────────
  sectionRow('2. Schedule View'),
  testRow('TC-006', 'Schedule Grid Loads', '1. Log in as any role\n2. Click Schedule tab', 'Full schedule grid renders with correct month, employee rows, and day columns.'),
  testRow('TC-007', 'Month Navigation (Previous)', '1. On Schedule tab\n2. Click left arrow / Previous Month', 'Grid updates to prior month. Header shows correct month/year.'),
  testRow('TC-008', 'Month Navigation (Next)', '1. On Schedule tab\n2. Click right arrow / Next Month', 'Grid updates to next month. Header shows correct month/year.'),
  testRow('TC-009', 'Today Highlighted', '1. Navigate to current month', "Today's column is visually highlighted (different background or border)."),
  testRow('TC-010', 'Employee Row Filter by Team', '1. Use team filter/dropdown on schedule\n2. Select a specific team', 'Only employees from that team are shown in the grid.'),
  testRow('TC-011', 'Shift Legend Visible', '1. View the schedule grid', 'Shift legend/key is visible and colour-coded correctly.'),
  testRow('TC-012', 'Hover Employee Card', '1. Hover over an employee name in the grid', 'Tooltip or hover card appears showing employee details (shift, level, skills).'),

  // ── 3. SCHEDULE EDITING ───────────────────────────────────────────────────
  sectionRow('3. Schedule Editing'),
  testRow('TC-013', 'Admin: Edit Any Cell', '1. Log in as Admin/Manager/Supervisor\n2. Click a schedule cell\n3. Change the shift value\n4. Save', 'Cell updates with new value. Change persists on page reload.'),
  testRow('TC-014', 'Employee: Edit Own Row Only', '1. Log in as Employee\n2. Click a cell in your own row\n3. Attempt to edit another employee\'s cell', 'Employee can edit own cells only. Other cells are read-only or ignored.'),
  testRow('TC-015', 'Override Saves Correctly', '1. Change a schedule cell from default\n2. Reload page\n3. Navigate back to same month', 'Override value is retained. Default schedule is not shown instead.'),
  testRow('TC-016', 'Clear Override (Reset to Default)', '1. Edit a cell that has an override\n2. Clear/reset to default', 'Cell reverts to employee\'s default weekly schedule. Override removed from DB.'),

  // ── 4. BULK SCHEDULE CHANGES ──────────────────────────────────────────────
  sectionRow('4. Bulk Schedule Changes'),
  testRow('TC-017', 'Bulk Schedule Tab Loads', '1. Log in as Admin/Manager/Supervisor\n2. Click Bulk Changes in sidebar', 'Bulk Changes form loads with team/date range/shift options.'),
  testRow('TC-018', 'Apply Bulk Change', '1. Select a team\n2. Select date range\n3. Select shift type\n4. Click Apply', 'All affected employees in that team have schedule updated for date range. Confirmation message shown.'),
  testRow('TC-019', 'Bulk Change Scope (Team Only)', '1. Apply bulk change to one team\n2. Check another team\'s schedule', 'Other teams are unaffected.'),

  // ── 5. HEATMAP ────────────────────────────────────────────────────────────
  sectionRow('5. Heatmap'),
  testRow('TC-020', 'Heatmap Tab Loads', '1. Log in (any role)\n2. Click Heatmap in sidebar', 'Heatmap renders with colour-coded coverage by day/hour.'),
  testRow('TC-021', 'Heatmap Reflects Current Month', '1. Open Heatmap\n2. Compare to schedule for same month', 'Coverage totals on heatmap match staffed days in schedule grid.'),
  testRow('TC-022', 'Heatmap Month Navigation', '1. Use prev/next on heatmap\n2. Check different month', 'Heatmap updates to reflect correct month data.'),

  // ── 6. EMPLOYEE MANAGEMENT ────────────────────────────────────────────────
  sectionRow('6. Employee Management'),
  testRow('TC-023', 'Add Employee', '1. Go to Settings > Add Employee\n2. Fill in Name, Team, Shift, Hours, Level\n3. Save', 'Employee appears in schedule grid. Record saved in DB.'),
  testRow('TC-024', 'Edit Employee', '1. Find employee in list\n2. Click Edit\n3. Change a field (e.g. shift or level)\n4. Save', 'Changes reflected immediately in employee list and schedule grid.'),
  testRow('TC-025', 'Deactivate Employee', '1. Edit an employee\n2. Set Active = false / deactivate\n3. Save', 'Employee no longer appears in schedule grid. Still in DB.'),
  testRow('TC-026', 'Employee Level Shown in User Table', '1. Go to Settings > User Management\n2. View user table', 'Level column shows linked employee\'s level (not Team column).'),
  testRow('TC-027', 'Link Employee to User', '1. Find a user with no employee link\n2. Use Link button\n3. Select employee record', 'User is linked to employee. Linked employee section shows in profile.'),

  // ── 7. USER MANAGEMENT ────────────────────────────────────────────────────
  sectionRow('7. User Management'),
  testRow('TC-028', 'Add User (Google SSO)', '1. Click Add User\n2. Set Auth Method = Google SSO only\n3. Fill username, email, role\n4. Submit', 'User created in DB with auth_method = google. No password stored. User appears in list.'),
  testRow('TC-029', 'Add User (Password Only)', '1. Click Add User\n2. Set Auth Method = Password only\n3. Fill all fields including password\n4. Submit', 'User created in DB with auth_method = local. Password hash stored. User can log in with credentials.'),
  testRow('TC-030', 'Add User (Google + Password)', '1. Click Add User\n2. Set Auth Method = Google SSO + Password\n3. Fill all fields including password\n4. Submit', 'User created with auth_method = both. Can log in via either method.'),
  testRow('TC-031', 'Password Field Toggle', '1. Open Add User modal\n2. Switch between auth method options', 'Password field appears only when "Password only" or "Google + Password" selected. Hidden for Google SSO only.'),
  testRow('TC-032', 'Duplicate Username Rejected', '1. Try to add a user with an existing username', 'Error: "Username or email already exists." No duplicate created.'),
  testRow('TC-033', 'Edit User Role', '1. Click edit (pencil) on a user\n2. Change role\n3. Save', 'Role updated in DB. Change reflected immediately in user table.'),
  testRow('TC-034', 'Edit User Password', '1. Edit a local user\n2. Enter new password in New Password field\n3. Save', 'Password hash updated. User can log in with new password.'),
  testRow('TC-035', 'Delete User', '1. Click delete (trash) on a user\n2. Confirm prompt', 'User removed from DB. No longer appears in list on page reload.'),
  testRow('TC-036', 'Cannot Delete Own Account', '1. Log in as admin\n2. Try to delete your own account', 'Error: "You cannot delete your own account." Account is preserved.'),
  testRow('TC-037', 'Role/Level Filter in Search Bar', '1. Use Role or Level dropdown in User Management search bar\n2. Select a value', 'User table filters to show only matching users.'),
  testRow('TC-038', 'User Search by Name/Email', '1. Type in user search field', 'Table filters in real-time to matching names or emails.'),

  // ── 8. SETTINGS & MOTD ────────────────────────────────────────────────────
  sectionRow('8. Settings & Message of the Day (MOTD)'),
  testRow('TC-039', 'Add MOTD Message', '1. Go to Settings\n2. Click Add Message\n3. Enter message text, optional dates\n4. Save', 'Message appears in MOTD list and in the ticker on the schedule page.'),
  testRow('TC-040', 'Edit MOTD Message', '1. Click Edit on an existing MOTD\n2. Modify text or dates\n3. Click Update Message', 'Changes saved. Updated message shown in list and ticker.'),
  testRow('TC-041', 'Delete MOTD Message', '1. Click Delete on a MOTD\n2. Confirm', 'Message removed from list and no longer appears in ticker.'),
  testRow('TC-042', 'MOTD Date Restriction', '1. Add MOTD with start/end date range\n2. View ticker inside and outside date range', 'Message only appears in ticker when current date is within the range.'),
  testRow('TC-043', 'Anniversary Toggle', '1. Toggle "Show Work Anniversaries" on/off\n2. Check ticker', 'Work anniversary entries appear/disappear from ticker accordingly.'),
  testRow('TC-044', 'Current Year/Month Setting', '1. Go to Settings\n2. Change the current month/year\n3. Save', 'Schedule grid updates to reflect newly set month/year.'),

  // ── 9. BACKUPS & RESTORE ──────────────────────────────────────────────────
  sectionRow('9. Backups & Restore'),
  testRow('TC-045', 'Create Backup', '1. Go to Backups tab\n2. Click Create Backup / Download Snapshot', 'Backup file downloaded. Contains employee and schedule data.'),
  testRow('TC-046', 'Restore from Backup', '1. Go to Backups tab\n2. Upload a valid backup file\n3. Confirm restore', 'Data restored successfully. Employees and schedules match backup. Success message shown.'),
  testRow('TC-047', 'Invalid Backup Rejected', '1. Try to upload an invalid or corrupt file as backup', 'Error message shown. Existing data is NOT overwritten.'),
  testRow('TC-048', 'Import from JSON', '1. Use Import from JSON function\n2. Upload a valid JSON export\n3. Confirm', 'Data imported. Settings (year/month) updated. Employees restored correctly.'),

  // ── 10. ACTIVITY LOG ──────────────────────────────────────────────────────
  sectionRow('10. Activity Log'),
  testRow('TC-049', 'Activity Log Opens', '1. Click Activity Log in sidebar', 'Dropdown/panel opens showing recent activity entries.'),
  testRow('TC-050', 'Login Event Logged', '1. Log in\n2. Open Activity Log', 'Login event appears with timestamp and username.'),
  testRow('TC-051', 'User Add/Edit/Delete Logged', '1. Add, edit, or delete a user\n2. Check Activity Log', 'Corresponding event appears in log with action, user, and timestamp.'),
  testRow('TC-052', 'MOTD Change Logged', '1. Add or edit a MOTD\n2. Check Activity Log', 'motd_add or motd_update event visible in log.'),

  // ── 11. PROFILE ───────────────────────────────────────────────────────────
  sectionRow('11. My Profile'),
  testRow('TC-053', 'Profile Tab Loads', '1. Click My Profile in sidebar', 'Profile page shows name, email, username, role, team, and linked employee info.'),
  testRow('TC-054', 'Profile Photo from Google', '1. Log in with Google SSO\n2. View My Profile', 'Google profile photo displayed. No upload button shown.'),
  testRow('TC-055', 'Edit Profile — Full Name', '1. Click Edit on My Profile\n2. Change Full Name\n3. Save', 'Name updated in DB and reflected in sidebar/header.'),
  testRow('TC-056', 'Edit Profile — Email', '1. Click Edit on My Profile\n2. Change email\n3. Save', 'Email updated in DB.'),
  testRow('TC-057', 'Change Password (Local Account)', '1. Edit profile for a local account\n2. Enter current and new password\n3. Save', 'Password updated. Can log in with new password. Cannot log in with old password.'),

  // ── 12. USER MANUAL ───────────────────────────────────────────────────────
  sectionRow('12. User Manual'),
  testRow('TC-058', 'Manual Tab Visible to All Roles', '1. Log in as Employee\n2. Check sidebar', 'User Manual link is visible in sidebar regardless of role.'),
  testRow('TC-059', 'Staff Manual Content (Admin/Manager/Supervisor)', '1. Log in as Admin\n2. Open User Manual', 'Shows Staff Guide badge. All 10 sections visible (Roles, Login, Schedule, Bulk, Heatmap, Employees, Settings, Backups, Activity Log, Profile).'),
  testRow('TC-060', 'Employee Manual Content', '1. Log in as Employee\n2. Open User Manual', 'Shows Employee Guide badge. Only 5 sections visible (Login, Viewing Schedule, Editing Own Schedule, Heatmap, My Profile).'),

  // ── 13. SIDEBAR & UI ──────────────────────────────────────────────────────
  sectionRow('13. Sidebar & General UI'),
  testRow('TC-061', 'Sidebar Clock Displayed', '1. Log in and view any page', 'Timezone clock shown at top of sidebar above "Main" section. Displays time, timezone, and date.'),
  testRow('TC-062', 'Theme Switching', '1. Open user menu or theme control\n2. Switch themes', 'Page re-renders with selected theme. Sidebar, cards, and grid colours update correctly.'),
  testRow('TC-063', 'Role-Based Sidebar Sections', '1. Log in as Employee\n2. Check sidebar sections', 'Employee sees Main section only (Schedule, Heatmap, My Profile, User Manual, Logout). No Management or Admin sections.'),
  testRow('TC-064', 'Admin Sidebar Sections', '1. Log in as Admin\n2. Check sidebar', 'Management and Admin sections visible (Bulk Changes, Add Employee, Activity Log, Settings, Backups, Setup Checklist).'),
  testRow('TC-065', 'Email Export Tools', '1. Go to Settings\n2. Find Email Export Tools card\n3. Use Export to File or Copy All', 'Email list exported/copied correctly. export.png image displayed in card.'),
  testRow('TC-066', 'Add User Tooltip', '1. Hover over Add User button in Settings', 'Tooltip appears: "This is only used for non-Google SSO access."'),
];

// ── Build document ────────────────────────────────────────────────────────────
const doc = new Document({
  styles: {
    default: {
      document: { run: { font: 'Arial', size: 18 } },
    },
    paragraphStyles: [
      { id: 'Heading1', name: 'Heading 1', basedOn: 'Normal', next: 'Normal', quickFormat: true,
        run: { size: 36, bold: true, font: 'Arial', color: C.darkBlue },
        paragraph: { spacing: { before: 320, after: 160 }, outlineLevel: 0 } },
      { id: 'Heading2', name: 'Heading 2', basedOn: 'Normal', next: 'Normal', quickFormat: true,
        run: { size: 26, bold: true, font: 'Arial', color: C.midBlue },
        paragraph: { spacing: { before: 240, after: 100 }, outlineLevel: 1 } },
    ],
  },
  sections: [{
    properties: {
      page: {
        size: { width: 15840, height: 12240 }, // Landscape Letter
        margin: { top: 1080, right: 1080, bottom: 1080, left: 1080 },
      },
    },
    headers: {
      default: new Header({
        children: [new Paragraph({
          children: [
            new TextRun({ text: 'Employee Scheduling System  |  UAT Test Plan', font: 'Arial', size: 16, color: C.darkGrey }),
            new TextRun({ children: ['\t', PageNumber.CURRENT], font: 'Arial', size: 16, color: C.darkGrey }),
          ],
          tabStops: [{ type: 'right', position: 12600 }],
          border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: C.midGrey, space: 1 } },
        })],
      }),
    },
    children: [
      ...coverPage(),
      h1('UAT Test Cases'),
      para('Instructions: Execute each test case in order. Mark Pass / Fail / N/A in the status column. Record any defect reference or notes in the final column.', { italic: true, color: C.darkGrey }),
      ...spacer(1),
      new Table({
        width: { size: TOTAL, type: WidthType.DXA },
        columnWidths: COL,
        rows: [tableHeader(), ...rows],
      }),
      new Paragraph({ children: [new PageBreak()] }),
      ...summaryTable(),
    ],
  }],
});

Packer.toBuffer(doc).then(buf => {
  fs.writeFileSync('/sessions/keen-affectionate-lovelace/mnt/SCHEDULE/UAT_Test_Plan.docx', buf);
  console.log('Done');
});
