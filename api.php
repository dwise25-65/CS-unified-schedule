<?php
/**
 * Employee Scheduling System - REST API
 * Handles all database operations through RESTful endpoints
 * 
 * Employee Endpoints:
 * - GET  /api.php?action=get_employees                  – list all active employees
 * - GET  /api.php?action=get_employee&id=1              – single employee by ID
 * - GET  /api.php?action=search_employees[&q=&team=&shift=&level=&supervisor=&skill=] – filtered list
 * - GET  /api.php?action=get_employees_by_skill&skill=mh|ma|win[&team=&format=summary] – employees by skill
 * - POST /api.php?action=save_employee                  – create or update employee
 * - DEL  /api.php?action=delete_employee&id=1           – soft-delete employee
 *
 * Schedule Endpoints:
 * - GET  /api.php?action=get_schedule&employee_id=1&year=2026&month=2
 * - GET  /api.php?action=get_overrides_range&start_date=&end_date= – all overrides in a range
 * - POST /api.php?action=save_schedule                  – save full monthly schedule
 * - POST /api.php?action=save_override                  – add/update single day override
 * - DEL  /api.php?action=delete_override&employee_id=1&date=Y-m-d
 * - POST /api.php?action=copy_schedule                  – copy a month's schedule to other employees
 * - POST /api.php?action=swap_shifts                    – swap overrides between two employees
 *
 * User/Auth Endpoints:
 * - GET  /api.php?action=get_users
 * - POST /api.php?action=save_user
 *
 * MOTD Endpoints:
 * - GET  /api.php?action=get_motd_messages
 * - POST /api.php?action=save_motd
 * - POST /api.php?action=deactivate_motd               – set active=0
 *
 * Audit Log Endpoints:
 * - GET  /api.php?action=get_audit_log[&limit=50&offset=0&table_name=&action=&user_id=]
 *
 * Backup Endpoints:
 * - POST /api.php?action=create_backup
 * - GET  /api.php?action=get_backups
 *
 * API Key Endpoints (UAT / Programmatic Access):
 * - POST /api.php?action=generate_api_key[&user_id=]  – generate X-API-Key for a user
 * - POST /api.php?action=revoke_api_key[&user_id=]    – revoke X-API-Key for a user
 * Pass the key as:  X-API-Key: <key>  in the request header.
 * The "uat" role has full access to all endpoints.
 *
 * Bulk Schedule Endpoints:
 * All bulk endpoints accept employees via any combination of:
 *   "employee_ids": [1,2,3]          – numeric IDs
 *   "emails":       ["a@co.com"]     – e-mail addresses (resolved to IDs)
 *   "names":        ["Alice Smith"]  – partial name match (case-insensitive)
 *
 * Bulk Schedule Endpoints (all accept employee_ids, emails, and/or names):
 * - GET/POST /api.php?action=lookup_employees          – preview who will be affected
 * - POST /api.php?action=bulk_change_shift             – change 1st/2nd/3rd shift
 * - POST /api.php?action=bulk_change_hours             – change working hours text
 * - POST /api.php?action=bulk_change_weekly_pattern    – set 7-day work pattern
 * - POST /api.php?action=bulk_add_override             – add PTO/holiday/sick/off over a date range
 * - POST /api.php?action=bulk_clear_overrides          – remove overrides for a date range
 * - POST /api.php?action=bulk_assign_team              – move employees to a new team
 * - POST /api.php?action=bulk_assign_supervisor        – reassign supervisor
 */

// Security headers
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Start session for authentication
session_start();

// Include dependencies
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/google_auth_config.php';  // Your existing auth

// Initialize database
$db = Database::getInstance();
$conn = $db->getConnection();

// Ensure users table has api_key column (UAT support)
try {
    $conn->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS api_key VARCHAR(64) NULL DEFAULT NULL UNIQUE");
} catch (Exception $e) { /* column may already exist */ }

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Response helper function
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// Error helper function
function sendError($message, $statusCode = 400) {
    http_response_code($statusCode);
    echo json_encode(['error' => $message]);
    exit;
}

// Role → permissions map (mirrors auth_user_management.php)
$apiRoles = [
    'admin'      => ['view_schedule', 'edit_schedule', 'manage_employees', 'manage_users', 'manage_backups', 'view_all_teams', 'manage_all_profiles'],
    'manager'    => ['view_schedule', 'edit_schedule', 'manage_employees', 'manage_users', 'manage_backups', 'view_all_teams', 'manage_all_profiles'],
    'supervisor' => ['view_schedule', 'edit_schedule', 'manage_employees', 'manage_users', 'manage_backups', 'view_all_teams', 'manage_all_profiles'],
    'employee'   => ['view_schedule', 'edit_own_schedule', 'view_all_teams', 'view_own'],
    'uat'        => ['view_schedule', 'edit_schedule', 'manage_employees', 'manage_users', 'manage_backups', 'view_all_teams', 'manage_all_profiles'],
];

// ── X-API-Key authentication (for UAT / programmatic access) ──────────────────
// If a session isn't active, check for X-API-Key header and authenticate that way.
if (!isset($_SESSION['user_id'])) {
    $incomingKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($incomingKey !== '') {
        $apiUser = $db->fetchOne("SELECT id, username, role FROM users WHERE api_key = ? AND active = 1", [$incomingKey]);
        if ($apiUser) {
            $_SESSION['user_id']   = $apiUser['id'];
            $_SESSION['user_role'] = $apiUser['role'];
            $_SESSION['username']  = $apiUser['username'];
        }
    }
}

// Check if user is authenticated
function requireAuth() {
    if (!isset($_SESSION['user_id'])) {
        sendError('Authentication required', 401);
    }
}

// Check if the current session user has a given permission
function hasPermission($permission) {
    global $apiRoles;
    $role = $_SESSION['user_role'] ?? null;
    if (!$role) return false;
    return in_array($permission, $apiRoles[$role] ?? []);
}

// Check if user has specific permission
function requirePermission($permission) {
    requireAuth();
    if (!hasPermission($permission)) {
        sendError('Insufficient permissions', 403);
    }
}

// Audit log helper
function logAudit($action, $tableName = null, $recordId = null, $oldData = null, $newData = null) {
    global $db;
    
    $userId = $_SESSION['user_id'] ?? null;
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $sql = "INSERT INTO audit_log (user_id, action, table_name, record_id, old_data, new_data, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $db->query($sql, [
        $userId,
        $action,
        $tableName,
        $recordId,
        $oldData ? json_encode($oldData) : null,
        $newData ? json_encode($newData) : null,
        $ipAddress,
        $userAgent
    ]);
}

// ================================================================
// EMPLOYEE ENDPOINTS
// ================================================================

/**
 * GET /api.php?action=get_employees
 * Returns all employees
 */
function getEmployees() {
    global $db;
    
    $sql = "SELECT e.*, u.username, u.email as user_email 
            FROM employees e 
            LEFT JOIN users u ON e.user_id = u.id 
            WHERE e.active = 1
            ORDER BY e.name";
    
    $employees = $db->fetchAll($sql);
    sendResponse(['employees' => $employees]);
}

/**
 * GET /api.php?action=get_employee&id=1
 * Returns single employee by ID
 */
function getEmployee() {
    global $db;
    
    $id = $_GET['id'] ?? null;
    if (!$id) sendError('Employee ID required');
    
    $sql = "SELECT e.*, u.username, u.email as user_email 
            FROM employees e 
            LEFT JOIN users u ON e.user_id = u.id 
            WHERE e.id = ?";
    
    $employee = $db->fetchOne($sql, [$id]);
    
    if (!$employee) sendError('Employee not found', 404);
    
    sendResponse($employee);
}

/**
 * POST /api.php?action=save_employee
 * Creates or updates employee
 */
function saveEmployee() {
    requirePermission('manage_employees');
    global $db;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['name']) || empty($data['email'])) {
        sendError('Name and email are required');
    }
    
    $db->beginTransaction();
    
    try {
        if (!empty($data['id'])) {
            // Update existing employee
            $sql = "UPDATE employees SET 
                    name = ?, email = ?, team = ?, level = ?, shift = ?, 
                    hours = ?, supervisor = ?, skills = ?, notes = ?, 
                    user_id = ?, active = ?
                    WHERE id = ?";
            
            $db->execute($sql, [
                $data['name'],
                $data['email'],
                $data['team'] ?? null,
                $data['level'] ?? null,
                $data['shift'] ?? null,
                $data['hours'] ?? 40.00,
                $data['supervisor'] ?? null,
                $data['skills'] ?? null,
                $data['notes'] ?? null,
                $data['user_id'] ?? null,
                $data['active'] ?? 1,
                $data['id']
            ]);
            
            $employeeId = $data['id'];
            logAudit('UPDATE_EMPLOYEE', 'employees', $employeeId, null, $data);
            
        } else {
            // Insert new employee
            $sql = "INSERT INTO employees 
                    (name, email, team, level, shift, hours, supervisor, skills, notes, user_id, active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $employeeId = $db->insert($sql, [
                $data['name'],
                $data['email'],
                $data['team'] ?? null,
                $data['level'] ?? null,
                $data['shift'] ?? null,
                $data['hours'] ?? 40.00,
                $data['supervisor'] ?? null,
                $data['skills'] ?? null,
                $data['notes'] ?? null,
                $data['user_id'] ?? null,
                $data['active'] ?? 1
            ]);
            
            logAudit('CREATE_EMPLOYEE', 'employees', $employeeId, null, $data);
        }
        
        $db->commit();
        sendResponse(['success' => true, 'employee_id' => $employeeId]);
        
    } catch (Exception $e) {
        $db->rollback();
        sendError('Failed to save employee: ' . $e->getMessage());
    }
}

/**
 * GET /api.php?action=search_employees
 * Filter employees by team, shift, level, supervisor, skill, or a name/email query.
 * All query params are optional — omitting all returns every active employee.
 *
 * Query params:
 *   q          – partial name or email match (case-insensitive)
 *   team       – exact team name
 *   shift      – shift number 1/2/3 or label string
 *   level      – exact level string
 *   supervisor – partial supervisor name match
 *   skill      – filter by skill: mh | ma | win (employees who have that skill = true)
 *   active     – 1 (default) or 0
 */
function searchEmployees() {
    requireAuth();
    global $db;

    $conditions = [];
    $params = [];

    $active = isset($_GET['active']) ? (int)$_GET['active'] : 1;
    $conditions[] = 'e.active = ?';
    $params[] = $active;

    if (!empty($_GET['q'])) {
        $q = '%' . trim($_GET['q']) . '%';
        $conditions[] = '(e.name LIKE ? OR e.email LIKE ?)';
        $params[] = $q;
        $params[] = $q;
    }
    if (!empty($_GET['team'])) {
        $conditions[] = 'e.team = ?';
        $params[] = trim($_GET['team']);
    }
    if (isset($_GET['shift']) && $_GET['shift'] !== '') {
        $conditions[] = 'e.shift = ?';
        $params[] = $_GET['shift'];
    }
    if (!empty($_GET['level'])) {
        $conditions[] = 'e.level = ?';
        $params[] = trim($_GET['level']);
    }
    if (!empty($_GET['supervisor'])) {
        $conditions[] = 'e.supervisor LIKE ?';
        $params[] = '%' . trim($_GET['supervisor']) . '%';
    }
    // Skill filter — skills stored as JSON {"mh":true/false,"ma":true/false,"win":true/false}
    $validSkills = ['mh', 'ma', 'win'];
    if (!empty($_GET['skill']) && in_array(strtolower($_GET['skill']), $validSkills)) {
        $sk = strtolower($_GET['skill']);
        // JSON_EXTRACT returns true/1 when the skill flag is set
        $conditions[] = "(JSON_EXTRACT(e.skills, '$.$sk') = true OR JSON_EXTRACT(e.skills, '$.$sk') = 1)";
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    $employees = $db->fetchAll(
        "SELECT e.*, u.username, u.email AS user_email
         FROM employees e
         LEFT JOIN users u ON e.user_id = u.id
         $where
         ORDER BY e.name",
        $params
    );

    // Decode skills JSON for each employee in the response
    foreach ($employees as &$emp) {
        if (isset($emp['skills']) && is_string($emp['skills'])) {
            $emp['skills'] = json_decode($emp['skills'], true) ?? ['mh'=>false,'ma'=>false,'win'=>false];
        }
    }
    unset($emp);

    sendResponse(['employees' => $employees, 'count' => count($employees)]);
}

/**
 * GET /api.php?action=get_employees_by_skill&skill=mh
 * Returns all active employees who have a specific skill enabled.
 * Convenience wrapper around search_employees with skill= param.
 *
 * Query params:
 *   skill      – required: mh | ma | win
 *   team       – optional: filter by team as well
 *   format     – optional: "summary" returns name/email/team/skills only
 */
function getEmployeesBySkill() {
    requireAuth();
    global $db;

    $validSkills = ['mh', 'ma', 'win'];
    $skill = strtolower(trim($_GET['skill'] ?? ''));

    if (!$skill || !in_array($skill, $validSkills)) {
        sendError('skill parameter required. Valid values: mh, ma, win', 400);
    }

    $conditions = ['e.active = 1'];
    $params = [];

    $conditions[] = "(JSON_EXTRACT(e.skills, '$.$skill') = true OR JSON_EXTRACT(e.skills, '$.$skill') = 1)";

    if (!empty($_GET['team'])) {
        $conditions[] = 'e.team = ?';
        $params[] = trim($_GET['team']);
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    $employees = $db->fetchAll(
        "SELECT e.id, e.name, e.email, e.team, e.level, e.shift, e.hours, e.skills,
                e.supervisor_id, e.weekly_schedule
         FROM employees e
         $where
         ORDER BY e.name",
        $params
    );

    // Decode skills JSON and flag which skills each employee has
    $skillLabels = ['mh' => 'MH', 'ma' => 'MA', 'win' => 'WIN'];
    foreach ($employees as &$emp) {
        if (isset($emp['skills']) && is_string($emp['skills'])) {
            $emp['skills'] = json_decode($emp['skills'], true) ?? ['mh'=>false,'ma'=>false,'win'=>false];
        }
        // Add human-readable active_skills list
        $emp['active_skills'] = array_values(array_filter(
            array_map(fn($k,$v) => $v ? $skillLabels[$k] : null, array_keys($emp['skills'] ?? []), $emp['skills'] ?? [])
        ));
    }
    unset($emp);

    if (($_GET['format'] ?? '') === 'summary') {
        $employees = array_map(fn($e) => [
            'id'           => $e['id'],
            'name'         => $e['name'],
            'email'        => $e['email'],
            'team'         => $e['team'],
            'active_skills'=> $e['active_skills'],
        ], $employees);
    }

    sendResponse([
        'skill'     => strtoupper($skill),
        'count'     => count($employees),
        'employees' => $employees,
    ]);
}

/**
 * GET /api.php?action=get_emails_by_team
 * Returns email addresses for all active employees, optionally filtered by team.
 *
 * Query params:
 *   team   (optional) – filter to a specific team name
 *   format (optional) – "csv" returns a plain comma-separated email string
 *                       "list" returns a newline-separated string
 *                       omit for full JSON response
 */
function getEmailsByTeam() {
    requireAuth();
    global $db;

    $conditions = ['e.active = 1', "e.email != '' AND e.email IS NOT NULL"];
    $params = [];

    if (!empty($_GET['team'])) {
        $conditions[] = 'e.team = ?';
        $params[] = trim($_GET['team']);
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    $rows = $db->fetchAll(
        "SELECT e.id, e.name, e.email, e.team
         FROM employees e
         $where
         ORDER BY e.team, e.name",
        $params
    );

    $format = strtolower(trim($_GET['format'] ?? ''));

    if ($format === 'csv') {
        $emails = array_column($rows, 'email');
        header('Content-Type: text/plain');
        echo implode(', ', $emails);
        exit;
    }

    if ($format === 'list') {
        $emails = array_column($rows, 'email');
        header('Content-Type: text/plain');
        echo implode("\n", $emails);
        exit;
    }

    // Group by team for the default JSON response
    $byTeam = [];
    foreach ($rows as $row) {
        $team = $row['team'] ?: 'Unassigned';
        if (!isset($byTeam[$team])) {
            $byTeam[$team] = ['team' => $team, 'count' => 0, 'employees' => []];
        }
        $byTeam[$team]['employees'][] = ['id' => $row['id'], 'name' => $row['name'], 'email' => $row['email']];
        $byTeam[$team]['count']++;
    }

    sendResponse([
        'total'  => count($rows),
        'team'   => empty($_GET['team']) ? null : trim($_GET['team']),
        'teams'  => array_values($byTeam),
    ]);
}

/**
 * DELETE /api.php?action=delete_employee&id=1
 * Soft deletes employee
 */
function deleteEmployee() {
    requirePermission('manage_employees');
    global $db;
    
    $id = $_GET['id'] ?? null;
    if (!$id) sendError('Employee ID required');
    
    $sql = "UPDATE employees SET active = 0 WHERE id = ?";
    $db->execute($sql, [$id]);
    
    logAudit('DELETE_EMPLOYEE', 'employees', $id);
    sendResponse(['success' => true]);
}

// ================================================================
// SCHEDULE ENDPOINTS
// ================================================================

/**
 * GET /api.php?action=get_schedule&employee_id=1&year=2026&month=2
 * Returns employee schedule for specific month
 */
function getSchedule() {
    global $db;
    
    $employeeId = $_GET['employee_id'] ?? null;
    $year = $_GET['year'] ?? date('Y');
    $month = $_GET['month'] ?? date('n');
    
    if (!$employeeId) sendError('Employee ID required');
    
    // Get base schedule
    $sql = "SELECT schedule_data, updated_at, updated_by 
            FROM schedules 
            WHERE employee_id = ? AND year = ? AND month = ?";
    
    $schedule = $db->fetchOne($sql, [$employeeId, $year, $month]);
    
    // Get overrides for this month
    $sql = "SELECT * FROM schedule_overrides 
            WHERE employee_id = ? 
            AND YEAR(override_date) = ? 
            AND MONTH(override_date) = ?
            ORDER BY override_date";
    
    $overrides = $db->fetchAll($sql, [$employeeId, $year, $month]);
    
    sendResponse([
        'schedule' => $schedule ? json_decode($schedule['schedule_data'], true) : [],
        'overrides' => $overrides,
        'updated_at' => $schedule['updated_at'] ?? null
    ]);
}

/**
 * POST /api.php?action=save_schedule
 * Saves entire month schedule
 */
function saveSchedule() {
    requirePermission('manage_schedules');
    global $db;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['employee_id']) || empty($data['year']) || empty($data['month'])) {
        sendError('Employee ID, year, and month are required');
    }
    
    $userId = $_SESSION['user_id'];
    
    $db->beginTransaction();
    
    try {
        // Check if schedule exists
        $sql = "SELECT id FROM schedules 
                WHERE employee_id = ? AND year = ? AND month = ?";
        
        $existing = $db->fetchOne($sql, [$data['employee_id'], $data['year'], $data['month']]);
        
        if ($existing) {
            // Update
            $sql = "UPDATE schedules 
                    SET schedule_data = ?, updated_by = ? 
                    WHERE employee_id = ? AND year = ? AND month = ?";
            
            $db->execute($sql, [
                json_encode($data['schedule_data']),
                $userId,
                $data['employee_id'],
                $data['year'],
                $data['month']
            ]);
            
        } else {
            // Insert
            $sql = "INSERT INTO schedules (employee_id, year, month, schedule_data, updated_by) 
                    VALUES (?, ?, ?, ?, ?)";
            
            $db->insert($sql, [
                $data['employee_id'],
                $data['year'],
                $data['month'],
                json_encode($data['schedule_data']),
                $userId
            ]);
        }
        
        $db->commit();
        logAudit('SAVE_SCHEDULE', 'schedules', $data['employee_id'], null, $data);
        sendResponse(['success' => true]);
        
    } catch (Exception $e) {
        $db->rollback();
        sendError('Failed to save schedule: ' . $e->getMessage());
    }
}

/**
 * POST /api.php?action=save_override
 * Saves single day override (PTO, sick, holiday, custom hours)
 */
function saveOverride() {
    requirePermission('manage_schedules');
    global $db;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['employee_id']) || empty($data['date']) || empty($data['type'])) {
        sendError('Employee ID, date, and type are required');
    }
    
    $userId = $_SESSION['user_id'];
    
    $db->beginTransaction();
    
    try {
        // Check if override exists
        $sql = "SELECT id FROM schedule_overrides 
                WHERE employee_id = ? AND override_date = ?";
        
        $existing = $db->fetchOne($sql, [$data['employee_id'], $data['date']]);
        
        if ($existing) {
            // Update
            $sql = "UPDATE schedule_overrides 
                    SET override_type = ?, custom_hours = ?, notes = ? 
                    WHERE employee_id = ? AND override_date = ?";
            
            $db->execute($sql, [
                $data['type'],
                $data['custom_hours'] ?? null,
                $data['notes'] ?? null,
                $data['employee_id'],
                $data['date']
            ]);
            
        } else {
            // Insert
            $sql = "INSERT INTO schedule_overrides 
                    (employee_id, override_date, override_type, custom_hours, notes, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            
            $db->insert($sql, [
                $data['employee_id'],
                $data['date'],
                $data['type'],
                $data['custom_hours'] ?? null,
                $data['notes'] ?? null,
                $userId
            ]);
        }
        
        $db->commit();
        logAudit('SAVE_OVERRIDE', 'schedule_overrides', $data['employee_id'], null, $data);
        sendResponse(['success' => true]);
        
    } catch (Exception $e) {
        $db->rollback();
        sendError('Failed to save override: ' . $e->getMessage());
    }
}

/**
 * GET /api.php?action=get_overrides_range
 * Returns all schedule overrides within a date range, joined to employee info.
 * Useful for planning views — "who has PTO/sick next week across the whole company?"
 *
 * Query params:
 *   start_date   – YYYY-MM-DD (required)
 *   end_date     – YYYY-MM-DD (required)
 *   employee_ids – comma-separated IDs to narrow results (optional)
 *   type         – filter by override type: pto|sick|holiday|custom_hours|off (optional)
 */
function getOverridesRange() {
    requireAuth();
    global $db;

    $startDate = $_GET['start_date'] ?? null;
    $endDate   = $_GET['end_date']   ?? null;
    if (!$startDate || !$endDate) sendError('start_date and end_date required');

    $conditions = ['so.override_date BETWEEN ? AND ?'];
    $params     = [$startDate, $endDate];

    if (!empty($_GET['employee_ids'])) {
        $ids          = array_map('intval', explode(',', $_GET['employee_ids']));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $conditions[] = "so.employee_id IN ($placeholders)";
        $params       = array_merge($params, $ids);
    }
    if (!empty($_GET['type'])) {
        $conditions[] = 'so.override_type = ?';
        $params[]     = strtolower(trim($_GET['type']));
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    $overrides = $db->fetchAll(
        "SELECT so.*, e.name AS employee_name, e.email AS employee_email, e.team
         FROM schedule_overrides so
         JOIN employees e ON so.employee_id = e.id
         $where
         ORDER BY so.override_date, e.name",
        $params
    );

    sendResponse(['overrides' => $overrides, 'count' => count($overrides)]);
}

/**
 * POST /api.php?action=copy_schedule
 * Copies a monthly schedule blob from one source employee+month to one or
 * more target employees and/or a different month. Overwrites any existing
 * schedule_data for the target. Does NOT copy day-level overrides.
 *
 * Body:
 * {
 *   "from_employee_id": 1,
 *   "from_year":        2026,
 *   "from_month":       3,
 *   "to_employee_ids":  [2, 3],   // ─┐ target employees – any combination
 *   "to_emails":        [...],    //  ├─ at least one required
 *   "to_names":         [...],    // ─┘
 *   "to_year":          2026,     // optional – defaults to from_year
 *   "to_month":         4         // optional – defaults to from_month
 * }
 */
function copySchedule() {
    requirePermission('manage_schedules');
    global $db;

    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['from_employee_id'])) sendError('from_employee_id required');
    if (empty($data['from_year']))        sendError('from_year required');
    if (empty($data['from_month']))       sendError('from_month required');

    $fromId    = (int)$data['from_employee_id'];
    $fromYear  = (int)$data['from_year'];
    $fromMonth = (int)$data['from_month'];
    $toYear    = (int)($data['to_year']  ?? $fromYear);
    $toMonth   = (int)($data['to_month'] ?? $fromMonth);

    // Resolve target employees via the "to_" prefixed lookup fields
    $lookupData = [
        'employee_ids' => $data['to_employee_ids'] ?? [],
        'emails'       => $data['to_emails']       ?? [],
        'names'        => $data['to_names']        ?? [],
    ];
    $targetIds = resolveEmployeeIds($lookupData);

    $source = $db->fetchOne(
        "SELECT schedule_data FROM schedules WHERE employee_id = ? AND year = ? AND month = ?",
        [$fromId, $fromYear, $fromMonth]
    );
    if (!$source) sendError('Source schedule not found for the specified employee/year/month', 404);

    $scheduleData = $source['schedule_data'];
    $userId       = $_SESSION['user_id'];
    $copied       = 0;

    $db->beginTransaction();
    try {
        foreach ($targetIds as $targetId) {
            $existing = $db->fetchOne(
                "SELECT id FROM schedules WHERE employee_id = ? AND year = ? AND month = ?",
                [$targetId, $toYear, $toMonth]
            );
            if ($existing) {
                $db->execute(
                    "UPDATE schedules SET schedule_data = ?, updated_by = ? WHERE employee_id = ? AND year = ? AND month = ?",
                    [$scheduleData, $userId, $targetId, $toYear, $toMonth]
                );
            } else {
                $db->insert(
                    "INSERT INTO schedules (employee_id, year, month, schedule_data, updated_by) VALUES (?, ?, ?, ?, ?)",
                    [$targetId, $toYear, $toMonth, $scheduleData, $userId]
                );
            }
            $copied++;
        }
        $db->commit();
        logAudit('COPY_SCHEDULE', 'schedules', null, null, [
            'from_employee_id' => $fromId, 'from_year' => $fromYear, 'from_month' => $fromMonth,
            'to_employee_ids'  => $targetIds, 'to_year' => $toYear, 'to_month' => $toMonth,
        ]);
        sendResponse(['success' => true, 'schedules_copied' => $copied]);
    } catch (Exception $e) {
        $db->rollback();
        sendError('copy_schedule failed: ' . $e->getMessage());
    }
}

/**
 * POST /api.php?action=swap_shifts
 * Swaps the day-level overrides between two employees on one or more dates.
 * Employees on their regular weekly schedule (no override) are treated as
 * having no override — so if only one side has one, it moves to the other.
 *
 * Body:
 * {
 *   "employee_id_a": 2,
 *   "employee_id_b": 5,
 *   "dates": ["2026-03-15", "2026-03-16"]
 * }
 */
function swapShifts() {
    requirePermission('manage_schedules');
    global $db;

    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['employee_id_a']))                          sendError('employee_id_a required');
    if (empty($data['employee_id_b']))                          sendError('employee_id_b required');
    if (empty($data['dates']) || !is_array($data['dates']))     sendError('dates array required');

    $empA   = (int)$data['employee_id_a'];
    $empB   = (int)$data['employee_id_b'];
    $dates  = $data['dates'];
    $userId = $_SESSION['user_id'];

    if ($empA === $empB) sendError('employee_id_a and employee_id_b must be different employees');

    $db->beginTransaction();
    try {
        foreach ($dates as $date) {
            $overrideA = $db->fetchOne(
                "SELECT * FROM schedule_overrides WHERE employee_id = ? AND override_date = ?",
                [$empA, $date]
            );
            $overrideB = $db->fetchOne(
                "SELECT * FROM schedule_overrides WHERE employee_id = ? AND override_date = ?",
                [$empB, $date]
            );

            // Remove both overrides for this date
            $db->execute(
                "DELETE FROM schedule_overrides WHERE employee_id IN (?,?) AND override_date = ?",
                [$empA, $empB, $date]
            );

            // Write A's override to B
            if ($overrideA) {
                $db->insert(
                    "INSERT INTO schedule_overrides (employee_id, override_date, override_type, custom_hours, notes, created_by) VALUES (?,?,?,?,?,?)",
                    [$empB, $date, $overrideA['override_type'], $overrideA['custom_hours'], $overrideA['notes'], $userId]
                );
            }
            // Write B's override to A
            if ($overrideB) {
                $db->insert(
                    "INSERT INTO schedule_overrides (employee_id, override_date, override_type, custom_hours, notes, created_by) VALUES (?,?,?,?,?,?)",
                    [$empA, $date, $overrideB['override_type'], $overrideB['custom_hours'], $overrideB['notes'], $userId]
                );
            }
        }
        $db->commit();
        logAudit('SWAP_SHIFTS', 'schedule_overrides', null, null, [
            'employee_id_a' => $empA, 'employee_id_b' => $empB, 'dates' => $dates,
        ]);
        sendResponse(['success' => true, 'dates_swapped' => count($dates)]);
    } catch (Exception $e) {
        $db->rollback();
        sendError('swap_shifts failed: ' . $e->getMessage());
    }
}

/**
 * DELETE /api.php?action=delete_override&employee_id=1&date=2026-02-15
 * Deletes schedule override
 */
function deleteOverride() {
    requirePermission('manage_schedules');
    global $db;
    
    $employeeId = $_GET['employee_id'] ?? null;
    $date = $_GET['date'] ?? null;
    
    if (!$employeeId || !$date) sendError('Employee ID and date required');
    
    $sql = "DELETE FROM schedule_overrides 
            WHERE employee_id = ? AND override_date = ?";
    
    $db->execute($sql, [$employeeId, $date]);
    
    logAudit('DELETE_OVERRIDE', 'schedule_overrides', $employeeId);
    sendResponse(['success' => true]);
}

// ================================================================
// USER ENDPOINTS
// ================================================================

/**
 * GET /api.php?action=get_users
 * Returns all users
 */
function getUsers() {
    requirePermission('manage_users');
    global $db;
    
    $sql = "SELECT u.*, e.name as employee_name, e.id as employee_id 
            FROM users u 
            LEFT JOIN employees e ON u.id = e.user_id 
            ORDER BY u.username";
    
    $users = $db->fetchAll($sql);
    sendResponse(['users' => $users]);
}

/**
 * POST /api.php?action=save_user
 * Creates or updates user
 */
function saveUser() {
    requirePermission('manage_users');
    global $db;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['username']) || empty($data['email'])) {
        sendError('Username and email are required');
    }
    
    $db->beginTransaction();
    
    try {
        if (!empty($data['id'])) {
            // Update
            $sql = "UPDATE users SET 
                    username = ?, email = ?, role = ?, active = ?
                    WHERE id = ?";
            
            $db->execute($sql, [
                $data['username'],
                $data['email'],
                $data['role'],
                $data['active'] ?? 1,
                $data['id']
            ]);
            
            $userId = $data['id'];
            
        } else {
            // Insert
            $sql = "INSERT INTO users (username, email, role, auth_method, active) 
                    VALUES (?, ?, ?, ?, ?)";
            
            $userId = $db->insert($sql, [
                $data['username'],
                $data['email'],
                $data['role'],
                $data['auth_method'] ?? 'local',
                $data['active'] ?? 1
            ]);
        }
        
        $db->commit();
        logAudit('SAVE_USER', 'users', $userId, null, $data);
        sendResponse(['success' => true, 'user_id' => $userId]);
        
    } catch (Exception $e) {
        $db->rollback();
        sendError('Failed to save user: ' . $e->getMessage());
    }
}

// ================================================================
// MOTD ENDPOINTS
// ================================================================

/**
 * GET /api.php?action=get_motd_messages
 * Returns all active MOTD messages
 */
function getMOTDMessages() {
    global $db;

    // Query motd_messages directly (no dependency on optional v_active_motd view)
    $today = date('Y-m-d');
    $sql = "SELECT * FROM motd_messages
            WHERE active = 1
              AND (start_date IS NULL OR start_date <= ?)
              AND (end_date   IS NULL OR end_date   >= ?)
            ORDER BY created_at DESC";
    $messages = $db->fetchAll($sql, [$today, $today]);

    sendResponse(['messages' => $messages]);
}

/**
 * POST /api.php?action=deactivate_motd
 * Deactivates a MOTD message (sets active = 0). The record is kept for audit.
 *
 * Body: { "id": 5 }
 */
function deactivateMOTD() {
    requirePermission('manage_users');
    global $db;

    $data = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($data['id'] ?? 0);
    if (!$id) sendError('id required');

    $db->execute("UPDATE motd_messages SET active = 0 WHERE id = ?", [$id]);
    logAudit('DEACTIVATE_MOTD', 'motd_messages', $id);
    sendResponse(['success' => true]);
}

/**
 * POST /api.php?action=save_motd
 * Saves MOTD message
 */
function saveMOTD() {
    requirePermission('manage_users');  // Or specific MOTD permission
    global $db;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['message'])) {
        sendError('Message is required');
    }
    
    $userId = $_SESSION['user_id'];
    
    $sql = "INSERT INTO motd_messages 
            (message, start_date, end_date, include_anniversaries, created_by) 
            VALUES (?, ?, ?, ?, ?)";
    
    $id = $db->insert($sql, [
        $data['message'],
        $data['start_date'] ?? null,
        $data['end_date'] ?? null,
        $data['include_anniversaries'] ?? 1,
        $userId
    ]);
    
    logAudit('CREATE_MOTD', 'motd_messages', $id, null, $data);
    sendResponse(['success' => true, 'motd_id' => $id]);
}

// ================================================================
// BULK SCHEDULE ENDPOINTS
// ================================================================

/**
 * resolveEmployeeIds($data)
 *
 * Resolves a mixed set of identifiers from the request body into a
 * deduplicated array of integer employee IDs.  Callers may supply any
 * combination of:
 *
 *   "employee_ids": [1, 2, 3]              – numeric IDs  (already supported)
 *   "emails":       ["a@co.com","b@co.com"] – employee e-mail addresses
 *   "names":        ["Alice Smith"]         – partial or full employee names
 *                                            (case-insensitive LIKE match)
 *
 * At least one of the three fields must be present and non-empty.
 * Throws a 400 error if nothing resolves to a valid employee.
 *
 * @param  array $data  Decoded JSON request body
 * @return int[]        Deduplicated list of employee IDs
 */
function resolveEmployeeIds(array $data): array {
    global $db;

    $ids = [];

    // 1. Direct numeric IDs
    if (!empty($data['employee_ids']) && is_array($data['employee_ids'])) {
        foreach ($data['employee_ids'] as $id) {
            $ids[] = (int)$id;
        }
    }

    // 2. Resolve by e-mail
    if (!empty($data['emails']) && is_array($data['emails'])) {
        $emails       = array_filter(array_map('trim', $data['emails']));
        $placeholders = implode(',', array_fill(0, count($emails), '?'));
        $rows = $db->fetchAll(
            "SELECT id FROM employees WHERE email IN ($placeholders) AND active = 1",
            $emails
        );
        foreach ($rows as $row) {
            $ids[] = (int)$row['id'];
        }
    }

    // 3. Resolve by name (case-insensitive partial match)
    if (!empty($data['names']) && is_array($data['names'])) {
        foreach ($data['names'] as $name) {
            $name = trim($name);
            if ($name === '') continue;
            $rows = $db->fetchAll(
                "SELECT id FROM employees WHERE name LIKE ? AND active = 1",
                ['%' . $name . '%']
            );
            foreach ($rows as $row) {
                $ids[] = (int)$row['id'];
            }
        }
    }

    if (empty($ids)) {
        sendError('Provide at least one of: employee_ids, emails, or names — and ensure they match active employees');
    }

    return array_values(array_unique($ids));
}

/**
 * GET /api.php?action=lookup_employees
 * Resolves employee_ids, emails, and/or names to full employee records.
 * Useful for confirming who will be affected before a bulk operation.
 *
 * Query params (at least one required):
 *   employee_ids  – comma-separated numeric IDs, e.g. ?employee_ids=1,2,3
 *   emails        – comma-separated e-mail addresses
 *   names         – comma-separated name fragments
 *
 * Returns:
 *   { "employees": [ { id, name, email, team, shift, ... } ] }
 */
function lookupEmployees(): void {
    requireAuth();
    global $db;

    // Accept both GET (comma-separated) and POST (JSON arrays) for flexibility
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $data = [];
        if (!empty($_GET['employee_ids'])) {
            $data['employee_ids'] = array_map('trim', explode(',', $_GET['employee_ids']));
        }
        if (!empty($_GET['emails'])) {
            $data['emails'] = array_map('trim', explode(',', $_GET['emails']));
        }
        if (!empty($_GET['names'])) {
            $data['names'] = array_map('trim', explode(',', $_GET['names']));
        }
    }

    $ids          = resolveEmployeeIds($data);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $employees = $db->fetchAll(
        "SELECT e.id, e.name, e.email, e.team, e.level, e.shift, e.hours,
                e.supervisor, e.weekly_schedule, u.username
         FROM   employees e
         LEFT JOIN users u ON e.user_id = u.id
         WHERE  e.id IN ($placeholders)
         ORDER BY e.name",
        $ids
    );

    sendResponse(['employees' => $employees, 'count' => count($employees)]);
}

/**
 * POST /api.php?action=bulk_change_shift
 * Changes the shift assignment (1=1st, 2=2nd, 3=3rd) for multiple employees.
 * Updates employees.shift directly — this is what the app actually reads.
 *
 * Body:
 * {
 *   "employee_ids": [1, 2, 3],              // ─┐ supply any combination;
 *   "emails":       ["a@co.com"],           //  ├─ at least one required
 *   "names":        ["Alice Smith"],        // ─┘
 *   "shift": 2                              // required – 1=1st Shift, 2=2nd Shift, 3=3rd Shift
 * }
 */
function bulkChangeShift() {
    requirePermission('manage_schedules');
    global $db;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['shift'])) sendError('shift required');

    $shift = (int)$data['shift'];
    if (!in_array($shift, [1, 2, 3], true)) sendError('shift must be 1 (1st), 2 (2nd), or 3 (3rd)');

    $employeeIds  = resolveEmployeeIds($data);
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    $hasRange     = !empty($data['start_date']) && !empty($data['end_date']);

    $db->beginTransaction();
    try {
        // Always update the base employee record
        $params = array_merge([$shift], $employeeIds);
        $db->execute(
            "UPDATE employees SET shift = ?, updated_at = NOW() WHERE id IN ($placeholders)",
            $params
        );

        // If a date range is given, also write per-day overrides so the
        // change is visible on the schedule grid for those specific dates.
        $overridesWritten = 0;
        if ($hasRange) {
            $userId      = $_SESSION['user_id'];
            $allowedDows = isset($data['days_of_week']) && is_array($data['days_of_week'])
                ? array_map('intval', $data['days_of_week']) : null;
            $skipDaysOff = !empty($data['skip_days_off']);
            $notes       = json_encode(['shift' => $shift, 'source' => 'bulk_change_shift']);

            // Build date list
            $dates  = [];
            $cursor = new DateTime($data['start_date']);
            $end    = new DateTime($data['end_date']);
            if ($end < $cursor) sendError('end_date must be on or after start_date');
            while ($cursor <= $end) { $dates[] = $cursor->format('Y-m-d'); $cursor->modify('+1 day'); }

            // Load weekly patterns if skip_days_off
            $empSchedules = [];
            if ($skipDaysOff) {
                $rows = $db->fetchAll(
                    "SELECT id, weekly_schedule FROM employees WHERE id IN ($placeholders)", $employeeIds
                );
                foreach ($rows as $r) {
                    $empSchedules[$r['id']] = array_map('intval', explode(',', $r['weekly_schedule'] ?? '0,1,1,1,1,1,0'));
                }
            }

            foreach ($employeeIds as $empId) {
                foreach ($dates as $date) {
                    $dow = (int)(new DateTime($date))->format('w');
                    if ($allowedDows !== null && !in_array($dow, $allowedDows, true)) continue;
                    if ($skipDaysOff && isset($empSchedules[$empId]) && ($empSchedules[$empId][$dow] ?? 0) == 0) continue;

                    $existing = $db->fetchOne(
                        "SELECT id FROM schedule_overrides WHERE employee_id = ? AND override_date = ?",
                        [$empId, $date]
                    );
                    if ($existing) {
                        $db->execute(
                            "UPDATE schedule_overrides SET override_type = 'custom_hours', notes = ? WHERE employee_id = ? AND override_date = ?",
                            [$notes, $empId, $date]
                        );
                    } else {
                        $db->insert(
                            "INSERT INTO schedule_overrides (employee_id, override_date, override_type, notes, created_by) VALUES (?,?,'custom_hours',?,?)",
                            [$empId, $date, $notes, $userId]
                        );
                    }
                    $overridesWritten++;
                }
            }
        }

        $db->commit();
        logAudit('BULK_CHANGE_SHIFT', 'employees', null, null, [
            'employee_ids' => $employeeIds, 'shift' => $shift,
            'date_range'   => $hasRange ? [$data['start_date'], $data['end_date']] : null,
        ]);
        $response = ['success' => true, 'employees_updated' => count($employeeIds)];
        if ($hasRange) $response['overrides_written'] = $overridesWritten;
        sendResponse($response);

    } catch (Exception $e) {
        $db->rollback();
        sendError('bulk_change_shift failed: ' . $e->getMessage());
    }
}

/**
 * POST /api.php?action=bulk_change_hours
 * Changes the working hours text for multiple employees.
 * Updates employees.hours — a free-text string like "9am-5pm" or "12pm-8pm".
 *
 * Body:
 * {
 *   "employee_ids": [1, 2, 3],              // ─┐ supply any combination;
 *   "emails":       ["a@co.com"],           //  ├─ at least one required
 *   "names":        ["Alice Smith"],        // ─┘
 *   "hours": "9am-5pm"                      // required – any text, e.g. "8am-4pm", "12pm-8pm", "7:00 AM - 3:00 PM"
 * }
 */
function bulkChangeHours() {
    requirePermission('manage_schedules');
    global $db;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['hours']) || trim($data['hours']) === '') sendError('hours string required');

    $employeeIds  = resolveEmployeeIds($data);
    $hours        = trim($data['hours']);
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    $hasRange     = !empty($data['start_date']) && !empty($data['end_date']);

    // Try to extract a numeric hours value from strings like "9am-5pm" (8h) for custom_hours field
    $numericHours = isset($data['numeric_hours']) ? (float)$data['numeric_hours'] : null;

    $db->beginTransaction();
    try {
        // Always update the base employee record
        $params = array_merge([$hours], $employeeIds);
        $db->execute(
            "UPDATE employees SET hours = ?, updated_at = NOW() WHERE id IN ($placeholders)",
            $params
        );

        // If a date range is given, write per-day custom_hours overrides
        $overridesWritten = 0;
        if ($hasRange) {
            $userId      = $_SESSION['user_id'];
            $allowedDows = isset($data['days_of_week']) && is_array($data['days_of_week'])
                ? array_map('intval', $data['days_of_week']) : null;
            $skipDaysOff = !empty($data['skip_days_off']);
            $notes       = json_encode(['hours' => $hours, 'source' => 'bulk_change_hours']);

            $dates  = [];
            $cursor = new DateTime($data['start_date']);
            $end    = new DateTime($data['end_date']);
            if ($end < $cursor) sendError('end_date must be on or after start_date');
            while ($cursor <= $end) { $dates[] = $cursor->format('Y-m-d'); $cursor->modify('+1 day'); }

            $empSchedules = [];
            if ($skipDaysOff) {
                $rows = $db->fetchAll(
                    "SELECT id, weekly_schedule FROM employees WHERE id IN ($placeholders)", $employeeIds
                );
                foreach ($rows as $r) {
                    $empSchedules[$r['id']] = array_map('intval', explode(',', $r['weekly_schedule'] ?? '0,1,1,1,1,1,0'));
                }
            }

            foreach ($employeeIds as $empId) {
                foreach ($dates as $date) {
                    $dow = (int)(new DateTime($date))->format('w');
                    if ($allowedDows !== null && !in_array($dow, $allowedDows, true)) continue;
                    if ($skipDaysOff && isset($empSchedules[$empId]) && ($empSchedules[$empId][$dow] ?? 0) == 0) continue;

                    $existing = $db->fetchOne(
                        "SELECT id FROM schedule_overrides WHERE employee_id = ? AND override_date = ?",
                        [$empId, $date]
                    );
                    if ($existing) {
                        $db->execute(
                            "UPDATE schedule_overrides SET override_type = 'custom_hours', custom_hours = ?, notes = ? WHERE employee_id = ? AND override_date = ?",
                            [$numericHours, $notes, $empId, $date]
                        );
                    } else {
                        $db->insert(
                            "INSERT INTO schedule_overrides (employee_id, override_date, override_type, custom_hours, notes, created_by) VALUES (?,?,'custom_hours',?,?,?)",
                            [$empId, $date, $numericHours, $notes, $userId]
                        );
                    }
                    $overridesWritten++;
                }
            }
        }

        $db->commit();
        logAudit('BULK_CHANGE_HOURS', 'employees', null, null, [
            'employee_ids' => $employeeIds, 'hours' => $hours,
            'date_range'   => $hasRange ? [$data['start_date'], $data['end_date']] : null,
        ]);
        $response = ['success' => true, 'employees_updated' => count($employeeIds)];
        if ($hasRange) $response['overrides_written'] = $overridesWritten;
        sendResponse($response);

    } catch (Exception $e) {
        $db->rollback();
        sendError('bulk_change_hours failed: ' . $e->getMessage());
    }
}

/**
 * POST /api.php?action=bulk_change_weekly_pattern
 * Changes the 7-day work pattern for multiple employees.
 * Updates employees.weekly_schedule — the "0,1,1,1,1,1,0" pattern the app uses.
 *
 * Body:
 * {
 *   "employee_ids":    [1, 2, 3],          // ─┐ supply any combination;
 *   "emails":          ["a@co.com"],       //  ├─ at least one required
 *   "names":           ["Alice Smith"],    // ─┘
 *   "weekly_schedule": [0,1,1,1,1,1,0]    // required – 7 values, 0=off 1=working, index 0=Sun…6=Sat
 * }
 */
function bulkChangeWeeklyPattern() {
    requirePermission('manage_schedules');
    global $db;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['weekly_schedule']) || !is_array($data['weekly_schedule'])) sendError('weekly_schedule array required');
    if (count($data['weekly_schedule']) !== 7) sendError('weekly_schedule must have exactly 7 values (Sun–Sat)');

    $employeeIds = resolveEmployeeIds($data);
    $weeklyStr   = implode(',', array_map('intval', $data['weekly_schedule']));
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));

    $db->beginTransaction();
    try {
        $params = array_merge([$weeklyStr], $employeeIds);
        $db->execute(
            "UPDATE employees SET weekly_schedule = ?, updated_at = NOW() WHERE id IN ($placeholders)",
            $params
        );

        $db->commit();
        logAudit('BULK_CHANGE_WEEKLY_PATTERN', 'employees', null, null, [
            'employee_ids' => $employeeIds, 'weekly_schedule' => $weeklyStr
        ]);
        sendResponse(['success' => true, 'employees_updated' => count($employeeIds)]);

    } catch (Exception $e) {
        $db->rollback();
        sendError('bulk_change_weekly_pattern failed: ' . $e->getMessage());
    }
}

/**
 * POST /api.php?action=bulk_add_override
 * Adds a day-level override (pto, sick, holiday, custom_hours, off) to multiple
 * employees across a date range or on specific dates.
 *
 * Body:
 * {
 *   "employee_ids":  [1, 2, 3],                        // ─┐ supply any combination;
 *   "emails":        ["a@co.com"],                     //  ├─ at least one required
 *   "names":         ["Alice Smith"],                  // ─┘
 *   "type":          "holiday",                         // required – pto|sick|holiday|custom_hours|off
 *   "dates":         ["2026-07-04"],                    // use this OR start_date+end_date
 *   "start_date":    "2026-07-04",                      // use this + end_date instead of dates[]
 *   "end_date":      "2026-07-06",
 *   "days_of_week":  [1,2,3,4,5],                       // optional – limit to these days (0=Sun…6=Sat)
 *   "skip_days_off": true,                              // optional – skip days the employee is off per weekly_schedule
 *   "custom_hours":  4,                                 // required when type=custom_hours
 *   "notes":         "Independence Day"                 // optional comment
 * }
 */
function bulkAddOverride() {
    requirePermission('manage_schedules');
    global $db;

    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['type'])) sendError('type required');

    $validTypes = ['pto', 'sick', 'holiday', 'custom_hours', 'off'];
    $type = strtolower($data['type']);
    if (!in_array($type, $validTypes, true)) sendError('type must be one of: ' . implode(', ', $validTypes));

    $employeeIds = resolveEmployeeIds($data);
    $notes       = $data['notes'] ?? null;
    $customHours = isset($data['custom_hours']) ? (float)$data['custom_hours'] : null;
    $userId      = $_SESSION['user_id'];
    $skipDaysOff = !empty($data['skip_days_off']);

    // Build list of dates to apply
    $dates = [];
    if (!empty($data['dates']) && is_array($data['dates'])) {
        $dates = $data['dates'];
    } elseif (!empty($data['start_date']) && !empty($data['end_date'])) {
        $cursor  = new DateTime($data['start_date']);
        $endDate = new DateTime($data['end_date']);
        if ($endDate < $cursor) sendError('end_date must be on or after start_date');
        while ($cursor <= $endDate) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
        }
    } else {
        sendError('provide either dates[] or start_date + end_date');
    }

    // Optional day-of-week filter
    $allowedDows = isset($data['days_of_week']) && is_array($data['days_of_week'])
        ? array_map('intval', $data['days_of_week'])
        : null;

    // Load weekly_schedule for each employee if skip_days_off is on
    $empSchedules = [];
    if ($skipDaysOff) {
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $rows = $db->fetchAll(
            "SELECT id, weekly_schedule FROM employees WHERE id IN ($placeholders)",
            $employeeIds
        );
        foreach ($rows as $row) {
            $empSchedules[$row['id']] = array_map('intval', explode(',', $row['weekly_schedule'] ?? '0,1,1,1,1,1,0'));
        }
    }

    $affected = 0;
    $db->beginTransaction();
    try {
        foreach ($employeeIds as $empId) {
            foreach ($dates as $date) {
                $dow = (int)(new DateTime($date))->format('w'); // 0=Sun…6=Sat

                // Skip if not in allowed days_of_week
                if ($allowedDows !== null && !in_array($dow, $allowedDows, true)) continue;

                // Skip if employee is off that day per their weekly pattern
                if ($skipDaysOff && isset($empSchedules[$empId])) {
                    if (($empSchedules[$empId][$dow] ?? 0) == 0) continue;
                }

                $existing = $db->fetchOne(
                    "SELECT id FROM schedule_overrides WHERE employee_id = ? AND override_date = ?",
                    [$empId, $date]
                );
                if ($existing) {
                    $db->execute(
                        "UPDATE schedule_overrides SET override_type = ?, custom_hours = ?, notes = ? WHERE employee_id = ? AND override_date = ?",
                        [$type, $customHours, $notes, $empId, $date]
                    );
                } else {
                    $db->insert(
                        "INSERT INTO schedule_overrides (employee_id, override_date, override_type, custom_hours, notes, created_by) VALUES (?, ?, ?, ?, ?, ?)",
                        [$empId, $date, $type, $customHours, $notes, $userId]
                    );
                }
                $affected++;
            }
        }

        $db->commit();
        logAudit('BULK_ADD_OVERRIDE', 'schedule_overrides', null, null, [
            'employee_ids' => $employeeIds, 'type' => $type, 'dates_count' => count($dates)
        ]);
        sendResponse(['success' => true, 'overrides_written' => $affected]);

    } catch (Exception $e) {
        $db->rollback();
        sendError('bulk_add_override failed: ' . $e->getMessage());
    }
}

/**
 * POST /api.php?action=bulk_clear_overrides
 * Removes day-level overrides for multiple employees across a date range,
 * restoring them to their default weekly schedule.
 *
 * Body:
 * {
 *   "employee_ids": [1, 2, 3],    // ─┐ supply any combination;
 *   "emails":       ["a@co.com"], //  ├─ at least one required
 *   "names":        ["Alice"],    // ─┘
 *   "start_date":   "2026-03-01", // required
 *   "end_date":     "2026-03-31"  // required
 * }
 */
function bulkClearOverrides() {
    requirePermission('manage_schedules');
    global $db;

    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['start_date']) || empty($data['end_date'])) sendError('start_date and end_date required');

    $employeeIds  = resolveEmployeeIds($data);
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));

    $db->beginTransaction();
    try {
        $params = array_merge([$data['start_date'], $data['end_date']], $employeeIds);
        $db->execute(
            "DELETE FROM schedule_overrides
             WHERE override_date BETWEEN ? AND ?
               AND employee_id IN ($placeholders)",
            $params
        );

        $db->commit();
        logAudit('BULK_CLEAR_OVERRIDES', 'schedule_overrides', null, null, [
            'employee_ids' => $employeeIds,
            'start_date'   => $data['start_date'],
            'end_date'     => $data['end_date'],
        ]);
        sendResponse(['success' => true]);

    } catch (Exception $e) {
        $db->rollback();
        sendError('bulk_clear_overrides failed: ' . $e->getMessage());
    }
}

/**
 * POST /api.php?action=bulk_assign_team
 * Moves multiple employees to a new team.
 *
 * Body:
 * {
 *   "employee_ids": [1, 2, 3],  // ─┐ supply any combination;
 *   "emails":       [...],      //  ├─ at least one required
 *   "names":        [...],      // ─┘
 *   "team": "Support"           // required
 * }
 */
function bulkAssignTeam() {
    requirePermission('manage_employees');
    global $db;

    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['team'])) sendError('team required');

    $employeeIds  = resolveEmployeeIds($data);
    $team         = trim($data['team']);
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));

    $db->beginTransaction();
    try {
        $params = array_merge([$team], $employeeIds);
        $db->execute(
            "UPDATE employees SET team = ?, updated_at = NOW() WHERE id IN ($placeholders)",
            $params
        );
        $db->commit();
        logAudit('BULK_ASSIGN_TEAM', 'employees', null, null, [
            'employee_ids' => $employeeIds, 'team' => $team,
        ]);
        sendResponse(['success' => true, 'employees_updated' => count($employeeIds)]);
    } catch (Exception $e) {
        $db->rollback();
        sendError('bulk_assign_team failed: ' . $e->getMessage());
    }
}

/**
 * POST /api.php?action=bulk_assign_supervisor
 * Changes the supervisor for multiple employees.
 *
 * Body:
 * {
 *   "employee_ids": [1, 2, 3],   // ─┐ supply any combination;
 *   "emails":       [...],       //  ├─ at least one required
 *   "names":        [...],       // ─┘
 *   "supervisor": "Jane Smith"   // required – name string stored on employee record
 * }
 */
function bulkAssignSupervisor() {
    requirePermission('manage_employees');
    global $db;

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['supervisor']) || trim($data['supervisor']) === '') sendError('supervisor required');

    $employeeIds  = resolveEmployeeIds($data);
    $supervisor   = trim($data['supervisor']);
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));

    $db->beginTransaction();
    try {
        $params = array_merge([$supervisor], $employeeIds);
        $db->execute(
            "UPDATE employees SET supervisor = ?, updated_at = NOW() WHERE id IN ($placeholders)",
            $params
        );
        $db->commit();
        logAudit('BULK_ASSIGN_SUPERVISOR', 'employees', null, null, [
            'employee_ids' => $employeeIds, 'supervisor' => $supervisor,
        ]);
        sendResponse(['success' => true, 'employees_updated' => count($employeeIds)]);
    } catch (Exception $e) {
        $db->rollback();
        sendError('bulk_assign_supervisor failed: ' . $e->getMessage());
    }
}

// ================================================================
// AUDIT LOG ENDPOINTS
// ================================================================

/**
 * GET /api.php?action=get_audit_log
 * Returns recent audit log entries with pagination.
 * Requires manage_users permission (admin / manager / supervisor).
 *
 * Query params (all optional):
 *   limit      – max records per page (default 50, max 500)
 *   offset     – pagination offset (default 0)
 *   table_name – filter by table (employees, schedules, schedule_overrides, etc.)
 *   action     – partial keyword match on action column (e.g. SHIFT, OVERRIDE)
 *   user_id    – filter by which user performed the action
 */
function getAuditLog() {
    requirePermission('manage_users');
    global $db;

    $limit  = min((int)($_GET['limit']  ?? 50), 500);
    $offset = (int)($_GET['offset'] ?? 0);

    $conditions = [];
    $params     = [];

    if (!empty($_GET['table_name'])) {
        $conditions[] = 'al.table_name = ?';
        $params[]     = $_GET['table_name'];
    }
    if (!empty($_GET['action'])) {
        $conditions[] = 'al.action LIKE ?';
        $params[]     = '%' . $_GET['action'] . '%';
    }
    if (!empty($_GET['user_id'])) {
        $conditions[] = 'al.user_id = ?';
        $params[]     = (int)$_GET['user_id'];
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $entries = $db->fetchAll(
        "SELECT al.id, al.action, al.table_name, al.record_id,
                al.old_data, al.new_data, al.ip_address, al.created_at,
                u.username, u.email AS user_email
         FROM audit_log al
         LEFT JOIN users u ON al.user_id = u.id
         $where
         ORDER BY al.created_at DESC
         LIMIT $limit OFFSET $offset",
        $params
    );

    $total = $db->fetchOne(
        "SELECT COUNT(*) AS total FROM audit_log al $where",
        $params
    );

    sendResponse([
        'entries' => $entries,
        'count'   => count($entries),
        'total'   => (int)($total['total'] ?? 0),
        'limit'   => $limit,
        'offset'  => $offset,
    ]);
}

// ================================================================
// BACKUP ENDPOINTS
// ================================================================

// ================================================================
// API KEY ENDPOINTS (UAT / PROGRAMMATIC ACCESS)
// ================================================================

/**
 * POST /api.php?action=generate_api_key
 * Generates (or regenerates) an API key.
 *
 * Two ways to call this:
 *   1. Already authenticated (session or X-API-Key): no extra fields needed.
 *   2. Not authenticated: supply username + password in the JSON body.
 *      The credentials are verified and a key is issued for that account.
 *
 * Optional body field:
 *   user_id – generate a key for a different user (requires manage_users permission).
 *
 * Returns: { api_key: "...", user_id: N }
 */
function generateApiKey() {
    global $db;

    $requestBody = json_decode(file_get_contents('php://input'), true) ?? [];

    // ── Credential-based bootstrap (no session required) ─────────────────────
    if (!isset($_SESSION['user_id'])) {
        $username    = trim($requestBody['username'] ?? '');
        $password    = $requestBody['password'] ?? '';
        $googleToken = trim($requestBody['google_id_token'] ?? '');

        if ($googleToken !== '') {
            // ── Google SSO path ───────────────────────────────────────────────
            // Verify the ID token using Google's tokeninfo endpoint
            $tokenInfo = @json_decode(file_get_contents(
                'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($googleToken)
            ), true);

            if (empty($tokenInfo['email']) || empty($tokenInfo['sub'])) {
                sendError('Invalid or expired Google ID token', 401);
            }
            if (($tokenInfo['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
                sendError('Google token audience mismatch', 401);
            }

            $googleEmail = strtolower(trim($tokenInfo['email']));
            $googleId    = $tokenInfo['sub'];

            // Find the user by email or oauth_provider_id
            $user = $db->fetchOne(
                "SELECT id, role, username FROM users WHERE (LOWER(email) = ? OR oauth_provider_id = ?) AND active = 1 LIMIT 1",
                [$googleEmail, $googleId]
            );

            if (!$user) {
                sendError('No active account found for this Google account', 401);
            }

        } elseif ($username !== '' && $password !== '') {
            // ── Username + password path ──────────────────────────────────────
            $user = $db->fetchOne(
                "SELECT id, role, username, password_hash FROM users WHERE (username = ? OR email = ?) AND active = 1 LIMIT 1",
                [$username, $username]
            );

            $hash = $user['password_hash'] ?? '';
            if (!$user || !$hash || !password_verify($password, $hash)) {
                sendError('Invalid credentials', 401);
            }

        } else {
            sendError('Authentication required. Provide username+password, google_id_token, or an active session.', 401);
        }

        // Bootstrap session for this request
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['username']  = $user['username'];
    }

    $targetId = intval($requestBody['user_id'] ?? $_SESSION['user_id']);

    // Only admins / uat role can generate keys for other users
    if ($targetId !== (int)$_SESSION['user_id']) {
        requirePermission('manage_users');
    }

    $newKey = bin2hex(random_bytes(32)); // 64-char hex key
    $db->fetchOne("UPDATE users SET api_key = ? WHERE id = ?", [$newKey, $targetId]);

    sendResponse(['api_key' => $newKey, 'user_id' => $targetId]);
}

/**
 * POST /api.php?action=revoke_api_key
 * Revokes the API key for the current user (or a specific user if admin).
 */
function revokeApiKey() {
    requireAuth();
    global $db;

    $requestBody = json_decode(file_get_contents('php://input'), true) ?? [];
    $targetId    = intval($requestBody['user_id'] ?? $_POST['user_id'] ?? $_SESSION['user_id']);

    if ($targetId !== (int)$_SESSION['user_id']) {
        requirePermission('manage_users');
    }

    $db->fetchOne("UPDATE users SET api_key = NULL WHERE id = ?", [$targetId]);
    sendResponse(['success' => true, 'message' => 'API key revoked']);
}

// ================================================================

/**
 * POST /api.php?action=create_backup
 * Creates full system backup
 */
function createBackup() {
    requirePermission('manage_backups');
    global $db;
    
    $data = json_decode(file_get_contents('php://input'), true);
    $userId = $_SESSION['user_id'];
    
    $db->query("CALL sp_create_backup(?, ?, ?)", [
        $data['type'] ?? 'manual',
        $userId,
        $data['description'] ?? null
    ]);
    
    sendResponse(['success' => true]);
}

/**
 * GET /api.php?action=get_backups
 * Returns list of backups
 */
function getBackups() {
    requirePermission('manage_backups');
    global $db;
    
    $sql = "SELECT id, backup_type, file_size, created_at, created_by, description 
            FROM backups 
            ORDER BY created_at DESC 
            LIMIT 50";
    
    $backups = $db->fetchAll($sql);
    sendResponse(['backups' => $backups]);
}

// ================================================================
// ROUTE HANDLER
// ================================================================

try {
    switch ($action) {
        // Employees
        case 'get_employees':
            getEmployees();
            break;
        case 'get_employee':
            getEmployee();
            break;
        case 'search_employees':
            searchEmployees();
            break;
        case 'get_employees_by_skill':
            getEmployeesBySkill();
            break;
        case 'get_emails_by_team':
            getEmailsByTeam();
            break;
        case 'save_employee':
            saveEmployee();
            break;
        case 'delete_employee':
            deleteEmployee();
            break;
            
        // Schedules
        case 'get_schedule':
            getSchedule();
            break;
        case 'get_overrides_range':
            getOverridesRange();
            break;
        case 'copy_schedule':
            copySchedule();
            break;
        case 'swap_shifts':
            swapShifts();
            break;
        case 'save_schedule':
            saveSchedule();
            break;
        case 'save_override':
            saveOverride();
            break;
        case 'delete_override':
            deleteOverride();
            break;

        // Bulk – Employee Lookup
        case 'lookup_employees':
            lookupEmployees();
            break;

        // Bulk Schedule Operations
        case 'bulk_change_shift':
            bulkChangeShift();
            break;
        case 'bulk_change_hours':
            bulkChangeHours();
            break;
        case 'bulk_change_weekly_pattern':
            bulkChangeWeeklyPattern();
            break;
        case 'bulk_add_override':
            bulkAddOverride();
            break;
        case 'bulk_clear_overrides':
            bulkClearOverrides();
            break;
        case 'bulk_assign_team':
            bulkAssignTeam();
            break;
        case 'bulk_assign_supervisor':
            bulkAssignSupervisor();
            break;

        // Audit Log
        case 'get_audit_log':
            getAuditLog();
            break;

        // Users
        case 'get_users':
            getUsers();
            break;
        case 'save_user':
            saveUser();
            break;
            
        // MOTD
        case 'get_motd_messages':
            getMOTDMessages();
            break;
        case 'save_motd':
            saveMOTD();
            break;
        case 'deactivate_motd':
            deactivateMOTD();
            break;
            
        // Backups
        case 'create_backup':
            createBackup();
            break;
        case 'get_backups':
            getBackups();
            break;

        // API Key management (UAT / programmatic access)
        case 'generate_api_key':
            generateApiKey();
            break;
        case 'revoke_api_key':
            revokeApiKey();
            break;

        default:
            sendError('Invalid action', 400);
    }
    
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    sendError('An error occurred: ' . $e->getMessage(), 500);
}
