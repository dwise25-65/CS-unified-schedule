<?php
// 
// PATCHED BY SKILLS DISPLAY PATCHER - 2025-09-10T02:00:38.154Z
// Changes: Added MH/MA/WIN skills display to employee boxes
// 


// Schedule Management System - v4.0 (Enhanced with new themes)
// Main Application File

// Include the authentication and user management system
require_once __DIR__ . '/auth_user_management.php';

// Initialize authentication
initializeAuth();

// Require login for all operations
requireLogin();

// Release PHP session file lock early for read-only page loads.
// Sessions are file-locked by default; holding the lock during the entire page render
// causes concurrent requests (multiple tabs, quick navigation) to queue and wait.
// For normal GET page loads (no action being processed) we have already read everything
// we need from $_SESSION, so we can release the lock immediately.
// Writes made before this point (e.g. in requireLogin) are already flushed.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET['action'])) {
    session_write_close();
}

error_reporting(E_ALL);
ini_set('display_errors', 0);

// Configuration
// DATA_FILE (schedule_data.json) is intentionally removed — database is the single source of truth.
define('BACKUPS_DIR', __DIR__ . '/backups');
define('ACTIVITY_LOG_FILE', __DIR__ . '/activity_log.json');
define('TEMPLATES_FILE', __DIR__ . '/schedule_templates.json');
define('MOTD_FILE', __DIR__ . '/motd_data.json');
// define('HOLIDAYS_FILE', __DIR__ . '/holidays.json');
define('APP_VERSION', '4.0');

// ── MOTD Management Functions — DB-backed ─────────────────────────────────
// Messages stored in `motd_messages` table.
// show_anniversaries_global stored in `settings` table (key: show_anniversaries_global).

function motdEnsureTable() {
    // Use DB-backed flag so this truly runs once ever, not once per PHP request
    if (isMigrationApplied('motd_table_ready')) return;
    try {
        require_once __DIR__ . '/Database.php';
        $db  = Database::getInstance();
        $pdo = $db->getConnection();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS motd_messages (
                id                    INT AUTO_INCREMENT PRIMARY KEY,
                message               TEXT NOT NULL DEFAULT '',
                start_date            DATE NULL,
                end_date              DATE NULL,
                include_anniversaries TINYINT(1) NOT NULL DEFAULT 0,
                created_by            VARCHAR(200) NOT NULL DEFAULT '',
                updated_by            VARCHAR(200) NOT NULL DEFAULT '',
                created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Add any missing columns to existing tables (safe schema migrations)
        $colInfo      = $pdo->query("SHOW COLUMNS FROM motd_messages")->fetchAll(PDO::FETCH_ASSOC);
        $existingCols = array_column($colInfo, 'Field');
        $colTypes     = array_column($colInfo, 'Type', 'Field');
        if (!in_array('updated_by', $existingCols)) {
            $pdo->exec("ALTER TABLE motd_messages ADD COLUMN updated_by VARCHAR(200) NOT NULL DEFAULT ''");
        } elseif (stripos($colTypes['updated_by'] ?? '', 'int') !== false) {
            $pdo->exec("ALTER TABLE motd_messages MODIFY COLUMN updated_by VARCHAR(200) NOT NULL DEFAULT ''");
        }
        if (!in_array('created_by', $existingCols)) {
            $pdo->exec("ALTER TABLE motd_messages ADD COLUMN created_by VARCHAR(200) NOT NULL DEFAULT ''");
        } elseif (stripos($colTypes['created_by'] ?? '', 'int') !== false) {
            $pdo->exec("ALTER TABLE motd_messages MODIFY COLUMN created_by VARCHAR(200) NOT NULL DEFAULT ''");
        }
        if (!in_array('updated_at', $existingCols)) {
            $pdo->exec("ALTER TABLE motd_messages ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
        if (!in_array('created_at', $existingCols)) {
            $pdo->exec("ALTER TABLE motd_messages ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
        markMigrationApplied('motd_table_ready');

        // ── One-time migration: import existing JSON messages if DB is empty ──
        $count = (int)$pdo->query("SELECT COUNT(*) FROM motd_messages")->fetchColumn();
        if ($count === 0 && defined('MOTD_FILE') && file_exists(MOTD_FILE)) {
            $raw = json_decode(file_get_contents(MOTD_FILE), true);
            if ($raw && is_array($raw)) {
                $msgs = $raw['messages'] ?? [];
                // Handle old single-message format
                if (empty($msgs) && !empty($raw['message'])) {
                    $msgs = [[
                        'message'               => $raw['message'],
                        'start_date'            => null,
                        'end_date'              => null,
                        'include_anniversaries' => $raw['include_anniversaries'] ?? false,
                        'created_by'            => $raw['updated_by'] ?? '',
                    ]];
                }
                $ins = $pdo->prepare(
                    "INSERT INTO motd_messages (message, start_date, end_date, include_anniversaries, created_by, updated_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())"
                );
                foreach ($msgs as $m) {
                    $ins->execute([
                        $m['message']               ?? '',
                        $m['start_date']            ?: null,
                        $m['end_date']              ?: null,
                        ($m['include_anniversaries'] ?? false) ? 1 : 0,
                        $m['created_by']            ?? '',
                        $m['updated_by']            ?? $m['created_by'] ?? '',
                    ]);
                }
                // Migrate global anniversaries setting
                if (!empty($raw['show_anniversaries_global'])) {
                    $pdo->prepare(
                        "INSERT INTO settings (setting_key, setting_value, description)
                         VALUES ('show_anniversaries_global', '1', 'Always show work anniversaries in ticker')
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
                    )->execute();
                }
            }
        }
    } catch (Exception $e) {
        error_log('motdEnsureTable error: ' . $e->getMessage());
    }
}

function motdGetShowAnniversaries() {
    try {
        $db  = Database::getInstance();
        $pdo = $db->getConnection();
        $row = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'show_anniversaries_global' LIMIT 1");
        $row->execute();
        $r = $row->fetch(PDO::FETCH_ASSOC);
        return $r ? (bool)(int)$r['setting_value'] : false;
    } catch (Exception $e) {
        return false;
    }
}

function motdSetShowAnniversaries($val) {
    try {
        $db  = Database::getInstance();
        $pdo = $db->getConnection();
        $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value, description)
             VALUES ('show_anniversaries_global', ?, 'Always show work anniversaries in ticker')
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([$val ? '1' : '0']);
        return true;
    } catch (Exception $e) {
        error_log('motdSetShowAnniversaries error: ' . $e->getMessage());
        return false;
    }
}

function motdRowToArray($row) {
    return [
        'id'                    => 'motd_' . $row['id'],
        '_db_id'                => (int)$row['id'],
        'message'               => $row['message']               ?? '',
        'start_date'            => $row['start_date']            ?? null,
        'end_date'              => $row['end_date']              ?? null,
        'include_anniversaries' => (bool)(int)($row['include_anniversaries'] ?? 0),
        'created_by'            => $row['created_by']            ?? '',
        'updated_by'            => $row['updated_by']            ?? '',
        'created_at'            => $row['created_at']            ?? '',
        'updated_at'            => $row['updated_at']            ?? '',
    ];
}

function loadMOTD() {
    motdEnsureTable();
    try {
        $db   = Database::getInstance();
        $pdo  = $db->getConnection();
        $rows = $pdo->query("SELECT * FROM motd_messages ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $messages = array_map('motdRowToArray', $rows);
        return [
            'messages'                => $messages,
            'show_anniversaries_global' => motdGetShowAnniversaries(),
        ];
    } catch (Exception $e) {
        error_log('loadMOTD error: ' . $e->getMessage());
        return ['messages' => [], 'show_anniversaries_global' => false];
    }
}


function motdCreatedByValue($pdo, $name) {
    // Returns the right value for created_by/updated_by depending on column type.
    $colInfo  = $pdo->query("SHOW COLUMNS FROM motd_messages")->fetchAll(PDO::FETCH_ASSOC);
    $colTypes = array_column($colInfo, 'Type', 'Field');
    $isInt    = stripos($colTypes['created_by'] ?? '', 'int') !== false;
    if (!$isInt) {
        return $name; // Already VARCHAR — just use the name
    }
    // Column is INT with a FK to users.id — try to drop FK and convert to VARCHAR
    try {
        $fks = $pdo->query("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'motd_messages'
              AND COLUMN_NAME  IN ('created_by','updated_by')
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($fks as $fkName) {
            $pdo->exec("ALTER TABLE motd_messages DROP FOREIGN KEY `$fkName`");
        }
        $pdo->exec("ALTER TABLE motd_messages MODIFY COLUMN created_by VARCHAR(200) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE motd_messages MODIFY COLUMN updated_by VARCHAR(200) NOT NULL DEFAULT ''");
        return $name; // Migration done — use the real name
    } catch (Exception $e) {
        error_log('motd FK migration failed: ' . $e->getMessage());
    }
    // ALTER not permitted — fall back to storing the actual user ID (INT)
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE full_name = ? OR username = ? LIMIT 1");
        $stmt->execute([$name, $name]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) return (int)$user['id'];
    } catch (Exception $e) {
        error_log('motd user lookup failed: ' . $e->getMessage());
    }
    // Last resort: NULL (FK is ON DELETE SET NULL so column is nullable)
    return null;
}

function addMOTD($message, $startDate, $endDate, $includeAnniversaries, $createdBy) {
    motdEnsureTable();
    try {
        $db  = Database::getInstance();
        $pdo = $db->getConnection();
        $byVal = motdCreatedByValue($pdo, $createdBy);
        $pdo->prepare(
            "INSERT INTO motd_messages (message, start_date, end_date, include_anniversaries, created_by, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())"
        )->execute([$message, $startDate ?: null, $endDate ?: null, $includeAnniversaries ? 1 : 0, $byVal, $byVal]);
        $newId = (int)$pdo->lastInsertId();
        return $newId > 0 ? 'motd_' . $newId : false;
    } catch (Exception $e) {
        error_log('addMOTD error: ' . $e->getMessage());
        return false;
    }
}

function updateMOTD($id, $message, $startDate, $endDate, $includeAnniversaries, $updatedBy) {
    motdEnsureTable(); // ensures table exists AND all columns are present
    // id may be 'motd_123' (string) or plain int
    $dbId = (int)preg_replace('/[^0-9]/', '', $id);
    if ($dbId <= 0) return false;
    try {
        $db  = Database::getInstance();
        $pdo = $db->getConnection();
        $byVal = motdCreatedByValue($pdo, $updatedBy);
        $stmt = $pdo->prepare(
            "UPDATE motd_messages SET message=?, start_date=?, end_date=?, include_anniversaries=?, updated_by=?, updated_at=NOW() WHERE id=?"
        );
        $stmt->execute([$message, $startDate ?: null, $endDate ?: null, $includeAnniversaries ? 1 : 0, $byVal, $dbId]);
        return true;
    } catch (Exception $e) {
        error_log('updateMOTD error: ' . $e->getMessage());
        return false;
    }
}

function deleteMOTD($id) {
    motdEnsureTable();
    $dbId = (int)preg_replace('/[^0-9]/', '', $id);
    if ($dbId <= 0) return false;
    try {
        $db  = Database::getInstance();
        $pdo = $db->getConnection();
        $stmt = $pdo->prepare("DELETE FROM motd_messages WHERE id = ?");
        $stmt->execute([$dbId]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log('deleteMOTD error: ' . $e->getMessage());
        return false;
    }
}

function getActiveMOTDs() {
    $data  = loadMOTD();
    $today = date('Y-m-d');
    $activeMOTDs = [];
    foreach ($data['messages'] as $motd) {
        $startDate = $motd['start_date'];
        $endDate   = $motd['end_date'];
        if (empty($startDate) && empty($endDate)) { $activeMOTDs[] = $motd; continue; }
        $isActive = true;
        if (!empty($startDate) && $today < $startDate) $isActive = false;
        if (!empty($endDate)   && $today > $endDate)   $isActive = false;
        if ($isActive) $activeMOTDs[] = $motd;
    }
    return $activeMOTDs;
}

function getDisplayMOTD($employees) {
    $data                    = loadMOTD();
    $activeMOTDs             = getActiveMOTDs();
    $globalShowAnniversaries = $data['show_anniversaries_global'] ?? false;

    $messages         = [];
    $showAnniversaries = $globalShowAnniversaries;
    foreach ($activeMOTDs as $motd) {
        if (!empty($motd['message'])) $messages[] = $motd['message'];
        if ($motd['include_anniversaries'])         $showAnniversaries = true;
    }
    $combinedMessage = implode(' • • • ', $messages);

    $anniversaryMessage = '';
    if ($showAnniversaries) {
        $today   = new DateTime();
        $todayMD = $today->format('m-d');
        $anniversaries = [];
        foreach ($employees as $emp) {
            if (!empty($emp['start_date'])) {
                $startDate = new DateTime($emp['start_date']);
                if ($startDate->format('m-d') === $todayMD) {
                    $years = (int)$startDate->diff($today)->y;
                    if ($years > 0) $anniversaries[] = ['name' => $emp['name'], 'years' => $years];
                }
            }
        }
        if (!empty($anniversaries)) {
            $parts = [];
            foreach ($anniversaries as $a) {
                $parts[] = htmlspecialchars($a['name']) . ' - ' . $a['years'] . ' year' . ($a['years'] != 1 ? 's' : '') . ' with us!';
            }
            $anniversaryMessage = '🎉 Work Anniversaries Today: ' . implode(' • ', $parts);
        }
    }
    return [
        'message'           => $combinedMessage,
        'anniversary_message' => $anniversaryMessage,
        'has_content'       => !empty($combinedMessage) || !empty($anniversaryMessage),
    ];
}

// Ensure backups directory exists
if (!file_exists(BACKUPS_DIR)) {
    mkdir(BACKUPS_DIR, 0755, true);
}

// ── Migration tracking helpers ────────────────────────────────────────────────
// Replaces per-request static-variable guards. Stores a flag in the settings
// table so each one-time schema migration truly runs ONCE ever, not once per
// PHP request (PHP static vars reset on every request).
// Global cache for migration flags — populated once per request by isMigrationApplied().
$_migrationFlagCache = null;

function isMigrationApplied($key) {
    global $_migrationFlagCache;
    // On first call: fetch ALL migration flags in one query instead of one query per flag.
    // All subsequent calls are pure in-memory lookups — no DB round-trip.
    if ($_migrationFlagCache === null) {
        try {
            $db   = Database::getInstance();
            $rows = $db->fetchAll(
                "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE '_migration_%'"
            );
            $_migrationFlagCache = [];
            foreach ($rows as $row) {
                $_migrationFlagCache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {
            return false; // settings table not ready yet — let the migration run
        }
    }
    return ($_migrationFlagCache['_migration_' . $key] ?? '') === '1';
}
function markMigrationApplied($key) {
    global $_migrationFlagCache;
    try {
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO settings (setting_key, setting_value, description)
             VALUES (?, '1', 'One-time schema migration flag')
             ON DUPLICATE KEY UPDATE setting_value = '1'",
            ['_migration_' . $key]
        );
        // Update the in-memory cache so subsequent isMigrationApplied() calls
        // in this same request see the newly written flag immediately.
        if ($_migrationFlagCache !== null) {
            $_migrationFlagCache['_migration_' . $key] = '1';
        }
    } catch (Exception $e) { /* ignore — migration ran, flag just couldn't be saved */ }
}
// ─────────────────────────────────────────────────────────────────────────────

// Initialize data structures
$employees = [];
$scheduleOverrides = [];
$activityLog = [];
$scheduleTemplates = [];
$nextId = 1;
$currentYear = 2025;
$currentMonth = 6;
$message = '';
$messageType = '';

// Check for session messages (from redirects)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['messageType'] ?? 'success';
    unset($_SESSION['message'], $_SESSION['messageType']);
    // Prevent the browser from caching pages that contain a flash message.
    // Without this the browser caches the page with the banner baked in,
    // so auto-refresh / back-button navigation re-displays the old message
    // even though the session variable has already been cleared.
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }
}

// Employee levels
$levels = [
    '' => '-- No Level --',
    'ssa' => 'SSA',
    'ssa2' => 'SSA2',
    'tam' => 'TAM',
    'tam2' => 'TAM2',
    'SR. Supervisor' => 'SR. Supervisor',
    'SR. Manager' => 'SR. Manager',
    'manager' => 'Manager',
    'IMP Tech' => 'IMP Tech',
    'IMP Coordinator' => 'IMP Coordinator',
    'l1' => 'L1',
    'l2' => 'L2',
    'l3' => 'L3',
    'SecOps T1' => 'SecOps T1',
    'SecOps T2' => 'SecOps T2',
    'SecOps T3' => 'SecOps T3',
    'SecEng' => 'SecEng',
    'Supervisor' => 'Supervisor',
    'technical_writer' => 'Technical Writer',
    'trainer' => 'Trainer',
    'tech_coach' => 'Tech Coach'
];

// Teams
$teams = [
    'esg' => 'ESG', 
    'support' => 'Support',
    'windows' => 'Windows',
    'security' => 'Security',
    'secops_abuse' => 'SecOps/Abuse',
    'migrations' => 'Migrations',
    'learning_development' => 'Learning and Development',
    'Implementations' => 'Implementations',
    'Account Services' => 'Account Services',
    'Account Services Stellar' => 'Account Services Stellar'
];

// Supervisor/Manager level detection helper


function isSupervisorOrManagerLevelStrict($entity) {
    $lvlKey = strtolower(trim((string)($entity['level'] ?? '')));
    $lvlName = '';
    if (function_exists('getLevelName')) {
        $lvlName = strtolower(trim((string)getLevelName($entity['level'] ?? '')));
    }

    $isLeader = false;
    foreach ([$lvlKey, $lvlName] as $val) {
        if (strpos($val, 'supervisor') !== false) $isLeader = true;
        if (strpos($val, 'manager') !== false) $isLeader = true;
    }

    return $isLeader;
}

// Helper function for HTML escaping
function escapeHtml($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Generate team options HTML
function generateTeamOptions($includeAllTeams = false) {
    global $teams;
    
    $html = '';
    if ($includeAllTeams) {
        $html .= "<option value=\"all\">All Teams</option>\n";
    }
    foreach ($teams as $value => $label) {
        $html .= "<option value=\"{$value}\">{$label}</option>\n";
    }
    return $html;
}

// Generate level options HTML
function generateLevelOptions() {
    global $levels;
    $html = '';
    foreach ($levels as $value => $label) {
        $html .= "<option value=\"{$value}\">{$label}</option>\n";
    }
    return $html;
}

// ---- Leadership recognition helpers ----
function normalizeToArray($val) {
    if ($val === null) return [];
    if (is_array($val)) return $val;
    return [$val];
}

function isSupervisorOrManager($entity) {
    $vals = [];
    if (isset($entity['team'])) $vals[] = $entity['team'];
    if (isset($entity['role'])) $vals[] = $entity['role'];
    if (isset($entity['roles'])) {
        if (is_array($entity['roles'])) { $vals = array_merge($vals, $entity['roles']); }
        else { $vals[] = $entity['roles']; }
    }
    foreach ($vals as $v) {
        $t = strtolower(trim((string)$v));
        if ($t === '') continue;
        if (strpos($t, 'supervisor') !== false) return true;
        if (strpos($t, 'senior manager') !== false) return true;
        if (strpos($t, 'sr. manager') !== false) return true;
        if (strpos($t, 'sr manager') !== false) return true;
        if (strpos($t, 'manager') !== false) return true;
        if (strpos($t, 'mgr') !== false) return true;
    }
    return false;
}
// Generate supervisor options HTML (now role-aware)
function generateSupervisorOptions() {
    global $employees;
    $html = '<option value="">None</option>';

    $supervisorsAndManagers = array_values(array_filter($employees, 'isSupervisorOrManagerLevelStrict'));
    if (empty($supervisorsAndManagers)) {
        $supervisorsAndManagers = array_values(array_filter($employees, 'isSupervisorOrManagerLevelStrict'));
    }
    usort($supervisorsAndManagers, function($a, $b) {
        return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
    });

    foreach ($supervisorsAndManagers as $supervisor) {
        $labelRole = (function_exists('getLevelName') ? getLevelName($supervisor['level'] ?? '') : '') ?: formatLeadershipLabel($supervisor);
        $html .= sprintf(
            '<option value="%d">%s (%s)</option>',
            (int)$supervisor['id'],
            escapeHtml($supervisor['name']),
            strtoupper($labelRole)
        );
    }
    return $html;
}

// Check if current user can edit a specific employee's schedule
function canEditEmployeeSchedule($employeeId) {
    // Admin, Manager, and Supervisor can edit all schedules they have access to
    if (hasPermission('edit_schedule')) {
        return true;
    }
    
    // Employees can only edit their own schedule
    if (hasPermission('edit_own_schedule')) {
        $currentEmployee = getCurrentUserEmployee();
        return $currentEmployee && $currentEmployee['id'] == $employeeId;
    }
    
    return false;
}

// Get supervisor name by ID
function getSupervisorName($supervisorId) {
    global $employees;
    if (!$supervisorId) return 'None';
    
    foreach ($employees as $employee) {
        if ($employee['id'] == $supervisorId) {
            return $employee['name'];
        }
    }
    return 'Unknown';
}


// Activity Log Functions
// Ensures the activity_log DB table exists
function ensureActivityLogTable() {
    if (isMigrationApplied('activity_log_table')) return;
    try {
        $pdo = Database::getInstance()->getConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
            id           VARCHAR(36)  NOT NULL PRIMARY KEY,
            timestamp    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_id      INT          NULL,
            user_name    VARCHAR(200) NOT NULL DEFAULT '',
            user_role    VARCHAR(50)  NOT NULL DEFAULT '',
            action       VARCHAR(100) NOT NULL DEFAULT '',
            details      TEXT         NULL,
            target_type  VARCHAR(100) NOT NULL DEFAULT '',
            target_id    VARCHAR(100) NULL,
            ip_address   VARCHAR(45)  NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        markMigrationApplied('activity_log_table');
    } catch (Exception $e) {
        error_log("ensureActivityLogTable error: " . $e->getMessage());
    }
}

function loadActivityLog() {
    global $activityLog;

    $dbLogs   = [];
    $jsonLogs = [];

    // Load from DB
    try {
        ensureActivityLogTable();
        $pdo  = Database::getInstance()->getConnection();
        $rows = $pdo->query(
            "SELECT * FROM activity_log ORDER BY timestamp DESC LIMIT 500"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Helper: pick the first key that exists in a row, with fallback value
        $pick = function(array $row, array $keys, $default = '') {
            foreach ($keys as $k) {
                if (array_key_exists($k, $row)) return $row[$k];
            }
            return $default;
        };

        $dbLogs = array_map(function($r) use ($pick) {
            return [
                'id'          => $pick($r, ['id']),
                'timestamp'   => $pick($r, ['timestamp', 'created_at', 'log_time']),
                'user_id'     => $pick($r, ['user_id', 'userId'], null),
                'user_name'   => $pick($r, ['user_name', 'userName', 'username']),
                'user_role'   => $pick($r, ['user_role', 'userRole', 'role']),
                'action'      => $pick($r, ['action', 'action_type', 'log_action', 'event']),
                'details'     => $pick($r, ['details', 'description', 'message', 'log_details']),
                'target_type' => $pick($r, ['target_type', 'targetType', 'object_type']),
                'target_id'   => $pick($r, ['target_id', 'targetId', 'object_id'], null),
                'ip_address'  => $pick($r, ['ip_address', 'ip', 'ipAddress', 'remote_addr']),
            ];
        }, $rows);
    } catch (Exception $e) {
        error_log("loadActivityLog DB error: " . $e->getMessage());
    }

    // Always also load JSON — it holds historical entries that predate the DB
    if (file_exists(ACTIVITY_LOG_FILE)) {
        $data = json_decode(file_get_contents(ACTIVITY_LOG_FILE), true);
        if ($data && isset($data['logs'])) {
            $jsonLogs = $data['logs'];
        }
    }

    // Merge: DB entries are authoritative; JSON fills in anything not already in DB
    $merged = $dbLogs;
    $dbIds  = array_flip(array_column($dbLogs, 'id'));
    foreach ($jsonLogs as $entry) {
        if (!isset($dbIds[$entry['id'] ?? ''])) {
            $merged[] = $entry;
        }
    }

    // Sort newest-first and cap at 500
    usort($merged, function($a, $b) {
        return strcmp($b['timestamp'], $a['timestamp']);
    });

    $activityLog = array_slice($merged, 0, 500);
}


function addActivityLog($action, $details = '', $targetType = '', $targetId = null) {
    global $activityLog;

    // ── Allowlist: only record the events that matter ──────────────────────────
    // Everything else (logins, logouts, schedule changes, MOTD edits, etc.)
    // is silently discarded so the log stays focused and readable.
    $allowedActions = [
        'employee_add',       // New employee created
        'employee_edit',      // Employee record updated
        'backup_create',      // Backup snapshot created (manual or auto)
        'backup_restore',     // Snapshot fully restored
        'merge_restore',      // Merge-restore operation
        'selective_restore',  // Selective restore operation
    ];
    if (!in_array($action, $allowedActions, true)) {
        return;
    }
    // ───────────────────────────────────────────────────────────────────────────

    $currentUser = getCurrentUser();
    if (!$currentUser) return;

    $logEntry = [
        'id'          => uniqid('al_', true),
        'timestamp'   => date('Y-m-d H:i:s'),
        'user_id'     => $currentUser['id'],
        'user_name'   => $currentUser['full_name'] ?? $currentUser['username'] ?? '',
        'user_role'   => $currentUser['role'] ?? '',
        'action'      => $action,
        'details'     => $details,
        'target_type' => $targetType,
        'target_id'   => $targetId,
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];

    // Write to DB using only columns that actually exist in the live table.
    // Previous schema migrations revealed the original table used different
    // column names (e.g. action_type vs action). Rather than guess, we read
    // SHOW COLUMNS once per request (cached in a static) and build the INSERT
    // dynamically so it works regardless of the table's exact schema.
    try {
        ensureActivityLogTable();
        $pdo = Database::getInstance()->getConnection();

        // Column metadata: name → type string, fetched once per request.
        // Storing the type lets us skip auto-increment integer PKs (the actual
        // table has id INT AUTO_INCREMENT, not VARCHAR(36) as the migration
        // assumed — inserting a uniqid string into an INT column was causing
        // SQLSTATE[22007] "Incorrect integer value" and aborting every INSERT).
        static $_alCols = null;
        if ($_alCols === null) {
            $_alCols = [];
            foreach ($pdo->query("SHOW COLUMNS FROM activity_log")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $_alCols[$col['Field']] = strtolower($col['Type']); // e.g. 'int(11)', 'varchar(36)'
            }
        }

        // Returns true when a column is an integer type with AUTO_INCREMENT —
        // we must omit it from the INSERT and let MySQL assign the value.
        $isAutoInt = function(string $colName) use ($pdo): bool {
            static $_extras = null;
            if ($_extras === null) {
                $_extras = [];
                foreach ($pdo->query("SHOW COLUMNS FROM activity_log")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $_extras[$c['Field']] = strtolower($c['Extra']);
                }
            }
            return isset($_extras[$colName]) && strpos($_extras[$colName], 'auto_increment') !== false;
        };

        // Map each logical field → [value, ordered list of possible column names].
        // Keyed by logical name so identical values on different fields never
        // collide (using values as array keys caused silent INSERT failures).
        $candidates = [
            'id'          => [$logEntry['id'],          ['id']],
            'timestamp'   => [$logEntry['timestamp'],   ['timestamp', 'created_at', 'log_time']],
            'user_id'     => [$logEntry['user_id'],     ['user_id', 'userId']],
            'user_name'   => [$logEntry['user_name'],   ['user_name', 'userName', 'username']],
            'user_role'   => [$logEntry['user_role'],   ['user_role', 'userRole', 'role']],
            'action'      => [$logEntry['action'],      ['action', 'action_type', 'log_action', 'event']],
            'details'     => [$logEntry['details'],     ['details', 'description', 'message', 'log_details']],
            'target_type' => [$logEntry['target_type'], ['target_type', 'targetType', 'object_type']],
            'target_id'   => [$logEntry['target_id'],   ['target_id', 'targetId', 'object_id']],
            'ip_address'  => [$logEntry['ip_address'],  ['ip_address', 'ip', 'ipAddress', 'remote_addr']],
        ];

        $cols = [];
        $vals = [];
        foreach ($candidates as [$value, $names]) {
            foreach ($names as $name) {
                if (isset($_alCols[$name])) {
                    // Skip auto-increment columns — MySQL assigns the value
                    if ($isAutoInt($name)) break;
                    $cols[] = "`$name`";
                    $vals[] = $value;
                    break; // use first match, skip remaining aliases
                }
            }
        }

        if (!empty($cols)) {
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT INTO activity_log (" . implode(', ', $cols) . ") VALUES ($placeholders)")
                ->execute($vals);
        }

    } catch (Exception $e) {
        error_log("addActivityLog DB error: " . $e->getMessage());
        // Fallback: keep in-memory so the rest of the request can still read it.
        // Guard against null: $activityLog may not be initialised yet if this
        // function is called early (e.g. during logout at line 15, before the
        // global $activityLog = [] at line 402 has executed). PHP 8.4 made
        // array_unshift(null) a fatal TypeError; 7.4 only issued a warning.
        if (!is_array($activityLog)) $activityLog = [];
        array_unshift($activityLog, $logEntry);
        if (count($activityLog) > 500) $activityLog = array_slice($activityLog, 0, 500);
        // JSON fallback — ACTIVITY_LOG_FILE is defined at line 36 but this
        // function can be called at line 15 before that constant exists.
        if (defined('ACTIVITY_LOG_FILE')) {
            $data = ['logs' => $activityLog, 'lastUpdated' => date('c')];
            @file_put_contents(ACTIVITY_LOG_FILE, json_encode($data, JSON_PRETTY_PRINT));
        }
    }

    // Keep in-memory global up to date so callers see the new entry without re-querying
    if (!is_array($activityLog)) $activityLog = [];
    array_unshift($activityLog, $logEntry);
    if (count($activityLog) > 500) $activityLog = array_slice($activityLog, 0, 500);
}

function getCurrentUserName() {
    $currentUser = getCurrentUser();
    if ($currentUser && isset($currentUser['full_name'])) {
        return $currentUser['full_name'];
    }
    return 'Unknown User';
}

/**
 * Generate a full SQL dump (DROP/CREATE TABLE + INSERT rows) for the given tables.
 * Returns a SQL string suitable for storing or adding to a ZIP as database_dump.sql.
 */
function generateSqlDump($db, $tables) {
    $pdo = $db->getConnection();
    $edt = new DateTime('now', new DateTimeZone('America/New_York'));

    $sql  = "-- ============================================================\n";
    $sql .= "-- Schedule App - Full Database Backup\n";
    $sql .= "-- Generated : " . $edt->format('Y-m-d H:i:s T') . "\n";
    $sql .= "-- Database  : employee_scheduling2\n";
    $sql .= "-- Tables    : " . implode(', ', $tables) . "\n";
    $sql .= "-- ============================================================\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $sql .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
    $sql .= "SET NAMES utf8mb4;\n\n";

    foreach ($tables as $table) {
        try {
            // --- CREATE TABLE ---
            $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
            $sql .= "-- --------------------------------------------------------\n";
            $sql .= "-- Table structure for `{$table}`\n";
            $sql .= "-- --------------------------------------------------------\n\n";
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $createRow[1] . ";\n\n";

            // --- INSERT rows ---
            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $sql .= "-- Data for `{$table}` (" . count($rows) . " rows)\n";
                $sql .= "INSERT INTO `{$table}` VALUES\n";
                $valueLines = [];
                foreach ($rows as $row) {
                    $vals = array_map(function($v) use ($pdo) {
                        if ($v === null) return 'NULL';
                        return $pdo->quote((string)$v);
                    }, array_values($row));
                    $valueLines[] = '  (' . implode(', ', $vals) . ')';
                }
                $sql .= implode(",\n", $valueLines) . ";\n\n";
            } else {
                $sql .= "-- (no rows in `{$table}`)\n\n";
            }
        } catch (Exception $e) {
            $sql .= "-- ERROR dumping `{$table}`: " . $e->getMessage() . "\n\n";
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

function getRecentActivity($limit = 20) {
    global $activityLog;
    // Lazy-load: only pull 500 rows from DB when someone actually asks for the log.
    // Normal page renders never call this, so the heavy SELECT is skipped entirely.
    if (empty($activityLog)) {
        loadActivityLog();
    }
    return array_slice($activityLog, 0, $limit);
}

function formatActivityTime($timestamp) {
    $time = new DateTime($timestamp);
    $now = new DateTime();
    $diff = $now->diff($time);
    
    if ($diff->days > 0) {
        return $diff->days . ' day' . ($diff->days > 1 ? 's' : '') . ' ago';
    } elseif ($diff->h > 0) {
        return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    } elseif ($diff->i > 0) {
        return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    } else {
        return 'Just now';
    }
}

function getActivityIcon($action) {
    $icons = [
        'schedule_change' => '📅',
        'employee_add' => '👤➕',
        'employee_edit' => '👤✏️',
        'employee_delete' => '👤🗑️',
        'user_add' => '👥➕',
        'user_edit' => '👥✏️',
        'user_delete' => '👥🗑️',
        'profile_update' => '👤✏️',
        'backup_create' => '💾',
        'backup_upload' => '📤',
        'backup_download' => '📥',
        'backup_delete' => '🗑️',
        'bulk_change' => '📅🔄',
        'template_create' => '📋➕',
        'template_delete' => '📋🗑️',
        'login' => '🔓',
        'logout' => '🚪',
        'timeout' => '⏰'
    ];
    
    return $icons[$action] ?? '📋';
}

// ============================================================================
// USER-EMPLOYEE LINKING FUNCTIONS (Google SSO Integration)
// ============================================================================

/**
 * Get or create employee record for a logged-in user
 * This ensures every user has an employee record for schedule access
 */
/**
 * Sync user role based on employee level
 * Ensures user permissions match their employee level in the schedule
 * IMPORTANT: Only syncs when employee level indicates a higher role
 * Does NOT downgrade roles unless explicitly set
 */
function syncUserRoleFromEmployeeLevel($userId, $employeeLevel, $forceSync = false) {
    global $users;
    
    // Map employee levels to user roles
    $levelToRoleMap = [
        'sr supervisor' => 'supervisor',
        'supervisor' => 'supervisor',
        'manager' => 'manager',
        'admin' => 'admin'
    ];
    
    // Normalize level: lowercase, trim, and remove punctuation (periods, etc)
    $normalizedLevel = strtolower(trim($employeeLevel ?? ''));
    $normalizedLevel = preg_replace('/[^\w\s]/', '', $normalizedLevel); // Remove punctuation
    $normalizedLevel = preg_replace('/\s+/', ' ', $normalizedLevel); // Normalize whitespace
    $normalizedLevel = trim($normalizedLevel);
    
    // If employee level is empty/null and we're not forcing sync, don't change anything
    if (empty($normalizedLevel) && !$forceSync) {
        return; // Don't downgrade user just because employee level is empty
    }
    
    // Get the new role from the mapping (or keep as employee if not in map)
    $newRole = $levelToRoleMap[$normalizedLevel] ?? null;
    
    // If level doesn't map to anything (empty or unrecognized), don't change role
    if ($newRole === null && !$forceSync) {
        return;
    }
    
    // If forceSync is true with empty level, check if we should downgrade
    if ($forceSync && $newRole === null) {
        // Don't downgrade from supervisor/manager/admin to employee just because level is empty
        // Only downgrade if explicitly editing and the user was already employee
        foreach ($users as $user) {
            if ($user['id'] === $userId) {
                $currentRole = $user['role'];
                // If currently supervisor/manager/admin, keep that role unless level explicitly says otherwise
                if (in_array($currentRole, ['supervisor', 'manager', 'admin'])) {
                    error_log("PROTECTION: Not downgrading user $userId from '$currentRole' to 'employee' due to empty level");
                    return; // Don't downgrade!
                }
                break;
            }
        }
        $newRole = 'employee';
    }
    
    // Update user role directly in DB
    foreach ($users as &$user) {
        if ($user['id'] === $userId) {
            $oldRole = $user['role'];

            if ($oldRole !== $newRole) {
                // Write to DB
                try {
                    $db  = Database::getInstance();
                    $pdo = $db->getConnection();
                    $pdo->prepare("UPDATE users SET role = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$newRole, $userId]);
                } catch (Exception $e) {
                    error_log("syncUserRoleFromEmployeeLevel DB error: " . $e->getMessage());
                }

                // Keep in-memory global in sync
                $user['role'] = $newRole;

                // Update session if this is the current user
                if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $userId) {
                    $_SESSION['user_role'] = $newRole;
                }

                addActivityLog(
                    'user_edit',
                    "User role automatically updated from '$oldRole' to '$newRole' based on employee level",
                    'user',
                    $userId
                );
            }
            break;
        }
    }
    unset($user);
}

function getOrCreateUserEmployee($userId) {
    global $users, $employees, $nextId;
    
    // Find the user
    $user = getUserById($userId);
    if (!$user) {
        error_log("getOrCreateUserEmployee: User ID $userId not found");
        return null;
    }
    
    // Check if user already has an employee record (by user_id)
    foreach ($employees as $employee) {
        if (isset($employee['user_id']) && $employee['user_id'] == $userId) {
            return $employee;
        }
    }
    
    // Check by email match (for existing employees)
    if (!empty($user['email'])) {
        foreach ($employees as &$employee) {
            if (!empty($employee['email']) && 
                strtolower($employee['email']) === strtolower($user['email'])) {
                // Link this employee to the user
                $employee['user_id'] = $userId;
                
                // Set schedule_access if not already set
                if (!isset($employee['schedule_access'])) {
                    // Admins/Managers don't need schedule_access - their ROLE gives them access
                    // Only set for non-admin/manager users with "all" team access
                    $employee['schedule_access'] = '';
                    if ($user['team'] === 'all' && !in_array($user['role'], ['admin', 'manager'])) {
                        $employee['schedule_access'] = 'all';
                    }
                }
                
                // Update user with employee_id link (bidirectional) — write directly to DB
                try {
                    $db  = Database::getInstance();
                    $pdo = $db->getConnection();
                    // employee_id column may not exist on all installs; use meta or just skip if absent
                    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'employee_id'")->fetch();
                    if ($cols) {
                        $pdo->prepare("UPDATE users SET employee_id = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([$employee['id'], $userId]);
                    }
                } catch (Exception $e) {
                    error_log("getOrCreateUserEmployee link DB error: " . $e->getMessage());
                }
                // Keep in-memory global in sync
                foreach ($GLOBALS['users'] as &$u) {
                    if ($u['id'] === $userId) {
                        $u['employee_id'] = $employee['id'];
                        break;
                    }
                }
                unset($u);
                
                // DON'T auto-sync role here - this runs on every page load
                // Role sync should only happen when explicitly creating/editing employees
                
                if (saveData()) {
                    addActivityLog(
                        'employee_edit',
                        "Automatically linked {$user['full_name']} to existing employee record (ID: {$employee['id']})",
                        'employee',
                        $employee['id']
                    );
                }
                return $employee;
            }
        }
    }
    
    // No employee record found - DON'T auto-create for existing users
    // Only auto-create during Google SSO signup (handled in processGoogleSSOLogin)
    error_log("getOrCreateUserEmployee: No employee record for user $userId ({$user['full_name']}). Manual creation may be required.");
    
    // Note: We return null here so the user can still log in
    // The UI should detect this and offer to create an employee record
    return null;
}

/**
 * Get current logged-in user's employee record
 * Creates one automatically if it doesn't exist
 */
function getCurrentUserEmployee() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    return getOrCreateUserEmployee($_SESSION['user_id']);
}

/**
 * Check if current user/employee can view a specific team's schedule
 * 
 * SIMPLIFIED: Everyone can view all team schedules.
 * The 'team' field is just for organizational purposes (which team they belong to).
 * No access restrictions on viewing.
 * 
 * @param string $teamToView The team whose schedule we want to view
 * @return bool Always returns true - everyone can view all teams
 */
function canViewTeamSchedule($teamToView) {
    // Everyone can view all team schedules
    return true;
}

/**
 * Get list of teams current user can view
 * 
 * SIMPLIFIED: Everyone can view all teams.
 * 
 * @return array Always returns ['all'] - everyone has full visibility
 */
function getAllowedTeams() {
    // Everyone can view all teams
    return ['all'];
}

/**
 * Find employee by user ID
 */
function getEmployeeByUserId($userId) {
    global $employees;
    
    foreach ($employees as $employee) {
        if (isset($employee['user_id']) && $employee['user_id'] == $userId) {
            return $employee;
        }
    }
    
    return null;
}

/**
 * Get user by employee ID
 */
function getUserByEmployeeId($employeeId) {
    global $employees, $users;
    
    $userId = null;
    foreach ($employees as $employee) {
        if ($employee['id'] == $employeeId && isset($employee['user_id'])) {
            $userId = $employee['user_id'];
            break;
        }
    }
    
    if (!$userId) return null;
    
    return getUserById($userId);
}

/**
 * Manually link an employee to a user
 */
function linkEmployeeToUser($employeeId, $userId) {
    global $employees;
    
    // Verify user exists
    $user = getUserById($userId);
    if (!$user) {
        return false;
    }
    
    // Find and link employee
    foreach ($employees as &$employee) {
        if ($employee['id'] == $employeeId) {
            $employee['user_id'] = $userId;
            
            if (saveData()) {
                addActivityLog(
                    'employee_edit',
                    "Manually linked employee {$employee['name']} to user {$user['full_name']}",
                    'employee',
                    $employeeId
                );
                return true;
            }
            return false;
        }
    }
    
    return false;
}

/**
 * Unlink employee from user
 */
function unlinkEmployeeFromUser($employeeId) {
    global $employees;
    
    foreach ($employees as &$employee) {
        if ($employee['id'] == $employeeId) {
            $oldUserId = $employee['user_id'] ?? null;
            $employee['user_id'] = null;
            
            if (saveData()) {
                if ($oldUserId) {
                    $user = getUserById($oldUserId);
                    $userName = $user ? $user['full_name'] : "User #$oldUserId";
                    addActivityLog(
                        'employee_edit',
                        "Unlinked employee {$employee['name']} from user {$userName}",
                        'employee',
                        $employeeId
                    );
                }
                return true;
            }
            return false;
        }
    }
    
    return false;
}

/**
 * Get statistics about user-employee linking
 */
function getUserEmployeeLinkStats() {
    global $users, $employees;
    
    $stats = [
        'total_users' => count($users),
        'total_employees' => count($employees),
        'linked_employees' => 0,
        'unlinked_employees' => 0,
        'users_without_employees' => 0,
        'google_sso_users' => 0
    ];
    
    // Count linked employees
    foreach ($employees as $employee) {
        if (isset($employee['user_id']) && $employee['user_id'] !== null) {
            $stats['linked_employees']++;
        } else {
            $stats['unlinked_employees']++;
        }
    }
    
    // Count users without employees
    foreach ($users as $user) {
        $hasEmployee = false;
        foreach ($employees as $employee) {
            if (isset($employee['user_id']) && $employee['user_id'] == $user['id']) {
                $hasEmployee = true;
                break;
            }
        }
        if (!$hasEmployee) {
            $stats['users_without_employees']++;
        }
        
        // Count Google SSO users
        if (isset($user['auth_method']) && $user['auth_method'] === 'google_sso') {
            $stats['google_sso_users']++;
        }
    }
    
    return $stats;
}

/**
 * Get list of unlinked employees (those without user accounts)
 */
function getUnlinkedEmployees() {
    global $employees;
    
    $unlinked = [];
    foreach ($employees as $employee) {
        if (!isset($employee['user_id']) || $employee['user_id'] === null) {
            $unlinked[] = $employee;
        }
    }
    
    return $unlinked;
}

/**
 * Get list of users without employee records
 */
function getUsersWithoutEmployees() {
    global $users, $employees;
    
    $usersWithoutEmployees = [];
    
    foreach ($users as $user) {
        $hasEmployee = false;
        foreach ($employees as $employee) {
            if (isset($employee['user_id']) && $employee['user_id'] == $user['id']) {
                $hasEmployee = true;
                break;
            }
        }
        
        if (!$hasEmployee) {
            $usersWithoutEmployees[] = $user;
        }
    }
    
    return $usersWithoutEmployees;
}

/**
 * Bulk link users to employees by email matching
 * Use this once after implementation to link existing records
 */
function bulkLinkByEmail() {
    global $users, $employees;
    
    $results = [
        'total_users' => count($users),
        'total_employees' => count($employees),
        'linked' => 0,
        'already_linked' => 0,
        'no_match' => 0,
        'details' => []
    ];
    
    foreach ($users as $user) {
        // Check if user already has employee
        $hasEmployee = false;
        foreach ($employees as $emp) {
            if (isset($emp['user_id']) && $emp['user_id'] == $user['id']) {
                $hasEmployee = true;
                $results['already_linked']++;
                break;
            }
        }
        
        if ($hasEmployee) continue;
        
        // Try to find matching employee by email
        if (!empty($user['email'])) {
            $matched = false;
            foreach ($employees as &$employee) {
                if (!empty($employee['email']) && 
                    strtolower($employee['email']) === strtolower($user['email']) &&
                    (!isset($employee['user_id']) || $employee['user_id'] === null)) {
                    
                    // Link them
                    $employee['user_id'] = $user['id'];
                    $matched = true;
                    $results['linked']++;
                    $results['details'][] = [
                        'user' => $user['full_name'],
                        'email' => $user['email'],
                        'employee' => $employee['name'],
                        'employee_id' => $employee['id']
                    ];
                    break;
                }
            }
            unset($employee);
            
            if (!$matched) {
                $results['no_match']++;
            }
        } else {
            $results['no_match']++;
        }
    }
    
    if ($results['linked'] > 0) {
        saveData();
        addActivityLog(
            'bulk_link',
            "Bulk linked {$results['linked']} users to employees by email",
            'system',
            null
        );
    }
    
    return $results;
}

// Schedule Template Functions
function ensureScheduleTemplatesTable() {
    if (isMigrationApplied('schedule_templates_table')) return;
    try {
        $pdo = Database::getInstance()->getConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS schedule_templates (
            id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(200) NOT NULL DEFAULT '',
            description VARCHAR(500) NOT NULL DEFAULT '',
            schedule    VARCHAR(20)  NOT NULL DEFAULT '0111110',
            created_by  VARCHAR(200) NOT NULL DEFAULT 'System',
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        markMigrationApplied('schedule_templates_table');
    } catch (Exception $e) {
        error_log("ensureScheduleTemplatesTable error: " . $e->getMessage());
    }
}

function loadScheduleTemplates() {
    global $scheduleTemplates;

    // Try DB first
    try {
        ensureScheduleTemplatesTable();
        $pdo  = Database::getInstance()->getConnection();
        $rows = $pdo->query("SELECT * FROM schedule_templates ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            $scheduleTemplates = array_map(function($r) {
                // Convert stored string "0111110" back to array [0,1,1,1,1,1,0]
                $schedArr = array_map('intval', str_split($r['schedule']));
                // Pad or trim to exactly 7 elements
                while (count($schedArr) < 7) $schedArr[] = 0;
                $schedArr = array_slice($schedArr, 0, 7);
                return [
                    'id'          => (int)$r['id'],
                    'name'        => $r['name'],
                    'description' => $r['description'],
                    'schedule'    => $schedArr,
                    'created_by'  => $r['created_by'],
                    'created_at'  => $r['created_at'],
                ];
            }, $rows);
            return;
        }
    } catch (Exception $e) {
        error_log("loadScheduleTemplates DB error, falling back to JSON: " . $e->getMessage());
    }

    // JSON fallback
    if (file_exists(TEMPLATES_FILE)) {
        $data = json_decode(file_get_contents(TEMPLATES_FILE), true);
        if ($data && isset($data['templates'])) {
            $scheduleTemplates = $data['templates'];
        }
    }

    // Seed defaults if still empty
    if (empty($scheduleTemplates)) {
        $scheduleTemplates = [
            ['id' => 1, 'name' => 'Standard Mon-Fri', 'description' => 'Monday through Friday work schedule', 'schedule' => [0,1,1,1,1,1,0], 'created_by' => 'System', 'created_at' => date('c')],
            ['id' => 2, 'name' => 'Weekend Shift',    'description' => 'Saturday and Sunday work schedule',   'schedule' => [1,0,0,0,0,0,1], 'created_by' => 'System', 'created_at' => date('c')],
            ['id' => 3, 'name' => 'Full Week',         'description' => 'Seven days a week schedule',          'schedule' => [1,1,1,1,1,1,1], 'created_by' => 'System', 'created_at' => date('c')],
        ];
        saveScheduleTemplates();
    }
}

function saveScheduleTemplates() {
    global $scheduleTemplates;

    // Try to write to DB
    try {
        ensureScheduleTemplatesTable();
        $pdo = Database::getInstance()->getConnection();
        $pdo->exec("DELETE FROM schedule_templates");
        $stmt = $pdo->prepare(
            "INSERT INTO schedule_templates (id, name, description, schedule, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($scheduleTemplates as $t) {
            $schedStr = implode('', array_map('intval', $t['schedule']));
            $stmt->execute([
                $t['id'],
                $t['name'],
                $t['description'],
                $schedStr,
                $t['created_by'] ?? 'System',
                $t['created_at'] ?? date('Y-m-d H:i:s'),
            ]);
        }
        return true;
    } catch (Exception $e) {
        error_log("saveScheduleTemplates DB error: " . $e->getMessage());
    }

    // JSON fallback
    $data = ['templates' => $scheduleTemplates, 'lastUpdated' => date('c')];
    return file_put_contents(TEMPLATES_FILE, json_encode($data, JSON_PRETTY_PRINT)) !== false;
}

function getNextTemplateId() {
    global $scheduleTemplates;
    $maxId = 0;
    foreach ($scheduleTemplates as $template) {
        if ($template['id'] > $maxId) {
            $maxId = $template['id'];
        }
    }
    return $maxId + 1;
}

function addScheduleTemplate($name, $description, $schedule) {
    global $scheduleTemplates;
    
    $currentUser = getCurrentUser();
    if (!$currentUser) return false;
    
    $template = [
        'id' => getNextTemplateId(),
        'name' => $name,
        'description' => $description,
        'schedule' => $schedule,
        'created_by' => $currentUser['full_name'],
        'created_at' => date('c')
    ];
    
    $scheduleTemplates[] = $template;
    
    if (saveScheduleTemplates()) {
        addActivityLog('template_create', "Created schedule template: $name ($description)", 'template', $template['id']);
        return true;
    }
    
    return false;
}

function deleteScheduleTemplate($templateId) {
    global $scheduleTemplates;
    
    $templateName = 'Unknown';
    foreach ($scheduleTemplates as $key => $template) {
        if ($template['id'] === $templateId) {
            $templateName = $template['name'];
            unset($scheduleTemplates[$key]);
            break;
        }
    }
    
    $scheduleTemplates = array_values($scheduleTemplates);
    
    if (saveScheduleTemplates()) {
        addActivityLog('template_delete', "Deleted schedule template: $templateName", 'template', $templateId);
        return true;
    }
    
    return false;
}

function formatScheduleDisplay($schedule) {
    $days = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    $workDays = [];
    
    for ($i = 0; $i < 7; $i++) {
        if ($schedule[$i] == 1) {
            $workDays[] = $days[$i];
        }
    }
    
    return implode(', ', $workDays);
}

// Load data from file or latest backup
/**
 * Load employees and schedule overrides from the database.
 * Populates $employees (array of associative arrays matching the legacy format)
 * and $scheduleOverrides (keyed by "{empId}-{year}-{month0idx}-{day}").
 * Also restores $currentYear / $currentMonth from the `settings` table.
 */
function loadData() {
    global $employees, $scheduleOverrides, $nextId, $currentYear, $currentMonth;

    try {
        $db  = Database::getInstance();
        $pdo = $db->getConnection();

        // ── One-time migration: add 'on' to override_type ENUM if missing ─────
        if (!isMigrationApplied('enum_on_override_type')) {
            try {
                $pdo->exec(
                    "ALTER TABLE schedule_overrides
                     MODIFY COLUMN override_type
                     ENUM('pto','sick','holiday','custom_hours','off','on') NOT NULL"
                );
                markMigrationApplied('enum_on_override_type');
            } catch (\Exception $me) { /* already has 'on' or table doesn't exist yet */ }
        }

        // ── One-time migration: add slack_id column to users table if missing ─
        if (!isMigrationApplied('users_slack_id')) {
            try {
                $colCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'slack_id'")->fetch();
                if (!$colCheck) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN slack_id VARCHAR(50) NULL DEFAULT NULL");
                }
                markMigrationApplied('users_slack_id');
            } catch (\Exception $me) { /* ignore */ }
        }

        // ── One-time migration: add slack_id column to employees table if missing ─
        if (!isMigrationApplied('employees_slack_id')) {
            try {
                $colCheck = $pdo->query("SHOW COLUMNS FROM employees LIKE 'slack_id'")->fetch();
                if (!$colCheck) {
                    $pdo->exec("ALTER TABLE employees ADD COLUMN slack_id VARCHAR(50) NULL DEFAULT NULL");
                }
                markMigrationApplied('employees_slack_id');
            } catch (\Exception $me) { /* ignore */ }
        }

        // ── One-time migration: ensure google_picture column exists in users ─
        // google_auth_config.php writes to profile_photo_url; display code also reads
        // google_picture (old JSON-migration field). Keep both in sync.
        if (!isMigrationApplied('users_google_picture')) {
            try {
                $colCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'google_picture'")->fetch();
                if (!$colCheck) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN google_picture TEXT NULL DEFAULT NULL");
                }
                markMigrationApplied('users_google_picture');
            } catch (\Exception $me) { /* ignore */ }
        }

        // ── One-time migration: ensure profile_photo_url column exists in users ─
        if (!isMigrationApplied('users_profile_photo_url')) {
            try {
                $colCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_photo_url'")->fetch();
                if (!$colCheck) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN profile_photo_url TEXT NULL DEFAULT NULL");
                }
                markMigrationApplied('users_profile_photo_url');
            } catch (\Exception $me) { /* ignore */ }
        }

        // ── One-time migration: ensure google_profile_photo column exists in users ─
        // This is the ORIGINAL JSON-era column where Google photo URLs were stored.
        if (!isMigrationApplied('users_google_profile_photo')) {
            try {
                $colCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'google_profile_photo'")->fetch();
                if (!$colCheck) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN google_profile_photo TEXT NULL DEFAULT NULL");
                }
                markMigrationApplied('users_google_profile_photo');
            } catch (\Exception $me) { /* ignore */ }
        }

        // ── One-time migration: ensure profile_photo column exists in users ────
        if (!isMigrationApplied('users_profile_photo')) {
            try {
                $colCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_photo'")->fetch();
                if (!$colCheck) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(500) NULL DEFAULT NULL");
                }
                markMigrationApplied('users_profile_photo');
            } catch (\Exception $me) { /* ignore */ }
        }

        // ── One-time migration: ensure activity_log has all expected columns ──
        // The table existed before the migration that defined its schema, so it
        // may be missing columns (notably 'timestamp') that the INSERT expects.
        // SQLSTATE[42S22] "Unknown column 'timestamp'" crashes addActivityLog()
        // and, on PHP 8.4, the subsequent array_unshift(null) becomes a fatal.
        if (!isMigrationApplied('activity_log_add_missing_cols')) {
            try {
                $alCols = [];
                foreach ($pdo->query("SHOW COLUMNS FROM activity_log")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $alCols[] = $c['Field'];
                }
                if (!in_array('timestamp', $alCols)) {
                    $pdo->exec("ALTER TABLE activity_log ADD COLUMN timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER id");
                }
                if (!in_array('user_id', $alCols)) {
                    $pdo->exec("ALTER TABLE activity_log ADD COLUMN user_id INT NULL");
                }
                if (!in_array('user_name', $alCols)) {
                    $pdo->exec("ALTER TABLE activity_log ADD COLUMN user_name VARCHAR(200) NOT NULL DEFAULT ''");
                }
                if (!in_array('user_role', $alCols)) {
                    $pdo->exec("ALTER TABLE activity_log ADD COLUMN user_role VARCHAR(50) NOT NULL DEFAULT ''");
                }
                if (!in_array('target_type', $alCols)) {
                    $pdo->exec("ALTER TABLE activity_log ADD COLUMN target_type VARCHAR(100) NOT NULL DEFAULT ''");
                }
                if (!in_array('target_id', $alCols)) {
                    $pdo->exec("ALTER TABLE activity_log ADD COLUMN target_id VARCHAR(100) NULL");
                }
                if (!in_array('ip_address', $alCols)) {
                    $pdo->exec("ALTER TABLE activity_log ADD COLUMN ip_address VARCHAR(45) NOT NULL DEFAULT ''");
                }
                markMigrationApplied('activity_log_add_missing_cols');
            } catch (\Exception $me) {
                error_log("Migration activity_log_add_missing_cols failed: " . $me->getMessage());
            }
        }

        // ── One-time migration: add 'action' and 'details' to activity_log ─────
        // These were omitted from activity_log_add_missing_cols (now applied),
        // so a second pass is needed with a new key.
        if (!isMigrationApplied('activity_log_add_missing_cols_v2')) {
            try {
                $alCols = [];
                foreach ($pdo->query("SHOW COLUMNS FROM activity_log")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $alCols[] = $c['Field'];
                }
                if (!in_array('action', $alCols)) {
                    $pdo->exec("ALTER TABLE activity_log ADD COLUMN action VARCHAR(100) NOT NULL DEFAULT ''");
                }
                if (!in_array('details', $alCols)) {
                    $pdo->exec("ALTER TABLE activity_log ADD COLUMN details TEXT NULL");
                }
                markMigrationApplied('activity_log_add_missing_cols_v2');
            } catch (\Exception $me) {
                error_log("Migration activity_log_add_missing_cols_v2 failed: " . $me->getMessage());
            }
        }

        // ── One-time migration: fix activity_log NOT NULL columns with no default
        // The original table used different column names (e.g. action_type instead
        // of action) and some NOT NULL columns have no DEFAULT, causing MySQL to
        // reject every INSERT. This migration reads the actual schema and assigns
        // a safe empty-string default to every such column so inserts never fail.
        if (!isMigrationApplied('activity_log_fix_defaults')) {
            try {
                $alRows = $pdo->query("SHOW COLUMNS FROM activity_log")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($alRows as $col) {
                    // Only touch NOT NULL columns that have no default value
                    if ($col['Null'] === 'NO' && $col['Default'] === null && $col['Key'] !== 'PRI') {
                        $colName = $col['Field'];
                        $colType = strtoupper($col['Type']);
                        // TEXT/BLOB types cannot have a DEFAULT in older MySQL — skip them
                        if (strpos($colType, 'TEXT') !== false || strpos($colType, 'BLOB') !== false) {
                            // Make these nullable instead so the INSERT never fails
                            $pdo->exec("ALTER TABLE activity_log MODIFY COLUMN `{$colName}` {$col['Type']} NULL");
                        } else {
                            $pdo->exec("ALTER TABLE activity_log MODIFY COLUMN `{$colName}` {$col['Type']} NOT NULL DEFAULT ''");
                        }
                    }
                }
                markMigrationApplied('activity_log_fix_defaults');
            } catch (\Exception $me) {
                error_log("Migration activity_log_fix_defaults failed: " . $me->getMessage());
            }
        }

        // ── One-time migration: widen profile_photo to TEXT ──────────────────
        // The column was originally created as VARCHAR(255) before the migration
        // system existed. Google profile photo URLs can exceed 255 chars, causing
        // SQLSTATE[22001] "Data too long" on every Google sign-in. profile_photo
        // now stores only Google OAuth URLs (no uploaded file paths remain).
        if (!isMigrationApplied('users_profile_photo_widen_text')) {
            try {
                $colDef = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_photo'")->fetch(PDO::FETCH_ASSOC);
                // Widen if column exists and is still a VARCHAR of any length
                if ($colDef && stripos($colDef['Type'], 'varchar') !== false) {
                    $pdo->exec("ALTER TABLE users MODIFY COLUMN profile_photo TEXT NULL DEFAULT NULL");
                }
                markMigrationApplied('users_profile_photo_widen_text');
            } catch (\Exception $me) {
                error_log("Migration users_profile_photo_widen_text failed: " . $me->getMessage());
            }
        }

        // ── Load employees ────────────────────────────────────────────────────
        $rows = $pdo
            ->query('SELECT * FROM employees WHERE active = 1 ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);

        $employees = [];
        $maxId     = 0;

        foreach ($rows as $row) {
            // Parse skills JSON stored in the `skills` column (or `notes`)
            $skills = ['mh' => false, 'ma' => false, 'win' => false];
            $rawSkills = $row['skills'] ?? '';
            if ($rawSkills) {
                $decoded = json_decode($rawSkills, true);
                if (is_array($decoded)) {
                    $skills = [
                        'mh'  => (bool)($decoded['mh']  ?? false),
                        'ma'  => (bool)($decoded['ma']  ?? false),
                        'win' => (bool)($decoded['win'] ?? false),
                    ];
                }
            }

            // Parse weekly_schedule stored as "0,1,1,1,1,1,0"
            $scheduleStr = $row['weekly_schedule'] ?? '';
            if ($scheduleStr) {
                $schedule = array_map('intval', explode(',', $scheduleStr));
            } else {
                $schedule = [0, 1, 1, 1, 1, 1, 0];
            }

            $emp = [
                'id'              => (int)$row['id'],
                'name'            => $row['name'],
                'team'            => $row['team']       ?? '',
                'shift'           => (int)($row['shift'] ?? 1),
                'hours'           => $row['hours']       ?? '',
                'level'           => $row['level']       ?? '',
                'email'           => $row['email']       ?? '',
                'supervisor_id'   => isset($row['supervisor_id']) && $row['supervisor_id'] !== null ? (int)$row['supervisor_id'] : null,
                'schedule'        => $schedule,
                'skills'          => $skills,
                'user_id'         => isset($row['user_id']) ? (int)$row['user_id'] : null,
                // schedule_access is NOT a database column — it is a runtime-only
                // computed field. All employees see all team schedules by default.
                // The UI field in Edit Employee is reserved for future use only.
                // It is intentionally set to '' here and never written to the DB.
                'schedule_access' => '',
                'start_date'      => $row['hire_date']   ?? '',
                'notes'           => $row['notes']       ?? '',
                'slack_id'        => $row['slack_id']    ?? null,
            ];


            $employees[] = $emp;
            if ($emp['id'] > $maxId) {
                $maxId = $emp['id'];
            }
        }

        $nextId = $maxId + 1;

        // ── Load schedule overrides ───────────────────────────────────────────
        // DB stores: employee_id, override_date (DATE), override_type, notes
        // App expects keyed array: "{empId}-{year}-{month0idx}-{day}" => ['status'=>…, 'comment'=>…, …]
        $overrideRows = $pdo
            ->query(
                'SELECT employee_id, override_date, override_type, notes
                 FROM schedule_overrides
                 ORDER BY override_date'
            )
            ->fetchAll(PDO::FETCH_ASSOC);

        $scheduleOverrides = [];
        foreach ($overrideRows as $or) {
            $date  = new DateTime($or['override_date']);
            $year  = (int)$date->format('Y');
            $month = (int)$date->format('n') - 1;  // convert to 0-indexed (JS style)
            $day   = (int)$date->format('j');
            $empId = (int)$or['employee_id'];

            $key = "{$empId}-{$year}-{$month}-{$day}";

            $overrideType = $or['override_type'];
            $notes        = $or['notes'] ?? '';

            // For custom_hours and 'on', the notes column stores the hours string
            // (not a comment) — never set it as comment or it shows as a spurious note.
            // For all other statuses (sick, pto, off, holiday) notes IS the comment.
            if ($overrideType === 'custom_hours') {
                $override = ['status' => $overrideType];
                if ($notes !== '') $override['customHours'] = $notes;
            } elseif ($overrideType === 'on') {
                $override = ['status' => $overrideType];
                if ($notes !== '') $override['hours'] = $notes;
            } else {
                $override = [
                    'status'  => $overrideType,
                    'comment' => $notes,
                ];
            }

            $scheduleOverrides[$key] = $override;
        }

        // ── Load currentYear / currentMonth from settings ─────────────────────
        $settingsRows = $pdo
            ->query(
                "SELECT setting_key, setting_value FROM settings
                 WHERE setting_key IN ('current_year','current_month')"
            )
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        if (isset($settingsRows['current_year'])) {
            $currentYear = (int)$settingsRows['current_year'];
        }
        if (isset($settingsRows['current_month'])) {
            // DB stores calendar month (1-indexed); convert to 0-indexed for app
            $currentMonth = (int)$settingsRows['current_month'] - 1;
        }

        // Defaults if settings not yet seeded
        if (!$currentYear)  $currentYear  = (int)date('Y');
        if (!isset($currentMonth) || $currentMonth < 0) {
            $currentMonth = (int)date('n') - 1;
        }

    } catch (Exception $e) {
        error_log('loadData() database error: ' . $e->getMessage());
        // Leave $employees / $scheduleOverrides as empty arrays so page still renders
        global $message, $messageType;
        $message     = '⛔ Failed to load data from database: ' . htmlspecialchars($e->getMessage());
        $messageType = 'error';
    }
}

// Find the most recent .json backup file (used by the Backups tab UI)
function findLatestBackup() {
    if (!is_dir(BACKUPS_DIR)) {
        return null;
    }
    
    $files = scandir(BACKUPS_DIR);
    if ($files === false) {
        return null;
    }
    
    $backups = [];
    foreach ($files as $file) {
        if (substr($file, -5) === '.json' && strpos($file, 'schedule_backup_') === 0) {
            $date = str_replace(['schedule_backup_', '.json'], '', $file);
            $backups[$date] = $file;
        }
    }
    
    if (empty($backups)) {
        return null;
    }
    
    krsort($backups);
    return reset($backups);
}

/**
 * Normalise schedule overrides from either storage format to the app's
 * in-memory format: array keyed by "{empId}-{year}-{month0idx}-{day}".
 *
 * create_backup / auto-backup store the raw DB rows:
 *   [ ["employee_id"=>23, "override_date"=>"2025-12-01", "override_type"=>"pto", ...], … ]
 *
 * upload_backup / pre-restore snapshots store the app-format directly:
 *   { "23-2025-11-0-1": {"status":"pto","comment":""}, … }
 *
 * Returns the app-format array ready for $scheduleOverrides assignment.
 */
function normalizeScheduleOverrides(array $raw): array {
    if (empty($raw)) return [];

    // Detect DB-row format: first element is an assoc array with 'override_date'
    $first = reset($raw);
    if (!is_array($first) || !array_key_exists('override_date', $first)) {
        // Already in app-format — sanitize and return.
        // A previous bug caused comment to be populated with the hours/customHours
        // value for 'custom_hours' and 'on' entries. Strip any such spurious comments
        // so they are never persisted or shown to users.
        foreach ($raw as $key => &$entry) {
            if (!is_array($entry)) continue;
            $st = $entry['status'] ?? '';
            if ($st === 'custom_hours') {
                // comment should never echo customHours — remove it
                if (isset($entry['comment']) && $entry['comment'] === ($entry['customHours'] ?? null)) {
                    unset($entry['comment']);
                }
            } elseif ($st === 'on') {
                // comment should never echo hours — remove it
                if (isset($entry['comment']) && $entry['comment'] === ($entry['hours'] ?? null)) {
                    unset($entry['comment']);
                }
            }
        }
        unset($entry);
        return $raw;
    }

    $appFormat = [];
    foreach ($raw as $row) {
        if (empty($row['employee_id']) || empty($row['override_date'])) continue;
        try {
            $date   = new DateTime($row['override_date']);
            $year   = (int)$date->format('Y');
            $month0 = (int)$date->format('n') - 1;   // 0-indexed like JS
            $day    = (int)$date->format('j');
            $empId  = (int)$row['employee_id'];
            $key    = "{$empId}-{$year}-{$month0}-{$day}";

            $overrideType = $row['override_type'] ?? '';
            $notes        = $row['notes']         ?? '';

            $entry = ['status' => $overrideType];

            if ($overrideType === 'custom_hours') {
                // notes column holds the custom hours string — NOT a comment
                if ($notes !== '') $entry['customHours'] = $notes;
            } elseif ($overrideType === 'on') {
                // notes column holds the working-hours string — NOT a comment
                if ($notes !== '') $entry['hours'] = $notes;
            } else {
                // For all other statuses (sick, pto, off, holiday, etc.)
                // notes is a genuine admin/sick comment — preserve it
                $entry['comment'] = $notes;
            }
            $appFormat[$key] = $entry;
        } catch (Exception $e) {
            // skip malformed rows
        }
    }
    return $appFormat;
}

/**
 * Normalise a single employee record from either a DB-format backup or an
 * app-format backup so it is compatible with the in-memory format expected
 * by saveData().
 *
 * DB-format backups (create_backup / auto-backup) store raw database rows:
 *   • weekly_schedule  →  "0,1,1,1,1,1,0"   (string, needs splitting into array)
 *   • hire_date        →  "2022-01-15"        (app uses start_date key)
 *   • skills           →  '{"mh":true,...}'   (JSON string, not array)
 *
 * App-format backups (upload_backup / pre-restore snapshots) already use
 * schedule / start_date / skills-as-array — this function is safe to call on both.
 */
function normalizeBackupEmployee(array $emp): array {
    // ── 1. weekly_schedule (string) → schedule (7-element int array) ─────────
    if (!array_key_exists('schedule', $emp)) {
        $wsStr = $emp['weekly_schedule'] ?? '';
        if ($wsStr !== '') {
            $parts = array_map('intval', explode(',', $wsStr));
            while (count($parts) < 7) $parts[] = 0;
            $emp['schedule'] = array_slice($parts, 0, 7);
        } else {
            $emp['schedule'] = [0, 1, 1, 1, 1, 1, 0]; // Mon-Fri safe default
        }
    }

    // ── 2. hire_date → start_date ─────────────────────────────────────────────
    if (!array_key_exists('start_date', $emp) && array_key_exists('hire_date', $emp)) {
        $emp['start_date'] = $emp['hire_date'];
    }

    // ── 3. Ensure optional scalar fields have safe defaults ──────────────────
    if (!isset($emp['supervisor_id']))        $emp['supervisor_id'] = null;
    if (!isset($emp['level']))                $emp['level']         = '';
    if (!isset($emp['email']))                $emp['email']         = '';
    if (!array_key_exists('hours', $emp))     $emp['hours']         = '';
    if (!isset($emp['notes']))                $emp['notes']         = '';

    // ── 4. Decode skills JSON string → typed boolean array ───────────────────
    $rawSk = $emp['skills'] ?? [];
    if (is_string($rawSk) && $rawSk !== '') {
        $rawSk = json_decode($rawSk, true) ?? [];
    }
    $emp['skills'] = [
        'mh'  => (bool)(is_array($rawSk) ? ($rawSk['mh']  ?? false) : false),
        'ma'  => (bool)(is_array($rawSk) ? ($rawSk['ma']  ?? false) : false),
        'win' => (bool)(is_array($rawSk) ? ($rawSk['win'] ?? false) : false),
    ];

    return $emp;
}

/**
 * Persist in-memory $employees and $scheduleOverrides back to the database.
 *
 * Employee changes → UPDATE employees SET weekly_schedule, shift, hours, level,
 *                    skills, notes WHERE id = ?
 * Override changes → Full replace strategy:
 *   1. DELETE all overrides for employees present in $employees
 *   2. INSERT current $scheduleOverrides
 * currentYear/currentMonth → settings table
 *
 * Returns true on success, false on failure.
 */
function saveData(): bool {
    global $employees, $scheduleOverrides, $currentYear, $currentMonth;

    try {
        $db  = Database::getInstance();
        $pdo = $db->getConnection();

        $pdo->beginTransaction();

        // ── 1. Persist employee changes (INSERT new, UPDATE existing) ────────
        $stmtEmp = $pdo->prepare(
            'INSERT INTO employees
                (id, name, email, team, weekly_schedule, shift, hours, level, skills, notes, supervisor_id, hire_date, slack_id, active, updated_at)
             VALUES
                (:id, :name, :email, :team, :ws, :shift, :hours, :level, :skills, :notes, :supervisor_id, :hire_date, :slack_id, 1, NOW())
             ON DUPLICATE KEY UPDATE
                name            = VALUES(name),
                email           = VALUES(email),
                team            = VALUES(team),
                weekly_schedule = VALUES(weekly_schedule),
                shift           = VALUES(shift),
                hours           = VALUES(hours),
                level           = VALUES(level),
                skills          = VALUES(skills),
                notes           = VALUES(notes),
                supervisor_id   = VALUES(supervisor_id),
                hire_date       = VALUES(hire_date),
                slack_id        = VALUES(slack_id),
                active          = 1,
                updated_at      = NOW()'
        );

        foreach ($employees as $emp) {
            $weeklyStr = implode(',', $emp['schedule'] ?? [0,1,1,1,1,1,0]);
            // Skills can arrive as a PHP array (normal path) or a JSON string (from DB backup rows).
            // Normalize to array first so json_encode() never double-encodes.
            $rawSk = $emp['skills'] ?? [];
            if (is_string($rawSk) && $rawSk !== '') {
                $rawSk = json_decode($rawSk, true) ?? [];
            }
            $skills = json_encode([
                'mh'  => (bool)(is_array($rawSk) ? ($rawSk['mh']  ?? false) : false),
                'ma'  => (bool)(is_array($rawSk) ? ($rawSk['ma']  ?? false) : false),
                'win' => (bool)(is_array($rawSk) ? ($rawSk['win'] ?? false) : false),
            ]);
            $hireDate  = !empty($emp['start_date']) ? $emp['start_date'] : null;
            $stmtEmp->execute([
                ':id'            => (int)$emp['id'],
                ':name'          => $emp['name']  ?? '',
                ':email'         => $emp['email'] ?? '',
                ':team'          => $emp['team']  ?? '',
                ':ws'            => $weeklyStr,
                ':shift'         => (int)($emp['shift'] ?? 1),
                ':hours'         => $emp['hours']  ?? '',
                ':level'         => $emp['level']  ?? '',
                ':skills'        => $skills,
                ':notes'         => $emp['notes']  ?? '',
                ':supervisor_id' => isset($emp['supervisor_id']) && $emp['supervisor_id'] ? (int)$emp['supervisor_id'] : null,
                ':hire_date'     => $hireDate,
                ':slack_id'      => !empty($emp['slack_id']) ? $emp['slack_id'] : null,
            ]);
        }

        // ── 1.5. DELETE employees no longer in memory ────────────────────────────
        // Get current employee IDs from the in-memory array
        $currentIds = array_column($employees, 'id');
        $currentIds = array_filter(array_map('intval', $currentIds));

        if (!empty($currentIds)) {
            // Delete employees that exist in DB but not in memory
            $placeholders = implode(',', array_fill(0, count($currentIds), '?'));
            $pdo->prepare(
                "DELETE FROM employees WHERE id NOT IN ($placeholders)"
            )->execute($currentIds);
        } else {
            // If no employees left in memory, delete all
            $pdo->exec("DELETE FROM employees");
        }

        // ── 2. Replace schedule overrides ────────────────────────────────────
        // Collect employee IDs we manage
        $empIds = array_column($employees, 'id');
        $empIds = array_map('intval', $empIds);

        if (!empty($empIds)) {
            $placeholders = implode(',', array_fill(0, count($empIds), '?'));
            $pdo->prepare(
                "DELETE FROM schedule_overrides WHERE employee_id IN ($placeholders)"
            )->execute($empIds);
        }

        if (!empty($scheduleOverrides)) {
            $stmtOvr = $pdo->prepare(
                'INSERT IGNORE INTO schedule_overrides
                   (employee_id, override_date, override_type, notes)
                 VALUES (:emp_id, :date, :type, :notes)'
            );

            // Daily key format: {empId}-{year}-{month0idx}-{day}
            $dailyPattern = '/^(\d+)-(\d{4})-(\d+)-(\d+)$/';

            // Enum values accepted by DB — 'on' marks working on a normally-off day
            $validTypes = ['pto','sick','holiday','custom_hours','off','on'];

            foreach ($scheduleOverrides as $key => $override) {
                if (!preg_match($dailyPattern, $key, $m)) {
                    continue; // skip shift-change keys and other non-date formats
                }

                $empId    = (int)$m[1];
                $year     = (int)$m[2];
                $month0   = (int)$m[3];
                $day      = (int)$m[4];

                // Detect ISO-style keys from legacy JSON backups where the month component
                // is a 1-indexed calendar month (e.g. "262-2026-03-01" = March 1, not April 1).
                // Heuristic: if the raw month string is zero-padded (leading zero) OR the
                // parsed month0 value is >= 12 (impossible as 0-indexed), treat it as calMonth
                // directly rather than adding 1.
                $rawMonth = $m[3];
                if (strlen($rawMonth) === 2 && $rawMonth[0] === '0') {
                    // Zero-padded: "03" is calendar month 3 (March), not month0 index 3
                    $calMonth = (int)$rawMonth;  // already 1-indexed calendar month
                } else {
                    $calMonth = $month0 + 1;     // standard app format: add 1 to convert 0-index → calendar
                }

                if (!checkdate($calMonth, $day, $year)) {
                    continue;
                }

                $dateStr = sprintf('%04d-%02d-%02d', $year, $calMonth, $day);
                $status  = strtolower($override['status'] ?? '');

                if (!in_array($status, $validTypes, true)) {
                    continue; // skip unknown statuses
                }

                $notes = $override['comment'] ?? '';
                if ($status === 'custom_hours' && !empty($override['customHours'])) {
                    // custom_hours stores the time range in notes
                    $notes = $override['customHours'];
                } elseif ($status === 'on' && !empty($override['hours'])) {
                    // 'on' overrides store the working hours in the notes column
                    $notes = $override['hours'];
                }

                $stmtOvr->execute([
                    ':emp_id' => $empId,
                    ':date'   => $dateStr,
                    ':type'   => $status,
                    ':notes'  => $notes ?: null,
                ]);
            }
        }

        // ── 3. Persist currentYear / currentMonth ─────────────────────────────
        // $currentMonth in app is 0-indexed; store as 1-indexed calendar month in DB
        $calMonth = (int)$currentMonth + 1;

        $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value, description)
             VALUES ('current_year', :val, 'Currently displayed schedule year')
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([':val' => (int)$currentYear]);

        $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value, description)
             VALUES ('current_month', :val, 'Currently displayed schedule month (1=Jan)')
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([':val' => $calMonth]);

        $pdo->commit();
        return true;

    } catch (Exception $e) {
        try { $pdo->rollBack(); } catch (Exception $rb) {}
        $errMsg = 'saveData() database error: ' . $e->getMessage();
        error_log($errMsg);
        // Store last error so callers can surface it to the user
        trigger_error($errMsg, E_USER_WARNING);
        return false;
    }
}

/**
 * Alias kept for call-sites that pass $employees / $scheduleOverrides as args.
 * Both parameters are ignored — the global state is what gets persisted.
 */
function saveScheduleData($employeesArg = null, $overridesArg = null): bool {
    return saveData();
}

/**
 * Clean up old schedule overrides to prevent file bloat
 * Removes overrides older than specified months
 * Only accessible to supervisors, managers, and admins
 * 
 * Keeps: Current month + (monthsToKeep - 1) previous months + ALL FUTURE entries
 * Removes: ONLY entries older than the specified months
 * 
 * @param int $monthsToKeep Number of months of history to keep (default: 3)
 * @return int Number of entries removed
 */
function cleanupOldScheduleOverrides($monthsToKeep = 3) {
    global $scheduleOverrides, $employees;
    
    // Calculate cutoff date: Start of the month that is $monthsToKeep months ago
    // Example: If today is Dec 31, 2025 and monthsToKeep=3:
    //   - Keep: December 2025, November 2025, October 2025, and ALL FUTURE
    //   - Remove: September 2025 and earlier
    $today = new DateTime();
    $cutoffMonth = clone $today;
    $cutoffMonth->modify("-{$monthsToKeep} months");
    $cutoffMonth->modify('first day of this month');
    $cutoffMonth->setTime(0, 0, 0);
    $cutoffDate = $cutoffMonth->getTimestamp();
    
    $removedCount = 0;
    $removedByType = ['daily' => 0, 'shift' => 0];
    
    foreach ($scheduleOverrides as $key => $override) {
        $shouldRemove = false;
        
        // Handle daily override format: employeeId-year-month-day
        if (preg_match('/^(\d+)-(\d{4})-(\d+)-(\d+)$/', $key, $matches)) {
            $year = (int)$matches[2];
            $month = (int)$matches[3];
            $day = (int)$matches[4];
            
            // Month is 0-indexed in key, so add 1 for proper date
            $overrideDate = strtotime("$year-" . ($month + 1) . "-$day");
            
            if ($overrideDate !== false) {
                // Remove ONLY if too old (before cutoff)
                // Keep everything from cutoff forward (including all future)
                if ($overrideDate < $cutoffDate) {
                    $shouldRemove = true;
                    $removedByType['daily']++;
                }
            }
        }
        // Handle shift change format: employeeId-shift-year-month-day
        elseif (preg_match('/^(\d+)-shift-(\d{4})-(\d{2})-(\d{2})$/', $key, $matches)) {
            $year = $matches[2];
            $month = $matches[3];
            $day = $matches[4];
            
            $overrideDate = strtotime("$year-$month-$day");
            
            if ($overrideDate !== false) {
                // Remove ONLY if too old (before cutoff)
                if ($overrideDate < $cutoffDate) {
                    $shouldRemove = true;
                    $removedByType['shift']++;
                }
            }
        }
        
        if ($shouldRemove) {
            unset($scheduleOverrides[$key]);
            $removedCount++;
        }
    }
    
    // Save if we removed anything
    if ($removedCount > 0) {
        $success = saveData();
        
        if ($success) {
            $cutoffDateStr = $cutoffMonth->format('M Y');
            $message = "Cleaned up $removedCount old schedule overrides " .
                      "(Daily: {$removedByType['daily']}, Shift changes: {$removedByType['shift']}) " .
                      "older than $cutoffDateStr";
            addActivityLog('cleanup_overrides', $message, 'system', 0);
        }
        
        return $removedCount;
    }
    
    return 0;
}


// Handle user management operations
$userManagementResult = handleUserManagement();
if ($userManagementResult['message']) {
    $message = $userManagementResult['message'];
    $messageType = $userManagementResult['messageType'];
}

// Handle profile updates
$profileResult = handleProfileUpdate();
if ($profileResult['message']) {
    $message = $profileResult['message'];
    $messageType = $profileResult['messageType'];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Detect silent upload failure: when the file exceeds PHP's post_max_size the
    // entire $_POST and $_FILES are empty even though bytes were sent.
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $maxBytes  = (int)ini_get('post_max_size') ?: 8;
        $sentMB    = round((int)$_SERVER['CONTENT_LENGTH'] / 1048576, 1);
        $message   = "⛔ Upload failed: the file ({$sentMB} MB) exceeds the server's post_max_size limit (" . ini_get('post_max_size') . "). Ask your host to raise upload_max_filesize and post_max_size, or compress/split the backup file.";
        $msgEncoded = base64_encode($message);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=backups&msg=' . $msgEncoded . '&type=error');
        exit;
    }

    // Suppress all PHP notices/warnings for AJAX calls — stray output breaks JSON parsing
    if (!empty($_POST['_ajax'])) {
        ini_set('display_errors', '0');
        ob_start();

        // Safety net: if a fatal error (E_ERROR) occurs after this point and PHP
        // terminates abnormally, flush the buffer and return a JSON error instead
        // of sending whatever partial HTML the buffer may contain.
        register_shutdown_function(function () {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                // Discard any partial output already buffered
                while (ob_get_level() > 0) { ob_end_clean(); }
                if (!headers_sent()) {
                    header('Content-Type: application/json');
                }
                echo json_encode([
                    'success' => false,
                    'message' => '⛔ Server error: ' . $err['message'],
                ]);
            }
        });
    }

    loadData();
    // Pre-build O(1) indexed lookups for use inside the switch cases below
    $employeesById = array_column($employees, null, 'id');
    $usersById     = array_column($users,     null, 'id');

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'add_employee':
            if (!hasPermission('manage_employees')) {
                $message = "⛔ You don't have permission to manage employees.";
                $messageType = 'error';
                break;
            }
            
            $name = trim($_POST['empName'] ?? '');
            $team = $_POST['empTeam'] ?? 'tams';
            $shift = intval($_POST['empShift'] ?? 1);
            $hours = trim($_POST['empHours'] ?? '');
            $level = $_POST['empLevel'] ?? '';
            $email = trim($_POST['empEmail'] ?? '');
            $supervisorId = !empty($_POST['empSupervisor']) ? intval($_POST['empSupervisor']) : null;
            $scheduleAccess = !empty($_POST['empScheduleAccess']) ? $_POST['empScheduleAccess'] : '';
            $linkUserId = !empty($_POST['link_user_id']) ? intval($_POST['link_user_id']) : null;
            $authMethod = $_POST['empAuthMethod'] ?? 'both';
            $startDate = trim($_POST['empStartDate'] ?? '');
            $rawEmpSlackId = strtoupper(trim($_POST['empSlackId'] ?? ''));
            $empSlackId = (!empty($rawEmpSlackId) && preg_match('/^[UW][A-Z0-9]{6,}$/', $rawEmpSlackId)) ? $rawEmpSlackId : null;
            
            // Validate auth_method
            if (!in_array($authMethod, ['password', 'google', 'both'])) {
                $authMethod = 'both';
            }
            
            if ($name && $hours) {
                $schedule = [];
                for ($i = 0; $i < 7; $i++) {
                    $schedule[$i] = isset($_POST["day$i"]) ? 1 : 0;
                }
                
                $newEmployee = [
                    'id' => $nextId++,
                    'name' => $name,
                    'team' => $team,
                    'shift' => $shift,
                    'hours' => $hours,
                    'level' => $level,
                    'email' => $email,
                    'supervisor_id' => $supervisorId,
                    'schedule_access' => $scheduleAccess,
                    'start_date' => $startDate,
                    'slack_id' => $empSlackId,
                    'schedule' => $schedule,
                    'skills' => [
                        'mh' => isset($_POST['skillMH']),
                        'ma' => isset($_POST['skillMA']),
                        'win' => isset($_POST['skillWin'])
                    ]
                ];
                
                // Add user_id if provided (linking to user account)
                if ($linkUserId) {
                    $newEmployee['user_id'] = $linkUserId;
                    error_log("ADD EMPLOYEE: Linking to user ID $linkUserId");
                }

                // ── INSERT into database ──────────────────────────────────────
                $newEmployeeId = null;
                try {
                    $dbInst = Database::getInstance();
                    $weeklyStr = implode(',', $schedule);
                    $skillsJson = json_encode($newEmployee['skills']);
                    $newEmployeeId = $dbInst->insert(
                        'INSERT INTO employees
                           (name, email, team, level, shift, hours, weekly_schedule, skills,
                            notes, user_id, hire_date, supervisor_id, slack_id, active)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                        [
                            $name, $email, $team, $level, $shift, $hours, $weeklyStr,
                            $skillsJson, '', $linkUserId ?: null,
                            $startDate ?: null, $supervisorId ?: null, $empSlackId ?: null
                        ]
                    );
                    $newEmployee['id'] = (int)$newEmployeeId;
                    $nextId = $newEmployeeId + 1;
                } catch (Exception $dbEx) {
                    error_log('ADD EMPLOYEE DB INSERT ERROR: ' . $dbEx->getMessage());
                    $message = '⛔ Failed to save employee to database: ' . htmlspecialchars($dbEx->getMessage());
                    $messageType = 'error';
                    break;
                }
                // Sync in-memory array
                $employees[] = $newEmployee;

                if ($newEmployeeId) {
                    // If linked to user, also update the user record
                    if ($linkUserId) {
                        // Determine role to set
                        $roleToSet = null;
                        if (isset($_POST['empUserRole'])) {
                            $newUserRole = $_POST['empUserRole'];
                            if (in_array($newUserRole, ['employee', 'supervisor', 'manager', 'admin'])) {
                                $roleToSet = $newUserRole;
                                error_log("ADD EMPLOYEE: Set user $linkUserId role to $roleToSet");
                            }
                        }
                        // Direct DB UPDATE instead of saveUsers()
                        try {
                            if ($roleToSet !== null) {
                                $pdo->prepare("UPDATE users SET employee_id = ?, auth_method = ?, role = ?, updated_at = NOW() WHERE id = ?")
                                    ->execute([$newEmployeeId, $authMethod, $roleToSet, $linkUserId]);
                            } else {
                                $pdo->prepare("UPDATE users SET employee_id = ?, auth_method = ?, updated_at = NOW() WHERE id = ?")
                                    ->execute([$newEmployeeId, $authMethod, $linkUserId]);
                            }
                            error_log("ADD EMPLOYEE: Updated user $linkUserId with employee_id $newEmployeeId and auth_method $authMethod");
                        } catch (Exception $e) {
                            error_log("ADD EMPLOYEE: DB update user failed: " . $e->getMessage());
                        }

                        // Sync user role with employee level (force sync since we're explicitly setting level)
                        // SKIP if we manually set the role above
                        if (!isset($_POST['empUserRole'])) {
                            syncUserRoleFromEmployeeLevel($linkUserId, $level, true);
                        }
                    }
                    
                    $supervisorName = getSupervisorName($supervisorId);
                    $levelName = getLevelName($level);
                    $linkedMessage = $linkUserId ? " (Linked to user account)" : "";
                    addActivityLog('employee_add', "Added employee: $name (Team: $team, Shift: " . getShiftName($shift) . ", Level: $levelName, Reports to: $supervisorName)$linkedMessage", 'employee', $newEmployeeId);
                    $message = "✅ $name has been added to the schedule! Level: $levelName, Reports to: $supervisorName$linkedMessage";
                    $messageType = 'success';
                    error_log("ADD EMPLOYEE SUCCESS: $name added to $team");
                } else {
                    $message = "⛔ Failed to save employee data.";
                    $messageType = 'error';
                    error_log("ADD EMPLOYEE ERROR: Failed to save data");
                }
            } else {
                $message = "⛔ Please fill in all required fields.";
                $messageType = 'error';
                error_log("ADD EMPLOYEE ERROR: Missing required fields - name=$name, hours=$hours");
            }
            break;
            
        case 'edit_employee':
            $employeeId = intval($_POST['empId'] ?? $_POST['employeeId'] ?? 0);
            
            // Find the target employee — O(1) via pre-built index
            $targetEmployee = $employeesById[$employeeId] ?? null;
            
            if (!$targetEmployee) {
                if (!empty($_POST['_ajax'])) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => '⛔ Employee not found.']); exit; }
                $message = "⛔ Employee not found.";
                $messageType = 'error';
                break;
            }
            
            // Check permissions - use $currentUser role, not session!
            $canEdit = false;
            $isEditingOwnProfile = false;
            
            // Get current user — O(1) via pre-built index
            $currentUserForCheck = $usersById[$_SESSION['user_id']] ?? null;
            
            $userRole = $currentUserForCheck['role'] ?? 'employee';
            
            if (in_array($userRole, ['admin', 'manager', 'supervisor'])) {
                // Admins, managers, supervisors can edit all employees
                $canEdit = true;
            } elseif ($userRole === 'employee') {
                // Employees can edit their own profile and schedule
                $currentEmployee = getCurrentUserEmployee();
                if ($currentEmployee && $currentEmployee['id'] == $employeeId) {
                    $canEdit = true;
                    $isEditingOwnProfile = true;
                }
            }
            
            if (!$canEdit) {
                if (!empty($_POST['_ajax'])) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => '🚫 Access Denied.']); exit; }
                $message = "🚫 Access Denied: You can only edit your own employee profile and schedule.";
                $messageType = 'error';
                break;
            }
            
            // For employees editing own profile, preserve locked fields and only allow editable ones
            if ($isEditingOwnProfile) {
                // Employees can edit: shift, hours, schedule days, email
                $name = $targetEmployee['name'];  // Locked
                $team = $targetEmployee['team'];  // Locked
                $level = $targetEmployee['level'] ?? '';  // Locked
                $supervisorId = $targetEmployee['supervisor_id'] ?? null;  // Locked
                $scheduleAccess = $targetEmployee['schedule_access'] ?? '';  // Locked
                $authMethod = $targetEmployee['auth_method'] ?? 'both';  // Locked
                $startDate = $targetEmployee['start_date'] ?? '';  // Locked
                
                // Editable fields
                $shift = intval($_POST['empShift'] ?? 1);
                $hours = trim($_POST['empHours'] ?? '');
                $email = trim($_POST['empEmail'] ?? '');
                
                // Skills preserved
                $skills = $targetEmployee['skills'] ?? ['mh' => false, 'ma' => false, 'win' => false];
            } else {
                // Admins/managers/supervisors can edit everything
                $name = trim($_POST['empName'] ?? '');
                $team = $_POST['empTeam'] ?? '';
                $shift = intval($_POST['empShift'] ?? 1);
                $hours = trim($_POST['empHours'] ?? '');
                $level = $_POST['empLevel'] ?? '';
                $email = trim($_POST['empEmail'] ?? '');
                $supervisorId = !empty($_POST['empSupervisor']) ? intval($_POST['empSupervisor']) : null;
                $scheduleAccess = !empty($_POST['empScheduleAccess']) ? $_POST['empScheduleAccess'] : '';
                $authMethod = $_POST['empAuthMethod'] ?? 'both';
                $startDate = trim($_POST['empStartDate'] ?? '');
                
                // Build skills array
                $skills = [
                    'mh' => isset($_POST['skillMH']),
                    'ma' => isset($_POST['skillMA']),
                    'win' => isset($_POST['skillWin'])
                ];
            }
            
            // Validate auth_method
            if (!in_array($authMethod, ['password', 'google', 'both'])) {
                $authMethod = 'both';
            }

            // Slack Member ID — available to all roles (own or admin edit)
            $rawEditSlackId = strtoupper(trim($_POST['empSlackId'] ?? ''));
            $editEmpSlackId = (!empty($rawEditSlackId) && preg_match('/^[UW][A-Z0-9]{6,}$/', $rawEditSlackId)) ? $rawEditSlackId : null;
            // If the field was submitted but blank, explicitly clear it
            $editEmpSlackIdSubmitted = isset($_POST['empSlackId']);
            $editEmpSlackIdFinal = $editEmpSlackIdSubmitted ? $editEmpSlackId : ($targetEmployee['slack_id'] ?? null);

            if ($name && $hours && $employeeId) {
                // Build schedule array from checkboxes
                $schedule = [];
                for ($i = 0; $i < 7; $i++) {
                    $schedule[$i] = isset($_POST["day$i"]) ? 1 : 0;
                }

                // Skills array already built above based on permission level

                // O(1) lookup for linked user_id — replaces two foreach scans
                $linkedUserId = $employeesById[$employeeId]['user_id'] ?? null;

                // Fallback: employees.user_id may be null if the link was established from
                // the user side (users.employee_id). Search users array the same way the
                // display dropdown does, so role changes always save regardless of which
                // side of the link was set.
                if (!$linkedUserId) {
                    foreach ($users as $u) {
                        if (isset($u['employee_id']) && (int)$u['employee_id'] === $employeeId) {
                            $linkedUserId = $u['id'];
                            break;
                        }
                    }
                }

                // Last-resort fallback: match by email
                if (!$linkedUserId) {
                    $empEmail = $employeesById[$employeeId]['email'] ?? '';
                    if ($empEmail) {
                        foreach ($users as $u) {
                            if (!empty($u['email']) && strtolower($u['email']) === strtolower($empEmail)) {
                                $linkedUserId = $u['id'];
                                break;
                            }
                        }
                    }
                }

                // Acquire DB connection once for both the user update and the employee UPDATE
                $pdo = Database::getInstance()->getConnection();

                if ($linkedUserId) {
                    // Determine role to set (admins/managers/supervisors only, not own profile)
                    $roleToSet = null;
                    if (isset($_POST['empUserRole']) && !$isEditingOwnProfile) {
                        $newUserRole = $_POST['empUserRole'];
                        if (in_array($newUserRole, ['employee', 'supervisor', 'manager', 'admin'])) {
                            $roleToSet = $newUserRole;
                            error_log("EDIT EMPLOYEE: Updated user $linkedUserId role to $roleToSet");
                        }
                    }
                    // Role and auth_method are updated in separate queries so a bad
                    // auth_method value (ENUM mismatch) can never block the role save.
                    if ($roleToSet !== null) {
                        try {
                            $pdo->prepare("UPDATE users SET role = ?, updated_at = NOW() WHERE id = ?")
                                ->execute([$roleToSet, $linkedUserId]);
                            error_log("EDIT EMPLOYEE: Set user $linkedUserId role to $roleToSet");
                        } catch (Exception $e) {
                            error_log("EDIT EMPLOYEE: role update failed: " . $e->getMessage());
                        }
                    }
                    try {
                        $pdo->prepare("UPDATE users SET auth_method = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([$authMethod, $linkedUserId]);
                    } catch (Exception $e) {
                        error_log("EDIT EMPLOYEE: auth_method update failed (value='$authMethod'): " . $e->getMessage());
                    }

                    // Heal the bidirectional link: if either side was null, write both
                    // now so future saves use the fast O(1) path and the display is correct.
                    if (!$employeesById[$employeeId]['user_id']) {
                        try {
                            $pdo->prepare("UPDATE employees SET user_id = ? WHERE id = ?")
                                ->execute([$linkedUserId, $employeeId]);
                        } catch (Exception $e) {
                            error_log("EDIT EMPLOYEE: Could not heal employees.user_id: " . $e->getMessage());
                        }
                    }
                    $colCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'employee_id'")->fetch();
                    if ($colCheck) {
                        $userRow = null;
                        foreach ($users as $u) { if ($u['id'] == $linkedUserId) { $userRow = $u; break; } }
                        if (!($userRow['employee_id'] ?? null)) {
                            try {
                                $pdo->prepare("UPDATE users SET employee_id = ? WHERE id = ?")
                                    ->execute([$employeeId, $linkedUserId]);
                            } catch (Exception $e) {
                                error_log("EDIT EMPLOYEE: Could not heal users.employee_id: " . $e->getMessage());
                            }
                        }
                    }

                    // Sync user role with new employee level (force sync since level is being explicitly changed)
                    // SKIP if we manually set the role above
                    if (!isset($_POST['empUserRole']) || $isEditingOwnProfile) {
                        syncUserRoleFromEmployeeLevel($linkedUserId, $level, true);
                    }
                }
                
                // Targeted single-row UPDATE — replaces saveData() which rebuilt every row
                $weeklyStr  = implode(',', $schedule);
                $rawSk      = $skills;
                $skillsJson = json_encode([
                    'mh'  => (bool)($rawSk['mh']  ?? false),
                    'ma'  => (bool)($rawSk['ma']  ?? false),
                    'win' => (bool)($rawSk['win'] ?? false),
                ]);
                $hireDate = !empty($startDate) ? $startDate : null;
                try {
                    $pdo->prepare(
                        'UPDATE employees SET
                             name=?, email=?, team=?, level=?, shift=?, hours=?,
                             weekly_schedule=?, skills=?, supervisor_id=?,
                             hire_date=?, slack_id=?, updated_at=NOW()
                         WHERE id=?'
                    )->execute([
                        $name, $email, $team, $level, $shift, $hours,
                        $weeklyStr, $skillsJson,
                        $supervisorId ?: null,
                        $hireDate, $editEmpSlackIdFinal,
                        $employeeId
                    ]);

                    // When hours change, update existing 'on' overrides in schedule_overrides
                    // so the schedule page shows the new hours (not the stale hours in notes).
                    $oldHours = $employeesById[$employeeId]['hours'] ?? '';
                    if ($oldHours !== $hours) {
                        $pdo->prepare(
                            "UPDATE schedule_overrides SET notes = ? WHERE employee_id = ? AND override_type = 'on'"
                        )->execute([$hours, $employeeId]);
                    }

                    // When a day changes from OFF→ON in the weekly schedule, delete any
                    // plain 'off' override records for that day-of-week.  Those overrides
                    // existed only because the day wasn't a working day; now that it is,
                    // they would override the new schedule and keep cells showing as OFF.
                    // MySQL DAYOFWEEK(): 1=Sunday … 7=Saturday (our array: 0=Sun … 6=Sat).
                    // PTO / sick / holiday overrides are intentional and are NOT touched.
                    $oldSchedule   = $employeesById[$employeeId]['schedule'] ?? [0,1,1,1,1,1,0];
                    $newlyOnDows   = [];   // MySQL DAYOFWEEK values for days now turned ON
                    for ($di = 0; $di < 7; $di++) {
                        if (($oldSchedule[$di] ?? 0) == 0 && $schedule[$di] == 1) {
                            $newlyOnDows[] = $di + 1;   // convert 0-based → MySQL 1-based
                        }
                    }
                    if (!empty($newlyOnDows)) {
                        $ph = implode(',', array_fill(0, count($newlyOnDows), '?'));
                        $pdo->prepare(
                            "DELETE FROM schedule_overrides
                             WHERE employee_id = ?
                               AND override_type = 'off'
                               AND DAYOFWEEK(override_date) IN ($ph)"
                        )->execute(array_merge([$employeeId], $newlyOnDows));
                        error_log("EDIT EMPLOYEE [id=$employeeId] cleared 'off' overrides for newly-active DOW(s): " . implode(',', $newlyOnDows));
                    }

                    $supervisorName = getSupervisorName($supervisorId);
                    $levelName      = getLevelName($level);
                    $shiftInfo      = getShiftName($shift);

                    addActivityLog(
                        'employee_edit',
                        "Updated employee: $name (Team: $team, Shift: $shiftInfo, Level: $levelName, Reports to: $supervisorName, Auth: $authMethod)",
                        'employee',
                        $employeeId
                    );

                    $successMsg = "✅ $name has been updated successfully! Level: $levelName, Reports to: $supervisorName, Shift: $shiftInfo, Auth: $authMethod";

                    // Diagnostic: log exactly what was received and saved for working days
                    $receivedDaysLog = [];
                    for ($di = 0; $di < 7; $di++) {
                        $receivedDaysLog["day$di"] = isset($_POST["day$di"]) ? 1 : 0;
                    }
                    error_log("EDIT EMPLOYEE [id=$employeeId] working days received: " . json_encode($receivedDaysLog) . " → weekly_schedule saved: $weeklyStr");

                    if (!empty($_POST['_ajax'])) {
                        ob_end_clean();
                        header('Content-Type: application/json');
                        // Include the saved schedule so the JS can update its cache
                        // authoritatively from the server rather than re-reading FormData.
                        echo json_encode([
                            'success'       => true,
                            'message'       => $successMsg,
                            'savedSchedule' => $schedule,
                            'savedHours'    => $hours,
                        ]);
                        exit;
                    }

                    $_SESSION['message']     = $successMsg;
                    $_SESSION['messageType'] = 'success';
                    header("Location: ?tab=edit-employee&id=$employeeId");
                    exit;
                } catch (\Throwable $e) {
                    // Catches both Exception and PHP 7+ Error (TypeError, etc.)
                    error_log("EDIT EMPLOYEE DB ERROR: " . $e->getMessage());
                    if (!empty($_POST['_ajax'])) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => '⛔ Failed to save employee changes: ' . $e->getMessage()]); exit; }
                    $message     = "⛔ Failed to save employee changes.";
                    $messageType = 'error';
                }
            } else {
                if (!empty($_POST['_ajax'])) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => '⛔ Please fill in all required fields.']); exit; }
                $message = "⛔ Please fill in all required fields.";
                $messageType = 'error';
            }
            break;
            
        case 'delete_employee':
            if (!hasPermission('manage_employees')) {
                $message = "⛔ You don't have permission to manage employees.";
                $messageType = 'error';
                break;
            }
            
            $employeeId = intval($_POST['employeeId'] ?? 0);
            
            $deletedEmployeeName = 'Unknown';
            foreach ($employees as $emp) {
                if ($emp['id'] == $employeeId) {
                    $deletedEmployeeName = $emp['name'];
                    break;
                }
            }
            
            $employees = array_filter($employees, function($emp) use ($employeeId) {
                return $emp['id'] != $employeeId;
            });
            $employees = array_values($employees);
            
            // Remove schedule overrides for this employee
            foreach ($scheduleOverrides as $key => $override) {
                if (strpos($key, "$employeeId-") === 0) {
                    unset($scheduleOverrides[$key]);
                }
            }
            
            if (saveData()) {
                addActivityLog('employee_delete', "Deleted employee: $deletedEmployeeName", 'employee', $employeeId);
                $_SESSION['message'] = "✅ Employee \"$deletedEmployeeName\" has been deleted successfully.";
                $_SESSION['messageType'] = 'success';
                header("Location: ?tab=schedule");
                exit;
            } else {
                $message = "⛔ Failed to delete employee.";
                $messageType = 'error';
            }
            break;
            
        case 'add_motd':
            if (!hasPermission('manage_users')) {
                $message = "⛔ You don't have permission to manage MOTD.";
                $messageType = 'error';
                break;
            }
            
            $motdMessage = trim($_POST['motd_message'] ?? '');
            $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $includeAnniversaries = isset($_POST['include_anniversaries']);
            $createdBy = getCurrentUserName();
            
            // Allow empty message if anniversaries are included
            if (empty($motdMessage) && !$includeAnniversaries) {
                $message = "⛔ Please provide a message or enable work anniversaries.";
                $messageType = 'error';
                break;
            }
            
            if (addMOTD($motdMessage, $startDate, $endDate, $includeAnniversaries, $createdBy)) {
                addActivityLog('motd_add', "Added new Message of the Day", 'system', null);
                $_SESSION['message'] = "✅ Message of the Day has been added successfully.";
                $_SESSION['messageType'] = 'success';
                header("Location: ?tab=settings");
                exit;
            } else {
                $message = "⛔ Failed to add Message of the Day.";
                $messageType = 'error';
            }
            break;
        
        case 'toggle_global_anniversaries':
            if (!hasPermission('manage_users')) {
                $message = "⛔ You don't have permission to manage MOTD settings.";
                $messageType = 'error';
                break;
            }
            
            $showGlobalAnniversaries = isset($_POST['show_anniversaries_global']);

            if (motdSetShowAnniversaries($showGlobalAnniversaries)) {
                $status = $showGlobalAnniversaries ? 'enabled' : 'disabled';
                addActivityLog('motd_settings', "Global work anniversaries display $status", 'system', null);
                $_SESSION['message'] = $showGlobalAnniversaries ?
                    "✅ Work anniversaries will now always be displayed." :
                    "✅ Work anniversaries display has been disabled.";
                $_SESSION['messageType'] = 'success';
                header("Location: ?tab=settings");
                exit;
            } else {
                $message = "⛔ Failed to update anniversaries setting.";
                $messageType = 'error';
            }
            break;
        
        case 'edit_motd':
            if (!hasPermission('manage_users')) {
                $message = "⛔ You don't have permission to manage MOTD.";
                $messageType = 'error';
                break;
            }

            $motdId = $_POST['motd_id'] ?? '';
            $motdMessage = trim($_POST['motd_message'] ?? '');
            $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $includeAnniversaries = isset($_POST['include_anniversaries']);
            $updatedBy = getCurrentUserName();

            if (updateMOTD($motdId, $motdMessage, $startDate, $endDate, $includeAnniversaries, $updatedBy)) {
                addActivityLog('motd_update', "Updated Message of the Day", 'system', null);
                $_SESSION['message'] = "✅ Message of the Day has been updated successfully.";
                $_SESSION['messageType'] = 'success';
                header("Location: ?tab=settings");
                exit;
            } else {
                $message = "⛔ Failed to update Message of the Day.";
                $messageType = 'error';
            }
            break;
        
        case 'delete_motd':
            if (!hasPermission('manage_users')) {
                $message = "⛔ You don't have permission to manage MOTD.";
                $messageType = 'error';
                break;
            }
            
            $motdId = $_POST['motd_id'] ?? '';
            
            if (deleteMOTD($motdId)) {
                addActivityLog('motd_delete', "Deleted Message of the Day", 'system', null);
                $_SESSION['message'] = "✅ Message of the Day has been deleted successfully.";
                $_SESSION['messageType'] = 'success';
                header("Location: ?tab=settings");
                exit;
            } else {
                $message = "⛔ Failed to delete Message of the Day.";
                $messageType = 'error';
            }
            break;
            
        case 'clear_activity_log':
            if (!hasPermission('manage_backups')) {
                $message = "⛔ You don't have permission to clear the activity log.";
                $messageType = 'error';
                break;
            }
            try {
                $alClearPdo = Database::getInstance()->getConnection();
                $alClearPdo->exec("DELETE FROM activity_log");
                // Also wipe the JSON fallback file if it exists
                $alJsonPath = __DIR__ . '/activity_log.json';
                if (file_exists($alJsonPath)) {
                    file_put_contents($alJsonPath, json_encode(['logs' => [], 'lastUpdated' => date('c')]));
                }
                // Reset in-memory cache
                global $activityLog;
                $activityLog = [];
                $_SESSION['message']     = "✅ Activity log cleared successfully.";
                $_SESSION['messageType'] = 'success';
            } catch (Exception $e) {
                $_SESSION['message']     = "⛔ Failed to clear activity log: " . htmlspecialchars($e->getMessage());
                $_SESSION['messageType'] = 'error';
            }
            header("Location: ?tab=settings");
            exit;

        case 'auto_match_emails':
            if (!hasPermission('manage_employees')) {
                $message = "⛔ You don't have permission to manage employees.";
                $messageType = 'error';
                break;
            }

            $matchedCount = 0;
            $totalEmployees = count($employees);
            
            foreach ($employees as &$employee) {
                // Skip if employee already has an email
                if (!empty($employee['email'])) {
                    continue;
                }
                
                // Try to find matching user by name
                foreach ($users as $user) {
                    if (!empty($user['full_name']) && !empty($user['email'])) {
                        $userNameNormalized = strtolower(trim($user['full_name']));
                        $employeeNameNormalized = strtolower(trim($employee['name']));
                        
                        if ($userNameNormalized === $employeeNameNormalized) {
                            $employee['email'] = $user['email'];
                            $matchedCount++;
                            break;
                        }
                    }
                }
            }
            unset($employee); // Break reference
            
            if ($matchedCount > 0) {
                if (saveData()) {
                    addActivityLog('bulk_email_match', "Auto-matched $matchedCount employee emails from user records", 'system', null);
                    $message = "✅ Successfully matched $matchedCount out of $totalEmployees employee email addresses from user records!";
                    $messageType = 'success';
                } else {
                    $message = "⛔ Failed to save email matches.";
                    $messageType = 'error';
                }
            } else {
                $message = "ℹ️ No new email matches found. All employees either already have emails or don't have matching user records.";
                $messageType = 'info';
            }
            break;
            
        case 'auto_link_users_employees':
            if (!hasPermission('manage_employees')) {
                $message = "⛔ You don't have permission to manage employees.";
                $messageType = 'error';
                break;
            }
            
            loadUsers();
            $results = bulkLinkByEmail();
            
            if ($results['linked'] > 0) {
                $message = "✅ Successfully linked {$results['linked']} users to employees!";
                if ($results['no_match'] > 0) {
                    $message .= "<br>ℹ️ {$results['no_match']} users had no matching employees.";
                }
                if ($results['already_linked'] > 0) {
                    $message .= "<br>ℹ️ {$results['already_linked']} users were already linked.";
                }
                $messageType = 'success';
            } else {
                if ($results['already_linked'] > 0) {
                    $message = "ℹ️ All users are already linked. No new links created.";
                } else {
                    $message = "ℹ️ No matches found. Users may not have corresponding employee records or emails may not match.";
                }
                $messageType = 'info';
            }
            break;
            
        case 'edit_cell':
            $employeeId = intval($_POST['employeeId'] ?? 0);
            
            if (!canEditEmployeeSchedule($employeeId)) {
                $message = "⛔ You don't have permission to edit this employee's schedule.";
                $messageType = 'error';
                break;
            }
            
            $day = intval($_POST['day'] ?? 0);
            $status = $_POST['status'] ?? '';
            $comment = trim($_POST['comment'] ?? '');
            $customHours = trim($_POST['customHours'] ?? '');
            $year = intval($_POST['year'] ?? $currentYear);
            $month = intval($_POST['month'] ?? $currentMonth);
            
            $overrideKey = "$employeeId-$year-$month-$day";
            
            $employee = array_filter($employees, function($emp) use ($employeeId) {
                return $emp['id'] === $employeeId;
            });
            $employee = reset($employee);
            $employeeName = $employee ? $employee['name'] : 'Employee';
            
            if ($status === 'schedule') {
                // Reset to original schedule
                if (isset($scheduleOverrides[$overrideKey])) {
                    unset($scheduleOverrides[$overrideKey]);
                    
                    if (saveData()) {
                        $date = mktime(0, 0, 0, $month + 1, $day, $year);
                        $dayOfWeek = date('w', $date);
                        $isScheduled = $employee && $employee['schedule'][$dayOfWeek] == 1;
                        
                        $logDetails = "Reset {$employeeName}'s schedule for " . getMonthName($month) . " $day to default";
                        addActivityLog('schedule_change', $logDetails, 'schedule', $employeeId);
                        
                        if ($isScheduled) {
                            $message = "✅ Reset {$employeeName}'s schedule to: Working ({$employee['hours']})";
                        } else {
                            $message = "✅ Reset {$employeeName}'s schedule to: Day Off";
                        }
                        $messageType = 'success';
                    } else {
                        $message = "⛔ Failed to reset schedule.";
                        $messageType = 'error';
                    }
                } else {
                    $date = mktime(0, 0, 0, $month + 1, $day, $year);
                    $dayOfWeek = date('w', $date);
                    $isScheduled = $employee && $employee['schedule'][$dayOfWeek] == 1;
                    
                    if ($isScheduled) {
                        $message = "ℹ️ {$employeeName} is already using their default schedule: Working ({$employee['hours']})";
                    } else {
                        $message = "ℹ️ {$employeeName} is already using their default schedule: Day Off";
                    }
                    $messageType = 'info';
                }
            } elseif (in_array($status, ['on', 'off', 'pto', 'sick', 'holiday', 'custom_hours'])) {
                $override = [
                    'status' => $status,
                    'comment' => $comment
                ];
                
                if ($status === 'custom_hours' && $customHours) {
                    $override['customHours'] = $customHours;
                }
                
                $scheduleOverrides[$overrideKey] = $override;
                
                if (saveData()) {
                    $statusText = getStatusText($status);
                    if ($status === 'custom_hours' && $customHours) {
                        $statusText = "Custom Hours ($customHours)";
                    }
                    $commentText = $comment ? " ($comment)" : '';
                    
                    $logDetails = "Changed {$employeeName}'s schedule for " . getMonthName($month) . " $day to: {$statusText}{$commentText}";
                    addActivityLog('schedule_change', $logDetails, 'schedule', $employeeId);
                    
                    $message = "✅ Updated {$employeeName}'s schedule: {$statusText}{$commentText}";
                    $messageType = 'success';
                } else {
                    $message = "⛔ Failed to update schedule.";
                    $messageType = 'error';
                }
            }
            break;
            
        case 'change_month':
            $monthValue = $_POST['monthSelect'] ?? '';
            if ($monthValue) {
                list($currentYear, $currentMonth) = explode('-', $monthValue);
                $currentYear = intval($currentYear);
                $currentMonth = intval($currentMonth);
                saveData();
            }
            break;
            
        case 'download_backup':
            if (!hasPermission('manage_backups')) {
                $message = "⛔ You don't have permission to manage backups.";
                $messageType = 'error';
                break;
            }
            
            $downloadSnapshotId = (int)($_POST['snapshot_id'] ?? 0);
            
            try {
                require_once __DIR__ . '/Database.php';
                $dbDl = Database::getInstance();
                $edtTime = new DateTime('now', new DateTimeZone('America/New_York'));
                
                // Tables to include in a fresh SQL dump (for live exports)
                $dumpTables = ['employees', 'schedule_overrides', 'motd_messages', 'schedule_templates', 'activity_log', 'users', 'settings'];

                if ($downloadSnapshotId > 0) {
                    // Download a specific DB snapshot as a zip
                    $snapMeta = $dbDl->fetchOne("SELECT * FROM backup_snapshots WHERE id = ?", [$downloadSnapshotId]);
                    if (!$snapMeta) throw new Exception("Snapshot not found.");

                    $rows = $dbDl->fetchAll("SELECT table_name, data_json, record_count FROM backup_data WHERE snapshot_id = ?", [$downloadSnapshotId]);
                    $snapData  = [];
                    $sqlDump   = null;
                    foreach ($rows as $row) {
                        if ($row['table_name'] === 'sql_dump') {
                            // SQL dump is stored as a JSON-encoded string
                            $sqlDump = json_decode($row['data_json'], true);
                        } else {
                            $snapData[$row['table_name']] = json_decode($row['data_json'], true);
                        }
                    }

                    // If no SQL dump was stored with this snapshot, generate one now from live DB
                    if (!$sqlDump) {
                        $sqlDump = generateSqlDump($dbDl, $dumpTables);
                    }

                    $exportPayload = [
                        'snapshot_name'     => $snapMeta['snapshot_name'],
                        'backup_type'       => $snapMeta['backup_type'] ?? 'manual',
                        'snapshot_id'       => $downloadSnapshotId,
                        'employees'         => $snapData['employees']          ?? [],
                        'scheduleOverrides' => $snapData['schedule_overrides'] ?? [],
                        'users'             => $snapData['users']              ?? [],
                        'templates'         => $snapData['schedule_templates'] ?? [],
                        'motd_messages'     => $snapData['motd_messages']      ?? [],
                        'settings'          => $snapData['settings']           ?? [],
                        'activity_log'      => $snapData['activity_log']       ?? [],
                        'exportDate'        => $edtTime->format('c'),
                        'version'           => APP_VERSION,
                        'format'            => 'db_snapshot_export'
                    ];
                    $zipName = 'snapshot_' . $downloadSnapshotId . '_' . $edtTime->format('Y-m-d_H-i-s') . '.zip';
                } else {
                    // Download current live data — also generate a fresh SQL dump
                    $sqlDump = generateSqlDump($dbDl, $dumpTables);
                    $exportPayload = [
                        'snapshot_name'     => 'Live Export - ' . $edtTime->format('Y-m-d H:i:s'),
                        'backup_type'       => 'manual',
                        'employees'         => $employees,
                        'scheduleOverrides' => $scheduleOverrides,
                        'nextId'            => $nextId,
                        'currentYear'       => $currentYear,
                        'currentMonth'      => $currentMonth,
                        'exportDate'        => $edtTime->format('c'),
                        'version'           => APP_VERSION,
                        'format'            => 'db_snapshot_export'
                    ];
                    $zipName = 'backup_' . $edtTime->format('Y-m-d_H-i-s') . '.zip';
                }

                $jsonContent  = json_encode($exportPayload, JSON_PRETTY_PRINT);
                $jsonFilename = str_replace('.zip', '.json', $zipName);
                $sqlFilename  = str_replace('.zip', '_database_dump.sql', $zipName);

                // Build zip in memory
                $tmpFile = tempnam(sys_get_temp_dir(), 'bkp_');
                $zip = new ZipArchive();
                if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                    $zip->addFromString($jsonFilename, $jsonContent);
                    $zip->addFromString($sqlFilename, $sqlDump);
                    $zip->close();
                    
                    addActivityLog('backup_download', "Downloaded backup zip: $zipName (snapshot $downloadSnapshotId)", 'backup');
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . $zipName . '"');
                    header('Content-Length: ' . filesize($tmpFile));
                    readfile($tmpFile);
                    unlink($tmpFile);
                    exit;
                } else {
                    unlink($tmpFile);
                    throw new Exception("Could not create zip archive.");
                }
            } catch (Exception $e) {
                $message = "⛔ Download failed: " . $e->getMessage();
                $messageType = 'error';
            }
            break;
            
        case 'convert_hours_to_12h':
            if (!hasPermission('manage_employees')) {
                $message = "⛔ You don't have permission to update employees.";
                $messageType = 'error';
                break;
            }
            try {
                $db = Database::getInstance();
                $employees = $db->fetchAll("SELECT id, hours FROM employees WHERE active = 1 AND hours IS NOT NULL AND hours != ''");
                $converted = 0;
                $skipped   = 0;
                foreach ($employees as $emp) {
                    $orig = trim($emp['hours']);
                    if (!$orig) continue;
                    // Skip if already contains am/pm
                    if (preg_match('/[ap]m/i', $orig)) { $skipped++; continue; }
                    // Convert "H-H" or "HH-HH" numeric 24h ranges
                    $new = preg_replace_callback(
                        '/(\d{1,2})(?::(\d{2}))?-(\d{1,2})(?::(\d{2}))?/',
                        function($m) {
                            $fmt = function($h, $min) {
                                $h = (int)$h; $min = (int)($min ?? 0);
                                $suf = $h >= 12 ? 'pm' : 'am';
                                $h12 = $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h);
                                return $min === 0 ? "{$h12}{$suf}" : sprintf('%d:%02d%s', $h12, $min, $suf);
                            };
                            return $fmt($m[1], $m[2] ?? 0) . '-' . $fmt($m[3], $m[4] ?? 0);
                        },
                        $orig
                    );
                    if ($new !== $orig) {
                        $db->fetchOne("UPDATE employees SET hours = ? WHERE id = ?", [$new, $emp['id']]);
                        $converted++;
                    } else {
                        $skipped++;
                    }
                }
                $message = "✅ Hours conversion complete: {$converted} record(s) converted to 12-hour format, {$skipped} already correct or skipped.";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = "❌ Conversion failed: " . $e->getMessage();
                $messageType = 'error';
            }
            $activeTab = 'settings';
            break;

        case 'create_backup':
            if (!hasPermission('manage_backups')) {
                $message = "⛔ You don't have permission to manage backups.";
                $messageType = 'error';
                break;
            }

            $edtTime = new DateTime('now', new DateTimeZone('America/New_York'));
            $snapshotLabel = '📝 Manual Backup - ' . $edtTime->format('Y-m-d H:i:s');
            $backupDescription = $_POST['backup_description'] ?? '';

            try {
                require_once __DIR__ . '/Database.php';
                $dbBackup = Database::getInstance();

                // All data tables to include in the backup (excludes the backup tables themselves)
                $backupTables = ['employees', 'schedule_overrides', 'motd_messages', 'schedule_templates', 'activity_log', 'users', 'settings'];

                // Fetch data from every table
                $tableData    = [];
                $recordCounts = [];
                foreach ($backupTables as $tbl) {
                    try {
                        $rows = $dbBackup->fetchAll("SELECT * FROM `{$tbl}`");
                        $tableData[$tbl]    = $rows;
                        $recordCounts[$tbl] = count($rows);
                    } catch (Exception $e) {
                        $tableData[$tbl]    = [];
                        $recordCounts[$tbl] = 0;
                    }
                }

                // Create the snapshot record
                $dbBackup->fetchOne(
                    "INSERT INTO backup_snapshots (snapshot_name, description, backup_type, created_by, created_at, record_counts, is_legacy_import)
                     VALUES (?, ?, 'manual', ?, NOW(), ?, 0)",
                    [$snapshotLabel, $backupDescription, $currentUser['id'], json_encode($recordCounts)]
                );
                $snapshotRow = $dbBackup->fetchOne("SELECT LAST_INSERT_ID() as sid");
                $snapshotId  = $snapshotRow ? (int)$snapshotRow['sid'] : 0;

                if ($snapshotId > 0) {
                    // Store each table's data as JSON
                    foreach ($backupTables as $tbl) {
                        $dbBackup->fetchOne(
                            "INSERT INTO backup_data (snapshot_id, table_name, data_json, record_count) VALUES (?, ?, ?, ?)",
                            [$snapshotId, $tbl, json_encode($tableData[$tbl]), count($tableData[$tbl])]
                        );
                    }

                    // Generate a full SQL dump and store it alongside the table data
                    $sqlDump = generateSqlDump($dbBackup, $backupTables);
                    $dbBackup->fetchOne(
                        "INSERT INTO backup_data (snapshot_id, table_name, data_json, record_count) VALUES (?, 'sql_dump', ?, 1)",
                        [$snapshotId, json_encode($sqlDump)]
                    );

                    $empCount = $recordCounts['employees'] ?? 0;
                    addActivityLog('backup_create', "Created DB snapshot: $snapshotLabel (ID: $snapshotId, {$empCount} employees, full SQL dump included)", 'backup');
                    $message = "✅ Backup created successfully: $snapshotLabel (includes full database SQL dump)";
                    $messageType = 'success';
                } else {
                    $message = "⛔ Failed to create backup: could not retrieve snapshot ID.";
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                $message = "⛔ Backup failed: " . $e->getMessage();
                $messageType = 'error';
            }
            break;
            
        case 'upload_backup':
            // Helper: redirect to backups tab with a message and exit immediately.
            // Using an inline closure avoids relying on the generic redirect below,
            // which reads $_POST['current_tab'] and could land on the wrong tab.
            $uploadRedirect = function(string $msg, string $type) {
                $encoded = base64_encode($msg);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=backups&msg=' . $encoded . '&type=' . urlencode($type));
                exit;
            };

            if (!hasPermission('manage_backups')) {
                $uploadRedirect("⛔ You don't have permission to manage backups.", 'error');
            }

            if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
                $errCode = $_FILES['backup_file']['error'] ?? -1;
                $uploadErrMap = [
                    UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload_max_filesize limit (' . ini_get('upload_max_filesize') . ').',
                    UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form MAX_FILE_SIZE limit.',
                    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE    => 'No file was selected for upload.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary upload folder.',
                    UPLOAD_ERR_CANT_WRITE => 'Server failed to write the file to disk.',
                    UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
                ];
                $uploadRedirect("⛔ Upload error: " . ($uploadErrMap[$errCode] ?? "Unknown error (code $errCode)."), 'error');
            }

            try {
                $uploadedFile = $_FILES['backup_file']['tmp_name'];
                $filename     = $_FILES['backup_file']['name'];
                $ext          = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                if (!in_array($ext, ['json', 'zip'])) {
                    throw new Exception("Only .zip or .json files are accepted.");
                }

                // Extract JSON from zip or read directly
                if ($ext === 'zip') {
                    $zip = new ZipArchive();
                    if ($zip->open($uploadedFile) !== true) throw new Exception("Could not open zip file.");
                    $jsonContent = null;
                    for ($zi = 0; $zi < $zip->numFiles; $zi++) {
                        $stat = $zip->statIndex($zi);
                        if (strtolower(pathinfo($stat['name'], PATHINFO_EXTENSION)) === 'json') {
                            $jsonContent = $zip->getFromIndex($zi);
                            break;
                        }
                    }
                    $zip->close();
                    if ($jsonContent === null) throw new Exception("No JSON file found inside zip.");
                } else {
                    $jsonContent = file_get_contents($uploadedFile);
                }

                $data = json_decode($jsonContent, true);
                if ($data === null) throw new Exception("Invalid JSON: " . json_last_error_msg());
                if (!isset($data['employees']) || !is_array($data['employees'])) throw new Exception("Missing employees array.");

                // Ensure required fields exist (do NOT convert schedule format - app uses array natively)
                foreach ($data['employees'] as &$emp) {
                    foreach (['supervisor_id'=>null,'level'=>'','email'=>'','skills'=>[]] as $k=>$def) {
                        if (!isset($emp[$k])) $emp[$k] = $def;
                    }
                    // schedule must always be an array e.g. [0,1,1,1,1,1,0]
                    if (!isset($emp['schedule']) || !is_array($emp['schedule'])) {
                        $emp['schedule'] = [0,1,1,1,1,1,0]; // default Mon-Fri
                    }
                }
                unset($emp);

                $overrides = $data['scheduleOverrides'] ?? $data['overrides'] ?? [];

                // Save as DB snapshot
                require_once __DIR__ . '/Database.php';
                $dbUpl = Database::getInstance();
                $snapLabel  = 'Uploaded: ' . $filename . ' — ' . date('Y-m-d H:i:s');
                $snapCounts = ['employees' => count($data['employees']), 'schedule_overrides' => count($overrides)];
                $dbUpl->fetchOne(
                    "INSERT INTO backup_snapshots (snapshot_name, description, backup_type, created_by, created_at, record_counts, is_legacy_import)
                     VALUES (?, 'Uploaded via backup restore UI', 'json_import', ?, NOW(), ?, 0)",
                    [$snapLabel, $currentUser['id'], json_encode($snapCounts)]
                );
                $sidRow     = $dbUpl->fetchOne("SELECT LAST_INSERT_ID() as sid");
                $newSnapId  = $sidRow ? (int)$sidRow['sid'] : 0;
                if ($newSnapId > 0) {
                    $dbUpl->fetchOne(
                        "INSERT INTO backup_data (snapshot_id, table_name, data_json, record_count) VALUES (?, 'employees', ?, ?)",
                        [$newSnapId, json_encode($data['employees']), count($data['employees'])]
                    );
                    $dbUpl->fetchOne(
                        "INSERT INTO backup_data (snapshot_id, table_name, data_json, record_count) VALUES (?, 'schedule_overrides', ?, ?)",
                        [$newSnapId, json_encode($overrides), count($overrides)]
                    );
                }

                // Upload only saves the snapshot — it does NOT immediately apply to live data.
                // The user can then choose Merge or Restore All from the backup list below.
                if ($newSnapId > 0) {
                    addActivityLog('backup_upload', "Uploaded backup file: $filename (" . count($data['employees']) . " employees, snapshot #$newSnapId) — awaiting Merge or Restore", 'backup');
                    $uploadRedirect(
                        "✅ Backup &quot;" . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') . "&quot; saved as snapshot #$newSnapId (" . count($data['employees']) . " employees, " . count($overrides) . " overrides). Use <strong>Merge</strong> or <strong>Restore All</strong> from the list below.",
                        'success'
                    );
                } else {
                    $uploadRedirect("⛔ File parsed but failed to create snapshot record.", 'error');
                }
            } catch (Exception $e) {
                $uploadRedirect("⛔ Upload failed: " . $e->getMessage(), 'error');
            }
            break; // never reached — all paths above call $uploadRedirect() which exits
            
        case 'save_backup_schedule':
            if (!hasPermission('manage_backups')) {
                $message = "⛔ You don't have permission to manage backups.";
                $messageType = 'error';
                break;
            }
            try {
                require_once __DIR__ . '/Database.php';
                $dbSched = Database::getInstance();
                $pdo     = $dbSched->getConnection();

                $freq    = $_POST['backup_frequency'] ?? 'disabled';
                $hour    = (int)($_POST['backup_hour']   ?? 2);
                $minute  = (int)($_POST['backup_minute'] ?? 0);
                $keep    = max(1, min(90, (int)($_POST['backup_keep'] ?? 7)));

                $validFreqs = ['disabled', 'daily', 'weekly', 'monthly'];
                if (!in_array($freq, $validFreqs)) $freq = 'disabled';
                $hour   = max(0, min(23, $hour));
                $minute = max(0, min(59, $minute));

                $schedJson = json_encode([
                    'frequency'   => $freq,
                    'hour'        => $hour,
                    'minute'      => $minute,
                    'keep'        => $keep,
                    'updated_at'  => date('Y-m-d H:i:s'),
                    'updated_by'  => $currentUser['id'] ?? 0,
                ]);

                $pdo->prepare(
                    "INSERT INTO settings (setting_key, setting_value, description)
                     VALUES ('backup_schedule', :val, 'Automatic backup schedule configuration')
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
                )->execute([':val' => $schedJson]);

                addActivityLog('backup_schedule', "Scheduled backup set to: $freq at " . sprintf('%02d:%02d', $hour, $minute) . ", keep $keep", 'backup');
                $message     = "✅ Backup schedule saved. Next backup: $freq at " . sprintf('%02d:%02d', $hour, $minute) . ' ET';
                $messageType = 'success';
            } catch (Exception $e) {
                $message     = "⛔ Failed to save schedule: " . $e->getMessage();
                $messageType = 'error';
            }
            header("Location: ?tab=backups");
            exit;

        case 'delete_backup':
            if (!hasPermission('manage_backups')) {
                $message = "⛔ You don't have permission to manage backups.";
                $messageType = 'error';
                break;
            }
            
            $snapshotId = (int)($_POST['snapshot_id'] ?? 0);
            if ($snapshotId > 0) {
                try {
                    require_once __DIR__ . '/Database.php';
                    $dbDel = Database::getInstance();
                    $snap = $dbDel->fetchOne("SELECT snapshot_name FROM backup_snapshots WHERE id = ?", [$snapshotId]);
                    if ($snap) {
                        $dbDel->fetchOne("DELETE FROM backup_data WHERE snapshot_id = ?", [$snapshotId]);
                        $dbDel->fetchOne("DELETE FROM backup_snapshots WHERE id = ?", [$snapshotId]);
                        addActivityLog('backup_delete', "Deleted DB snapshot: " . $snap['snapshot_name'] . " (ID: $snapshotId)", 'backup');
                        $message = "✅ Backup deleted: " . htmlspecialchars($snap['snapshot_name']);
                        $messageType = 'success';
                    } else {
                        $message = "⛔ Backup not found.";
                        $messageType = 'error';
                    }
                } catch (Exception $e) {
                    $message = "⛔ Delete failed: " . $e->getMessage();
                    $messageType = 'error';
                }
            } else {
                $message = "⛔ Invalid backup ID.";
                $messageType = 'error';
            }
            break;
            
        case 'bulk_delete_backups':
            if (!hasPermission('manage_backups')) {
                $message = "⛔ You don't have permission to manage backups.";
                $messageType = 'error';
                break;
            }
            $bulkIds = $_POST['bulk_snapshot_ids'] ?? '';
            $idList  = array_filter(array_map('intval', explode(',', $bulkIds)));
            if (empty($idList)) {
                $message = "⛔ No backups selected.";
                $messageType = 'error';
                break;
            }
            try {
                require_once __DIR__ . '/Database.php';
                $dbBulkDel = Database::getInstance();
                $deleted = 0;
                foreach ($idList as $bid) {
                    $bsnap = $dbBulkDel->fetchOne("SELECT snapshot_name FROM backup_snapshots WHERE id = ?", [$bid]);
                    if ($bsnap) {
                        $dbBulkDel->fetchOne("DELETE FROM backup_data WHERE snapshot_id = ?", [$bid]);
                        $dbBulkDel->fetchOne("DELETE FROM backup_snapshots WHERE id = ?", [$bid]);
                        addActivityLog('backup_delete', "Bulk deleted DB snapshot: " . $bsnap['snapshot_name'] . " (ID: $bid)", 'backup');
                        $deleted++;
                    }
                }
                $message = "✅ Deleted $deleted backup(s).";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = "⛔ Bulk delete failed: " . $e->getMessage();
                $messageType = 'error';
            }
            header("Location: ?tab=backups");
            exit;

        case 'restore_backup':
            if (!hasPermission('manage_backups')) {
                $message = "⛔ You don't have permission to manage backups.";
                $messageType = 'error';
                break;
            }
            
            $restoreSnapshotId = (int)($_POST['snapshot_id'] ?? 0);
            
            if ($restoreSnapshotId <= 0) {
                $message = "⛔ Invalid backup ID.";
                $messageType = 'error';
                break;
            }
            
            try {
                require_once __DIR__ . '/Database.php';
                $dbRestore = Database::getInstance();
                
                $snapMeta = $dbRestore->fetchOne("SELECT * FROM backup_snapshots WHERE id = ?", [$restoreSnapshotId]);
                if (!$snapMeta) {
                    $message = "⛔ Backup snapshot not found.";
                    $messageType = 'error';
                    break;
                }
                
                $empRow = $dbRestore->fetchOne(
                    "SELECT data_json FROM backup_data WHERE snapshot_id = ? AND table_name = 'employees'",
                    [$restoreSnapshotId]
                );
                $ovRow = $dbRestore->fetchOne(
                    "SELECT data_json FROM backup_data WHERE snapshot_id = ? AND table_name = 'schedule_overrides'",
                    [$restoreSnapshotId]
                );
                
                $restoredEmployees = $empRow ? (json_decode($empRow['data_json'], true) ?? []) : [];
                $restoredOverrides = $ovRow  ? normalizeScheduleOverrides(json_decode($ovRow['data_json'], true) ?? []) : [];
                
                if (empty($restoredEmployees)) {
                    $message = "⛔ No employee data found in this backup.";
                    $messageType = 'error';
                    break;
                }
                
                // Auto-save current data as a pre-restore DB snapshot
                $preLabel = '🔒 Pre-Restore Snapshot - ' . date('Y-m-d H:i:s');
                $preCounts = ['employees' => count($employees), 'scheduleOverrides' => count($scheduleOverrides)];
                $dbRestore->fetchOne(
                    "INSERT INTO backup_snapshots (snapshot_name, description, backup_type, created_by, created_at, record_counts, is_legacy_import)
                     VALUES (?, 'Auto-created before restore', 'pre_restore', ?, NOW(), ?, 0)",
                    [$preLabel, $currentUser['id'], json_encode($preCounts)]
                );
                $preRow = $dbRestore->fetchOne("SELECT LAST_INSERT_ID() as sid");
                $preId  = $preRow ? (int)$preRow['sid'] : 0;
                if ($preId > 0) {
                    $dbRestore->fetchOne(
                        "INSERT INTO backup_data (snapshot_id, table_name, data_json, record_count) VALUES (?, 'employees', ?, ?)",
                        [$preId, json_encode($employees), count($employees)]
                    );
                    $dbRestore->fetchOne(
                        "INSERT INTO backup_data (snapshot_id, table_name, data_json, record_count) VALUES (?, 'schedule_overrides', ?, ?)",
                        [$preId, json_encode($scheduleOverrides), count($scheduleOverrides)]
                    );
                }
                
                // Apply restored data
                $employees = $restoredEmployees;
                $scheduleOverrides = $restoredOverrides;
                $nextId = max(array_column($employees, 'id') ?: [0]) + 1;

                // Normalise each employee record: convert DB-format fields
                // (weekly_schedule, hire_date, skills-as-JSON) to the app format
                // that saveData() expects.
                foreach ($employees as &$employee) {
                    $employee = normalizeBackupEmployee($employee);
                }
                unset($employee);

                // Clear existing data first to avoid UNIQUE constraint conflicts
                // (e.g. email index) when incoming IDs differ from current DB rows
                try {
                    $pdo = $dbRestore->getConnection();
                    $pdo->exec("DELETE FROM schedule_overrides");
                    $pdo->exec("DELETE FROM employees");
                } catch (Exception $clearErr) {
                    throw new Exception("Could not clear existing data before restore: " . $clearErr->getMessage());
                }

                $saveResult = saveData();
                if ($saveResult === true) {
                    addActivityLog('backup_restore', "Restored DB snapshot: " . $snapMeta['snapshot_name'] . " (ID: $restoreSnapshotId, " . count($employees) . " employees). Pre-restore snapshot ID: $preId", 'backup');
                    $message = "✅ Successfully restored " . count($employees) . " employees from: " . htmlspecialchars($snapMeta['snapshot_name']) . "<br>🔒 Pre-restore snapshot saved (ID: $preId)";
                    $messageType = 'success';
                } else {
                    // Grab last DB error from PHP error log to surface to user
                    $lastErr = error_get_last();
                    $errDetail = $lastErr ? ' — ' . htmlspecialchars($lastErr['message']) : '';
                    $message = "⛔ Failed to save restored data to database." . $errDetail . " (Check server error log for details.)";
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                $message = "⛔ Restore failed: " . $e->getMessage();
                $messageType = 'error';
            }
            break;
            
        case 'cleanup_old_overrides':
            // Only supervisors, managers, and admins can run cleanup
            if (!hasPermission('manage_backups')) {
                $message = "⛔ You don't have permission to cleanup old data.";
                $messageType = 'error';
                break;
            }
            
            // Create DB snapshot before cleanup
            $backupFilename = 'Pre-Cleanup Snapshot - ' . date('Y-m-d H:i:s');
            try {
                require_once __DIR__ . '/Database.php';
                $dbCleanup = Database::getInstance();
                $cleanupCounts = ['employees' => count($employees), 'scheduleOverrides' => count($scheduleOverrides)];
                $dbCleanup->fetchOne(
                    "INSERT INTO backup_snapshots (snapshot_name, description, backup_type, created_by, created_at, record_counts, is_legacy_import)
                     VALUES (?, 'Auto-created before cleanup operation', 'pre_cleanup', ?, NOW(), ?, 0)",
                    [$backupFilename, $currentUser['id'], json_encode($cleanupCounts)]
                );
                $cleanupPreRow = $dbCleanup->fetchOne("SELECT LAST_INSERT_ID() as sid");
                $cleanupPreId  = $cleanupPreRow ? (int)$cleanupPreRow['sid'] : 0;
                if ($cleanupPreId > 0) {
                    $dbCleanup->fetchOne(
                        "INSERT INTO backup_data (snapshot_id, table_name, data_json, record_count) VALUES (?, 'employees', ?, ?)",
                        [$cleanupPreId, json_encode($employees), count($employees)]
                    );
                    $dbCleanup->fetchOne(
                        "INSERT INTO backup_data (snapshot_id, table_name, data_json, record_count) VALUES (?, 'schedule_overrides', ?, ?)",
                        [$cleanupPreId, json_encode($scheduleOverrides), count($scheduleOverrides)]
                    );
                }
            } catch (Exception $e) {
                error_log("Pre-cleanup backup failed: " . $e->getMessage());
            }
            
            // Get months to keep from POST (default 3 months)
            $monthsToKeep = isset($_POST['months_to_keep']) && is_numeric($_POST['months_to_keep']) 
                            ? (int)$_POST['months_to_keep'] 
                            : 3;
            
            // Ensure reasonable range (1-12 months)
            $monthsToKeep = max(1, min(12, $monthsToKeep));
            
            // Get file size before cleanup
            $fileSizeBefore = 0; // No longer applicable — data is stored in DB, not a flat file

            // Perform cleanup
            $removedCount = cleanupOldScheduleOverrides($monthsToKeep);

            // File-size metric no longer meaningful
            $fileSizeAfter = 0;
            $savedBytes = 0;
            $savedKB = 0;
            
            if ($removedCount > 0) {
                $message = "✅ Successfully cleaned up $removedCount old schedule override entries<br>" .
                          "🔒 Pre-cleanup snapshot saved<br>" .
                          "💾 Space saved: " . number_format($savedKB) . " KB";
                $messageType = 'success';
            } else {
                $message = "ℹ️ No old entries found to clean up. All overrides are within the last $monthsToKeep months.<br>" .
                          "🔒 Pre-cleanup snapshot saved";
                $messageType = 'info';
            }
            break;
            
        case 'merge_restore_backup':
            if (!hasPermission('manage_backups')) {
                $message = "⛔ You don't have permission to manage backups.";
                $messageType = 'error';
                break;
            }

            $mergeSnapshotId = (int)($_POST['snapshot_id'] ?? 0);
            if ($mergeSnapshotId <= 0) {
                $message = "⛔ Invalid backup ID.";
                $messageType = 'error';
                break;
            }

            try {
                require_once __DIR__ . '/Database.php';
                $dbMerge   = Database::getInstance();
                $pdoMerge  = $dbMerge->getConnection();

                // Backup metadata — created_at is the cutoff timestamp
                $snapMeta = $dbMerge->fetchOne(
                    "SELECT * FROM backup_snapshots WHERE id = ?", [$mergeSnapshotId]
                );
                if (!$snapMeta) {
                    $message = "⛔ Backup snapshot not found.";
                    $messageType = 'error';
                    break;
                }
                $snapCreatedAt = $snapMeta['created_at'];

                // Load backup employees + overrides
                $empRow = $dbMerge->fetchOne(
                    "SELECT data_json FROM backup_data WHERE snapshot_id = ? AND table_name = 'employees'",
                    [$mergeSnapshotId]
                );
                $ovRow = $dbMerge->fetchOne(
                    "SELECT data_json FROM backup_data WHERE snapshot_id = ? AND table_name = 'schedule_overrides'",
                    [$mergeSnapshotId]
                );
                $backupEmployees = $empRow ? (json_decode($empRow['data_json'], true) ?? []) : [];
                $backupOverrides = $ovRow  ? normalizeScheduleOverrides(json_decode($ovRow['data_json'], true) ?? []) : [];

                if (empty($backupEmployees)) {
                    $message = "⛔ No employee data found in this backup.";
                    $messageType = 'error';
                    break;
                }

                // Auto-save current state as a pre-merge snapshot
                $preLabel  = '🔒 Pre-Merge Snapshot - ' . date('Y-m-d H:i:s');
                $preCounts = ['employees' => count($employees), 'scheduleOverrides' => count($scheduleOverrides)];
                $dbMerge->fetchOne(
                    "INSERT INTO backup_snapshots (snapshot_name, description, backup_type, created_by, created_at, record_counts, is_legacy_import)
                     VALUES (?, 'Auto-created before merge restore', 'pre_restore', ?, NOW(), ?, 0)",
                    [$preLabel, $currentUser['id'], json_encode($preCounts)]
                );
                $preRow = $dbMerge->fetchOne("SELECT LAST_INSERT_ID() as sid");
                $preId  = $preRow ? (int)$preRow['sid'] : 0;
                if ($preId > 0) {
                    $dbMerge->fetchOne(
                        "INSERT INTO backup_data (snapshot_id, table_name, data_json, record_count) VALUES (?, 'employees', ?, ?)",
                        [$preId, json_encode($employees), count($employees)]
                    );
                    $dbMerge->fetchOne(
                        "INSERT INTO backup_data (snapshot_id, table_name, data_json, record_count) VALUES (?, 'schedule_overrides', ?, ?)",
                        [$preId, json_encode($scheduleOverrides), count($scheduleOverrides)]
                    );
                }

                // Fetch current employees' updated_at from DB so we can compare timestamps
                $currentUpdatedAt = [];
                $empTs = $pdoMerge->query("SELECT id, updated_at FROM employees WHERE active = 1")
                                  ->fetchAll(PDO::FETCH_ASSOC);
                foreach ($empTs as $r) {
                    $currentUpdatedAt[(int)$r['id']] = $r['updated_at'];
                }

                // Index current employee IDs for fast lookup
                $currentEmpIds = array_flip(array_map('intval', array_column($employees, 'id')));

                $addedEmp   = 0;
                $updatedEmp = 0;
                $skippedEmp = 0;

                // force_overwrite=1 lets the backup win even when local records are newer
                $forceOverwrite = !empty($_POST['force_overwrite']);

                foreach ($backupEmployees as $backupEmp) {
                    $bid = (int)($backupEmp['id'] ?? 0);
                    if (!$bid) continue;

                    // Normalise DB-format fields (weekly_schedule, hire_date, skills-as-JSON)
                    $backupEmp = normalizeBackupEmployee($backupEmp);

                    if (!isset($currentEmpIds[$bid])) {
                        // New employee not in current system → add
                        $employees[] = $backupEmp;
                        $addedEmp++;
                    } else {
                        // Employee exists — overwrite if current record is older than the backup
                        // OR if force_overwrite is requested
                        $curTs = $currentUpdatedAt[$bid] ?? null;
                        if (!$forceOverwrite && $curTs && strtotime($curTs) >= strtotime($snapCreatedAt)) {
                            // Current is same age or newer → skip, keep local changes
                            $skippedEmp++;
                        } else {
                            // Current is older than the backup snapshot (or force) → update from backup
                            foreach ($employees as $idx => $emp) {
                                if ((int)$emp['id'] === $bid) {
                                    $employees[$idx] = $backupEmp;
                                    break;
                                }
                            }
                            $updatedEmp++;
                        }
                    }
                }

                // Merge schedule overrides — only add keys that are absent in current.
                // No per-record timestamps exist on overrides, so any key already present
                // is assumed to be the authoritative local version and is left untouched.
                $addedOvr   = 0;
                $skippedOvr = 0;
                foreach ($backupOverrides as $key => $override) {
                    if (isset($scheduleOverrides[$key])) {
                        $skippedOvr++;
                    } else {
                        $scheduleOverrides[$key] = $override;
                        $addedOvr++;
                    }
                }

                if (saveData()) {
                    $sn = htmlspecialchars($snapMeta['snapshot_name']);
                    addActivityLog('merge_restore',
                        "Merge-restored from: {$snapMeta['snapshot_name']} (ID: $mergeSnapshotId). " .
                        "Employees: +$addedEmp added, ~$updatedEmp updated, $skippedEmp kept (newer). " .
                        "Overrides: +$addedOvr added, $skippedOvr kept (existing). Pre-merge snapshot #$preId",
                        'backup'
                    );
                    $message = "✅ Merge complete from: <strong>$sn</strong><br>" .
                               "👥 Employees: +$addedEmp added, ~$updatedEmp updated, $skippedEmp skipped (local version is newer)<br>" .
                               "📅 Schedule overrides: +$addedOvr added, $skippedOvr kept (already exist locally)<br>" .
                               "🔒 Pre-merge snapshot saved (ID: $preId)";
                    $messageType = 'success';
                } else {
                    $message = "⛔ Merge restore failed to save.";
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                $message = "⛔ Merge restore failed: " . $e->getMessage();
                $messageType = 'error';
            }
            break;

        case 'selective_restore_backup':
            if (!hasPermission('manage_backups')) {
                $message = "⛔ You don't have permission to manage backups.";
                $messageType = 'error';
                break;
            }
            
            $selSnapId           = (int)($_POST['snapshot_id'] ?? 0);
            $selectedEmployeeIds = $_POST['selectedEmployees'] ?? [];
            
            if ($selSnapId <= 0 || empty($selectedEmployeeIds)) {
                $message = "⛔ Please select a backup and at least one employee to restore.";
                $messageType = 'error';
                break;
            }
            
            try {
                require_once __DIR__ . '/Database.php';
                $dbSel = Database::getInstance();
                
                // Load backup employees from DB
                $empRow = $dbSel->fetchOne(
                    "SELECT data_json FROM backup_data WHERE snapshot_id = ? AND table_name = 'employees'",
                    [$selSnapId]
                );
                $ovRow  = $dbSel->fetchOne(
                    "SELECT data_json FROM backup_data WHERE snapshot_id = ? AND table_name = 'schedule_overrides'",
                    [$selSnapId]
                );
                $snapMeta = $dbSel->fetchOne("SELECT snapshot_name FROM backup_snapshots WHERE id = ?", [$selSnapId]);
                
                if (!$empRow) throw new Exception("No employee data found for snapshot #$selSnapId.");
                
                $backupEmployees = json_decode($empRow['data_json'], true) ?? [];
                $backupOverrides = $ovRow ? normalizeScheduleOverrides(json_decode($ovRow['data_json'], true) ?? []) : [];
                
                // Save pre-selective-restore DB snapshot
                $preLabel  = 'Pre-Selective-Restore - ' . date('Y-m-d H:i:s');
                $preCounts = ['employees' => count($employees), 'schedule_overrides' => count($scheduleOverrides)];
                $dbSel->fetchOne(
                    "INSERT INTO backup_snapshots (snapshot_name, description, backup_type, created_by, created_at, record_counts, is_legacy_import)
                     VALUES (?, 'Auto-created before selective restore', 'pre_restore', ?, NOW(), ?, 0)",
                    [$preLabel, $currentUser['id'], json_encode($preCounts)]
                );
                $preRow = $dbSel->fetchOne("SELECT LAST_INSERT_ID() as sid");
                $preId  = $preRow ? (int)$preRow['sid'] : 0;
                if ($preId > 0) {
                    $dbSel->fetchOne("INSERT INTO backup_data (snapshot_id, table_name, data_json, record_count) VALUES (?, 'employees', ?, ?)",
                        [$preId, json_encode($employees), count($employees)]);
                    $dbSel->fetchOne("INSERT INTO backup_data (snapshot_id, table_name, data_json, record_count) VALUES (?, 'schedule_overrides', ?, ?)",
                        [$preId, json_encode($scheduleOverrides), count($scheduleOverrides)]);
                }
                
                $restoredCount = 0;
                $restoredNames = [];
                
                foreach ($selectedEmployeeIds as $employeeId) {
                    $employeeId = intval($employeeId);
                    
                    // Find in backup
                    $backupEmployee = null;
                    foreach ($backupEmployees as $emp) {
                        if ((int)$emp['id'] === $employeeId) { $backupEmployee = $emp; break; }
                    }
                    if (!$backupEmployee) continue;
                    
                    // Normalise DB-format fields (weekly_schedule, hire_date, skills-as-JSON)
                    $backupEmployee = normalizeBackupEmployee($backupEmployee);
                    
                    // Replace or add in current employees
                    $existingIndex = null;
                    foreach ($employees as $idx => $emp) {
                        if ((int)$emp['id'] === $employeeId) { $existingIndex = $idx; break; }
                    }
                    if ($existingIndex !== null) {
                        $employees[$existingIndex] = $backupEmployee;
                    } else {
                        $employees[] = $backupEmployee;
                    }
                    
                    // Restore overrides for this employee from normalised app-format array
                    foreach ($backupOverrides as $key => $override) {
                        if (strpos((string)$key, "{$employeeId}-") === 0) {
                            $scheduleOverrides[$key] = $override;
                        }
                    }
                    
                    $restoredCount++;
                    $restoredNames[] = $backupEmployee['name'];
                }
                
                if ($restoredCount > 0 && saveData()) {
                    $namesList = implode(', ', array_slice($restoredNames, 0, 5));
                    if (count($restoredNames) > 5) $namesList .= ' and ' . (count($restoredNames) - 5) . ' more';
                    $snapName = $snapMeta ? $snapMeta['snapshot_name'] : "Snapshot #$selSnapId";
                    
                    addActivityLog('selective_restore',
                        "Selectively restored $restoredCount employee(s) from: $snapName ($namesList). Pre-restore snapshot #$preId",
                        'backup');
                    
                    $message = "✅ Restored $restoredCount employee(s): $namesList<br>🔒 Pre-restore snapshot saved (#$preId)";
                    $messageType = 'success';
                } elseif ($restoredCount === 0) {
                    $message = "⛔ None of the selected employees were found in this backup.";
                    $messageType = 'error';
                } else {
                    $message = "⛔ Failed to save restored data.";
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                $message = "⛔ Selective restore failed: " . $e->getMessage();
                $messageType = 'error';
            }
            break;
            
case 'bulk_schedule_change':
    if (!hasPermission('edit_schedule')) {
        $message = "⛔ You don't have permission to make bulk schedule changes.";
        $messageType = 'error';
        break;
    }
    
    $startDate = trim($_POST['startDate'] ?? '');
    $endDate = trim($_POST['endDate'] ?? '');
    $selectedEmployees = $_POST['selectedEmployees'] ?? [];
    $bulkStatus = $_POST['bulkStatus'] ?? '';
    $bulkComment = trim($_POST['bulkComment'] ?? '');
    $bulkCustomHours = trim($_POST['bulkCustomHours'] ?? '');
    $skipDaysOff = isset($_POST['skipDaysOff']) && $_POST['skipDaysOff'] === 'on';
    $newSchedule = $_POST['newSchedule'] ?? []; // Array of days to apply changes to (0=Sunday, 6=Saturday)
    $changeShift = isset($_POST['changeShift']) && $_POST['changeShift'] === 'on'; // Change shift option
    $newShift = isset($_POST['newShift']) ? intval($_POST['newShift']) : 0; // New shift value
    $shiftChangeWhen = $_POST['shiftChangeWhen'] ?? 'now'; // When to apply shift change (now or start_date)
    
    if ($startDate && $endDate && !empty($selectedEmployees) && $bulkStatus) {
        $changesCount = 0;
        $skippedCount = 0;
        $employeeNames = [];
        $shiftChangedCount = 0; // NEW: Track shift changes
        
        // Create a lookup array for employees
        $employeeLookup = [];
        foreach ($employees as $emp) {
            $employeeLookup[$emp['id']] = $emp;
            if (in_array($emp['id'], $selectedEmployees)) {
                $employeeNames[] = $emp['name'];
            }
        }
        
        // Handle shift changes for selected employees
        $shiftScheduledCount = 0; // Track scheduled shift changes
        if ($changeShift && $newShift > 0) {
            // Parse start date to check if it's in the future
            $startDateTime = new DateTime($startDate);
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            $startDateTime->setTime(0, 0, 0);
            $isStartDateFuture = $startDateTime > $today;
            
            if ($shiftChangeWhen === 'now' || !$isStartDateFuture) {
                // Apply shift change immediately
                foreach ($employees as &$employee) {
                    if (in_array($employee['id'], $selectedEmployees)) {
                        $oldShift = $employee['shift'];
                        $employee['shift'] = $newShift;
                        $shiftChangedCount++;
                        
                        // Log individual shift change
                        $effectiveNote = ($shiftChangeWhen === 'start_date' && !$isStartDateFuture) 
                            ? " (effective " . $startDateTime->format('M j, Y') . ")"
                            : "";
                        addActivityLog('shift_change', 
                            "Changed shift for {$employee['name']} from " . getShiftName($oldShift) . " to " . getShiftName($newShift) . $effectiveNote,
                            'employee',
                            $employee['id']
                        );
                    }
                }
                unset($employee); // Break reference
            } else {
                // Schedule shift change for future date
                // Store in scheduleOverrides for future processing
                foreach ($selectedEmployees as $employeeId) {
                    $employee = $employeeLookup[$employeeId] ?? null;
                    if ($employee) {
                        $overrideKey = "$employeeId-shift-" . $startDateTime->format('Y-m-d');
                        $scheduleOverrides[$overrideKey] = [
                            'type' => 'shift_change',
                            'employeeId' => $employeeId,
                            'employeeName' => $employee['name'],
                            'oldShift' => $employee['shift'],
                            'newShift' => $newShift,
                            'effectiveDate' => $startDate,
                            'scheduledBy' => getCurrentUserName(),
                            'scheduledAt' => date('Y-m-d H:i:s')
                        ];
                        $shiftScheduledCount++;
                        
                        // Log scheduled shift change
                        addActivityLog('shift_scheduled', 
                            "Scheduled shift change for {$employee['name']} from " . getShiftName($employee['shift']) . 
                            " to " . getShiftName($newShift) . " effective " . $startDateTime->format('M j, Y'),
                            'employee',
                            $employeeId
                        );
                    }
                }
            }
        }
        
        // Parse start and end dates
        $startDateTime = new DateTime($startDate);
        $endDateTime = new DateTime($endDate);
        
        if ($startDateTime > $endDateTime) {
            $message = "⛔ Start date must be before end date.";
            $messageType = 'error';
            break;
        }
        
        // Process each date in the range
        $currentDate = clone $startDateTime;
        while ($currentDate <= $endDateTime) {
            $year = intval($currentDate->format('Y'));
            $month = intval($currentDate->format('n')) - 1; // Convert to 0-based
            $day = intval($currentDate->format('j'));
            $dayOfWeek = intval($currentDate->format('w')); // 0 = Sunday, 6 = Saturday
            
            // If newSchedule is set, only apply to those days
            if (!empty($newSchedule) && !in_array((string)$dayOfWeek, $newSchedule)) {
                $skippedCount++;
                $currentDate->add(new DateInterval('P1D'));
                continue; // Skip this date - not in the new schedule
            }
            
            foreach ($selectedEmployees as $employeeId) {
                $employeeId = intval($employeeId);
                $employee = $employeeLookup[$employeeId] ?? null;
                
                // Check if we should skip days off based on employee schedule
                if ($skipDaysOff && $employee) {
                    $isScheduledToWork = isset($employee['schedule'][$dayOfWeek]) && $employee['schedule'][$dayOfWeek] == 1;
                    
                    if (!$isScheduledToWork) {
                        $skippedCount++;
                        continue; // Skip this day for this employee
                    }
                }
                
                $overrideKey = "$employeeId-$year-$month-$day";
                
                if ($bulkStatus === 'schedule') {
                    if (isset($scheduleOverrides[$overrideKey])) {
                        unset($scheduleOverrides[$overrideKey]);
                        $changesCount++;
                    }
                } else {
                    $override = [
                        'status' => $bulkStatus,
                        'comment' => $bulkComment
                    ];
                    
                    if ($bulkStatus === 'custom_hours' && $bulkCustomHours) {
                        $override['customHours'] = $bulkCustomHours;
                    }
                    
                    $scheduleOverrides[$overrideKey] = $override;
                    $changesCount++;
                }
            }
            
            $currentDate->add(new DateInterval('P1D'));
        }
        
        if (saveData()) {
            $dateRangeText = $startDate === $endDate 
                ? date('M j, Y', strtotime($startDate)) 
                : date('M j, Y', strtotime($startDate)) . ' to ' . date('M j, Y', strtotime($endDate));
            
            $employeeList = implode(', ', array_slice($employeeNames, 0, 3));
            if (count($employeeNames) > 3) {
                $employeeList .= ' and ' . (count($employeeNames) - 3) . ' others';
            }
            
            $statusText = getStatusText($bulkStatus);
            if ($bulkStatus === 'custom_hours' && $bulkCustomHours) {
                $statusText = "Custom Hours ($bulkCustomHours)";
            } elseif ($bulkStatus === 'schedule') {
                $statusText = "Reset to Default Schedule";
            }
            
            $commentText = $bulkComment ? " ($bulkComment)" : '';
            $scheduleText = '';
            
            // Build schedule description
            if (!empty($newSchedule)) {
                $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                $scheduleDayNames = array_map(function($d) use ($dayNames) { return $dayNames[$d]; }, $newSchedule);
                $scheduleText = " [Schedule: " . implode(', ', $scheduleDayNames) . "]";
            }
            
            // Build shift change text
            $shiftText = '';
            if ($shiftChangedCount > 0) {
                $shiftText = " [Shift changed to " . getShiftName($newShift) . " for $shiftChangedCount employees - IMMEDIATE]";
            } elseif ($shiftScheduledCount > 0) {
                $shiftText = " [Shift change to " . getShiftName($newShift) . " scheduled for " . 
                            date('M j, Y', strtotime($startDate)) . " - $shiftScheduledCount employees]";
            }
            
            $skipText = '';
            if ($skippedCount > 0) {
                if ($skipDaysOff && !empty($newSchedule)) {
                    $skipText = " (Skipped $skippedCount: custom schedule + employee days off)";
                } elseif ($skipDaysOff) {
                    $skipText = " (Skipped $skippedCount days off per employee schedules)";
                } elseif (!empty($newSchedule)) {
                    $skipText = " (Skipped $skippedCount non-scheduled days)";
                }
            }
            
            $logDetails = "Bulk update: $changesCount changes to $employeeList for $dateRangeText - Status: $statusText$commentText$scheduleText$shiftText$skipText";
            addActivityLog('bulk_change', $logDetails, 'schedule');
            
            $message = "✅ Bulk update completed: $changesCount changes applied to $employeeList for $dateRangeText - Status: $statusText$commentText$scheduleText$shiftText$skipText";
            $messageType = 'success';
        } else {
            $message = "⛔ Failed to save bulk changes.";
            $messageType = 'error';
        }
    } else {
        $message = "⛔ Please fill in all required fields for bulk changes.";
        $messageType = 'error';
    }
    break;

case 'download_schedule_csv':
    if (!hasPermission('edit_schedule')) {
        $message = "⛔ You don't have permission to download schedules.";
        $messageType = 'error';
        break;
    }
    
    // Get team filter from form
    $downloadTeam = trim($_POST['download_team'] ?? '');
    
    // Get date range from form or use defaults
    $downloadStartDate = trim($_POST['download_start_date'] ?? '');
    $downloadEndDate = trim($_POST['download_end_date'] ?? '');
    
    // Generate CSV with current schedule
    $csv = "Employee Name,Date,Status,Hours,Shift,Custom Hours,Team\n";
    
    // Parse date range
    if ($downloadStartDate && $downloadEndDate) {
        $startDate = DateTime::createFromFormat('Y-m-d', $downloadStartDate);
        $endDate = DateTime::createFromFormat('Y-m-d', $downloadEndDate);
        
        if (!$startDate || !$endDate) {
            // Fallback to defaults if invalid dates
            $startDate = new DateTime('first day of this month');
            $endDate = new DateTime('last day of next month');
        }
    } else {
        // Default to current month + next month
        $startDate = new DateTime('first day of this month');
        $endDate = new DateTime('last day of next month');
    }
    
    $employeeCount = 0;
    
    // Loop through each employee
    foreach ($employees as $emp) {
        // Filter by team if specified
        if ($downloadTeam !== '') {
            $empTeam = strtoupper(trim($emp['team'] ?? ''));
            if ($empTeam !== strtoupper($downloadTeam)) {
                continue; // Skip this employee if not in selected team
            }
        }
        
        $employeeCount++;
        $empName = $emp['name'];
        $empTeam = strtoupper(trim($emp['team'] ?? ''));
        $shift = $emp['shift'] ?? 1;
        $schedule = $emp['schedule'] ?? [0,1,1,1,1,1,0]; // Default M-F
        
        // Loop through each day in range
        $currentDate = clone $startDate;
        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->format('Y-m-d');
            $dayOfWeek = (int)$currentDate->format('w'); // 0=Sunday, 6=Saturday
            
            // Check if employee normally works this day
            $normallyWorks = isset($schedule[$dayOfWeek]) && $schedule[$dayOfWeek] == 1;
            
            // Check for override — key format: {empId}-{year}-{month0idx}-{day}
            $overrideDt  = new DateTime($dateStr);
            $ovrYear     = (int)$overrideDt->format('Y');
            $ovrMonth0   = (int)$overrideDt->format('n') - 1;
            $ovrDay      = (int)$overrideDt->format('j');
            $overrideKey = $emp['id'] . '-' . $ovrYear . '-' . $ovrMonth0 . '-' . $ovrDay;
            $override = $scheduleOverrides[$overrideKey] ?? null;
            
            // Determine status and hours
            if ($override) {
                $status = strtoupper($override['status'] ?? 'ON');
                // Use actual hours from override or employee default
                $hours = $override['hours'] ?? ($normallyWorks ? ($emp['hours'] ?? '9am-5pm') : '0');
                $customHours = $override['customHours'] ?? $override['custom_hours'] ?? '';
            } else {
                $status = $normallyWorks ? 'ON' : 'OFF';
                // Use employee's actual hours field (12-hour format like 9am-5pm)
                $hours = $normallyWorks ? ($emp['hours'] ?? '9am-5pm') : '0';
                $customHours = '';
            }
            
            // Add row to CSV with team
            $csv .= '"' . $empName . '","' . $dateStr . '","' . $status . '","' . $hours . '",' . $shift . ',"' . $customHours . '","' . $empTeam . '"' . "\n";
            
            $currentDate->modify('+1 day');
        }
    }
    
    // Create filename with team name if filtered
    $filename = 'schedule_' . date('Y-m-d');
    if ($downloadTeam !== '') {
        $filename .= '_' . strtolower(str_replace(' ', '_', $downloadTeam));
    }
    $filename .= '.csv';
    
    // Send CSV as download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $csv;
    exit;
    break;

case 'upload_schedule_csv':
    if (!hasPermission('edit_schedule')) {
        $message = "⛔ You don't have permission to upload schedules.";
        $messageType = 'error';
        break;
    }
    
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $message = "⛔ Error uploading file. Please try again.";
        $messageType = 'error';
        break;
    }
    
    $filePath = $_FILES['csv_file']['tmp_name'];
    
    // Read and parse CSV
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        $message = "⛔ Could not read CSV file.";
        $messageType = 'error';
        break;
    }
    
    // Create automatic DB snapshot before applying changes
    try {
        $dbBkp = Database::getInstance();
        $edtBkp = new DateTime('now', new DateTimeZone('America/New_York'));
        $bkpLabel = '📤 Pre-CSV Upload Backup - ' . $edtBkp->format('Y-m-d H:i:s');
        $dbBkp->fetchOne(
            "INSERT INTO backup_snapshots (snapshot_name, description, backup_type, created_by, created_at, record_counts, is_legacy_import)
             VALUES (?, 'Automatic backup before CSV bulk upload', 'auto', ?, NOW(), ?, 0)",
            [$bkpLabel, $currentUser['id'] ?? 0, json_encode(['employees' => count($employees), 'scheduleOverrides' => count($scheduleOverrides)])]
        );
        $bkpRow = $dbBkp->fetchOne("SELECT LAST_INSERT_ID() as sid");
        $bkpId  = $bkpRow ? (int)$bkpRow['sid'] : 0;
        if ($bkpId > 0) {
            $dbBkp->fetchOne("INSERT INTO backup_data (snapshot_id, table_name, data_json, record_count) VALUES (?, 'employees', ?, ?)",
                [$bkpId, json_encode($employees), count($employees)]);
            $dbBkp->fetchOne("INSERT INTO backup_data (snapshot_id, table_name, data_json, record_count) VALUES (?, 'schedule_overrides', ?, ?)",
                [$bkpId, json_encode($scheduleOverrides), count($scheduleOverrides)]);
        }
    } catch (Exception $bkpEx) {
        error_log('CSV upload pre-backup failed: ' . $bkpEx->getMessage());
    }
    
    // Skip header row
    fgetcsv($handle);
    
    $successCount = 0;
    $errorCount = 0;
    $errors = [];
    $changesApplied = [];
    $affectedEmployees = []; // Track unique employees
    $affectedTeams = []; // Track which teams were affected
    
    while (($row = fgetcsv($handle)) !== false) {
        // Parse CSV row (support both old and new format with Team column)
        $empName = trim($row[0] ?? '');
        $date = trim($row[1] ?? '');
        $status = strtoupper(trim($row[2] ?? ''));
        $hours = trim($row[3] ?? '0'); // Keep as string to support 12-hour format like "9am-5pm"
        $shift = intval($row[4] ?? 1);
        $customHours = trim($row[5] ?? '');
        // $team = trim($row[6] ?? ''); // Optional team column (not used for validation)
        
        // Validate employee exists
        $employee = null;
        foreach ($employees as $emp) {
            if (strtolower($emp['name']) === strtolower($empName)) {
                $employee = $emp;
                break;
            }
        }
        
        if (!$employee) {
            $errors[] = "Employee not found: $empName";
            $errorCount++;
            continue;
        }
        
        // Track affected employee and team
        if (!in_array($employee['id'], $affectedEmployees)) {
            $affectedEmployees[] = $employee['id'];
            $empTeam = strtoupper(trim($employee['team'] ?? 'UNKNOWN'));
            if (!in_array($empTeam, $affectedTeams)) {
                $affectedTeams[] = $empTeam;
            }
        }
        
        // Validate date
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObj) {
            $errors[] = "Invalid date format: $date (use YYYY-MM-DD)";
            $errorCount++;
            continue;
        }
        
        // Validate status
        $validStatuses = ['ON', 'OFF', 'PTO', 'SICK', 'HOLIDAY', 'CUSTOM_HOURS', 'SCHEDULE'];
        if (!in_array($status, $validStatuses)) {
            $errors[] = "Invalid status '$status' for $empName on $date";
            $errorCount++;
            continue;
        }
        
        // Build key in the format saveData() expects: {empId}-{year}-{month0idx}-{day}
        $dateObj2    = new DateTime($date);
        $yr          = (int)$dateObj2->format('Y');
        $mo0         = (int)$dateObj2->format('n') - 1; // 0-indexed month
        $dy          = (int)$dateObj2->format('j');
        $overrideKey = $employee['id'] . '-' . $yr . '-' . $mo0 . '-' . $dy;

        if ($status === 'SCHEDULE') {
            // Reset to default schedule — remove any existing override
            unset($scheduleOverrides[$overrideKey]);
            $changesApplied[] = "$empName: $date reset to default schedule";
            $successCount++;
        } else {
            // Create or update override
            $override = [
                'status'  => strtolower($status),
                'hours'   => $hours,
                'comment' => '',
            ];
            // camelCase 'customHours' is what saveData() reads for the notes column
            if ($status === 'CUSTOM_HOURS' && $customHours !== '') {
                $override['customHours'] = $customHours;
            }

            $scheduleOverrides[$overrideKey] = $override;
            $changesApplied[] = "$empName: $date set to $status";
            $successCount++;
        }
    }
    
    fclose($handle);
    
    // Save changes
    if ($successCount > 0) {
        if (saveScheduleData($employees, $scheduleOverrides)) {
            // Build team info string
            $teamInfo = '';
            if (count($affectedTeams) > 0) {
                sort($affectedTeams);
                $teamInfo = ' [Teams: ' . implode(', ', $affectedTeams) . ']';
            }
            
            $summary = "✅ CSV Upload Complete: $successCount changes applied to " . count($affectedEmployees) . " employees$teamInfo";
            if ($errorCount > 0) {
                $summary .= ", $errorCount errors skipped";
            }
            
            // Add to activity log
            $currentUser = getCurrentUser();
            $username = $currentUser['full_name'] ?? $currentUser['username'] ?? 'Unknown';
            addActivityLog('bulk_csv_upload', "CSV upload: $successCount changes applied by $username$teamInfo", 'schedule');
            
            $message = $summary;
            if (!empty($errors) && count($errors) <= 5) {
                $message .= "<br><br>Errors:<br>• " . implode("<br>• ", $errors);
            } elseif (!empty($errors)) {
                $message .= "<br><br>First 5 errors:<br>• " . implode("<br>• ", array_slice($errors, 0, 5));
                $message .= "<br>... and " . (count($errors) - 5) . " more errors";
            }
            
            $messageType = 'success';
        } else {
            $message = "⛔ Failed to save changes. Backup was created.";
            $messageType = 'error';
        }
    } else {
        $message = "⛔ No valid changes found in CSV file. $errorCount errors.";
        if (!empty($errors) && count($errors) <= 10) {
            $message .= "<br><br>Errors:<br>• " . implode("<br>• ", $errors);
        }
        $messageType = 'error';
    }
    break;
            
        case 'create_template':
            if (!hasPermission('manage_employees')) {
                $message = "⛔ You don't have permission to manage templates.";
                $messageType = 'error';
                break;
            }
            
            $name = trim($_POST['templateName'] ?? '');
            $description = trim($_POST['templateDescription'] ?? '');
            
            if ($name && $description) {
                $schedule = [];
                for ($i = 0; $i < 7; $i++) {
                    $schedule[$i] = isset($_POST["templateDay$i"]) ? 1 : 0;
                }
                
                if (addScheduleTemplate($name, $description, $schedule)) {
                    $message = "✅ Schedule template '$name' created successfully!";
                    $messageType = 'success';
                } else {
                    $message = "⛔ Failed to create schedule template.";
                    $messageType = 'error';
                }
            } else {
                $message = "⛔ Please provide template name and description.";
                $messageType = 'error';
            }
            break;
            
        case 'delete_template':
            if (!hasPermission('manage_employees')) {
                $message = "⛔ You don't have permission to manage templates.";
                $messageType = 'error';
                break;
            }
            
            $templateId = intval($_POST['templateId'] ?? 0);
            if ($templateId && deleteScheduleTemplate($templateId)) {
                $message = "✅ Schedule template deleted successfully.";
                $messageType = 'success';
            } else {
                $message = "⛔ Failed to delete schedule template.";
                $messageType = 'error';
            }
            break;
            
    }
    
    // Redirect to prevent form resubmission
    $redirectTab = $_POST['current_tab'] ?? $_GET['tab'] ?? 'schedule';
    // Normalize tab name: convert underscores to hyphens
    $redirectTab = str_replace('_', '-', $redirectTab);
    
    $redirectParams = [
        'monthSelect' => $currentYear . '-' . $currentMonth,
        'shift' => $_GET['shift'] ?? 'all',
        'supervisor' => $_GET['supervisor'] ?? 'all',
        'level' => $_GET['level'] ?? 'all',
        'tab' => $redirectTab
    ];
    
    // Handle team as array
    $teamParam = $_GET['team'] ?? 'all';
    if (is_array($teamParam)) {
        foreach ($teamParam as $team) {
            $redirectParams['team'][] = $team;
        }
    } else {
        $redirectParams['team'] = $teamParam;
    }
    
    if ($message) {
        $redirectParams['msg'] = base64_encode($message);
        $redirectParams['type'] = $messageType;
    }
    
    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($redirectParams));
    exit;
}

// Load data for display
loadData();
// Pre-build O(1) indexed lookups — eliminates foreach scans in edit/add paths
$employeesById = array_column($employees, null, 'id');
$usersById     = array_column($users,     null, 'id');
loadScheduleTemplates();
// loadActivityLog() removed from startup — now lazy-loaded inside getRecentActivity()
// so the 500-row SELECT only runs when the activity log is actually requested.

// AJAX endpoint for fetching activity log
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_activity_log') {
    header('Content-Type: application/json');
    $recentActivity = getRecentActivity(20);
    echo json_encode(['success' => true, 'logs' => $recentActivity]);
    exit;
}

// Handle URL parameters
if (isset($_GET['monthSelect'])) {
    $monthValue = $_GET['monthSelect'];
    list($currentYear, $currentMonth) = explode('-', $monthValue);
    $currentYear = intval($currentYear);
    $currentMonth = intval($currentMonth);
    // Persist the selected month/year to the settings table
    try {
        $dbNav = Database::getInstance();
        $calMonthNav = $currentMonth + 1;
        $dbNav->execute(
            "INSERT INTO settings (setting_key, setting_value, description)
             VALUES ('current_year', ?, 'Currently displayed schedule year')
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [(int)$currentYear]
        );
        $dbNav->execute(
            "INSERT INTO settings (setting_key, setting_value, description)
             VALUES ('current_month', ?, 'Currently displayed schedule month (1=Jan)')
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [(int)$calMonthNav]
        );
    } catch (Exception $e) {
        error_log('monthSelect settings save error: ' . $e->getMessage());
    }
} elseif (isset($_GET['year']) && isset($_GET['month'])) {
    $currentYear = intval($_GET['year']);
    $currentMonth = intval($_GET['month']);
}

if (isset($_GET['msg'])) {
    $message = base64_decode($_GET['msg']);
    $messageType = $_GET['type'] ?? 'info';
}

// Determine active tab from URL parameter
$activeTab = $_GET['tab'] ?? 'schedule';
// Normalize tab name: convert underscores to hyphens (for backwards compatibility)
$activeTab = str_replace('_', '-', $activeTab);
// Validate tab name to prevent XSS
$validTabs = ['schedule', 'heatmap', 'settings', 'backups', 'bulk-schedule', 'add-employee', 'edit-employee', 'view-profile', 'profile', 'manual', 'api-docs', 'checklist'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'schedule';
}

// Filter employees based on permissions
// Handle team filter as array for multiple selection
$teamFilterRaw = $_GET['team'] ?? $_GET['preserveTeam'] ?? 'all';
$teamFilter = is_array($teamFilterRaw) ? $teamFilterRaw : [$teamFilterRaw];

// If no teams selected or empty array, default to 'all'
if (empty($teamFilter) || (count($teamFilter) === 1 && empty($teamFilter[0]))) {
    $teamFilter = ['all'];
}

$shiftFilter = $_GET['shift'] ?? $_GET['preserveShift'] ?? 'all';
$supervisorFilter = $_GET['supervisor'] ?? $_GET['preserveSupervisor'] ?? 'all';
$levelFilter = $_GET['level'] ?? $_GET['preserveLevel'] ?? 'all';
$skillsFilter = $_GET['skills'] ?? $_GET['preserveSkills'] ?? 'all';


// Cache current user ONCE before the loop — avoids N database calls for employee accounts
$_cachedCurrentUserForFilter = hasPermission('view_all_teams') ? null : getCurrentUser();

$filteredEmployees = array_filter($employees, function($emp) use ($teamFilter, $shiftFilter, $supervisorFilter, $levelFilter, $skillsFilter, $_cachedCurrentUserForFilter) {
    if ($_cachedCurrentUserForFilter !== null) {
        if ($_cachedCurrentUserForFilter['team'] !== 'all' && $emp['team'] !== $_cachedCurrentUserForFilter['team']) {
            return false;
        }
    }
    
    // Handle multiple team selection
    $teamMatch = in_array('all', $teamFilter) || in_array($emp['team'], $teamFilter);
    $shiftMatch = $shiftFilter === 'all' || $emp['shift'] == $shiftFilter;
    $levelMatch = $levelFilter === 'all' || $emp['level'] === $levelFilter || (empty($emp['level']) && $levelFilter === '');
    
    $supervisorMatch = true;
    if ($supervisorFilter !== 'all') {
        if ($supervisorFilter === 'none') {
            $supervisorMatch = empty($emp['supervisor_id']);
        } else {
            $supervisorMatch = $emp['supervisor_id'] == $supervisorFilter;
        }
    }
    
    // Skills filtering
    $skillsMatch = true;
    if ($skillsFilter !== 'all') {
        $empSkills = $emp['skills'] ?? [];
        $skillsMatch = !empty($empSkills[$skillsFilter]);
    }
    
    return $teamMatch && $shiftMatch && $supervisorMatch && $levelMatch && $skillsMatch;
});

// Remove duplicates - same employee ID should only appear once
// This prevents supervisors/managers from being counted twice
// Use hash key (isset) instead of in_array for O(1) vs O(n) lookup
$seenIds = [];
$filteredEmployees = array_filter($filteredEmployees, function($emp) use (&$seenIds) {
    if (isset($seenIds[$emp['id']])) {
        return false;
    }
    $seenIds[$emp['id']] = true;
    return true;
});

// Default name sorting
usort($filteredEmployees, function($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

// Move current user's employee to the top of the list
$currentEmployee = getCurrentUserEmployee();
if ($currentEmployee) {
    $currentEmployeeId = $currentEmployee['id'];
    $currentUserEntry = null;
    $otherEmployees = [];
    
    foreach ($filteredEmployees as $emp) {
        if ($emp['id'] == $currentEmployeeId) {
            $currentUserEntry = $emp;
        } else {
            $otherEmployees[] = $emp;
        }
    }
    
    // If current user's employee is in the filtered list, put them first
    if ($currentUserEntry) {
        $filteredEmployees = array_merge([$currentUserEntry], $otherEmployees);
    }
}

// Get backup info for header - DB-based
$latestBackup = findLatestBackup();
$backupCount = 0;
$latestSnapshotName = '';
if (file_exists(__DIR__ . '/Database.php')) {
    try {
        require_once __DIR__ . '/Database.php';
        $dbForBackups = Database::getInstance();
        $countRow = $dbForBackups->fetchOne("SELECT COUNT(*) as cnt FROM backup_snapshots");
        $backupCount = $countRow ? (int)$countRow['cnt'] : 0;
        $latestRow = $dbForBackups->fetchOne("SELECT snapshot_name FROM backup_snapshots ORDER BY created_at DESC LIMIT 1");
        $latestSnapshotName = $latestRow ? $latestRow['snapshot_name'] : '';
    } catch (Exception $e) {
        // fallback to file count
        if (is_dir(BACKUPS_DIR)) {
            $files = glob(BACKUPS_DIR . '/schedule_backup_*.json');
            $backupCount = count($files);
        }
    }
} else {
    if (is_dir(BACKUPS_DIR)) {
        $files = glob(BACKUPS_DIR . '/schedule_backup_*.json');
        $backupCount = count($files);
    }
}

// ── Auto-backup check (poor-man's cron) ─────────────────────────────────────
// Runs on every page load; skips silently if not due or already ran today.
$autoBackupMessage = '';
try {
    if (file_exists(__DIR__ . '/Database.php')) {
        require_once __DIR__ . '/Database.php';
        $dbAutoCheck = Database::getInstance();
        $pdoAuto     = $dbAutoCheck->getConnection();

        $schedRowAuto = $pdoAuto->prepare("SELECT setting_value FROM settings WHERE setting_key = 'backup_schedule' LIMIT 1");
        $schedRowAuto->execute();
        $schedJsonAuto = $schedRowAuto->fetchColumn();

        if ($schedJsonAuto) {
            $schedAuto = json_decode($schedJsonAuto, true);
            $freqAuto  = $schedAuto['frequency'] ?? 'disabled';

            if ($freqAuto !== 'disabled') {
                $hourAuto   = (int)($schedAuto['hour']   ?? 2);
                $minuteAuto = (int)($schedAuto['minute'] ?? 0);
                $keepAuto   = (int)($schedAuto['keep']   ?? 7);

                $tzAuto      = new DateTimeZone('America/New_York');
                $nowAuto     = new DateTime('now', $tzAuto);
                $nowHAuto    = (int)$nowAuto->format('H');
                $nowMAuto    = (int)$nowAuto->format('i');
                $todayAuto   = $nowAuto->format('Y-m-d');
                $dayOfWkAuto = (int)$nowAuto->format('N');
                $dayOfMoAuto = (int)$nowAuto->format('j');

                $nowTotalMinsAuto   = $nowHAuto * 60 + $nowMAuto;
                $schedTotalMinsAuto = $hourAuto * 60 + $minuteAuto;
                // Fire any time on or after the scheduled time (not just within 5 min)
                // The "already ran today" check below prevents duplicate runs.
                $withinWindowAuto   = ($nowTotalMinsAuto >= $schedTotalMinsAuto);

                $shouldRunAuto = false;
                if ($withinWindowAuto) {
                    if ($freqAuto === 'daily')   $shouldRunAuto = true;
                    if ($freqAuto === 'weekly'  && $dayOfWkAuto === 1) $shouldRunAuto = true;
                    if ($freqAuto === 'monthly' && $dayOfMoAuto === 1) $shouldRunAuto = true;
                }

                if ($shouldRunAuto) {
                    $lastAutoRowStmt = $pdoAuto->prepare(
                        "SELECT created_at FROM backup_snapshots
                         WHERE backup_type = 'auto' AND DATE(created_at) = ?
                         ORDER BY created_at DESC LIMIT 1"
                    );
                    $lastAutoRowStmt->execute([$todayAuto]);
                    $alreadyRanToday = $lastAutoRowStmt->fetchColumn();

                    if (!$alreadyRanToday) {
                        $autoLabel  = '🤖 Scheduled Backup - ' . $nowAuto->format('Y-m-d H:i:s') . ' ET';
                        $autoTables = ['employees','schedule_overrides','motd_messages','schedule_templates','activity_log','users','settings'];
                        $autoData   = [];
                        $autoCounts = [];
                        foreach ($autoTables as $tbl) {
                            try {
                                $rows = $dbAutoCheck->fetchAll("SELECT * FROM `{$tbl}`");
                                $autoData[$tbl]   = $rows;
                                $autoCounts[$tbl] = count($rows);
                            } catch (Exception $e) {
                                $autoData[$tbl]   = [];
                                $autoCounts[$tbl] = 0;
                            }
                        }

                        $insAuto = $pdoAuto->prepare(
                            "INSERT INTO backup_snapshots (snapshot_name, description, backup_type, created_by, created_at, record_counts, is_legacy_import)
                             VALUES (?, ?, 'auto', 0, NOW(), ?, 0)"
                        );
                        $insAuto->execute([$autoLabel, "Automatic $freqAuto backup", json_encode($autoCounts)]);
                        $autoSnapId = (int)$pdoAuto->lastInsertId();

                        if ($autoSnapId > 0) {
                            foreach ($autoTables as $tbl) {
                                $pdoAuto->prepare(
                                    "INSERT INTO backup_data (snapshot_id, table_name, data_json, record_count) VALUES (?, ?, ?, ?)"
                                )->execute([$autoSnapId, $tbl, json_encode($autoData[$tbl]), count($autoData[$tbl])]);
                            }
                            if (function_exists('generateSqlDump')) {
                                $dumpAuto = generateSqlDump($dbAutoCheck, $autoTables);
                                $pdoAuto->prepare(
                                    "INSERT INTO backup_data (snapshot_id, table_name, data_json, record_count) VALUES (?, 'sql_dump', ?, 1)"
                                )->execute([$autoSnapId, json_encode($dumpAuto)]);
                            }
                            // Prune old auto backups beyond retention limit
                            if ($keepAuto > 0) {
                                $oldIdsStmt = $pdoAuto->prepare(
                                    "SELECT id FROM backup_snapshots WHERE backup_type = 'auto'
                                     ORDER BY created_at DESC LIMIT 1000 OFFSET ?"
                                );
                                $oldIdsStmt->execute([$keepAuto]);
                                foreach ($oldIdsStmt->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
                                    $pdoAuto->prepare("DELETE FROM backup_data      WHERE snapshot_id = ?")->execute([$oldId]);
                                    $pdoAuto->prepare("DELETE FROM backup_snapshots WHERE id = ?")->execute([$oldId]);
                                }
                            }
                            addActivityLog('backup_create', "Auto $freqAuto backup created: $autoLabel (ID $autoSnapId)", 'backup');
                            $autoBackupMessage = "✅ Scheduled backup ran automatically at " . $nowAuto->format('g:i A') . " ET.";
                        }
                    }
                }
            }
        }
    }
} catch (Exception $eAuto) {
    error_log('Auto-backup error: ' . $eAuto->getMessage());
}
// ─────────────────────────────────────────────────────────────────────────────

// Load current backup schedule settings for the UI
$backupSchedule = ['frequency' => 'disabled', 'hour' => 2, 'minute' => 0, 'keep' => 7];
$nextBackupLabel = 'Not scheduled';
try {
    if (file_exists(__DIR__ . '/Database.php')) {
        require_once __DIR__ . '/Database.php';
        $dbSchedLoad  = Database::getInstance();
        $schedLoadRow = $dbSchedLoad->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'backup_schedule' LIMIT 1");
        if ($schedLoadRow && !empty($schedLoadRow['setting_value'])) {
            $parsed = json_decode($schedLoadRow['setting_value'], true);
            if (is_array($parsed)) $backupSchedule = array_merge($backupSchedule, $parsed);
        }
        if ($backupSchedule['frequency'] !== 'disabled') {
            $tzDisp  = new DateTimeZone('America/New_York');
            $nowDisp = new DateTime('now', $tzDisp);
            $cand    = clone $nowDisp;
            $cand->setTime((int)$backupSchedule['hour'], (int)$backupSchedule['minute'], 0);
            if ($cand <= $nowDisp) {
                if ($backupSchedule['frequency'] === 'daily')   $cand->modify('+1 day');
                if ($backupSchedule['frequency'] === 'weekly')  $cand->modify('next monday');
                if ($backupSchedule['frequency'] === 'monthly') {
                    $cand->modify('first day of next month');
                    $cand->setTime((int)$backupSchedule['hour'], (int)$backupSchedule['minute'], 0);
                }
            }
            $nextBackupLabel = $cand->format('D, M j, Y \a\t g:i A') . ' ET';
        }
    }
} catch (Exception $eSchedLoad) { /* use defaults */ }

// Helper functions


// Generate skills badges HTML
function generateSkillsBadges($skills) {
    if (!$skills || !is_array($skills)) {
        return '';
    }
    
    $badges = '';
    // Solid colours with white text — looks correct in both light and dark themes
    $skillLabels = [
        'mh'  => ['label' => 'MH',  'bg' => '#1976d2'],
        'ma'  => ['label' => 'MA',  'bg' => '#7b1fa2'],
        'win' => ['label' => 'Win', 'bg' => '#2e7d32'],
    ];

    foreach ($skillLabels as $skill => $config) {
        if (!empty($skills[$skill])) {
            $badges .= sprintf(
                '<span class="skill-badge" style="background: %s !important; color: #fff !important; padding: 2px 6px; border-radius: 8px; font-size: 0.75em; font-weight: bold; margin: 1px 2px; display: inline-block;">%s</span>',
                $config['bg'],
                $config['label']
            );
        }
    }
    
    return $badges;
}

function getDaysInMonth($year, $month) {
    return date('t', mktime(0, 0, 0, $month + 1, 1, $year));
}

function getMonthName($month) {
    $months = ['January', 'February', 'March', 'April', 'May', 'June',
              'July', 'August', 'September', 'October', 'November', 'December'];
    return $months[$month];
}

function getDayName($dayNum) {
    $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    return $days[$dayNum];
}

function getStatusText($status) {
    $statusMap = [
        'on' => 'ON',
        'off' => 'OFF',
        'pto' => 'PTO',
        'sick' => 'SICK',
        'holiday' => 'HOL',
        'custom_hours' => 'CUSTOM',
        'schedule' => 'DEFAULT'
    ];
    return $statusMap[$status] ?? strtoupper($status);
}

function getShiftName($shift) {
    $shiftNames = [
        1 => '1st Shift',
        2 => '2nd Shift',
        3 => '3rd Shift',
    ];
    return $shiftNames[$shift] ?? $shift . 'th Shift';
}

function getLevelName($level) {
    global $levels;
    if (empty($level)) {
        return '';
    }
    return $levels[$level] ?? strtoupper($level);
}

$daysInMonth = getDaysInMonth($currentYear, $currentMonth);

// Get today's date for highlighting (will be updated by JavaScript based on user's timezone)
$todayYear = date('Y');
$todayMonth = date('n') - 1;
$todayDay = date('j');
$isCurrentMonth = ($todayYear == $currentYear && $todayMonth == $currentMonth);

$currentUser = getCurrentUser();

// ========================================
// UNIFIED PROFILE SYSTEM FUNCTIONS
// Must be defined before array_map usage
// ========================================

/**
 * Get employee profile with auth user data combined
 * This creates a unified view of employee + auth user data
 */
function getEmployeeProfile($employeeId) {
    global $employees, $users;
    
    // Find employee
    $employee = null;
    foreach ($employees as $emp) {
        if ($emp['id'] == $employeeId) {
            $employee = $emp;
            break;
        }
    }
    
    if (!$employee) {
        return null;
    }
    
    // Find associated auth user
    $authUser = null;
    if (isset($employee['user_id'])) {
        foreach ($users as $user) {
            if ($user['id'] == $employee['user_id']) {
                $authUser = $user;
                break;
            }
        }
    }
    
    // Combine data - auth user is the master for personal info
    $profile = [
        'employee_id' => $employee['id'],
        'user_id' => $employee['user_id'] ?? null,
        
        // Personal info from auth user (master source)
        'name' => $authUser['full_name'] ?? 'Unknown',
        'email' => $authUser['email'] ?? '',
        'team' => $authUser['team'] ?? 'all',
        'role' => $authUser['role'] ?? 'employee',
        'profile_photo' => $authUser['profile_photo'] ?? null,
        'google_picture' => $authUser['google_picture'] ?? null,
        'profile_photo_url' => $authUser['profile_photo_url'] ?? null,
        'auth_method' => $authUser['auth_method'] ?? 'google',

        // Schedule info from employee record
        'shift' => $employee['shift'] ?? 1,
        'hours' => $employee['hours'] ?? '8:00 AM - 5:00 PM',
        'schedule' => $employee['schedule'] ?? [0,1,1,1,1,1,0],
        'skills' => $employee['skills'] ?? [],
        'supervisor_id' => $employee['supervisor_id'] ?? null,
        'level' => $employee['level'] ?? ''
    ];
    
    return $profile;
}

/**
 * Get unified profile for current logged-in user
 */
function getCurrentUserProfile() {
    global $currentUser;
    
    if (!$currentUser) {
        return null;
    }
    
    // Find employee linked to this user
    $employees = getEmployees();
    $employeeId = null;
    
    foreach ($employees as $emp) {
        if (isset($emp['user_id']) && $emp['user_id'] == $currentUser['id']) {
            $employeeId = $emp['id'];
            break;
        }
    }
    
    if (!$employeeId) {
        // Return just auth user data if no employee record
        return [
            'user_id' => $currentUser['id'],
            'name' => $currentUser['full_name'],
            'email' => $currentUser['email'],
            'team' => $currentUser['team'],
            'role' => $currentUser['role'],
            'profile_photo' => $currentUser['profile_photo'] ?? null,
            'google_picture' => $currentUser['google_picture'] ?? null,
            'profile_photo_url' => $currentUser['profile_photo_url'] ?? null,
            'auth_method' => $currentUser['auth_method'] ?? 'google',
            'employee_id' => null,
            'shift' => null,
            'hours' => null,
            'schedule' => null,
            'skills' => [],
            'supervisor_id' => null,
            'level' => ''
        ];
    }
    
    return getEmployeeProfile($employeeId);
}

/**
 * Update unified profile - updates both auth user and employee records
 */
function updateUnifiedProfile($userId, $updates) {
    global $users, $employees;
    
    $result = ['success' => false, 'message' => ''];
    
    // Find user
    $userIndex = null;
    foreach ($users as $index => $user) {
        if ($user['id'] == $userId) {
            $userIndex = $index;
            break;
        }
    }
    
    if ($userIndex === null) {
        $result['message'] = 'User not found';
        return $result;
    }
    
    // Find employee
    $employeeIndex = null;
    foreach ($employees as $index => $emp) {
        if (isset($emp['user_id']) && $emp['user_id'] == $userId) {
            $employeeIndex = $index;
            break;
        }
    }
    
    // Update auth user (master for personal info)
    if (isset($updates['name'])) {
        $users[$userIndex]['full_name'] = $updates['name'];
    }
    if (isset($updates['email'])) {
        $users[$userIndex]['email'] = $updates['email'];
    }
    if (isset($updates['team'])) {
        $users[$userIndex]['team'] = $updates['team'];
    }
    if (isset($updates['profile_photo'])) {
        $users[$userIndex]['profile_photo'] = $updates['profile_photo'];
    }
    if (isset($updates['password']) && !empty($updates['password'])) {
        $users[$userIndex]['password'] = password_hash($updates['password'], PASSWORD_DEFAULT);
    }
    
    // Save auth user changes directly to DB
    try {
        $db  = Database::getInstance();
        $pdo = $db->getConnection();

        $setClauses = ["updated_at = NOW()"];
        $params     = [];

        if (isset($updates['name'])) {
            $setClauses[] = "username = ?";
            $params[]     = $updates['name'];
        }
        if (isset($updates['email'])) {
            $setClauses[] = "email = ?";
            $params[]     = $updates['email'];
        }
        if (isset($updates['team'])) {
            $setClauses[] = "team = ?";
            $params[]     = $updates['team'];
        }
        if (isset($updates['password']) && !empty($updates['password'])) {
            $setClauses[] = "password_hash = ?";
            $params[]     = password_hash($updates['password'], PASSWORD_DEFAULT);
        }

        $params[] = $userId;
        if (count($params) > 1) { // at least one real field besides updated_at
            $pdo->prepare("UPDATE users SET " . implode(', ', $setClauses) . " WHERE id = ?")
                ->execute($params);
        }
    } catch (Exception $e) {
        error_log("updateUnifiedProfile DB error: " . $e->getMessage());
        $result['message'] = 'Failed to save user data';
        return $result;
    }
    
    // Update employee record if exists (schedule-specific data only)
    if ($employeeIndex !== null) {
        // Note: We don't update name/email/team here anymore - auth user is the master
        // Only update schedule-specific fields if provided
        if (isset($updates['shift'])) {
            $employees[$employeeIndex]['shift'] = $updates['shift'];
        }
        if (isset($updates['hours'])) {
            $employees[$employeeIndex]['hours'] = $updates['hours'];
        }
        if (isset($updates['schedule'])) {
            $employees[$employeeIndex]['schedule'] = $updates['schedule'];
        }
        if (isset($updates['supervisor_id'])) {
            $employees[$employeeIndex]['supervisor_id'] = $updates['supervisor_id'];
        }
        
        // Save employee changes
        if (!saveScheduleData($employees)) {
            $result['message'] = 'Failed to save employee data';
            return $result;
        }
    }
    
    $result['success'] = true;
    $result['message'] = 'Profile updated successfully';
    return $result;
}

// Prepare users data for JavaScript
$usersForJS = array_map(function($user) {
    // Skip if not a valid array
    if (!is_array($user)) {
        return null;
    }
    return [
        'id'          => $user['id']          ?? null,
        'username'    => $user['username']    ?? null,
        'email'       => $user['email']       ?? '',
        'full_name'   => $user['full_name']   ?? 'Unknown',
        'role'        => $user['role']        ?? 'employee',
        'team'        => $user['team']        ?? 'all',
        'active'      => $user['active']      ?? true,
        'created_at'  => $user['created_at']  ?? date('c'),
        'updated_at'  => $user['updated_at']  ?? null,
        'slack_id'    => $user['slack_id']    ?? null,
        // Extra fields needed for client-side gear-icon pre-fill
        'auth_method' => $user['auth_method'] ?? 'both',
        'employee_id' => $user['employee_id'] ?? null,
        // Resolved photo URL (handles local files, Google URLs, etc.) so the
        // JS view-profile panel can display the photo without a server round-trip.
        'photo_url'   => getProfilePhotoUrl($user),
    ];
}, $users);
// Remove null entries
$usersForJS = array_filter($usersForJS);

// Prepare employees data for JavaScript
// NOTE: All needed fields are already on $employee from the DB query.
// Removed the per-employee getEmployeeProfile() call that caused O(N²) loops.
$employeesForJS = array_map(function($employee) {
    if (!is_array($employee)) {
        return null;
    }
    return [
        'id'              => $employee['id']              ?? null,
        'name'            => $employee['name']            ?? 'Unknown',
        'team'            => $employee['team']            ?? 'esg',
        'email'           => $employee['email']           ?? '',
        'shift'           => $employee['shift']           ?? 1,
        'hours'           => $employee['hours']           ?? '8:00 AM - 5:00 PM',
        'level'           => $employee['level']           ?? '',
        'supervisor_id'   => $employee['supervisor_id']   ?? null,
        'schedule'        => $employee['schedule']        ?? [0,1,1,1,1,1,0],
        'skills'          => $employee['skills']          ?? ['mh' => false, 'ma' => false, 'win' => false],
        'slack_id'        => $employee['slack_id']        ?? null,
        // Extra fields needed for client-side gear-icon pre-fill (avoids page reload)
        'user_id'         => $employee['user_id']         ?? null,
        'start_date'      => $employee['start_date']      ?? '',
        'schedule_access' => $employee['schedule_access'] ?? '',
    ];
}, $employees);
// Remove null entries
$employeesForJS = array_filter($employeesForJS);

// Handle GET action for AJAX requests
if (isset($_GET['action']) && $_GET['action'] === 'get_backup_employees') {
    header('Content-Type: application/json');
    
    if (!hasPermission('manage_backups')) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    
    $snapId = (int)($_GET['snapshot_id'] ?? 0);
    
    if ($snapId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid snapshot ID']);
        exit;
    }
    
    try {
        require_once __DIR__ . '/Database.php';
        $dbGbe = Database::getInstance();
        $row   = $dbGbe->fetchOne(
            "SELECT data_json FROM backup_data WHERE snapshot_id = ? AND table_name = 'employees'",
            [$snapId]
        );
        
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'No employee data found for this snapshot']);
            exit;
        }
        
        $empList = json_decode($row['data_json'], true);
        if (!is_array($empList)) {
            echo json_encode(['success' => false, 'error' => 'Could not parse employee data']);
            exit;
        }
        
        echo json_encode([
            'success'       => true,
            'employees'     => $empList,
            'employeeCount' => count($empList)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle unified profile API endpoints
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'get_unified_profile') {
        $profile = getCurrentUserProfile();
        if ($profile) {
            echo json_encode(['success' => true, 'profile' => $profile]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Profile not found']);
        }
        exit;
    }
}

if (isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'save_unified_profile') {
        if (!$currentUser) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            exit;
        }
        
        $updates = [];
        
        // Personal info
        if (isset($_POST['name'])) {
            $updates['name'] = trim($_POST['name']);
        }
        if (isset($_POST['email'])) {
            $email = trim($_POST['email']);
            // Validate email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'error' => 'Invalid email address']);
                exit;
            }
            $updates['email'] = $email;
        }
        if (isset($_POST['team'])) {
            $updates['team'] = $_POST['team'];
        }
        
        // Password change (only if provided and user has password auth)
        if (isset($_POST['new_password']) && !empty($_POST['new_password'])) {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'];
            
            // Verify current password
            if (!password_verify($currentPassword, $currentUser['password'])) {
                echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
                exit;
            }
            
            // Validate new password
            if (strlen($newPassword) < 6) {
                echo json_encode(['success' => false, 'error' => 'New password must be at least 6 characters']);
                exit;
            }
            
            $updates['password'] = $newPassword;
        }
        
        // Handle profile photo upload
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/profiles/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileExtension = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (!in_array($fileExtension, $allowedExtensions)) {
                echo json_encode(['success' => false, 'error' => 'Invalid file type']);
                exit;
            }
            
            if ($_FILES['profile_photo']['size'] > 5242880) { // 5MB
                echo json_encode(['success' => false, 'error' => 'File too large (max 5MB)']);
                exit;
            }
            
            $newFileName = 'profile_' . $currentUser['id'] . '_' . time() . '.' . $fileExtension;
            $uploadPath = $uploadDir . $newFileName;
            
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadPath)) {
                // Delete old photo if exists
                if (!empty($currentUser['profile_photo']) && file_exists($currentUser['profile_photo'])) {
                    unlink($currentUser['profile_photo']);
                }
                $updates['profile_photo'] = $uploadPath;
            }
        }
        
        $result = updateUnifiedProfile($currentUser['id'], $updates);
        
        if ($result['success']) {
            // Reload current user
            foreach ($users as $user) {
                if ($user['id'] == $currentUser['id']) {
                    $_SESSION['user'] = $user;
                    $currentUser = $user;
                    break;
                }
            }
        }
        
        echo json_encode($result);
        exit;
    }
    
    if ($action === 'delete_profile_photo') {
        if (!$currentUser) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            exit;
        }
        
        if (!empty($currentUser['profile_photo']) && file_exists($currentUser['profile_photo'])) {
            unlink($currentUser['profile_photo']);
        }
        
        $result = updateUnifiedProfile($currentUser['id'], ['profile_photo' => null]);
        
        if ($result['success']) {
            // Reload current user
            foreach ($users as $user) {
                if ($user['id'] == $currentUser['id']) {
                    $_SESSION['user'] = $user;
                    $currentUser = $user;
                    break;
                }
            }
        }
        
        echo json_encode($result);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CS Unified Schedule</title>
    
    <!-- Immediate Theme Loader - Prevents Flash of Default Theme -->
    <script>
    (function() {
        try {
            const savedTheme = localStorage.getItem('scheduleSystemTheme') || 'default';

            const themeCSS = generateInlineThemeCSS(savedTheme);
            if (themeCSS) {
                const style = document.createElement('style');
                style.id = 'preload-theme';
                style.textContent = themeCSS;
                document.head.appendChild(style);
            }
        } catch (e) {
        }
        
        function generateInlineThemeCSS(themeName) {
            const themes = {
                default: { primary: '#333399', tableHead: '#1e40af', secondary: '#1e2a6e', accent: '#0891b2', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#cbd5e1' },
                ocean: { primary: '#0066ff', secondary: '#003366', accent: '#0ea5e9', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#003366', textMuted: '#000000', border: '#cbd5e1' },
                forest: { primary: '#065f46', secondary: '#059669', accent: '#10b981', success: '#059669', warning: '#d97706', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#1e293b', border: '#a7f3d0' },
                sunset: { primary: '#c2410c', secondary: '#f97316', accent: '#fb923c', success: '#059669', warning: '#996600', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#fed7aa' },
                royal: { primary: '#6600ff', secondary: '#4c0099', accent: '#c084fc', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#e9d5ff' },
                dark: { primary: '#58a6ff', secondary: '#21262d', accent: '#79c0ff', success: '#3fb950', warning: '#e3b341', danger: '#f85149', surface: '#0d1117', card: '#161b22', text: '#e6edf3', textMuted: '#8b949e', border: '#30363d' },
                crimson: { primary: '#991b1b', secondary: '#dc2626', accent: '#ef4444', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#ffffff' },
                teal: { primary: '#134e4a', secondary: '#0f766e', accent: '#14b8a6', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#ffffff' },
                amber: { primary: '#92400e', secondary: '#d97706', accent: '#f59e0b', success: '#059669', warning: '#d97706', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#ffffff' },
                slate: { primary: '#1e293b', secondary: '#475569', accent: '#64748b', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#ffffff' },
                emerald: { primary: '#065f46', secondary: '#059669', accent: '#10b981', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#ffffff' },
                midnight: { primary: '#312e81', secondary: '#4338ca', accent: '#6366f1', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#ffffff' },
                rose: { primary: '#9f1239', secondary: '#e11d48', accent: '#f43f5e', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#ffffff' },
                copper: { primary: '#9a3412', secondary: '#ea580c', accent: '#fb923c', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#ffffff' }
            };
            
            const colors = themes[themeName];
            if (!colors) return '';
            
            const isDk = themeName === 'dark';
            const cBg  = colors.surface;
            const tHead = colors.tableHead || colors.primary;
            return `
                :root {
                    --primary-color:   ${colors.primary};
                    --secondary-color: ${colors.secondary};
                    --accent-color:    ${colors.accent};
                    --success-color:   ${colors.success};
                    --warning-color:   ${colors.warning};
                    --danger-color:    ${colors.danger};
                    --body-bg:         ${cBg};
                    --card-bg:         ${colors.card};
                    --text-color:      ${colors.text};
                    --text-muted:      ${colors.textMuted};
                    --border-color:    ${colors.border};
                    --secondary-bg:    ${isDk ? colors.secondary : '#f8f9fa'};
                    --surface-color:   ${colors.surface};
                    --search-bg:       ${colors.card};
                    --input-bg:        ${colors.card};
                }
                body { background: ${colors.surface} !important; color: ${colors.text} !important; }
                .app-main { background: ${cBg} !important; color: ${colors.text} !important; }
                .tab-content { background: ${cBg} !important; color: ${colors.text} !important; }
                .tab-content [style*="background: white"], .tab-content [style*="background:white"],
                .tab-content [style*="background: #fff"], .tab-content [style*="background:#fff"] {
                    background: ${colors.card} !important; color: ${colors.text} !important;
                }
                .search-controls, .team-filter-section {
                    background: ${colors.card} !important; color: ${colors.text} !important;
                }
                .search-controls *, .team-filter-section * { color: ${colors.text} !important; }
                label { color: ${colors.text} !important; }
                .header { background: ${colors.primary} !important; color: white !important; }
                .header, .header *, .header *:before, .header *:after { color: white !important; text-shadow: 0 1px 2px rgba(0,0,0,0.5) !important; }
                .container { background: ${colors.card} !important; color: ${colors.text} !important; }
                .nav-tabs { background: ${themeName === 'dark' ? colors.secondary : '#ecf0f1'} !important; border-bottom-color: ${colors.border} !important; }
                .nav-tab { color: ${colors.text} !important; background: ${themeName === 'dark' ? colors.secondary : 'transparent'} !important; }
                .nav-tab.active { background: ${colors.card} !important; border-bottom-color: ${colors.accent} !important; color: ${colors.accent} !important; }
                .controls { background: ${themeName === 'dark' ? colors.secondary : '#ecf0f1'} !important; color: ${colors.text} !important; }
                .controls label, .controls span, .controls select, .controls input { color: ${colors.text} !important; }
                select, input[type="text"], input[type="email"], input[type="password"] { background: ${colors.card} !important; color: ${colors.text} !important; border-color: ${colors.border} !important; }
                button:not(.sr-btn):not([class*="sr-btn"]):not(.action-btn):not(.clear-search-btn):not(.status-btn):not(.tooltip-close-btn):not(.theme-swatch-btn):not(.api-copy-btn), .btn:not(.sr-btn) { background: ${colors.primary} !important; color: white !important; border-color: ${colors.primary} !important; }
                button:not(.sr-btn):not(.action-btn):not(.clear-search-btn):not(.status-btn):not(.tooltip-close-btn):not(.theme-swatch-btn):not(.api-copy-btn):hover, .btn:not(.sr-btn):hover { color: white !important; }
                .sr-btn-primary { background: ${colors.primary} !important; color: #fff !important; }
                .sr-btn-ghost { background: transparent !important; color: ${colors.textMuted} !important; border: 1.5px solid ${colors.border} !important; }
                .sr-btn-outline { background: transparent !important; color: ${colors.primary} !important; border: 1.5px solid ${colors.primary} !important; }
                .sr-btn-danger { background: #dc3545 !important; color: #fff !important; }
                /* Schedule accent bar — themed left/bottom border + top strip above table */
                #schedule-tab { border-left-color: ${colors.primary} !important; border-bottom-color: ${colors.primary} !important; }
                .schedule-wrapper { border-top-color: ${colors.primary} !important; }
                .schedule-table th { background: ${tHead} !important; color: white !important; border-color: ${colors.border} !important; }
                .schedule-table td { border-color: ${colors.border} !important; color: ${colors.text} !important; }
                .schedule-table td.today-cell { background: linear-gradient(135deg, #fff3e0 0%, #ffe0b3 100%) !important; border: 2px solid #ff6b35 !important; color: #1a1a1a !important; }
                .schedule-table td.today-cell .status-text { color: #1a1a1a !important; }
                .schedule-table td.today-cell .status-on,.schedule-table td.today-cell .status-pto,.schedule-table td.today-cell .status-sick,.schedule-table td.today-cell .status-holiday,.schedule-table td.today-cell .status-custom_hours,.schedule-table td.today-cell .status-schedule { color: white !important; }
                .schedule-table th:first-child { background: ${tHead} !important; }
                .employee-name { background: ${themeName === 'dark' ? colors.secondary : '#95a5a6'} !important; color: ${themeName === 'dark' ? colors.text : 'white'} !important; }
                /* Override for employee names in PTO/Sick/Holiday cards - no background */
                .employee-card .employee-name { background: none !important; color: ${colors.text} !important; }
                .pto-section .employee-card .employee-name { background: none !important; color: ${colors.text} !important; }
                .sick-section .employee-card .employee-name { background: none !important; color: ${colors.text} !important; }
                .holiday-section .employee-card .employee-name { background: none !important; color: ${colors.text} !important; }
                .status-on { background: ${colors.primary} !important; color: white !important; }
                .status-off { background: ${colors.secondary} !important; }
                .status-pto { background: ${colors.warning} !important; }
                .status-sick { background: ${colors.danger} !important; }
                .status-holiday { background: #22c55e !important; }
                .status-custom_hours { background: linear-gradient(135deg, ${colors.primary}, ${colors.primary}99) !important; color: white !important; border: 2px solid ${colors.accent} !important; font-weight: bold !important; }
                .status-schedule { background: ${colors.primary}BB !important; color: white !important; border: 1px solid ${colors.accent} !important; }
                .motd-banner { background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%) !important; }
                .motd-management-section { background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%) !important; }
                .motd-scrolling-content { color: white !important; font-weight: bold !important; font-size: 18px !important; }
                .motd-scrolling-content * { color: white !important; font-weight: bold !important; }
                /* Force all MOTD management text to white */
                .motd-management-section,
                .motd-management-section *:not(input):not(textarea):not(.motd-add-button) { color: white !important; }
                .motd-section-title,
                .motd-section-description,
                .motd-form-title,
                .motd-form-label,
                .motd-form-hint,
                .motd-empty-text,
                .motd-empty-subtext,
                .motd-message-text,
                .motd-date-info,
                .motd-anniversary-badge,
                .motd-cancel-button,
                .motd-edit-button { color: white !important; }
                /* Email Export Tools */
                .email-export-section { background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%) !important; }
                .email-export-section,
                .email-export-section * { color: white !important; }
                .email-export-section *:not(input):not(textarea) { color: white !important; }
                .email-section-title,
                .email-section-description,
                .email-status-text,
                .email-button-secondary { color: white !important; }
                /* Rippling API Integration */
                .rippling-api-section { background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%) !important; }
                .rippling-api-section,
                .rippling-api-section * { color: white !important; }
                .rippling-api-section *:not(input):not(textarea) { color: white !important; }
                /* View Profile Header */
                .view-profile-header { background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%) !important; color: white !important; }
                .view-profile-header,
                .view-profile-header * { color: white !important; }
                /* Bulk Schedule employee list */
                .sr { background: ${cBg} !important; color: ${colors.text} !important; }
                .sr-card { background: ${colors.card} !important; color: ${colors.text} !important; }
                .sr-card-title, .sr-card-sub, .sr-page-title h2, .sr-lbl, .sr-emp-name { color: ${colors.text} !important; }
                .sr-emp-meta, .sr-card-sub, .sr-page-subtitle { color: ${colors.textMuted} !important; }
                .sr-emp-top { background: ${isDk ? colors.secondary : 'rgba(0,0,0,0.04)'} !important; border-bottom-color: ${colors.border} !important; }
                .sr-emp-top label, .sr-emp-top span { color: ${colors.text} !important; }
                .sr-emp-list { background: ${isDk ? colors.secondary : '#f4f6f8'} !important; }
                .sr-emp-item { border-bottom-color: ${colors.border} !important; }
                .sr-emp-search { background: ${colors.card} !important; }
                .sr-ctrl { background: ${colors.card} !important; color: ${colors.text} !important; border-color: ${colors.border} !important; }
                .sr-toggle-btn { background: ${isDk ? colors.secondary : '#f1f5f9'} !important; color: ${colors.text} !important; border-color: ${colors.border} !important; }
                .sr-check-block { background: ${colors.card} !important; color: ${colors.text} !important; }
                .sr-check-block strong, .sr-check-block p, .sr-check-block li { color: ${colors.text} !important; }
                .sr-btn-primary { background: ${colors.primary} !important; color: #fff !important; border-color: ${colors.primary} !important; }
                .sr-btn-ghost { background: transparent !important; color: ${colors.textMuted} !important; border: 1.5px solid ${colors.border} !important; }
                .sr-btn-outline { background: transparent !important; color: ${colors.primary} !important; border: 1.5px solid ${colors.primary} !important; }
                .sr-btn-danger { background: #dc3545 !important; color: #fff !important; }
                .users-table th { background: ${colors.primary} !important; color: white !important; }
                .users-table td { color: ${colors.text} !important; background: ${colors.card} !important; border-bottom-color: ${colors.border} !important; }
                .role-badge { background: ${colors.primary}22 !important; color: ${colors.primary} !important; }
                .role-badge.role-admin     { background: ${colors.danger}22 !important; color: ${colors.danger} !important; }
                .role-badge.role-supervisor{ background: ${colors.warning}22 !important; color: ${colors.warning} !important; }
                .role-badge.role-manager   { background: ${colors.accent}22 !important; color: ${colors.accent} !important; }
                .role-badge.role-employee  { background: ${colors.secondary}22 !important; color: ${colors.secondary} !important; }
                .level-badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600; background: ${colors.accent}22 !important; color: ${colors.accent} !important; border: 1px solid ${colors.accent}44 !important; }
                .level-badge.level-ssa  { background: #dc262622 !important; color: #dc2626 !important; border-color: #dc262644 !important; }
                .level-badge.level-ssa2 { background: #9b59b622 !important; color: #9b59b6 !important; border-color: #9b59b644 !important; }
                .level-badge.level-l1   { background: #06b6d422 !important; color: #06b6d4 !important; border-color: #06b6d444 !important; }
                .level-badge.level-l2   { background: #f59e0b22 !important; color: #b45309 !important; border-color: #f59e0b44 !important; }
                .level-badge.level-l3   { background: #0891b222 !important; color: #0891b2 !important; border-color: #0891b244 !important; }
                .level-badge.level-tam  { background: #f9731622 !important; color: #f97316 !important; border-color: #f9731644 !important; }
                .level-badge.level-tam2 { background: #ea580c22 !important; color: #ea580c !important; border-color: #ea580c44 !important; }
                .level-badge.level-manager         { background: #7c3aed22 !important; color: #7c3aed !important; border-color: #7c3aed44 !important; }
                .level-badge.level-Supervisor      { background: #0369a122 !important; color: #0369a1 !important; border-color: #0369a144 !important; }
                .level-badge.level-SR--Supervisor  { background: #0f766e22 !important; color: #0f766e !important; border-color: #0f766e44 !important; }
                .level-badge.level-SR--Manager     { background: #c2410c22 !important; color: #c2410c !important; border-color: #c2410c44 !important; }
                .level-badge.level-IMP-Tech        { background: #1d4ed822 !important; color: #1d4ed8 !important; border-color: #1d4ed844 !important; }
                .level-badge.level-IMP-Coordinator { background: #6d28d922 !important; color: #6d28d9 !important; border-color: #6d28d944 !important; }
                .level-badge.level-SecOps-T1       { background: #1a56db22 !important; color: #1a56db !important; border-color: #1a56db44 !important; }
                .level-badge.level-SecOps-T2       { background: #7e3af222 !important; color: #7e3af2 !important; border-color: #7e3af244 !important; }
                .level-badge.level-SecOps-T3       { background: #e7469422 !important; color: #e74694 !important; border-color: #e7469444 !important; }
                .level-badge.level-SecEng          { background: #d61f6922 !important; color: #d61f69 !important; border-color: #d61f6944 !important; }
                .level-badge.level-technical_writer{ background: #0d948822 !important; color: #0d9488 !important; border-color: #0d948844 !important; }
                .level-badge.level-trainer         { background: #16a34a22 !important; color: #16a34a !important; border-color: #16a34a44 !important; }
                .level-badge.level-tech_coach      { background: #0284c722 !important; color: #0284c7 !important; border-color: #0284c744 !important; }
                .status-badge.status-active { background: ${colors.success}22 !important; color: ${colors.success} !important; }
                .status-badge.status-inactive { background: ${colors.danger}22 !important; color: ${colors.danger} !important; }
                .user-photo-placeholder { background: ${colors.primary} !important; color: white !important; }
                .action-btn { background: transparent !important; border: none !important; color: ${colors.text} !important; }

                ${isDk ? `
                /* ═══════════════════════════════════════════════════════════
                   DARK MODE — override every hardcoded white/light background
                   ═══════════════════════════════════════════════════════════ */

                /* ── Stats bar ── */
                .stats { background: ${colors.secondary} !important; }
                .stat-item { background: ${colors.card} !important; color: ${colors.text} !important; }
                .stat-number { color: ${colors.accent} !important; }
                .stat-label { color: ${colors.text} !important; }

                /* ── Supervisor info inside employee name cells ── */
                .supervisor-info { color: ${colors.textMuted} !important; }

                /* ── Level badge inside employee name cells — fixed colors matching hover card ── */
                .level-info { color: white !important; }
                .level-info.level-ssa  { background: #dc2626 !important; }
                .level-info.level-ssa2 { background: #9b59b6 !important; }
                .level-info.level-l1   { background: #06b6d4 !important; }
                .level-info.level-l2   { background: #f59e0b !important; }
                .level-info.level-l3   { background: #0891b2 !important; }
                .level-info.level-tam  { background: #f97316 !important; }
                .level-info.level-tam2 { background: #ea580c !important; }
                .level-info.level-manager    { background: #7c3aed !important; }
                .level-info.level-Supervisor { background: #0369a1 !important; }
                .level-info.level-SR--Supervisor  { background: #0f766e !important; }
                .level-info.level-SR--Manager     { background: #c2410c !important; }
                .level-info.level-IMP-Tech        { background: #1d4ed8 !important; }
                .level-info.level-IMP-Coordinator { background: #6d28d9 !important; }
                .level-info.level-SecOps-T1       { background: #1a56db !important; }
                .level-info.level-SecOps-T2       { background: #7e3af2 !important; }
                .level-info.level-SecOps-T3       { background: #e74694 !important; }
                .level-info.level-SecEng          { background: #d61f69 !important; }
                .level-info.level-technical_writer { background: #0d9488 !important; }
                .level-info.level-trainer    { background: #16a34a !important; }
                .level-info.level-tech_coach { background: #0284c7 !important; }

                /* ── Activity log dropdown ── */
                .activity-log-dropdown { background: ${colors.card} !important; border-color: ${colors.border} !important; }
                .activity-log-item { border-bottom-color: ${colors.border} !important; }
                .activity-log-item:hover { background: ${colors.secondary} !important; }
                .activity-action { color: ${colors.accent} !important; }
                .activity-description { color: ${colors.text} !important; }
                .activity-meta { color: ${colors.textMuted} !important; }
                .activity-user { color: ${colors.accent} !important; }
                .activity-time { color: ${colors.textMuted} !important; }
                .activity-empty { color: ${colors.textMuted} !important; }

                /* ── Employee actions dropdown ── */
                .actions-dropdown { background: ${colors.card} !important; border-color: ${colors.border} !important; }
                .dropdown-item { color: ${colors.text} !important; background: transparent !important; }
                .dropdown-item:hover { background: ${colors.secondary} !important; color: #fff !important; }
                .dropdown-item.edit { color: ${colors.accent} !important; }
                .dropdown-item.delete { color: ${colors.danger} !important; }

                /* ── Modals ── */
                .modal-content,
                .edit-modal-content,
                .session-modal-content { background: ${colors.card} !important; color: ${colors.text} !important; }
                .modal-content h2, .modal-content h3, .modal-content h4,
                .edit-modal-content h2, .edit-modal-content h3, .edit-modal-content h4 { color: ${colors.text} !important; }
                .modal-content label, .edit-modal-content label { color: ${colors.text} !important; }
                #editInfo { color: ${colors.text} !important; }
                .warning-text { background: #422006 !important; border-color: #92400e !important; color: #fbbf24 !important; }
                /* Status button colours — exactly match cell override colours */
                .status-btn { color: #fff !important; }
                .status-btn.status-on           { background: ${colors.primary} !important; }
                .status-btn.status-off          { background: ${colors.secondary} !important; }
                .status-btn.status-pto          { background: ${colors.warning} !important; color: #1a1a1a !important; }
                .status-btn.status-sick         { background: ${colors.danger} !important; }
                .status-btn.status-holiday      { background: #22c55e !important; }
                .status-btn.status-custom_hours { background: linear-gradient(135deg, ${colors.primary}, ${colors.primary}99) !important; border: 2px solid ${colors.accent} !important; font-weight: bold !important; }
                .status-btn.status-schedule     { background: ${colors.primary}BB !important; border: 1px solid ${colors.accent} !important; }
                .form-group input, .form-group select { background: ${colors.secondary} !important; color: ${colors.text} !important; border-color: ${colors.border} !important; }

                /* ── Message banners ── */
                .message.success { background: #052e16 !important; color: #86efac !important; border-color: #166534 !important; }
                .message.error   { background: #2d0a0e !important; color: #fca5a5 !important; border-color: #991b1b !important; }
                .message.warning { background: #2d1b00 !important; color: #fcd34d !important; border-color: #92400e !important; }
                .message.info    { background: #0c2340 !important; color: #93c5fd !important; border-color: #1e40af !important; }
                .bulk-warning    { background: #2d1b00 !important; border-color: #92400e !important; color: #fcd34d !important; }

                /* ── Employee hover card ── */
                .employee-hover-card { background: ${colors.card} !important; border-color: ${colors.border} !important; }
                .employee-card-header { background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%) !important; color: white !important; }
                .employee-card-header * { color: white !important; }
                .employee-card-initials { color: white !important; }
                .employee-card-name { color: white !important; }
                .employee-card-team { color: white !important; }
                .employee-card-details { background: ${colors.card} !important; }
                .card-label { color: ${colors.textMuted} !important; }
                .card-value { color: ${colors.text} !important; }
                .employee-card-row .role-badge { color: white !important; }
                .employee-card-row .status-active { color: white !important; }
                .employee-card-row .status-inactive { color: white !important; }
                .employee-card-row .level-badge { color: white !important; }
                .employee-card-schedule { background: ${themeName === 'dark' ? colors.secondary : '#f8f9fa'} !important; border-top-color: ${colors.border} !important; }

                /* ── Profile card ── */
                .profile-card { background: ${colors.card} !important; border-color: ${colors.border} !important; }
                .profile-details { background: ${colors.card} !important; }
                .profile-field { border-bottom-color: ${colors.border} !important; }
                .field-label { color: ${colors.textMuted} !important; }
                .field-value { color: ${colors.text} !important; }
                .field-value.no-link { color: ${colors.textMuted} !important; }
                .employee-link { background: ${colors.secondary} !important; border-color: ${colors.border} !important; }
                .password-section { background: ${colors.secondary} !important; border-color: ${colors.border} !important; }
                .password-section h4 { color: ${colors.text} !important; }
                .form-note { color: ${colors.textMuted} !important; }

                /* ── Backup section ── */
                .backup-section { background: ${colors.secondary} !important; border-color: ${colors.border} !important; color: ${colors.text} !important; }
                .backup-item { background: ${colors.card} !important; border-color: ${colors.border} !important; }
                .backup-info { color: ${colors.textMuted} !important; }
                /* Backup table header — force white text on dark surface-color background */
                #backups-tab thead th, #backups-tab thead td { color: white !important; background: ${colors.secondary} !important; }

                /* ── Template section ── */
                .template-section { background: ${colors.secondary} !important; border-color: ${colors.border} !important; }
                .template-list { background: ${colors.card} !important; border-color: ${colors.border} !important; }
                .template-item { border-bottom-color: ${colors.border} !important; color: ${colors.text} !important; }
                .template-item:hover { background: ${colors.secondary} !important; }
                .template-name { color: ${colors.accent} !important; }
                .template-description, .template-meta { color: ${colors.textMuted} !important; }
                .template-header { color: ${colors.text} !important; }
                .template-toggle { color: ${colors.accent} !important; }

                /* ── Bulk employee list ── */
                .bulk-employee-list { background: ${colors.secondary} !important; border-color: ${colors.border} !important; }
                .bulk-employee-item label { color: ${colors.text} !important; }

                /* ── Empty states & permission denied ── */
                .empty-state { color: ${colors.textMuted} !important; }
                .permission-denied { background: #2d0a0e !important; color: #fca5a5 !important; }

                /* ── Misc text that's hardcoded dark ── */
                .form-group label, .form-group span { color: ${colors.text} !important; }

                /* ── Schedule Export table ── */
                .se-wrap { background: ${colors.card} !important; border-color: ${colors.border} !important; }
                .se-table thead th { background: ${colors.primary} !important; color: #ffffff !important; }
                .se-table tbody tr:nth-child(odd)  { background: ${colors.card} !important; }
                .se-table tbody tr:nth-child(even) { background: ${colors.secondary} !important; }
                .se-table tbody tr:hover { background: ${colors.border} !important; }
                .se-table td { color: ${colors.text} !important; border-color: ${colors.border} !important; }
                .se-td-name { color: ${colors.text} !important; font-weight: 600 !important; }
                .se-td-work { background: #1a4731 !important; color: #6ee7b7 !important; }
                .se-td-off  { background: ${colors.secondary} !important; color: ${colors.textMuted} !important; }
                ` : ''}
            `;
        }
    })();
    </script>
    
 <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
 <link rel="stylesheet" href="mobile.css?v=<?php echo time(); ?>">

    <!-- CSS for this section is in styles.css (search for "INDEX.PHP EXTRACTED STYLES") -->
</head>
<body>
    <div class="container with-sidebar">
        <div class="header">
            
            <!-- User Info — simple name + avatar display (nav moved to sidebar) -->
            <?php
            // Set role variables here so they're available to sidebar below
            $userRole = $currentUser['role'] ?? 'employee';
            $isAdminOrSupervisor = in_array($userRole, ['admin', 'manager', 'supervisor']);
            // Resolve profile photo — mirrors getProfilePhotoUrl() priority
            $currentUserPhoto = null;
            // 1. Local uploaded file
            if (isset($currentUser['profile_photo']) && $currentUser['profile_photo']) {
                $photo = $currentUser['profile_photo'];
                if (strpos($photo, 'http://') !== 0 && strpos($photo, 'https://') !== 0) {
                    $localPath = __DIR__ . '/profile_photos/' . $photo;
                    if (@file_exists($localPath)) {
                        $currentUserPhoto = 'profile_photos/' . $photo;
                    }
                }
            }
            // 2. profile_photo_url — written fresh on every Google login
            if (!$currentUserPhoto && isset($currentUser['profile_photo_url']) && $currentUser['profile_photo_url']) {
                if (strpos($currentUser['profile_photo_url'], 'https://') === 0) {
                    $currentUserPhoto = $currentUser['profile_photo_url'];
                }
            }
            // 3. google_profile_photo — original JSON-era column, most users have data here
            if (!$currentUserPhoto && isset($currentUser['google_profile_photo']) && $currentUser['google_profile_photo']) {
                if (strpos($currentUser['google_profile_photo'], 'https://') === 0) {
                    $currentUserPhoto = $currentUser['google_profile_photo'];
                }
            }
            // 4. google_picture
            if (!$currentUserPhoto && isset($currentUser['google_picture']) && $currentUser['google_picture']) {
                if (strpos($currentUser['google_picture'], 'https://') === 0) {
                    $currentUserPhoto = $currentUser['google_picture'];
                }
            }
            // 4. profile_photo as a URL (last resort — may be stale from old migration)
            if (!$currentUserPhoto && isset($currentUser['profile_photo']) && $currentUser['profile_photo']) {
                $photo = $currentUser['profile_photo'];
                if (strpos($photo, 'https://') === 0) {
                    $currentUserPhoto = $photo;
                }
            }
            ?>
            
            <h1>CS Unified Schedule</h1>
            <p>Team Schedule &amp; Shift Management</p>

            <!-- Clock pinned to top-right of header -->
            <div class="header-clock">
                <div class="header-clock-time" id="userTime"><?php echo date('g:i:s A'); ?></div>
                <div class="header-clock-sub">
                    <span id="userTimezone"><?php echo date_default_timezone_get(); ?></span>
                    <span id="clockDate"><?php echo date('l, M j, Y'); ?></span>
                </div>
            </div>
        </div>

        <!-- Message of the Day Banner - Always visible when content exists -->
        <?php 
        $motdDisplay = getDisplayMOTD($employees);
        ?>
        <?php if ($motdDisplay['has_content']): ?>
        <div id="motdBanner" class="motd-banner" style="padding: 15px 20px; margin: 15px 0; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); overflow: hidden;">
            <div class="motd-inner-content" style="display: flex; align-items: center; gap: 12px;">
                <div style="font-size: 28px; line-height: 1; flex-shrink: 0;">📢</div>
                <div class="motd-content-wrapper" style="flex: 1; overflow: hidden;">
                    <div class="motd-scrolling-content" style="color: white; font-weight: bold; font-size: 18px;">
                        <?php 
                        $fullMessage = '';
                        
                        // Add message if present
                        if (!empty($motdDisplay['message'])) {
                            $fullMessage = str_replace("\n", " • ", htmlspecialchars($motdDisplay['message']));
                        }
                        
                        // Add anniversaries if present (with separator if message exists)
                        if (!empty($motdDisplay['anniversary_message'])) {
                            if (!empty($fullMessage)) {
                                $fullMessage .= ' • • • ';
                            }
                            $fullMessage .= $motdDisplay['anniversary_message'];
                        }
                        
                        echo '<span style="display: inline-block; white-space: nowrap; padding-right: 100px;">' . $fullMessage . '</span>';
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($message): ?>
        <div id="flash-message" class="message <?php echo $messageType; ?>">
            <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <!-- Employee Status Message for Employees Only -->
<?php 
$currentEmployee = getCurrentUserEmployee();
if (hasPermission('edit_own_schedule') && !hasPermission('edit_schedule')): ?>
<div class="message <?php echo $currentEmployee ? 'success' : 'info'; ?>">
    <strong>Employee Schedule Access:</strong>
    <?php if ($currentEmployee): ?>
        ✅ You are linked to employee: <strong><?php echo escapeHtml($currentEmployee['name']); ?></strong> - Look for the green row with "YOU" indicator to edit your schedule.
    <?php else: ?>
        ✅ **Personal Schedule Access:** You can create and edit your own schedule entries. You may also request to be linked to an employee record for full integration.
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
// Define showTab function early so navigation buttons can use it
function updateMonthSelect() {
    var monthSel = document.getElementById('monthNameSel');
    var yearSel  = document.getElementById('monthYearSel');
    var hidden   = document.getElementById('monthSelectHidden');
    if (!monthSel || !yearSel || !hidden) return;
    hidden.value = yearSel.value + '-' + monthSel.value;
    var form = hidden.closest('form');
    if (form) form.submit();
}

function showTab(tabName) {
    // CRITICAL: Normalize tab name - convert underscore to hyphen
    const normalizedTabName = tabName.replace(/_/g, '-');
    
    // Batch DOM reads first
    const allTabs = document.querySelectorAll('.tab-content');
    const allNavBtns = document.querySelectorAll('.nav-tab');
    const selectedTab = document.getElementById(normalizedTabName + '-tab');
    
    // Batch DOM writes together
    requestAnimationFrame(() => {
        // Hide all tabs and remove active class
        allTabs.forEach(tab => {
            tab.classList.remove('active');
            tab.style.display = 'none';
        });
        
        allNavBtns.forEach(btn => btn.classList.remove('active'));
        
        // Show selected tab
        if (selectedTab) {
            selectedTab.classList.add('active');
            selectedTab.style.display = 'block';
        }
        
        // Add active class to clicked nav tab
        allNavBtns.forEach(btn => {
            if (btn.onclick && btn.onclick.toString().includes(`'${tabName}'`)) {
                btn.classList.add('active');
            }
        });

        // Update sidebar link active states
        document.querySelectorAll('.sidebar-link').forEach(link => {
            link.classList.remove('active');
            const clickAttr = link.getAttribute('onclick') || '';
            if (clickAttr.includes(`'${normalizedTabName}'`) || clickAttr.includes(`'${tabName}'`)) {
                link.classList.add('active');
            }
        });

        // Flush Chrome's hover-state cache by briefly disabling pointer events
        // on the sidebar. Without this, Chrome keeps :hover on whichever link
        // the mouse last touched after a JS-only tab switch.
        const sidebar = document.getElementById('appSidebar');
        if (sidebar) {
            sidebar.style.pointerEvents = 'none';
            requestAnimationFrame(() => { sidebar.style.pointerEvents = ''; });
        }
        
        // Initialize heatmap when showing heatmap tab (AFTER tab is active)
        if (normalizedTabName === 'heatmap') {
            // Check if function exists (it's defined later in the page)
            if (typeof initializeHeatmap === 'function') {
                initializeHeatmap();
                if (typeof initializeSkillsHeatmap === 'function') {
                    initializeSkillsHeatmap();
                }
            } else {
                // Retry after a short delay to allow the rest of the page to load
                setTimeout(() => {
                    if (typeof initializeHeatmap === 'function') {
                        initializeHeatmap();
                        if (typeof initializeSkillsHeatmap === 'function') {
                            initializeSkillsHeatmap();
                        }
                    } else {
                    }
                }, 100);
            }
            // Fallback: re-render after a short delay in case rAF render didn't take
            setTimeout(function() {
                var gridEl = document.getElementById('heatmapGridContainer');
                var skGridEl = document.getElementById('skHeatmapGridContainer');
                var needsRender = (gridEl && gridEl.querySelector('.loading')) ||
                                  (skGridEl && skGridEl.querySelector('.loading'));
                if (needsRender) {
                    if (typeof updateHeatmapData === 'function') updateHeatmapData();
                    if (typeof updateSkillsHeatmapData === 'function') updateSkillsHeatmapData();
                }
            }, 200);
        }
        
        // Initialize profile when showing profile tab (AFTER tab is active)
        if (normalizedTabName === 'profile') {
            const profileForms = document.querySelectorAll('#profile-tab form');
            profileForms.forEach(form => {
                if (!form.dataset.initialized) {
                    form.addEventListener('submit', handleProfileFormSubmit);
                    form.dataset.initialized = 'true';
                }
            });
        }
    });
    
    // Update URL with tab parameter (without page reload)
    // Save current parameters BEFORE creating new URL
    const currentParams = new URLSearchParams(window.location.search);
    const currentId = currentParams.get('id');
    const currentUserId = currentParams.get('user_id');
    const currentTab = currentParams.get('tab');
    
    const url = new URL(window.location);
    
    // Clear ALL parameters
    url.search = '';
    
    // Set only the tab parameter
    url.searchParams.set('tab', tabName);
    
    // Add back specific parameters ONLY if staying on the same special tab
    if (normalizedTabName === 'edit-employee' && currentId) {
        // Only keep 'id' if it was already there
        url.searchParams.set('id', currentId);
    } else if (normalizedTabName === 'view-profile' && currentUserId) {
        // Only keep 'user_id' if it was already there
        url.searchParams.set('user_id', currentUserId);
    } else {
    }
    
    window.history.replaceState({}, '', url);
}
</script>

        <!-- Navigation Tabs (hidden when sidebar active) -->
        <div class="nav-tabs">
            <button class="nav-tab<?php echo ($activeTab === 'schedule') ? ' active' : ''; ?>" onclick="showTab('schedule')">📅 Schedule</button>

            <?php if (hasPermission('view_schedule')): ?>
            <button class="nav-tab<?php echo ($activeTab === 'heatmap') ? ' active' : ''; ?>" onclick="showTab('heatmap')">📊 Heatmap</button>
            <?php endif; ?>
        </div>

        <!-- Sidebar + Main Content Wrapper -->
        <div class="app-body">

            <!-- Sidebar Navigation -->
            <nav class="app-sidebar" id="appSidebar">

                <!-- User Identity block with My Profile link -->
                <div style="display: flex; align-items: center; gap: 10px; padding: 10px 12px; margin: 4px 6px 0;">
                    <div style="width: 38px; height: 38px; border-radius: 50%; overflow: hidden; border: 2px solid rgba(255,255,255,0.35); flex-shrink: 0; background: rgba(255,255,255,0.15); position: relative;">
                        <?php $headerInitials = strtoupper(substr($currentUser['full_name'], 0, 2)); ?>
                        <?php if ($currentUserPhoto): ?>
                            <div style="display:flex;width:100%;height:100%;align-items:center;justify-content:center;color:white;font-weight:bold;font-size:15px;background:var(--secondary-color,#6c757d);"><?php echo $headerInitials; ?></div>
                            <img src="<?php echo htmlspecialchars($currentUserPhoto); ?>"
                                 alt="<?php echo htmlspecialchars($currentUser['full_name']); ?>"
                                 style="width:100%;height:100%;object-fit:cover;display:none;position:absolute;top:0;left:0;"
                                 onload="this.style.display='';this.previousElementSibling.style.display='none';"
                                 onerror="this.style.display='none';this.previousElementSibling.style.display='flex';">
                        <?php else: ?>
                            <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: white !important; font-weight: bold; font-size: 15px; background: var(--secondary-color, #6c757d);">
                                <?php echo $headerInitials; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="overflow: hidden; flex: 1; min-width: 0;">
                        <div style="color: white; font-weight: 600; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo escapeHtml($currentUser['full_name']); ?></div>
                        <div style="color: rgba(255,255,255,0.6); font-size: 10px; text-transform: uppercase; letter-spacing: 0.6px;"><?php echo escapeHtml($userRole); ?></div>
                        <a href="#" onclick="event.preventDefault(); showTab('profile');" style="color: rgba(255,255,255,0.5); font-size: 10px; text-decoration: none; display: inline-block; margin-top: 2px; transition: color 0.15s;" onmouseover="this.style.color='rgba(255,255,255,0.9)'" onmouseout="this.style.color='rgba(255,255,255,0.5)'">👤 My Profile</a>
                    </div>
                </div>

                <div class="sidebar-section-label">Main</div>

                <a class="sidebar-link<?php echo ($activeTab === 'schedule') ? ' active' : ''; ?>" href="#" onclick="event.preventDefault(); showTab('schedule'); this.blur();">
                    <span class="sb-icon">📅</span>
                    <span>Schedule</span>
                </a>

                <?php if (hasPermission('view_schedule')): ?>
                <a class="sidebar-link<?php echo ($activeTab === 'heatmap') ? ' active' : ''; ?>" href="#" onclick="event.preventDefault(); showTab('heatmap'); this.blur();">
                    <span class="sb-icon">📊</span>
                    <span>Heatmap</span>
                </a>
                <?php endif; ?>

                <?php if ($isAdminOrSupervisor): ?>
                <div class="sidebar-divider"></div>
                <div class="sidebar-section-label">Management</div>

                <a class="sidebar-link<?php echo ($activeTab === 'bulk-schedule') ? ' active' : ''; ?>" href="#" onclick="event.preventDefault(); showTab('bulk-schedule'); this.blur();">
                    <span class="sb-icon">⚡</span>
                    <span>Bulk Changes</span>
                </a>

                <a class="sidebar-link<?php echo ($activeTab === 'add-employee') ? ' active' : ''; ?>" href="#" onclick="event.preventDefault(); showTab('add-employee'); this.blur();">
                    <span class="sb-icon">➕</span>
                    <span>Add Employee</span>
                </a>

                <div class="sidebar-divider"></div>
                <div class="sidebar-section-label">Admin</div>

                <a class="sidebar-link<?php echo ($activeTab === 'settings') ? ' active' : ''; ?>" href="#" onclick="event.preventDefault(); showTab('settings'); this.blur();">
                    <span class="sb-icon">👥</span>
                    <span>Settings</span>
                </a>

                <a class="sidebar-link<?php echo ($activeTab === 'backups') ? ' active' : ''; ?>" href="#" onclick="event.preventDefault(); showTab('backups'); this.blur();">
                    <span class="sb-icon">💾</span>
                    <span>Backups</span>
                </a>

                <a class="sidebar-link<?php echo ($activeTab === 'checklist') ? ' active' : ''; ?>" href="#" onclick="event.preventDefault(); showTab('checklist'); this.blur();">
                    <span class="sb-icon">✅</span>
                    <span>Setup Checklist</span>
                </a>
                <?php endif; ?>

                <a class="sidebar-link<?php echo ($activeTab === 'manual') ? ' active' : ''; ?>" href="#" onclick="event.preventDefault(); showTab('manual'); this.blur();">
                    <span class="sb-icon">📖</span>
                    <span>User Manual</span>
                </a>

                <?php if ($isAdminOrSupervisor): ?>
                <a class="sidebar-link<?php echo ($activeTab === 'api-docs') ? ' active' : ''; ?>" href="#" onclick="event.preventDefault(); showTab('api-docs'); this.blur();">
                    <span class="sb-icon">🔌</span>
                    <span>API Reference</span>
                </a>
                <?php endif; ?>

                <div class="sidebar-divider" style="margin: 8px 0; border-top: 1px solid rgba(255,255,255,0.12);"></div>
                <a class="sidebar-link" href="?action=logout" style="color: rgba(255,110,110,0.9);">
                    <span class="sb-icon">🚪</span>
                    <span>Logout</span>
                </a>

            </nav><!-- /.app-sidebar -->

            <!-- Main Content Area -->
            <div class="app-main">

        <!-- Global Notification Bar (for AJAX responses) -->
        <div id="notificationBar" style="display: none; padding: 15px 20px; margin: 20px 0; border-radius: 8px; font-size: 14px; font-weight: bold; transition: all 0.3s ease;">
            <span id="notificationMessage"></span>
            <button onclick="hideNotification()" style="float: right; background: none; border: none; color: inherit; cursor: pointer; font-size: 18px; font-weight: bold; opacity: 0.7; margin-top: -2px;" title="Close">&times;</button>
        </div>

        <!-- Schedule Tab -->
        <div id="schedule-tab" class="tab-content<?php echo ($activeTab === 'schedule') ? ' active' : ''; ?>" style="position: relative;">
            <div class="controls">
                <form method="GET" style="display: contents;">
                    <!-- Preserve team filter when changing other filters -->
                    <?php if (is_array($teamFilter)): ?>
                        <?php foreach ($teamFilter as $team): ?>
                            <?php if ($team !== 'all'): ?>
                                <input type="hidden" name="team[]" value="<?php echo escapeHtml($team); ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <label>Month:</label>
                    <div style="display:inline-flex; gap:4px; align-items:center;">
                        <?php $monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December']; ?>
                        <select id="monthNameSel" onchange="updateMonthSelect()" style="padding:4px 8px; border-radius:4px; border:1px solid var(--border-color,#ddd); background:var(--card-bg,#fff); color:var(--text-color,#333); font-size:13px; cursor:pointer;">
                            <?php foreach ($monthNames as $mi => $mn): ?>
                                <option value="<?php echo $mi; ?>" <?php echo $mi == $currentMonth ? 'selected' : ''; ?>><?php echo $mn; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="monthYearSel" onchange="updateMonthSelect()" style="padding:4px 8px; border-radius:4px; border:1px solid var(--border-color,#ddd); background:var(--card-bg,#fff); color:var(--text-color,#333); font-size:13px; cursor:pointer;">
                            <?php for ($y = 2025; $y <= 2027; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == $currentYear ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <input type="hidden" name="monthSelect" id="monthSelectHidden" value="<?php echo $currentYear . '-' . $currentMonth; ?>">
                    
                    <label>Shift:</label>
                    <select name="shift" onchange="this.form.submit();">
                        <option value="all" <?php echo $shiftFilter === 'all' ? 'selected' : ''; ?>>All Shifts</option>
                        <option value="1" <?php echo $shiftFilter === '1' ? 'selected' : ''; ?>>1st Shift</option>
                        <option value="2" <?php echo $shiftFilter === '2' ? 'selected' : ''; ?>>2nd Shift</option>
                        <option value="3" <?php echo $shiftFilter === '3' ? 'selected' : ''; ?>>3rd Shift</option>
                       
                    </select>
                    
                    <label>Level:</label>
                    <select name="level" onchange="this.form.submit();">
                        <option value="all" <?php echo $levelFilter === 'all' ? 'selected' : ''; ?>>All Levels</option>
                        <option value="" <?php echo $levelFilter === '' ? 'selected' : ''; ?>>No Level</option>
                        <?php foreach ($levels as $levelKey => $levelName): ?>
                        <?php if ($levelKey !== ''): ?>
                        <option value="<?php echo $levelKey; ?>" <?php echo $levelFilter === $levelKey ? 'selected' : ''; ?>>
                            <?php echo $levelName; ?>
                        </option>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    
                    <label>Supervisor/Manager:</label>
                    <select name="supervisor" onchange="this.form.submit();">
                        <option value="all" <?php echo $supervisorFilter === 'all' ? 'selected' : ''; ?>>All Supervisors/Managers</option>
                        <option value="none" <?php echo $supervisorFilter === 'none' ? 'selected' : ''; ?>>No Supervisor/Manager</option>
                        <?php
                            $supMgr = array_values(array_filter($employees, 'isSupervisorOrManagerLevelStrict'));
                            if (empty($supMgr)) {
                                $supMgr = array_values(array_filter($employees, 'isSupervisorOrManagerLevelStrict'));
                            }
                            usort($supMgr, function($a, $b) { return strcasecmp($a['name'] ?? '', $b['name'] ?? ''); });
                            foreach ($supMgr as $supervisor):
                                $labelRole = (function_exists('getLevelName') ? getLevelName($supervisor['level'] ?? '') : '') ?: formatLeadershipLabel($supervisor); ?>
                        <option value="<?php echo (int)$supervisor['id']; ?>" <?php echo $supervisorFilter == $supervisor['id'] ? 'selected' : ''; ?>>
                            <?php echo escapeHtml($supervisor['name']); ?> (<?php echo strtoupper($labelRole); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <label>Skills:</label>
                    <select name="skills" onchange="this.form.submit();">
                        <option value="all" <?php echo $skillsFilter === 'all' ? 'selected' : ''; ?>>No sort</option>
                        <option value="mh" <?php echo $skillsFilter === 'mh' ? 'selected' : ''; ?>>MH - Managed Hosting</option>
                        <option value="ma" <?php echo $skillsFilter === 'ma' ? 'selected' : ''; ?>>MA - Managed Apps</option>
                        <option value="win" <?php echo $skillsFilter === 'win' ? 'selected' : ''; ?>>Win - Windows</option>
                    </select>

<script>
// Employee Search Functionality
let originalEmployeeRows = null;
let searchActive = false;

function filterEmployees(searchTerm) {
    const table = document.querySelector('.schedule-table tbody');
    const searchResults = document.getElementById('searchResults');
    
    if (!table) return;
    
    // Store original rows on first search
    if (!originalEmployeeRows) {
        originalEmployeeRows = Array.from(table.querySelectorAll('tr'));
    }
    
    searchTerm = searchTerm.toLowerCase().trim();
    
    // Save search term to localStorage for persistence
    if (searchTerm === '') {
        localStorage.removeItem('employeeSearchTerm');
    } else {
        localStorage.setItem('employeeSearchTerm', searchTerm);
    }
    
    if (searchTerm === '') {
        // Show all employees
        restoreAllEmployees();
        return;
    }
    
    searchActive = true;
    let visibleCount = 0;
    
    originalEmployeeRows.forEach(row => {
        const employeeName = row.querySelector('.employee-name-text');
        if (employeeName) {
            const name = employeeName.textContent.toLowerCase();
            const isMatch = name.includes(searchTerm);
            
            if (isMatch) {
                row.style.display = '';
                visibleCount++;
                // Highlight matching text
                highlightSearchTerm(employeeName, searchTerm);
            } else {
                row.style.display = 'none';
            }
        }
    });
    
    // Update search results indicator (use CSS vars so dark theme works)
    if (visibleCount === 0) {
        searchResults.textContent = 'No matches found';
        searchResults.style.background = 'var(--danger-color, #dc3545)';
        searchResults.style.color = '#fff';
    } else {
        searchResults.textContent = `${visibleCount} employee${visibleCount !== 1 ? 's' : ''} found`;
        searchResults.style.background = 'var(--primary-color, #333399)';
        searchResults.style.color = '#fff';
    }
    searchResults.style.display = 'inline-block';
}

function highlightSearchTerm(element, searchTerm) {
    const originalText = element.getAttribute('data-original-text') || element.textContent;
    if (!element.getAttribute('data-original-text')) {
        element.setAttribute('data-original-text', originalText);
    }
    
    if (searchTerm === '') {
        element.textContent = originalText;
        return;
    }
    
    const regex = new RegExp(`(${escapeRegExp(searchTerm)})`, 'gi');
    const highlightedText = originalText.replace(regex, '<mark style="background: #ffeb3b; padding: 1px 2px; border-radius: 2px;">$1</mark>');
    element.innerHTML = highlightedText;
}

function clearEmployeeSearch() {
    const searchInput = document.getElementById('employeeSearch');
    const searchResults = document.getElementById('searchResults');
    
    if (searchInput) {
        searchInput.value = '';
        searchInput.focus();
    }
    
    if (searchResults) {
        searchResults.style.display = 'none';
    }
    
    // Clear from localStorage
    localStorage.removeItem('employeeSearchTerm');
    
    restoreAllEmployees();
}

function restoreAllEmployees() {
    const table = document.querySelector('.schedule-table tbody');
    if (!table || !originalEmployeeRows) return;
    
    searchActive = false;
    
    // Show all rows and remove highlighting
    originalEmployeeRows.forEach(row => {
        row.style.display = '';
        const employeeName = row.querySelector('.employee-name-text');
        if (employeeName && employeeName.getAttribute('data-original-text')) {
            employeeName.textContent = employeeName.getAttribute('data-original-text');
        }
    });
}

function escapeRegExp(string) {
    return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

// Enhanced search with keyboard shortcuts
document.addEventListener('keydown', function(event) {
    // Ctrl/Cmd + F to focus search
    if ((event.ctrlKey || event.metaKey) && event.key === 'f') {
        event.preventDefault();
        const searchInput = document.getElementById('employeeSearch');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        }
    }
    
    // Escape to clear search when input is focused
    if (event.key === 'Escape') {
        const searchInput = document.getElementById('employeeSearch');
        if (searchInput && document.activeElement === searchInput) {
            clearEmployeeSearch();
        }
    }
});

// Preserve search when page updates
function preserveSearchState() {
    const searchInput = document.getElementById('employeeSearch');
    if (searchInput && searchInput.value) {
        // Re-apply search after a brief delay to allow DOM to update
        setTimeout(() => {
            filterEmployees(searchInput.value);
        }, 100);
    }
}

// Initialize search preservation
document.addEventListener('DOMContentLoaded', function() {
    // Reset search state on page load
    originalEmployeeRows = null;
    searchActive = false;
    
    // Restore search from localStorage if available
    const savedSearchTerm = localStorage.getItem('employeeSearchTerm');
    const searchInput = document.getElementById('employeeSearch');
    if (savedSearchTerm && searchInput) {
        searchInput.value = savedSearchTerm;
        // Wait for table to be fully rendered
        setTimeout(function() {
            filterEmployees(savedSearchTerm);
        }, 100);
    }
    
    // Add search preservation to form submissions
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            const searchInput = document.getElementById('employeeSearch');
            if (searchInput && searchInput.value) {
                // Add hidden input to preserve search
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'preserveSearch';
                hiddenInput.value = searchInput.value;
                form.appendChild(hiddenInput);
            }
        });
    });
    
    // Initialize MOTD visibility - No longer needed as MOTD is always visible in header
});

// Restore search from URL parameter
<?php if (isset($_GET['preserveSearch'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('employeeSearch');
    if (searchInput) {
        searchInput.value = '<?php echo escapeHtml($_GET['preserveSearch']); ?>';
        filterEmployees(searchInput.value);
    }
});
<?php endif; ?>


// Multi-select team filter handler
function applyTeamFilter() {
    const form = document.getElementById('teamFilterForm');
    const teamSelect = document.getElementById('teamFilter');
    const selectedOptions = Array.from(teamSelect.selectedOptions);
    
    // If nothing is selected, default to "All Teams"
    if (selectedOptions.length === 0) {
        Array.from(teamSelect.options).forEach(opt => {
            opt.selected = (opt.value === 'all');
        });
    }
    // If "All Teams" is selected, deselect all others
    else if (selectedOptions.some(opt => opt.value === 'all')) {
        Array.from(teamSelect.options).forEach(opt => {
            opt.selected = (opt.value === 'all');
        });
    }
    
    form.submit();
}

function clearTeamFilter() {
    const form = document.getElementById('teamFilterForm');
    const teamSelect = document.getElementById('teamFilter');
    
    // Deselect all options
    Array.from(teamSelect.options).forEach(opt => {
        opt.selected = false;
    });
    
    // Select "All Teams"
    const allOption = teamSelect.querySelector('option[value="all"]');
    if (allOption) {
        allOption.selected = true;
    }
    
    // Submit the form to apply the filter
    form.submit();
}

// Handle multi-select display hint
document.addEventListener('DOMContentLoaded', function() {
    const teamSelect = document.getElementById('teamFilter');
    if (teamSelect) {
        // Set height to show it's multi-select
        teamSelect.style.height = 'auto';
        
        // If no options are selected, select "All Teams" by default
        const selectedOptions = Array.from(teamSelect.selectedOptions);
        if (selectedOptions.length === 0) {
            const allOption = teamSelect.querySelector('option[value="all"]');
            if (allOption) {
                allOption.selected = true;
            }
        }
        
        // Add hint text
        const label = teamSelect.previousElementSibling;
        if (label && label.tagName === 'LABEL') {
            label.title = 'Hold Ctrl (Cmd on Mac) to select multiple teams';
        }
    }
});
</script>

<style>
/* Header Clock (top-right of main header bar) */
.header-clock {
    position: absolute;
    top: 50%;
    right: 18px;
    transform: translateY(-50%);
    text-align: right;
    background: rgba(0,0,0,0.18);
    border-radius: 8px;
    padding: 8px 14px;
    min-width: 170px;
    pointer-events: none;
}
.header-clock-time {
    font-size: 22px;
    font-weight: 700;
    color: #fff !important;
    letter-spacing: 0.5px;
    line-height: 1.2;
    font-family: 'Courier New', monospace;
}
.header-clock-sub {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 1px;
    margin-top: 3px;
    font-size: 11px;
    color: rgba(255,255,255,0.8) !important;
    line-height: 1.4;
}

/* Tooltip Styling */
.has-tooltip {
    position: relative;
    cursor: pointer;
}

.has-tooltip .tooltip {
    /* Hidden by default — JavaScript controls show/hide timing entirely.
       CSS :hover still triggers the instant show; JS manages the 15s hide delay
       and keeps pointer-events active so the panel is reachable and copyable. */
    visibility: hidden;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.15s ease, visibility 0.15s ease;
    position: absolute;
    left: calc(100% + 15px);
    top: 0;
    background: #1e2a35 !important;
    color: #ffffff !important;
    padding: 16px;
    border-radius: 8px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.45);
    z-index: 1000;
    min-width: 380px;
    max-width: min(580px, calc(100vw - 100% - 30px));
    max-height: 80vh;
    overflow-y: auto;
    white-space: normal;
    font-size: 13px;
    line-height: 1.5;
    user-select: text;
    cursor: text;
}

/* CSS hover provides the instant show; JS overrides inline to extend the hide */
.has-tooltip:hover .tooltip {
    visibility: visible;
    opacity: 1;
    pointer-events: auto;
}

.tooltip::before {
    content: '';
    position: absolute;
    top: 20px;
    right: 100%;
    border: 8px solid transparent;
    border-right-color: #1e2a35;
    pointer-events: none;
}

.tooltip-header {
    font-weight: bold;
    font-size: 15px;
    color: #ffffff !important;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 2px solid rgba(255,255,255,0.3);
}

.tooltip-content {
    display: grid;
    grid-template-columns: 1fr 1fr;
    column-gap: 20px;
    row-gap: 0;
}

.tooltip-team-group {
    break-inside: avoid;
    padding-bottom: 8px;
    margin: 0;
}

.tooltip-team {
    font-weight: bold;
    margin: 0 0 2px 0;
    padding: 0;
    color: #ffffff !important;
}

.tooltip-employees {
    margin: 0 0 0 10px;
    padding: 0;
    color: #ffffff !important;
}

/* Working Today Stats Styling — white card, dark text, themed accent bar */
.stat-item.has-tooltip {
    background: #ffffff !important;
    color: var(--text-color, #333333) !important;
    border: 1px solid var(--border-color, rgba(0,0,0,0.1)) !important;
}

.stat-number {
    font-size: 64px !important;
    font-weight: bold !important;
    color: var(--primary-color, #0c5460) !important;
    line-height: 1 !important;
    margin-bottom: 8px !important;
}

.stat-label {
    font-size: 18px !important;
    font-weight: bold !important;
    color: var(--text-color, #333333) !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}

/* Hint text inside the white card */
.stat-item.has-tooltip > div:not(.tooltip) {
    color: var(--text-muted, #666666) !important;
}

/* Tooltip popout — always dark bg / white text regardless of theme.
   Use three-class specificity (0-3-0) to beat any dynamic theme rule that uses
   .tab-content small  (0-1-1)  or  .tab-content > div  (0-1-1). */
.stat-item.has-tooltip .tooltip,
.stat-item.has-tooltip .tooltip *,
.stat-item.has-tooltip .tooltip small,
.stat-item.has-tooltip .tooltip span,
.stat-item.has-tooltip .tooltip div {
    color: #ffffff !important;
}

/* ── Schedule accent bar ─────────────────────────────────────────────────────
   A themed vertical bar runs down the left edge of the schedule tab, connecting
   the controls/filter area visually to the scrollable schedule table.
   A matching top strip sits immediately above the schedule-wrapper to bridge the
   gap between the filter widgets and the table header.
   Both use CSS custom properties so they update instantly with every theme.
   ─────────────────────────────────────────────────────────────────────────── */
#schedule-tab {
    border-left: 5px solid var(--primary-color, #0c5460);
    border-bottom: 5px solid var(--primary-color, #0c5460);
    border-radius: 0 0 0 6px;
}

/* Schedule wrapper — the accent bar div above it replaces the old border-top */
.schedule-wrapper {
    border-top: none !important;
    border-radius: 0;
}

/* Additional styling for search controls */
.search-indicator {
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-5px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Theme compatibility for search controls */
/* Force search controls to always start on second row by taking full width */
.search-controls {
    flex-shrink: 0;
    flex-basis: 100%;
    width: 100%;
    max-width: none;
}

.search-controls input:focus {
    outline: none;
    border-color: #094a55;
    box-shadow: 0 0 0 2px rgba(12, 84, 96, 0.2);
}

.search-controls button:hover {
    background: #094a55;
    transform: translateY(-1px);
}

/* Multi-select team filter styling */
.controls {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 10px;
}

.controls form {
    display: contents;
}

.team-filter-section {
    flex-shrink: 0;
    max-width: 300px;
    align-self: flex-start;
}

/* Search controls - force to second row across all browsers */
.search-controls {
    flex-shrink: 0;
    align-self: flex-start;
    flex-basis: 100%;
    width: 100%;
    max-width: none;
}

#teamFilter {
    height: 120px !important;
    min-height: 120px !important;
    max-height: 120px !important;
    overflow-y: auto;
    cursor: pointer;
    resize: none !important;
}

#teamFilter option {
    padding: 5px 8px;
    cursor: pointer;
}

#teamFilter option:checked {
    background: linear-gradient(0deg, #0c5460 0%, #0c5460 100%);
    color: white;
}

.controls button[onclick="applyTeamFilter();"] {
    background: #0c5460;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s;
}

.controls button[onclick="applyTeamFilter();"]:hover {
    background: #094a55;
    transform: translateY(-1px);
}

.controls label[title] {
    cursor: help;
    position: relative;
}

/* Team filter enhanced styling */
.team-filter-section {
    max-width: 300px;
}

.team-filter-section label {
    user-select: none;
}

.team-filter-section kbd {
    font-family: monospace;
    font-weight: bold;
}

#teamFilter option {
    transition: background 0.1s;
}

#teamFilter option:hover {
    background: #e8f5f7 !important;
}

/* Responsive adjustments for team filter and search */
@media (max-width: 900px) {
    .controls {
        flex-direction: column;
    }
    
    .team-filter-section {
        width: 100%;
        max-width: 100%;
        margin-left: 0 !important;
        margin-bottom: 15px;
    }
    
    .search-controls {
        width: 100%;
        margin-left: 0 !important;
        margin-bottom: 15px;
    }
    
    .stat-item {
        margin-left: 0 !important;
    }
    
    #teamFilter {
        width: 100% !important;
    }
}

@media (max-width: 768px) {
    .team-filter-section > form > div {
        flex-direction: column !important;
    }
    
    .team-filter-section select {
        width: 100% !important;
        height: 120px !important;
    }
    
    .team-filter-section button[onclick="applyTeamFilter();"] {
        width: 100% !important;
        margin-top: 8px !important;
    }
}

    background: var(--search-bg, #ffffff) !important;
    border-color: var(--search-border, #000000) !important;
}

.search-controls label {
    color: var(--search-text, #0c5460) !important;
}
</style>                 
                    <!-- Note: Team filter intentionally NOT preserved when changing month - defaults to "All Teams" -->
                    </form>
                
                <!-- Search and Team Filter Container - Side by Side -->
                <div style="display: flex; gap: 15px; align-items: stretch; margin-top: 5px; flex-wrap: wrap;">
                    <!-- Employee Search Controls -->
                    <div class="search-controls" style="background: var(--card-bg, white); color: var(--text-color, #333); padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); display: flex; flex-direction: column; gap: 8px; flex: 1;">
                        <label style="margin: 0; color: var(--text-color, #333); font-weight: bold; font-size: 14px;">🔍 Search Employees:</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="text"
                                   id="employeeSearch"
                                   placeholder="Type employee name..."
                                   style="flex: 1; padding: 8px 12px; border: 2px solid var(--primary-color, #0c5460); border-radius: 6px; font-size: 13px; background: var(--card-bg, white); color: var(--text-color, #333);"
                                   oninput="filterEmployees(this.value)"
                                   onkeyup="if(event.key === 'Escape') clearEmployeeSearch()">
                            <div style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; background: var(--primary-color, #0c5460); border-radius: 6px; color: white !important; font-size: 20px; flex-shrink: 0;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
                            </div>
                            <button type="button"
                                    onclick="clearEmployeeSearch()"
                                    style="background: var(--primary-color, #0c5460); color: white; border: none; padding: 8px 12px; border-radius: 4px; font-size: 13px; cursor: pointer; font-weight: bold;"
                                    title="Clear search">
                                Clear
                            </button>
                        </div>

                        <!-- Help Text Box -->
                        <div style="flex: 1; background: var(--primary-color, #0c5460); border: 1px solid rgba(255,255,255,0.2); border-radius: 6px; padding: 10px; display: flex; flex-direction: column; gap: 6px; min-height: 80px;">
                            <div style="font-size: 12px; color: #fff !important; font-weight: bold;">💡 Quick Tips:</div>
                            <div style="font-size: 11px; color: #fff !important; line-height: 1.4;">
                                • Search persists until you click <strong style="color:#fff !important;">Clear</strong> or press <kbd style="background: rgba(255,255,255,0.2); color: #fff !important; padding: 1px 4px; border: 1px solid rgba(255,255,255,0.4); border-radius: 3px; font-size: 10px;">ESC</kbd>
                            </div>
                            <div style="font-size: 11px; color: #fff !important; line-height: 1.4;">
                                • Your search is saved even when navigating to other pages
                            </div>
                        </div>

                        <span id="searchResults" class="search-indicator" style="background: var(--primary-color, #0c5460); color: #fff !important; padding: 6px 10px; border-radius: 4px; font-size: 12px; font-weight: bold; display: none; text-align: center;">
                            <!-- Results count will appear here -->
                        </span>

                        <?php
                        // Build a human-readable filter summary banner
                        $filterParts = [];

                        // Teams
                        if (!in_array('all', $teamFilter)) {
                            $teamLabels = [
                                'learning_development'    => 'Learning & Development',
                                'esg'                     => 'ESG',
                                'support'                 => 'Support',
                                'windows'                 => 'Windows',
                                'security'                => 'Security',
                                'secops_abuse'            => 'SecOps/Abuse',
                                'migrations'              => 'Migrations',
                                'Implementations'         => 'Implementations',
                                'Account Services'        => 'Account Services',
                                'Account Services Stellar'=> 'Account Services Stellar',
                            ];
                            $teamNames = array_map(fn($t) => $teamLabels[$t] ?? ucfirst($t), $teamFilter);
                            $filterParts[] = implode(', ', $teamNames);
                        }

                        // Shift
                        if ($shiftFilter !== 'all') {
                            $filterParts[] = getShiftName((int)$shiftFilter);
                        }

                        // Level
                        if ($levelFilter !== 'all') {
                            $filterParts[] = 'Level: ' . ($levelFilter === '' ? 'None' : strtoupper($levelFilter));
                        }

                        // Skills
                        if ($skillsFilter !== 'all') {
                            $skillLabels = ['mh' => 'MH', 'ma' => 'MA', 'win' => 'Win'];
                            $filterParts[] = 'Skill: ' . ($skillLabels[$skillsFilter] ?? ucfirst($skillsFilter));
                        }

                        // Supervisor
                        if ($supervisorFilter !== 'all') {
                            $supEmp = array_filter($employees, fn($e) => $e['id'] == $supervisorFilter);
                            $supEmp = reset($supEmp);
                            $supName = $supEmp ? $supEmp['name'] : 'Supervisor #' . $supervisorFilter;
                            $filterParts[] = 'Supervisor: ' . $supName;
                        }

                        if (!empty($filterParts)):
                        ?>
                        <div id="filterBanner" style="margin-top: 6px; padding: 7px 12px; background: var(--primary-color, #0c5460); color: #fff !important; border-radius: 5px; font-size: 12px; font-weight: 600; line-height: 1.4;">
                            <span style="color: #fff !important;">👁 You are viewing the schedule for </span><strong style="color: #fff !important;"><?php echo escapeHtml(implode(' · ', $filterParts)); ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Team Filter Section -->
                    <?php if (hasPermission('view_all_teams')): ?>
                    <div class="team-filter-section" style="background: var(--card-bg, white); color: var(--text-color, #333); padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <form method="GET" id="teamFilterForm">
                            <!-- Preserve other filters -->
                            <input type="hidden" name="monthSelect" value="<?php echo $currentYear . '-' . $currentMonth; ?>">
                            <input type="hidden" name="shift" value="<?php echo escapeHtml($shiftFilter); ?>">
                            <input type="hidden" name="level" value="<?php echo escapeHtml($levelFilter); ?>">
                            <input type="hidden" name="supervisor" value="<?php echo escapeHtml($supervisorFilter); ?>">
                            <input type="hidden" name="skills" value="<?php echo escapeHtml($skillsFilter); ?>">
                            
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                <!-- Label -->
                                <label style="font-weight: bold; color: var(--text-color, #0c5460); font-size: 14px; white-space: nowrap;">
                                    🏢 Filter by Teams:
                                </label>
                                <div style="font-size: 11px; color: #666; margin-top: -4px; margin-bottom: 4px;">
                                    Hold <strong>Ctrl</strong> (Windows/Linux) or <strong>Cmd</strong> (Mac) to select multiple
                                </div>
                                
                                <!-- Dropdown -->
                                <select name="team[]" id="teamFilter" multiple style="width: 100%; height: 120px; min-height: 120px; max-height: 120px; padding: 5px; border: 2px solid #0c5460; border-radius: 6px; font-size: 13px; resize: none; overflow-y: auto;">
                                    <option value="all" <?php echo in_array('all', $teamFilter) ? 'selected' : ''; ?>>All Teams</option>
                                    <option value="learning_development" <?php echo in_array('learning_development', $teamFilter) ? 'selected' : ''; ?>>Learning & Development</option>
                                    <option value="esg" <?php echo in_array('esg', $teamFilter) ? 'selected' : ''; ?>>ESG</option>
                                    <option value="support" <?php echo in_array('support', $teamFilter) ? 'selected' : ''; ?>>Support</option>
                                    <option value="windows" <?php echo in_array('windows', $teamFilter) ? 'selected' : ''; ?>>Windows</option>
                                    <option value="security" <?php echo in_array('security', $teamFilter) ? 'selected' : ''; ?>>Security</option>
                                    <option value="secops_abuse" <?php echo in_array('secops_abuse', $teamFilter) ? 'selected' : ''; ?>>SecOps/Abuse</option>
                                    <option value="migrations" <?php echo in_array('migrations', $teamFilter) ? 'selected' : ''; ?>>Migrations</option>
                                    <option value="Implementations" <?php echo in_array('Implementations', $teamFilter) ? 'selected' : ''; ?>>Implementations</option>
                                    <option value="Account Services" <?php echo in_array('Account Services', $teamFilter) ? 'selected' : ''; ?>>Account Services</option>
                                    <option value="Account Services Stellar" <?php echo in_array('Account Services Stellar', $teamFilter) ? 'selected' : ''; ?>>Account Services Stellar</option>
                                </select>
                                
                                <!-- Buttons at bottom -->
                                <div style="display: flex; gap: 8px;">
                                    <button type="button" onclick="applyTeamFilter();" style="flex: 1; background: var(--primary-color, #0c5460); color: white; border: none; padding: 8px 12px; border-radius: 4px; font-size: 13px; cursor: pointer; font-weight: bold;">
                                        Apply Filter
                                    </button>
                                    <button type="button" onclick="clearTeamFilter();" style="flex: 1; background: var(--text-muted, #6c757d); color: white; border: none; padding: 8px 12px; border-radius: 4px; font-size: 13px; cursor: pointer; font-weight: bold;">
                                        Clear Filter
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                
                    <!-- Working Today Stats -->
                    <div class="stat-item has-tooltip" style="background: #ffffff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); display: flex; flex-direction: column; align-items: center; justify-content: center; max-width: 300px; min-width: 220px; text-align: center; border: 1px solid var(--border-color, rgba(0,0,0,0.1)); color: var(--text-color, #333);">
                    <?php
                    // Initial calculation (will be updated by JavaScript based on user's timezone)
                    $today = date('w');
                    $todayDate = date('j');
                    $currentTodayYear = date('Y');
                    $currentTodayMonth = date('n') - 1; // 0-indexed month
                    
                    $workingToday = [];
                    
                    // Use filtered employees instead of all employees to respect team/shift/level filters
                    foreach ($filteredEmployees as $emp) {
                        $isWorkingToday = false;
                        
                        // Always check today's overrides (using actual today's date, not viewed month)
                        $overrideKey = $emp['id'] . '-' . $currentTodayYear . '-' . $currentTodayMonth . '-' . $todayDate;
                        
                        if (isset($scheduleOverrides[$overrideKey])) {
                            $override = $scheduleOverrides[$overrideKey];
                            $status = $override['status'];
                            // Only count as working if status is 'on' or 'custom_hours'
                            // Excludes: 'off', 'pto', 'sick', 'half_day_pto', 'half_day_sick', etc.
                            $isWorkingToday = ($status === 'on' || $status === 'custom_hours');
                        } else {
                            // No override - use base schedule
                            $isWorkingToday = ($emp['schedule'][$today] == 1);
                        }
                        
                        if ($isWorkingToday) {
                            $workingToday[] = $emp;
                        }
                    }
                    
                    // Build filter description for tooltip
                    $filterDescription = '';
                    if (!in_array('all', $teamFilter)) {
                        $filterDescription = implode(', ', array_map('strtoupper', $teamFilter));
                    }
                    if ($shiftFilter !== 'all') {
                        $filterDescription .= ($filterDescription ? ' - ' : '') . getShiftName($shiftFilter);
                    }
                    
                    // Group working employees by team/shift, then by level
                    $workingByTeamShift = [];
                    
                    foreach ($workingToday as $emp) {
                        $team = strtoupper($emp['team']);
                        $shift = intval($emp['shift']);
                        $level = getLevelName($emp['level'] ?? '');
                        if (empty($level)) $level = 'Staff';
                        
                        $shiftText = getShiftName($shift);
                        $hours = $emp['hours'] ?? '';
                        
                        // Check for today's override and use its hours if present
                        $overrideKey = $emp['id'] . '-' . $currentTodayYear . '-' . $currentTodayMonth . '-' . $todayDate;
                        if (isset($scheduleOverrides[$overrideKey])) {
                            $override = $scheduleOverrides[$overrideKey];
                            if ($override['status'] === 'custom_hours' && !empty($override['customHours'])) {
                                $hours = $override['customHours'];
                            }
                            // 'on' overrides: use the employee's current base hours (not stale notes value)
                        }
                        
                        // Key format: "shift_number|TEAM" so ksort puts 1st shift
                        // before 2nd before 3rd, and sorts alphabetically by team
                        // within the same shift.
                        $key = sprintf('%d|%s', $shift, $team);
                        if (!isset($workingByTeamShift[$key])) {
                            $workingByTeamShift[$key] = [];
                        }
                        if (!isset($workingByTeamShift[$key][$level])) {
                            $workingByTeamShift[$key][$level] = [];
                        }

                        // Format: Name [Hours]
                        $empDisplay = $emp['name'];
                        if (!empty($hours)) {
                            $empDisplay .= ' [' . $hours . ']';
                        }
                        $workingByTeamShift[$key][$level][] = $empDisplay;
                    }

                    // Sort by shift number first (ksort on "1|ESG" < "2|ESG" < "3|ESG"),
                    // then sort levels and names within each group.
                    ksort($workingByTeamShift);
                    foreach ($workingByTeamShift as $key => &$levelGroups) {
                        ksort($levelGroups);
                        foreach ($levelGroups as $level => &$empList) {
                            sort($empList);
                        }
                    }
                    unset($levelGroups, $empList);
                    ?>
                    <div class="stat-number"><?php echo count($workingToday); ?></div>
                    <div class="stat-label">Working Today<?php echo $filterDescription ? ' <span style="font-size: 11px; opacity: 0.7;">(' . $filterDescription . ')</span>' : ''; ?></div>
                    <div style="font-size: 13px; color: var(--text-muted, #666666); margin-top: 8px; opacity: 0.85;">
                        💡 Use Filters to see who's "Working Today"
                    </div>
                    
                    <?php if (!empty($workingByTeamShift)): ?>
                    <div class="tooltip">
                        <div class="tooltip-header" style="display:flex; align-items:flex-start; justify-content:space-between; gap:8px;">
                            <div>
                                Working Today (<?php echo date('l'); ?>)<?php echo $filterDescription ? ' - ' . $filterDescription : ''; ?>
                                <br><small style="font-weight: normal; font-size: 10px; opacity: 0.75;">Excludes PTO, sick leave &amp; off<?php echo $filterDescription ? ' • Filtered view' : ''; ?></small>
                            </div>
                            <button class="tooltip-close-btn" title="Close" style="background:rgba(255,255,255,0.25); border:none; color:#fff; font-size:16px; line-height:1; padding:2px 7px; border-radius:4px; cursor:pointer; flex-shrink:0; margin-top:2px;">&times;</button>
                        </div>
                        <?php foreach ($workingByTeamShift as $groupKey => $levelGroups):
                            $totalInGroup = 0;
                            foreach ($levelGroups as $empList) {
                                $totalInGroup += count($empList);
                            }
                            // Rebuild display label from "shiftNum|TEAM" key
                            [$_shiftNum, $_teamName] = explode('|', $groupKey, 2);
                            $groupLabel = getShiftName((int)$_shiftNum) . ' — ' . $_teamName;
                        ?>
                        <div class="tooltip-team-group">
                            <div class="tooltip-team" style="font-size: 14px; border-bottom: 1px solid rgba(255,255,255,0.18); padding-bottom: 4px; margin-bottom: 6px;"><?php echo $groupLabel; ?> <span style="opacity:0.75;">(<?php echo $totalInGroup; ?>)</span></div>
                            <?php foreach ($levelGroups as $level => $empList): ?>
                            <div class="tooltip-employees" style="margin-left: 10px; margin-bottom: 5px;">
                                <span style="font-weight: 600; font-size: 12px; opacity: 0.85;"><?php echo $level; ?> (<?php echo count($empList); ?>): </span>
                                <span style="font-size: 12px;"><?php echo implode(', ', $empList); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div><!-- end stat-item -->
                </div><!-- end search/filter/stats flex row -->
            </div>

            <div class="schedule-wrapper">
                <?php if (empty($employees)): ?>
                <div class="empty-state">
                    <h3>No employees added yet</h3>
                    <p>Click "+ Add Employee" to start building your schedule</p>
                    <p style="font-size: 12px; color: #999; margin-top: 10px;">💾 All changes are automatically saved</p>
                </div>
                <?php else: ?>
                
                <table class="schedule-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                                <?php
                                $date = mktime(0, 0, 0, $currentMonth + 1, $day, $currentYear);
                                $dayName = getDayName(date('w', $date));
                                $isTodayColumn = ($isCurrentMonth && $day == $todayDay);
                                ?>
                                <th<?php echo $isTodayColumn ? ' class="today-header"' : ''; ?>>
                                    <?php echo $day; ?><br>
                                    <?php echo $dayName; ?>
                                    <?php if ($isTodayColumn): ?>
                                        <br><span style="font-weight: bold; font-size: 10px; background: rgba(255,255,255,0.3); padding: 2px 6px; border-radius: 3px; display: inline-block; margin-top: 2px;"></span>
                                    <?php endif; ?>
                                </th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $currentUserEmployeeId = $currentEmployee ? $currentEmployee['id'] : null;

                        // Pre-build supervisor name lookup so getSupervisorName() isn't called
                        // N times inside the loop (each call does a linear scan = O(N²) total).
                        $supervisorNameIndex = [];
                        foreach ($employees as $_se) {
                            $supervisorNameIndex[$_se['id']] = $_se['name'];
                        }

                        // Cache edit permission: the result of canEditEmployeeSchedule() is constant
                        // for all 31 cells of a given employee, so we compute it once per employee.
                        $canEditByEmpId = [];
                        foreach ($filteredEmployees as $_fe) {
                            $canEditByEmpId[$_fe['id']] = canEditEmployeeSchedule($_fe['id']);
                        }
                        ?>

                        <?php foreach ($filteredEmployees as $employee): ?>
                        <?php $isOwnEmployee = ($currentUserEmployeeId && $employee['id'] == $currentUserEmployeeId); ?>
                        <?php $canEditCell = $canEditByEmpId[$employee['id']] ?? false; ?>
                        <tr>
                            <td class="employee-name team-<?php echo $employee['team']; ?><?php echo $isOwnEmployee ? ' own-employee' : ''; ?>"
                                onmouseover="if(typeof showEmployeeCard === 'function') showEmployeeCard(this, <?php echo (int)$employee['id']; ?>)"
                                onmouseleave="if(typeof scheduleHideEmployeeCard === 'function' && !event.relatedTarget?.closest?.('#employeeHoverCard')) scheduleHideEmployeeCard()"
                                style="position: relative;">
                                
                                <!-- Employee Action Icons -->
                                <?php
                                // Only show gear icon if user can edit this employee
                                $showGearIcon = false;
                                
                                // Check user role from $currentUser (not session!)
                                $userRole = $currentUser['role'] ?? 'employee';
                                
                                if (in_array($userRole, ['admin', 'manager', 'supervisor'])) {
                                    // Admins, managers, supervisors can see all gear icons
                                    $showGearIcon = true;
                                } elseif ($userRole === 'employee' && $isOwnEmployee) {
                                    // Employees can only see gear icon on their own profile
                                    $showGearIcon = true;
                                }
                                
                                if ($showGearIcon):
                                ?>
                                <div class="employee-actions-icons">
                                    <button onclick="openEditEmployeeInline(<?php echo $employee['id']; ?>); return false;" 
                                       class="employee-gear-link" 
                                       title="Edit Employee Settings"
                                       style="background: none; border: none; cursor: pointer; padding: 0; font-size: inherit; color: inherit;">
                                        ⚙️
                                    </button>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($isOwnEmployee): ?>
                                <div class="employee-indicator">YOU</div>
                                <?php endif; ?>
                                <div class="employee-name-text"><?php echo escapeHtml($employee['name']); ?></div>
                                <div class="team-info"><?php echo strtoupper($employee['team']); ?> - <?php echo getShiftName($employee['shift']); ?></div>
                                <?php if (!empty($employee['level'])): ?>
                                <div class="level-info level-<?php echo preg_replace('/[^a-zA-Z0-9_-]/', '-', $employee['level']); ?>"><?php echo getLevelName($employee['level']); ?></div>
                                <?php endif; ?>
                                <?php
                                // Use pre-built index — no loop per employee
                                $supervisorName = $employee['supervisor_id']
                                    ? ($supervisorNameIndex[$employee['supervisor_id']] ?? 'Unknown')
                                    : 'None';
                                if ($supervisorName !== 'None'):
                                ?>
                                <div class="supervisor-info">👑 Reports to: <?php echo escapeHtml($supervisorName); ?></div>
                                <?php endif; ?>
                                <!-- ADD SKILLS DISPLAY + SLACK LINK -->
<?php
$skillBadges = generateSkillsBadges($employee['skills'] ?? []);
$hasSlackId  = !empty($employee['slack_id']);
if ($skillBadges || $hasSlackId):
?>
<div class="skills-info" style="margin-top: 5px; line-height: 1.2;"><?php echo $skillBadges; ?><?php if ($hasSlackId): ?><a href="https://slack.com/app_redirect?channel=<?php echo htmlspecialchars($employee['slack_id']); ?>&team=T024FSSFY" target="_blank" rel="noopener" onclick="event.stopPropagation();" style="display:inline-block;background:#4A154B;color:#fff !important;font-size:9px;font-weight:600;padding:2px 5px;border-radius:8px;text-decoration:none;margin-left:3px;vertical-align:middle;white-space:nowrap;">DM in Slack</a><?php endif; ?></div>
<?php endif; ?>
                            </td>
                            
                            <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                                <?php
                                $date = mktime(0, 0, 0, $currentMonth + 1, $day, $currentYear);
                                $dayOfWeek = date('w', $date);
                                $isScheduled = $employee['schedule'][$dayOfWeek] == 1;
                                $isTodayCell = ($isCurrentMonth && $day == $todayDay);
                                
                                // Check for overrides
                                $overrideKey = $employee['id'] . '-' . $currentYear . '-' . $currentMonth . '-' . $day;
                                $status = 'off';
                                $statusText = 'OFF';
                                $comment = '';
                                $customHours = '';
                                $title = '';
                                $hasOverride = false;
                                $hasComment = false;
                                
                                if (isset($scheduleOverrides[$overrideKey])) {
                                    $override = $scheduleOverrides[$overrideKey];
                                    $status = $override['status'];
                                    $statusText = getStatusText($status);
                                    $comment = isset($override['comment']) ? $override['comment'] : '';
                                    $customHours = isset($override['customHours']) ? $override['customHours'] : '';
                                    // If customHours not stored separately, try reading from comment/notes
                                    if ($status === 'custom_hours' && empty($customHours) && !empty($comment)) {
                                        $customHours = $comment;
                                    }
                                    $hasOverride = true;
                                    // A comment is only meaningful (and shown) when it is
                                    // a genuine admin/sick note — not when it mirrors the
                                    // hours string stored for custom_hours or 'on' entries.
                                    $onHours = $override['hours'] ?? '';
                                    $hasComment = !empty($comment)
                                        && $comment !== $customHours
                                        && $comment !== $onHours;

                                    if ($status === 'custom_hours') {
                                        $statusText = $customHours ?: $employee['hours'];
                                    } elseif ($status === 'on') {
                                        // 'on' = employee working their normal schedule.
                                        // Always use the employee's current base hours so that
                                        // hour changes are reflected immediately on the schedule
                                        // page without stale values from the notes column.
                                        $statusText = $employee['hours'];
                                    }

                                    // Only render tooltip for genuine comments, not echoed hours strings
                                    if ($hasComment) {
                                        $title = 'title="' . escapeHtml($comment) . '"';
                                    }
                                } elseif ($isScheduled) {
                                    $status = 'on';
                                    $statusText = $employee['hours'];
                                }
                                
                                $cellClasses = 'status-' . $status;
                                if ($isTodayCell) {
                                    $cellClasses .= ' today-cell';
                                }
                                if ($hasComment) {
                                    $cellClasses .= ' has-comment';
                                }
                                if ($isOwnEmployee) {
                                    $cellClasses .= ' own-employee-cell';
                                }
                                // $canEditCell already computed per-employee above — no repeated call here
                                if (!$canEditCell) {
                                    $cellClasses .= ' non-editable-cell';
                                }
                                ?>
                                <td class="<?php echo $cellClasses; ?>"
                                    <?php if ($canEditCell): ?>
                                    data-eid="<?php echo (int)$employee['id']; ?>"
                                    data-day="<?php echo (int)$day; ?>"
                                    data-dow="<?php echo (int)$dayOfWeek; ?>"
                                    <?php if ($hasOverride): ?>
                                    data-ovr="1"
                                    data-ovr-type="<?php echo htmlspecialchars($status, ENT_QUOTES); ?>"
                                    <?php if ($comment !== ''): ?>data-comment="<?php echo htmlspecialchars($comment, ENT_QUOTES); ?>"<?php endif; ?>
                                    <?php if ($customHours !== ''): ?>data-ch="<?php echo htmlspecialchars($customHours, ENT_QUOTES); ?>"<?php endif; ?>
                                    <?php endif; ?>
                                    style="cursor:pointer;"
                                    <?php else: ?>
                                    style="cursor:not-allowed;"
                                    <?php endif; ?>
                                    <?php echo $title; ?>>
                                    <div class="cell-content">
                                        <div class="status-text"><?php echo $statusText; ?></div>
                                    </div>
                                </td>
                            <?php endfor; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php endif; ?>
            </div>

            <!-- Controls Section -->
            <?php if (hasPermission('manage_backups')): ?>
            <div id="backupList" class="backup-list">
                <div style="background: #e7f3ff; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 12px;">
                    
                </div>
                <?php
                if (is_dir(BACKUPS_DIR)) {
                    $backupFiles = glob(BACKUPS_DIR . '/schedule_backup_*.json');
                    rsort($backupFiles);
                    
                    if (empty($backupFiles)) {
                        echo '<p style="color: #666; font-style: italic;">No backup files found. Create your first backup using the button above.</p>';
                    } else {
                        foreach ($backupFiles as $backupPath) {
                            $filename = basename($backupPath);
                            $fileDate = filemtime($backupPath);
                            $fileSize = filesize($backupPath);
                            
                            $backupInfo = json_decode(file_get_contents($backupPath), true);
                            $employeeCount = isset($backupInfo['employees']) ? count($backupInfo['employees']) : 'Unknown';
                            
                            echo '<div class="backup-item">';
                            echo '<div>';
                            echo '<strong>' . escapeHtml($filename) . '</strong><br>';
                            echo '<span class="backup-info">';
                            echo 'Created: ' . date('Y-m-d H:i:s', $fileDate) . ' • '; 
                            echo 'Size: ' . number_format($fileSize) . ' bytes • ';
                            echo 'Employees: ' . $employeeCount;
                            echo '</span>';
                            echo '</div>';
                            echo '<div>';
                            echo '<div class="backup-actions">';
                            echo '<form method="POST" style="display: inline;" target="_blank">';
                            echo '<input type="hidden" name="action" value="download_backup_file">';
                            echo '<input type="hidden" name="backupFilename" value="' . escapeHtml($filename) . '">';
                            echo '<button type="submit" style="background: none; border: none; color: #007bff; text-decoration: underline; cursor: pointer; font-size: 12px;">📥 Download</button>';
                            echo '</form>';
                            echo '<form method="POST" style="display: inline;" onsubmit="return confirm(\'Delete backup: ' . escapeHtml($filename) . '?\');">';
                            echo '<input type="hidden" name="action" value="delete_backup">';
                            echo '<input type="hidden" name="backupFilename" value="' . escapeHtml($filename) . '">';
                            echo '<button type="submit" style="background: #dc3545; color: white; border: none; border-radius: 3px; padding: 2px 6px; font-size: 10px; cursor: pointer;" title="Delete Backup">🗑️ Delete</button>';
                            echo '</form>';
                            echo '</div>';
                            echo '</div>';
                            echo '</div>';
                        }
                    }
                }
                ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Profile Tab -->
        <div id="profile-tab" class="tab-content<?php echo ($activeTab === 'profile') ? ' active' : ''; ?>">
            <div style="width: 98%; margin: 0 auto; padding: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="margin: 0; color: var(--text-color, #333);">👤 My Profile</h2>
                    <button onclick="showTab('schedule')" style="background: var(--secondary-color, #6c757d); color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">← Back to Schedule</button>
                </div>
                
                <?php 
                $currentUser = getCurrentUser();
                $linkedEmployee = getCurrentUserEmployee();
                $profilePhotoUrl = getProfilePhotoUrl($currentUser);
                $authMethodUsed = $_SESSION['auth_method_used'] ?? 'password';
                $userAuthMethod = $currentUser['auth_method'] ?? 'password';
                ?>
                
                <!-- Profile Form - INLINE VERSION -->
                <div style="background: var(--card-bg, white); padding: 20px; border-radius: 8px; border: 1px solid var(--border-color, #dee2e6); box-shadow: 0 2px 4px rgba(0,0,0,0.1); color: var(--text-color, #333);">
                    <form method="POST" id="inlineProfileForm" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="current_tab" value="profile">
                        
                        <!-- Two Column Layout -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            
                            <!-- LEFT COLUMN -->
                            <div>
                                <!-- Profile Photo -->
                                <div style="margin-bottom: 15px; text-align: center;">
                                    <label style="font-weight: bold; margin-bottom: 8px; display: block; color: var(--text-color, #333);">Profile Photo:</label>
                                    <div style="display: inline-block;">
                                        <?php $profileInitials = strtoupper(substr($currentUser['full_name'], 0, 2)); ?>
                                        <?php if ($profilePhotoUrl): ?>
                                            <img src="<?php echo htmlspecialchars($profilePhotoUrl); ?>" alt="Profile Photo" id="currentProfilePhoto" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 3px solid var(--primary-color, #007bff); margin-bottom: 10px;"
                                                 onerror="this.style.display='none';document.getElementById('currentProfilePhotoBg').style.display='flex';">
                                            <div id="currentProfilePhotoBg" style="display:none;width:120px;height:120px;border-radius:50%;background:var(--primary-color,#007bff);color:white;align-items:center;justify-content:center;font-size:48px;font-weight:bold;margin:0 auto 10px;"><?php echo $profileInitials; ?></div>
                                        <?php else: ?>
                                            <div id="currentProfilePhoto" style="width: 120px; height: 120px; border-radius: 50%; background: var(--primary-color, #007bff); color: white !important; display: flex; align-items: center; justify-content: center; font-size: 48px; font-weight: bold; margin: 0 auto 10px;">
                                                <?php echo $profileInitials; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 10px; color: var(--text-muted, #666); margin-top: 6px;">Profile photo is synced from your Google account.</div>
                                </div>
                                
                                <!-- Full Name -->
                                <div style="margin-bottom: 12px;">
                                    <label style="font-weight: bold; margin-bottom: 5px; display: block; color: var(--text-color, #333);">Full Name:</label>
                                    <input type="text" name="profile_full_name" id="inline_profile_full_name" value="<?php echo htmlspecialchars($currentUser['full_name']); ?>" required style="width: 100%; padding: 8px; border: 1px solid var(--border-color, #dee2e6); border-radius: 4px; background: var(--card-bg, white); color: var(--text-color, #333);">
                                </div>
                                
                                <!-- Email -->
                                <div style="margin-bottom: 12px;">
                                    <label style="font-weight: bold; margin-bottom: 5px; display: block; color: var(--text-color, #333);">📧 Email:</label>
                                    <input type="email" name="profile_email" id="inline_profile_email" value="<?php echo htmlspecialchars($currentUser['email']); ?>" required style="width: 100%; padding: 8px; border: 1px solid var(--border-color, #dee2e6); border-radius: 4px; background: var(--card-bg, white); color: var(--text-color, #333);">
                                </div>
                                
                                <!-- Username -->
                                <div style="margin-bottom: 12px;">
                                    <label style="font-weight: bold; margin-bottom: 5px; display: block; color: var(--text-color, #333);">Username:</label>
                                    <input type="text" value="<?php echo htmlspecialchars($currentUser['username']); ?>" disabled style="width: 100%; padding: 8px; border: 1px solid var(--border-color, #dee2e6); border-radius: 4px; background: var(--secondary-bg, #f8f9fa); color: var(--text-muted, #6c757d);">
                                    <div style="font-size: 10px; color: var(--text-muted, #666); margin-top: 3px;">Username cannot be changed</div>
                                </div>

                                <!-- Slack Member ID -->
                                <div style="margin-bottom: 12px; background: var(--secondary-bg, #f8f9fa); border: 1px solid var(--border-color, #dee2e6); border-radius: 6px; padding: 12px;">
                                    <label for="inline_profile_slack_id" style="font-weight: bold; margin-bottom: 5px; display: flex; align-items: center; gap: 6px; color: var(--text-color, #333);">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="#4A154B"><path d="M5.042 15.165a2.528 2.528 0 0 1-2.52 2.523A2.528 2.528 0 0 1 0 15.165a2.527 2.527 0 0 1 2.522-2.52h2.52v2.52zM6.313 15.165a2.527 2.527 0 0 1 2.521-2.52 2.527 2.527 0 0 1 2.521 2.52v6.313A2.528 2.528 0 0 1 8.834 24a2.528 2.528 0 0 1-2.521-2.522v-6.313zM8.834 5.042a2.528 2.528 0 0 1-2.521-2.52A2.528 2.528 0 0 1 8.834 0a2.528 2.528 0 0 1 2.521 2.522v2.52H8.834zM8.834 6.313a2.528 2.528 0 0 1 2.521 2.521 2.528 2.528 0 0 1-2.521 2.521H2.522A2.528 2.528 0 0 1 0 8.834a2.528 2.528 0 0 1 2.522-2.521h6.312zM18.956 8.834a2.528 2.528 0 0 1 2.522-2.521A2.528 2.528 0 0 1 24 8.834a2.528 2.528 0 0 1-2.522 2.521h-2.522V8.834zM17.688 8.834a2.528 2.528 0 0 1-2.523 2.521 2.527 2.527 0 0 1-2.52-2.521V2.522A2.527 2.527 0 0 1 15.165 0a2.528 2.528 0 0 1 2.523 2.522v6.312zM15.165 18.956a2.528 2.528 0 0 1 2.523 2.522A2.528 2.528 0 0 1 15.165 24a2.527 2.527 0 0 1-2.52-2.522v-2.522h2.52zM15.165 17.688a2.527 2.527 0 0 1-2.52-2.523 2.526 2.526 0 0 1 2.52-2.52h6.313A2.527 2.527 0 0 1 24 15.165a2.528 2.528 0 0 1-2.522 2.523h-6.313z"/></svg>
                                        Slack Member ID
                                    </label>
                                    <input type="text"
                                           name="profile_slack_id"
                                           id="inline_profile_slack_id"
                                           value="<?php echo htmlspecialchars($currentUser['slack_id'] ?? ''); ?>"
                                           placeholder="e.g. U01AB2CD3EF"
                                           maxlength="50"
                                           style="width: 100%; padding: 8px; border: 1px solid var(--border-color, #dee2e6); border-radius: 4px; background: var(--card-bg, white); color: var(--text-color, #333); font-family: monospace;">
                                    <div style="font-size: 10px; color: var(--text-muted, #666); margin-top: 6px; line-height: 1.6;">
                                        <strong>How to find your Slack Member ID:</strong><br>
                                        1. Open Slack → click your <strong>profile picture</strong> in the sidebar.<br>
                                        2. Click <strong>Profile</strong>, then the <strong>⋯ More</strong> button (three dots).<br>
                                        3. Click <strong>"Copy member ID"</strong> — it starts with <code style="background:#e9ecef;padding:1px 3px;border-radius:2px;">U</code> (e.g. <code style="background:#e9ecef;padding:1px 3px;border-radius:2px;">U01AB2CD3EF</code>).<br>
                                        4. Paste it above and click <strong>Update Profile</strong>.<br>
                                        <span style="color:#27ae60;">✅ Saves a clickable Slack link on the schedule for your team to reach you.</span>
                                    </div>
                                </div>
                            </div>

                            <!-- RIGHT COLUMN -->
                            <div>
                                <!-- Account Information -->
                                <div style="background: var(--secondary-bg, #f8f9fa); padding: 15px; border-radius: 6px; margin-bottom: 15px; border: 1px solid var(--border-color, #dee2e6);">
                                    <h3 style="margin: 0 0 12px 0; font-size: 14px; color: var(--text-color, #495057);">🔐 Account Information</h3>
                                    
                                    <div style="margin-bottom: 10px;">
                                        <span style="font-weight: bold; font-size: 12px; color: var(--text-color, #333);">Role:</span>
                                        <span style="background: var(--primary-color, #007bff); color: white !important; padding: 3px 8px; border-radius: 12px; font-size: 11px; margin-left: 5px;">
                                            <?php echo ucfirst($currentUser['role']); ?>
                                        </span>
                                    </div>

                                    <div style="margin-bottom: 10px;">
                                        <span style="font-weight: bold; font-size: 12px; color: var(--text-color, #333);">Team Access:</span>
                                        <span style="background: var(--success-color, #28a745); color: white !important; padding: 3px 8px; border-radius: 12px; font-size: 11px; margin-left: 5px;">
                                            <?php echo strtoupper($currentUser['team']); ?>
                                        </span>
                                    </div>

                                    <div style="margin-bottom: 10px;">
                                        <span style="font-weight: bold; font-size: 12px; color: var(--text-color, #333);">Authentication:</span>
                                        <span style="background: <?php echo ($authMethodUsed === 'google') ? '#EA4335' : 'var(--text-muted, #6c757d)'; ?>; color: white !important; padding: 3px 8px; border-radius: 12px; font-size: 11px; margin-left: 5px;">
                                            <?php if ($authMethodUsed === 'google'): ?>
                                                🔑 Google SSO
                                            <?php else: ?>
                                                🔒 Password
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    
                                    <div style="margin-bottom: 10px;">
                                        <span style="font-weight: bold; font-size: 12px; color: var(--text-color, #333);">Allowed Auth Methods:</span>
                                        <span style="font-size: 11px; margin-left: 5px; color: var(--text-color, #333);">
                                            <?php 
                                            if ($userAuthMethod === 'both') {
                                                echo '🔑 Google SSO & 🔒 Password';
                                            } elseif ($userAuthMethod === 'google') {
                                                echo '🔑 Google SSO Only';
                                            } else {
                                                echo '🔒 Password Only';
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    
                                    <div>
                                        <span style="font-weight: bold; font-size: 12px; color: var(--text-color, #333);">Member Since:</span>
                                        <span style="font-size: 11px; margin-left: 5px; color: var(--text-color, #333);"><?php echo date('M j, Y', strtotime($currentUser['created_at'])); ?></span>
                                    </div>

                                    <?php if ($linkedEmployee && !empty($linkedEmployee['start_date'])): ?>
                                    <?php
                                        $profStartDate = new DateTime($linkedEmployee['start_date']);
                                        $profToday     = new DateTime();
                                        $profInterval  = $profStartDate->diff($profToday);
                                        $profYears     = $profInterval->y;
                                        $profMonths    = $profInterval->m;
                                        $profDays      = $profInterval->days;
                                        $profNext      = new DateTime($linkedEmployee['start_date']);
                                        $profNext->setDate((int)$profToday->format('Y'), (int)$profStartDate->format('m'), (int)$profStartDate->format('d'));
                                        if ($profNext < $profToday) $profNext->modify('+1 year');
                                        $profDaysUntil = $profToday->diff($profNext)->days;
                                    ?>
                                    <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border-color, #dee2e6);">
                                        <span style="font-weight: bold; font-size: 12px; color: var(--text-color, #333);">📅 Start Date:</span>
                                        <span style="font-size: 11px; margin-left: 5px; color: var(--text-color, #333);"><?php echo $profStartDate->format('M j, Y'); ?></span>
                                    </div>
                                    <div style="margin-top: 6px;">
                                        <span style="font-weight: bold; font-size: 12px; color: var(--text-color, #333);">⏳ Time with Company:</span>
                                        <span style="font-size: 11px; margin-left: 5px; color: var(--text-color, #333);">
                                            <?php
                                            if ($profYears > 0) {
                                                echo $profYears . ' yr' . ($profYears != 1 ? 's' : '');
                                                if ($profMonths > 0) echo ', ' . $profMonths . ' mo';
                                            } elseif ($profMonths > 0) {
                                                echo $profMonths . ' mo';
                                            } else {
                                                echo $profDays . ' day' . ($profDays != 1 ? 's' : '');
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    <div style="margin-top: 6px;">
                                        <span style="font-weight: bold; font-size: 12px; color: var(--text-color, #333);">🎉 Next Anniversary:</span>
                                        <span style="font-size: 11px; margin-left: 5px; color: <?php echo $profDaysUntil <= 30 ? '#22c55e' : 'var(--text-color, #333)'; ?>; font-weight: <?php echo $profDaysUntil <= 30 ? '700' : 'normal'; ?>;">
                                            <?php echo $profNext->format('M j, Y'); ?>
                                            <?php if ($profDaysUntil === 0): ?> 🎂 Today!
                                            <?php elseif ($profDaysUntil <= 30): ?> (<?php echo $profDaysUntil; ?> day<?php echo $profDaysUntil != 1 ? 's' : ''; ?> away)
                                            <?php else: ?> (in <?php echo $profDaysUntil; ?> days)
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- API Key — admin / manager / supervisor only -->
                                <?php if ($isAdminOrSupervisor): ?>
                                <div style="background: var(--secondary-bg, #f8f9fa); padding: 15px; border-radius: 6px; margin-bottom: 15px; border: 1px solid var(--border-color, #dee2e6);">
                                    <h3 style="margin: 0 0 12px 0; font-size: 14px; color: var(--text-color, #495057);">🔑 API Key</h3>
                                    <p style="font-size: 11px; color: var(--text-muted, #6c757d); margin: 0 0 10px 0;">Use this key to authenticate with the REST API via the <code>X-API-Key</code> header.</p>

                                    <?php if (!empty($currentUser['api_key'])): ?>
                                    <div style="margin-bottom: 10px;">
                                        <span style="font-weight: bold; font-size: 12px; color: var(--text-color, #333);">Current Key:</span>
                                        <div style="display: flex; align-items: center; gap: 6px; margin-top: 5px;">
                                            <input type="text" id="profileApiKeyDisplay"
                                                   value="<?php echo htmlspecialchars($currentUser['api_key']); ?>"
                                                   readonly
                                                   style="flex: 1; font-family: monospace; font-size: 11px; padding: 5px 8px; border: 1px solid var(--border-color, #dee2e6); border-radius: 4px; background: var(--card-bg, #fff); color: var(--text-color, #333);">
                                            <button type="button" onclick="copyProfileApiKey()" title="Copy key" style="padding: 5px 8px; font-size: 11px; border-radius: 4px; white-space: nowrap;">📋 Copy</button>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <p style="font-size: 11px; color: var(--text-muted, #6c757d); font-style: italic; margin: 0 0 10px 0;">No API key generated yet.</p>
                                    <?php endif; ?>

                                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                        <button type="button" onclick="generateProfileApiKey()" style="font-size: 12px; padding: 6px 14px; border-radius: 4px;">
                                            <?php echo !empty($currentUser['api_key']) ? '🔄 Regenerate Key' : '✨ Generate Key'; ?>
                                        </button>
                                        <?php if (!empty($currentUser['api_key'])): ?>
                                        <button type="button" onclick="revokeProfileApiKey()" style="font-size: 12px; padding: 6px 14px; border-radius: 4px; background: var(--danger-color, #dc3545) !important; border-color: var(--danger-color, #dc3545) !important; color: white !important;">
                                            🗑️ Revoke Key
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <div id="profileApiKeyMsg" style="margin-top: 8px; font-size: 11px;"></div>
                                </div>
                                <?php endif; // isAdminOrSupervisor — API Key ?>

                                <!-- Linked Employee -->
                                <?php if ($linkedEmployee): ?>
                                <div style="background: var(--secondary-bg, #e7f3ff); padding: 15px; border-radius: 6px; margin-bottom: 15px; border: 1px solid var(--border-color, #b3d9ff);">
                                    <h3 style="margin: 0 0 12px 0; font-size: 14px; color: var(--text-color, #004085);">👷 Linked Employee Record</h3>
                                    
                                    <div style="margin-bottom: 8px;">
                                        <span style="font-weight: bold; font-size: 12px; color: var(--text-color, #333);">Name:</span>
                                        <span style="font-size: 12px; margin-left: 5px; color: var(--text-color, #333);"><?php echo htmlspecialchars($linkedEmployee['name']); ?></span>
                                    </div>
                                    
                                    <div style="margin-bottom: 8px;">
                                        <span style="font-weight: bold; font-size: 12px; color: var(--text-color, #333);">Team:</span>
                                        <span style="background: var(--primary-color, #333399); color: white !important; padding: 2px 6px; border-radius: 10px; font-size: 10px; margin-left: 5px;">
                                            <?php echo strtoupper($linkedEmployee['team']); ?>
                                        </span>
                                    </div>
                                    
                                    <div style="margin-bottom: 8px;">
                                        <span style="font-weight: bold; font-size: 12px; color: var(--text-color, #333);">Shift:</span>
                                        <span style="font-size: 11px; margin-left: 5px; color: var(--text-color, #333);"><?php echo getShiftName($linkedEmployee['shift']); ?></span>
                                    </div>
                                    
                                    <?php if (!empty($linkedEmployee['level'])): ?>
                                    <div style="margin-bottom: 8px;">
                                        <span style="font-weight: bold; font-size: 12px; color: var(--text-color, #333);">Level:</span>
                                        <span style="background: var(--warning-color, #ffc107); color: white !important; padding: 2px 6px; border-radius: 10px; font-size: 10px; margin-left: 5px;">
                                            <?php echo getLevelName($linkedEmployee['level']); ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>

                                    <?php
                                    // Schedule-based status for linked employee
                                    ?>
                                    <div>
                                        <span style="font-weight: bold; font-size: 12px; color: var(--text-color, #333);">Hours:</span>
                                        <span style="font-size: 11px; margin-left: 5px; color: var(--text-color, #333);"><?php echo htmlspecialchars($linkedEmployee['hours']); ?></span>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div style="background: var(--secondary-bg, #fff3cd); padding: 15px; border-radius: 6px; margin-bottom: 15px; border: 1px solid var(--border-color, #ffeaa7);">
                                    <p style="margin: 0; font-size: 12px; color: var(--text-color, #856404);">
                                        <strong>ℹ️ Not Linked:</strong> Your account is not linked to an employee schedule record.
                                    </p>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Change Password Section -->
                                <?php if ($userAuthMethod === 'password' || $userAuthMethod === 'both'): ?>
                                <div style="background: var(--card-bg, #fff); padding: 15px; border-radius: 6px; border: 1px solid var(--border-color, #dee2e6);">
                                    <h3 style="margin: 0 0 12px 0; font-size: 14px; color: var(--text-color, #495057);">🔑 Change Password</h3>
                                    
                                    <div style="margin-bottom: 10px;">
                                        <label style="font-weight: bold; margin-bottom: 5px; display: block; font-size: 12px; color: var(--text-color, #333);">Current Password:</label>
                                        <input type="password" name="current_password" id="inline_current_password" autocomplete="current-password" style="width: 100%; padding: 6px; border: 1px solid var(--border-color, #dee2e6); border-radius: 4px; font-size: 12px; background: var(--card-bg, white); color: var(--text-color, #333);">
                                    </div>
                                    
                                    <div style="margin-bottom: 10px;">
                                        <label style="font-weight: bold; margin-bottom: 5px; display: block; font-size: 12px; color: var(--text-color, #333);">New Password:</label>
                                        <input type="password" name="new_password" id="inline_new_password" autocomplete="new-password" style="width: 100%; padding: 6px; border: 1px solid var(--border-color, #dee2e6); border-radius: 4px; font-size: 12px; background: var(--card-bg, white); color: var(--text-color, #333);">
                                    </div>
                                    
                                    <div style="font-size: 10px; color: var(--text-muted, #666);">Leave blank to keep current password</div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Theme Selector -->
                        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border-color, #dee2e6);">
                            <h3 style="margin: 0 0 14px 0; font-size: 14px; color: var(--text-color, #495057);">🎨 Interface Theme</h3>
                            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                <?php
                                $profileThemes = [
                                    'default'  => ['label' => 'Default',        'color' => '#007bff'],
                                    'ocean'    => ['label' => 'Ocean Blue',      'color' => '#0277bd'],
                                    'forest'   => ['label' => 'Forest Green',   'color' => '#2e7d32'],
                                    'sunset'   => ['label' => 'Sunset Orange',  'color' => '#e65100'],
                                    'royal'    => ['label' => 'Royal Purple',   'color' => '#6a1b9a'],
                                    'dark'     => ['label' => 'Dark Mode',      'color' => '#263238'],
                                    'crimson'  => ['label' => 'Crimson Red',    'color' => '#b71c1c'],
                                    'teal'     => ['label' => 'Teal Cyan',      'color' => '#00695c'],
                                    'amber'    => ['label' => 'Amber Gold',     'color' => '#f57f17'],
                                    'slate'    => ['label' => 'Slate Gray',     'color' => '#455a64'],
                                    'emerald'  => ['label' => 'Emerald',        'color' => '#1b5e20'],
                                    'midnight' => ['label' => 'Midnight Blue',  'color' => '#1a237e'],
                                    'rose'     => ['label' => 'Rose Pink',      'color' => '#880e4f'],
                                    'copper'   => ['label' => 'Copper Bronze',  'color' => '#bf360c'],
                                ];
                                foreach ($profileThemes as $key => $theme): ?>
                                <button type="button"
                                    onclick="handleThemeSelection('<?php echo $key; ?>'); document.querySelectorAll('.theme-swatch-btn').forEach(b=>b.style.outline='none'); this.style.outline='3px solid '+this.dataset.color; this.style.outlineOffset='2px';"
                                    class="theme-swatch-btn"
                                    data-theme="<?php echo $key; ?>"
                                    data-color="<?php echo $theme['color']; ?>"
                                    title="<?php echo $theme['label']; ?>"
                                    style="display:flex; align-items:center; gap:6px; padding:6px 12px; border-radius:20px; border:2px solid #dee2e6; background:#ffffff; cursor:pointer; font-size:12px; color:#333333; transition:all 0.15s;">
                                    <span style="width:14px; height:14px; border-radius:50%; background:<?php echo $theme['color']; ?>; display:inline-block; flex-shrink:0;"></span>
                                    <?php echo $theme['label']; ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <p style="margin: 8px 0 0 0; font-size: 11px; color: var(--text-muted, #888);">Theme is applied instantly and saved for your account.</p>
                        </div>

                        <!-- Submit Button -->
                        <div style="text-align: center; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border-color, #dee2e6);">
                            <button type="submit" style="padding: 10px 24px; font-size: 14px; font-weight: bold; background: var(--primary-color, #007bff) !important; color: white !important; border: none; border-radius: 4px; cursor: pointer;">💾 Update Profile</button>
                            <button type="button" onclick="showTab('schedule')" style="background: var(--text-muted, #6c757d) !important; color: white !important; border: none; margin-left: 10px; padding: 10px 24px; border-radius: 4px; cursor: pointer;">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Bulk Schedule Tab (Inline) -->
        <?php if (hasPermission('edit_schedule')): ?>
        <div id="bulk-schedule-tab" class="tab-content<?php echo ($activeTab === 'bulk-schedule') ? ' active' : ''; ?>">
        <style>
        /* ── Bulk Schedule page — scoped .sr reuse ── */
        .sr *, .sr *::before, .sr *::after { box-sizing: border-box; }
        .sr {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--body-bg, #f8f9fa);
            color: var(--text-color, #212529);
            padding: 28px 32px;
        }
        .sr-page-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:28px; flex-wrap:wrap; }
        .sr-page-title  { display:flex; align-items:center; gap:10px; }
        .sr-page-title h2 { font-size:22px; font-weight:700; color:var(--text-color,#212529); margin:0; }
        .sr-page-subtitle { font-size:13px; color:var(--text-muted,#6c757d); margin-top:3px; }
        .sr-page-actions  { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .sr-btn {
            display:inline-flex; align-items:center; gap:7px;
            padding:9px 18px; border-radius:8px; font-size:13.5px;
            font-weight:600; border:none; cursor:pointer;
            transition:all 0.18s ease; white-space:nowrap;
            font-family:inherit; line-height:1; text-decoration:none;
        }
        .sr-btn-primary { background:var(--primary-color,#007bff); color:#fff; }
        .sr-btn-primary:hover { filter:brightness(1.1); transform:translateY(-1px); box-shadow:0 4px 12px rgba(0,0,0,0.2); }
        .sr-btn-ghost { background:transparent; color:var(--text-muted,#6c757d); border:1.5px solid var(--border-color,#dee2e6); }
        .sr-btn-ghost:hover { background:var(--surface-color,#f8f9fa); border-color:var(--text-muted,#6c757d); color:var(--text-color,#212529); }
        .sr-btn-success { background:#27ae60; color:#fff; border:none; }
        .sr-btn-success:hover { filter:brightness(1.08); transform:translateY(-1px); }
        .sr-btn-sm { padding:6px 12px; font-size:12px; }
        .sr-btn:disabled { opacity:0.45; cursor:not-allowed; transform:none !important; }
        .sr-section { margin-bottom:24px; }
        .sr-section-header { display:flex; align-items:center; gap:9px; margin-bottom:16px; }
        .sr-section-header h3 { font-size:16px; font-weight:700; color:var(--text-color,#212529); margin:0; }
        .sr-section-divider { flex:1; height:1px; background:var(--border-color,#dee2e6); margin-left:8px; }
        .sr-card {
            background:var(--card-bg,#fff); border:1px solid var(--border-color,#dee2e6);
            border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,0.06);
            overflow:hidden; transition:box-shadow 0.18s ease,transform 0.18s ease;
            margin-bottom:20px;
        }
        .sr-card-accented { border-top:3px solid var(--primary-color,#007bff); }
        .sr-card-head {
            padding:18px 22px 14px; border-bottom:1px solid var(--border-color,#dee2e6);
            display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
        }
        .sr-card-head-left { display:flex; align-items:center; gap:12px; }
        .sr-icon-badge {
            width:40px; height:40px; border-radius:10px; display:flex;
            align-items:center; justify-content:center; font-size:18px;
            flex-shrink:0; background:var(--primary-color,#007bff); color:#fff; opacity:0.9;
        }
        .sr-icon-badge.soft { background:color-mix(in srgb,var(--primary-color,#007bff) 12%,transparent); }
        .sr-card-title { font-size:15px; font-weight:700; color:var(--text-color,#212529); }
        .sr-card-sub   { font-size:12px; color:var(--text-muted,#6c757d); margin-top:2px; }
        .sr-card-body  { padding:22px; }
        .sr-two-col { display:grid; grid-template-columns:1fr 1fr; gap:24px; }
        .sr-lbl { font-size:12px; font-weight:600; color:var(--text-color,#495057); display:block; margin-bottom:5px; }
        .sr-ctrl {
            padding:9px 12px; border:1.5px solid var(--border-color,#dee2e6);
            border-radius:7px; font-size:13.5px; font-family:inherit;
            color:var(--text-color,#212529); background:var(--card-bg,#fff);
            transition:border-color 0.2s,box-shadow 0.2s; width:100%;
        }
        .sr-ctrl:focus { outline:none; border-color:var(--primary-color,#007bff); box-shadow:0 0 0 3px color-mix(in srgb,var(--primary-color,#007bff) 12%,transparent); }
        .sr-fg { display:flex; flex-direction:column; gap:5px; margin-bottom:14px; }
        .sr-hint { font-size:11.5px; color:var(--text-muted,#6c757d); margin-top:3px; }
        .sr-date-pair { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .sr-check-block {
            background:color-mix(in srgb,var(--primary-color,#007bff) 6%,transparent);
            border:1px solid color-mix(in srgb,var(--primary-color,#007bff) 22%,transparent);
            border-radius:9px; padding:14px 16px; margin-bottom:14px;
        }
        .sr-check-block.amber {
            background:color-mix(in srgb,#f39c12 8%,transparent);
            border-color:color-mix(in srgb,#f39c12 30%,transparent);
        }
        .sr-check-row { display:flex; align-items:center; gap:9px; cursor:pointer; font-size:13px; font-weight:600; }
        .sr-check-row input[type=checkbox],.sr-check-row input[type=radio] { width:16px; height:16px; accent-color:var(--primary-color,#007bff); cursor:pointer; margin:0; flex-shrink:0; }
        .sr-sched-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:6px; text-align:center; margin:10px 0 8px; }
        .sr-sched-day { font-size:11px; font-weight:700; color:var(--text-muted,#6c757d); text-transform:uppercase; padding-bottom:2px; }
        .sr-sched-grid input[type=checkbox] { width:18px; height:18px; accent-color:var(--primary-color,#007bff); cursor:pointer; margin:0; }
        .sr-preset-row { display:flex; gap:6px; flex-wrap:wrap; }
        .sr-preset { padding:5px 11px; font-size:11px; font-weight:700; border:none; border-radius:5px; cursor:pointer; font-family:inherit; }
        .sr-warn { background:color-mix(in srgb,#e67e22 10%,transparent); border:1px solid color-mix(in srgb,#e67e22 35%,transparent); border-radius:9px; padding:13px 16px; font-size:12.5px; margin:16px 0 0; }
        .sr-warn strong { color:#e67e22; }
        .sr-impact { margin-top:6px; font-weight:700; color:var(--primary-color,#007bff); font-size:13px; }
        .sr-emp-wrap { border:1.5px solid var(--border-color,#dee2e6); border-radius:10px; overflow:hidden; }
        .sr-emp-top { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; background:color-mix(in srgb,var(--primary-color,#007bff) 6%,transparent); border-bottom:1px solid var(--border-color,#dee2e6); }
        .sr-emp-top label { display:flex; align-items:center; gap:8px; font-weight:700; font-size:13px; cursor:pointer; }
        .sr-emp-top input[type=checkbox] { width:16px; height:16px; accent-color:var(--primary-color,#007bff); margin:0; }
        .sr-emp-count { font-size:11px; color:var(--text-muted,#6c757d); }
        .sr-emp-search { padding:10px 14px; border-bottom:1px solid var(--border-color,#dee2e6); background:var(--card-bg,#fff); }
        .sr-emp-search input { width:100%; padding:8px 12px; border:1.5px solid var(--border-color,#dee2e6); border-radius:7px; font-size:13px; font-family:inherit; background:var(--body-bg,#f8f9fa); color:var(--text-color,#212529); }
        .sr-emp-search input:focus { outline:none; border-color:var(--primary-color,#007bff); }
        .sr-emp-list { max-height:480px; overflow-y:auto; background:var(--body-bg,#f8f9fa); }
        .sr-emp-item { display:flex; align-items:center; gap:10px; padding:9px 14px; border-bottom:1px solid var(--border-color,#dee2e6); cursor:pointer; transition:background 0.12s; }
        .sr-emp-item:last-child { border-bottom:none; }
        .sr-emp-item:hover { background:color-mix(in srgb,var(--primary-color,#007bff) 7%,transparent); }
        .sr-emp-item input[type=checkbox] { width:16px; height:16px; accent-color:var(--primary-color,#007bff); margin:0; flex-shrink:0; }
        .sr-emp-name { font-size:13px; font-weight:600; color:var(--text-color,#212529); }
        .sr-emp-meta { font-size:11px; color:var(--text-muted,#6c757d); }
        .sr-submit-row { display:flex; gap:12px; justify-content:center; padding-top:20px; border-top:1px solid var(--border-color,#dee2e6); margin-top:20px; }
        .sr-step-badge { display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--primary-color,#007bff); color:#fff; font-size:11px; font-weight:700; flex-shrink:0; }
        .sr-code { background:color-mix(in srgb,var(--primary-color,#007bff) 10%,transparent); color:var(--primary-color,#007bff); padding:2px 7px; border-radius:4px; font-family:monospace; font-size:11px; }
        .sr-collapsible { display:none; margin-top:12px; }
        .sr-collapsible.open { display:block; }
        .sr-toggle-btn { width:100%; text-align:left; background:var(--body-bg,#f1f5f9); color:var(--text-color,#1e293b); border:1.5px solid var(--border-color,#dee2e6); padding:9px 14px; border-radius:7px; cursor:pointer; font-size:12.5px; font-weight:600; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center; font-family:inherit; transition:background 0.15s; }
        .sr-toggle-btn:hover { background:color-mix(in srgb,var(--primary-color,#007bff) 8%,transparent); border-color:var(--primary-color,#007bff); }
        @media(max-width:860px) { .sr { padding:20px 16px; } .sr-two-col { grid-template-columns:1fr; } }
        </style>

        <div class="sr">

            <!-- Page Header -->
            <div class="sr-page-header">
                <div>
                    <div class="sr-page-title">
                        <span style="font-size:26px;">📋</span>
                        <div>
                            <h2>Bulk Schedule Changes</h2>
                            <div class="sr-page-subtitle">Apply schedule changes to multiple employees across a date range</div>
                        </div>
                    </div>
                </div>
                <div class="sr-page-actions">
                    <button onclick="showTab('schedule')" class="sr-btn sr-btn-ghost">← Back to Schedule</button>
                </div>
            </div>

            <!-- ── Apply Changes Card ── -->
            <div class="sr-card sr-card-accented">
                <div class="sr-card-head">
                    <div class="sr-card-head-left">
                        <div class="sr-icon-badge">⚡</div>
                        <div>
                            <div class="sr-card-title">Apply Changes</div>
                            <div class="sr-card-sub">Set dates, status, and employees to update</div>
                        </div>
                    </div>
                </div>
                <div class="sr-card-body">
                <form method="POST" id="inlineBulkForm" onsubmit="return submitInlineForm(this, 'bulk-schedule');">
                    <input type="hidden" name="action" value="bulk_schedule_change">

                    <div class="sr-two-col">

                        <!-- LEFT: Settings -->
                        <div>

                            <!-- Date Range -->
                            <div class="sr-fg">
                                <label class="sr-lbl">Date Range</label>
                                <div class="sr-date-pair">
                                    <div>
                                        <span class="sr-hint" style="display:block;margin-bottom:4px;">Start</span>
                                        <input type="date" name="startDate" id="bulkStartDate" required class="sr-ctrl"
                                               onchange="if(typeof validateBulkForm==='function') validateBulkForm();">
                                    </div>
                                    <div>
                                        <span class="sr-hint" style="display:block;margin-bottom:4px;">End</span>
                                        <input type="date" name="endDate" id="bulkEndDate" required class="sr-ctrl"
                                               onchange="if(typeof validateBulkForm==='function') validateBulkForm();">
                                    </div>
                                </div>
                            </div>

                            <!-- Status -->
                            <div class="sr-fg">
                                <label class="sr-lbl">Status to Apply</label>
                                <select name="bulkStatus" id="bulkStatusSelect" required class="sr-ctrl"
                                        onchange="var d=document.getElementById('bulkCustomHoursDiv');if(d)d.style.display=this.value==='custom_hours'?'block':'none';if(typeof validateBulkForm==='function')validateBulkForm();">
                                    <option value="">Select status…</option>
                                    <option value="on">✅ Override: Working</option>
                                    <option value="off">❌ Override: Day Off</option>
                                    <option value="pto">🏖️ PTO</option>
                                    <option value="sick">🤒 Sick</option>
                                    <option value="holiday">🎄 Holiday</option>
                                    <option value="custom_hours">⏰ Custom Hours</option>
                                    <option value="schedule">🔄 Reset to Default Schedule</option>
                                </select>
                            </div>

                            <!-- Custom Hours (hidden until selected) -->
                            <div id="bulkCustomHoursDiv" class="sr-fg" style="display:none;">
                                <label class="sr-lbl">Custom Hours</label>
                                <input type="text" name="bulkCustomHours" id="bulkCustomHours" placeholder="e.g. 9-13 or 8-12&amp;14-18" class="sr-ctrl">
                                <span class="sr-hint">Examples: 9-13 &nbsp;|&nbsp; 8-12&amp;14-18</span>
                            </div>

                            <!-- Skip Days Off -->
                            <div class="sr-check-block">
                                <label class="sr-check-row">
                                    <input type="checkbox" name="skipDaysOff" id="skipDaysOff"
                                           onchange="if(typeof validateBulkForm==='function')validateBulkForm();if(typeof updateSkipDaysOffInfoStandalone==='function')updateSkipDaysOffInfoStandalone();">
                                    🚫 Skip days off
                                </label>
                                <div class="sr-hint" style="margin-left:25px;margin-top:5px;">Only apply to scheduled working days per each employee's weekly schedule.</div>
                                <div id="skipDaysOffInfo" class="sr-hint" style="margin-left:25px;margin-top:6px;color:var(--primary-color,#007bff);font-weight:700;display:none;">✅ Will skip non-working days</div>
                            </div>

                            <!-- Weekly Schedule -->
                            <div class="sr-fg">
                                <label class="sr-lbl">Set Weekly Schedule <span style="font-weight:400;text-transform:none;font-size:11px;">(Optional)</span></label>
                                <div class="sr-sched-grid">
                                    <div class="sr-sched-day">Su</div>
                                    <div class="sr-sched-day">Mo</div>
                                    <div class="sr-sched-day">Tu</div>
                                    <div class="sr-sched-day">We</div>
                                    <div class="sr-sched-day">Th</div>
                                    <div class="sr-sched-day">Fr</div>
                                    <div class="sr-sched-day">Sa</div>
                                    <div><input type="checkbox" name="newSchedule[]" value="0"></div>
                                    <div><input type="checkbox" name="newSchedule[]" value="1"></div>
                                    <div><input type="checkbox" name="newSchedule[]" value="2"></div>
                                    <div><input type="checkbox" name="newSchedule[]" value="3"></div>
                                    <div><input type="checkbox" name="newSchedule[]" value="4"></div>
                                    <div><input type="checkbox" name="newSchedule[]" value="5"></div>
                                    <div><input type="checkbox" name="newSchedule[]" value="6"></div>
                                </div>
                                <div class="sr-preset-row">
                                    <button type="button" onclick="setBulkScheduleStandalone('weekdays')" class="sr-preset" style="background:color-mix(in srgb,var(--primary-color,#007bff) 14%,transparent);color:var(--primary-color,#007bff);">M–F</button>
                                    <button type="button" onclick="setBulkScheduleStandalone('weekends')" class="sr-preset" style="background:color-mix(in srgb,var(--primary-color,#007bff) 14%,transparent);color:var(--primary-color,#007bff);">Wknd</button>
                                    <button type="button" onclick="setBulkScheduleStandalone('all')" class="sr-preset" style="background:color-mix(in srgb,#27ae60 14%,transparent);color:#27ae60;">All</button>
                                    <button type="button" onclick="setBulkScheduleStandalone('clear')" class="sr-preset" style="background:color-mix(in srgb,#c0392b 14%,transparent);color:#c0392b;">Clear</button>
                                </div>
                            </div>

                            <!-- Shift Change -->
                            <div class="sr-check-block amber">
                                <label class="sr-check-row">
                                    <input type="checkbox" name="changeShift" id="changeShiftCheckbox"
                                           onchange="var d=document.getElementById('shiftChangeDiv');if(d)d.style.display=this.checked?'block':'none';if(typeof toggleShiftChange==='function')toggleShiftChange();">
                                    🔄 Also Change Shift Assignment
                                </label>
                                <div class="sr-hint" style="margin-left:25px;margin-top:5px;">Update shift assignment effective on the start date.</div>
                                <div id="shiftChangeDiv" style="display:none;margin-top:14px;padding-top:12px;border-top:1px solid color-mix(in srgb,#f39c12 30%,transparent);">
                                    <div class="sr-fg">
                                        <label class="sr-lbl">New Shift</label>
                                        <select name="newShift" id="newShift" class="sr-ctrl">
                                            <option value="">Select new shift…</option>
                                            <option value="1">1st Shift</option>
                                            <option value="2">2nd Shift</option>
                                            <option value="3">3rd Shift</option>
                                        </select>
                                    </div>
                                    <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:10px;">
                                        <label class="sr-check-row" style="font-weight:normal;">
                                            <input type="radio" name="shiftChangeWhen" value="now" checked>
                                            Change Now
                                        </label>
                                        <label class="sr-check-row" style="font-weight:normal;">
                                            <input type="radio" name="shiftChangeWhen" value="start_date">
                                            Change on Start Date
                                        </label>
                                    </div>
                                    <div id="shiftEffectiveDate" class="sr-hint" style="color:var(--primary-color,#007bff);">Shift will change immediately when you submit.</div>
                                </div>
                            </div>

                            <!-- Comment -->
                            <div class="sr-fg">
                                <label class="sr-lbl">Comment <span style="font-weight:400;text-transform:none;font-size:11px;">(Optional)</span></label>
                                <input type="text" name="bulkComment" id="bulkComment" placeholder="Add a note…" class="sr-ctrl">
                            </div>

                        </div>

                        <!-- RIGHT: Employee Selection -->
                        <div>
                            <label class="sr-lbl">Select Employees</label>
                            <div class="sr-emp-wrap">
                                <div class="sr-emp-top">
                                    <label>
                                        <input type="checkbox" id="selectAllEmployees" onchange="ScheduleApp.toggleAllEmployees()">
                                        Select All
                                    </label>
                                    <span id="bulkEmployeeCount" class="sr-emp-count"></span>
                                </div>
                                <div class="sr-emp-search">
                                    <input type="text" id="bulkEmployeeSearch" placeholder="🔍 Search by name or team…" oninput="filterBulkEmployees()">
                                </div>
                                <div class="sr-emp-list" id="bulkEmployeeList">
                                    <?php
                                    $bulkEmployees = $employees;
                                    usort($bulkEmployees, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
                                    foreach ($bulkEmployees as $employee): ?>
                                    <div class="bulk-employee-item sr-emp-item"
                                         data-name="<?php echo strtolower(escapeHtml($employee['name'])); ?>"
                                         data-team="<?php echo strtolower($employee['team']); ?>"
                                         data-shift="<?php echo $employee['shift']; ?>"
                                         onclick="var cb=this.querySelector('.employee-checkbox');cb.checked=!cb.checked;if(typeof ScheduleApp!=='undefined'&&ScheduleApp.validateBulkForm)ScheduleApp.validateBulkForm();else if(typeof validateBulkForm==='function')validateBulkForm();">
                                        <input type="checkbox" name="selectedEmployees[]" value="<?php echo $employee['id']; ?>" class="employee-checkbox"
                                               onclick="event.stopPropagation();">
                                        <div>
                                            <div class="sr-emp-name"><?php echo escapeHtml($employee['name']); ?></div>
                                            <div class="sr-emp-meta"><?php echo strtoupper($employee['team']); ?> · <?php echo getShiftName($employee['shift']); ?><?php if(!empty($employee['level'])): ?> · <?php echo getLevelName($employee['level']); ?><?php endif; ?></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                    </div><!-- /.sr-two-col -->

                    <!-- Warning + Submit -->
                    <div class="sr-warn">
                        ⚠️ <strong>Warning:</strong> This applies the selected status to all chosen employees across the full date range. This action cannot be undone.
                        <div id="bulkImpactSummary" class="sr-impact"></div>
                    </div>
                    <div class="sr-submit-row">
                        <button type="submit" id="bulkSubmitBtn" disabled class="sr-btn sr-btn-primary" style="font-size:14px;padding:11px 28px;">Apply Bulk Changes</button>
                        <button type="button" onclick="showTab('schedule')" class="sr-btn sr-btn-ghost">Cancel</button>
                    </div>

                </form>
                </div>
            </div>

            <!-- ── CSV Upload Card ── -->
            <div class="sr-card">
                <div class="sr-card-head">
                    <div class="sr-card-head-left">
                        <div class="sr-icon-badge soft">📤</div>
                        <div>
                            <div class="sr-card-title">Bulk Upload via CSV</div>
                            <div class="sr-card-sub">Download, edit in Excel or Sheets, upload back</div>
                        </div>
                    </div>
                    <span style="font-size:11px;background:color-mix(in srgb,var(--primary-color,#007bff) 12%,transparent);color:var(--primary-color,#007bff);padding:3px 10px;border-radius:20px;font-weight:600;">CSV</span>
                </div>
                <div class="sr-card-body">
                    <div class="sr-two-col">

                        <!-- Step 1 -->
                        <div class="sr-check-block" style="padding:18px 20px;">
                            <div style="display:flex;align-items:center;gap:10px;font-weight:700;font-size:14px;margin-bottom:10px;">
                                <span class="sr-step-badge">1</span> Download Current Schedule
                            </div>
                            <p class="sr-hint" style="margin:0 0 14px;">Export your schedule as a CSV template, then modify it in your spreadsheet app.</p>
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="download_schedule_csv">
                                <div class="sr-fg">
                                    <label class="sr-lbl">Date Range</label>
                                    <div class="sr-date-pair">
                                        <div>
                                            <span class="sr-hint" style="display:block;margin-bottom:3px;">Start</span>
                                            <input type="date" name="download_start_date" required class="sr-ctrl" value="<?php echo date('Y-m-01'); ?>">
                                        </div>
                                        <div>
                                            <span class="sr-hint" style="display:block;margin-bottom:3px;">End</span>
                                            <input type="date" name="download_end_date" required class="sr-ctrl" value="<?php echo date('Y-m-t', strtotime('+1 month')); ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="sr-fg">
                                    <label class="sr-lbl">Filter by Team <span style="font-weight:400;text-transform:none;">(Optional)</span></label>
                                    <select name="download_team" class="sr-ctrl">
                                        <option value="">All Teams</option>
                                        <?php
                                        $csvTeams = [];
                                        foreach ($employees as $emp) {
                                            $team = strtoupper(trim($emp['team'] ?? ''));
                                            if ($team && !in_array($team, $csvTeams)) $csvTeams[] = $team;
                                        }
                                        sort($csvTeams);
                                        foreach ($csvTeams as $team) echo '<option value="'.htmlspecialchars($team).'">'.htmlspecialchars($team).'</option>';
                                        ?>
                                    </select>
                                </div>
                                <button type="submit" class="sr-btn sr-btn-primary" style="width:100%;justify-content:center;">📥 Download Template CSV</button>
                            </form>
                            <div class="sr-hint" style="margin-top:10px;padding:8px 12px;background:color-mix(in srgb,var(--primary-color,#007bff) 8%,transparent);border-radius:7px;">
                                <strong>Tip:</strong> Download by team to work one team at a time.
                            </div>
                        </div>

                        <!-- Step 2 -->
                        <div class="sr-check-block" style="background:color-mix(in srgb,#27ae60 6%,transparent);border-color:color-mix(in srgb,#27ae60 25%,transparent);padding:18px 20px;">
                            <div style="display:flex;align-items:center;gap:10px;font-weight:700;font-size:14px;margin-bottom:10px;">
                                <span class="sr-step-badge" style="background:#27ae60;">2</span> Upload Modified CSV
                            </div>
                            <p class="sr-hint" style="margin:0 0 14px;">After editing, upload your CSV here to apply all changes.</p>
                            <form method="POST" action="" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="upload_schedule_csv">
                                <input type="file" name="csv_file" id="csvFileInput" accept=".csv" style="display:none;" onchange="this.form.submit();">
                                <button type="button" onclick="document.getElementById('csvFileInput').click();" class="sr-btn sr-btn-success" style="width:100%;justify-content:center;">📤 Upload CSV File</button>
                            </form>
                            <div class="sr-hint" style="margin-top:10px;padding:8px 12px;background:color-mix(in srgb,#f39c12 10%,transparent);border-radius:7px;">
                                <strong>Note:</strong> A backup is created automatically before changes are applied.
                            </div>
                            <div style="margin-top:14px;padding-top:12px;border-top:1px solid color-mix(in srgb,#27ae60 20%,transparent);">
                                <p class="sr-hint" style="font-weight:700;margin-bottom:6px;">📋 Need help?</p>
                                <button class="sr-toggle-btn" onclick="var el=document.getElementById('csvFormatGuide');el.classList.toggle('open');this.querySelector('span').textContent=el.classList.contains('open')?'▲':'▼';">
                                    📋 CSV Format Guide <span>▼</span>
                                </button>
                                <button class="sr-toggle-btn" onclick="var el=document.getElementById('dateRangeExamples');el.classList.toggle('open');this.querySelector('span').textContent=el.classList.contains('open')?'▲':'▼';">
                                    📅 Date Range Examples &amp; Tips <span>▼</span>
                                </button>
                            </div>
                        </div>

                    </div>

                    <!-- CSV Format Guide -->
                    <div id="csvFormatGuide" class="sr-collapsible" style="margin-top:16px;background:var(--body-bg,#f1f5f9);border:1.5px solid var(--border-color,#dee2e6);border-radius:10px;padding:20px;">
                        <p class="sr-hint" style="margin:0 0 10px;">Your CSV must have these columns in exact order:</p>
                        <div style="background:var(--card-bg,#fff);padding:14px;border-radius:8px;font-family:monospace;font-size:12px;overflow-x:auto;margin-bottom:16px;border:1px solid var(--border-color,#e2e8f0);">
                            <div style="font-weight:700;color:var(--primary-color,#0ea5e9);margin-bottom:6px;">Employee Name, Date, Status, Hours, Shift, Custom Hours, Team</div>
                            <div class="sr-hint">John Smith, 2026-01-28, PTO, 0, 1, , ALL</div>
                            <div class="sr-hint">Jane Doe, 2026-01-28, SICK, 0, 1, , ESG</div>
                            <div class="sr-hint">Mike Johnson, 2026-01-29, CUSTOM_HOURS, 0, 2, 9am-1pm, SUPPORT</div>
                        </div>
                        <div class="sr-two-col" style="gap:16px;">
                            <div>
                                <strong style="font-size:13px;">Valid Status Values:</strong>
                                <div style="margin-top:8px;display:flex;flex-direction:column;gap:5px;font-size:12px;">
                                    <div><span class="sr-code">OFF</span> – Day Off</div>
                                    <div><span class="sr-code">PTO</span> – Paid Time Off</div>
                                    <div><span class="sr-code">SICK</span> – Sick Leave</div>
                                    <div><span class="sr-code">HOLIDAY</span> – Holiday</div>
                                    <div><span class="sr-code">CUSTOM_HOURS</span> – Custom Hours</div>
                                    <div><span class="sr-code">SCHEDULE</span> – Reset to Default</div>
                                </div>
                                <div class="sr-hint" style="margin-top:10px;padding:7px 10px;background:color-mix(in srgb,#f39c12 10%,transparent);border-radius:6px;">Don't include <span class="sr-code">ON</span> rows — they have no effect.</div>
                            </div>
                            <div>
                                <strong style="font-size:13px;">Important Notes:</strong>
                                <div style="margin-top:8px;display:flex;flex-direction:column;gap:5px;font-size:12px;color:var(--text-muted,#475569);">
                                    <div>Date format: <span class="sr-code">YYYY-MM-DD</span></div>
                                    <div>Name matching is case-insensitive</div>
                                    <div>Only include rows you want to change</div>
                                    <div>Team column is informational only</div>
                                    <div>Use CUSTOM_HOURS + Custom Hours column to set specific hours</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Date Range Examples -->
                    <div id="dateRangeExamples" class="sr-collapsible" style="margin-top:10px;background:color-mix(in srgb,#27ae60 5%,transparent);border:1.5px solid color-mix(in srgb,#27ae60 25%,transparent);border-radius:10px;padding:20px;">
                        <strong style="font-size:14px;display:block;margin-bottom:14px;">📅 Common Examples</strong>
                        <div class="sr-two-col" style="gap:12px;">
                            <div style="background:var(--card-bg,#fff);padding:14px;border-radius:8px;border:1px solid var(--border-color,#d1fae5);">
                                <strong style="font-size:13px;">PTO for a week</strong>
                                <ol style="margin:8px 0 0 18px;padding:0;font-size:12px;color:var(--text-muted,#065f46);line-height:1.9;">
                                    <li>Download CSV for the week's date range</li>
                                    <li>Select Status column, type <span class="sr-code">PTO</span>, fill down</li>
                                    <li>Upload</li>
                                </ol>
                            </div>
                            <div style="background:var(--card-bg,#fff);padding:14px;border-radius:8px;border:1px solid var(--border-color,#d1fae5);">
                                <strong style="font-size:13px;">Holiday for everyone</strong>
                                <ol style="margin:8px 0 0 18px;padding:0;font-size:12px;color:var(--text-muted,#065f46);line-height:1.9;">
                                    <li>Download for one day (e.g. 2026-12-25)</li>
                                    <li>Fill Status with <span class="sr-code">HOLIDAY</span></li>
                                    <li>Upload</li>
                                </ol>
                            </div>
                        </div>
                        <div style="margin-top:12px;background:var(--card-bg,#fff);padding:14px;border-radius:8px;border:1px solid var(--border-color,#d1fae5);">
                            <strong style="font-size:13px;">💡 Quick Tips</strong>
                            <div style="margin-top:8px;display:flex;flex-direction:column;gap:5px;font-size:12px;color:var(--text-muted,#475569);">
                                <div><strong>Fill Down:</strong> Ctrl+D (Cmd+D on Mac)</div>
                                <div><strong>Find &amp; Replace:</strong> Ctrl+H to swap statuses in bulk</div>
                                <div><strong>Team changes:</strong> Download by team to avoid affecting others</div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Inline scripts (preserved) -->
            <script>
            window.setBulkScheduleStandalone = function(preset) {
                const checkboxes = document.querySelectorAll('input[name="newSchedule[]"]');
                if (!checkboxes || !checkboxes.length) return;
                switch(preset) {
                    case 'weekdays':  checkboxes.forEach((cb,i) => cb.checked = (i>=1&&i<=5)); break;
                    case 'weekends':  checkboxes.forEach((cb,i) => cb.checked = (i===0||i===6)); break;
                    case 'all':       checkboxes.forEach(cb => cb.checked = true); break;
                    case 'clear':     checkboxes.forEach(cb => cb.checked = false); break;
                }
            };
            window.updateSkipDaysOffInfoStandalone = function() {
                const cb = document.getElementById('skipDaysOff');
                const info = document.getElementById('skipDaysOffInfo');
                if (cb && info) info.style.display = cb.checked ? 'block' : 'none';
            };
            window.toggleShiftChange = function() {
                const cb = document.getElementById('changeShiftCheckbox');
                const div = document.getElementById('shiftChangeDiv');
                const sel = document.getElementById('newShift');
                if (cb && div) {
                    div.style.display = cb.checked ? 'block' : 'none';
                    if (sel) { sel.required = cb.checked; if (!cb.checked) sel.value = ''; }
                }
            };
            (function() {
                setTimeout(function() {
                    var startDate = document.getElementById('bulkStartDate');
                    var endDate   = document.getElementById('bulkEndDate');
                    var status    = document.getElementById('bulkStatusSelect');
                    var submitBtn = document.getElementById('bulkSubmitBtn');
                    var empList   = document.getElementById('bulkEmployeeList');
                    window.validateBulkFormInline = function() {
                        if (!startDate||!endDate||!status||!submitBtn||!empList) return;
                        var sel = empList.querySelectorAll('.employee-checkbox:checked');
                        var valid = startDate.value && endDate.value && status.value && sel.length > 0 && startDate.value <= endDate.value;
                        submitBtn.disabled = !valid;
                        if (valid) {
                            var days = Math.floor((new Date(endDate.value)-new Date(startDate.value))/(86400000))+1;
                            submitBtn.textContent = 'Apply '+(sel.length*days)+' Changes ('+sel.length+' employees × '+days+' days)';
                        } else { submitBtn.textContent = 'Apply Bulk Changes'; }
                    };
                    if (typeof window.validateBulkForm !== 'function') window.validateBulkForm = window.validateBulkFormInline;
                    validateBulkFormInline();
                }, 500);
            })();
            </script>

        </div><!-- /.sr -->
        </div>
        <?php endif; ?>

        <!-- Add Employee Tab (Inline) -->
        <?php if (hasPermission('manage_employees')): ?>
        <div id="add-employee-tab" class="tab-content<?php echo ($activeTab === 'add-employee') ? ' active' : ''; ?>">
            <div style="width: 98%; margin: 0 auto; padding: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="margin: 0;">➕ Create Employee Schedule</h2>
                    <button onclick="showTab('schedule')" style="background: var(--secondary-color, #6c757d); color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">← Back to Schedule</button>
                </div>
                
                <!-- Add Employee Form - INLINE VERSION -->
                <div style="background: var(--card-bg, white); color: var(--text-color, #333); padding: 20px; border-radius: 8px; border: 1px solid var(--border-color, #dee2e6); box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <form method="POST" id="inlineAddEmployeeForm" onsubmit="return submitInlineForm(this, 'add-employee');">
                        <input type="hidden" name="action" value="add_employee">
                        <input type="hidden" name="link_user_id" id="linkUserId" value="">
                        
                        <!-- Two Column Layout -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            
                            <!-- LEFT COLUMN -->
                            <div>
                                <!-- Employee Name -->
                                <div style="margin-bottom: 12px;">
                                    <label style="font-weight: bold; margin-bottom: 5px; display: block;">Employee Name:</label>
                                    <input type="text" name="empName" id="addEmpName" required placeholder="Enter full name" style="width: 100%; padding: 6px;">
                                </div>
                                
                                <!-- Team, Level, Shift in 3 columns -->
                                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-bottom: 12px;">
                                    <div>
                                        <label style="font-weight: bold; margin-bottom: 5px; display: block; font-size: 12px;">Team:</label>
                                        <select name="empTeam" id="addEmpTeam" required style="width: 100%; padding: 6px;">
                                            <?php echo generateTeamOptions(); ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="font-weight: bold; margin-bottom: 5px; display: block; font-size: 12px;">Level:</label>
                                        <select name="empLevel" id="addEmpLevel" style="width: 100%; padding: 6px;">
                                            <?php echo generateLevelOptions(); ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <!-- USER ROLE FIELD -->
                                <div style="margin-bottom: 12px; border: 2px solid #f59e0b; border-radius: 6px; padding: 12px; background: #fffbeb;">
                                    <label style="font-weight: bold; margin-bottom: 5px; display: block; color: #92400e;">
                                        👥 User Role (Permissions):
                                    </label>
                                    <select name="empUserRole" id="addEmpUserRole" style="width: 100%; padding: 8px; border: 2px solid #f59e0b; font-weight: 600;">
                                        <option value="employee" selected>Employee (Restricted Access)</option>
                                        <option value="supervisor">Supervisor (Full Access)</option>
                                        <option value="manager">Manager (Full Access)</option>
                                        <option value="admin">Admin (Full Access)</option>
                                    </select>
                                    <div style="font-size: 10px; color: #92400e; margin-top: 5px;">
                                        ⚠️ Controls system permissions (who can edit what)
                                    </div>
                                </div>
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px;">
                                    <div>
                                        <label style="font-weight: bold; margin-bottom: 5px; display: block; font-size: 12px;">Shift:</label>
                                        <select name="empShift" id="addEmpShift" required style="width: 100%; padding: 6px;">
                                            <option value="1">1st</option>
                                            <option value="2">2nd</option>
                                            <option value="3">3rd</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <!-- Schedule Access -->
                                <div style="margin-bottom: 12px;">
                                    <label style="font-weight: bold; margin-bottom: 5px; display: block;">Schedule Access (Optional):</label>
                                    <select name="empScheduleAccess" id="addEmpScheduleAccess" style="width: 100%; padding: 6px;">
                                        <option value="" selected>Default (All Teams)</option>
                                        <option value="all">All Teams</option>
                                        <?php echo generateTeamOptions(false); ?>
                                    </select>
                                    <div style="font-size: 10px; color: #666; margin-top: 3px;">
                                        ℹ️ <strong>Note:</strong> All employees can view all team schedules by default.<br>
                                        This field is for future use and does not restrict viewing.
                                    </div>
                                </div>
                                
                                <!-- Working Hours -->
                                <div style="margin-bottom: 12px;">
                                    <label style="font-weight: bold; margin-bottom: 5px; display: block;">Working Hours:</label>
                                    <input type="text" name="empHours" id="addEmpHours" required placeholder="e.g., 9am-5pm, 3pm-11pm" style="width: 100%; padding: 6px;" oninput="normalizeHoursInput(this)" onblur="normalizeHoursInput(this)">
                                    <div style="font-size: 10px; color: #666; margin-top: 3px;">12-hour format: 9am-5pm, 3pm-11pm, 10pm-6am</div>
                                </div>
                                
                                <!-- Email and Supervisor in 2 columns -->
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px;">
                                    <div>
                                        <label style="font-weight: bold; margin-bottom: 5px; display: block; font-size: 12px;">Email:</label>
                                        <input type="email" name="empEmail" id="addEmpEmail" placeholder="email@company.com" style="width: 100%; padding: 6px;">
                                    </div>
                                    <div>
                                        <label style="font-weight: bold; margin-bottom: 5px; display: block; font-size: 12px;">Supervisor:</label>
                                        <select name="empSupervisor" id="addEmpSupervisor" style="width: 100%; padding: 6px;">
                                            <?php echo generateSupervisorOptions(); ?>
                                        </select>
                                    </div>
                                </div>
                                
                            </div>
                            
                            <!-- RIGHT COLUMN -->
                            <div>
                                <!-- Weekly Schedule -->
                                <div style="margin-bottom: 12px;">
                                    <label style="font-weight: bold; margin-bottom: 5px; display: block;">Weekly Schedule:</label>
                                    
                                    <!-- Template Selection -->
                                    <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin-bottom: 10px;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                            <strong style="font-size: 12px;">📋 Templates</strong>
                                            <button type="button" onclick="
                                                try {
                                                    var mgmt = document.getElementById('templateManagement');
                                                    if (mgmt) {
                                                        var isHidden = mgmt.style.display === 'none' || mgmt.style.display === '';
                                                        mgmt.style.display = isHidden ? 'block' : 'none';
                                                    }
                                                } catch(e) {
                                                }
                                            " style="background: none; border: none; color: var(--primary-color, #007bff); cursor: pointer; font-size: 11px;">Manage</button>
                                        </div>
                                        
                                        <div style="display: flex; gap: 6px; margin-bottom: 8px;">
                                            <select id="templateSelect" onchange="if(window.ScheduleApp && ScheduleApp.applyTemplate) ScheduleApp.applyTemplate(); else if(typeof applyTemplate === 'function') applyTemplate();" style="flex: 1; padding: 6px; font-size: 12px;">
                                                <option value="">Select template...</option>
                                                <?php foreach ($scheduleTemplates as $template): ?>
                                                <option value="<?php echo $template['id']; ?>" 
                                                        data-schedule="<?php echo implode(',', $template['schedule']); ?>"
                                                        title="<?php echo escapeHtml($template['description']); ?>">
                                                    <?php echo escapeHtml($template['name']); ?> 
                                                    (<?php echo formatScheduleDisplay($template['schedule']); ?>)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" onclick="if(window.ScheduleApp && ScheduleApp.clearSchedule) ScheduleApp.clearSchedule(); else if(typeof clearSchedule === 'function') clearSchedule();" style="background: var(--secondary-color, #6c757d); color: white; border: none; padding: 6px 10px; border-radius: 3px; font-size: 11px;">Clear</button>
                                        </div>
                                        
                                        <!-- Template Management -->
                                        <div id="templateManagement" style="display: none; border-top: 1px solid #dee2e6; padding-top: 10px; margin-top: 10px;">
                                            <div style="display: flex; gap: 6px; margin-bottom: 8px;">
                                                <input type="text" id="newTemplateName" placeholder="Name..." style="flex: 1; padding: 4px 6px; font-size: 11px;">
                                                <input type="text" id="newTemplateDescription" placeholder="Description..." style="flex: 1; padding: 4px 6px; font-size: 11px;">
                                                <button type="button" onclick="if(window.ScheduleApp) ScheduleApp.saveCurrentAsTemplate(); else saveCurrentAsTemplate();" style="background: var(--success-color, #28a745); color: white; border: none; padding: 4px 8px; border-radius: 3px; font-size: 10px;">Save</button>
                                            </div>
                                            
                                            <div style="max-height: 100px; overflow-y: auto;">
                                                <?php foreach ($scheduleTemplates as $template): ?>
                                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 4px 0; border-bottom: 1px solid #eee; font-size: 11px;">
                                                    <div>
                                                        <strong><?php echo escapeHtml($template['name']); ?></strong><br>
                                                        <span style="color: #666; font-size: 10px;"><?php echo formatScheduleDisplay($template['schedule']); ?></span>
                                                    </div>
                                                    <?php if ($template['created_by'] !== 'System'): ?>
                                                    <button type="button" onclick="if(window.ScheduleApp) ScheduleApp.deleteTemplate(<?php echo $template['id']; ?>, '<?php echo addslashes($template['name']); ?>')" 
                                                            style="background: #dc3545; color: white; border: none; padding: 2px 4px; border-radius: 3px; font-size: 9px;">Del</button>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Schedule Grid -->
                                    <div class="schedule-grid" style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center; margin-bottom: 8px;">
                                        <div style="font-weight: bold; font-size: 11px;">S</div>
                                        <div style="font-weight: bold; font-size: 11px;">M</div>
                                        <div style="font-weight: bold; font-size: 11px;">T</div>
                                        <div style="font-weight: bold; font-size: 11px;">W</div>
                                        <div style="font-weight: bold; font-size: 11px;">T</div>
                                        <div style="font-weight: bold; font-size: 11px;">F</div>
                                        <div style="font-weight: bold; font-size: 11px;">S</div>
                                        
                                        <div><input type="checkbox" name="day0" id="addDay0"></div>
                                        <div><input type="checkbox" name="day1" id="addDay1" checked></div>
                                        <div><input type="checkbox" name="day2" id="addDay2" checked></div>
                                        <div><input type="checkbox" name="day3" id="addDay3" checked></div>
                                        <div><input type="checkbox" name="day4" id="addDay4" checked></div>
                                        <div><input type="checkbox" name="day5" id="addDay5" checked></div>
                                        <div><input type="checkbox" name="day6" id="addDay6"></div>
                                    </div>
                                    
                                    <div style="display: flex; gap: 6px; flex-wrap: wrap; font-size: 11px;">
                                        <button type="button" onclick="
                                            try {
                                                document.getElementById('addDay0').checked = false;
                                                document.getElementById('addDay1').checked = true;
                                                document.getElementById('addDay2').checked = true;
                                                document.getElementById('addDay3').checked = true;
                                                document.getElementById('addDay4').checked = true;
                                                document.getElementById('addDay5').checked = true;
                                                document.getElementById('addDay6').checked = false;
                                            } catch(e) { }

                                        " style="padding: 4px 8px; background: var(--primary-color, #17a2b8); color: white; border: none; border-radius: 3px;">M-F</button>
                                        <button type="button" onclick="
                                            try {
                                                document.getElementById('addDay0').checked = true;
                                                document.getElementById('addDay1').checked = true;
                                                document.getElementById('addDay2').checked = false;
                                                document.getElementById('addDay3').checked = false;
                                                document.getElementById('addDay4').checked = false;
                                                document.getElementById('addDay5').checked = true;
                                                document.getElementById('addDay6').checked = true;
                                            } catch(e) { }

                                        " style="padding: 4px 8px; background: var(--primary-color, #17a2b8); color: white; border: none; border-radius: 3px;">Wknd</button>
                                        <button type="button" onclick="
                                            try {
                                                document.getElementById('addDay0').checked = true;
                                                document.getElementById('addDay1').checked = true;
                                                document.getElementById('addDay2').checked = true;
                                                document.getElementById('addDay3').checked = true;
                                                document.getElementById('addDay4').checked = true;
                                                document.getElementById('addDay5').checked = true;
                                                document.getElementById('addDay6').checked = true;
                                            } catch(e) { }

                                        " style="padding: 4px 8px; background: var(--success-color, #28a745); color: white; border: none; border-radius: 3px;">All</button>
                                    </div>
                                </div>
                                
                                <!-- Skills -->
                                <div style="margin-bottom: 12px;">
                                    <label style="font-weight: bold; margin-bottom: 5px; display: block;">Skills:</label>
                                    <div class="skill-form-badges" style="background: #f8f9fa; padding: 10px; border-radius: 4px; border: 1px solid #dee2e6;">
                                        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; margin: 0; font-size: 12px;">
                                                <input type="checkbox" name="skillMH" id="addSkillMH">
                                                <span class="skill-badge-mh" style="background: #e3f2fd; color: #1976d2; padding: 3px 6px; border-radius: 8px; font-weight: bold;">MH</span>
                                                <span class="skill-label-text">Managed Hosting</span>
                                            </label>
                                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; margin: 0; font-size: 12px;">
                                                <input type="checkbox" name="skillMA" id="addSkillMA">
                                                <span class="skill-badge-ma" style="background: #f3e5f5; color: #7b1fa2; padding: 3px 6px; border-radius: 8px; font-weight: bold;">MA</span>
                                                <span class="skill-label-text">Managed Apps</span>
                                            </label>
                                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; margin: 0; font-size: 12px;">
                                                <input type="checkbox" name="skillWin" id="addSkillWin">
                                                <span class="skill-badge-win" style="background: #e8f5e8; color: #388e3c; padding: 3px 6px; border-radius: 8px; font-weight: bold;">Win</span>
                                                <span class="skill-label-text">Windows</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Start Date -->
                                <div style="margin-bottom: 12px;">
                                    <label style="font-weight: bold; margin-bottom: 5px; display: block; font-size: 12px;">📅 Start Date:</label>
                                    <input type="date" name="empStartDate" id="addEmpStartDate" style="width: 100%; padding: 6px;">
                                    <div style="font-size: 10px; color: #666; margin-top: 3px;">Employee's first day with the company (for anniversary tracking)</div>
                                </div>
                                
                                <!-- Auth Method -->
                                <div style="margin-bottom: 12px;">
                                    <label style="font-weight: bold; margin-bottom: 5px; display: block; font-size: 12px;">🔐 Authentication Method:</label>
                                    <select name="empAuthMethod" id="addEmpAuthMethod" style="width: 100%; padding: 6px;">
                                        <option value="both" selected>Both (Password + Google SSO)</option>
                                        <option value="password">Password Only</option>
                                        <option value="google">Google SSO Only</option>
                                    </select>
                                    <div style="font-size: 10px; color: #666; margin-top: 3px;">
                                        How this employee/user can login to the system
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Slack Member ID (Full Width) -->
                        <div style="margin-top: 12px; background: var(--secondary-bg, #f8f9fa); border: 1px solid var(--border-color, #dee2e6); border-radius: 6px; padding: 12px;">
                            <label style="display:flex;align-items:center;gap:6px;font-weight:bold;margin-bottom:6px;color:var(--text-color,#333);">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="#4A154B"><path d="M5.042 15.165a2.528 2.528 0 0 1-2.52 2.523A2.528 2.528 0 0 1 0 15.165a2.527 2.527 0 0 1 2.522-2.52h2.52v2.52zM6.313 15.165a2.527 2.527 0 0 1 2.521-2.52 2.527 2.527 0 0 1 2.521 2.52v6.313A2.528 2.528 0 0 1 8.834 24a2.528 2.528 0 0 1-2.521-2.522v-6.313zM8.834 5.042a2.528 2.528 0 0 1-2.521-2.52A2.528 2.528 0 0 1 8.834 0a2.528 2.528 0 0 1 2.521 2.522v2.52H8.834zM8.834 6.313a2.528 2.528 0 0 1 2.521 2.521 2.528 2.528 0 0 1-2.521 2.521H2.522A2.528 2.528 0 0 1 0 8.834a2.528 2.528 0 0 1 2.522-2.521h6.312zM18.956 8.834a2.528 2.528 0 0 1 2.522-2.521A2.528 2.528 0 0 1 24 8.834a2.528 2.528 0 0 1-2.522 2.521h-2.522V8.834zM17.688 8.834a2.528 2.528 0 0 1-2.523 2.521 2.527 2.527 0 0 1-2.52-2.521V2.522A2.527 2.527 0 0 1 15.165 0a2.528 2.528 0 0 1 2.523 2.522v6.312zM15.165 18.956a2.528 2.528 0 0 1 2.523 2.522A2.528 2.528 0 0 1 15.165 24a2.527 2.527 0 0 1-2.52-2.522v-2.522h2.52zM15.165 17.688a2.527 2.527 0 0 1-2.52-2.523 2.526 2.526 0 0 1 2.52-2.52h6.313A2.527 2.527 0 0 1 24 15.165a2.528 2.528 0 0 1-2.522 2.523h-6.313z"/></svg>
                                Slack Member ID <span style="font-weight:400;font-size:11px;color:var(--text-muted,#888);">(optional)</span>
                            </label>
                            <input type="text" name="empSlackId" id="addEmpSlackId"
                                   placeholder="e.g. U01AB2CD3EF"
                                   maxlength="50"
                                   style="width:100%;padding:8px;border:1px solid var(--border-color,#dee2e6);border-radius:4px;font-family:monospace;background:var(--card-bg,white);color:var(--text-color,#333);">
                            <div style="font-size:10px;color:var(--text-muted,#666);margin-top:5px;line-height:1.6;">
                                Open Slack → click your <strong>profile picture</strong> → <strong>Profile</strong> → <strong>⋯ More</strong> → <strong>"Copy member ID"</strong> (starts with <code style="background:#e9ecef;padding:1px 3px;border-radius:2px;">U</code>). Adds a clickable Slack link on the schedule.
                            </div>
                        </div>

                        <!-- Submit Buttons (Full Width) -->
                        <div style="text-align: center; margin-top: 12px;">
                            <button type="submit" style="padding: 10px 20px; font-size: 14px; font-weight: bold; background: var(--success-color, #28a745); color: white; border: none; border-radius: 4px; cursor: pointer;">Add Employee</button>
                            <button type="button" onclick="showTab('schedule')" style="background: var(--secondary-color, #6c757d); color: white; border: none; margin-left: 10px; padding: 10px 20px; border-radius: 4px; cursor: pointer;">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Heatmap Tab -->
        <div id="heatmap-tab" class="tab-content<?php echo ($activeTab === 'heatmap') ? ' active' : ''; ?>">
            <div class="heatmap-controls">
                <div class="control-group">
                    <div class="control-item">
                        <label for="heatmapTeamFilter">Team</label>
                        <select id="heatmapTeamFilter" onchange="updateHeatmapData()">
                            <option value="all">All Teams</option>
                            <?php foreach ($teams as $key => $name): ?>
                                <option value="<?php echo $key; ?>"><?php echo $name; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="control-item">
                        <label for="heatmapDateFrom">From Date</label>
                        <input type="date" id="heatmapDateFrom" onchange="updateHeatmapData()" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px;">
                    </div>

                    <div class="control-item">
                        <label for="heatmapDateTo">To Date</label>
                        <input type="date" id="heatmapDateTo" onchange="updateHeatmapData()" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px;">
                    </div>

                    <div class="control-item">
                        <label for="heatmapTimeFrom">From Time</label>
                        <select id="heatmapTimeFrom" onchange="updateHeatmapData()">
                            <option value="all">All Hours</option>
                            <option value="0">12:00 AM</option>
                            <option value="1">1:00 AM</option>
                            <option value="2">2:00 AM</option>
                            <option value="3">3:00 AM</option>
                            <option value="4">4:00 AM</option>
                            <option value="5">5:00 AM</option>
                            <option value="6">6:00 AM</option>
                            <option value="7">7:00 AM</option>
                            <option value="8">8:00 AM</option>
                            <option value="9">9:00 AM</option>
                            <option value="10">10:00 AM</option>
                            <option value="11">11:00 AM</option>
                            <option value="12" selected>12:00 PM</option>
                            <option value="13">1:00 PM</option>
                            <option value="14">2:00 PM</option>
                            <option value="15">3:00 PM</option>
                            <option value="16">4:00 PM</option>
                            <option value="17">5:00 PM</option>
                            <option value="18">6:00 PM</option>
                            <option value="19">7:00 PM</option>
                            <option value="20">8:00 PM</option>
                            <option value="21">9:00 PM</option>
                            <option value="22">10:00 PM</option>
                            <option value="23">11:00 PM</option>
                        </select>
                    </div>

                    <div class="control-item">
                        <label for="heatmapTimeTo">To Time</label>
                        <select id="heatmapTimeTo" onchange="updateHeatmapData()">
                            <option value="all">All Hours</option>
                            <option value="0" selected>12:00 AM</option>
                            <option value="1">1:00 AM</option>
                            <option value="2">2:00 AM</option>
                            <option value="3">3:00 AM</option>
                            <option value="4">4:00 AM</option>
                            <option value="5">5:00 AM</option>
                            <option value="6">6:00 AM</option>
                            <option value="7">7:00 AM</option>
                            <option value="8">8:00 AM</option>
                            <option value="9">9:00 AM</option>
                            <option value="10">10:00 AM</option>
                            <option value="11">11:00 AM</option>
                            <option value="12">12:00 PM</option>
                            <option value="13">1:00 PM</option>
                            <option value="14">2:00 PM</option>
                            <option value="15">3:00 PM</option>
                            <option value="16">4:00 PM</option>
                            <option value="17">5:00 PM</option>
                            <option value="18">6:00 PM</option>
                            <option value="19">7:00 PM</option>
                            <option value="20">8:00 PM</option>
                            <option value="21">9:00 PM</option>
                            <option value="22">10:00 PM</option>
                            <option value="23">11:00 PM</option>
                        </select>
                    </div>

                    <div class="control-item">
                        <label for="heatmapLevelFilter">Level</label>
                        <select id="heatmapLevelFilter" onchange="updateHeatmapData()">
                            <option value="all">All Levels</option>
                            <?php foreach ($levels as $key => $name): ?>
                                <option value="<?php echo $key; ?>"><?php echo $name; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="control-item">
                        <label for="heatmapSupervisorFilter">Supervisor</label>
                        <select id="heatmapSupervisorFilter" onchange="updateHeatmapData()">
                            <option value="all">All Supervisors</option>
                            <option value="none">No Supervisor</option>
                            <?php 
                            $supervisorsAndManagers = array_filter($employees, function($emp) {
                                $level = strtolower($emp['level'] ?? '');
                                return strpos($level, 'supervisor') !== false || strpos($level, 'manager') !== false;
                            });
                            foreach ($supervisorsAndManagers as $supervisor): ?>
                                <option value="<?php echo $supervisor['id']; ?>">
                                    <?php echo escapeHtml($supervisor['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="control-item">
                        <label for="heatmapDayFilter">Day</label>
                        <select id="heatmapDayFilter" onchange="updateHeatmapData()">
                            <option value="all">All Days</option>
                            <option value="0">Sunday</option>
                            <option value="1">Monday</option>
                            <option value="2">Tuesday</option>
                            <option value="3">Wednesday</option>
                            <option value="4">Thursday</option>
                            <option value="5">Friday</option>
                            <option value="6">Saturday</option>
                        </select>
                    </div>

                    <div class="control-item">
                        <label for="heatmapShiftFilter">Shift</label>
                        <select id="heatmapShiftFilter" onchange="updateHeatmapData()">
                            <option value="all">All Shifts</option>
                            <option value="1">1st Shift</option>
                            <option value="2">2nd Shift</option>
                            <option value="3">3rd Shift</option>
                            
                        </select>
                    </div>
                    
                    <div class="control-item">
                        <label style="visibility: hidden;">Refresh</label>
                        <button onclick="refreshHeatmap()" style="padding: 8px 16px; background: var(--primary-color, #2196f3); color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: bold; width: 100%;">
                            🔄 Refresh Heatmap
                        </button>
                    </div>
                    
                    <div class="control-item">
                        <label style="visibility: hidden;">Clear</label>
                        <button onclick="clearHeatmapFilters()" style="padding: 8px 16px; background: var(--danger-color, #f44336); color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: bold; width: 100%;">
                            🗑️ Clear Filters
                        </button>
                    </div>
                </div>
            </div>

            <div class="heatmap-content">
                <div style="background: var(--secondary-bg, #e3f2fd); border-left: 4px solid var(--primary-color, #2196f3); padding: 12px 15px; margin-bottom: 20px; border-radius: 4px; font-size: 14px; color: var(--text-color, #1565c0);">
                    <strong>📅 Coverage Heatmap:</strong> This heatmap shows coverage for the selected date range (defaults to current week). Use the date filters to view different time periods. Employees on PTO, sick leave, or marked as off are automatically excluded from the coverage calculations.
                </div>
                <div id="heatmapStatsContainer" class="stats-container"></div>
                <div id="heatmapGridContainer" class="heatmap-container">
                    <div class="loading">Loading heatmap data...</div>
                </div>
                <div class="legend">
                    <div class="legend-item"><strong>Coverage Intensity:</strong></div>
                    <div class="legend-item"><div class="legend-color intensity-0"></div><span>0-12%</span></div>
                    <div class="legend-item"><div class="legend-color intensity-2"></div><span>25%</span></div>
                    <div class="legend-item"><div class="legend-color intensity-4"></div><span>50%</span></div>
                    <div class="legend-item"><div class="legend-color intensity-6"></div><span>75%</span></div>
                    <div class="legend-item"><div class="legend-color intensity-8"></div><span>100%</span></div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════ -->
            <!-- SKILLS COVERAGE HEATMAP                        -->
            <!-- ══════════════════════════════════════════════ -->
            <div style="border-top: 3px solid var(--primary-color, #333399); margin-top: 32px; padding-top: 28px;">

                <div style="display:flex; align-items:center; gap:12px; margin-bottom:18px; flex-wrap:wrap;">
                    <h2 style="margin:0; color:var(--primary-color,#333399); font-size:20px;">🎯 Skills Coverage Heatmap</h2>
                    <span style="font-size:13px; color:var(--text-muted,#64748b);">View skill-specific coverage — select a skill or view all side-by-side</span>
                </div>

                <!-- Skills Heatmap Controls -->
                <div class="heatmap-controls">
                    <div class="control-group">

                        <div class="control-item">
                            <label for="skHeatmapSkillFilter">Skill</label>
                            <select id="skHeatmapSkillFilter" onchange="updateSkillsHeatmapData()">
                                <option value="all">All Skills</option>
                                <option value="mh">🔵 Managed Hosting (MH)</option>
                                <option value="ma">🟣 Managed Apps (MA)</option>
                                <option value="win">🟢 Windows (Win)</option>
                            </select>
                        </div>

                        <div class="control-item">
                            <label for="skHeatmapTeamFilter">Team</label>
                            <select id="skHeatmapTeamFilter" onchange="updateSkillsHeatmapData()">
                                <option value="all">All Teams</option>
                                <?php foreach ($teams as $key => $name): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="control-item">
                            <label for="skHeatmapDateFrom">From Date</label>
                            <input type="date" id="skHeatmapDateFrom" onchange="updateSkillsHeatmapData()" style="padding:8px; border:1px solid #ccc; border-radius:4px; font-size:13px;">
                        </div>

                        <div class="control-item">
                            <label for="skHeatmapDateTo">To Date</label>
                            <input type="date" id="skHeatmapDateTo" onchange="updateSkillsHeatmapData()" style="padding:8px; border:1px solid #ccc; border-radius:4px; font-size:13px;">
                        </div>

                        <div class="control-item">
                            <label for="skHeatmapTimeFrom">From Time</label>
                            <select id="skHeatmapTimeFrom" onchange="updateSkillsHeatmapData()">
                                <option value="all">All Hours</option>
                                <option value="0">12:00 AM</option><option value="1">1:00 AM</option><option value="2">2:00 AM</option>
                                <option value="3">3:00 AM</option><option value="4">4:00 AM</option><option value="5">5:00 AM</option>
                                <option value="6">6:00 AM</option><option value="7">7:00 AM</option><option value="8">8:00 AM</option>
                                <option value="9">9:00 AM</option><option value="10">10:00 AM</option><option value="11">11:00 AM</option>
                                <option value="12" selected>12:00 PM</option><option value="13">1:00 PM</option><option value="14">2:00 PM</option>
                                <option value="15">3:00 PM</option><option value="16">4:00 PM</option><option value="17">5:00 PM</option>
                                <option value="18">6:00 PM</option><option value="19">7:00 PM</option><option value="20">8:00 PM</option>
                                <option value="21">9:00 PM</option><option value="22">10:00 PM</option><option value="23">11:00 PM</option>
                            </select>
                        </div>

                        <div class="control-item">
                            <label for="skHeatmapTimeTo">To Time</label>
                            <select id="skHeatmapTimeTo" onchange="updateSkillsHeatmapData()">
                                <option value="all">All Hours</option>
                                <option value="0" selected>12:00 AM</option><option value="1">1:00 AM</option><option value="2">2:00 AM</option>
                                <option value="3">3:00 AM</option><option value="4">4:00 AM</option><option value="5">5:00 AM</option>
                                <option value="6">6:00 AM</option><option value="7">7:00 AM</option><option value="8">8:00 AM</option>
                                <option value="9">9:00 AM</option><option value="10">10:00 AM</option><option value="11">11:00 AM</option>
                                <option value="12">12:00 PM</option><option value="13">1:00 PM</option><option value="14">2:00 PM</option>
                                <option value="15">3:00 PM</option><option value="16">4:00 PM</option><option value="17">5:00 PM</option>
                                <option value="18">6:00 PM</option><option value="19">7:00 PM</option><option value="20">8:00 PM</option>
                                <option value="21">9:00 PM</option><option value="22">10:00 PM</option><option value="23">11:00 PM</option>
                            </select>
                        </div>

                        <div class="control-item">
                            <label for="skHeatmapLevelFilter">Level</label>
                            <select id="skHeatmapLevelFilter" onchange="updateSkillsHeatmapData()">
                                <option value="all">All Levels</option>
                                <?php foreach ($levels as $key => $name): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="control-item">
                            <label for="skHeatmapSupervisorFilter">Supervisor</label>
                            <select id="skHeatmapSupervisorFilter" onchange="updateSkillsHeatmapData()">
                                <option value="all">All Supervisors</option>
                                <option value="none">No Supervisor</option>
                                <?php foreach ($supervisorsAndManagers as $supervisor): ?>
                                    <option value="<?php echo $supervisor['id']; ?>"><?php echo escapeHtml($supervisor['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="control-item">
                            <label for="skHeatmapDayFilter">Day</label>
                            <select id="skHeatmapDayFilter" onchange="updateSkillsHeatmapData()">
                                <option value="all">All Days</option>
                                <option value="0">Sunday</option><option value="1">Monday</option><option value="2">Tuesday</option>
                                <option value="3">Wednesday</option><option value="4">Thursday</option><option value="5">Friday</option>
                                <option value="6">Saturday</option>
                            </select>
                        </div>

                        <div class="control-item">
                            <label for="skHeatmapShiftFilter">Shift</label>
                            <select id="skHeatmapShiftFilter" onchange="updateSkillsHeatmapData()">
                                <option value="all">All Shifts</option>
                                <option value="1">1st Shift</option>
                                <option value="2">2nd Shift</option>
                                <option value="3">3rd Shift</option>
                            </select>
                        </div>

                        <div class="control-item">
                            <label style="visibility:hidden;">Refresh</label>
                            <button onclick="refreshSkillsHeatmap()" style="padding:8px 16px; background:var(--primary-color,#333399) !important; color:white !important; border:none; border-radius:4px; cursor:pointer; font-size:13px; font-weight:bold; width:100%;">
                                🔄 Refresh
                            </button>
                        </div>

                        <div class="control-item">
                            <label style="visibility:hidden;">Clear</label>
                            <button onclick="clearSkillsHeatmapFilters()" style="padding:8px 16px; background:var(--danger-color,#dc3545) !important; color:white !important; border:none; border-radius:4px; cursor:pointer; font-size:13px; font-weight:bold; width:100%;">
                                🗑️ Clear Filters
                            </button>
                        </div>

                    </div>
                </div>

                <!-- Skills Heatmap Content -->
                <div class="heatmap-content">
                    <div style="background:var(--secondary-bg,#f0f0ff); border-left:4px solid var(--primary-color,#333399); padding:12px 15px; margin-bottom:20px; border-radius:4px; font-size:14px; color:var(--text-color,#1e293b);">
                        <strong>🎯 Skills Heatmap:</strong> Shows how many employees with the selected skill are scheduled per hour.
                        When <strong>All Skills</strong> is selected, each date shows three rows — one per skill (🔵 MH, 🟣 MA, 🟢 Win).
                        Click any cell to see which employees are working.
                    </div>
                    <div id="skHeatmapStatsContainer" class="stats-container"></div>
                    <div id="skHeatmapGridContainer" class="heatmap-container">
                        <div class="loading">Loading skills heatmap data...</div>
                    </div>
                    <div class="legend">
                        <div class="legend-item">
                            <span style="background:#1976d2;color:#fff !important;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:bold;">MH</span>
                            <span style="background:#7b1fa2;color:#fff !important;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:bold;margin-left:4px;">MA</span>
                            <span style="background:#2e7d32;color:#fff !important;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:bold;margin-left:4px;">Win</span>
                        </div>
                        <div class="legend-item"><strong>Skill Intensity:</strong></div>
                        <div class="legend-item"><div class="legend-color intensity-0"></div><span>0-12%</span></div>
                        <div class="legend-item"><div class="legend-color intensity-2"></div><span>25%</span></div>
                        <div class="legend-item"><div class="legend-color intensity-4"></div><span>50%</span></div>
                        <div class="legend-item"><div class="legend-color intensity-6"></div><span>75%</span></div>
                        <div class="legend-item"><div class="legend-color intensity-8"></div><span>100%</span></div>
                    </div>
                </div>

            </div><!-- end skills heatmap -->
        </div>

        <!-- Settings Tab -->
        <div id="settings-tab" class="tab-content<?php echo ($activeTab === 'settings') ? ' active' : ''; ?>">
            <?php 
            try {
                // Try to call the function
                $content = getUserManagementHTML();
                if (empty($content)) {
                    echo '<div style="padding: 40px; background: #fff3cd; border: 3px solid #ffc107;">';
                    echo '<h2>⚠️ Settings Tab - Empty Content</h2>';
                    echo '<p>The getUserManagementHTML() function returned empty content.</p>';
                    echo '<p><strong>Possible reasons:</strong></p>';
                    echo '<ul>';
                    echo '<li>Permission check failed (you need "manage_users" permission)</li>';
                    echo '<li>Function returned blank due to an error</li>';
                    echo '</ul>';
                    echo '<p>Your role: ' . ($_SESSION['user_role'] ?? 'unknown') . '</p>';
                    echo '</div>';
                } else {
                    echo $content;
                }
            } catch (Exception $e) {
                echo '<div style="padding: 40px; background: #f8d7da; border: 3px solid #dc3545;">';
                echo '<h2>❌ Settings Tab - Error</h2>';
                echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
                echo '</div>';
            }
            ?>

            <?php if ($isAdminOrSupervisor): ?>
            <!-- Hours format conversion utility -->
            <div style="max-width:860px; margin:20px auto; padding:20px; background:var(--card-bg,#fff); border:1px solid var(--border-color,#dee2e6); border-left:5px solid #6c5ce7; border-radius:8px;">
                <h3 style="margin:0 0 8px; font-size:15px; color:#6c5ce7;">🕐 Convert Employee Hours to 12-Hour Format</h3>
                <p style="margin:0 0 14px; font-size:13px; color:var(--text-muted,#64748b);">
                    If any employee hours are stored in 24-hour format (e.g., <code>9-17</code>, <code>15-23</code>) this will automatically convert them all to 12-hour format (e.g., <code>9am-5pm</code>, <code>3pm-11pm</code>). Records already in 12-hour format are left unchanged.
                </p>
                <form method="POST">
                    <input type="hidden" name="action" value="convert_hours_to_12h">
                    <input type="hidden" name="current_tab" value="settings">
                    <button type="submit" onclick="return confirm('Convert all employee hours to 12-hour format? Existing 12-hour values will not be changed.');" style="font-size:13px; padding:8px 20px; border-radius:5px;">
                        🔄 Convert All Hours to 12-Hour Format
                    </button>
                </form>
            </div>
            <?php endif; ?>

        </div>

        <!-- Backup Management Tab -->
        <?php if (hasPermission('manage_backups')): ?>
        <div id="backups-tab" class="tab-content<?php echo ($activeTab === 'backups') ? ' active' : ''; ?>">
            <div style="max-width: 1400px; margin: 0 auto;">
                <h2 style="margin-bottom: 20px;">💾 Backup Management</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px;">
                    
                    <!-- Total Backups Card -->
                    <div style="background: var(--card-bg, white); padding: 25px; border-radius: 12px; color: var(--text-color, #2d3748); box-shadow: 0 4px 6px rgba(0,0,0,0.1); border: 1px solid var(--border-color, #e2e8f0);">
                        <div style="font-size: 14px; opacity: 0.8; margin-bottom: 10px; color: var(--primary-color, #667eea);">💾 Total Snapshots</div>
                        <div style="font-size: 48px; font-weight: bold; margin-bottom: 5px; color: var(--primary-color, #667eea);"><?php echo $backupCount; ?></div>
                        <div style="font-size: 12px; opacity: 0.7; color: var(--text-muted, #666);">Database snapshots stored</div>
                    </div>
                    
                    <!-- Latest Backup Card -->
                    <div style="background: var(--card-bg, white); padding: 25px; border-radius: 12px; color: var(--text-color, #2d3748); box-shadow: 0 4px 6px rgba(0,0,0,0.1); border: 1px solid var(--border-color, #e2e8f0);">
                        <div style="font-size: 14px; opacity: 0.8; margin-bottom: 10px; color: var(--accent-color, #f093fb);">🕐 Latest Backup</div>
                        <div style="font-size: 18px; font-weight: bold; margin-bottom: 5px; color: var(--accent-color, #f093fb);">
                            <?php
                            if (!empty($latestSnapshotName)) {
                                $shortName = mb_strimwidth($latestSnapshotName, 0, 30, '...');
                                echo htmlspecialchars($shortName);
                            } else {
                                echo 'No backups yet';
                            }
                            ?>
                        </div>
                        <div style="font-size: 12px; opacity: 0.7; color: var(--text-muted);">Most recent snapshot</div>
                    </div>
                    
                    <!-- Storage Type Card -->
                    <div style="background: var(--card-bg, white); padding: 25px; border-radius: 12px; color: var(--text-color, #2d3748); box-shadow: 0 4px 6px rgba(0,0,0,0.1); border: 1px solid var(--border-color, #e2e8f0);">
                        <div style="font-size: 14px; opacity: 0.8; margin-bottom: 10px; color: var(--success-color, #48bb78);">🗄️ Storage</div>
                        <div style="font-size: 24px; font-weight: bold; margin-bottom: 5px; color: var(--success-color, #48bb78);">Database</div>
                        <div style="font-size: 12px; opacity: 0.7; color: var(--text-muted, #666);">Snapshots stored in MySQL</div>
                    </div>
                    
                </div>
                
                <!-- Backup Actions Section -->
                <div style="background: var(--card-bg, white); padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 30px; border: 1px solid var(--border-color, #e2e8f0);">
                    <h3 style="margin: 0 0 20px 0; color: var(--text-color, #2d3748);">⚡ Quick Actions</h3>
                    <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                        <form method="POST" style="flex: 1; min-width: 200px;">
                            <input type="hidden" name="action" value="download_backup">
                            <button type="submit" style="width: 100%; background: var(--primary-color, #667eea); color: white; border: none; padding: 15px 20px; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.opacity='0.9'" onmouseout="this.style.transform='translateY(0)'; this.style.opacity='1'">
                                📂 Download Live Data
                            </button>
                        </form>
                        
                        <form method="POST" style="flex: 1; min-width: 200px;">
                            <input type="hidden" name="action" value="create_backup">
                            <input type="hidden" name="current_tab" value="backups">
                            <button type="submit" style="width: 100%; background: var(--accent-color, #f093fb); color: white; border: none; padding: 15px 20px; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.opacity='0.9'" onmouseout="this.style.transform='translateY(0)'; this.style.opacity='1'">
                                💾 Create New Backup
                            </button>
                        </form>
                        
                        <form method="POST" enctype="multipart/form-data" style="flex: 1; min-width: 200px;">
                            <input type="hidden" name="action" value="upload_backup">
                            <input type="hidden" name="current_tab" value="backups">
                            <input type="file" name="backup_file" accept=".json,.zip" style="display: none;" id="backupFileInputTab" onchange="this.form.submit();">
                            <button type="button" onclick="document.getElementById('backupFileInputTab').click();" title="Saves the file as a snapshot — does not restore immediately. Choose Merge or Restore All from the list." style="width: 100%; background: var(--success-color, #48bb78); color: white; border: none; padding: 15px 20px; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.opacity='0.9'" onmouseout="this.style.transform='translateY(0)'; this.style.opacity='1'">
                                📤 Upload Backup (.zip / .json)
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Schedule Backup Section -->
                <?php if (!empty($autoBackupMessage)): ?>
                <div style="background:#f0fff4; border:1px solid #48bb78; color:#276749; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-size:14px;">
                    <?php echo htmlspecialchars($autoBackupMessage); ?>
                </div>
                <?php endif; ?>

                <div style="background: var(--card-bg, white); padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 30px; border: 1px solid var(--border-color, #e2e8f0);">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
                        <h3 style="margin:0; color:var(--text-color,#2d3748);">🕐 Schedule Automatic Backups</h3>
                        <?php if ($backupSchedule['frequency'] !== 'disabled'): ?>
                        <div style="background:#f0fff4; border:1px solid #48bb78; color:#276749; padding:6px 14px; border-radius:20px; font-size:13px; font-weight:600;">
                            ✅ Active &nbsp;·&nbsp; Next: <?php echo htmlspecialchars($nextBackupLabel); ?>
                        </div>
                        <?php else: ?>
                        <div style="background:#fff5f5; border:1px solid #fc8181; color:#c53030; padding:6px 14px; border-radius:20px; font-size:13px; font-weight:600;">
                            ⏸ Disabled
                        </div>
                        <?php endif; ?>
                    </div>

                    <p style="margin:0 0 20px; font-size:13px; color:var(--text-muted,#666);">
                        Backups run automatically when any user visits the site during the scheduled window.
                        Only one auto-backup runs per day even if multiple users are active.
                    </p>

                    <form method="POST">
                        <input type="hidden" name="action" value="save_backup_schedule">
                        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-bottom:20px;">

                            <!-- Frequency -->
                            <div>
                                <label style="display:block; font-size:12px; font-weight:600; color:var(--text-muted,#666); text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px;">Frequency</label>
                                <select name="backup_frequency" id="bsFreq" onchange="toggleBackupScheduleFields()" style="width:100%; padding:10px 12px; border:1px solid var(--border-color,#e2e8f0); border-radius:8px; background:var(--input-bg,white); color:var(--text-color,#2d3748); font-size:14px;">
                                    <option value="disabled" <?php echo $backupSchedule['frequency']==='disabled' ? 'selected' : ''; ?>>⏸ Disabled</option>
                                    <option value="daily"    <?php echo $backupSchedule['frequency']==='daily'    ? 'selected' : ''; ?>>📅 Daily</option>
                                    <option value="weekly"   <?php echo $backupSchedule['frequency']==='weekly'   ? 'selected' : ''; ?>>📆 Weekly (Mon)</option>
                                    <option value="monthly"  <?php echo $backupSchedule['frequency']==='monthly'  ? 'selected' : ''; ?>>🗓 Monthly (1st)</option>
                                </select>
                            </div>

                            <!-- Time (hidden when disabled) -->
                            <div id="bsTimeWrap">
                                <label style="display:block; font-size:12px; font-weight:600; color:var(--text-muted,#666); text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px;">Time (ET)</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <select name="backup_hour" style="flex:1; padding:10px 8px; border:1px solid var(--border-color,#e2e8f0); border-radius:8px; background:var(--input-bg,white); color:var(--text-color,#2d3748); font-size:14px;">
                                        <?php for ($h = 0; $h < 24; $h++): ?>
                                        <option value="<?php echo $h; ?>" <?php echo (int)$backupSchedule['hour']===$h ? 'selected' : ''; ?>>
                                            <?php echo date('g A', mktime($h, 0, 0)); ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                    <span style="color:var(--text-muted,#666); font-size:13px;">:</span>
                                    <select name="backup_minute" style="flex:1; padding:10px 8px; border:1px solid var(--border-color,#e2e8f0); border-radius:8px; background:var(--input-bg,white); color:var(--text-color,#2d3748); font-size:14px;">
                                        <?php foreach ([0,15,30,45] as $m): ?>
                                        <option value="<?php echo $m; ?>" <?php echo (int)$backupSchedule['minute']===$m ? 'selected' : ''; ?>>
                                            <?php echo sprintf('%02d', $m); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Retention -->
                            <div id="bsKeepWrap">
                                <label style="display:block; font-size:12px; font-weight:600; color:var(--text-muted,#666); text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px;">Keep Last</label>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <input type="number" name="backup_keep" min="1" max="90" value="<?php echo (int)$backupSchedule['keep']; ?>"
                                           style="width:80px; padding:10px 12px; border:1px solid var(--border-color,#e2e8f0); border-radius:8px; background:var(--input-bg,white); color:var(--text-color,#2d3748); font-size:14px;">
                                    <span style="font-size:13px; color:var(--text-muted,#666);">auto backups</span>
                                </div>
                                <div style="font-size:11px; color:var(--text-muted,#888); margin-top:4px;">Older auto backups are pruned automatically</div>
                            </div>

                        </div>

                        <button type="submit" style="background:var(--primary-color,#667eea); color:white; border:none; padding:12px 28px; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer;">
                            💾 Save Schedule
                        </button>
                    </form>
                </div>

                <!-- Backup List Section -->
                <div style="background: var(--card-bg, white); padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 1px solid var(--border-color, #e2e8f0);">
                    <h3 style="margin: 0 0 20px 0; color: var(--text-color, #fff) !important;">📋 All Backups</h3>
                    <div id="backupList">
                        <?php
                        $allSnapshots = [];
                        if (file_exists(__DIR__ . '/Database.php')) {
                            try {
                                require_once __DIR__ . '/Database.php';
                                $dbList = Database::getInstance();
                                $allSnapshots = $dbList->fetchAll(
                                    "SELECT bs.*, bd_emp.record_count as emp_count,
                                            bd_ov.record_count as ov_count
                                     FROM backup_snapshots bs
                                     LEFT JOIN backup_data bd_emp ON bd_emp.snapshot_id = bs.id AND bd_emp.table_name = 'employees'
                                     LEFT JOIN backup_data bd_ov  ON bd_ov.snapshot_id  = bs.id AND bd_ov.table_name  = 'schedule_overrides'
                                     ORDER BY bs.created_at DESC"
                                );
                            } catch (Exception $e) {
                                echo '<p style="color:#c00;">DB error loading backups: ' . htmlspecialchars($e->getMessage()) . '</p>';
                            }
                        }
                        
                        $typeLabels = [
                            'manual'      => ['label' => 'Manual',      'color' => '#667eea', 'bg' => '#ebf4ff'],
                            'auto'        => ['label' => 'Auto',         'color' => '#48bb78', 'bg' => '#f0fff4'],
                            'pre_restore' => ['label' => 'Pre-Restore',  'color' => '#ed8936', 'bg' => '#fffaf0'],
                            'pre_cleanup' => ['label' => 'Pre-Cleanup',  'color' => '#e53e3e', 'bg' => '#fff5f5'],
                            'json_import' => ['label' => 'JSON Import',  'color' => '#805ad5', 'bg' => '#faf5ff'],
                        ];
                        
                        if (empty($allSnapshots)) {
                            echo '<p style="color: var(--text-muted, #666); font-style: italic; text-align: center; padding: 40px;">No backups yet. Click &quot;Create Backup&quot; above to save the current state.</p>';
                        } else {
                            // Standalone bulk delete form — NOT wrapping the table to avoid nested form issues
                            echo '<form method="POST" id="bulkDeleteForm" style="display:none;">';
                            echo '<input type="hidden" name="action" value="bulk_delete_backups">';
                            echo '<input type="hidden" name="current_tab" value="backups">';
                            echo '<input type="hidden" name="bulk_snapshot_ids" id="bulkSnapshotIds" value="">';
                            echo '</form>';
                            echo '<div id="bulkDeleteToolbar" style="display:none; align-items:center; gap:10px; margin-bottom:10px; padding:8px 12px; background:var(--secondary-color,#334155); border:1px solid var(--border-color,#e2e8f0); border-radius:8px;">';
                            echo '<span id="bulkSelectedCount" style="font-size:13px; font-weight:600; color:white;">0 selected</span>';
                            echo '<button type="button" onclick="doBulkDelete()" style="background:#f56565;color:white;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;">🗑️ Delete Selected</button>';
                            echo '<button type="button" onclick="clearBulkSelection()" style="background:var(--secondary-color,#718096);color:white;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:13px;">✕ Clear</button>';
                            echo '</div>';

                            echo '<div style="overflow-x: auto;">';
                            echo '<table style="width: 100%; border-collapse: collapse;">';
                            echo '<thead>';
                            echo '<tr style="background: var(--secondary-color, #334155); border-bottom: 2px solid var(--border-color, #e2e8f0);">';
                            echo '<th style="padding: 12px; width:36px;"><input type="checkbox" id="bulkSelectAll" onchange="toggleAllBackups(this)" title="Select all" style="cursor:pointer;width:15px;height:15px;"></th>';
                            echo '<th style="padding: 12px; text-align: left; font-weight: 600; color: white;">💾 Backup Name</th>';
                            echo '<th style="padding: 12px; text-align: left; font-weight: 600; color: white;">🏷️ Type</th>';
                            echo '<th style="padding: 12px; text-align: left; font-weight: 600; color: white;">📅 Date Created</th>';
                            echo '<th style="padding: 12px; text-align: left; font-weight: 600; color: white;">👥 Records</th>';
                            echo '<th style="padding: 12px; text-align: left; font-weight: 600; color: white;">Actions</th>';
                            echo '</tr>';
                            echo '</thead>';
                            echo '<tbody>';

                            foreach ($allSnapshots as $snap) {
                                $snapId   = (int)$snap['id'];
                                $snapName = $snap['snapshot_name'];
                                $snapType = $snap['backup_type'] ?? 'manual';
                                $snapDt = new DateTime($snap['created_at'], new DateTimeZone('UTC'));
                                $snapDt->setTimezone(new DateTimeZone('America/New_York'));
                                $snapDate = $snapDt->getTimestamp();
                                $empCount = (int)($snap['emp_count'] ?? 0);
                                $ovCount  = (int)($snap['ov_count']  ?? 0);

                                $tl = $typeLabels[$snapType] ?? ['label' => ucfirst($snapType), 'color' => '#666', 'bg' => '#f0f0f0'];
                                $badge = '<span style="background:' . $tl['bg'] . '; color:' . $tl['color'] . '; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600;">' . $tl['label'] . '</span>';

                                echo '<tr style="border-bottom: 1px solid var(--border-color, #e2e8f0); transition: background 0.2s; color: var(--text-color, #2d3748);" onmouseover="this.style.background=\'rgba(255,255,255,0.12)\'" onmouseout="this.style.background=\'transparent\'">';
                                echo '<td style="padding: 12px;"><input type="checkbox" class="backup-checkbox" value="' . $snapId . '" onchange="updateBulkToolbar()" style="cursor:pointer;width:15px;height:15px;"></td>';
                                echo '<td style="padding: 12px;"><strong>' . escapeHtml($snapName) . '</strong>';
                                if (!empty($snap['description'])) {
                                    echo '<br><small style="color: var(--text-muted,#888);">' . escapeHtml($snap['description']) . '</small>';
                                }
                                echo '</td>';
                                echo '<td style="padding: 12px;">' . $badge . '</td>';
                                echo '<td style="padding: 12px;">' . date('M j, Y g:i A', $snapDate) . ' ET</td>';
                                echo '<td style="padding: 12px;">' . $empCount . ' emp / ' . $ovCount . ' overrides</td>';
                                echo '<td style="padding: 12px; white-space: nowrap;">';
                                
                                // Restore All
                                echo '<form method="POST" style="display: inline;">';
                                echo '<input type="hidden" name="action" value="restore_backup">';
                                echo '<input type="hidden" name="current_tab" value="backups">';
                                echo '<input type="hidden" name="snapshot_id" value="' . $snapId . '">';
                                echo '<button type="submit" onclick="return confirm(\'Restore ALL data from this backup? This will overwrite everything — current data will be auto-saved first.\')" style="background: var(--success-color, #48bb78); color: white; border: none; padding: 6px 10px; border-radius: 6px; cursor: pointer; margin-right: 3px; font-size: 12px;">🔄 Restore All</button>';
                                echo '</form>';

                                // Merge Restore (differential)
                                echo '<form method="POST" style="display: inline;" id="mergeForm_' . $snapId . '">';
                                echo '<input type="hidden" name="action" value="merge_restore_backup">';
                                echo '<input type="hidden" name="current_tab" value="backups">';
                                echo '<input type="hidden" name="snapshot_id" value="' . $snapId . '">';
                                echo '<input type="hidden" name="force_overwrite" id="forceOverwrite_' . $snapId . '" value="0">';
                                echo '<button type="button" onclick="doMergeRestore(' . $snapId . ')" style="background: var(--accent-color, #805ad5); color: white; border: none; padding: 6px 10px; border-radius: 6px; cursor: pointer; margin-right: 3px; font-size: 12px;">🔀 Merge</button>';
                                echo '</form>';

                                // Selective Restore
                                $jsSnapName = addslashes(htmlspecialchars_decode($snapName));
                                echo '<button type="button" onclick="openSelectiveRestoreModal(' . $snapId . ', \'' . $jsSnapName . '\')" style="background: var(--primary-color, #667eea); color: white; border: none; padding: 6px 10px; border-radius: 6px; cursor: pointer; margin-right: 3px; font-size: 12px;">👥 Selective</button>';
                                
                                // Download as ZIP
                                echo '<form method="POST" style="display: inline;">';
                                echo '<input type="hidden" name="action" value="download_backup">';
                                echo '<input type="hidden" name="snapshot_id" value="' . $snapId . '">';
                                echo '<button type="submit" style="background: var(--secondary-color, #718096); color: white; border: none; padding: 6px 10px; border-radius: 6px; cursor: pointer; margin-right: 3px; font-size: 12px;">⬇️ Download</button>';
                                echo '</form>';
                                
                                // Delete
                                echo '<form method="POST" style="display: inline;">';
                                echo '<input type="hidden" name="action" value="delete_backup">';
                                echo '<input type="hidden" name="current_tab" value="backups">';
                                echo '<input type="hidden" name="snapshot_id" value="' . $snapId . '">';
                                echo '<button type="submit" onclick="return confirm(\'Delete this backup? This cannot be undone.\')" style="background: var(--danger-color, #f56565); color: white; border: none; padding: 6px 10px; border-radius: 6px; cursor: pointer; font-size: 12px;">🗑️ Delete</button>';
                                echo '</form>';
                                
                                echo '</td>';
                                echo '</tr>';
                            }
                            
                            echo '</tbody>';
                            echo '</table>';
                            echo '</div>';
                            echo '<script>';
                            echo 'function updateBulkToolbar(){';
                            echo '  var checked=document.querySelectorAll(".backup-checkbox:checked");';
                            echo '  var toolbar=document.getElementById("bulkDeleteToolbar");';
                            echo '  var countEl=document.getElementById("bulkSelectedCount");';
                            echo '  toolbar.style.display=checked.length>0?"flex":"none";';
                            echo '  countEl.textContent=checked.length+" selected";';
                            echo '  var all=document.querySelectorAll(".backup-checkbox");';
                            echo '  document.getElementById("bulkSelectAll").indeterminate=checked.length>0&&checked.length<all.length;';
                            echo '  document.getElementById("bulkSelectAll").checked=checked.length===all.length&&all.length>0;';
                            echo '}';
                            echo 'function toggleAllBackups(cb){';
                            echo '  document.querySelectorAll(".backup-checkbox").forEach(function(c){c.checked=cb.checked;});';
                            echo '  updateBulkToolbar();';
                            echo '}';
                            echo 'function clearBulkSelection(){';
                            echo '  document.querySelectorAll(".backup-checkbox").forEach(function(c){c.checked=false;});';
                            echo '  document.getElementById("bulkSelectAll").checked=false;';
                            echo '  updateBulkToolbar();';
                            echo '}';
                            echo 'function doBulkDelete(){';
                            echo '  var ids=Array.from(document.querySelectorAll(".backup-checkbox:checked")).map(function(c){return c.value;});';
                            echo '  if(ids.length===0){alert("No backups selected.");return;}';
                            echo '  if(!confirm("Delete "+ids.length+" backup(s)? This cannot be undone.")){return;}';
                            echo '  document.getElementById("bulkSnapshotIds").value=ids.join(",");';
                            echo '  document.getElementById("bulkDeleteForm").submit();';
                            echo '}';
                            echo '</script>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Hidden Edit Employee Tab (accessed via URL parameter or gear icon) -->
        <div id="edit-employee-tab" class="tab-content<?php echo ($activeTab === 'edit-employee') ? ' active' : ''; ?>" style="<?php echo ($activeTab !== 'edit-employee') ? 'display: none;' : ''; ?>">
            
            <div style="max-width: 1200px; margin: 0 auto; padding: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
                    <div>
                        <h2 style="margin: 0 0 5px 0;">⚙️ Edit Employee</h2>
                        <p style="margin: 0; opacity: 0.7; font-size: 14px;">Modify employee schedule, team, skills, and settings</p>
                    </div>
                    <button onclick="showTab('schedule')" 
                            style="background: var(--secondary-color, #6c757d); color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600;">
                        ← Back to Schedule
                    </button>
                </div>
                
                <?php
                // Get employee ID from URL if present
                $editEmployeeId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;
                $editEmployee = null;
                
                if ($editEmployeeId) {
                    // Find the employee
                    foreach ($employees as $emp) {
                        if ($emp['id'] == $editEmployeeId) {
                            $editEmployee = $emp;
                            break;
                        }
                    }
                }
                
                // ACCESS CONTROL: Check if user can edit this employee
                $canEditThisEmployee = false;
                $isEditingOwnProfile = false;
                
                // Check user role from $currentUser (not session!)
                $userRole = $currentUser['role'] ?? 'employee';
                
                if (in_array($userRole, ['admin', 'manager', 'supervisor'])) {
                    // Admins, managers, supervisors can edit anyone
                    $canEditThisEmployee = true;
                } elseif ($userRole === 'employee' && $editEmployee) {
                    // Employees can only edit themselves
                    $currentUserEmp = getCurrentUserEmployee();
                    if ($currentUserEmp && $currentUserEmp['id'] == $editEmployee['id']) {
                        $canEditThisEmployee = true;
                        $isEditingOwnProfile = true;
                    }
                }
                
                // If no permission, show error message
                if (!$canEditThisEmployee):
                ?>
                    <div style="background: #fee2e2; border: 2px solid #dc2626; border-radius: 8px; padding: 30px; text-align: center;">
                        <div style="font-size: 48px; margin-bottom: 20px;">🚫</div>
                        <h3 style="color: #991b1b; margin-bottom: 10px;">Access Denied</h3>
                        <p style="color: #7f1d1d; margin-bottom: 20px;">
                            You do not have permission to edit this employee's profile.<br>
                            Employees can only edit their own schedule and profile.
                        </p>
                        <button onclick="showTab('schedule')" 
                                style="background: #3b82f6; color: white; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600;">
                            ← Return to Schedule
                        </button>
                    </div>
            </div>
        </div>
        <?php else: // User has permission - continue with form rendering ?>
                
                <?php
                // Use first employee as default if none specified (for initial render)
                if (!$editEmployee && !empty($employees)) {
                    $editEmployee = $employees[0];
                }
                
                // Default values if still no employee
                if (!$editEmployee) {
                    $editEmployee = [
                        'id' => 0,
                        'name' => '',
                        'team' => 'esg',
                        'shift' => 1,
                        'level' => '',
                        'email' => '',
                        'hours' => '',
                        'supervisor_id' => '',
                        'schedule' => [0, 1, 1, 1, 1, 1, 0],
                        'skills' => ['mh' => false, 'ma' => false, 'win' => false]
                    ];
                }
                
                // Auto-match email from user management
                $emailToUse = $editEmployee['email'] ?? '';
                if (empty($emailToUse)) {
                    foreach ($users as $user) {
                        if (isset($user['full_name']) && isset($editEmployee['name']) &&
                            strtolower(trim($user['full_name'])) === strtolower(trim($editEmployee['name']))) {
                            if (!empty($user['email'])) {
                                $emailToUse = $user['email'];
                                break;
                            }
                        }
                    }
                }
                
                $schedule = $editEmployee['schedule'] ?? [0, 1, 1, 1, 1, 1, 0];
                $skills = $editEmployee['skills'] ?? ['mh' => false, 'ma' => false, 'win' => false];
                $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                
                // Determine what fields the current user can edit ($isEditingOwnProfile already set above)
                $canEditAllFields = hasPermission('manage_employees') || hasPermission('edit_schedule');
                
                // Employees can only edit: shift, schedule days, email
                // Employees CANNOT edit: name, team, level, schedule_access, supervisor, skills, hours
                $fieldReadOnly = !$canEditAllFields && $isEditingOwnProfile ? 'readonly style="background: #f5f5f5; cursor: not-allowed;"' : '';
                $fieldDisabled = !$canEditAllFields && $isEditingOwnProfile ? 'disabled style="background: #f5f5f5; cursor: not-allowed;"' : '';

                // Resolve profile photo for the Edit Employee header
                $editEmpLinkedUser = null;
                $editEmpPhotoUrl   = null;
                $editEmpInitials   = strtoupper(substr($editEmployee['name'] ?? 'U', 0, 2));
                if (!empty($editEmployee['user_id'])) {
                    foreach ($users as $u) {
                        if ($u['id'] == $editEmployee['user_id']) {
                            $editEmpLinkedUser = $u;
                            break;
                        }
                    }
                }
                if ($editEmpLinkedUser) {
                    $editEmpPhotoUrl = getProfilePhotoUrl($editEmpLinkedUser);
                    if (!empty($editEmpLinkedUser['full_name'])) {
                        $editEmpInitials = strtoupper(substr($editEmpLinkedUser['full_name'], 0, 2));
                    }
                }
                ?>
                
                <?php if (!$canEditAllFields && $isEditingOwnProfile): ?>
                <div style="background: #e0f2fe; border-left: 4px solid #0284c7; padding: 15px; margin-bottom: 20px; border-radius: 6px;">
                    <strong>ℹ️ Employee Access:</strong> You can edit your <strong>shift</strong>, <strong>hours</strong>, and <strong>schedule days</strong>. 
                    Other fields require supervisor approval.
                </div>
                <?php endif; ?>
                
                <!-- Edit Employee Form - INLINE VERSION (like bulk-schedule) - ALWAYS PRESENT -->
                <form method="POST" id="editEmployeeForm" style="background: var(--card-bg, white); color: var(--text-color, #333); padding: 30px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <input type="hidden" name="action" value="edit_employee">
                    <input type="hidden" name="empId" id="editEmpId" value="<?php echo $editEmployee['id']; ?>">
                   
                    <!-- Employee Info Header -->
                    <div id="editEmployeeHeader" class="employee-info-header" style="padding: 20px; border-radius: 8px; margin-bottom: 30px; color: white !important;">
                        <h3 style="margin: 0 0 10px 0; font-size: 24px; color: white !important; display: flex; align-items: center; gap: 14px;">
                            <!-- Profile photo or initials avatar -->
                            <div id="editEmpAvatarWrap" style="width: 52px; height: 52px; border-radius: 50%; overflow: hidden; border: 3px solid rgba(255,255,255,0.4); background: rgba(255,255,255,0.2); flex-shrink: 0; display: flex; align-items: center; justify-content: center; position: relative;">
                                <?php if ($editEmpPhotoUrl): ?>
                                    <div style="display:flex;width:100%;height:100%;align-items:center;justify-content:center;font-size:20px;font-weight:bold;color:white !important;">
                                        <?php echo htmlspecialchars($editEmpInitials); ?>
                                    </div>
                                    <img src="<?php echo htmlspecialchars($editEmpPhotoUrl); ?>" alt="Profile Photo"
                                         style="width:100%;height:100%;object-fit:cover;display:none;position:absolute;top:0;left:0;"
                                         onload="this.style.display='';this.previousElementSibling.style.display='none';"
                                         onerror="this.style.display='none';this.previousElementSibling.style.display='flex';">
                                <?php else: ?>
                                    <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: bold; color: white !important;">
                                        <?php echo htmlspecialchars($editEmpInitials); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <span id="editEmployeeHeaderName" style="color: white !important;"><?php echo escapeHtml($editEmployee['name']); ?></span>
                        </h3>
                        <div style="display: flex; gap: 20px; flex-wrap: wrap; font-size: 14px; opacity: 0.9; color: white !important;">
                            <div style="color: white !important;"><strong style="color: white !important;">Team:</strong> <span id="editEmployeeHeaderTeam" style="color: white !important;"><?php echo strtoupper($editEmployee['team']); ?></span></div>
                            <div style="color: white !important;"><strong style="color: white !important;">Shift:</strong> <span id="editEmployeeHeaderShift" style="color: white !important;"><?php echo getShiftName($editEmployee['shift']); ?></span></div>
                            <div style="color: white !important;"><strong style="color: white !important;">ID:</strong> <span id="editEmployeeHeaderId" style="color: white !important;">#<?php echo $editEmployee['id']; ?></span></div>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px;">
                        
                        <!-- Basic Information -->
                        <div class="section-box" style="padding: 20px; border-radius: 8px;">
                            <h4 style="margin: 0 0 15px 0; color: var(--primary-color, #667eea); border-bottom: 2px solid var(--primary-color, #667eea); padding-bottom: 8px;">
                                📋 Basic Information
                            </h4>
                            
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Name:</label>
                                <input type="text" name="empName" id="editEmpName" value="<?php echo escapeHtml($editEmployee['name']); ?>" required 
                                       <?php echo $fieldReadOnly; ?>
                                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;">
                                <?php if ($fieldReadOnly): ?>
                                <div style="font-size: 11px; color: #666; margin-top: 5px;">🔒 Contact supervisor to change name</div>
                                <?php endif; ?>
                            </div>
                            
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Team:</label>
                                <select name="empTeam" id="editEmpTeam" required 
                                        <?php echo $fieldDisabled; ?>
                                        style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;">
                                    <?php foreach ($teams as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $editEmployee['team'] === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($fieldDisabled): ?>
                                <div style="font-size: 11px; color: #666; margin-top: 5px;">🔒 Contact supervisor to change team</div>
                                <?php endif; ?>
                            </div>
                            
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Level:</label>
                                <select name="empLevel" id="editEmpLevel"
                                        <?php echo $fieldDisabled; ?>
                                        style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;">
                                    <?php foreach ($levels as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($editEmployee['level'] ?? '') === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($fieldDisabled): ?>
                                <div style="font-size: 11px; color: #666; margin-top: 5px;">🔒 Contact supervisor to change level</div>
                                <?php endif; ?>
                            </div>
                            
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Email:</label>
                                <input type="email" name="empEmail" id="editEmpEmail" value="<?php echo escapeHtml($emailToUse); ?>"
                                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;"
                                       placeholder="employee@company.com">
                            </div>

                            <div style="margin-bottom: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 12px;">
                                <label style="display:flex;align-items:center;gap:6px;font-weight:600;margin-bottom:6px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="#4A154B"><path d="M5.042 15.165a2.528 2.528 0 0 1-2.52 2.523A2.528 2.528 0 0 1 0 15.165a2.527 2.527 0 0 1 2.522-2.52h2.52v2.52zM6.313 15.165a2.527 2.527 0 0 1 2.521-2.52 2.527 2.527 0 0 1 2.521 2.52v6.313A2.528 2.528 0 0 1 8.834 24a2.528 2.528 0 0 1-2.521-2.522v-6.313zM8.834 5.042a2.528 2.528 0 0 1-2.521-2.52A2.528 2.528 0 0 1 8.834 0a2.528 2.528 0 0 1 2.521 2.522v2.52H8.834zM8.834 6.313a2.528 2.528 0 0 1 2.521 2.521 2.528 2.528 0 0 1-2.521 2.521H2.522A2.528 2.528 0 0 1 0 8.834a2.528 2.528 0 0 1 2.522-2.521h6.312zM18.956 8.834a2.528 2.528 0 0 1 2.522-2.521A2.528 2.528 0 0 1 24 8.834a2.528 2.528 0 0 1-2.522 2.521h-2.522V8.834zM17.688 8.834a2.528 2.528 0 0 1-2.523 2.521 2.527 2.527 0 0 1-2.52-2.521V2.522A2.527 2.527 0 0 1 15.165 0a2.528 2.528 0 0 1 2.523 2.522v6.312zM15.165 18.956a2.528 2.528 0 0 1 2.523 2.522A2.528 2.528 0 0 1 15.165 24a2.527 2.527 0 0 1-2.52-2.522v-2.522h2.52zM15.165 17.688a2.527 2.527 0 0 1-2.52-2.523 2.526 2.526 0 0 1 2.52-2.52h6.313A2.527 2.527 0 0 1 24 15.165a2.528 2.528 0 0 1-2.522 2.523h-6.313z"/></svg>
                                    Slack Member ID
                                </label>
                                <input type="text" name="empSlackId" id="editEmpSlackId"
                                       value="<?php echo htmlspecialchars($editEmployee['slack_id'] ?? ''); ?>"
                                       placeholder="e.g. U01AB2CD3EF"
                                       maxlength="50"
                                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; font-family: monospace;">
                                <div style="font-size: 11px; color: #666; margin-top: 6px; line-height: 1.6;">
                                    Open Slack → click your <strong>profile picture</strong> → <strong>Profile</strong> → <strong>⋯ More</strong> → <strong>"Copy member ID"</strong> (starts with <code style="background:#e9ecef;padding:1px 3px;border-radius:2px;">U</code>).<br>
                                    <span style="color:#27ae60;">✅ Adds a clickable Slack link on the schedule for quick contact.</span>
                                </div>
                            </div>

                            <div style="margin-bottom: 0;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 600;">🔐 Authentication Method:</label>
                                <select name="empAuthMethod" id="editEmpAuthMethod"
                                        style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;">
                                    <?php
                                    // Get auth_method from linked user if available
                                    $authMethod = 'both'; // default
                                    if (isset($editEmployee['user_id'])) {
                                        foreach ($users as $u) {
                                            if ($u['id'] === $editEmployee['user_id']) {
                                                $authMethod = $u['auth_method'] ?? 'both';
                                                break;
                                            }
                                        }
                                    }
                                    ?>
                                    <option value="both" <?php echo $authMethod === 'both' ? 'selected' : ''; ?>>Both (Password + Google SSO)</option>
                                    <option value="password" <?php echo $authMethod === 'password' ? 'selected' : ''; ?>>Password Only</option>
                                    <option value="google" <?php echo $authMethod === 'google' ? 'selected' : ''; ?>>Google SSO Only</option>
                                </select>
                                <div style="font-size: 11px; color: #666; margin-top: 5px;">
                                    How this employee/user can login to the system
                                </div>
                            </div>
                            
                            <!-- Skills -->
                            <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;">
                                <?php if ($fieldDisabled): ?>
                                <div style="font-size: 11px; color: #666; margin-bottom: 10px; background: #f5f5f5; padding: 8px; border-radius: 4px;">
                                    🔒 Contact supervisor to update skills
                                </div>
                                <?php endif; ?>
                                <div>
                                    <label style="display: block; margin-bottom: 10px; font-weight: 600;">🛠️ Technical Skills:</label>
                                    <div style="display: flex; flex-direction: column; gap: 10px;">
                                        <label class="checkbox-label" style="display: flex; align-items: center; padding: 12px; border-radius: 6px; cursor: <?php echo $fieldDisabled ? 'not-allowed' : 'pointer'; ?>;">
                                            <input type="checkbox" name="skillMH" value="1" 
                                                   <?php echo !empty($skills['mh']) ? 'checked' : ''; ?>
                                                   <?php echo $fieldDisabled; ?>
                                                   style="margin-right: 10px; transform: scale(1.2);">
                                            <div>
                                                <div style="font-weight: 600;">Managed Hosting</div>
                                                <div class="skill-description" style="font-size: 12px;">Server management and optimization</div>
                                            </div>
                                        </label>
                                        <label class="checkbox-label" style="display: flex; align-items: center; padding: 12px; border-radius: 6px; cursor: <?php echo $fieldDisabled ? 'not-allowed' : 'pointer'; ?>;">
                                            <input type="checkbox" name="skillMA" value="1" 
                                                   <?php echo !empty($skills['ma']) ? 'checked' : ''; ?>
                                                   <?php echo $fieldDisabled; ?>
                                                   style="margin-right: 10px; transform: scale(1.2);">
                                            <div>
                                                <div style="font-weight: 600;">Managed Applications</div>
                                                <div class="skill-description" style="font-size: 12px;">Application deployment and support</div>
                                            </div>
                                        </label>
                                        <label class="checkbox-label" style="display: flex; align-items: center; padding: 12px; border-radius: 6px; cursor: <?php echo $fieldDisabled ? 'not-allowed' : 'pointer'; ?>;">
                                            <input type="checkbox" name="skillWin" value="1" 
                                                   <?php echo !empty($skills['win']) ? 'checked' : ''; ?>
                                                   <?php echo $fieldDisabled; ?>
                                                   style="margin-right: 10px; transform: scale(1.2);">
                                            <div>
                                                <div style="font-weight: 600;">Windows</div>
                                                <div class="skill-description" style="font-size: 12px;">Windows server administration</div>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Schedule & Shift -->
                        <div class="section-box" style="padding: 20px; border-radius: 8px;">
                            <h4 style="margin: 0 0 15px 0; color: #f093fb; border-bottom: 2px solid #f093fb; padding-bottom: 8px;">
                                📅 Schedule & Shift
                            </h4>
                            
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Shift:</label>
                                <select name="empShift" id="editEmpShift" required 
                                        style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;">
                                    <option value="1" <?php echo $editEmployee['shift'] == 1 ? 'selected' : ''; ?>>1st Shift</option>
                                    <option value="2" <?php echo $editEmployee['shift'] == 2 ? 'selected' : ''; ?>>2nd Shift</option>
                                    <option value="3" <?php echo $editEmployee['shift'] == 3 ? 'selected' : ''; ?>>3rd Shift</option>
                                </select>
                            </div>
                            
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Hours:</label>
                                <input type="text" name="empHours" id="editEmpHours" value="<?php echo escapeHtml($editEmployee['hours'] ?? ''); ?>" required
                                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;"
                                       placeholder="e.g., 9am-5pm, 3pm-11pm"
                                       oninput="normalizeHoursInput(this)" onblur="normalizeHoursInput(this)">
                                <div style="font-size: 11px; color: var(--text-muted, #6c757d); margin-top: 4px;">12-hour format: 9am-5pm, 3pm-11pm, 10pm-6am</div>
                            </div>
                            
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Supervisor:</label>
                                <select name="empSupervisor" id="editEmpSupervisor"
                                        <?php echo $fieldDisabled; ?>
                                        style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;">
                                    <option value="">No Supervisor</option>
                                    <?php
                                        $supervisorsList = array_values(array_filter($employees, 'isSupervisorOrManagerLevelStrict'));
                                        usort($supervisorsList, function($a, $b) { return strcasecmp($a['name'] ?? '', $b['name'] ?? ''); });
                                        foreach ($supervisorsList as $supervisor):
                                            $labelRole = (function_exists('getLevelName') ? getLevelName($supervisor['level'] ?? '') : '') ?: formatLeadershipLabel($supervisor);
                                    ?>
                                        <option value="<?php echo $supervisor['id']; ?>" <?php echo ($editEmployee['supervisor_id'] ?? '') == $supervisor['id'] ? 'selected' : ''; ?>>
                                            <?php echo escapeHtml($supervisor['name']); ?> (<?php echo strtoupper($labelRole); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($fieldDisabled): ?>
                                <div style="font-size: 11px; color: #666; margin-top: 5px;">🔒 Contact supervisor to change</div>
                                <?php endif; ?>
                            </div>
                            
                            <div>
                                <label style="display: block; margin-bottom: 10px; font-weight: 600;">Working Days:</label>
                                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px;">
                                    <?php foreach ($dayNames as $i => $day): ?>
                                    <label class="checkbox-label" style="display: flex; align-items: center; padding: 8px; border-radius: 4px; cursor: pointer;">
                                        <input type="checkbox" name="day<?php echo $i; ?>" value="1" 
                                               <?php echo $schedule[$i] == 1 ? 'checked' : ''; ?>
                                               style="margin-right: 8px;">
                                        <span style="font-size: 14px;"><?php echo $day; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Schedule Settings -->
                        <div class="section-box" style="padding: 20px; border-radius: 8px;">
                            <h4 style="margin: 0 0 15px 0; color: #48bb78; border-bottom: 2px solid #48bb78; padding-bottom: 8px;">
                                ⚙️ Schedule Settings
                            </h4>
                            
                            <!-- USER ROLE FIELD - Only for Admin/Manager/Supervisor -->
                            <?php 
                            $showUserRoleField = in_array($currentUser['role'] ?? 'employee', ['admin', 'manager', 'supervisor']);
                            if ($showUserRoleField): 
                            ?>
                            <div style="margin-bottom: 15px; border: 2px solid #f59e0b; border-radius: 8px; padding: 15px; background: #fffbeb;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #92400e;">
                                    👥 User Role (Permissions):
                                </label>
                                <select name="empUserRole" id="editEmpUserRole"
                                        style="width: 100%; padding: 10px; border: 2px solid #f59e0b; border-radius: 6px; font-size: 14px; font-weight: 600;">
                                    <?php
                                    $currentEmployeeUserRole = 'employee';
                                    // Search by employee_id first
                                    foreach ($users as $u) {
                                        if (isset($u['employee_id']) && $u['employee_id'] == $editEmployee['id']) {
                                            $currentEmployeeUserRole = $u['role'] ?? 'employee';
                                            break;
                                        }
                                    }
                                    // Fallback: match by user_id stored on employee record
                                    if ($currentEmployeeUserRole === 'employee' && !empty($editEmployee['user_id'])) {
                                        foreach ($users as $u) {
                                            if ($u['id'] == $editEmployee['user_id']) {
                                                $currentEmployeeUserRole = $u['role'] ?? 'employee';
                                                break;
                                            }
                                        }
                                    }
                                    // Last-resort fallback: match by email
                                    if ($currentEmployeeUserRole === 'employee' && !empty($editEmployee['email'])) {
                                        foreach ($users as $u) {
                                            if (!empty($u['email']) && strtolower($u['email']) === strtolower($editEmployee['email'])) {
                                                $currentEmployeeUserRole = $u['role'] ?? 'employee';
                                                break;
                                            }
                                        }
                                    }
                                    $roleOptions = [
                                        'employee' => 'Employee (Restricted Access)',
                                        'supervisor' => 'Supervisor (Full Access)',
                                        'manager' => 'Manager (Full Access)',
                                        'admin' => 'Admin (Full Access)'
                                    ];
                                    foreach ($roleOptions as $roleValue => $roleLabel):
                                        $selected = ($currentEmployeeUserRole === $roleValue) ? 'selected' : '';
                                    ?>
                                    <option value="<?php echo $roleValue; ?>" <?php echo $selected; ?>>
                                        <?php echo $roleLabel; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div style="font-size: 11px; color: #92400e; margin-top: 8px; line-height: 1.5;">
                                    ⚠️ <strong>Important:</strong> This controls system permissions.<br>
                                    • <strong>Employee:</strong> Can only edit own schedule<br>
                                    • <strong>Supervisor/Manager/Admin:</strong> Can edit all employees
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Schedule Access (Optional):</label>
                                <select name="empScheduleAccess" id="editEmpScheduleAccess"
                                        <?php echo $fieldDisabled; ?>
                                        style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;">
                                    <option value="" <?php echo empty($editEmployee['schedule_access']) ? 'selected' : ''; ?>>Default (All Teams)</option>
                                    <?php 
                                    echo "<option value=\"all\" " . (($editEmployee['schedule_access'] ?? '') === 'all' ? 'selected' : '') . ">All Teams</option>\n";
                                    foreach ($teams as $value => $label) {
                                        $selected = ($editEmployee['schedule_access'] ?? '') === $value ? 'selected' : '';
                                        echo "<option value=\"{$value}\" {$selected}>{$label}</option>\n";
                                    }
                                    ?>
                                </select>
                                <?php /* schedule_access is NOT a DB column — it is a runtime-only
                                         field initialised to '' on every page load. Any value saved
                                         here is NOT persisted and will reset on reload. This field
                                         is reserved for a future per-team access restriction feature.
                                         By design — do not add a DB column without a spec review. */ ?>
                                <div style="font-size: 11px; color: #666; margin-top: 5px;">
                                    ℹ️ <strong>Note:</strong> All employees can view all team schedules by default.<br>
                                    This field is for future use and does not restrict viewing.
                                    <?php if ($fieldDisabled): ?><br>🔒 Contact supervisor to change access<?php endif; ?>
                                </div>
                            </div>
                            
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 600;">📅 Start Date:</label>
                                <input type="date" name="empStartDate" id="editEmpStartDate" 
                                       value="<?php echo escapeHtml($editEmployee['start_date'] ?? ''); ?>"
                                       style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;">
                                <div style="font-size: 11px; color: #666; margin-top: 5px;">
                                    First day with the company (for anniversary tracking)
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div style="margin-top: 30px; display: flex; gap: 15px; justify-content: flex-end;">
                        <button type="button" onclick="showTab('schedule')"
                                style="background: var(--secondary-color, #6c757d); color: white; border: none; padding: 12px 30px; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: 600;">
                            Cancel
                        </button>
                        <button type="submit" 
                                style="background: linear-gradient(135deg, var(--primary-color, #667eea) 0%, var(--secondary-color, #764ba2) 100%); color: white; border: none; padding: 12px 30px; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: 600; box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                            💾 Save Changes
                        </button>
                        <button type="button" id="deleteEmployeeBtn" class="action-btn delete" 
                                onclick="deleteCurrentEmployee()"
                                style="background: #dc3545; color: white; border: none; padding: 12px 30px; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: 600;">
                            🗑️ Delete
                        </button>

                    </div>
                </form>
            </div>
        </div>
        <?php endif; // End of permission check ?>

        <!-- User Manual Tab -->
        <div id="manual-tab" class="tab-content<?php echo ($activeTab === 'manual') ? ' active' : ''; ?>">
            <div style="max-width:1100px;margin:0 auto;padding:10px 20px;">
                <?php require_once __DIR__ . '/manual.php'; ?>
            </div>
        </div>

        <!-- Setup Checklist Tab (admin / supervisor only) -->
        <?php if (hasPermission('edit_schedule')): ?>
        <div id="checklist-tab" class="tab-content<?php echo ($activeTab === 'checklist') ? ' active' : ''; ?>">
            <div style="max-width:1100px;margin:0 auto;padding:20px;">

                <div style="display:flex; align-items:center; gap:12px; margin-bottom:24px; padding-bottom:16px; border-bottom:2px solid var(--border-color,#e0e0e0);">
                    <span style="font-size:32px;">📋</span>
                    <div>
                        <h1 style="margin:0; font-size:24px; color:var(--text-color,#1e293b);">Employee Setup Checklist for Sorting</h1>
                        <p style="margin:4px 0 0; color:var(--text-muted,#64748b); font-size:14px;">Follow these steps to ensure employees are properly configured for schedule sorting and shift rotation.</p>
                    </div>
                </div>

                <div class="checklist-warning-box" style="background:#fff3cd; border:2px solid #ffc107; border-radius:8px; padding:16px 20px; margin-bottom:24px;">
                    <strong>⚠️ Important:</strong> Follow this checklist to ensure employees are properly configured for schedule sorting and shift rotation.
                </div>

                <!-- STEP 1 -->
                <div style="background:var(--card-bg,#fff); border:1px solid var(--border-color,#e0e0e0); border-left:5px solid #007bff; border-radius:8px; padding:24px; margin-bottom:20px;">
                    <h3 style="margin-top:0; color:#007bff;">✅ STEP 1: Configure Base Employee Settings</h3>
                    <p style="margin-bottom:10px;"><strong>Using the Gear Icon (⚙️) in Employee Column:</strong></p>
                    <ol style="line-height:1.9; padding-left:25px; margin:0; color:var(--text-color,#1e293b);">
                        <li>Click the <strong>gear icon (⚙️)</strong> next to the employee's name in the schedule</li>
                        <li><strong>Set Base Schedule:</strong> Check the days the employee normally works (Sun–Sat)</li>
                        <li><strong>Verify Shift Assignment:</strong> Ensure correct shift is selected (1st, 2nd, or 3rd Shift)</li>
                        <li><strong>Set Skills &amp; Specializations:</strong>
                            <ul style="margin-top:5px; padding-left:25px;">
                                <li>☑️ MH (Managed Hosting)</li>
                                <li>☑️ MA (Managed Apps)</li>
                                <li>☑️ Win (Windows)</li>
                            </ul>
                        </li>
                        <li><strong>Set Default Working Hours:</strong> Enter standard hours (e.g., 9p-5p, 2pm-10p)</li>
                        <li>Click <strong>"Update Employee"</strong> to save</li>
                    </ol>
                </div>

                <!-- STEP 2 -->
                <div style="background:var(--card-bg,#fff); border:1px solid var(--border-color,#e0e0e0); border-left:5px solid var(--success-color, #28a745); border-radius:8px; padding:24px; margin-bottom:20px;">
                    <h3 style="margin-top:0; color:var(--success-color, #28a745);">STEP 2: Configure Custom Schedules</h3>
                    <p style="margin-bottom:14px;"><strong>Using the Bulk Schedule Editor (UI):</strong></p>
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <div style="background:var(--surface-bg,#f8f9fa); padding:14px 18px; border-radius:6px; border:1px solid var(--border-color,#e0e0e0); color:var(--text-color,#1e293b);">
                            <strong>A. Access Bulk Editor</strong>
                            <p style="margin:5px 0 0;">Click <strong>"Bulk Schedule Changes"</strong> from the sidebar</p>
                        </div>
                        <div style="background:var(--surface-bg,#f8f9fa); padding:14px 18px; border-radius:6px; border:1px solid var(--border-color,#e0e0e0); color:var(--text-color,#1e293b);">
                            <strong>B. Configure Date Range</strong>
                            <p style="margin:5px 0 0;">Set <strong>Start Date</strong> and <strong>End Date</strong> for the rotation period (can span multiple months/years)</p>
                        </div>
                        <div style="background:var(--surface-bg,#f8f9fa); padding:14px 18px; border-radius:6px; border:1px solid var(--border-color,#e0e0e0); color:var(--text-color,#1e293b);">
                            <strong>C. Choose Schedule Application Method</strong>
                            <p style="margin:8px 0 4px;"><strong>Option 1:</strong> ☑️ <strong>"Skip days off (only apply to scheduled working days)"</strong></p>
                            <p style="margin:0 0 4px 18px; font-size:13px; color:var(--text-muted,#64748b);">Use this to keep existing base schedule and only change shift assignment</p>
                            <p style="margin:10px 0 4px;"><strong>Option 2:</strong> ☑️ <strong>"Set New Weekly Schedule (Optional)"</strong></p>
                            <p style="margin:0 0 0 18px; font-size:13px; color:var(--text-muted,#64748b);">Use this if the employee's working days change during the rotation</p>
                        </div>
                        <div style="background:var(--surface-bg,#f8f9fa); padding:14px 18px; border-radius:6px; border:1px solid var(--border-color,#e0e0e0); color:var(--text-color,#1e293b);">
                            <strong>D. Select Employees</strong>
                            <p style="margin:5px 0 0;">Check the box next to each employee for this rotation in the <strong>"Select Employees"</strong> panel</p>
                        </div>
                        <div style="background:var(--surface-bg,#f8f9fa); padding:14px 18px; border-radius:6px; border:1px solid var(--border-color,#e0e0e0); color:var(--text-color,#1e293b);">
                            <strong>E. Configure Shift Change</strong>
                            <ol style="margin:10px 0 0; line-height:1.9; padding-left:25px;">
                                <li><strong>Status to Apply:</strong> Select <strong>"Custom Hours"</strong> and enter specific hours in 12-hour format (e.g., 3pm–11pm)</li>
                                <li><strong>Shift Assignment:</strong> ☑️ Check <strong>"Also Change Shift Assignment"</strong> and select new shift</li>
                                <li><strong>Change Timing:</strong>
                                    <ul style="margin-top:5px; padding-left:25px;">
                                        <li>☑️ <strong>"Change Now (Immediate)"</strong> — for immediate shift changes</li>
                                        <li><em>OR</em> ☑️ <strong>"Change on Start Date"</strong> — for scheduled future changes</li>
                                    </ul>
                                </li>
                            </ol>
                        </div>
                        <div style="background:var(--surface-bg,#f8f9fa); padding:14px 18px; border-radius:6px; border:1px solid var(--border-color,#e0e0e0); color:var(--text-color,#1e293b);">
                            <strong>F. Apply Changes</strong>
                            <p style="margin:5px 0 0;">Click <strong>"Apply Bulk Changes"</strong> and verify changes in the schedule grid</p>
                        </div>
                    </div>
                </div>

                <!-- STEP 3 — API Bulk Schedule -->
                <div style="background:var(--card-bg,#fff); border:1px solid var(--border-color,#e0e0e0); border-left:5px solid #6c5ce7; border-radius:8px; padding:24px; margin-bottom:20px;">
                    <h3 style="margin-top:0; color:#6c5ce7;">🔌 STEP 3: Bulk Schedule Changes via API</h3>
                    <p style="margin-bottom:14px;">You can apply bulk schedule overrides programmatically using the REST API — useful for automated rotations, integrations, or scripted imports.</p>

                    <div style="display:flex; flex-direction:column; gap:12px;">
                        <div style="background:var(--surface-bg,#f8f9fa); padding:14px 18px; border-radius:6px; border:1px solid var(--border-color,#e0e0e0); color:var(--text-color,#1e293b);">
                            <strong>Authentication — Generating Your API Key</strong>
                            <p style="margin:6px 0 4px; font-size:13px;">All API requests require an <strong>X-API-Key</strong> header. Follow these steps to generate yours:</p>
                            <ol style="margin:8px 0 10px; padding-left:22px; font-size:13px; line-height:1.8;">
                                <li>Click <strong>My Profile</strong> in the sidebar (below your name)</li>
                                <li>Scroll to the <strong>Account Information</strong> panel on the right side</li>
                                <li>Find the <strong>🔑 API Key</strong> section</li>
                                <li>Click <strong>✨ Generate Key</strong> — your key will appear immediately</li>
                                <li>Click <strong>📋 Copy</strong> to copy the key to your clipboard</li>
                            </ol>
                            <p style="margin:0 0 8px; font-size:13px;"><strong>⚠️ Important:</strong> Regenerating a key immediately invalidates the old one. Keep your key secret — anyone with it can access the API as you.</p>
                            <pre style="background:#1e293b; color:#e2e8f0; padding:10px 14px; border-radius:5px; font-size:12px; overflow-x:auto; margin:0;">X-API-Key: your-api-key-here</pre>
                        </div>

                        <div style="background:var(--surface-bg,#f8f9fa); padding:14px 18px; border-radius:6px; border:1px solid var(--border-color,#e0e0e0); color:var(--text-color,#1e293b);">
                            <strong>Endpoint: Apply Bulk Overrides</strong>
                            <p style="margin:6px 0 4px; font-size:13px;"><span style="background:#22c55e; color:#fff; padding:2px 7px; border-radius:4px; font-size:11px; font-weight:700; margin-right:6px;">POST</span><code>api.php?action=bulk_schedule_override</code></p>
                            <p style="margin:10px 0 6px; font-size:13px;"><strong>Request body (JSON):</strong></p>
                            <pre style="background:#1e293b; color:#e2e8f0; padding:12px 14px; border-radius:5px; font-size:12px; overflow-x:auto; margin:0;">{
  "start_date": "2026-03-01",
  "end_date":   "2026-03-14",
  "status":     "custom_hours",
  "hours":      "3pm-11pm",
  "shift":      "2nd",
  "skip_days_off": true,
  "employee_ids": [12, 34, 56]
}</pre>
                        </div>

                        <div style="background:var(--surface-bg,#f8f9fa); padding:14px 18px; border-radius:6px; border:1px solid var(--border-color,#e0e0e0); color:var(--text-color,#1e293b);">
                            <strong>Field Reference</strong>
                            <table style="width:100%; border-collapse:collapse; font-size:13px; margin-top:10px;">
                                <thead>
                                    <tr style="background:var(--primary-color,#333399); color:#fff;">
                                        <th style="padding:7px 10px; text-align:left; border-radius:4px 0 0 0;">Field</th>
                                        <th style="padding:7px 10px; text-align:left;">Type</th>
                                        <th style="padding:7px 10px; text-align:left; border-radius:0 4px 0 0;">Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr style="border-bottom:1px solid var(--border-color,#e0e0e0);">
                                        <td style="padding:7px 10px;"><code>start_date</code></td>
                                        <td style="padding:7px 10px; color:var(--text-muted,#64748b);">string</td>
                                        <td style="padding:7px 10px;">ISO date (YYYY-MM-DD) — start of range</td>
                                    </tr>
                                    <tr style="border-bottom:1px solid var(--border-color,#e0e0e0); background:var(--surface-bg,#f8f9fa);">
                                        <td style="padding:7px 10px;"><code>end_date</code></td>
                                        <td style="padding:7px 10px; color:var(--text-muted,#64748b);">string</td>
                                        <td style="padding:7px 10px;">ISO date — end of range (inclusive)</td>
                                    </tr>
                                    <tr style="border-bottom:1px solid var(--border-color,#e0e0e0);">
                                        <td style="padding:7px 10px;"><code>status</code></td>
                                        <td style="padding:7px 10px; color:var(--text-muted,#64748b);">string</td>
                                        <td style="padding:7px 10px;"><code>on</code>, <code>off</code>, <code>sick</code>, <code>vacation</code>, <code>custom_hours</code></td>
                                    </tr>
                                    <tr style="border-bottom:1px solid var(--border-color,#e0e0e0); background:var(--surface-bg,#f8f9fa);">
                                        <td style="padding:7px 10px;"><code>hours</code></td>
                                        <td style="padding:7px 10px; color:var(--text-muted,#64748b);">string</td>
                                        <td style="padding:7px 10px;">Hours string when status is <code>custom_hours</code> (e.g., <code>"9am-5pm"</code>, <code>"3pm-11pm"</code>)</td>
                                    </tr>
                                    <tr style="border-bottom:1px solid var(--border-color,#e0e0e0);">
                                        <td style="padding:7px 10px;"><code>shift</code></td>
                                        <td style="padding:7px 10px; color:var(--text-muted,#64748b);">string</td>
                                        <td style="padding:7px 10px;"><code>"1st"</code>, <code>"2nd"</code>, or <code>"3rd"</code> — updates shift assignment</td>
                                    </tr>
                                    <tr style="border-bottom:1px solid var(--border-color,#e0e0e0); background:var(--surface-bg,#f8f9fa);">
                                        <td style="padding:7px 10px;"><code>skip_days_off</code></td>
                                        <td style="padding:7px 10px; color:var(--text-muted,#64748b);">boolean</td>
                                        <td style="padding:7px 10px;">If <code>true</code>, only applies overrides on days in the employee's base schedule</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:7px 10px;"><code>employee_ids</code></td>
                                        <td style="padding:7px 10px; color:var(--text-muted,#64748b);">array</td>
                                        <td style="padding:7px 10px;">Array of employee IDs to apply the changes to</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div style="background:var(--surface-bg,#f8f9fa); padding:14px 18px; border-radius:6px; border:1px solid var(--border-color,#e0e0e0); color:var(--text-color,#1e293b);">
                            <strong>Example: Move employees to 2nd shift for 2 weeks</strong>
                            <pre style="background:#1e293b; color:#e2e8f0; padding:12px 14px; border-radius:5px; font-size:12px; overflow-x:auto; margin-top:10px;">curl -X POST "https://yoursite.com/api.php?action=bulk_schedule_override" \
  -H "X-API-Key: your-key-here" \
  -H "Content-Type: application/json" \
  -d '{
    "start_date": "2026-03-01",
    "end_date":   "2026-03-14",
    "status":     "custom_hours",
    "hours":      "3pm-11pm",
    "shift":      "2nd",
    "skip_days_off": true,
    "employee_ids": [12, 34, 56]
  }'</pre>
                        </div>

                        <div style="background:var(--surface-bg,#f8f9fa); padding:14px 18px; border-radius:6px; border:1px solid var(--border-color,#e0e0e0); color:var(--text-color,#1e293b);">
                            <strong>Success Response</strong>
                            <pre style="background:#1e293b; color:#e2e8f0; padding:12px 14px; border-radius:5px; font-size:12px; overflow-x:auto; margin-top:10px;">{
  "success": true,
  "applied": 42,
  "employees_updated": 3
}</pre>
                        </div>

                        <div style="background:#fff3cd; border:1px solid #ffc107; border-radius:6px; padding:14px 18px; color:var(--text-color,#1e293b);">
                            <strong>💡 Tip:</strong> To get employee IDs, call <code>api.php?action=get_employees</code> or use the search endpoint. See <strong>API Reference</strong> in the sidebar for the full list of endpoints.
                        </div>
                    </div>
                </div>

                <!-- Critical Requirements -->
                <div style="background:#ffe6e6; border:2px solid #dc3545; border-radius:8px; padding:20px; margin-bottom:20px;">
                    <h3 style="margin-top:0; color:#dc3545;">⚠️ Critical Requirements for Sorting</h3>
                    <p><strong>For sorting to work correctly, you MUST:</strong></p>
                    <ul style="line-height:1.9; padding-left:25px; margin:0; color:var(--text-color,#1e293b);">
                        <li>✅ Set base schedule via gear icon</li>
                        <li>✅ Assign correct shift (1st, 2nd, or 3rd)</li>
                        <li>✅ Set skills for each admin (MH, MA, Win)</li>
                        <li>✅ Use bulk editor or API for any shift rotations</li>
                        <li>✅ Always check "Also Change Shift Assignment" when using the bulk editor</li>
                        <li>✅ Choose appropriate change timing (Now or Start Date)</li>
                    </ul>
                </div>

                <!-- Example Workflow -->
                <div style="background:#e7f3ff; border-left:5px solid #2196F3; border-radius:8px; padding:20px; margin-bottom:20px;">
                    <h3 style="margin-top:0; color:#2196F3;">💡 Example Workflow</h3>
                    <p><strong>Scenario:</strong> Moving employee from 1st to 2nd shift for 2 weeks</p>
                    <div style="margin-top:14px; color:var(--text-color,#1e293b);">
                        <strong>1. Gear Icon Setup (One-time):</strong>
                        <ul style="margin:5px 0 12px; padding-left:25px;">
                            <li>Base schedule: Mon–Fri ✅</li>
                            <li>Default shift: 1st Shift</li>
                            <li>Skills: MH ✅, MA ✅</li>
                            <li>Hours: 9am–5pm</li>
                        </ul>
                        <strong>2. Bulk Editor (For Rotation):</strong>
                        <ul style="margin:5px 0 12px; padding-left:25px;">
                            <li>Date range: 03/01/2026 to 03/14/2026</li>
                            <li>Skip days off: ✅</li>
                            <li>Select employee: ✅</li>
                            <li>Status: Custom Hours (3pm–11pm)</li>
                            <li>Also Change Shift: ✅ → 2nd Shift</li>
                            <li>Change on Start Date: ✅</li>
                            <li>Apply Bulk Changes</li>
                        </ul>
                        <strong>3. Result:</strong>
                        <ul style="margin:5px 0 0; padding-left:25px;">
                            <li>Employee works Mon–Fri (base schedule)</li>
                            <li>From 03/01–03/14: 2nd Shift (3pm–11pm)</li>
                            <li>After 03/14: Returns to 1st Shift (9am–5pm)</li>
                            <li>Sorting recognizes shift change correctly ✅</li>
                        </ul>
                    </div>
                </div>

                <!-- Troubleshooting -->
                <div style="background:var(--card-bg,#fff); border:1px solid var(--border-color,#e0e0e0); border-radius:8px; padding:24px; margin-bottom:20px; color:var(--text-color,#1e293b);">
                    <h3 style="margin-top:0;">🆘 Troubleshooting</h3>
                    <div style="margin-bottom:16px;">
                        <strong>Sorting Not Working?</strong>
                        <ol style="margin:6px 0 0; line-height:1.9; padding-left:25px;">
                            <li>Check if employee has skills set (gear icon)</li>
                            <li>Verify shift is assigned correctly</li>
                            <li>Ensure base schedule exists</li>
                            <li>Try re-applying bulk changes with "Also Change Shift Assignment"</li>
                        </ol>
                    </div>
                    <div style="margin-bottom:16px;">
                        <strong>Shift Not Changing?</strong>
                        <ol style="margin:6px 0 0; line-height:1.9; padding-left:25px;">
                            <li>Make sure "Also Change Shift Assignment" is checked</li>
                            <li>Verify date range includes today (if using "Change Now")</li>
                            <li>Check employee is selected in employee list</li>
                            <li>Confirm shift was selected from dropdown</li>
                        </ol>
                    </div>
                    <div>
                        <strong>Hours Not Showing?</strong>
                        <ol style="margin:6px 0 0; line-height:1.9; padding-left:25px;">
                            <li>Use "Custom Hours" status</li>
                            <li>Enter hours in correct 12-hour format (e.g., 9am–5pm, 8am–12pm &amp; 2pm–6pm)</li>
                            <li>Make sure "Skip days off" is checked if keeping base schedule</li>
                            <li>Verify date range is correct</li>
                        </ol>
                    </div>
                </div>

            </div>
        </div>
        <?php endif; ?>

        <!-- API Reference Tab (admin / supervisor only) -->
        <?php if ($isAdminOrSupervisor): ?>
        <div id="api-docs-tab" class="tab-content<?php echo ($activeTab === 'api-docs') ? ' active' : ''; ?>">
            <div style="max-width:1100px;margin:0 auto;padding:10px 20px;">
                <?php require_once __DIR__ . '/api_docs.php'; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- View Profile Tab (inline like edit-employee) -->
        <div id="view-profile-tab" class="tab-content<?php echo ($activeTab === 'view-profile') ? ' active' : ''; ?>" style="<?php echo ($activeTab !== 'view-profile') ? 'display: none;' : ''; ?>">
            <div style="width: 98%; margin: 0 auto; padding: 20px;">
            
            <?php
            // Get user ID from URL
            $viewUserId = isset($_GET['user_id']) && is_numeric($_GET['user_id']) ? (int)$_GET['user_id'] : null;
            $viewUser = null;
            $viewLinkedEmployee = null;
            
            if ($viewUserId) {
                // Find the user
                foreach ($users as $u) {
                    if (isset($u['id']) && $u['id'] == $viewUserId) {
                        $viewUser = $u;
                        break;
                    }
                }
                
                // Find linked employee
                if ($viewUser) {
                    foreach ($employees as $emp) {
                        if (strtolower(trim($emp['name'] ?? '')) === strtolower(trim($viewUser['full_name'] ?? ''))) {
                            $viewLinkedEmployee = $emp;
                            break;
                        }
                    }
                }
            }
            
            if ($viewUser):
                // Get supervisor name if linked employee exists
                $supervisorName = 'None';
                if ($viewLinkedEmployee && !empty($viewLinkedEmployee['supervisor_id'])) {
                    foreach ($employees as $emp) {
                        if ($emp['id'] == $viewLinkedEmployee['supervisor_id']) {
                            $supervisorName = $emp['name'];
                            break;
                        }
                    }
                }
                
                // Get profile photo URL — delegates to getProfilePhotoUrl() which already
                // handles local files, Google URLs, and avoids the double-URL bug where a
                // full https:// URL was being prefixed with 'profile_photos/'.
                $profilePhotoUrl = getProfilePhotoUrl($viewUser);
            ?>
            
            <!-- Profile Card - Similar to Employee Hover Card -->
            <div style="border-radius: 16px; box-shadow: 0 8px 32px rgba(0,0,0,0.12); overflow: hidden; background: var(--card-bg, white);">
                
                <!-- Header with Photo and Basic Info -->
                <div class="view-profile-header" style="padding: 30px; color: white !important;">
                    <div style="display: flex; align-items: center; gap: 25px;">
                        <!-- Profile Photo -->
                        <div style="width: 120px; height: 120px; border-radius: 50%; overflow: hidden; border: 4px solid rgba(255,255,255,0.3); background: rgba(255,255,255,0.2); flex-shrink: 0; position: relative;">
                            <?php if ($profilePhotoUrl): ?>
                                <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:48px;font-weight:bold;color:white !important;">
                                    <?php echo strtoupper(substr($viewUser['full_name'] ?? 'U', 0, 2)); ?>
                                </div>
                                <img src="<?php echo htmlspecialchars($profilePhotoUrl); ?>" alt="Profile Photo"
                                     style="width:100%;height:100%;object-fit:cover;position:absolute;top:0;left:0;display:none;"
                                     onload="this.style.display='';this.previousElementSibling.style.display='none';"
                                     onerror="this.style.display='none';this.previousElementSibling.style.display='flex';">
                            <?php else: ?>
                                <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 48px; font-weight: bold; color: white !important;">
                                    <?php echo strtoupper(substr($viewUser['full_name'] ?? 'U', 0, 2)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Name and Role -->
                        <div style="flex: 1;">
                            <h1 style="margin: 0 0 10px 0; font-size: 32px; font-weight: 600;">
                                <?php echo htmlspecialchars($viewUser['full_name'] ?? 'Unknown'); ?>
                            </h1>
                            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                                <span style="background: rgba(255,255,255,0.25); padding: 6px 16px; border-radius: 20px; font-size: 14px; font-weight: 600; text-transform: uppercase;">
                                    <?php echo htmlspecialchars($viewUser['role'] ?? 'Employee'); ?>
                                </span>
                                <?php if ($viewLinkedEmployee): ?>
                                <span style="background: rgba(255,255,255,0.15); padding: 6px 16px; border-radius: 20px; font-size: 14px;">
                                    <?php echo strtoupper($viewLinkedEmployee['team'] ?? 'N/A'); ?>
                                </span>
                                <?php endif; ?>
                                <span style="background: <?php echo ($viewUser['active'] ?? true) ? 'rgba(46, 204, 113, 0.3)' : 'rgba(231, 76, 60, 0.3)'; ?>; padding: 6px 16px; border-radius: 20px; font-size: 14px;">
                                    <?php echo ($viewUser['active'] ?? true) ? '✅ Active' : '❌ Inactive'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Content Grid -->
                <div style="padding: 30px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 30px;">
                        
                        <!-- Account Information -->
                        <div style="background: #f8f9fa; border-radius: 12px; padding: 24px;">
                            <h3 style="margin: 0 0 20px 0; color: var(--primary-color, #667eea); font-size: 18px; display: flex; align-items: center; gap: 10px;">
                                <span>🔐</span> Account Information
                            </h3>
                            
                            <div style="display: flex; flex-direction: column; gap: 16px;">
                                <div style="display: flex; justify-content: space-between; padding-bottom: 12px; border-bottom: 1px solid #e9ecef;">
                                    <span style="color: var(--text-muted, #6c757d); font-weight: 500;">Username</span>
                                    <span style="font-weight: 600;"><?php echo htmlspecialchars($viewUser['username'] ?? 'N/A'); ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding-bottom: 12px; border-bottom: 1px solid #e9ecef;">
                                    <span style="color: var(--text-muted, #6c757d); font-weight: 500;">Email</span>
                                    <span style="font-weight: 600;"><?php echo htmlspecialchars($viewUser['email'] ?? 'N/A'); ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding-bottom: 12px; border-bottom: 1px solid #e9ecef;">
                                    <span style="color: var(--text-muted, #6c757d); font-weight: 500;">Auth Method</span>
                                    <span style="font-weight: 600;">
                                        <?php 
                                        $authMethod = $viewUser['auth_method'] ?? 'password';
                                        if ($authMethod === 'google') {
                                            echo '<span style="background: #ea4335; color: white !important; padding: 3px 10px; border-radius: 12px; font-size: 12px;">🔗 Google SSO</span>';
                                        } else {
                                            echo '<span style="background: var(--secondary-color, #6c757d); color: white !important; padding: 3px 10px; border-radius: 12px; font-size: 12px;">🔑 Password</span>';
                                        }
                                        ?>
                                    </span>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding-bottom: 12px; border-bottom: 1px solid #e9ecef;">
                                    <span style="color: var(--text-muted, #6c757d); font-weight: 500;">Team</span>
                                    <span style="font-weight: 600;"><?php echo strtoupper($viewUser['team'] ?? 'N/A'); ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="color: var(--text-muted, #6c757d); font-weight: 500;">Created</span>
                                    <span style="font-weight: 600;"><?php echo isset($viewUser['created_at']) ? date('M j, Y', strtotime($viewUser['created_at'])) : 'N/A'; ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($viewLinkedEmployee): ?>
                        <!-- Employee Information -->
                        <div style="background: #f8f9fa; border-radius: 12px; padding: 24px;">
                            <h3 style="margin: 0 0 20px 0; color: var(--success-color, #28a745); font-size: 18px; display: flex; align-items: center; gap: 10px;">
                                <span>👤</span> Employee Information
                                <a href="?tab=edit-employee&id=<?php echo $viewLinkedEmployee['id']; ?>" 
                                   style="margin-left: auto; font-size: 12px; color: var(--primary-color, #667eea); text-decoration: none;">
                                    Edit →
                                </a>
                            </h3>
                            
                            <div style="display: flex; flex-direction: column; gap: 16px;">
                                <div style="display: flex; justify-content: space-between; padding-bottom: 12px; border-bottom: 1px solid #e9ecef;">
                                    <span style="color: var(--text-muted, #6c757d); font-weight: 500;">🕒 Shift</span>
                                    <span style="font-weight: 600;"><?php echo getShiftName($viewLinkedEmployee['shift'] ?? 1); ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding-bottom: 12px; border-bottom: 1px solid #e9ecef;">
                                    <span style="color: var(--text-muted, #6c757d); font-weight: 500;">⏰ Hours</span>
                                    <span style="font-weight: 600;"><?php echo htmlspecialchars($viewLinkedEmployee['hours'] ?? 'N/A'); ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding-bottom: 12px; border-bottom: 1px solid #e9ecef;">
                                    <span style="color: var(--text-muted, #6c757d); font-weight: 500;">🏷️ Level</span>
                                    <span style="font-weight: 600;">
                                        <?php 
                                        $level = $viewLinkedEmployee['level'] ?? '';
                                        echo $level ? getLevelName($level) : 'Not Set';
                                        ?>
                                    </span>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding-bottom: 12px; border-bottom: 1px solid #e9ecef;">
                                    <span style="color: var(--text-muted, #6c757d); font-weight: 500;">👑 Reports To</span>
                                    <span style="font-weight: 600;"><?php echo htmlspecialchars($supervisorName); ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding-bottom: 12px; border-bottom: 1px solid #e9ecef;">
                                    <span style="color: var(--text-muted, #6c757d); font-weight: 500;">🎯 Skills</span>
                                    <span style="font-weight: 600;">
                                        <?php 
                                        $skills = $viewLinkedEmployee['skills'] ?? [];
                                        $activeSkills = [];
                                        if (!empty($skills['mh'])) $activeSkills[] = 'MH';
                                        if (!empty($skills['ma'])) $activeSkills[] = 'MA';
                                        if (!empty($skills['win'])) $activeSkills[] = 'Win';
                                        echo $activeSkills ? implode(', ', $activeSkills) : 'None';
                                        ?>
                                    </span>
                                </div>
                                <?php if (!empty($viewLinkedEmployee['start_date'])): 
                                    $startDate = new DateTime($viewLinkedEmployee['start_date']);
                                    $today = new DateTime();
                                    $interval = $startDate->diff($today);
                                    
                                    // Calculate years of service
                                    $years = $interval->y;
                                    $months = $interval->m;
                                    
                                    // Calculate next anniversary
                                    $nextAnniversary = new DateTime($viewLinkedEmployee['start_date']);
                                    $currentYear = (int)$today->format('Y');
                                    $nextAnniversary->setDate($currentYear, (int)$startDate->format('m'), (int)$startDate->format('d'));
                                    
                                    // If anniversary already passed this year, show next year's
                                    if ($nextAnniversary < $today) {
                                        $nextAnniversary->modify('+1 year');
                                    }
                                    
                                    // Calculate days until anniversary
                                    $daysUntil = $today->diff($nextAnniversary)->days;
                                ?>
                                <div style="display: flex; justify-content: space-between; padding-bottom: 12px; border-bottom: 1px solid #e9ecef;">
                                    <span style="color: var(--text-muted, #6c757d); font-weight: 500;">📅 Start Date</span>
                                    <span style="font-weight: 600;"><?php echo $startDate->format('M j, Y'); ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding-bottom: 12px; border-bottom: 1px solid #e9ecef;">
                                    <span style="color: var(--text-muted, #6c757d); font-weight: 500;">⏳ Time with Company</span>
                                    <span style="font-weight: 600;">
                                        <?php 
                                        if ($years > 0) {
                                            echo $years . ' year' . ($years != 1 ? 's' : '');
                                            if ($months > 0) echo ', ' . $months . ' month' . ($months != 1 ? 's' : '');
                                        } else if ($months > 0) {
                                            echo $months . ' month' . ($months != 1 ? 's' : '');
                                        } else {
                                            $days = $interval->days;
                                            echo $days . ' day' . ($days != 1 ? 's' : '');
                                        }
                                        ?>
                                    </span>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="color: var(--text-muted, #6c757d); font-weight: 500;">🎉 Next Anniversary</span>
                                    <span style="font-weight: 600; color: <?php echo $daysUntil <= 30 ? 'var(--success-color, #28a745)' : 'var(--primary-color, #667eea)'; ?>;">
                                        <?php 
                                        echo $nextAnniversary->format('M j, Y');
                                        echo ' (' . $daysUntil . ' days)';
                                        ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <!-- No Linked Employee -->
                        <div style="background: #fff3cd; border-radius: 12px; padding: 24px; border: 1px solid #ffc107;">
                            <h3 style="margin: 0 0 15px 0; color: #856404; font-size: 18px;">
                                ⚠️ No Linked Employee Record
                            </h3>
                            <p style="margin: 0 0 15px 0; color: #856404;">
                                This user account is not linked to an employee schedule record. 
                                To link this user, create an employee with the same name or update an existing employee's name to match.
                            </p>
                            <?php if (hasPermission('manage_employees')): ?>
                            <button onclick="document.getElementById('addModal').classList.add('show')" 
                                    style="background: var(--primary-color, #667eea); color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                + Create Employee Record
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($viewLinkedEmployee): ?>
                    <!-- Weekly Schedule -->
                    <div style="margin-top: 30px; background: #f8f9fa; border-radius: 12px; padding: 24px;">
                        <h3 style="margin: 0 0 20px 0; color: var(--primary-color, #17a2b8); font-size: 18px;">
                            📅 Weekly Schedule
                        </h3>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <?php
                            $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                            $schedule = $viewLinkedEmployee['schedule'] ?? [0,1,1,1,1,1,0];
                            $employeeHours = $viewLinkedEmployee['hours'] ?? '9am-5pm';
                            foreach ($dayNames as $index => $day):
                                $isWorking = isset($schedule[$index]) && $schedule[$index];
                            ?>
                            <div style="flex: 1; min-width: 100px; text-align: center; padding: 15px 10px; border-radius: 8px; 
                                        background: <?php echo $isWorking ? 'linear-gradient(135deg, #28a745, #20c997)' : '#dee2e6'; ?>; 
                                        color: <?php echo $isWorking ? 'white' : 'var(--text-muted, #6c757d)'; ?>;">
                                <div style="font-weight: 600; font-size: 16px;"><?php echo $day; ?></div>
                                <div style="font-size: 11px; margin-top: 4px; font-weight: 500;"><?php echo $isWorking ? htmlspecialchars($employeeHours) : 'Off'; ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Action Buttons -->
                    <div style="margin-top: 30px; display: flex; gap: 15px; justify-content: flex-end; flex-wrap: wrap;">
                        <button onclick="showTab('settings')" 
                                style="background: var(--secondary-color, #6c757d); color: white; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-weight: 600;">
                            ← Back to Settings
                        </button>
                        <?php if ($viewLinkedEmployee): ?>
                        <a href="?tab=edit-employee&id=<?php echo $viewLinkedEmployee['id']; ?>" 
                           style="background: var(--success-color, #28a745); color: white; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block;">
                            ⚙️ Edit Schedule
                        </a>
                        <?php endif; ?>
                        
                        <!-- Export Profile Button - Only for Admin/Manager/Supervisor -->
                        <?php if (in_array($currentUser['role'] ?? 'employee', ['admin', 'manager', 'supervisor'])): ?>
                        <button onclick="exportProfileToJson(<?php echo htmlspecialchars(json_encode([
                            'user' => $viewUser,
                            'employee' => $viewLinkedEmployee
                        ])); ?>)" 
                           style="background: #f59e0b; color: white; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;">
                            📥 Export Profile (JSON)
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            <!-- No User Found -->
            <div style="background: var(--card-bg, white); color: var(--text-color, #333); border-radius: 12px; padding: 60px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
                <div style="font-size: 64px; margin-bottom: 20px;">🔍</div>
                <h3 style="margin: 0 0 10px 0; color: var(--text-color, #333);">User Not Found</h3>
                <p style="margin: 0 0 20px 0; color: var(--text-muted, #666);">The requested user profile could not be found.</p>
                <button onclick="showTab('settings')" 
                        style="background: var(--primary-color, #667eea); color: white; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-weight: 600;">
                    ← Back to Settings
                </button>
            </div>
            <?php endif; ?>
        </div>
        </div>

        <!-- Add Employee Modal -->
    <?php if (hasPermission('manage_employees')): ?>
    <div id="addModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <h2>Create Employee Schedule</h2>
            
            <form method="POST">
                <input type="hidden" name="action" value="add_employee">
                
                <div class="form-group">
                    <label>Employee Name:</label>
                    <input type="text" name="empName" id="modalAddEmpName" required placeholder="Enter full name">
                </div>
                
                <div class="form-group">
                    <label>Team:</label>
                    <select name="empTeam" id="modalAddEmpTeam" required>
                        <?php echo generateTeamOptions(); ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Level:</label>
                    <select name="empLevel" id="modalAddEmpLevel">
                        <?php echo generateLevelOptions(); ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Shift:</label>
                    <select name="empShift" id="modalAddEmpShift" required>
                        <option value="1">1st Shift</option>
                        <option value="2">2nd Shift</option>
                        <option value="3">3rd Shift</option>
                        
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Default Working Hours:</label>
                    <input type="text" name="empHours" id="modalAddEmpHours" required placeholder="e.g., 9am-5pm, 3pm-11pm" oninput="normalizeHoursInput(this)" onblur="normalizeHoursInput(this)">
                    <div style="font-size: 11px; color: #666; margin-top: 3px;">12-hour format: 9am-5pm, 3pm-11pm.</div>
                </div>
                
                <div class="form-group">
                    <label>Email Address:</label>
                    <input type="email" name="empEmail" id="modalAddEmpEmail" placeholder="employee@company.com">
                    <div style="font-size: 11px; color: #666; margin-top: 3px;">Must match the email you set up the user with.</div>
                </div>
                
                <div class="form-group">
                    <label>Supervisor/Manager:</label>
                    <select name="empSupervisor" id="modalAddEmpSupervisor">
                        <?php echo generateSupervisorOptions(); ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Skills & Specializations:</label>
                    <div class="skill-form-badges" style="background: #f8f9fa; padding: 15px; border-radius: 5px; border: 1px solid #dee2e6;">
                        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0;">
                                <input type="checkbox" name="skillMH" id="modalAddSkillMH" style="transform: scale(1.2);">
                                <span class="skill-badge-mh" style="background: #e3f2fd; color: #1976d2; padding: 4px 8px; border-radius: 12px; font-weight: bold; font-size: 0.9em;">MH</span>
                                <span class="skill-label-text">Managed Hosting</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0;">
                                <input type="checkbox" name="skillMA" id="modalAddSkillMA" style="transform: scale(1.2);">
                                <span class="skill-badge-ma" style="background: #f3e5f5; color: #7b1fa2; padding: 4px 8px; border-radius: 12px; font-weight: bold; font-size: 0.9em;">MA</span>
                                <span class="skill-label-text">Managed Apps</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0;">
                                <input type="checkbox" name="skillWin" id="modalAddSkillWin" style="transform: scale(1.2);">
                                <span class="skill-badge-win" style="background: #e8f5e8; color: #388e3c; padding: 4px 8px; border-radius: 12px; font-weight: bold; font-size: 0.9em;">Win</span>
                                <span class="skill-label-text">Windows</span>
                            </label>
                        </div>
                        <div style="font-size: 11px; color: #666; margin-top: 8px;">
                            Select skills and specializations for this employee
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Weekly Schedule:</label>
                    
                    <!-- Template Selection -->
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <strong>📋 Schedule Templates</strong>
                            <button type="button" onclick="if(window.ScheduleApp) ScheduleApp.toggleTemplateManagement(); else toggleTemplateManagement();" style="background: none; border: none; color: #007bff; cursor: pointer; font-size: 12px;">Manage Templates</button>
                        </div>
                        
                        <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">
                            <select id="modalTemplateSelect" onchange="if(window.ScheduleApp && ScheduleApp.applyTemplate) ScheduleApp.applyTemplate(); else if(typeof applyTemplate === 'function') applyTemplate();" style="flex: 1;">
                                <option value="">Select a template...</option>
                                <?php foreach ($scheduleTemplates as $template): ?>
                                <option value="<?php echo $template['id']; ?>" 
                                        data-schedule="<?php echo implode(',', $template['schedule']); ?>"
                                        title="<?php echo escapeHtml($template['description']); ?>">
                                    <?php echo escapeHtml($template['name']); ?> 
                                    (<?php echo formatScheduleDisplay($template['schedule']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" onclick="if(window.ScheduleApp && ScheduleApp.clearSchedule) ScheduleApp.clearSchedule(); else if(typeof clearSchedule === 'function') clearSchedule();" style="background: var(--secondary-color, #6c757d); color: white; border: none; padding: 5px 10px; border-radius: 3px; font-size: 12px;">Clear</button>
                        </div>
                        
                        <!-- Template Management -->
                        <div id="modalTemplateManagement" style="display: none; border-top: 1px solid #dee2e6; padding-top: 15px; margin-top: 15px;">
                            <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                                <input type="text" id="modalNewTemplateName" placeholder="Template name..." style="flex: 1; padding: 5px 8px; font-size: 12px;">
                                <input type="text" id="modalNewTemplateDescription" placeholder="Description..." style="flex: 1; padding: 5px 8px; font-size: 12px;">
                                <button type="button" onclick="if(window.ScheduleApp) ScheduleApp.saveCurrentAsTemplate(); else saveCurrentAsTemplate();" style="background: var(--success-color, #28a745); color: white; border: none; padding: 5px 10px; border-radius: 3px; font-size: 12px;">Save Current</button>
                            </div>
                            
                            <div style="max-height: 120px; overflow-y: auto;">
                                <?php foreach ($scheduleTemplates as $template): ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 5px 0; border-bottom: 1px solid #eee; font-size: 12px;">
                                    <div>
                                        <strong><?php echo escapeHtml($template['name']); ?></strong><br>
                                        <span style="color: #666;"><?php echo escapeHtml($template['description']); ?> - <?php echo formatScheduleDisplay($template['schedule']); ?></span><br>
                                        <small style="color: #999;">Created by <?php echo escapeHtml($template['created_by']); ?></small>
                                    </div>
                                    <?php if ($template['created_by'] !== 'System'): ?>
                                    <button type="button" onclick="if(window.ScheduleApp) ScheduleApp.deleteTemplate(<?php echo $template['id']; ?>, '<?php echo addslashes($template['name']); ?>')" 
                                            style="background: #dc3545; color: white; border: none; padding: 2px 6px; border-radius: 3px; font-size: 10px;">Delete</button>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Schedule Grid -->
                    <div class="schedule-grid">
                        <div><strong>Sun</strong></div>
                        <div><strong>Mon</strong></div>
                        <div><strong>Tue</strong></div>
                        <div><strong>Wed</strong></div>
                        <div><strong>Thu</strong></div>
                        <div><strong>Fri</strong></div>
                        <div><strong>Sat</strong></div>
                        
                        <div><input type="checkbox" name="day0" id="modalAddDay0"></div>
                        <div><input type="checkbox" name="day1" id="modalAddDay1" checked></div>
                        <div><input type="checkbox" name="day2" id="modalAddDay2" checked></div>
                        <div><input type="checkbox" name="day3" id="modalAddDay3" checked></div>
                        <div><input type="checkbox" name="day4" id="modalAddDay4" checked></div>
                        <div><input type="checkbox" name="day5" id="modalAddDay5" checked></div>
                        <div><input type="checkbox" name="day6" id="modalAddDay6"></div>
                    </div>
                    
                    <div class="template-btns">
                        Quick Templates: 
                        <button type="button" onclick="if(window.ScheduleApp && ScheduleApp.setTemplate) ScheduleApp.setTemplate('weekdays'); else if(typeof setTemplate === 'function') setTemplate('weekdays');">Mon-Fri</button>
                        <button type="button" onclick="if(window.ScheduleApp && ScheduleApp.setTemplate) ScheduleApp.setTemplate('weekend'); else if(typeof setTemplate === 'function') setTemplate('weekend');">Weekends</button>
                        <button type="button" onclick="if(window.ScheduleApp && ScheduleApp.setTemplate) ScheduleApp.setTemplate('all'); else if(typeof setTemplate === 'function') setTemplate('all');">All Days</button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:5px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="#4A154B"><path d="M5.042 15.165a2.528 2.528 0 0 1-2.52 2.523A2.528 2.528 0 0 1 0 15.165a2.527 2.527 0 0 1 2.522-2.52h2.52v2.52zM6.313 15.165a2.527 2.527 0 0 1 2.521-2.52 2.527 2.527 0 0 1 2.521 2.52v6.313A2.528 2.528 0 0 1 8.834 24a2.528 2.528 0 0 1-2.521-2.522v-6.313zM8.834 5.042a2.528 2.528 0 0 1-2.521-2.52A2.528 2.528 0 0 1 8.834 0a2.528 2.528 0 0 1 2.521 2.522v2.52H8.834zM8.834 6.313a2.528 2.528 0 0 1 2.521 2.521 2.528 2.528 0 0 1-2.521 2.521H2.522A2.528 2.528 0 0 1 0 8.834a2.528 2.528 0 0 1 2.522-2.521h6.312zM18.956 8.834a2.528 2.528 0 0 1 2.522-2.521A2.528 2.528 0 0 1 24 8.834a2.528 2.528 0 0 1-2.522 2.521h-2.522V8.834zM17.688 8.834a2.528 2.528 0 0 1-2.523 2.521 2.527 2.527 0 0 1-2.52-2.521V2.522A2.527 2.527 0 0 1 15.165 0a2.528 2.528 0 0 1 2.523 2.522v6.312zM15.165 18.956a2.528 2.528 0 0 1 2.523 2.522A2.528 2.528 0 0 1 15.165 24a2.527 2.527 0 0 1-2.52-2.522v-2.522h2.52zM15.165 17.688a2.527 2.527 0 0 1-2.52-2.523 2.526 2.526 0 0 1 2.52-2.52h6.313A2.527 2.527 0 0 1 24 15.165a2.528 2.528 0 0 1-2.522 2.523h-6.313z"/></svg>
                        Slack Member ID: <span style="font-weight:400;font-size:11px;color:#888;">(optional)</span>
                    </label>
                    <input type="text" name="empSlackId" id="modalAddEmpSlackId"
                           placeholder="e.g. U01AB2CD3EF"
                           maxlength="50"
                           style="font-family:monospace;">
                    <div class="form-note">Open Slack → click your profile → ⋯ More → <strong>Copy member ID</strong>. Adds a clickable Slack link on the schedule.</div>
                </div>

                <div style="text-align: center; margin-top: 20px;">
                    <button type="submit">Add Employee</button>
                    <button type="button" onclick="ScheduleApp.closeModal()" style="background: var(--secondary-color, #6c757d); margin-left: 10px;">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Employee Setup Guide Modal (replaced by checklist tab — kept as hidden fallback) -->
    <?php if (false): // Replaced by checklist tab ?>
    <div id="employeeSetupGuideModal" class="modal">
        <div class="modal-content" style="max-width: 1400px; max-height: 90vh; overflow-y: auto; padding: 40px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;">📋 Employee Setup Checklist for Sorting</h2>
                <button onclick="closeEmployeeSetupGuide()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">&times;</button>
            </div>
            
            <div style="background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                <strong>⚠️ Important:</strong> Follow this checklist to ensure employees are properly configured for schedule sorting and shift rotation.
            </div>
            
            <div style="background: #f8f9fa; border-left: 4px solid #007bff; padding: 20px; margin-bottom: 25px; border-radius: 4px;">
                <h3 style="margin-top: 0; color: #007bff;">✅ STEP 1: Configure Base Employee Settings</h3>
                <p style="margin-bottom: 10px;"><strong>Using the Gear Icon (⚙️) in Employee Column:</strong></p>
                <ol style="line-height: 1.8; padding-left: 25px; margin-left: 0;">
                    <li>Click the <strong>gear icon (⚙️)</strong> next to the employee's name in the schedule</li>
                    <li><strong>Set Base Schedule:</strong> Check the days the employee normally works (Sun-Sat)</li>
                    <li><strong>Verify Shift Assignment:</strong> Ensure correct shift is selected (1st, 2nd, or 3rd Shift)</li>
                    <li><strong>Set Skills & Specializations:</strong>
                        <ul style="margin-top: 5px; padding-left: 25px;">
                            <li>☑️ MH (Managed Hosting)</li>
                            <li>☑️ MA (Managed Apps)</li>
                            <li>☑️ Win (Windows)</li>
                        </ul>
                    </li>
                    <li><strong>Set Default Working Hours:</strong> Enter standard hours (e.g., 9p-5p, 2pm-10p)</li>
                    <li>Click <strong>"Update Employee"</strong> to save</li>
                </ol>
            </div>
            
            <div style="background: #f8f9fa; border-left: 4px solid var(--success-color, #28a745); padding: 20px; margin-bottom: 25px; border-radius: 4px;">
                <h3 style="margin-top: 0; color: var(--success-color, #28a745);">STEP 2: Configure Custom Schedules</h3>
                <p style="margin-bottom: 10px;"><strong>Using the Bulk Schedule Editor:</strong></p>
                
                <div style="background: var(--card-bg, white); color: var(--text-color, #333); padding: 15px; border-radius: 4px; margin-bottom: 15px; border: 1px solid var(--border-color, #e0e0e0);">
                    <strong>A. Access Bulk Editor</strong>
                    <p style="margin: 5px 0 0 0;">Click the <strong>"Bulk Schedule Changes"</strong> button at the top of the schedule (or from user menu)</p>
                </div>
                
                <div style="background: var(--card-bg, white); color: var(--text-color, #333); padding: 15px; border-radius: 4px; margin-bottom: 15px; border: 1px solid var(--border-color, #e0e0e0);">
                    <strong>B. Configure Date Range</strong>
                    <p style="margin: 5px 0 0 0;">Set <strong>Start Date</strong> and <strong>End Date</strong> for the rotation period (can span multiple months/years)</p>
                </div>
                
                <div style="background: var(--card-bg, white); color: var(--text-color, #333); padding: 15px; border-radius: 4px; margin-bottom: 15px; border: 1px solid var(--border-color, #e0e0e0);">
                    <strong>C. Choose Schedule Application Method</strong>
                    <p style="margin: 5px 0;"><strong>Option 1:</strong> ☑️ <strong>"Skip days off (only apply to scheduled working days)"</strong></p>
                    <p style="margin: 5px 0 5px 20px; font-size: 13px; color: #666;">Use this to keep existing base schedule and only change shift assignment</p>
                    
                    <p style="margin: 15px 0 5px 0;"><strong>Option 2:</strong> ☑️ <strong>"Set New Weekly Schedule (Optional)"</strong></p>
                    <p style="margin: 5px 0 5px 20px; font-size: 13px; color: #666;">Use this if employee's working days change during the rotation</p>
                </div>
                
                <div style="background: var(--card-bg, white); color: var(--text-color, #333); padding: 15px; border-radius: 4px; margin-bottom: 15px; border: 1px solid var(--border-color, #e0e0e0);">
                    <strong>D. Select Employees</strong>
                    <p style="margin: 5px 0 0 0;">In <strong>"Select Employees"</strong> section, check the box next to each employee for this rotation</p>
                </div>
                
                <div style="background: var(--card-bg, white); color: var(--text-color, #333); padding: 15px; border-radius: 4px; margin-bottom: 15px; border: 1px solid var(--border-color, #e0e0e0);">
                    <strong>E. Configure Shift Change</strong>
                    <ol style="margin: 10px 0 0 0; line-height: 1.8; padding-left: 25px;">
                        <li><strong>Status to Apply:</strong> Select <strong>"Custom Hours"</strong> and enter specific hours (e.g., 15-23)</li>
                        <li><strong>Shift Assignment:</strong> ☑️ Check <strong>"Also Change Shift Assignment"</strong> and select new shift (1st, 2nd, or 3rd)</li>
                        <li><strong>Change Timing:</strong>
                            <ul style="margin-top: 5px; padding-left: 25px;">
                                <li>☑️ <strong>"Change Now (Immediate)"</strong> - for immediate shift changes</li>
                                <li><em>OR</em> ☑️ <strong>"Change on Start Date"</strong> - for scheduled future changes</li>
                            </ul>
                        </li>
                    </ol>
                </div>
                
                <div style="background: var(--card-bg, white); color: var(--text-color, #333); padding: 15px; border-radius: 4px; margin-bottom: 15px; border: 1px solid var(--border-color, #e0e0e0);">
                    <strong>F. Apply Changes</strong>
                    <p style="margin: 5px 0 0 0;">Click <strong>"Apply Bulk Changes"</strong> and verify changes in the schedule grid</p>
                </div>
            </div>
            
            <div style="background: #ffe6e6; border: 2px solid #dc3545; border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                <h3 style="margin-top: 0; color: #dc3545;">⚠️ Critical Requirements for Sorting</h3>
                <p><strong>For sorting to work correctly, you MUST:</strong></p>
                <ul style="line-height: 1.8; padding-left: 25px; margin-left: 0;">
                    <li>✅ Set base schedule via gear icon</li>
                    <li>✅ Assign correct shift (1st, 2nd, or 3rd)</li>
                    <li>✅ Set skills for each admin (MH, MA, Win)</li>
                    <li>✅ Use bulk editor for any shift rotations</li>
                    <li>✅ Always check "Also Change Shift Assignment" when using bulk editor</li>
                    <li>✅ Choose appropriate change timing (Now or Start Date)</li>
                </ul>
            </div>
            
            <div style="background: #e7f3ff; border-left: 4px solid #2196F3; padding: 20px; margin-bottom: 20px; border-radius: 4px;">
                <h3 style="margin-top: 0; color: #2196F3;">💡 Example Workflow</h3>
                <p><strong>Scenario:</strong> Moving employee from 1st to 2nd shift for 2 weeks</p>
                
                <div style="margin-top: 15px;">
                    <strong>1. Gear Icon Setup (One-time):</strong>
                    <ul style="margin: 5px 0; padding-left: 25px;">
                        <li>Base schedule: Mon-Fri ✅</li>
                        <li>Default shift: 1st Shift</li>
                        <li>Skills: MH ✅, MA ✅</li>
                        <li>Hours: 9-17</li>
                    </ul>
                </div>
                
                <div style="margin-top: 15px;">
                    <strong>2. Bulk Editor (For Rotation):</strong>
                    <ul style="margin: 5px 0; padding-left: 25px;">
                        <li>Date range: 12/01/2025 to 12/14/2025</li>
                        <li>Skip days off: ✅</li>
                        <li>Select employee: ✅</li>
                        <li>Status: Custom Hours (15-23)</li>
                        <li>Also Change Shift: ✅ → 2nd Shift</li>
                        <li>Change on Start Date: ✅</li>
                        <li>Apply Bulk Changes</li>
                    </ul>
                </div>
                
                <div style="margin-top: 15px;">
                    <strong>3. Result:</strong>
                    <ul style="margin: 5px 0; padding-left: 25px;">
                        <li>Employee works Mon-Fri (base schedule)</li>
                        <li>From 12/01-12/14: 2nd Shift (15-23)</li>
                        <li>After 12/14: Returns to 1st Shift (9-17)</li>
                        <li>Sorting recognizes shift change correctly ✅</li>
                    </ul>
                </div>
            </div>
            
            <div style="background: var(--card-bg, white); color: var(--text-color, #333); border: 1px solid var(--border-color, #ddd); border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                <h3 style="margin-top: 0; color: var(--text-color, #333);">🆘 Troubleshooting</h3>
                
                <div style="margin-bottom: 15px;">
                    <strong>Sorting Not Working?</strong>
                    <ol style="margin: 5px 0; line-height: 1.8; padding-left: 25px;">
                        <li>Check if employee has skills set (gear icon)</li>
                        <li>Verify shift is assigned correctly</li>
                        <li>Ensure base schedule exists</li>
                        <li>Try re-applying bulk changes with "Also Change Shift Assignment"</li>
                    </ol>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <strong>Shift Not Changing?</strong>
                    <ol style="margin: 5px 0; line-height: 1.8; padding-left: 25px;">
                        <li>Make sure "Also Change Shift Assignment" is checked</li>
                        <li>Verify date range includes today (if using "Change Now")</li>
                        <li>Check employee is selected in employee list</li>
                        <li>Confirm shift was selected from dropdown</li>
                    </ol>
                </div>
                
                <div>
                    <strong>Hours Not Showing?</strong>
                    <ol style="margin: 5px 0; line-height: 1.8; padding-left: 25px;">
                        <li>Use "Custom Hours" status</li>
                        <li>Enter hours in correct format (e.g., 9-17, 8-12&14-18)</li>
                        <li>Make sure "Skip days off" is checked if keeping base schedule</li>
                        <li>Verify date range is correct</li>
                    </ol>
                </div>
            </div>
            
            <div style="text-align: center; padding-top: 10px;">
                <button onclick="closeEmployeeSetupGuide()" style="background: #007bff; color: white; border: none; padding: 12px 30px; border-radius: 4px; cursor: pointer; font-size: 16px;">Got It!</button>
            </div>
        </div>
    </div>
    <?php endif; // end false — modal replaced by checklist tab ?>

    <!-- Selective Restore Modal -->
    <?php if (hasPermission('manage_backups')): ?>
    <div id="selectiveRestoreModal" class="modal">
        <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;">👥 Selective Employee Restore</h2>
                <button onclick="closeSelectiveRestoreModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">&times;</button>
            </div>
            
            <div style="background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                <strong>⚠️ Warning:</strong> This will restore selected employees from the backup, overwriting their current data. An emergency backup will be created automatically.
            </div>
            
            <form method="POST" id="selectiveRestoreForm">
                <input type="hidden" name="action" value="selective_restore_backup">
                <input type="hidden" name="current_tab" value="backups">
                <input type="hidden" name="snapshot_id" id="selectiveRestoreSnapshotId" value="">
                
                <div style="margin-bottom: 20px;">
                    <label style="font-weight: bold; display: block; margin-bottom: 10px;">
                        💾 Backup: <span id="selectiveRestoreBackupName" style="color: var(--primary-color, #667eea);"></span>
                    </label>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight: bold; display: block; margin-bottom: 10px;">🔍 Search Employees:</label>
                    <input type="text" id="selectiveRestoreSearch" placeholder="Search by name..." 
                           style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;"
                           oninput="filterSelectiveRestoreEmployees()">
                </div>
                
                <div style="margin-bottom: 15px; display: flex; gap: 10px;">
                    <button type="button" onclick="selectAllSelectiveEmployees()" 
                            style="background: var(--success-color, #28a745); color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">
                        ✅ Select All
                    </button>
                    <button type="button" onclick="deselectAllSelectiveEmployees()" 
                            style="background: var(--secondary-color, #6c757d); color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">
                        ⬜ Deselect All
                    </button>
                    <span id="selectiveRestoreCount" style="margin-left: auto; padding: 8px; color: #666;">0 selected</span>
                </div>
                
                <div id="selectiveRestoreEmployeeList" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 8px; padding: 10px; background: #f8f9fa;">
                    <div style="text-align: center; padding: 40px; color: #666;">
                        Loading employees from backup...
                    </div>
                </div>
                
                <div style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="closeSelectiveRestoreModal()" 
                            style="background: var(--secondary-color, #6c757d); color: white; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer;">
                        Cancel
                    </button>
                    <button type="submit" id="selectiveRestoreSubmitBtn"
                            style="background: var(--primary-color, #667eea); color: white; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-weight: bold;">
                        🔄 Restore Selected Employees
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Include modals from modals.php (which already calls getProfileModalHTML() internally) -->
    <?php require_once __DIR__ . '/modals.php'; ?>

    <!-- JavaScript -->
    <script src="script.js?v=<?php echo time(); ?>"></script>
    <script>
        // Standalone toggle function for bulk schedule shift change (doesn't require ScheduleApp)
        function toggleShiftChangeStandalone() {
            const checkbox = document.getElementById('changeShiftCheckbox');
            const shiftDiv = document.getElementById('shiftChangeDiv');
            const shiftSelect = document.getElementById('newShift');
            
            if (checkbox && shiftDiv) {
                if (checkbox.checked) {
                    shiftDiv.style.display = 'block';
                    if (shiftSelect) shiftSelect.required = true;
                } else {
                    shiftDiv.style.display = 'none';
                    if (shiftSelect) {
                        shiftSelect.required = false;
                        shiftSelect.value = '';
                    }
                }
            }
        }
        
        // Update effective date message for shift changes
        function updateShiftEffectiveDate() {
            const whenRadios = document.getElementsByName('shiftChangeWhen');
            const effectiveDiv = document.getElementById('shiftEffectiveDate');
            const startDateInput = document.getElementById('bulkStartDate');
            
            if (!effectiveDiv) return;
            
            let selectedWhen = 'now';
            for (const radio of whenRadios) {
                if (radio.checked) {
                    selectedWhen = radio.value;
                    break;
                }
            }
            
            if (selectedWhen === 'start_date' && startDateInput && startDateInput.value) {
                const startDate = new Date(startDateInput.value);
                const formattedDate = startDate.toLocaleDateString('en-US', { 
                    year: 'numeric', 
                    month: 'short', 
                    day: 'numeric' 
                });
                effectiveDiv.innerHTML = '<strong>Note:</strong> Shift will change effective ' + formattedDate + '.';
                effectiveDiv.style.background = '#d4edda';
                effectiveDiv.style.color = '#155724';
            } else {
                effectiveDiv.innerHTML = '<strong>Note:</strong> Shift will change immediately when you submit this form.';
                effectiveDiv.style.background = '#fff3cd';
                effectiveDiv.style.color = '#856404';
            }
        }
        
        // Filter employees in bulk schedule form
        // Debounce helper function
        let filterDebounceTimer = null;
        
        function filterBulkEmployees() {
            // Clear existing timer
            if (filterDebounceTimer) {
                clearTimeout(filterDebounceTimer);
            }
            
            // Debounce the actual filtering
            filterDebounceTimer = setTimeout(() => {
                const searchInput = document.getElementById('bulkEmployeeSearch');
                const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
                const employeeList = document.getElementById('bulkEmployeeList');
                if (!employeeList) return;
                
                const employeeItems = employeeList.querySelectorAll('.bulk-employee-item');
                let visibleCount = 0;
                let totalCount = employeeItems.length;
                
                // Batch style changes
                requestAnimationFrame(() => {
                    employeeItems.forEach(item => {
                        const name = item.dataset.name || '';
                        const team = item.dataset.team || '';
                        const shift = item.dataset.shift || '';
                        
                        const matches = searchTerm === '' || 
                            name.includes(searchTerm) || 
                            team.includes(searchTerm) ||
                            shift.includes(searchTerm);
                        
                        item.style.display = matches ? '' : 'none';
                        if (matches) visibleCount++;
                    });
                    
                    // Update count display
                    const countElement = document.getElementById('bulkEmployeeCount');
                    if (countElement) {
                        if (searchTerm === '') {
                            countElement.textContent = `(${totalCount} total)`;
                        } else {
                            countElement.textContent = `(${visibleCount} of ${totalCount} shown)`;
                        }
                    }
                });
            }, 150); // 150ms debounce
        }
        
        // Add listeners for radio buttons and start date
        document.addEventListener('DOMContentLoaded', function() {
            const whenRadios = document.getElementsByName('shiftChangeWhen');
            whenRadios.forEach(radio => {
                radio.addEventListener('change', updateShiftEffectiveDate);
            });
            
            const startDateInput = document.getElementById('bulkStartDate');
            if (startDateInput) {
                startDateInput.addEventListener('change', updateShiftEffectiveDate);
            }
            
            // Initialize employee count
            filterBulkEmployees();
        });
    </script>
    <script>
        // Initialize users data for Profile Viewer
        window.usersData = <?php echo json_encode($usersForJS); ?>;
        window.employeesData = <?php echo json_encode($employeesForJS); ?>;
        
        // Set global variables for JavaScript
        window.currentYear = <?php echo $currentYear; ?>;
        window.currentMonth = <?php echo $currentMonth; ?>;
        window.currentMonthName = '<?php echo getMonthName($currentMonth); ?>';
        window.currentUserId   = <?php echo $currentUser['id']; ?>;
        window.currentUserRole = <?php echo json_encode($currentUser['role'] ?? 'employee'); ?>;
        
        // Initialize JavaScript configuration
        ScheduleApp.initializeConfig({
            sessionTimeoutMs: <?php echo SESSION_TIMEOUT_MINUTES * 90 * 1000; ?>,
            warningTimeMs: <?php echo 5 * 60 * 1000; ?>,
            autoRefreshMs: <?php echo 50 * 60 * 1000; ?>,
            hasManageEmployees: <?php echo hasPermission('manage_employees') ? 'true' : 'false'; ?>,
            hasEditSchedule: <?php echo hasPermission('edit_schedule') ? 'true' : 'false'; ?>,
            hasEditOwnSchedule: <?php echo hasPermission('edit_own_schedule') ? 'true' : 'false'; ?>,
            hasManageBackups: <?php echo hasPermission('manage_backups') ? 'true' : 'false'; ?>,
            hasManageUsers: <?php echo hasPermission('manage_users') ? 'true' : 'false'; ?>
        });
        
        // Note: All functions (viewUserProfile, showEmployeeCard, etc.) are already exposed in script.js
        
        // ── Fast gear-icon handler ────────────────────────────────────────────────
        // Pre-fills the already-in-DOM edit form using window.employeesData /
        // window.usersData and switches the tab — zero network requests, near-instant.
        // Falls back to a full page load for:
        //   • employees editing their own profile (form renders with restricted fields)
        //   • any employee not found in the JS cache
        window.openEditEmployeeInline = function(employeeId) {
            var emp = (window.employeesData || []).find(function(e) { return e.id == employeeId; });

            // Fast path only for admins/managers/supervisors whose form is fully enabled
            var role = window.currentUserRole || 'employee';
            var isMgmt = (role === 'admin' || role === 'manager' || role === 'supervisor');

            if (!emp || !isMgmt) {
                window.location.href = '?tab=edit-employee&id=' + employeeId;
                return;
            }

            var form = document.getElementById('editEmployeeForm');
            if (!form) {
                window.location.href = '?tab=edit-employee&id=' + employeeId;
                return;
            }

            // Find linked user (for auth_method + user role)
            var linkedUser = null;
            if (emp.user_id) {
                linkedUser = (window.usersData || []).find(function(u) { return u.id == emp.user_id; });
            }
            if (!linkedUser) {
                linkedUser = (window.usersData || []).find(function(u) { return u.employee_id == employeeId; });
            }
            // Last-resort fallback: match by email (handles employees whose link
            // was established only via email and neither user_id nor employee_id is set)
            if (!linkedUser && emp.email) {
                linkedUser = (window.usersData || []).find(function(u) {
                    return u.email && u.email.toLowerCase() === emp.email.toLowerCase();
                });
                // Heal emp.user_id in the JS cache so future lookups use the fast path
                if (linkedUser && !emp.user_id) emp.user_id = linkedUser.id;
            }

            // ── Set field helpers ────────────────────────────────────────────────
            function setVal(id, val) {
                var el = document.getElementById(id);
                if (el) el.value = (val !== undefined && val !== null) ? val : '';
            }
            function setChk(name, on) {
                var el = form.querySelector('[name="' + name + '"]');
                if (el) el.checked = !!on;
            }

            // ── Header bar ───────────────────────────────────────────────────────
            var shiftLabel = {1: '1st Shift', 2: '2nd Shift', 3: '3rd Shift'};
            var hName  = document.getElementById('editEmployeeHeaderName');
            var hTeam  = document.getElementById('editEmployeeHeaderTeam');
            var hShift = document.getElementById('editEmployeeHeaderShift');
            var hId    = document.getElementById('editEmployeeHeaderId');
            if (hName)  hName.textContent  = emp.name || '';
            if (hTeam)  hTeam.textContent  = (emp.team || '').toUpperCase();
            if (hShift) hShift.textContent = shiftLabel[emp.shift] || ('Shift ' + emp.shift);
            if (hId)    hId.textContent    = '#' + emp.id;

            // ── Photo avatar ─────────────────────────────────────────────────────
            var avatarWrap = document.getElementById('editEmpAvatarWrap');
            if (avatarWrap) {
                var nameParts = (emp.name || 'U').trim().split(/\s+/);
                var initials = nameParts.map(function(w){ return w[0] || ''; }).join('').substring(0, 2).toUpperCase();
                var photoUrl = linkedUser ? (linkedUser.photo_url || '') : '';
                if (photoUrl) {
                    avatarWrap.innerHTML =
                        '<div style="display:flex;width:100%;height:100%;align-items:center;justify-content:center;font-size:20px;font-weight:bold;color:white !important;">' + initials + '</div>' +
                        '<img src="' + photoUrl + '" alt="Profile Photo" style="width:100%;height:100%;object-fit:cover;display:none;position:absolute;top:0;left:0;" ' +
                        'onload="this.style.display=\'\';this.previousElementSibling.style.display=\'none\';" ' +
                        'onerror="this.style.display=\'none\';this.previousElementSibling.style.display=\'flex\';">';
                } else {
                    avatarWrap.innerHTML =
                        '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:bold;color:white !important;">' + initials + '</div>';
                }
            }

            // ── Core fields ──────────────────────────────────────────────────────
            setVal('editEmpId',           emp.id);
            setVal('editEmpName',         emp.name);
            setVal('editEmpTeam',         emp.team);
            setVal('editEmpLevel',        emp.level        || '');
            setVal('editEmpEmail',        emp.email        || '');
            setVal('editEmpSlackId',      emp.slack_id     || '');
            setVal('editEmpShift',        emp.shift        || 1);
            setVal('editEmpHours',        emp.hours        || '');
            setVal('editEmpSupervisor',   emp.supervisor_id || '');
            setVal('editEmpStartDate',    emp.start_date   || '');
            setVal('editEmpScheduleAccess', emp.schedule_access || '');

            // ── User-linked fields ───────────────────────────────────────────────
            setVal('editEmpAuthMethod', linkedUser ? (linkedUser.auth_method || 'both') : 'both');
            setVal('editEmpUserRole',   linkedUser ? (linkedUser.role        || 'employee') : 'employee');

            // ── Skills ───────────────────────────────────────────────────────────
            var sk = emp.skills || {};
            setChk('skillMH',  sk.mh);
            setChk('skillMA',  sk.ma);
            setChk('skillWin', sk.win);

            // ── Working day checkboxes ────────────────────────────────────────────
            var sched = emp.schedule || [0,1,1,1,1,1,0];
            for (var i = 0; i < 7; i++) { setChk('day' + i, sched[i]); }

            // ── Switch tab without page reload ────────────────────────────────────
            // Push a history entry so the browser Back button still works
            if (window.history && window.history.pushState) {
                window.history.pushState(
                    {tab: 'edit-employee', id: employeeId},
                    '',
                    '?tab=edit-employee&id=' + employeeId
                );
            }
            // Manually show the tab (avoids showTab() overwriting our URL)
            document.querySelectorAll('.tab-content').forEach(function(t) { t.classList.remove('active'); });
            document.querySelectorAll('.nav-tab').forEach(function(t) { t.classList.remove('active'); });
            var editTab = document.getElementById('edit-employee-tab');
            if (editTab) { editTab.classList.add('active'); editTab.style.display = ''; }
            window.scrollTo(0, 0);
        };
        
        // Helper function to get shift name
        function getShiftName(shift) {
            switch(parseInt(shift)) {
                case 1: return '1st Shift';
                case 2: return '2nd Shift';
                case 3: return '3rd Shift';
                default: return 'Shift ' + shift;
            }
        }
        
        // Delete current employee from edit form - reads values from the form itself
        window.deleteCurrentEmployee = function() {
            // Get the current employee ID and name from the form
            const empIdField = document.getElementById('editEmpId');
            const empNameField = document.getElementById('editEmpName');
            
            if (!empIdField || !empIdField.value) {
                showNotification('No employee selected', 'warning');
                return;
            }
            
            const employeeId = empIdField.value;
            const employeeName = empNameField ? empNameField.value : 'this employee';
            
            if (confirm('Are you sure you want to delete ' + employeeName + '? This action cannot be undone.')) {
                // Create and submit a form for deletion
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="delete_employee">' +
                                 '<input type="hidden" name="employeeId" value="' + employeeId + '">';
                document.body.appendChild(form);
                form.submit();
            }
        };
        
        // ── Hours format normalizer ───────────────────────────────────────────────
        // Converts 24-hour numeric inputs (e.g. "9-17", "15-23") to 12-hour format
        // on-the-fly as the user types or leaves the field.
        function normalizeHoursInput(input) {
            const raw = input.value.trim();
            if (!raw) return;
            const converted = convertHoursTo12h(raw);
            if (converted && converted !== raw) {
                input.value = converted;
            }
        }

        function convertHoursTo12h(str) {
            if (!str) return str;

            // Already contains am/pm — leave as-is but tidy spacing
            if (/[ap]m/i.test(str)) {
                return str.trim().toLowerCase().replace(/\s*-\s*/g, '-').replace(/\s+/g, '');
            }

            // Normalise separators: support "–", "—", " to ", " - "
            str = str.trim().replace(/\s*[–—]\s*/g, '-').replace(/\s+to\s+/gi, '-');

            // Match one or two time ranges separated by & or comma (e.g. "8-12&14-18")
            const segmentPattern = /(\d{1,2})(?::(\d{2}))?-(\d{1,2})(?::(\d{2}))?/g;
            const converted = str.replace(segmentPattern, (match, h1, m1, h2, m2) => {
                const fmt = (h, m) => {
                    h = parseInt(h, 10);
                    m = m ? parseInt(m, 10) : 0;
                    const suffix = h >= 12 ? 'pm' : 'am';
                    const h12 = h === 0 ? 12 : h > 12 ? h - 12 : h;
                    return m === 0 ? `${h12}${suffix}` : `${h12}:${String(m).padStart(2,'0')}${suffix}`;
                };
                // Only convert if at least one value suggests 24h (>= 13 or looks like raw hour)
                const needsConvert = parseInt(h1,10) >= 13 || parseInt(h2,10) >= 13 ||
                                     (parseInt(h1,10) <= 12 && parseInt(h2,10) <= 12);
                return needsConvert ? `${fmt(h1,m1)}-${fmt(h2,m2)}` : match;
            });
            return converted;
        }
        // ── End hours normalizer ──────────────────────────────────────────────────

        // ── My Profile API Key actions ────────────────────────────────────────────
        function copyProfileApiKey() {
            const input = document.getElementById('profileApiKeyDisplay');
            if (!input) return;
            navigator.clipboard.writeText(input.value).then(() => {
                showProfileApiMsg('✅ Copied to clipboard!', '#22c55e');
            }).catch(() => {
                input.select();
                document.execCommand('copy');
                showProfileApiMsg('✅ Copied to clipboard!', '#22c55e');
            });
        }

        function showProfileApiMsg(msg, color) {
            const el = document.getElementById('profileApiKeyMsg');
            if (!el) return;
            el.textContent = msg;
            el.style.color = color || 'var(--text-color,#333)';
            setTimeout(() => { el.textContent = ''; }, 4000);
        }

        function generateProfileApiKey() {
            if (!confirm('Generate a new API key? Any existing key will be replaced and immediately invalidated.')) return;
            fetch('api.php?action=generate_api_key', { method: 'POST', credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (data.api_key) {
                        showProfileApiMsg('✅ New key generated — page will reload.', '#22c55e');
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        showProfileApiMsg('❌ ' + (data.error || 'Failed to generate key.'), '#dc3545');
                    }
                })
                .catch(() => showProfileApiMsg('❌ Network error.', '#dc3545'));
        }

        function revokeProfileApiKey() {
            if (!confirm('Revoke your API key? Any integrations using this key will stop working immediately.')) return;
            fetch('api.php?action=revoke_api_key', { method: 'POST', credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (data.success || data.revoked) {
                        showProfileApiMsg('🗑️ Key revoked — page will reload.', '#f59e0b');
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        showProfileApiMsg('❌ ' + (data.error || 'Failed to revoke key.'), '#dc3545');
                    }
                })
                .catch(() => showProfileApiMsg('❌ Network error.', '#dc3545'));
        }
        // ── End API Key actions ───────────────────────────────────────────────────

        // Employee edit modal functions
        // Employee Setup Guide functions
        // Employee Setup Guide — now a full tab page; redirect any legacy calls
        window.openEmployeeSetupGuide = function() {
            showTab('checklist');
        };
        window.closeEmployeeSetupGuide = function() {
            // No-op: modal replaced by tab
        };
        
        // Merge Restore with optional force-overwrite prompt
        window.doMergeRestore = function(snapId) {
            var forceInput = document.getElementById('forceOverwrite_' + snapId);
            var msg = 'Merge this backup into current data?\n\n' +
                      '• New employees from backup will be ADDED.\n' +
                      '• Employees already updated locally (after this backup was taken) will NOT be overwritten.\n' +
                      '• Schedule overrides that already exist locally will NOT be overwritten.\n\n' +
                      'Current data will be auto-saved as a pre-merge snapshot first.\n\n' +
                      'Click OK for standard merge, or CANCEL then use "Force Overwrite" to make backup win over newer local data.';
            var choice = confirm(msg);
            if (!choice) {
                // Ask if they want force overwrite instead
                var force = confirm('Did you want to FORCE OVERWRITE?\n\nThis makes the backup data win even when local records are newer (useful when syncing from production).\n\nClick OK to force-overwrite, or Cancel to abort.');
                if (!force) return;
                if (forceInput) forceInput.value = '1';
            } else {
                if (forceInput) forceInput.value = '0';
            }
            document.getElementById('mergeForm_' + snapId).submit();
        };

        // Selective Restore Modal Functions
        let selectiveRestoreEmployees = [];
        
        window.openSelectiveRestoreModal = function(snapshotId, snapshotName) {
            const modal = document.getElementById('selectiveRestoreModal');
            if (!modal) {
                showNotification('Selective restore modal not found. Please refresh the page.', 'error');
                return;
            }
            
            // Set snapshot ID and display name
            document.getElementById('selectiveRestoreSnapshotId').value = snapshotId;
            document.getElementById('selectiveRestoreBackupName').textContent = snapshotName || ('Snapshot #' + snapshotId);
            
            // Clear search
            document.getElementById('selectiveRestoreSearch').value = '';
            
            // Show loading
            document.getElementById('selectiveRestoreEmployeeList').innerHTML = '<div style="text-align: center; padding: 40px; color: #666;">Loading employees from backup...</div>';
            
            // Show modal
            modal.classList.add('show');
            
            // Fetch employees from DB snapshot
            fetch('?action=get_backup_employees&snapshot_id=' + encodeURIComponent(snapshotId))
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.employees) {
                        selectiveRestoreEmployees = data.employees;
                        renderSelectiveRestoreEmployees();
                    } else {
                        document.getElementById('selectiveRestoreEmployeeList').innerHTML = 
                            '<div style="text-align: center; padding: 40px; color: #dc3545;">Error loading employees: ' + (data.error || 'Unknown error') + '</div>';
                    }
                })
                .catch(error => {
                    document.getElementById('selectiveRestoreEmployeeList').innerHTML = 
                        '<div style="text-align: center; padding: 40px; color: #dc3545;">Error loading backup: ' + error.message + '</div>';
                });
        };
        
        window.closeSelectiveRestoreModal = function() {
            const modal = document.getElementById('selectiveRestoreModal');
            if (modal) {
                modal.classList.remove('show');
            }
            selectiveRestoreEmployees = [];
        };
        
        window.renderSelectiveRestoreEmployees = function() {
            const container = document.getElementById('selectiveRestoreEmployeeList');
            const searchTerm = document.getElementById('selectiveRestoreSearch').value.toLowerCase();
            
            let filteredEmployees = selectiveRestoreEmployees;
            if (searchTerm) {
                filteredEmployees = selectiveRestoreEmployees.filter(emp => 
                    emp.name.toLowerCase().includes(searchTerm) ||
                    (emp.team && emp.team.toLowerCase().includes(searchTerm))
                );
            }
            
            if (filteredEmployees.length === 0) {
                container.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;">No employees found</div>';
                return;
            }
            
            // Group by team
            const byTeam = {};
            filteredEmployees.forEach(emp => {
                const team = (emp.team || 'unknown').toUpperCase();
                if (!byTeam[team]) byTeam[team] = [];
                byTeam[team].push(emp);
            });
            
            let html = '';
            Object.keys(byTeam).sort().forEach(team => {
                html += '<div style="margin-bottom: 15px;">';
                html += '<div style="font-weight: bold; color: var(--primary-color, #667eea); padding: 5px 0; border-bottom: 1px solid var(--border-color, #ddd); margin-bottom: 8px;">' + team + ' (' + byTeam[team].length + ')</div>';
                
                byTeam[team].sort((a, b) => a.name.localeCompare(b.name)).forEach(emp => {
                    const shiftName = emp.shift == 1 ? '1st' : emp.shift == 2 ? '2nd' : emp.shift == 3 ? '3rd' : 'Unknown';
                    html += '<label style="display: flex; align-items: center; padding: 8px; margin: 4px 0; background: white; border-radius: 4px; cursor: pointer; border: 1px solid #eee;">';
                    html += '<input type="checkbox" name="selectedEmployees[]" value="' + emp.id + '" class="selective-restore-checkbox" onchange="updateSelectiveRestoreCount()" style="margin-right: 10px; transform: scale(1.2);">';
                    html += '<span style="flex: 1;">' + escapeHtml(emp.name) + '</span>';
                    html += '<span style="color: #666; font-size: 12px; margin-left: 10px;">' + shiftName + ' Shift</span>';
                    if (emp.level) {
                        html += '<span style="background: #e3f2fd; color: #1976d2; padding: 2px 8px; border-radius: 10px; font-size: 11px; margin-left: 8px;">' + emp.level.toUpperCase() + '</span>';
                    }
                    html += '</label>';
                });
                
                html += '</div>';
            });
            
            container.innerHTML = html;
            updateSelectiveRestoreCount();
        };
        
        window.filterSelectiveRestoreEmployees = function() {
            renderSelectiveRestoreEmployees();
        };
        
        window.selectAllSelectiveEmployees = function() {
            document.querySelectorAll('.selective-restore-checkbox').forEach(cb => cb.checked = true);
            updateSelectiveRestoreCount();
        };
        
        window.deselectAllSelectiveEmployees = function() {
            document.querySelectorAll('.selective-restore-checkbox').forEach(cb => cb.checked = false);
            updateSelectiveRestoreCount();
        };
        
        window.updateSelectiveRestoreCount = function() {
            const count = document.querySelectorAll('.selective-restore-checkbox:checked').length;
            document.getElementById('selectiveRestoreCount').textContent = count + ' selected';
            document.getElementById('selectiveRestoreSubmitBtn').disabled = count === 0;
        };
        
        // Helper function for escaping HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Profile Modal function - opens the profile EDIT modal
        window.openProfileModal = function(userId) {
            const modal = document.getElementById('profileModal');
            if (!modal) {
                return;
            }
            
            // Get the user data
            const user = userId ? window.usersData.find(u => u.id == userId) : window.usersData.find(u => u.id == window.currentUserId);
            
            if (!user) {
                return;
            }
            
            
            // Populate the form - use modal IDs
            const targetUserId = document.getElementById('modalTargetUserId') || document.getElementById('targetUserId');
            const fullName = document.getElementById('modalProfile_full_name') || document.getElementById('profile_full_name');
            const email = document.getElementById('modalProfile_email') || document.getElementById('profile_email');
            
            if (targetUserId) targetUserId.value = user.id;
            if (fullName) fullName.value = user.full_name;
            if (email) email.value = user.email;
            
            // Update modal title
            const isSelf = user.id == window.currentUserId;
            const titleElement = document.getElementById('profileModalTitle');
            if (titleElement) {
                titleElement.textContent = isSelf ? 'Edit Profile' : 'Edit User Profile: ' + user.full_name;
            }
            
            // Show the modal
            modal.style.display = 'flex';
            modal.classList.add('show');
        };
        
        // Close profile modal function
        window.closeProfileModal = function() {
            const modal = document.getElementById('profileModal');
            if (modal) {
                modal.style.display = 'none';
                modal.classList.remove('show');
            }
        };
        
        // Initialize ScheduleApp if it doesn't exist
        if (typeof window.ScheduleApp === 'undefined') {
            window.ScheduleApp = {};
        }
        
        // Add Employee Modal functions
        window.ScheduleApp.openAddModal = function() {
            const modal = document.getElementById('addModal');
            if (modal) {
                modal.style.display = 'flex';
                modal.classList.add('show');
            } else {
            }
        };
        
        // closeModal for add employee modal
        window.ScheduleApp.closeModal = function() {
            const modal = document.getElementById('addModal');
            if (modal) {
                modal.style.display = 'none';
                modal.classList.remove('show');
            }
        };
        
        // Also expose as standalone function for compatibility
        window.openAddModal = window.ScheduleApp.openAddModal;
        
        window.closeAddModal = function() {
            const modal = document.getElementById('addModal');
            if (modal) {
                modal.style.display = 'none';
                modal.classList.remove('show');
            }
        };
        
        // Activity Log — now lives in the Settings tab
        window.ScheduleApp.toggleActivityLog = function() {
            showTab('settings');
        };
        
        // Refresh Activity Log — now inside Settings tab; page-refresh navigates there
        window.ScheduleApp.refreshActivityLog = function() {
            window.location.href = '?tab=settings';
        };
        
        // Helper function to get activity icons in JavaScript
        function getActivityIconJS(action) {
            const icons = {
                'schedule_change': '📅',
                'employee_add': '👤➕',
                'employee_edit': '👤✏️',
                'employee_delete': '👤🗑️',
                'user_add': '👥➕',
                'user_edit': '👥✏️',
                'user_delete': '👥🗑️',
                'profile_update': '👤✏️',
                'backup_create': '💾',
                'backup_upload': '📤',
                'backup_download': '📥',
                'backup_delete': '🗑️',
                'bulk_change': '📅🔄',
                'template_create': '📋➕',
                'template_delete': '📋🗑️',
                'login': '🔓',
                'logout': '🚪',
                'timeout': '⏰'
            };
            return icons[action] || '📋';
        }
        
        // Helper function to format time in JavaScript
        function formatActivityTimeJS(timestamp) {
            const now = new Date();
            const time = new Date(timestamp);
            const diffMs = now - time;
            const diffMins = Math.floor(diffMs / 60000);
            const diffHours = Math.floor(diffMs / 3600000);
            const diffDays = Math.floor(diffMs / 86400000);
            
            if (diffDays > 0) {
                return diffDays + ' day' + (diffDays > 1 ? 's' : '') + ' ago';
            } else if (diffHours > 0) {
                return diffHours + ' hour' + (diffHours > 1 ? 's' : '') + ' ago';
            } else if (diffMins > 0) {
                return diffMins + ' minute' + (diffMins > 1 ? 's' : '') + ' ago';
            } else {
                return 'Just now';
            }
        }
        
        // Helper function to escape HTML in JavaScript
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Activity log auto-refresh removed — log now lives in Settings tab (static PHP render)
        
        // Also add to ScheduleApp for compatibility with modals.php
        window.ScheduleApp.closeEditEmployeeModal = window.closeEditEmployeeModal;
        
        // Template functions for edit employee modal
        window.ScheduleApp.setEditTemplate = function(type) {
            if (type === 'weekdays') {
                // Monday-Friday
                for (let i = 0; i < 7; i++) {
                    const checkbox = document.getElementById('editDay' + i);
                    if (checkbox) checkbox.checked = (i >= 1 && i <= 5);
                }
            } else if (type === 'weekend') {
                // Saturday-Sunday
                for (let i = 0; i < 7; i++) {
                    const checkbox = document.getElementById('editDay' + i);
                    if (checkbox) checkbox.checked = (i === 0 || i === 6);
                }
            } else if (type === 'all') {
                // All days
                for (let i = 0; i < 7; i++) {
                    const checkbox = document.getElementById('editDay' + i);
                    if (checkbox) checkbox.checked = true;
                }
            }
        };
        
        window.ScheduleApp.applyEditTemplate = function() {
            const select = document.getElementById('editTemplateSelect');
            if (!select || !select.value) return;
            
            const option = select.options[select.selectedIndex];
            const scheduleStr = option.getAttribute('data-schedule');
            if (!scheduleStr) return;
            
            const schedule = scheduleStr.split(',');
            for (let i = 0; i < 7; i++) {
                const checkbox = document.getElementById('editDay' + i);
                if (checkbox) checkbox.checked = (schedule[i] === '1');
            }
            
            // Reset select
            select.selectedIndex = 0;
        };
        
        window.ScheduleApp.clearEditSchedule = function() {
            for (let i = 0; i < 7; i++) {
                const checkbox = document.getElementById('editDay' + i);
                if (checkbox) checkbox.checked = false;
            }
        };
        
        // Modal-specific template functions (for editEmployeeModal in modals.php)
        window.ScheduleApp.setModalEditTemplate = function(type) {
            if (type === 'weekdays') {
                for (let i = 0; i < 7; i++) {
                    const checkbox = document.getElementById('modalEditDay' + i);
                    if (checkbox) checkbox.checked = (i >= 1 && i <= 5);
                }
            } else if (type === 'weekend') {
                for (let i = 0; i < 7; i++) {
                    const checkbox = document.getElementById('modalEditDay' + i);
                    if (checkbox) checkbox.checked = (i === 0 || i === 6);
                }
            } else if (type === 'all') {
                for (let i = 0; i < 7; i++) {
                    const checkbox = document.getElementById('modalEditDay' + i);
                    if (checkbox) checkbox.checked = true;
                }
            }
        };
        
        window.ScheduleApp.applyModalEditTemplate = function() {
            const select = document.getElementById('modalEditTemplateSelect');
            if (!select || !select.value) return;
            
            const option = select.options[select.selectedIndex];
            const scheduleStr = option.getAttribute('data-schedule');
            if (!scheduleStr) return;
            
            const schedule = scheduleStr.split(',');
            for (let i = 0; i < 7; i++) {
                const checkbox = document.getElementById('modalEditDay' + i);
                if (checkbox) checkbox.checked = (schedule[i] === '1');
            }
            
            select.selectedIndex = 0;
        };
        
        window.ScheduleApp.clearModalEditSchedule = function() {
            for (let i = 0; i < 7; i++) {
                const checkbox = document.getElementById('modalEditDay' + i);
                if (checkbox) checkbox.checked = false;
            }
        };
        
        // Auto-match all employee emails from user records
        window.autoMatchAllEmails = function() {
            if (!confirm('This will automatically match employee emails from user records based on matching names.\n\nContinue?')) {
                return;
            }
            
            // Create a form and submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'auto_match_emails';
            form.appendChild(actionInput);
            
            document.body.appendChild(form);
            form.submit();
        };
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            // (employeeSetupGuideModal replaced by checklist tab — no modal to close)

            // Profile modal
            const profileModal = document.getElementById('profileModal');
            if (event.target === profileModal) {
                closeProfileModal();
            }
            
            // Add employee modal
            const addModal = document.getElementById('addModal');
            if (event.target === addModal) {
                if (window.ScheduleApp && window.ScheduleApp.closeModal) {
                    window.ScheduleApp.closeModal();
                } else {
                    closeAddModal();
                }
            }
            
            // Bulk schedule modal
            const bulkModal = document.getElementById('bulkModal');
            if (event.target === bulkModal) {
                if (window.ScheduleApp && window.ScheduleApp.closeBulkModal) {
                    window.ScheduleApp.closeBulkModal();
                }
            }
        });
        
        // Add global event listeners
        document.addEventListener('scroll', hideEmployeeCard, true);
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.employee-name') && !event.target.closest('.employee-hover-card')) {
                hideEmployeeCard();
            }
        });
        
        
    </script>


<!-- Heatmap JavaScript -->
<script>
// Heatmap data and state
let heatmapData = null;
let heatmapEmployees = <?php echo json_encode(array_values($employees)); ?>;
let heatmapOverrides = <?php echo json_encode($scheduleOverrides); ?>;
let heatmapDatesInitialized = false; // Track if dates have been set THIS page load

// Initialize date range - leave empty by default (will default to current week in logic)
function initializeDateRange() {
    const dateFromInput = document.getElementById('heatmapDateFrom');
    const dateToInput = document.getElementById('heatmapDateTo');
    
    if (!dateFromInput || !dateToInput) return;
    
    // Leave dates empty - the system will default to current week when processing
    // This keeps the UI clean and matches the "Clear Filters" behavior
    dateFromInput.value = '';
    dateToInput.value = '';
}


function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function updateHeatmapData() {
    
    const teamFilter = document.getElementById('heatmapTeamFilter').value;
    const dateFrom = document.getElementById('heatmapDateFrom').value;
    const dateTo = document.getElementById('heatmapDateTo').value;
    const timeFromFilter = document.getElementById('heatmapTimeFrom').value;
    const timeToFilter = document.getElementById('heatmapTimeTo').value;
    const levelFilter = document.getElementById('heatmapLevelFilter').value;
    const supervisorFilter = document.getElementById('heatmapSupervisorFilter').value;
    const dayFilter = document.getElementById('heatmapDayFilter').value;
    const shiftFilter = document.getElementById('heatmapShiftFilter').value;
    
    
    // Filter employees
    const filteredEmployees = heatmapEmployees.filter(emp => {
        // Shift filter
        if (shiftFilter !== 'all') {
            const empShift = parseInt(emp.shift);
            const filterShift = parseInt(shiftFilter);
            if (empShift !== filterShift) return false;
        }
        
        const teamMatch = teamFilter === 'all' || emp.team === teamFilter;
        
        // Time range matching - check if employee works during the selected time range
        let timeMatch = true;
        if (timeFromFilter !== 'all' && timeToFilter !== 'all') {
            const fromHour = parseInt(timeFromFilter);
            const toHour = parseInt(timeToFilter);
            
            // Get employee's working hours
            const parsedHours = parseHoursString(emp.hours);
            if (parsedHours) {
                const { start, end } = parsedHours;
                // Check if there's any overlap between employee hours and filter range
                timeMatch = hoursOverlap(start, end, fromHour, toHour);
            } else {
                // Fall back to shift-based hours
                const shiftNum = parseInt(emp.shift);
                let empStart, empEnd;
                switch(shiftNum) {
                    case 1: empStart = 7; empEnd = 15; break;
                    case 2: empStart = 15; empEnd = 23; break;
                    case 3: empStart = 23; empEnd = 7; break;
                    default: timeMatch = false; break;
                }
                if (timeMatch !== false) {
                    timeMatch = hoursOverlap(empStart, empEnd, fromHour, toHour);
                }
            }
        }
        
        const levelMatch = levelFilter === 'all' || emp.level === levelFilter || (!emp.level && levelFilter === '');
        
        let supervisorMatch = true;
        if (supervisorFilter !== 'all') {
            if (supervisorFilter === 'none') {
                supervisorMatch = !emp.supervisor_id;
            } else {
                supervisorMatch = emp.supervisor_id == supervisorFilter;
            }
        }
        
        return teamMatch && timeMatch && levelMatch && supervisorMatch;
    });
    
    
    heatmapData = {
        employees: filteredEmployees,
        overrides: heatmapOverrides,
        totalEmployees: filteredEmployees.length,
        activeTeams: new Set(filteredEmployees.map(e => e.team)).size,
        dayFilter: dayFilter,
        dateFrom: dateFrom,
        dateTo: dateTo
    };
    
    // Always update the stats bar (fast)
    renderHeatmapStats();

    // Only do the expensive full grid rebuild when the heatmap tab is actually visible.
    // If the tab is hidden (e.g. user is on the schedule tab editing employees),
    // mark it dirty and let showTab() trigger the render when the user opens the heatmap.
    const heatmapTab = document.getElementById('heatmap-tab');
    if (heatmapTab && heatmapTab.classList.contains('active')) {
        renderHeatmapGrid();
        window._heatmapDirty = false;
    } else {
        window._heatmapDirty = true;
    }
}

// Refresh heatmap - reload data without page refresh
function refreshHeatmap() {
    // Show loading indicator
    const statsContainer = document.getElementById('heatmapStatsContainer');
    const gridContainer = document.getElementById('heatmapGridContainer');
    
    if (statsContainer) {
        statsContainer.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;">🔄 Refreshing heatmap data...</div>';
    }
    if (gridContainer) {
        gridContainer.innerHTML = '<div class="loading">Reloading heatmap data...</div>';
    }
    
    // Update the heatmap data (this will re-render everything)
    updateHeatmapData();
}

// Clear all heatmap filters and reset to defaults
function clearHeatmapFilters() {
    
    // Clear date inputs (set to empty)
    const dateFromInput = document.getElementById('heatmapDateFrom');
    const dateToInput = document.getElementById('heatmapDateTo');
    
    
    if (dateFromInput) {
        dateFromInput.value = '';
    }
    if (dateToInput) {
        dateToInput.value = '';
    }
    
    // Reset all filter dropdowns to "all"
    const teamFilter = document.getElementById('heatmapTeamFilter');
    if (teamFilter) teamFilter.value = 'all';
    
    const timeFromFilter = document.getElementById('heatmapTimeFrom');
    if (timeFromFilter) timeFromFilter.value = 'all';
    
    const timeToFilter = document.getElementById('heatmapTimeTo');
    if (timeToFilter) timeToFilter.value = 'all';
    
    const levelFilter = document.getElementById('heatmapLevelFilter');
    if (levelFilter) levelFilter.value = 'all';
    
    const supervisorFilter = document.getElementById('heatmapSupervisorFilter');
    if (supervisorFilter) supervisorFilter.value = 'all';
    
    const dayFilter = document.getElementById('heatmapDayFilter');
    if (dayFilter) dayFilter.value = 'all';
    
    const shiftFilter = document.getElementById('heatmapShiftFilter');
    if (shiftFilter) shiftFilter.value = 'all';
    
    
    // Update the heatmap with cleared filters (will default to current week)
    updateHeatmapData();
}

// Helper function to check if two time ranges overlap
function hoursOverlap(start1, end1, start2, end2) {
    // Handle ranges that cross midnight
    const normalize = (s, e) => {
        if (s > e) {
            // Range crosses midnight
            return [[s, 24], [0, e]];
        }
        return [[s, e]];
    };
    
    const ranges1 = normalize(start1, end1);
    const ranges2 = normalize(start2, end2);
    
    // Check if any segments overlap
    for (const [s1, e1] of ranges1) {
        for (const [s2, e2] of ranges2) {
            if (s1 < e2 && s2 < e1) {
                return true;
            }
        }
    }
    return false;
}

function renderHeatmapStats() {
    if (!heatmapData) return;
    
    const now = new Date();
    const todayIndex = now.getDay();
    const todayDate = now.getDate();
    const todayMonth = now.getMonth(); // 0-indexed
    const todayYear = now.getFullYear();
    
    // Count ALL employees working today (unfiltered), accounting for overrides
    // This matches the home page "Working Today" count
    const workingToday = heatmapEmployees.filter(emp => {
        // Check for override first
        const overrideKey = `${emp.id}-${todayYear}-${todayMonth}-${todayDate}`;
        
        if (heatmapData.overrides && heatmapData.overrides[overrideKey]) {
            const status = heatmapData.overrides[overrideKey].status;
            // Only count as working if status is 'on' or 'custom_hours'
            return (status === 'on' || status === 'custom_hours');
        }
        
        // No override - use base schedule
        return emp.schedule && emp.schedule[todayIndex] === 1;
    }).length;
    
    // Calculate PTO and Sick Leave for the selected date range
    const ptoSickEmployees = getPTOandSickLeave();
    
    const html = `
        <div class="stat-card">
            <div class="stat-value">${heatmapData.totalEmployees}</div>
            <div class="stat-label">Total Employees</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">${workingToday}</div>
            <div class="stat-label">Working Today</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">${heatmapData.activeTeams}</div>
            <div class="stat-label">Active Teams</div>
        </div>
    `;
    
    document.getElementById('heatmapStatsContainer').innerHTML = html;
    
    // Render PTO/Sick Leave section if there are any employees on leave
    if (ptoSickEmployees.pto.length > 0 || ptoSickEmployees.sick.length > 0 || ptoSickEmployees.holiday.length > 0) {
        renderPTOSickSection(ptoSickEmployees);
    } else {
        // Clear the section if no one is on leave
        const ptoSection = document.getElementById('ptoSickSection');
        if (ptoSection) {
            ptoSection.innerHTML = '';
        }
    }
}

function getPTOandSickLeave() {
    const ptoEmployees = [];
    const sickEmployees = [];
    const holidayEmployees = [];
    
    // Get date range - prioritize heatmapData, then fall back to date inputs, then current week
    let startDate, endDate;
    
    if (heatmapData && heatmapData.dateFrom && heatmapData.dateTo) {
        startDate = new Date(heatmapData.dateFrom + 'T00:00:00');
        endDate = new Date(heatmapData.dateTo + 'T00:00:00');
    } else {
        // Try to get from date inputs
        const dateFromInput = document.getElementById('heatmapDateFrom');
        const dateToInput = document.getElementById('heatmapDateTo');
        
        if (dateFromInput && dateToInput && dateFromInput.value && dateToInput.value) {
            startDate = new Date(dateFromInput.value + 'T00:00:00');
            endDate = new Date(dateToInput.value + 'T00:00:00');
        } else {
            // Default to current week
            const today = new Date();
            const dayOfWeek = today.getDay();
            startDate = new Date(today);
            startDate.setDate(today.getDate() - dayOfWeek);
            endDate = new Date(startDate);
            endDate.setDate(startDate.getDate() + 6);
        }
    }
    
    // Loop through ALL employees to find PTO/sick/holiday regardless of current filters
    heatmapEmployees.forEach(emp => {
        let currentDate = new Date(startDate);
        const leaveDates = { pto: [], sick: [], holiday: [] };
        
        while (currentDate <= endDate) {
            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();
            const day = currentDate.getDate();
            
            // Build override key - must match PHP format: employeeId-year-month-day
            const overrideKey = `${emp.id}-${year}-${month}-${day}`;
            const override = heatmapOverrides[overrideKey];
            
            if (override) {
                const dateStr = `${month + 1}/${day}`;
                if (override.status === 'pto') {
                    leaveDates.pto.push(dateStr);
                } else if (override.status === 'sick') {
                    leaveDates.sick.push(dateStr);
                } else if (override.status === 'holiday') {
                    leaveDates.holiday.push(dateStr);
                }
            }
            
            currentDate.setDate(currentDate.getDate() + 1);
        }
        
        // Add employee to respective lists if they have leave
        if (leaveDates.pto.length > 0) {
            ptoEmployees.push({
                name: emp.name,
                team: emp.team,
                dates: leaveDates.pto
            });
        }
        if (leaveDates.sick.length > 0) {
            sickEmployees.push({
                name: emp.name,
                team: emp.team,
                dates: leaveDates.sick
            });
        }
        if (leaveDates.holiday.length > 0) {
            holidayEmployees.push({
                name: emp.name,
                team: emp.team,
                dates: leaveDates.holiday
            });
        }
    });
    
    return { pto: ptoEmployees, sick: sickEmployees, holiday: holidayEmployees };
}

function renderPTOSickSection(ptoSickData) {
    let html = '<div class="pto-sick-container" style="padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top: 20px;">';
    html += '<h3 style="margin: 0 0 15px 0; font-size: 18px; border-bottom: 2px solid; padding-bottom: 10px;">📅 Time Off During Selected Period</h3>';
    
    html += '<div style="display: flex; flex-direction: column; gap: 15px;">';
    
    // PTO Section
    html += '<div class="pto-section" style="border-left: 4px solid; padding: 15px; border-radius: 4px;">';
    html += '<h4 style="margin: 0 0 12px 0; font-size: 16px;">🏖️ PTO (Paid Time Off)</h4>';
    
    if (ptoSickData.pto.length === 0) {
        html += '<p style="margin: 0; color: #666; font-style: italic;">No employees on PTO</p>';
    } else {
        html += '<div style="display: flex; flex-wrap: wrap; gap: 10px;">';
        ptoSickData.pto.forEach(emp => {
            const teamLabel = getTeamDisplayName(emp.team);
            html += `
                <div class="employee-card" style="padding: 8px 12px; border-radius: 4px; border: 1px solid; display: inline-flex; align-items: center; gap: 10px; max-width: 100%;">
                    <span class="employee-name" style="font-weight: bold; white-space: normal; word-break: break-word;">${emp.name}</span>
                    <span class="pto-sick-team-badge" style="padding: 2px 6px; border-radius: 3px; font-weight: 600; font-size: 11px; white-space: nowrap; flex-shrink: 0;">${teamLabel}</span>
                    <span class="employee-dates" style="font-size: 13px; white-space: nowrap; flex-shrink: 0;">📆 ${emp.dates.join(', ')}</span>
                </div>
            `;
        });
        html += '</div>';
    }
    html += '</div>';
    
    // Sick Leave Section
    html += '<div class="sick-section" style="border-left: 4px solid; padding: 15px; border-radius: 4px;">';
    html += '<h4 style="margin: 0 0 12px 0; font-size: 16px;">🤒 Sick Leave</h4>';
    
    if (ptoSickData.sick.length === 0) {
        html += '<p style="margin: 0; color: #666; font-style: italic;">No employees on sick leave</p>';
    } else {
        html += '<div style="display: flex; flex-wrap: wrap; gap: 10px;">';
        ptoSickData.sick.forEach(emp => {
            const teamLabel = getTeamDisplayName(emp.team);
            html += `
                <div class="employee-card" style="padding: 8px 12px; border-radius: 4px; border: 1px solid; display: inline-flex; align-items: center; gap: 10px; max-width: 100%;">
                    <span class="employee-name" style="font-weight: bold; white-space: normal; word-break: break-word;">${emp.name}</span>
                    <span class="pto-sick-team-badge" style="padding: 2px 6px; border-radius: 3px; font-weight: 600; font-size: 11px; white-space: nowrap; flex-shrink: 0;">${teamLabel}</span>
                    <span class="employee-dates" style="font-size: 13px; white-space: nowrap; flex-shrink: 0;">📆 ${emp.dates.join(', ')}</span>
                </div>
            `;
        });
        html += '</div>';
    }
    html += '</div>';
    
    // Holiday Section
    html += '<div class="holiday-section" style="border-left: 4px solid; padding: 15px; border-radius: 4px;">';
    html += '<h4 style="margin: 0 0 12px 0; font-size: 16px;">🎉 Holiday</h4>';
    
    if (ptoSickData.holiday.length === 0) {
        html += '<p style="margin: 0; color: #666; font-style: italic;">No employees on holiday</p>';
    } else {
        html += '<div style="display: flex; flex-wrap: wrap; gap: 10px;">';
        ptoSickData.holiday.forEach(emp => {
            const teamLabel = getTeamDisplayName(emp.team);
            html += `
                <div class="employee-card" style="padding: 8px 12px; border-radius: 4px; border: 1px solid; display: inline-flex; align-items: center; gap: 10px; white-space: nowrap;">
                    <span class="employee-name" style="font-weight: bold;">${emp.name}</span>
                    <span class="pto-sick-team-badge" style="padding: 2px 6px; border-radius: 3px; font-weight: 600; font-size: 11px;">${teamLabel}</span>
                    <span class="employee-dates" style="font-size: 13px;">📆 ${emp.dates.join(', ')}</span>
                </div>
            `;
        });
        html += '</div>';
    }
    html += '</div>';
    
    html += '</div>'; // Close flex column
    html += '</div>'; // Close container
    
    // Insert or update the PTO/Sick section
    let ptoSection = document.getElementById('ptoSickSection');
    if (!ptoSection) {
        ptoSection = document.createElement('div');
        ptoSection.id = 'ptoSickSection';
        const statsContainer = document.getElementById('heatmapStatsContainer');
        statsContainer.parentNode.insertBefore(ptoSection, statsContainer.nextSibling);
    }
    ptoSection.innerHTML = html;
}

function getTeamDisplayName(teamKey) {
    const teamNames = {
        'learning_development': 'L&D',
        'esg': 'ESG',
        'support': 'Support',
        'windows': 'Windows',
        'security': 'Security',
        'migrations': 'Migrations',
        'Implementations': 'Implementations',
        'Account Services': 'Account Services',
        'Account Services Stellar': 'Account Services Stellar'
    };
    return teamNames[teamKey] || teamKey;
}

function renderHeatmapGrid() {
    
    if (!heatmapData) {
        return;
    }
    
    const container = document.getElementById('heatmapGridContainer');
    if (!container) {
        return;
    }
    
    
    const hours = Array.from({length: 24}, (_, i) => i);
    
    // Generate date range
    let dates = [];
    if (heatmapData.dateFrom && heatmapData.dateTo) {
        const startDate = new Date(heatmapData.dateFrom + 'T00:00:00');
        const endDate = new Date(heatmapData.dateTo + 'T00:00:00');
        
        let currentDate = new Date(startDate);
        while (currentDate <= endDate) {
            dates.push(new Date(currentDate));
            currentDate.setDate(currentDate.getDate() + 1);
        }
    } else {
        // Default to current week
        const today = new Date();
        const dayOfWeek = today.getDay();
        const startOfWeek = new Date(today);
        startOfWeek.setDate(today.getDate() - dayOfWeek);
        
        for (let i = 0; i < 7; i++) {
            const date = new Date(startOfWeek);
            date.setDate(startOfWeek.getDate() + i);
            dates.push(date);
        }
    }
    
    // Apply day filter if specified
    if (heatmapData.dayFilter && heatmapData.dayFilter !== 'all') {
        const dayIndex = parseInt(heatmapData.dayFilter);
        dates = dates.filter(date => date.getDay() === dayIndex);
    }
    
    // Build HTML as array for better performance
    const htmlParts = ['<div class="heatmap-grid">'];
    
    // Header row
    htmlParts.push('<div class="heatmap-header"></div>');
    hours.forEach(hour => {
        const displayHour = hour === 0 ? '12AM' : hour < 12 ? hour + 'AM' : hour === 12 ? '12PM' : (hour-12) + 'PM';
        htmlParts.push(`<div class="heatmap-header">${displayHour}</div>`);
    });
    
    // Date rows
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    
    dates.forEach(date => {
        const dayOfWeek = date.getDay();
        const dayName = days[dayOfWeek];
        const monthName = months[date.getMonth()];
        const dayNum = date.getDate();
        
        htmlParts.push(`<div class="heatmap-row-label">${dayName} ${monthName} ${dayNum}</div>`);
        
        hours.forEach(hour => {
            const coverage = calculateCoverageForDate(heatmapData.employees, date, hour);
            const intensity = calculateIntensity(coverage.count, coverage.total);
            const percentage = coverage.total > 0 ? ((coverage.count/coverage.total)*100).toFixed(0) : 0;
            
            // Safely handle employee list and level breakdown
            let employeeList = '';
            let levelBreakdownStr = '';
            try {
                if (coverage.employees && Array.isArray(coverage.employees)) {
                    employeeList = coverage.employees.map(emp => {
                        const skillsList = emp.skills ? 
                            Object.keys(emp.skills).filter(skill => emp.skills[skill]).join(',') : '';
                        return `${emp.name}::${emp.schedule || ''}::${emp.isOverride ? '1' : '0'}::${emp.level || ''}::${skillsList}::${emp.team || ''}`;
                    }).join('|||');
                }
                
                if (coverage.levelBreakdown) {
                    levelBreakdownStr = Object.entries(coverage.levelBreakdown)
                        .map(([level, count]) => `${level}:${count}`)
                        .join('|||');
                }
            } catch (e) {}
            
            htmlParts.push(`<div class="heatmap-cell intensity-${intensity}" 
                          data-day="${dayName} ${monthName} ${dayNum}" 
                          data-hour="${hour}" 
                          data-count="${coverage.count}" 
                          data-total="${coverage.total}"
                          data-percentage="${percentage}"
                          data-employees="${employeeList}"
                          data-levels="${levelBreakdownStr}"
                          onclick="showHeatmapModal(this)">
                        ${coverage.count}
                     </div>`);
        });
    });
    
    htmlParts.push('</div>');
    
    
    // Update DOM immediately (no animation frame needed since we're already in one from showTab)
    container.innerHTML = htmlParts.join('');
    
}

// New function to calculate coverage for a specific date
function calculateCoverageForDate(employees, targetDate, hour) {
    let count = 0;
    let total = 0;
    let workingEmployees = [];
    
    const year = targetDate.getFullYear();
    const month = targetDate.getMonth(); // 0-indexed
    const day = targetDate.getDate();
    const dayOfWeek = targetDate.getDay();
    
    employees.forEach(employee => {
        if (!employee.schedule || employee.schedule[dayOfWeek] !== 1) {
            return;
        }
        
        // Check for PTO, sick, or off overrides for this specific date
        const overrideKey = `${employee.id}-${year}-${month}-${day}`;
        let employeeSchedule = '';
        let isOverride = false;
        let isWorking = false;
        
        if (heatmapData && heatmapData.overrides && heatmapData.overrides[overrideKey]) {
            const override = heatmapData.overrides[overrideKey];
            // Skip employees with PTO, sick, off, or holiday status
            if (['pto', 'sick', 'off', 'holiday'].includes(override.status)) {
                return; // Don't count this employee at all
            }
            
            // Handle custom_hours override
            if (override.status === 'custom_hours' && override.customHours) {
                total++;
                employeeSchedule = override.customHours;
                isOverride = true;
                const parsedHours = parseHoursString(override.customHours);
                if (parsedHours) {
                    const { start, end } = parsedHours;
                    if (isHourInRange(hour, start, end)) {
                        isWorking = true;
                    }
                }
                
                if (isWorking) {
                    count++;
                    workingEmployees.push({
                        name: employee.name,
                        schedule: employeeSchedule,
                        isOverride: isOverride,
                        level: employee.level || '',
                        skills: employee.skills || {},
                        team: employee.team || ''
                    });
                }
                return; // Done processing this employee
            }
            
            // If override status is 'on', treat as normal scheduled day (continue below)
        }
        
        // Regular schedule processing
        total++;
        
        // Get employee's normal schedule
        if (employee.hours && employee.hours.trim() !== '') {
            employeeSchedule = employee.hours;
        } else if (employee.shift) {
            const shiftNum = parseInt(employee.shift);
            employeeSchedule = getShiftName(shiftNum);
        }
        
        // Check if working at this hour
        const parsedHours = parseHoursString(employee.hours);
        if (parsedHours) {
            const { start, end } = parsedHours;
            if (isHourInRange(hour, start, end)) {
                isWorking = true;
            }
        } else {
            const shiftNum = parseInt(employee.shift);
            if (isHourInShift(hour, shiftNum)) {
                isWorking = true;
            }
        }
        
        if (isWorking) {
            count++;
            workingEmployees.push({
                name: employee.name,
                schedule: employeeSchedule,
                isOverride: isOverride,
                level: employee.level || '',
                skills: employee.skills || {},
                team: employee.team || ''
            });
        }
    });
    
    // Calculate level breakdown
    const levelBreakdown = {};
    workingEmployees.forEach(emp => {
        const level = emp.level || 'No Level';
        levelBreakdown[level] = (levelBreakdown[level] || 0) + 1;
    });
    
    return { count, total, employees: workingEmployees, levelBreakdown };
}

function parseHoursString(hoursStr) {
    if (!hoursStr || typeof hoursStr !== 'string') {
        return null;
    }
    
    // Try 24-hour format with colons first: "11:00 - 17:00"
    let match = hoursStr.match(/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/);
    if (match) {
        const startHour = parseInt(match[1]);
        const endHour = parseInt(match[3]);
        return { start: startHour, end: endHour };
    }
    
    // Try 12-hour format with AM/PM: "11PM - 8AM" or "11pm - 8am"
    match = hoursStr.match(/(\d{1,2})\s*(AM|PM|am|pm)\s*-\s*(\d{1,2})\s*(AM|PM|am|pm)/i);
    if (match) {
        let startHour = parseInt(match[1]);
        const startPeriod = match[2].toUpperCase();
        let endHour = parseInt(match[3]);
        const endPeriod = match[4].toUpperCase();
        
        // Convert to 24-hour format
        if (startPeriod === 'PM' && startHour !== 12) {
            startHour += 12;
        } else if (startPeriod === 'AM' && startHour === 12) {
            startHour = 0;
        }
        
        if (endPeriod === 'PM' && endHour !== 12) {
            endHour += 12;
        } else if (endPeriod === 'AM' && endHour === 12) {
            endHour = 0;
        }
        
        return { start: startHour, end: endHour };
    }
    
    return null;
}

function isHourInRange(hour, start, end) {
    if (start < end) {
        return hour >= start && hour < end;
    } else {
        return hour >= start || hour < end;
    }
}

function isHourInShift(hour, shiftNum) {
    switch(shiftNum) {
        case 1: return hour >= 7 && hour < 15;
        case 2: return hour >= 15 && hour < 23;
        case 3: return hour >= 23 || hour < 7;
        default: return false;
    }
}

function calculateIntensity(count, total) {
    if (total === 0) return 0;
    const percentage = (count / total) * 100;
    
    if (percentage === 0) return 0;
    if (percentage <= 12.5) return 1;
    if (percentage <= 25) return 2;
    if (percentage <= 37.5) return 3;
    if (percentage <= 50) return 4;
    if (percentage <= 62.5) return 5;
    if (percentage <= 75) return 6;
    if (percentage <= 87.5) return 7;
    return 8;
}

let heatmapModal = null;

function showHeatmapModal(element) {
    // Create modal if it doesn't exist
    if (!heatmapModal) {
        const overlay = document.createElement('div');
        overlay.className = 'heatmap-modal-overlay';
        overlay.id = 'heatmapModalOverlay';
        overlay.innerHTML = `
            <div class="heatmap-modal">
                <div class="heatmap-modal-header" id="modalHeader"></div>
                <div class="heatmap-modal-body" id="modalBody"></div>
                <div class="heatmap-modal-footer">
                    <button class="heatmap-modal-btn" onclick="hideHeatmapModal()">OK</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);
        heatmapModal = overlay;
        
        // Close on overlay click
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                hideHeatmapModal();
            }
        });
        
        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && heatmapModal.classList.contains('show')) {
                hideHeatmapModal();
            }
        });
    }
    
    const day = element.dataset.day;
    const hour = element.dataset.hour;
    const count = element.dataset.count;
    const total = element.dataset.total;
    const percentage = element.dataset.percentage;
    const employeesData = element.dataset.employees;
    const levelsData = element.dataset.levels;
    
    const displayHour = hour == 0 ? '12AM' : hour < 12 ? hour + 'AM' : hour == 12 ? '12PM' : (hour-12) + 'PM';
    
    // Get day index for schedule lookup
    const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const dayIndex = days.indexOf(day);
    
    // Update header
    document.getElementById('modalHeader').innerHTML = `${day} at ${displayHour}`;
    
    // Parse level breakdown
    let levelBreakdownHtml = '';
    if (levelsData && levelsData.length > 0) {
        const levelEntries = levelsData.split('|||').filter(entry => entry.length > 0);
        if (levelEntries.length > 0) {
            const levels = {};
            levelEntries.forEach(entry => {
                const [level, count] = entry.split(':');
                levels[level] = parseInt(count) || 0;
            });
            
            // Sort levels for consistent display
            const sortedLevels = Object.entries(levels).sort((a, b) => {
                // Put "No Level" last
                if (a[0] === 'No Level') return 1;
                if (b[0] === 'No Level') return -1;
                return a[0].localeCompare(b[0]);
            });
            
            const levelItems = sortedLevels.map(([level, count]) => {
                const displayLevel = level === 'No Level' ? 
                    '<span class="level-no-level" style="font-style: italic;">No Level</span>' : 
                    `<strong class="level-item-name" style="word-break: break-word; max-width: 140px;">${level.toUpperCase()}</strong>`;
                return `<div class="level-item" style="padding: 6px 10px; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                    <span style="flex: 1; min-width: 0;">${displayLevel}</span>
                    <span class="level-item-count" style="padding: 3px 10px; border-radius: 12px; font-weight: bold; font-size: 13px; white-space: nowrap; flex-shrink: 0;">${count}</span>
                </div>`;
            }).join('');
            
            levelBreakdownHtml = `
                <div class="heatmap-modal-divider"></div>
                <div style="margin-bottom: 15px;">
                    <h4 class="level-breakdown-title" style="margin: 0 0 10px 0; font-size: 15px;">📊 Coverage by Level</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px;">
                        ${levelItems}
                    </div>
                </div>
            `;
        }
    }
    
    // Build employee list with schedules
    let employeeListHtml = '';
    if (employeesData && employeesData.length > 0) {
        const employeeEntries = employeesData.split('|||').filter(entry => entry.length > 0);
        
        if (employeeEntries.length > 0) {
            // Parse employee data (format: name::schedule::isOverride::level::skills::team)
            const employees = employeeEntries.map(entry => {
                const parts = entry.split('::');
                // Parse skills from comma-separated string
                const skillsStr = parts[4] || '';
                const skillsArray = skillsStr ? skillsStr.split(',') : [];
                return {
                    name: parts[0] || '',
                    schedule: parts[1] || '',
                    isOverride: parts[2] === '1',
                    level: parts[3] || '',
                    skills: skillsArray,
                    team: parts[5] || ''
                };
            });
            
            // Define valid teams (matching homepage filter)
            const validTeams = {
                'esg': 'ESG',
                'support': 'Support',
                'windows': 'Windows',
                'security': 'Security',
                'migrations': 'Migrations',
                'learning_development': 'Learning and Development',
                'Implementations': 'Implementations',
                'Account Services': 'Account Services',
                'Account Services Stellar': 'Account Services Stellar'
            };
            
            // Filter employees to only include those with valid teams
            const employeesWithTeams = employees.filter(emp => emp.team && validTeams[emp.team]);
            
            // Sort by name
            employeesWithTeams.sort((a, b) => a.name.localeCompare(b.name));
            
            const employeeItems = employeesWithTeams.map(emp => {
                const scheduleDisplay = emp.schedule ? 
                    `<span style="font-size: 13px;">${emp.isOverride ? 
                        '<span class="heatmap-override-badge">⚠ ' + emp.schedule + '</span>' : 
                        '<span class="heatmap-regular-badge">' + emp.schedule + '</span>'
                    }</span>` : 
                    '';
                
                const levelDisplay = emp.level ?
                    `<span style="background: #1565c0; color: #fff !important; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: bold; margin-left: 8px;">${emp.level.toUpperCase()}</span>` :
                    '';

                // Create skills badges (solid colors work on both light and dark backgrounds)
                const skillsDisplay = emp.skills && emp.skills.length > 0 ?
                    emp.skills.map(skill => {
                        let bgColor = '#555';
                        if (skill === 'mh') {
                            bgColor = '#1976d2';
                        } else if (skill === 'ma') {
                            bgColor = '#7b1fa2';
                        } else if (skill === 'win') {
                            bgColor = '#2e7d32';
                        }
                        return `<span style="background: ${bgColor}; color: #fff !important; padding: 2px 6px; border-radius: 8px; font-size: 10px; font-weight: bold; margin-left: 4px;">${skill.toUpperCase()}</span>`;
                    }).join('') : '';
                
                return `
                    <div class="heatmap-modal-employee-item">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-weight: 500;">${emp.name}${levelDisplay}${skillsDisplay}</span>
                            ${scheduleDisplay}
                        </div>
                    </div>
                `;
            }).join('');
            
            employeeListHtml = `
                <div class="heatmap-modal-divider"></div>
                <div class="heatmap-modal-employees">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                        <h4 style="margin: 0;">Working Employees (${employeesWithTeams.length})</h4>
                    </div>
                    <div class="heatmap-legend-text">
                        <span class="heatmap-regular-badge">●</span> Regular schedule | 
                        <span class="heatmap-override-badge">⚠</span> Custom hours for this day
                    </div>
                    <div class="heatmap-modal-employee-list">
                        ${employeeItems}
                    </div>
                </div>
            `;
        }
    }
    
    // Update body
    document.getElementById('modalBody').innerHTML = `
        <div class="info-line"><strong>Coverage:</strong> ${count} out of ${total} employees</div>
        <div class="info-line"><strong>Percentage:</strong> ${percentage}%</div>
        ${levelBreakdownHtml}
        ${employeeListHtml}
    `;
    
    // Show modal
    heatmapModal.classList.add('show');
}

function hideHeatmapModal() {
    if (heatmapModal) {
        heatmapModal.classList.remove('show');
    }
}

// Helper function to get team display name
function getTeamDisplayName(team) {
    const teamNames = {
        'esg': 'ESG',
        'support': 'Support',
        'windows': 'Windows',
        'security': 'Security',
        'migrations': 'Migrations',
        'learning_development': 'Learning and Development',
        'Implementations': 'Implementations',
        'Account Services': 'Account Services',
        'Account Services Stellar': 'Account Services Stellar'
    };
    return teamNames[team] || team;
}

// Function to filter employees by team in heatmap modal

// Helper function to get shift name
function getShiftName(shift) {
    switch(shift) {
        case 1: return '1st Shift (7AM-3PM)';
        case 2: return '2nd Shift (3PM-11PM)';
        case 3: return '3rd Shift (11PM-7AM)';
        default: return 'Shift ' + shift;
    }
}

// ════════════════════════════════════════════════════════════
// SKILLS COVERAGE HEATMAP
// ════════════════════════════════════════════════════════════
let skillsHeatmapData = null;
let skillsHeatmapDatesInitialized = false;

const SKILL_DEFS = [
    { key: 'mh',  label: 'MH',  color: '#1976d2', emoji: '🔵' },
    { key: 'ma',  label: 'MA',  color: '#7b1fa2', emoji: '🟣' },
    { key: 'win', label: 'Win', color: '#2e7d32', emoji: '🟢' }
];

function updateSkillsHeatmapData() {
    const teamFilter       = document.getElementById('skHeatmapTeamFilter').value;
    const dateFrom         = document.getElementById('skHeatmapDateFrom').value;
    const dateTo           = document.getElementById('skHeatmapDateTo').value;
    const timeFromFilter   = document.getElementById('skHeatmapTimeFrom').value;
    const timeToFilter     = document.getElementById('skHeatmapTimeTo').value;
    const levelFilter      = document.getElementById('skHeatmapLevelFilter').value;
    const supervisorFilter = document.getElementById('skHeatmapSupervisorFilter').value;
    const dayFilter        = document.getElementById('skHeatmapDayFilter').value;
    const shiftFilter      = document.getElementById('skHeatmapShiftFilter').value;
    const skillFilter      = document.getElementById('skHeatmapSkillFilter').value;

    // Apply same base filters as the coverage heatmap
    const baseFiltered = heatmapEmployees.filter(emp => {
        if (shiftFilter !== 'all' && parseInt(emp.shift) !== parseInt(shiftFilter)) return false;
        if (shiftFilter !== '4' && emp.shift == 4) return false;

        if (teamFilter !== 'all' && emp.team !== teamFilter) return false;

        if (timeFromFilter !== 'all' && timeToFilter !== 'all') {
            const fromHour = parseInt(timeFromFilter);
            const toHour   = parseInt(timeToFilter);
            const parsed   = parseHoursString(emp.hours);
            if (parsed) {
                if (!hoursOverlap(parsed.start, parsed.end, fromHour, toHour)) return false;
            } else {
                const sn = parseInt(emp.shift);
                const ranges = { 1:[7,15], 2:[15,23], 3:[23,7] };
                if (!ranges[sn]) return false;
                if (!hoursOverlap(ranges[sn][0], ranges[sn][1], fromHour, toHour)) return false;
            }
        }

        if (levelFilter !== 'all' && emp.level !== levelFilter && !(emp.level === undefined && levelFilter === '')) return false;

        if (supervisorFilter !== 'all') {
            if (supervisorFilter === 'none' && emp.supervisor_id) return false;
            if (supervisorFilter !== 'none' && emp.supervisor_id != supervisorFilter) return false;
        }

        return true;
    });

    // Skill-specific subset (used when a single skill is selected)
    const skillFiltered = skillFilter === 'all'
        ? baseFiltered.filter(emp => emp.skills && (emp.skills.mh || emp.skills.ma || emp.skills.win))
        : baseFiltered.filter(emp => emp.skills && emp.skills[skillFilter]);

    skillsHeatmapData = {
        allEmployees:   baseFiltered,   // full filtered set (for "all" multi-row view)
        employees:      skillFiltered,  // skill-specific set
        overrides:      heatmapOverrides,
        dayFilter,
        dateFrom,
        dateTo,
        skillFilter,
        mhCount:  baseFiltered.filter(e => e.skills && e.skills.mh).length,
        maCount:  baseFiltered.filter(e => e.skills && e.skills.ma).length,
        winCount: baseFiltered.filter(e => e.skills && e.skills.win).length,
    };

    renderSkillsHeatmapStats();
    renderSkillsHeatmapGrid();
}

function renderSkillsHeatmapStats() {
    if (!skillsHeatmapData) return;
    const container = document.getElementById('skHeatmapStatsContainer');
    if (!container) return;

    const sf = skillsHeatmapData.skillFilter;
    const label = sf === 'all' ? 'Skilled Employees'
                : sf === 'mh'  ? 'MH Employees'
                : sf === 'ma'  ? 'MA Employees'
                               : 'Win Employees';

    container.innerHTML = `
        <div class="stat-card">
            <div class="stat-value">${skillsHeatmapData.employees.length}</div>
            <div class="stat-label">${label}</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">${new Set(skillsHeatmapData.employees.map(e=>e.team)).size}</div>
            <div class="stat-label">Teams Represented</div>
        </div>
        <div class="stat-card" style="gap:8px;">
            <div class="stat-label" style="margin-bottom:4px;">Skill Totals (filtered)</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center;">
                <span style="background:#1976d2;color:#fff !important;padding:3px 10px;border-radius:12px;font-size:13px;font-weight:bold;">MH: ${skillsHeatmapData.mhCount}</span>
                <span style="background:#7b1fa2;color:#fff !important;padding:3px 10px;border-radius:12px;font-size:13px;font-weight:bold;">MA: ${skillsHeatmapData.maCount}</span>
                <span style="background:#2e7d32;color:#fff !important;padding:3px 10px;border-radius:12px;font-size:13px;font-weight:bold;">Win: ${skillsHeatmapData.winCount}</span>
            </div>
        </div>`;
}

function renderSkillsHeatmapGrid() {
    if (!skillsHeatmapData) return;
    const container = document.getElementById('skHeatmapGridContainer');
    if (!container) return;

    const hours       = Array.from({length: 24}, (_, i) => i);
    const skillFilter = skillsHeatmapData.skillFilter;
    const days        = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const months      = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    // Build date range (identical logic to renderHeatmapGrid)
    let dates = [];
    if (skillsHeatmapData.dateFrom && skillsHeatmapData.dateTo) {
        const s = new Date(skillsHeatmapData.dateFrom + 'T00:00:00');
        const e = new Date(skillsHeatmapData.dateTo   + 'T00:00:00');
        let cur = new Date(s);
        while (cur <= e) { dates.push(new Date(cur)); cur.setDate(cur.getDate()+1); }
    } else {
        const today = new Date();
        const dow   = today.getDay();
        const sow   = new Date(today); sow.setDate(today.getDate() - dow);
        for (let i = 0; i < 7; i++) {
            const d = new Date(sow); d.setDate(sow.getDate() + i); dates.push(d);
        }
    }
    if (skillsHeatmapData.dayFilter !== 'all') {
        const di = parseInt(skillsHeatmapData.dayFilter);
        dates = dates.filter(d => d.getDay() === di);
    }

    // Helper: build cells for a given employee set using the shared calculation.
    // We temporarily redirect heatmapData.overrides so calculateCoverageForDate works.
    const calcCoverage = (employees, date, hour) => {
        const saved = heatmapData;
        heatmapData = { overrides: skillsHeatmapData.overrides };
        const result = calculateCoverageForDate(employees, date, hour);
        heatmapData = saved;
        return result;
    };

    const buildCell = (coverage, dayLabel, hour, skillLabel) => {
        const intensity  = calculateIntensity(coverage.count, coverage.total);
        const pct        = coverage.total > 0 ? ((coverage.count/coverage.total)*100).toFixed(0) : 0;
        let empList = '', levelStr = '';
        try {
            empList = (coverage.employees||[]).map(emp => {
                const sk = emp.skills ? Object.keys(emp.skills).filter(s=>emp.skills[s]).join(',') : '';
                return `${emp.name}::${emp.schedule||''}::${emp.isOverride?'1':'0'}::${emp.level||''}::${sk}::${emp.team||''}`;
            }).join('|||');
            levelStr = Object.entries(coverage.levelBreakdown||{}).map(([l,c])=>`${l}:${c}`).join('|||');
        } catch(e) {}
        const skillAttr = skillLabel ? ` data-skill="${skillLabel}"` : '';
        return `<div class="heatmap-cell intensity-${intensity}"
            data-day="${dayLabel}"
            data-hour="${hour}"
            data-count="${coverage.count}"
            data-total="${coverage.total}"
            data-percentage="${pct}"
            data-employees="${empList}"
            data-levels="${levelStr}"${skillAttr}
            onclick="showHeatmapModal(this)">${coverage.count}</div>`;
    };

    const htmlParts = ['<div class="heatmap-grid">'];

    // Header row
    htmlParts.push('<div class="heatmap-header"></div>');
    hours.forEach(h => {
        const lbl = h===0?'12AM': h<12?h+'AM': h===12?'12PM':(h-12)+'PM';
        htmlParts.push(`<div class="heatmap-header">${lbl}</div>`);
    });

    dates.forEach(date => {
        const dw   = date.getDay();
        const dn   = days[dw];
        const mn   = months[date.getMonth()];
        const day  = date.getDate();

        if (skillFilter === 'all') {
            // 3 sub-rows per date — one per skill
            SKILL_DEFS.forEach(skill => {
                const skillEmps = skillsHeatmapData.allEmployees.filter(
                    emp => emp.skills && emp.skills[skill.key]
                );
                const rowLabel = `<div class="heatmap-row-label" style="display:flex;align-items:center;gap:5px;font-size:11px;flex-wrap:wrap;">
                    <span>${mn} ${day}</span>
                    <span style="background:${skill.color};color:#fff !important;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:bold;white-space:nowrap;">${skill.label}</span>
                </div>`;
                htmlParts.push(rowLabel);
                hours.forEach(h => {
                    const cov = calcCoverage(skillEmps, date, h);
                    htmlParts.push(buildCell(cov, `${mn} ${day} (${skill.label})`, h, skill.label));
                });
            });
        } else {
            // Single skill — one row per date
            htmlParts.push(`<div class="heatmap-row-label">${mn} ${day}</div>`);
            hours.forEach(h => {
                const cov = calcCoverage(skillsHeatmapData.employees, date, h);
                htmlParts.push(buildCell(cov, `${mn} ${day}`, h, null));
            });
        }
    });

    htmlParts.push('</div>');
    container.innerHTML = htmlParts.join('');
}

function refreshSkillsHeatmap() {
    const stats = document.getElementById('skHeatmapStatsContainer');
    const grid  = document.getElementById('skHeatmapGridContainer');
    if (stats) stats.innerHTML = '<div style="text-align:center;padding:20px;color:#666;">🔄 Refreshing skills heatmap…</div>';
    if (grid)  grid.innerHTML  = '<div class="loading">Reloading skills heatmap data…</div>';
    updateSkillsHeatmapData();
}

function clearSkillsHeatmapFilters() {
    ['skHeatmapDateFrom','skHeatmapDateTo'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
    });
    ['skHeatmapSkillFilter','skHeatmapTeamFilter','skHeatmapTimeFrom','skHeatmapTimeTo',
     'skHeatmapLevelFilter','skHeatmapSupervisorFilter','skHeatmapDayFilter','skHeatmapShiftFilter'
    ].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = 'all';
    });
    // Reset time defaults to match original heatmap
    const tf = document.getElementById('skHeatmapTimeFrom'); if (tf) tf.value = '12';
    updateSkillsHeatmapData();
}

function initializeSkillsHeatmap() {
    if (!skillsHeatmapDatesInitialized) {
        // Mirror the date range from the coverage heatmap if already set, else use current week
        const covFrom = document.getElementById('heatmapDateFrom');
        const covTo   = document.getElementById('heatmapDateTo');
        const skFrom  = document.getElementById('skHeatmapDateFrom');
        const skTo    = document.getElementById('skHeatmapDateTo');
        if (covFrom && covTo && skFrom && skTo && covFrom.value) {
            skFrom.value = covFrom.value;
            skTo.value   = covTo.value;
        } else {
            // Default to current week
            const today = new Date();
            const dow   = today.getDay();
            const sow   = new Date(today); sow.setDate(today.getDate() - dow);
            const eow   = new Date(sow);   eow.setDate(sow.getDate() + 6);
            const fmt   = d => d.toISOString().split('T')[0];
            if (skFrom) skFrom.value = fmt(sow);
            if (skTo)   skTo.value   = fmt(eow);
        }
        skillsHeatmapDatesInitialized = true;
    }
    updateSkillsHeatmapData();
}
// ════════════════════════════════════════════════════════════
// END SKILLS COVERAGE HEATMAP
// ════════════════════════════════════════════════════════════

// Track whether heatmap was preloaded in the background
let heatmapPreloaded = false;

// Initialize heatmap when tab is shown
function initializeHeatmap() {
    // Only set dates ONCE per page load (first time heatmap tab is shown)
    if (!heatmapDatesInitialized) {
        initializeDateRange();
        heatmapDatesInitialized = true;
    }

    heatmapPreloaded = false;
    window._heatmapInitialized = true;

    updateHeatmapData();
}

// User Timezone Clock Update Function
let clockInitialized = false;

function updateUserClock() {
    const timeElement = document.getElementById('userTime');
    const timezoneElement = document.getElementById('userTimezone');
    const dateElement = document.getElementById('clockDate');
    
    if (!timeElement) return;
    
    try {
        const now = new Date();
        
        // Format time consistently with 2-digit hour (e.g., "02:45:30 PM")
        const timeString = now.toLocaleTimeString('en-US', { 
            hour: '2-digit',
            minute: '2-digit', 
            second: '2-digit',
            hour12: true 
        });
        
        // Update time every second
        timeElement.textContent = timeString;
        
        // Only update timezone and date on first load (to avoid flashing)
        if (!clockInitialized) {
            // Get timezone (e.g., "EST", "PST")
            const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            const shortTimezone = now.toLocaleTimeString('en-US', { timeZoneName: 'short' }).split(' ')[2];
            
            // Format date (e.g., "Wednesday, Nov 27, 2024")
            const dateString = now.toLocaleDateString('en-US', { 
                weekday: 'long', 
                month: 'short', 
                day: 'numeric',
                year: 'numeric'
            });
            
            if (timezoneElement) {
                timezoneElement.textContent = timezone + (shortTimezone ? ` (${shortTimezone})` : '');
            }
            if (dateElement) {
                dateElement.textContent = dateString;
            }
            
            clockInitialized = true;
        }
    } catch (error) {
        timeElement.textContent = new Date().toLocaleTimeString();
    }
}

// Initialize clock immediately
updateUserClock();

// Update clock every second
setInterval(updateUserClock, 1000);
</script>

<script>
// ── Working-Today tooltip: JS-managed show/hide ───────────────────────────────
// Problem: pure CSS :hover drops the moment the mouse crosses the ~15px gap
// between the trigger card and the tooltip panel, and transition-delay can't
// reliably bridge it.  Solution: JS keeps the panel open for 15 seconds after
// the mouse leaves so users have plenty of time to move to it and copy names.
//
// Inline styles set here override ALL CSS selectors (inline > class > tag),
// so the panel stays visible even when :hover is false.
(function () {
    var HIDE_DELAY_MS = 15000; // 15 seconds after last mouse-leave
    var openTooltips = []; // track all open {trigger, tooltip, hidePanel} so we can close globally

    function hidePanel(tooltip, hideTimer) {
        if (hideTimer) clearTimeout(hideTimer);
        tooltip.style.opacity       = '0';
        tooltip.style.transitionDelay = '0s';
        setTimeout(function () {
            tooltip.style.visibility    = 'hidden';
            tooltip.style.pointerEvents = 'none';
        }, 200);
        return null; // caller assigns return to hideTimer
    }

    function initTooltipTrigger(trigger) {
        var tooltip = trigger.querySelector('.tooltip');
        if (!tooltip || trigger._ttInit) return;
        trigger._ttInit = true;

        var hideTimer = null;
        var isOpen    = false;

        function showPanel() {
            if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
            tooltip.style.visibility    = 'visible';
            tooltip.style.opacity       = '1';
            tooltip.style.pointerEvents = 'auto';
            tooltip.style.transitionDelay = '0s';
            // Force white text every time it opens — beats any theme !important CSS
            tooltip.style.setProperty('color', '#ffffff', 'important');
            tooltip.style.setProperty('background', '#1e2a35', 'important');
            tooltip.querySelectorAll('*').forEach(function(el) {
                el.style.setProperty('color', '#ffffff', 'important');
            });
            isOpen = true;
        }

        function closePanelNow() {
            hideTimer = hidePanel(tooltip, hideTimer);
            isOpen = false;
        }

        function scheduleHide() {
            if (hideTimer) clearTimeout(hideTimer);
            hideTimer = setTimeout(function () {
                closePanelNow();
            }, HIDE_DELAY_MS);
        }

        trigger.addEventListener('mouseenter', showPanel);
        trigger.addEventListener('mouseleave', scheduleHide);

        // Mouse can move into the panel without it closing
        tooltip.addEventListener('mouseenter', showPanel);
        tooltip.addEventListener('mouseleave', scheduleHide);

        // Close button (×) inside tooltip header
        var closeBtn = tooltip.querySelector('.tooltip-close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                closePanelNow();
            });
        }

        // Store reference so click-outside and ESC can close this
        openTooltips.push({ trigger: trigger, tooltip: tooltip, close: closePanelNow, isOpen: function () { return isOpen; } });
    }

    // Close any open tooltip when clicking outside it
    document.addEventListener('click', function (e) {
        openTooltips.forEach(function (item) {
            if (!item.isOpen()) return;
            if (!item.trigger.contains(e.target) && !item.tooltip.contains(e.target)) {
                item.close();
            }
        });
    });

    // ESC key closes any open tooltip
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            openTooltips.forEach(function (item) { if (item.isOpen()) item.close(); });
        }
    });

    function initAllTooltips() {
        document.querySelectorAll('.has-tooltip').forEach(initTooltipTrigger);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAllTooltips);
    } else {
        initAllTooltips();
    }
}());
</script>

<!-- COMPLETE THEME SYSTEM -->
<script>
class ThemeSelector {
    constructor() {
        this.currentTheme = 'default';
        this.themeStylesheet = null;
        this.isOpen = false;
        
this.themes = {
    default: { name: 'Default Theme', css: this.generateThemeCSS('default') },
    ocean: { name: '🌊 Ocean Blue', css: this.generateThemeCSS('ocean') },
    forest: { name: '🌲 Forest Green', css: this.generateThemeCSS('forest') },
    sunset: { name: '🌅 Sunset Orange', css: this.generateThemeCSS('sunset') },
    royal: { name: '👑 Royal Purple', css: this.generateThemeCSS('royal') },
    dark: { name: '🌙 Modern Dark', css: this.generateThemeCSS('dark') },
    crimson: { name: '🔴 Crimson Red', css: this.generateThemeCSS('crimson') },
    teal: { name: '🧊 Teal Cyan', css: this.generateThemeCSS('teal') },
    amber: { name: '🟡 Amber Gold', css: this.generateThemeCSS('amber') },
    slate: { name: '⚪ Slate Gray', css: this.generateThemeCSS('slate') },
    emerald: { name: '🟢 Emerald Green', css: this.generateThemeCSS('emerald') },
    midnight: { name: '🌙 Midnight Blue', css: this.generateThemeCSS('midnight') },
    rose: { name: '🌹 Rose Pink', css: this.generateThemeCSS('rose') },
    copper: { name: '🟤 Copper Bronze', css: this.generateThemeCSS('copper') }
};
        
        this.init();
    }
    
    init() {
        this.loadSavedTheme();
        this.updateUI();
    }
    
    applyTheme(themeName, skipDelay = false) {
        const doApply = () => {
            if (this.themeStylesheet) {
                this.themeStylesheet.remove();
                this.themeStylesheet = null;
            }

            // Remove preload theme (always, even when switching to default)
            const preloadTheme = document.getElementById('preload-theme');
            if (preloadTheme) preloadTheme.remove();

            if (this.themes[themeName]?.css) {
                this.themeStylesheet = document.createElement('style');
                this.themeStylesheet.id = 'dynamic-theme-stylesheet';
                this.themeStylesheet.textContent = this.themes[themeName].css;
                // Append to <head> so it has the same cascade origin as styles.css
                document.head.appendChild(this.themeStylesheet);
            }

            this.currentTheme = themeName;
            this.saveTheme();
            this.updateUI();

            if (!skipDelay) {
                document.body.classList.remove('theme-switching');
            }
        };

        if (skipDelay) {
            // Run synchronously — no setTimeout — so the preload CSS is replaced
            // atomically with the new CSS and the browser never repaints without a theme.
            doApply();
        } else {
            document.body.classList.add('theme-switching');
            setTimeout(doApply, 250);
        }
    }
    
    updateUI() {

        // Refresh heatmap stats if on heatmap tab
        if (document.getElementById('heatmap-tab')?.classList.contains('active')) {
            if (typeof renderHeatmapStats === 'function' && heatmapData) {
                renderHeatmapStats();
            }
        }

        // Force inline-styled elements to pick up new CSS variable values.
        // Browsers re-resolve var() in stylesheets automatically, but elements
        // with hardcoded inline color strings need a nudge.
        requestAnimationFrame(() => {
            const computedPrimary = getComputedStyle(document.documentElement)
                .getPropertyValue('--primary-color').trim();
            const computedText    = getComputedStyle(document.documentElement)
                .getPropertyValue('--text-color').trim();
            const computedMuted   = getComputedStyle(document.documentElement)
                .getPropertyValue('--text-muted').trim();
            const computedCard    = getComputedStyle(document.documentElement)
                .getPropertyValue('--card-bg').trim();
            const computedBorder  = getComputedStyle(document.documentElement)
                .getPropertyValue('--border-color').trim();

            // Swatch buttons (theme picker) — always white regardless of active theme
            document.querySelectorAll('.theme-swatch-btn').forEach(btn => {
                btn.style.background   = '#ffffff';
                btn.style.borderColor  = '#dee2e6';
                btn.style.color        = '#333333';
            });

            // Primary action buttons — always white text
            document.querySelectorAll('.sr-btn-primary, button[style*="--primary-color"]').forEach(btn => {
                btn.style.color = '#fff';
            });

            // Expand/Collapse List toggle button
            const toggleBtn = document.getElementById('toggleUsersBtn');
            if (toggleBtn) {
                toggleBtn.style.color = '#fff';
                toggleBtn.querySelectorAll('span').forEach(s => s.style.color = '#fff');
            }

            // Filter banner and search results — always white
            ['filterBanner', 'searchResults'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.style.color = '#fff';
            });

            // Schedule tab accent bars — keep in sync with primary color
            const scheduleTab = document.getElementById('schedule-tab');
            if (scheduleTab) {
                scheduleTab.style.borderLeftColor   = computedPrimary;
                scheduleTab.style.borderBottomColor = computedPrimary;
            }
            // schedule-wrapper border replaced by the accent bar div — no border to update

            // Working Today widget — white card, dark text; accent bar takes the primary colour
            const statWidget = document.querySelector('.stat-item.has-tooltip');
            if (statWidget) {
                statWidget.style.background = '#ffffff';
                statWidget.style.color = computedText;
                statWidget.querySelectorAll('.stat-number').forEach(el => {
                    el.style.color = computedPrimary;
                });
                statWidget.querySelectorAll('.stat-label').forEach(el => {
                    el.style.color = computedText;
                });
                // Hint text
                statWidget.querySelectorAll('div:not(.tooltip):not(.tooltip *)').forEach(el => {
                    if (!el.classList.contains('stat-number') && !el.classList.contains('stat-label')) {
                        el.style.color = computedMuted || computedText;
                    }
                });
            }
            // Accent bar always matches the primary colour
            const accentBar = document.querySelector('.working-today-accent-bar');
            if (accentBar) {
                accentBar.style.background = computedPrimary;
            }
            // Tooltip popout — deep navy background, always white text (set via CSS in styles.css).
            // Only force the text colour here; background stays #1a2e4a via CSS so it is
            // always readable regardless of which primary colour the active theme uses.
            document.querySelectorAll('.stat-item .tooltip').forEach(tip => {
                tip.style.setProperty('color', '#ffffff', 'important');
                tip.style.setProperty('background', '#1e2a35', 'important');
                tip.querySelectorAll('*').forEach(el => {
                    el.style.setProperty('color', '#ffffff', 'important');
                });
            });
        });
    }
    
    saveTheme() {
        try {
            localStorage.setItem('scheduleSystemTheme', this.currentTheme);
        } catch (e) {
        }
    }
    
    loadSavedTheme() {
        try {
            const saved = localStorage.getItem('scheduleSystemTheme');
            if (saved && this.themes[saved]) {
                this.applyTheme(saved, true); // Skip delay on initial load
            }
        } catch (e) {
        }
    }
    
    generateThemeCSS(themeName) {
const themes = {
    default: {
        primary:   '#333399',  // main header / nav accents
        tableHead: '#1e40af',  // schedule table headers — matches styles.css baseline
        secondary: '#1e2a6e',
        accent:    '#0891b2',
        success:   '#059669',
        warning:   '#f59e0b',
        danger:    '#dc2626',
        surface:   '#000000',
        card:      '#ffffff',
        text:      '#1e293b',
        textMuted: '#64748b',
        border:    '#cbd5e1'
    },
    ocean: {
        primary: '#0066ff',
        secondary: '#003366', 
        accent: '#0ea5e9',
        success: '#059669',
        warning: '#f59e0b',
        danger: '#dc2626',
        surface: '#000000',
        card: '#ffffff',
        text: '#003366',
        textMuted: '#000000',
        border: '#cbd5e1'
    },
    forest: {
        primary: '#065f46',
        secondary: '#059669',
        accent: '#10b981',
        success: '#059669',
        warning: '#d97706',
        danger: '#dc2626',
        surface: '#000000',
        card: '#ffffff',
        text: '#1e293b',
        textMuted: '#1e293b',
        border: '#a7f3d0'
    },
    sunset: {
        primary: '#c2410c',
        secondary: '#f97316',
        accent: '#fb923c',
        success: '#059669',
        warning: '#996600', 
        danger: '#dc2626',
        surface: '#000000',
        card: '#ffffff',
        text: '#1e293b',
        textMuted: '#64748b',
        border: '#fed7aa'
    },
    royal: {
        primary: '#6600ff',
        secondary: '#4c0099',
        accent: '#c084fc',
        success: '#059669',
        warning: '#f59e0b',
        danger: '#dc2626',
        surface: '#000000',
        card: '#ffffff',
        text: '#1e293b',
        textMuted: '#64748b',
        border: '#e9d5ff'
    },
    dark: {
        primary: '#58a6ff',
        secondary: '#21262d',
        accent: '#79c0ff',
        success: '#3fb950',
        warning: '#e3b341',
        danger: '#f85149',
        surface: '#0d1117',
        card: '#161b22',
        text: '#e6edf3',
        textMuted: '#8b949e',
        border: '#30363d'
    },
    crimson: {
        primary: '#991b1b',
        secondary: '#dc2626',
        accent: '#ef4444',
        success: '#059669',
        warning: '#f59e0b',
        danger: '#dc2626',
        surface: '#000000',
        card: '#ffffff',
        text: '#1e293b',
        textMuted: '#64748b',
        border: '#ffffff'
    },
    teal: {
        primary: '#134e4a',
        secondary: '#0f766e',
        accent: '#14b8a6',
        success: '#059669',
        warning: '#f59e0b',
        danger: '#dc2626',
        surface: '#000000',
        card: '#ffffff',
        text: '#1e293b',
        textMuted: '#64748b',
        border: '#ffffff'
    },
    amber: {
        primary: '#92400e',
        secondary: '#d97706',
        accent: '#f59e0b',
        success: '#059669',
        warning: '#d97706',
        danger: '#dc2626',
        surface: '#000000',
        card: '#ffffff',
        text: '#1e293b',
        textMuted: '#64748b',
        border: '#ffffff'
    },
    slate: {
        primary: '#1e293b',
        secondary: '#475569',
        accent: '#64748b',
        success: '#059669',
        warning: '#f59e0b',
        danger: '#dc2626',
        surface: '#000000',
        card: '#ffffff',
        text: '#1e293b',
        textMuted: '#64748b',
        border: '#ffffff'
    },
    emerald: {
        primary: '#065f46',
        secondary: '#059669',
        accent: '#10b981',
        success: '#059669',
        warning: '#f59e0b',
        danger: '#dc2626',
        surface: '#000000',
        card: '#ffffff',
        text: '#1e293b',
        textMuted: '#64748b',
        border: '#ffffff'
    },
    midnight: {
        primary: '#312e81',
        secondary: '#4338ca',
        accent: '#6366f1',
        success: '#059669',
        warning: '#f59e0b',
        danger: '#dc2626',
        surface: '#000000',
        card: '#ffffff',
        text: '#1e293b',
        textMuted: '#64748b',
        border: '#ffffff'
    },
    rose: {
        primary: '#9f1239',
        secondary: '#e11d48',
        accent: '#f43f5e',
        success: '#059669',
        warning: '#f59e0b',
        danger: '#dc2626',
        surface: '#000000',
        card: '#ffffff',
        text: '#1e293b',
        textMuted: '#64748b',
        border: '#ffffff'
    },
    copper: {
        primary: '#9a3412',
        secondary: '#ea580c',
        accent: '#fb923c',
        success: '#059669',
        warning: '#f59e0b',
        danger: '#dc2626',
        surface: '#000000',
        card: '#ffffff',
        text: '#1e293b',
        textMuted: '#64748b',
        border: '#ffffff'
    }
};
    
    const colors = themes[themeName];
    if (!colors) return '';
    
    const isDark = themeName === 'dark';
    const contentBg    = isDark ? colors.surface : '#f0f2f5';
    const cardBg       = colors.card;
    const textColor    = colors.text;
    const mutedColor   = colors.textMuted;
    const borderColor  = colors.border;
    // tableHeadBg: separate from primary so Default Theme can match styles.css (#1e40af)
    const tableHeadBg  = colors.tableHead || colors.primary;

    let css = `
        /* ── CSS Custom Properties (makes var() work everywhere) ── */
        :root {
            --primary-color:   ${colors.primary};
            --secondary-color: ${colors.secondary};
            --accent-color:    ${colors.accent};
            --success-color:   ${colors.success};
            --warning-color:   ${colors.warning};
            --danger-color:    ${colors.danger};
            --body-bg:         ${contentBg};
            --card-bg:         ${cardBg};
            --text-color:      ${textColor};
            --text-muted:      ${mutedColor};
            --border-color:    ${borderColor};
            --secondary-bg:    ${isDark ? colors.secondary : '#f8f9fa'};
            --surface-color:   ${colors.surface};
            --search-bg:       ${cardBg};
            --input-bg:        ${cardBg};
        }

        /* ── Base Layout ── */
        body {
            background: ${colors.surface} !important;
            color: ${textColor} !important;
        }

        .app-main {
            background: ${contentBg} !important;
            color: ${textColor} !important;
        }

        /* Every tab page */
        .tab-content {
            background: ${contentBg} !important;
            color: ${textColor} !important;
        }

        /* ── Generic text inside content areas ── */
        .tab-content h1, .tab-content h2, .tab-content h3,
        .tab-content h4, .tab-content h5, .tab-content h6,
        .tab-content p, .tab-content span:not(.sb-icon):not(button span):not(.sr-btn span):not(#searchResults):not(#toggleUsersText):not(#toggleUsersIcon):not(#editEmployeeHeaderName):not(#editEmployeeHeaderTeam):not(#editEmployeeHeaderShift):not(#editEmployeeHeaderId):not(.key):not(.str):not(.num):not(.bool):not(.cmnt):not(.api-copy-btn),
        .tab-content label, .tab-content td, .tab-content th,
        .tab-content li, .tab-content small {
            color: ${textColor} !important;
        }

        /* ── Buttons always keep white text regardless of theme ── */
        /* Cover every button variant across the entire app */
        button,
        button span, button *, button i,
        input[type="submit"], input[type="button"], input[type="reset"],
        .btn, .btn span, .btn *, .btn i,
        .btn-primary, .btn-primary span, .btn-primary *,
        .btn-secondary, .btn-secondary span, .btn-secondary *,
        .btn-green, .btn-green span, .btn-green *,
        .btn-orange, .btn-orange span, .btn-orange *,
        .btn-purple, .btn-purple span, .btn-purple *,
        .btn-red, .btn-red span, .btn-red *,
        .sr-btn-primary, .sr-btn-primary span, .sr-btn-primary *,
        .tab-content button.sr-btn-primary,
        .tab-content button.sr-btn-primary span,
        .tab-content button.sr-btn-primary *,
        .tab-content .sr-btn-primary,
        .tab-content .sr-btn-primary span,
        .tab-content .sr-btn-primary *,
        #toggleUsersBtn, #toggleUsersBtn span, #toggleUsersBtn * {
            color: #fff !important;
        }
        /* Select elements should NOT get white text — reset them */
        select { color: ${textColor} !important; }

        /* Muted / helper text */
        .tab-content .text-muted,
        .tab-content small,
        .tab-content [style*="color: #666"],
        .tab-content [style*="color:#666"],
        .tab-content [style*="color: #999"],
        .tab-content [style*="color:#999"],
        .tab-content [style*="color: #555"],
        .tab-content [style*="color:#555"],
        .tab-content [style*="color: rgba(0"] {
            color: ${mutedColor} !important;
        }

        /* Generic white-background containers → use card color */
        .tab-content > div,
        .tab-content .card,
        .tab-content form {
            color: ${textColor} !important;
        }

        /* Inline white/light backgrounds inside tab pages */
        .tab-content [style*="background: white"],
        .tab-content [style*="background:white"],
        .tab-content [style*="background: #fff"],
        .tab-content [style*="background:#fff"],
        .tab-content [style*="background: #f8f9fa"],
        .tab-content [style*="background:#f8f9fa"],
        .tab-content [style*="background: #f5f5f5"],
        .tab-content [style*="background:#f5f5f5"] {
            background: ${cardBg} !important;
            color: ${textColor} !important;
        }

        /* Inline dark text inside tab pages */
        .tab-content [style*="color: #333"],
        .tab-content [style*="color:#333"],
        .tab-content [style*="color: #212"],
        .tab-content [style*="color: #1e"],
        .tab-content [style*="color: #2d"],
        .tab-content [style*="color: #3d"],
        .tab-content [style*="color: #4d"],
        .tab-content [style*="color: #495057"],
        .tab-content [style*="color:#495057"] {
            color: ${textColor} !important;
        }

        /* Section/info boxes */
        .tab-content [style*="background: #fffbeb"],
        .tab-content [style*="background: #e7f3ff"],
        .tab-content [style*="background: #fff3cd"],
        .tab-content [style*="background: #d1ecf1"],
        .tab-content [style*="background: #f8d7da"],
        .tab-content [style*="background: #d4edda"] {
            color: #1a1a1a !important;
        }

        /* Input / textarea / select inside tab pages */
        .tab-content input:not([type="submit"]):not([type="button"]):not([type="checkbox"]):not([type="radio"]),
        .tab-content textarea,
        .tab-content select {
            background: ${cardBg} !important;
            color: ${textColor} !important;
            border-color: ${borderColor} !important;
        }

        .tab-content input:disabled,
        .tab-content input[readonly] {
            background: ${isDark ? colors.secondary : '#e9ecef'} !important;
            color: ${mutedColor} !important;
        }

        /* ── Main Layout ── */
        .header {
            background: ${colors.primary} !important;
            color: ${isDark ? colors.text : 'white'} !important;
        }

        body {
            background: ${colors.surface} !important;
            color: ${textColor} !important;
        }

        /* Search & filter panels */
        .search-controls,
        .team-filter-section {
            background: ${cardBg} !important;
            color: ${textColor} !important;
        }

        .search-controls label,
        .search-controls span,
        .search-controls div,
        .team-filter-section label,
        .team-filter-section span,
        .team-filter-section div {
            color: ${textColor} !important;
        }

        /* Form labels and help text sitewide */
        label { color: ${textColor} !important; }
        small, .help-text, .hint { color: ${mutedColor} !important; }

        /* ── Bulk Schedule employee list — explicit overrides ── */
        .sr {
            background: ${contentBg} !important;
            color: ${textColor} !important;
        }
        .sr-card {
            background: ${cardBg} !important;
            color: ${textColor} !important;
        }
        .sr-card-title, .sr-card-sub,
        .sr-page-title h2, .sr-page-subtitle,
        .sr-section-header h3 {
            color: ${textColor} !important;
        }
        .sr-card-sub, .sr-page-subtitle, .sr-emp-count, .sr-emp-meta {
            color: ${mutedColor} !important;
        }
        .sr-emp-wrap {
            border-color: ${borderColor} !important;
        }
        .sr-emp-top {
            background: ${isDark ? colors.secondary : 'rgba(0,0,0,0.04)'} !important;
            border-bottom-color: ${borderColor} !important;
        }
        .sr-emp-top label, .sr-emp-top span {
            color: ${textColor} !important;
        }
        .sr-emp-search {
            background: ${cardBg} !important;
            border-bottom-color: ${borderColor} !important;
        }
        .sr-emp-list {
            background: ${isDark ? colors.secondary : '#f4f6f8'} !important;
        }
        .sr-emp-item {
            border-bottom-color: ${borderColor} !important;
            background: transparent !important;
        }
        .sr-emp-item:hover {
            background: ${isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.04)'} !important;
        }
        .sr-emp-name {
            color: ${textColor} !important;
        }
        .sr-emp-meta {
            color: ${mutedColor} !important;
        }
        .sr-lbl {
            color: ${textColor} !important;
        }
        .sr-ctrl {
            background: ${cardBg} !important;
            color: ${textColor} !important;
            border-color: ${borderColor} !important;
        }
        .sr-toggle-btn {
            background: ${isDark ? colors.secondary : '#f1f5f9'} !important;
            color: ${textColor} !important;
            border-color: ${borderColor} !important;
        }
        .sr-check-block {
            background: ${cardBg} !important;
            color: ${textColor} !important;
            border-color: ${borderColor} !important;
        }
        .sr-check-block strong, .sr-check-block p, .sr-check-block li {
            color: ${textColor} !important;
        }

        /* ── Settings user table ── */
        .users-table { border-collapse: collapse; width: 100%; }
        .users-table th {
            background: ${colors.primary} !important;
            color: white !important;
            padding: 10px 12px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
        }
        .users-table td {
            padding: 10px 12px;
            border-bottom: 1px solid ${borderColor} !important;
            color: ${textColor} !important;
            background: ${cardBg} !important;
            font-size: 13px;
        }
        .users-table tr:hover td { background: ${isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.02)'} !important; }
        .user-photo-placeholder { background: ${colors.primary} !important; color: white !important; }
        .role-badge { background: ${colors.primary}22 !important; color: ${colors.primary} !important; border: 1px solid ${colors.primary}44 !important; }
        /* Per-role badge colours */
        .role-badge.role-admin     { background: ${colors.danger}22 !important; color: ${colors.danger} !important; border-color: ${colors.danger}44 !important; }
        .role-badge.role-supervisor{ background: ${colors.warning}22 !important; color: ${colors.warning} !important; border-color: ${colors.warning}44 !important; }
        .role-badge.role-manager   { background: ${colors.accent}22 !important; color: ${colors.accent} !important; border-color: ${colors.accent}44 !important; }
        .role-badge.role-employee  { background: ${colors.secondary}22 !important; color: ${colors.secondary} !important; border-color: ${colors.secondary}44 !important; }
        /* Level badge colours — soft tinted pills matching role-badge style */
        .level-badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600; background: ${colors.accent}22 !important; color: ${colors.accent} !important; border: 1px solid ${colors.accent}44 !important; }
        .level-badge.level-ssa  { background: #dc262622 !important; color: #dc2626 !important; border-color: #dc262644 !important; }
        .level-badge.level-ssa2 { background: #9b59b622 !important; color: #9b59b6 !important; border-color: #9b59b644 !important; }
        .level-badge.level-l1   { background: #06b6d422 !important; color: #06b6d4 !important; border-color: #06b6d444 !important; }
        .level-badge.level-l2   { background: #f59e0b22 !important; color: #b45309 !important; border-color: #f59e0b44 !important; }
        .level-badge.level-l3   { background: #0891b222 !important; color: #0891b2 !important; border-color: #0891b244 !important; }
        .level-badge.level-tam  { background: #f9731622 !important; color: #f97316 !important; border-color: #f9731644 !important; }
        .level-badge.level-tam2 { background: #ea580c22 !important; color: #ea580c !important; border-color: #ea580c44 !important; }
        .level-badge.level-manager         { background: #7c3aed22 !important; color: #7c3aed !important; border-color: #7c3aed44 !important; }
        .level-badge.level-Supervisor      { background: #0369a122 !important; color: #0369a1 !important; border-color: #0369a144 !important; }
        .level-badge.level-SR--Supervisor  { background: #0f766e22 !important; color: #0f766e !important; border-color: #0f766e44 !important; }
        .level-badge.level-SR--Manager     { background: #c2410c22 !important; color: #c2410c !important; border-color: #c2410c44 !important; }
        .level-badge.level-IMP-Tech        { background: #1d4ed822 !important; color: #1d4ed8 !important; border-color: #1d4ed844 !important; }
        .level-badge.level-IMP-Coordinator { background: #6d28d922 !important; color: #6d28d9 !important; border-color: #6d28d944 !important; }
        .level-badge.level-SecOps-T1       { background: #1a56db22 !important; color: #1a56db !important; border-color: #1a56db44 !important; }
        .level-badge.level-SecOps-T2       { background: #7e3af222 !important; color: #7e3af2 !important; border-color: #7e3af244 !important; }
        .level-badge.level-SecOps-T3       { background: #e7469422 !important; color: #e74694 !important; border-color: #e7469444 !important; }
        .level-badge.level-SecEng          { background: #d61f6922 !important; color: #d61f69 !important; border-color: #d61f6944 !important; }
        .level-badge.level-technical_writer{ background: #0d948822 !important; color: #0d9488 !important; border-color: #0d948844 !important; }
        .level-badge.level-trainer         { background: #16a34a22 !important; color: #16a34a !important; border-color: #16a34a44 !important; }
        .level-badge.level-tech_coach      { background: #0284c722 !important; color: #0284c7 !important; border-color: #0284c744 !important; }

        /* ── Schedule Export table (override generic th color rule) ── */
        .se-table thead th { background: ${colors.primary} !important; color: #ffffff !important; }
        .status-badge.status-active { background: ${colors.success}22 !important; color: ${colors.success} !important; }
        .status-badge.status-inactive { background: ${colors.danger}22 !important; color: ${colors.danger} !important; }
        .sr-users-bar { background: ${isDark ? colors.secondary : '#f8f9fa'} !important; }
        .sr-user-count { color: ${mutedColor} !important; }
        #searchResults { color: #fff !important; }

        /* Schedule accent bar — themed borders */
        #schedule-tab { border-left-color: ${colors.primary} !important; border-bottom-color: ${colors.primary} !important; }
        .schedule-wrapper { border-top-color: ${colors.primary} !important; }

        .container {
            background: ${colors.card} !important;
            color: ${colors.text} !important;
        }
        /* Main Layout */
.header { 
    background: ${colors.primary} !important; 
    color: white !important;
}

/* Force all header text to be white with shadow */
.header, .header *, .header *:before, .header *:after {
    color: white !important;
    text-shadow: 0 1px 2px rgba(0,0,0,0.5) !important;
}

/* Keep theme dropdown colors intact */
.theme-dropdown, .theme-dropdown * {
    color: #333 !important;
    text-shadow: none !important;
}

        /* Navigation & Tabs */
        .nav-tabs {
            background: ${themeName === 'dark' ? colors.secondary : '#ecf0f1'} !important;
            border-bottom-color: ${colors.border} !important;
        }
        
        .nav-tab {
            color: ${colors.text} !important;
            background: ${themeName === 'dark' ? colors.secondary : 'transparent'} !important;
        }
        
        .nav-tab.active {
            background: ${colors.card} !important;
            border-bottom-color: ${colors.accent} !important;
            color: ${colors.accent} !important;
        }
        
        .nav-tab:hover {
            background: ${themeName === 'dark' ? colors.accent + '33' : '#d5dbdb'} !important;
        }
        
        /* Forms & Controls */
        .controls {
            background: ${themeName === 'dark' ? colors.secondary : '#ecf0f1'} !important;
            color: ${colors.text} !important;
        }

        .controls label, .controls span {
            color: ${colors.text} !important;
        }

        select, input[type="text"], input[type="email"], input[type="password"], input[type="date"] {
            background: ${colors.card} !important;
            color: ${colors.text} !important;
            border-color: ${colors.border} !important;
        }

        select:focus, input:focus {
            border-color: ${colors.accent} !important;
            box-shadow: 0 0 0 2px ${colors.accent}33 !important;
        }

        /* Buttons — exclude .sr-btn variants, .status-btn, .tooltip-close-btn, and .theme-swatch-btn */
        button:not(.sr-btn):not([class*="sr-btn"]):not(.action-btn):not(.clear-search-btn):not(.status-btn):not(.tooltip-close-btn):not(.theme-swatch-btn):not(.api-copy-btn), .btn:not(.sr-btn) {
            background: ${colors.primary} !important;
            color: white !important;
            border-color: ${colors.primary} !important;
        }

        button:not(.sr-btn):not(.action-btn):not(.clear-search-btn):not(.status-btn):not(.tooltip-close-btn):not(.theme-swatch-btn):not(.api-copy-btn):hover, .btn:not(.sr-btn):hover {
            background: ${colors.primary} !important;
            color: white !important;
            transform: translateY(-1px);
        }

        /* Theme swatch buttons — always white background with dark text regardless of active theme */
        .theme-swatch-btn {
            background: #ffffff !important;
            color: #333333 !important;
            border-color: #dee2e6 !important;
        }
        .theme-swatch-btn:hover {
            background: #f8f9fa !important;
            color: #333333 !important;
        }

        /* Status button colours — exactly match cell override colours */
        .status-btn { color: #fff !important; }
        .status-btn.status-on           { background: ${colors.primary} !important; }
        .status-btn.status-off          { background: ${colors.secondary} !important; }
        .status-btn.status-pto          { background: ${colors.warning} !important; color: #1a1a1a !important; }
        .status-btn.status-sick         { background: ${colors.danger} !important; }
        .status-btn.status-holiday      { background: #22c55e !important; }
        .status-btn.status-custom_hours { background: linear-gradient(135deg, ${colors.primary}, ${colors.primary}99) !important; border: 2px solid ${colors.accent} !important; font-weight: bold !important; }
        .status-btn.status-schedule     { background: ${colors.primary}BB !important; border: 1px solid ${colors.accent} !important; }

        /* Preserve .sr-btn variants for Settings UI */
        .sr-btn-primary { background: ${colors.primary} !important; color: #fff !important; border-color: ${colors.primary} !important; }
        .sr-btn-primary span, .sr-btn-primary * { color: #fff !important; }
        .sr-btn-primary:hover { filter: brightness(1.1) !important; background: ${colors.primary} !important; }
        .sr-btn-ghost { background: transparent !important; color: ${colors.textMuted} !important; border: 1.5px solid ${colors.border} !important; }
        .sr-btn-ghost:hover { background: ${contentBg} !important; color: ${colors.text} !important; border-color: ${colors.textMuted} !important; }
        .sr-btn-outline { background: transparent !important; color: ${colors.primary} !important; border: 1.5px solid ${colors.primary} !important; }
        .sr-btn-outline:hover { background: ${colors.primary} !important; color: #fff !important; }
        .sr-btn-danger { background: #dc3545 !important; color: #fff !important; border-color: #dc3545 !important; }
        .sr-btn-success { background: ${colors.success} !important; color: #fff !important; border-color: ${colors.success} !important; }
        .action-btn { background: transparent !important; border: none !important; color: ${colors.text} !important; }
        .clear-search-btn { background: ${colors.primary} !important; color: #fff !important; }
        
        .btn-green {
            background: ${colors.success} !important;
        }
        
        .btn-orange {
            background: ${colors.warning} !important;
        }
        
        .btn-red {
            background: ${colors.danger} !important;
        }
        
        .btn-purple {
            background: ${colors.primary} !important;
        }
        
        /* Schedule Table */
        .schedule-table th {
            background: ${tableHeadBg} !important;
            color: white !important;
            border-color: ${colors.border} !important;
        }
        
        .schedule-table td {
            border-color: ${colors.border} !important;
            color: ${colors.text} !important;
        }

        /* Today cell is always light orange — always needs dark text regardless of theme */
        .schedule-table td.today-cell {
            background: linear-gradient(135deg, #fff3e0 0%, #ffe0b3 100%) !important;
            border: 2px solid #ff6b35 !important;
            color: #1a1a1a !important;
        }
        .schedule-table td.today-cell .status-text { color: #1a1a1a !important; }
        .schedule-table td.today-cell .status-on,
        .schedule-table td.today-cell .status-pto,
        .schedule-table td.today-cell .status-sick,
        .schedule-table td.today-cell .status-holiday,
        .schedule-table td.today-cell .status-custom_hours,
        .schedule-table td.today-cell .status-schedule { color: white !important; }

        .schedule-table th:first-child {
            background: ${tableHeadBg} !important;
        }
        
        /* Employee Names */
        .employee-name {
            background: ${themeName === 'dark' ? colors.secondary : '#95a5a6'} !important;
            color: ${themeName === 'dark' ? colors.text : 'white'} !important;
        }
        
        .employee-name.own-employee {
            background: ${colors.success} !important;
        }
        
        .own-employee-cell {
            border-color: ${colors.success} !important;
        }
        
        /* Employee Action Icons */
        .employee-actions-icons button {
            background: transparent !important;
            border: none !important;
            opacity: ${themeName === 'dark' ? '0.8' : '0.7'} !important;
        }
        
        .employee-actions-icons button:hover {
            opacity: 1 !important;
            filter: ${themeName === 'dark' ? 'brightness(1.3)' : 'brightness(1.2)'} !important;
        }
        
        /* Dropdowns & Overlays */
        .actions-dropdown {
            background: ${colors.card} !important;
            border-color: ${colors.border} !important;
            box-shadow: 0 4px 20px ${themeName === 'dark' ? 'rgba(0,0,0,0.5)' : 'rgba(0,0,0,0.15)'} !important;
        }
        
        .dropdown-item {
            color: ${colors.text} !important;
        }
        
        .dropdown-item:hover {
            background: ${themeName === 'dark' ? colors.accent + '33' : '#f8f9fa'} !important;
        }
        
        .dropdown-item.edit {
            color: ${colors.accent} !important;
        }
        
        .dropdown-item.delete {
            color: ${colors.danger} !important;
        }
        
        /* Activity Log */
        .activity-log-dropdown {
            background: ${colors.card} !important;
            border-color: ${colors.border} !important;
            box-shadow: 0 8px 32px ${themeName === 'dark' ? 'rgba(0,0,0,0.7)' : 'rgba(0,0,0,0.15)'} !important;
        }
        
        .activity-log-header {
            background: ${colors.primary} !important;
            color: ${themeName === 'dark' ? colors.text : 'white'} !important;
        }
        
        .activity-log-header button {
            color: ${themeName === 'dark' ? colors.text : 'white'} !important;
        }
        
        .activity-log-item {
            border-bottom-color: ${colors.border} !important;
        }
        
        .activity-log-item:hover {
            background: ${themeName === 'dark' ? colors.secondary : '#f8f9fa'} !important;
        }
        
        .activity-action {
            color: ${colors.primary} !important;
        }
        
        .activity-description {
            color: ${colors.textMuted} !important;
        }
        
        .activity-user {
            color: ${colors.accent} !important;
        }
        
        /* Tooltips — always dark for consistent readability across all themes.
           High-specificity selectors used so these beat .tab-content span:not(.sb-icon)
           which has specificity 0-2-1. */
        .has-tooltip .tooltip,
        .stat-item.has-tooltip .tooltip {
            background: #1e2a35 !important;
            color: #ffffff !important;
            box-shadow: 0 6px 24px rgba(0,0,0,0.55) !important;
            border: 1px solid rgba(255,255,255,0.08) !important;
        }

        /* 0-3-1 specificity — beats any tab-content descendant rule */
        .tab-content .has-tooltip .tooltip,
        .tab-content .has-tooltip .tooltip *,
        .tab-content .has-tooltip .tooltip span,
        .tab-content .has-tooltip .tooltip div,
        .tab-content .has-tooltip .tooltip p,
        .tab-content .has-tooltip .tooltip small,
        .tab-content .has-tooltip .tooltip strong {
            color: #ffffff !important;
        }

        .has-tooltip .tooltip::before {
            border-right-color: #1e2a35 !important;
            border-bottom-color: transparent !important;
        }

        .has-tooltip .tooltip .tooltip-header {
            color: #ffffff !important;
            border-bottom-color: rgba(255,255,255,0.25) !important;
        }

        .has-tooltip .tooltip .tooltip-team {
            color: #ffffff !important;
        }

        .has-tooltip .tooltip .tooltip-employees {
            color: #ffffff !important;
        }
        
        /* Employee Hover Card */
        .employee-hover-card {
            background: ${colors.card} !important;
            border-color: ${colors.border} !important;
            box-shadow: 0 8px 32px ${themeName === 'dark' ? 'rgba(0,0,0,0.7)' : 'rgba(0,0,0,0.2)'} !important;
        }
        
        .employee-card-header {
            background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%) !important;
            color: white !important;
        }

        .employee-card-header * { color: white !important; }
        .employee-card-initials { color: white !important; }
        .employee-card-name { color: white !important; }
        .employee-card-team { color: white !important; }
        .employee-card-row .role-badge { color: white !important; }
        .employee-card-row .status-active { color: white !important; }
        .employee-card-row .status-inactive { color: white !important; }
        .employee-card-row .level-badge { color: white !important; }

        .employee-card-details {
            background: ${colors.card} !important;
        }

        .card-label {
            color: ${colors.textMuted} !important;
        }

        .card-value {
            color: ${colors.text} !important;
        }

        .employee-card-schedule {
            background: ${themeName === 'dark' ? colors.secondary : '#f8f9fa'} !important;
            border-top-color: ${colors.border} !important;
        }
        
        /* Modals */
        .modal-content {
            background: ${colors.card} !important;
            color: ${colors.text} !important;
            border: 1px solid ${colors.border} !important;
        }
        
        .edit-modal-content {
            background: ${colors.card} !important;
            color: ${colors.text} !important;
        }
        
        /* Statistics */
        .stats {
            background: ${themeName === 'dark' ? colors.secondary : '#ecf0f1'} !important;
        }
        
        .stat-item {
            background: ${colors.card} !important;
            border: 1px solid ${colors.border} !important;
        }
        
        .stat-number {
            color: ${colors.primary} !important;
        }
        
        .stat-label {
            color: ${colors.text} !important;
        }

        /* Backup Section */
        .backup-section {
            background: ${themeName === 'dark' ? colors.secondary : '#f8f9fa'} !important;
            border-color: ${colors.border} !important;
        }

        .backup-item {
            background: ${colors.card} !important;
            border-color: ${colors.border} !important;
        }

        .backup-info {
            color: ${colors.textMuted} !important;
        }

        /* Backup table header — force white text on secondary-color background */
        #backups-tab thead th, #backups-tab thead td {
            color: white !important;
            background: ${colors.secondary} !important;
        }
        
        /* Messages */
        .message.success {
            background: ${colors.success}22 !important;
            color: ${colors.success} !important;
            border-color: ${colors.success}66 !important;
        }
        
        .message.error {
            background: ${colors.danger}22 !important;
            color: ${colors.danger} !important;
            border-color: ${colors.danger}66 !important;
        }
        
        .message.warning {
            background: ${colors.warning}22 !important;
            color: ${colors.warning} !important;
            border-color: ${colors.warning}66 !important;
        }
        
        .message.info {
            background: ${colors.accent}22 !important;
            color: ${colors.accent} !important;
            border-color: ${colors.accent}66 !important;
        }
        
        /* Profile Elements */
        .profile-card {
            background: ${colors.card} !important;
            border-color: ${colors.border} !important;
        }
        
        .profile-header {
            background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%) !important;
            color: ${themeName === 'dark' ? colors.text : 'white'} !important;
        }
        
        .profile-details {
            background: ${colors.card} !important;
        }
        
        .field-label {
            color: ${colors.textMuted} !important;
        }
        
        .field-value {
            color: ${colors.text} !important;
        }
        
        /* Form Sections */
        .form-group label {
            color: ${colors.text} !important;
        }
        
        .template-section {
            background: ${themeName === 'dark' ? colors.secondary : '#f8f9fa'} !important;
            border-color: ${colors.border} !important;
        }
        
        /* Enhanced contrast for links and interactive elements */
        a {
            color: ${colors.accent} !important;
        }
        
        a:hover {
            color: ${colors.primary} !important;
        }
        
     /* Status colors — .status-on uses primary to match header/theme */
.status-on {
    background: ${colors.primary} !important;
    color: white !important;
}
.status-off {
    background: ${colors.secondary} !important;
}
.status-pto { 
    background: ${colors.warning} !important; 
}
.status-sick { 
    background: ${colors.danger} !important; 
}
.status-holiday { 
    background: #22c55e !important; 
}
.status-custom_hours {
    background: linear-gradient(135deg, ${colors.primary}, ${colors.primary}99) !important;
    color: white !important;
    border: 2px solid ${colors.accent} !important;
    font-weight: bold !important;
}
.status-schedule { 
    background: ${colors.primary}BB !important; 
    color: white !important;
    border: 1px solid ${colors.accent} !important;
}

/* Message of the Day (MOTD) Banner */
.motd-banner {
    background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%) !important;
}

/* MOTD Management Section in Settings */
.motd-management-section {
    background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%) !important;
    color: white !important;
}

.motd-scrolling-content {
    color: white !important;
    font-weight: bold !important;
    font-size: 18px !important;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
}

.motd-scrolling-content * {
    color: white !important;
    font-weight: bold !important;
}

.motd-banner strong {
    color: white !important;
}

.motd-banner a {
    color: #fbbf24 !important;
    text-decoration: underline !important;
    font-weight: bold !important;
}
        /* Sort controls */
        
        /* User Info */
        .user-info {
            background: rgba(255,255,255,0.1) !important;
        }
        
        .role-badge {
            background: ${colors.accent} !important;
            color: ${themeName === 'dark' ? colors.text : 'white'} !important;
        }
        
        /* Theme Dropdown */
        .theme-dropdown {
            background: ${colors.card} !important;
            border-color: ${colors.border} !important;
            box-shadow: 0 10px 30px ${themeName === 'dark' ? 'rgba(0,0,0,0.7)' : 'rgba(0,0,0,0.2)'} !important;
            color: ${colors.text} !important;
        }
        
        .theme-dropdown > div:hover {
            background: ${themeName === 'dark' ? colors.accent + '33' : '#f0f0f0'} !important;
        }
        
        /* User Menu Dropdown - Theme Aware */
        .user-menu-dropdown {
            background: ${colors.card} !important;
            border-color: ${colors.border} !important;
            box-shadow: 0 10px 30px ${themeName === 'dark' ? 'rgba(0,0,0,0.7)' : 'rgba(0,0,0,0.2)'} !important;
            color: ${colors.text} !important;
        }
        
        .user-menu-dropdown > div,
        .user-menu-dropdown > a {
            color: ${colors.text} !important;
        }
        
        /* Menu items styling */
        .user-menu-dropdown .menu-item {
            color: ${colors.text} !important;
            border-bottom-color: ${colors.border} !important;
        }
        
        .user-menu-dropdown .menu-item span {
            color: ${colors.text} !important;
        }
        
        /* Hover states for all clickable menu items */
        .user-menu-dropdown > div[onclick]:hover,
        .user-menu-dropdown .menu-item:hover {
            background: ${themeName === 'dark' ? colors.accent + '33' : '#f8f9fa'} !important;
        }
        
        .user-menu-dropdown .theme-option:hover {
            background: ${themeName === 'dark' ? colors.accent + '33' : '#f8f9fa'} !important;
        }
        
        /* Keep header sections visible */
        .user-menu-dropdown > div[style*="background: #34495e"] {
            background: ${colors.primary} !important;
            color: ${themeName === 'dark' ? colors.text : 'white'} !important;
        }
        
        .user-menu-dropdown > div[style*="background: #ecf0f1"] {
            background: ${themeName === 'dark' ? colors.secondary : '#ecf0f1'} !important;
            color: ${themeName === 'dark' ? '#ffffff' : '#000000'} !important;
        }
        
        .user-menu-dropdown > div[style*="background: #ecf0f1"] span {
            color: ${themeName === 'dark' ? '#ffffff' : '#000000'} !important;
        }
        
        .user-menu-dropdown > div[style*="background: #ecf0f1"]:hover {
            background: ${themeName === 'dark' ? colors.accent + '33' : '#dce3e8'} !important;
        }
        
        /* Theme submenu items */
        #themeSubmenu .theme-option {
            background: ${colors.card} !important;
            color: ${colors.text} !important;
            border-bottom-color: ${colors.border} !important;
        }
        
        /* Logout link styling */
        .user-menu-dropdown > a[href*="logout"] {
            color: ${colors.danger} !important;
        }
        
        .user-menu-dropdown > a[href*="logout"]:hover {
            background: ${themeName === 'dark' ? colors.danger + '22' : '#fef2f2'} !important;
        }
        
        /* Scrollbars for dark theme */
        ${themeName === 'dark' ? `
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: ${colors.secondary};
        }
        
        ::-webkit-scrollbar-thumb {
            background: ${colors.accent};
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: ${colors.primary};
        }
        ` : ''}
        
        /* High contrast text for readability */
        h1, h2, h3, h4, h5, h6 {
            color: ${colors.text} !important;
        }
        
        p, span, div {
            color: ${colors.text} !important;
        }
        
        /* Empty state styling */
        .empty-state {
            color: ${colors.textMuted} !important;
        }
        
        /* Enhanced visibility for all interactive elements */
        .actions-btn {
            background: ${colors.primary} !important;
            color: ${themeName === 'dark' ? colors.text : 'white'} !important;
        }
        
        .actions-btn:hover {
            background: ${colors.accent} !important;
        }
        
        /* Ensure proper contrast for all badge elements */
        .employee-indicator {
            background: ${colors.success} !important;
            color: white !important;
        }
        
        /* Level badge inside employee name cells — fixed colors matching hover card, not theme-dependent */
        .level-info { color: white !important; }
        .level-info.level-ssa  { background: #dc2626 !important; }
        .level-info.level-ssa2 { background: #9b59b6 !important; }
        .level-info.level-l1   { background: #06b6d4 !important; }
        .level-info.level-l2   { background: #f59e0b !important; }
        .level-info.level-l3   { background: #0891b2 !important; }
        .level-info.level-tam  { background: #f97316 !important; }
        .level-info.level-tam2 { background: #ea580c !important; }
        .level-info.level-manager    { background: #7c3aed !important; }
        .level-info.level-Supervisor { background: #0369a1 !important; }
        .level-info.level-SR--Supervisor  { background: #0f766e !important; }
        .level-info.level-SR--Manager     { background: #c2410c !important; }
        .level-info.level-IMP-Tech        { background: #1d4ed8 !important; }
        .level-info.level-IMP-Coordinator { background: #6d28d9 !important; }
        .level-info.level-SecOps-T1       { background: #1a56db !important; }
        .level-info.level-SecOps-T2       { background: #7e3af2 !important; }
        .level-info.level-SecOps-T3       { background: #e74694 !important; }
        .level-info.level-SecEng          { background: #d61f69 !important; }
        .level-info.level-technical_writer { background: #0d9488 !important; }
        .level-info.level-trainer    { background: #16a34a !important; }
        .level-info.level-tech_coach { background: #0284c7 !important; }
        
        /* Template buttons */
        .template-btns button {
            background: ${colors.accent} !important;
            color: ${themeName === 'dark' ? colors.text : 'white'} !important;
            border-color: ${colors.accent} !important;
        }
        
        .template-btns button:hover {
            background: ${colors.primary} !important;
        }
        
        /* Heatmap Intensity Colors - Full Theme Aware */
        ${(() => {
            // Define custom heatmap colors for each theme
            const heatmapColors = {
                default: {
                    0: { bg: '#f0f0ff', text: '#333399' },
                    1: { bg: '#d4d4f7', text: '#333399' },
                    2: { bg: '#b4b4ef', text: '#1e2a6e' },
                    3: { bg: '#9090e0', text: '#1e2a6e' },
                    4: { bg: '#6a6acc', text: 'white' },
                    5: { bg: '#5050b8', text: 'white' },
                    6: { bg: '#3d3da8', text: 'white' },
                    7: { bg: '#333399', text: 'white' },
                    8: { bg: '#1e2a6e', text: 'white' }
                },
                dark: {
                    0: { bg: colors.secondary, text: colors.textMuted },
                    1: { bg: '#1e3a5f', text: colors.text },
                    2: { bg: '#2563eb', text: colors.text },
                    3: { bg: '#3b82f6', text: 'white' },
                    4: { bg: '#60a5fa', text: 'white' },
                    5: { bg: '#93c5fd', text: colors.card },
                    6: { bg: colors.primary, text: 'white' },
                    7: { bg: colors.accent, text: 'white' },
                    8: { bg: colors.success, text: 'white' }
                },
                ocean: {
                    0: { bg: '#e0f2fe', text: '#0c4a6e' },
                    1: { bg: '#bae6fd', text: '#075985' },
                    2: { bg: '#7dd3fc', text: '#0369a1' },
                    3: { bg: '#38bdf8', text: '#075985' },
                    4: { bg: '#0ea5e9', text: 'white' },
                    5: { bg: '#0284c7', text: 'white' },
                    6: { bg: '#0369a1', text: 'white' },
                    7: { bg: '#075985', text: 'white' },
                    8: { bg: '#0c4a6e', text: 'white' }
                },
                forest: {
                    0: { bg: '#d1fae5', text: '#065f46' },
                    1: { bg: '#a7f3d0', text: '#047857' },
                    2: { bg: '#6ee7b7', text: '#047857' },
                    3: { bg: '#34d399', text: '#065f46' },
                    4: { bg: '#10b981', text: 'white' },
                    5: { bg: '#059669', text: 'white' },
                    6: { bg: '#047857', text: 'white' },
                    7: { bg: '#065f46', text: 'white' },
                    8: { bg: '#064e3b', text: 'white' }
                },
                sunset: {
                    0: { bg: '#fed7aa', text: '#9a3412' },
                    1: { bg: '#fdba74', text: '#9a3412' },
                    2: { bg: '#fb923c', text: '#7c2d12' },
                    3: { bg: '#f97316', text: 'white' },
                    4: { bg: '#ea580c', text: 'white' },
                    5: { bg: '#dc2626', text: 'white' },
                    6: { bg: '#c2410c', text: 'white' },
                    7: { bg: '#9a3412', text: 'white' },
                    8: { bg: '#7c2d12', text: 'white' }
                },
                royal: {
                    0: { bg: '#f3e8ff', text: '#6b21a8' },
                    1: { bg: '#e9d5ff', text: '#7e22ce' },
                    2: { bg: '#d8b4fe', text: '#7e22ce' },
                    3: { bg: '#c084fc', text: '#6b21a8' },
                    4: { bg: '#a855f7', text: 'white' },
                    5: { bg: '#9333ea', text: 'white' },
                    6: { bg: '#7e22ce', text: 'white' },
                    7: { bg: '#6b21a8', text: 'white' },
                    8: { bg: '#581c87', text: 'white' }
                },
                crimson: {
                    0: { bg: '#fee2e2', text: '#991b1b' },
                    1: { bg: '#fecaca', text: '#991b1b' },
                    2: { bg: '#fca5a5', text: '#7f1d1d' },
                    3: { bg: '#f87171', text: 'white' },
                    4: { bg: '#ef4444', text: 'white' },
                    5: { bg: '#dc2626', text: 'white' },
                    6: { bg: '#b91c1c', text: 'white' },
                    7: { bg: '#991b1b', text: 'white' },
                    8: { bg: '#7f1d1d', text: 'white' }
                },
                teal: {
                    0: { bg: '#ccfbf1', text: '#134e4a' },
                    1: { bg: '#99f6e4', text: '#0f766e' },
                    2: { bg: '#5eead4', text: '#0f766e' },
                    3: { bg: '#2dd4bf', text: '#134e4a' },
                    4: { bg: '#14b8a6', text: 'white' },
                    5: { bg: '#0d9488', text: 'white' },
                    6: { bg: '#0f766e', text: 'white' },
                    7: { bg: '#115e59', text: 'white' },
                    8: { bg: '#134e4a', text: 'white' }
                },
                amber: {
                    0: { bg: '#fef3c7', text: '#92400e' },
                    1: { bg: '#fde68a', text: '#92400e' },
                    2: { bg: '#fcd34d', text: '#78350f' },
                    3: { bg: '#fbbf24', text: '#78350f' },
                    4: { bg: '#f59e0b', text: 'white' },
                    5: { bg: '#d97706', text: 'white' },
                    6: { bg: '#b45309', text: 'white' },
                    7: { bg: '#92400e', text: 'white' },
                    8: { bg: '#78350f', text: 'white' }
                },
                slate: {
                    0: { bg: '#f1f5f9', text: '#0f172a' },
                    1: { bg: '#e2e8f0', text: '#1e293b' },
                    2: { bg: '#cbd5e1', text: '#334155' },
                    3: { bg: '#94a3b8', text: '#475569' },
                    4: { bg: '#64748b', text: 'white' },
                    5: { bg: '#475569', text: 'white' },
                    6: { bg: '#334155', text: 'white' },
                    7: { bg: '#1e293b', text: 'white' },
                    8: { bg: '#0f172a', text: 'white' }
                },
                emerald: {
                    0: { bg: '#d1fae5', text: '#065f46' },
                    1: { bg: '#a7f3d0', text: '#047857' },
                    2: { bg: '#6ee7b7', text: '#047857' },
                    3: { bg: '#34d399', text: '#065f46' },
                    4: { bg: '#10b981', text: 'white' },
                    5: { bg: '#059669', text: 'white' },
                    6: { bg: '#047857', text: 'white' },
                    7: { bg: '#065f46', text: 'white' },
                    8: { bg: '#064e3b', text: 'white' }
                },
                midnight: {
                    0: { bg: '#e0e7ff', text: '#312e81' },
                    1: { bg: '#c7d2fe', text: '#3730a3' },
                    2: { bg: '#a5b4fc', text: '#4338ca' },
                    3: { bg: '#818cf8', text: '#4338ca' },
                    4: { bg: '#6366f1', text: 'white' },
                    5: { bg: '#4f46e5', text: 'white' },
                    6: { bg: '#4338ca', text: 'white' },
                    7: { bg: '#3730a3', text: 'white' },
                    8: { bg: '#312e81', text: 'white' }
                },
                rose: {
                    0: { bg: '#ffe4e6', text: '#9f1239' },
                    1: { bg: '#fecdd3', text: '#9f1239' },
                    2: { bg: '#fda4af', text: '#881337' },
                    3: { bg: '#fb7185', text: 'white' },
                    4: { bg: '#f43f5e', text: 'white' },
                    5: { bg: '#e11d48', text: 'white' },
                    6: { bg: '#be123c', text: 'white' },
                    7: { bg: '#9f1239', text: 'white' },
                    8: { bg: '#881337', text: 'white' }
                },
                copper: {
                    0: { bg: '#fed7aa', text: '#9a3412' },
                    1: { bg: '#fdba74', text: '#9a3412' },
                    2: { bg: '#fb923c', text: '#7c2d12' },
                    3: { bg: '#f97316', text: 'white' },
                    4: { bg: '#ea580c', text: 'white' },
                    5: { bg: '#c2410c', text: 'white' },
                    6: { bg: '#9a3412', text: 'white' },
                    7: { bg: '#7c2d12', text: 'white' },
                    8: { bg: '#431407', text: 'white' }
                }
            };
            
            // Get colors for current theme, fallback to default theme colors if not found
            const currentHeatmap = heatmapColors[themeName] || heatmapColors['default'];
            
            // Generate CSS for all intensity levels
            let css = '';
            for (let i = 0; i <= 8; i++) {
                css += `.intensity-${i} { background: ${currentHeatmap[i].bg} !important; color: ${currentHeatmap[i].text} !important; }\n            `;
            }
            return css;
        })()}
        
        /* Coverage by Level - Theme Aware */
        .level-breakdown-title {
            color: ${colors.primary} !important;
        }
        
        .level-item {
            background: ${themeName === 'dark' ? colors.accent + '22' : '#f8f9fa'} !important;
            border: 1px solid ${themeName === 'dark' ? colors.accent + '44' : colors.border} !important;
        }
        
        .level-item-name {
            color: ${colors.text} !important;
        }
        
        .level-item-count {
            background: ${colors.primary} !important;
            color: ${themeName === 'dark' ? colors.card : 'white'} !important;
        }
        
        .level-no-level {
            color: ${colors.textMuted} !important;
        }
        
        /* Heatmap Statistics Cards - Theme Aware */
        .stat-card {
            background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%) !important;
        }
        
        .stat-card .stat-value,
        #heatmapStatsContainer .stat-card .stat-value,
        .stat-value {
            color: ${themeName === 'dark' ? colors.text : 'white'} !important;
            font-weight: bold !important;
            text-shadow: ${themeName === 'dark' ? 'none' : '1px 1px 2px rgba(0,0,0,0.3)'} !important;
        }
        
        .stat-card .stat-label,
        #heatmapStatsContainer .stat-card .stat-label {
            color: ${themeName === 'dark' ? colors.text : 'white'} !important;
            opacity: ${themeName === 'dark' ? '0.9' : '0.95'} !important;
            text-shadow: ${themeName === 'dark' ? 'none' : '1px 1px 2px rgba(0,0,0,0.3)'} !important;
        }
        
        /* Heatmap Controls Button - Theme Aware */
        .heatmap-controls button {
            background: ${colors.primary} !important;
            color: ${themeName === 'dark' ? colors.text : 'white'} !important;
        }
        
        .heatmap-controls button:hover {
            background: ${colors.secondary} !important;
        }
        
        /* Heatmap Modal Badges - Theme Aware */
        .heatmap-override-badge {
            color: ${colors.warning} !important;
            font-weight: 500 !important;
        }
        
        .heatmap-regular-badge {
            color: ${colors.primary} !important;
            font-weight: 500 !important;
        }
        
        .heatmap-legend-text {
            font-size: 12px !important;
            color: ${colors.textMuted} !important;
            margin-bottom: 10px !important;
        }
        
        /* PTO/Sick Leave Section - Theme Aware */
        .pto-sick-container {
            background: ${colors.card} !important;
            color: ${colors.text} !important;
            border: 1px solid ${colors.border} !important;
        }
        
        .pto-sick-container h3 {
            color: ${colors.text} !important;
            border-bottom-color: ${colors.border} !important;
        }
        
        .pto-section {
            background: ${themeName === 'dark' ? colors.primary + '22' : colors.primary + '15'} !important;
            border-left-color: ${colors.primary} !important;
        }
        
        .pto-section h4 {
            color: ${colors.primary} !important;
        }
        
        .pto-section .employee-card {
            background: ${colors.card} !important;
            border-color: ${colors.primary} !important;
            color: ${colors.text} !important;
        }
        
        .pto-section .employee-name {
            color: ${colors.text} !important;
        }
        
        .pto-section .employee-dates {
            color: ${colors.primary} !important;
        }
        
        .sick-section {
            background: ${themeName === 'dark' ? colors.secondary + '22' : colors.secondary + '15'} !important;
            border-left-color: ${colors.secondary} !important;
        }
        
        .sick-section h4 {
            color: ${themeName === 'dark' ? colors.secondary : colors.secondary} !important;
        }
        
        .sick-section .employee-card {
            background: ${colors.card} !important;
            border-color: ${colors.secondary} !important;
            color: ${colors.text} !important;
        }
        
        .sick-section .employee-name {
            color: ${colors.text} !important;
        }
        
        .sick-section .employee-dates {
            color: ${colors.secondary} !important;
        }
        
        /* Holiday Section - Theme Aware */
        .holiday-section {
            background: ${themeName === 'dark' ? colors.warning + '22' : colors.warning + '15'} !important;
            border-left-color: ${colors.warning} !important;
        }
        
        .holiday-section h4 {
            color: ${themeName === 'dark' ? colors.warning : colors.warning} !important;
        }
        
        .holiday-section .employee-card {
            background: ${colors.card} !important;
            border-color: ${colors.warning} !important;
            color: ${colors.text} !important;
        }
        
        .holiday-section .employee-name {
            color: ${colors.text} !important;
        }
        
        .holiday-section .employee-dates {
            color: ${colors.warning} !important;
        }
        
        .pto-sick-team-badge {
            background: ${colors.accent + '33'} !important;
            color: ${themeName === 'dark' ? colors.text : colors.text} !important;
        }
        
        /* IMPORTANT: Override employee-name background for time-off cards */
        .pto-section .employee-card .employee-name,
        .sick-section .employee-card .employee-name,
        .holiday-section .employee-card .employee-name {
            background: none !important;
            position: static !important;
            padding: 0 !important;
            z-index: auto !important;
            left: auto !important;
        }
        
        /* Edit Employee Form - Theme Aware */
        #editEmployeeForm {
            background: ${colors.card} !important;
            color: ${colors.text} !important;
        }
        
        #editEmployeeForm .employee-info-header {
            background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%) !important;
            color: white !important;
        }
        
        #editEmployeeForm .employee-info-header h3,
        #editEmployeeForm .employee-info-header div,
        #editEmployeeForm .employee-info-header span,
        #editEmployeeForm .employee-info-header strong {
            color: white !important;
        }
        
        #editEmployeeForm h3, #editEmployeeForm h4 {
            color: ${themeName === 'dark' ? colors.text : 'inherit'} !important;
        }
        
        #editEmployeeForm .section-box {
            background: ${themeName === 'dark' ? colors.secondary : '#f8f9fa'} !important;
        }
        
        #editEmployeeForm label {
            color: ${colors.text} !important;
        }
        
        #editEmployeeForm input[type="text"],
        #editEmployeeForm input[type="email"],
        #editEmployeeForm select {
            background: ${colors.card} !important;
            color: ${colors.text} !important;
            border-color: ${colors.border} !important;
        }
        
        #editEmployeeForm input:focus,
        #editEmployeeForm select:focus {
            border-color: ${colors.accent} !important;
            box-shadow: 0 0 0 2px ${colors.accent}33 !important;
        }
        
        #editEmployeeForm .checkbox-label {
            background: ${colors.card} !important;
            color: ${colors.text} !important;
        }
        
        #editEmployeeForm .checkbox-label:hover {
            background: ${themeName === 'dark' ? colors.secondary : '#f8f9fa'} !important;
        }
        
        #editEmployeeForm .skill-description {
            color: ${colors.textMuted} !important;
        }
        
        #editEmployeeForm button[type="button"] {
            background: ${themeName === 'dark' ? colors.secondary : '#95a5a6'} !important;
            color: ${themeName === 'dark' ? colors.text : 'white'} !important;
        }
        
        #editEmployeeForm button[type="submit"] {
            background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%) !important;
            color: ${themeName === 'dark' ? colors.text : 'white'} !important;
        }
        
        /* Email Export Tools Section */
        .email-export-section {
            background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%) !important;
            color: white !important;
        }
        .email-export-section,
        .email-export-section * {
            color: white !important;
        }
        .email-export-section *:not(input):not(textarea) {
            color: white !important;
        }
        .email-section-title,
        .email-section-description,
        .email-status-text,
        .email-button-secondary {
            color: white !important;
        }

        /* Rippling API Integration Section */
        .rippling-api-section {
            background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%) !important;
            color: white !important;
        }
        .rippling-api-section,
        .rippling-api-section * {
            color: white !important;
        }
        .rippling-api-section *:not(input):not(textarea) {
            color: white !important;
        }

        /* View Profile Header */
        .view-profile-header {
            background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%) !important;
            color: white !important;
        }
        .view-profile-header,
        .view-profile-header * {
            color: white !important;
        }

        /* Sidebar Theme */
        .app-sidebar {
            background: ${colors.secondary} !important;
        }
        .sidebar-link.active {
            border-left-color: ${colors.accent} !important;
        }
        .header-clock {
            background: rgba(0,0,0,0.2) !important;
        }
        .header-clock-time {
            color: #fff !important;
        }
        .header-clock-sub {
            color: rgba(255,255,255,0.85) !important;
        }

        /* CSS Custom Properties for theme-aware components */
        :root {
            --primary-color: ${colors.primary};
            --body-bg: ${colors.surface === '#000000' ? '#f8f9fa' : colors.surface};
            --card-bg: ${colors.card};
            --border-color: ${colors.border};
            --text-color: ${colors.text};
            --sidebar-bg: ${colors.secondary};
        }

        /* ── Doc shells (API Docs + User Manual) — theme-aware ──
           Light mode: lock to white bg + dark text (immune to broad theme rules).
           Dark mode: let the per-file [data-theme="dark"] CSS apply proper
           dark colors, while still ensuring child elements inherit correctly. */
        .api-doc-shell,
        .api-doc-shell h1, .api-doc-shell h2, .api-doc-shell h3,
        .api-doc-shell h4, .api-doc-shell h5, .api-doc-shell h6,
        .api-doc-shell p, .api-doc-shell span, .api-doc-shell label,
        .api-doc-shell td, .api-doc-shell th, .api-doc-shell li,
        .api-doc-shell small, .api-doc-shell code, .api-doc-shell pre {
            color: inherit !important;
        }
        ${isDark
            ? `.api-doc-shell { background-color: ${colors.card} !important; color: ${colors.text} !important; }`
            : `.api-doc-shell { background-color: #ffffff !important; color: #1e293b !important; }`
        }

        .man-doc-shell,
        .man-doc-shell h1, .man-doc-shell h2, .man-doc-shell h3,
        .man-doc-shell h4, .man-doc-shell h5, .man-doc-shell h6,
        .man-doc-shell p, .man-doc-shell span, .man-doc-shell label,
        .man-doc-shell td, .man-doc-shell th, .man-doc-shell li,
        .man-doc-shell small, .man-doc-shell code, .man-doc-shell pre {
            color: inherit !important;
        }
        ${isDark
            ? `.man-doc-shell { background-color: ${colors.card} !important; color: ${colors.text} !important; }`
            : `.man-doc-shell { background-color: #ffffff !important; color: #1e293b !important; }`
        }
        /* Skill badge pills — dark theme overrides */
        ${isDark ? `
        .skill-form-badges { background: #21262d !important; border-color: #30363d !important; }
        .skill-badge-mh { background: #1e3a5f !important; color: #93c5fd !important; }
        .skill-badge-ma { background: #3b1f4e !important; color: #c4b5fd !important; }
        .skill-badge-win { background: #14532d !important; color: #86efac !important; }
        .skill-label-text { color: ${colors.text} !important; }
        .checklist-warning-box { background: #2d2510 !important; border-color: #78350f !important; color: #fde68a !important; }
        .checklist-warning-box * { color: #fde68a !important; }
        ` : ''}
    `;

    return css;
}
}

// closeUserMenu kept for any legacy references
function closeUserMenu() {
    const menu = document.getElementById('sidebarUserMenu');
    const arrow = document.getElementById('sidebarUserArrow');
    if (menu) menu.style.display = 'none';
    if (arrow) arrow.style.transform = 'rotate(0deg)';
}

function toggleThemeSubmenu(event) {
    event.stopPropagation(); // Prevent closing the user menu
    const submenu = document.getElementById('themeSubmenu');
    const arrow = document.getElementById('themeSubmenuArrow');
    
    if (submenu && arrow) {
        if (submenu.style.display === 'none' || submenu.style.display === '') {
            submenu.style.display = 'block';
            arrow.style.transform = 'rotate(180deg)';
        } else {
            submenu.style.display = 'none';
            arrow.style.transform = 'rotate(0deg)';
        }
    }
}

function handleThemeSelection(theme) {
    // Apply via ThemeSelector instantly (skipDelay=true removes the 250ms lock)
    if (window.themeSelector) {
        window.themeSelector.applyTheme(theme, true);
    }
    // Sync ThemeManager: updates dynamic-theme-styles CSS variables AND sets/removes
    // the data-theme attribute on <body> so [data-theme="dark"] selectors in
    // api_docs.php, manual.php, and the injected stylesheet all activate correctly.
    if (window.themeManager) {
        window.themeManager.applyTheme(theme);
    } else {
        // Fallback if ThemeManager not available
        if (theme === 'dark') {
            document.body.setAttribute('data-theme', 'dark');
        } else {
            document.body.removeAttribute('data-theme');
        }
    }
}

function toggleUserMenu() {
    const menu = document.getElementById('sidebarUserMenu');
    const arrow = document.getElementById('sidebarUserArrow');
    if (!menu) return;
    const isOpen = menu.style.display !== 'none';
    menu.style.display = isOpen ? 'none' : 'block';
    if (arrow) arrow.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
}

// Close user menu when clicking outside the sidebar user area
document.addEventListener('click', function(e) {
    if (!e.target.closest('#sidebarUser') && !e.target.closest('#sidebarUserMenu')) {
        const menu = document.getElementById('sidebarUserMenu');
        const arrow = document.getElementById('sidebarUserArrow');
        if (menu && menu.style.display !== 'none') {
            menu.style.display = 'none';
            if (arrow) arrow.style.transform = 'rotate(0deg)';
        }
    }
});


document.addEventListener('DOMContentLoaded', function() {
    window.themeSelector = new ThemeSelector();
});

if (document.readyState !== 'loading') {
    window.themeSelector = new ThemeSelector();
}

const themeAnimationCSS = `
.theme-switching::before {
    content: '';
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.1);
    z-index: 9999;
    opacity: 0;
    animation: themeSwitch 0.5s ease;
    pointer-events: none;
}

@keyframes themeSwitch {
    0% { opacity: 0; }
    50% { opacity: 1; }
    100% { opacity: 0; }
}`;

const styleSheet = document.createElement('style');
styleSheet.textContent = themeAnimationCSS;
document.head.appendChild(styleSheet);
</script>
<!-- Enhanced Back to Top Button -->
<button class="back-to-top" id="backToTop" onclick="scrollToTop()" title="Back to Top">
    ↑
</button>
<script>
// Enhanced Back to Top Button Functionality
function updateBackToTop() {
    var backToTop = document.getElementById("backToTop");
    if (!backToTop) return;
    
    var scrollTop = document.body.scrollTop || document.documentElement.scrollTop;
    if (scrollTop > 300) {
        backToTop.classList.add("visible");
    } else {
        backToTop.classList.remove("visible");
    }
}

function scrollToTop() {
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
}

// Initialize back to top functionality
document.addEventListener("DOMContentLoaded", function() {
    updateBackToTop();
    window.addEventListener("scroll", updateBackToTop);
    window.addEventListener("resize", updateBackToTop);
    
    // Restore active tab from URL parameter
    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab');
    
    // Check if heatmap tab is already active (via PHP)
    const heatmapTab = document.getElementById('heatmap-tab');
    const isHeatmapActive = heatmapTab && heatmapTab.classList.contains('active');
    
    if (activeTab) {
        const activeContent = document.querySelector('.tab-content.active');
        const expectedId = activeTab + '-tab';
        
        // Only switch tab if the active tab doesn't match URL parameter
        if (!activeContent || activeContent.id !== expectedId) {
            showTab(activeTab);
        } else if (activeTab === 'heatmap' && isHeatmapActive) {
            // Heatmap tab is already active via PHP, just initialize it once
            if (typeof initializeHeatmap === 'function') {
                initializeHeatmap();
                if (typeof initializeSkillsHeatmap === 'function') {
                    initializeSkillsHeatmap();
                }
            }
        }
    } else if (isHeatmapActive) {
        // No tab parameter but heatmap is active (direct page load to heatmap)
        if (typeof initializeHeatmap === 'function') {
            initializeHeatmap();
            if (typeof initializeSkillsHeatmap === 'function') {
                initializeSkillsHeatmap();
            }
        }
    }
    
    // ==================================================================
    // HEATMAP PRELOAD — render both heatmaps in the background so data
    // is already present the moment the user clicks the heatmap tab.
    // ==================================================================
    setTimeout(function() {
        // Initialise date range if not done yet
        if (typeof heatmapDatesInitialized !== 'undefined' &&
            !heatmapDatesInitialized &&
            typeof initializeDateRange === 'function') {
            initializeDateRange();
            heatmapDatesInitialized = true;
        }
        // Pre-render Coverage Heatmap in background (synchronous, no network calls)
        if (typeof updateHeatmapData === 'function') {
            updateHeatmapData();
        }
        // Pre-render Skills Coverage Heatmap in background
        if (typeof initializeSkillsHeatmap === 'function') {
            initializeSkillsHeatmap();
        }
    }, 600); // slight delay so the main schedule tab renders first

    // ==================================================================
    // EDIT EMPLOYEE TAB - Now handled by PHP (like bulk-schedule)
    // ==================================================================
    // Edit employee form is populated by PHP on page load when ID is present in URL
    // No JavaScript form generation needed!
    
    // ==================================================================
    // END EDIT EMPLOYEE TAB FUNCTIONALITY
    // ==================================================================
    // ==================================================================
    
    // Function to add current_tab field to a form
    function addCurrentTabField(form) {
        if (!form.querySelector('input[name="current_tab"]')) {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'current_tab';
            hiddenInput.value = getCurrentActiveTab();
            form.appendChild(hiddenInput);
        }
    }
    
    // Function to update current_tab field in a form
    function updateCurrentTabField(form) {
        const currentTabInput = form.querySelector('input[name="current_tab"]');
        if (currentTabInput) {
            currentTabInput.value = getCurrentActiveTab();
        } else {
            addCurrentTabField(form);
        }
    }
    
    // Add hidden field to all existing forms
    document.querySelectorAll('form').forEach(form => {
        addCurrentTabField(form);
        
        // Update hidden field on form submission
        form.addEventListener('submit', function() {
            updateCurrentTabField(form);
        });
    });
    
    // Watch for dynamically added forms (for modals that might load later)
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
                if (node.nodeName === 'FORM') {
                    addCurrentTabField(node);
                    node.addEventListener('submit', function() {
                        updateCurrentTabField(node);
                    });
                } else if (node.querySelectorAll) {
                    node.querySelectorAll('form').forEach(function(form) {
                        addCurrentTabField(form);
                        form.addEventListener('submit', function() {
                            updateCurrentTabField(form);
                        });
                    });
                }
            });
        });
    });
    
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
});

// Helper function to get current active tab
function getCurrentActiveTab() {
    // First check URL
    const urlParams = new URLSearchParams(window.location.search);
    const urlTab = urlParams.get('tab');
    if (urlTab) return urlTab;
    
    // Then check active tab in DOM
    const activeTabContent = document.querySelector('.tab-content.active');
    if (activeTabContent) {
        const tabId = activeTabContent.id;
        return tabId.replace('-tab', ''); // Remove '-tab' suffix to get tab name
    }
    return 'schedule'; // Default tab
}

// Fallback for immediate execution
if (document.readyState !== "loading") {
    updateBackToTop();
    window.addEventListener("scroll", updateBackToTop);
    window.addEventListener("resize", updateBackToTop);
}

</script>



<script>
// Notification Bar Functions
function showNotification(message, type = 'success') {
    const bar = document.getElementById('notificationBar');
    const messageSpan = document.getElementById('notificationMessage');
    
    if (!bar || !messageSpan) return;
    
    messageSpan.innerHTML = message;
    bar.style.display = 'block';
    
    // Style based on type
    if (type === 'success') {
        bar.style.background = '#d4edda';
        bar.style.color = '#155724';
        bar.style.border = '1px solid #c3e6cb';
    } else if (type === 'error') {
        bar.style.background = '#f8d7da';
        bar.style.color = '#721c24';
        bar.style.border = '1px solid #f5c6cb';
    } else if (type === 'info') {
        bar.style.background = '#d1ecf1';
        bar.style.color = '#0c5460';
        bar.style.border = '1px solid #bee5eb';
    }
    
    // Scroll to top to show notification
    window.scrollTo({ top: 0, behavior: 'smooth' });
    
    // Auto-hide after 5 seconds
    setTimeout(hideNotification, 5000);
}

function hideNotification() {
    const bar = document.getElementById('notificationBar');
    if (bar) {
        bar.style.display = 'none';
    }
}

// Handle inline form submission with AJAX
function submitInlineForm(formElement, currentTab) {
    const formData = new FormData(formElement);
    
    // Show loading state
    const submitButton = formElement.querySelector('button[type="submit"]');
    const originalText = submitButton ? submitButton.innerHTML : '';
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.innerHTML = '⏳ Processing...';
    }
    
    fetch(window.location.pathname, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Server returned ' + response.status);
        }
        return response.text();
    })
    .then(html => {
        // Extract message from response if present
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const messageDiv = doc.querySelector('.message');
        
        let messageText = '✅ Action completed successfully!';
        let messageType = 'success';
        
        if (messageDiv) {
            messageText = messageDiv.textContent.trim();
            messageType = messageDiv.classList.contains('success') ? 'success' : 
                         messageDiv.classList.contains('error') ? 'error' : 'info';
        }
        
        showNotification(messageText, messageType);
        
        // Reset form if successful
        if (messageType === 'success') {
            formElement.reset();
            
            // Refresh activity log after successful action
            if (window.ScheduleApp && window.ScheduleApp.refreshActivityLog) {
                window.ScheduleApp.refreshActivityLog();
            }
            
            // Re-check default checkboxes for Add Employee form if present
            const day1 = formElement.querySelector('#addDay1, #modalAddDay1');
            const day2 = formElement.querySelector('#addDay2, #modalAddDay2');
            const day3 = formElement.querySelector('#addDay3, #modalAddDay3');
            const day4 = formElement.querySelector('#addDay4, #modalAddDay4');
            const day5 = formElement.querySelector('#addDay5, #modalAddDay5');
            if (day1) day1.checked = true;
            if (day2) day2.checked = true;
            if (day3) day3.checked = true;
            if (day4) day4.checked = true;
            if (day5) day5.checked = true;
            
            // Reload page after adding employee or bulk schedule changes to show updated data
            if (formElement.id === 'inlineBulkForm' || formElement.id === 'inlineAddEmployeeForm') {
                setTimeout(() => {
                    // Force complete page reload to show changes
                    window.location.href = window.location.pathname + '?tab=' + currentTab + '&t=' + new Date().getTime();
                    window.location.reload(true);
                }, 1500); // Wait 1.5 seconds to show success message
                return; // Don't restore button since we're reloading
            }
        }
        
        // Restore button
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.innerHTML = originalText;
        }
    })
    .catch(error => {
        showNotification('❌ An error occurred. Please try again.', 'error');
        
        // Restore button
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.innerHTML = originalText;
        }
    });
    
    return false; // Prevent default form submission
}

// Show/hide return to top button based on scroll position
window.addEventListener('scroll', function() {
    const returnToTopBtn = document.getElementById('returnToTop');
    if (returnToTopBtn) {
        if (window.pageYOffset > 300) {
            returnToTopBtn.style.display = 'block';
        } else {
            returnToTopBtn.style.display = 'none';
        }
    }
});

// Prefill employee form if coming from user management
document.addEventListener('DOMContentLoaded', function() {
    // Check if we have prefill data in sessionStorage
    const userId = sessionStorage.getItem('prefill_employee_user_id');
    const userName = sessionStorage.getItem('prefill_employee_name');
    const userEmail = sessionStorage.getItem('prefill_employee_email');
    const userTeam = sessionStorage.getItem('prefill_employee_team');
    
    if (userId && userName) {
        
        // Fill in the form fields
        const nameField = document.getElementById('addEmpName');
        const emailField = document.getElementById('addEmpEmail');
        const teamField = document.getElementById('addEmpTeam');
        const linkUserIdField = document.getElementById('linkUserId');
        
        if (nameField) nameField.value = userName;
        if (emailField) emailField.value = userEmail || '';
        if (linkUserIdField) linkUserIdField.value = userId;
        
        // Set team if not "all"
        if (teamField && userTeam && userTeam !== 'all') {
            teamField.value = userTeam;
        }
        
        // Clear sessionStorage after using
        sessionStorage.removeItem('prefill_employee_user_id');
        sessionStorage.removeItem('prefill_employee_name');
        sessionStorage.removeItem('prefill_employee_email');
        sessionStorage.removeItem('prefill_employee_team');
        
        // Show a notification
        showNotification('📋 Creating employee record for ' + userName, 'info');
        
        // Scroll to the form
        const addEmployeeSection = document.getElementById('add-employee');
        if (addEmployeeSection) {
            addEmployeeSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
});

// Export Profile to JSON
function exportProfileToJson(profileData) {
    try {
        // Create a clean profile object
        const profile = {
            exported_at: new Date().toISOString(),
            user_account: {
                id: profileData.user.id,
                username: profileData.user.username,
                email: profileData.user.email,
                full_name: profileData.user.full_name,
                role: profileData.user.role,
                active: profileData.user.active,
                auth_method: profileData.user.auth_method || 'password',
                created_at: profileData.user.created_at || null,
                last_login: profileData.user.last_login || null,
                profile_photo: profileData.user.profile_photo || null,
                google_profile_photo: profileData.user.google_profile_photo || null
            }
        };
        
        // Add employee data if available
        if (profileData.employee) {
            profile.employee_data = {
                id: profileData.employee.id,
                name: profileData.employee.name,
                team: profileData.employee.team,
                level: profileData.employee.level,
                shift: profileData.employee.shift,
                hours: profileData.employee.hours,
                email: profileData.employee.email,
                supervisor_id: profileData.employee.supervisor_id,
                schedule_access: profileData.employee.schedule_access,
                skills: profileData.employee.skills,
                schedule: profileData.employee.schedule || {}
            };
        }
        
        // Convert to JSON string with formatting
        const jsonString = JSON.stringify(profile, null, 2);
        
        // Create download blob
        const blob = new Blob([jsonString], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        
        // Create download link
        const a = document.createElement('a');
        a.href = url;
        a.download = `profile_${profileData.user.username}_${new Date().toISOString().split('T')[0]}.json`;
        
        // Trigger download
        document.body.appendChild(a);
        a.click();
        
        // Cleanup
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        // Show success message
        showNotification('✅ Profile exported successfully!', 'success');
        
    } catch (error) {
        showNotification('❌ Error exporting profile. Please try again.', 'error');
    }
}

// Export Profile by Email - For Bulk Schedule Tab
function exportProfileByEmail() {
    const emailInput = document.getElementById('exportEmailInput');
    const resultDiv = document.getElementById('exportResultMessage');
    const email = emailInput.value.trim();
    
    // Clear previous results
    resultDiv.style.display = 'none';
    resultDiv.className = '';
    resultDiv.innerHTML = '';
    
    // Validate email
    if (!email) {
        showExportResult('error', '❌ Please enter an email address.');
        return;
    }
    
    // Simple email validation
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        showExportResult('error', '❌ Please enter a valid email address.');
        return;
    }
    
    // Get PHP data embedded in page
    const usersData = <?php echo json_encode($users); ?>;
    const employeesData = <?php echo json_encode($employees); ?>;
    
    // Find user by email
    const user = usersData.find(u => u.email && u.email.toLowerCase() === email.toLowerCase());
    
    if (!user) {
        showExportResult('error', `❌ No user found with email: ${email}`);
        return;
    }
    
    // Find linked employee
    let employee = null;
    if (user.employee_id) {
        employee = employeesData.find(e => e.id === user.employee_id);
    } else {
        // Try to match by name
        employee = employeesData.find(e => 
            e.name && user.full_name && 
            e.name.toLowerCase().trim() === user.full_name.toLowerCase().trim()
        );
    }
    
    try {
        // Create profile object
        const profile = {
            exported_at: new Date().toISOString(),
            exported_by_email: email,
            user_account: {
                id: user.id,
                username: user.username,
                email: user.email,
                full_name: user.full_name,
                role: user.role,
                active: user.active,
                auth_method: user.auth_method || 'password',
                created_at: user.created_at || null,
                last_login: user.last_login || null,
                profile_photo: user.profile_photo || null,
                google_profile_photo: user.google_profile_photo || null
            }
        };
        
        // Add employee data if found
        if (employee) {
            profile.employee_data = {
                id: employee.id,
                name: employee.name,
                team: employee.team,
                level: employee.level,
                shift: employee.shift,
                hours: employee.hours,
                email: employee.email,
                supervisor_id: employee.supervisor_id,
                schedule_access: employee.schedule_access,
                skills: employee.skills,
                schedule: employee.schedule || {}
            };
        }
        
        // Convert to JSON
        const jsonString = JSON.stringify(profile, null, 2);
        
        // Create download
        const blob = new Blob([jsonString], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        
        // Create filename from email (sanitize)
        const emailPart = email.split('@')[0].replace(/[^a-z0-9]/gi, '_');
        const datePart = new Date().toISOString().split('T')[0];
        a.download = `profile_${emailPart}_${datePart}.json`;
        
        // Trigger download
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        // Show success
        showExportResult('success', `✅ Profile exported successfully for ${user.full_name} (${email})`);
        
        // Clear input
        emailInput.value = '';
        
    } catch (error) {
        showExportResult('error', '❌ Error exporting profile. Please try again.');
    }
}

// Helper function to show export results
function showExportResult(type, message) {
    const resultDiv = document.getElementById('exportResultMessage');
    resultDiv.style.display = 'block';
    
    if (type === 'success') {
        resultDiv.style.background = '#d1fae5';
        resultDiv.style.border = '1px solid #10b981';
        resultDiv.style.color = '#065f46';
    } else {
        resultDiv.style.background = '#fee2e2';
        resultDiv.style.border = '1px solid #dc2626';
        resultDiv.style.color = '#991b1b';
    }
    
    resultDiv.innerHTML = message;
}


</script>

        </div><!-- /.app-main -->
        </div><!-- /.app-body -->

<script>
// Toggle time/retention fields based on backup frequency selector
function toggleBackupScheduleFields() {
    var freq = document.getElementById('bsFreq');
    if (!freq) return;
    var disabled = freq.value === 'disabled';
    var timeWrap = document.getElementById('bsTimeWrap');
    var keepWrap = document.getElementById('bsKeepWrap');
    if (timeWrap) timeWrap.style.opacity = disabled ? '0.4' : '1';
    if (keepWrap) keepWrap.style.opacity = disabled ? '0.4' : '1';
    if (timeWrap) Array.from(timeWrap.querySelectorAll('select')).forEach(function(el){ el.disabled = disabled; });
    if (keepWrap) { var inp = keepWrap.querySelector('input'); if (inp) inp.disabled = disabled; }
}
document.addEventListener('DOMContentLoaded', function() { toggleBackupScheduleFields(); });
</script>

<!-- ===== MOBILE PURPOSE-BUILT LAYOUT ===== -->
<!-- Fixed bottom navigation bar (shown only on mobile) -->
<nav id="mobileBottomNav">
    <button type="button" data-tab="schedule" onclick="showTab('schedule')">
        <span class="mbn-icon">📅</span><span>Schedule</span>
    </button>
    <button type="button" data-tab="heatmap" onclick="showTab('heatmap')">
        <span class="mbn-icon">📊</span><span>Heatmap</span>
    </button>
    <button type="button" data-tab="bulk-schedule" onclick="showTab('bulk-schedule')">
        <span class="mbn-icon">📋</span><span>Bulk</span>
    </button>
    <button type="button" data-tab="settings" onclick="showTab('settings')">
        <span class="mbn-icon">⚙️</span><span>Settings</span>
    </button>
    <button type="button" data-tab="manual" onclick="showTab('manual')">
        <span class="mbn-icon">📖</span><span>Help</span>
    </button>
</nav>

<script>
/* ============================================================
   MOBILE PURPOSE-BUILT LAYOUT ENGINE
   Strategy: inject CSS via JS appended LAST to <head>, so it
   always wins over dynamically-injected theme CSS.
   ============================================================ */
(function() {
    'use strict';

    var IS_MOBILE = window.innerWidth <= 900;
    if (!IS_MOBILE) return;

    document.documentElement.classList.add('is-mobile');

    /* PHP-injected current filter state */
    var MOBILE_MONTH  = '<?php echo addslashes($currentYear . '-' . $currentMonth); ?>';
    var MOBILE_TEAM   = '<?php echo addslashes(count($teamFilter) === 1 ? $teamFilter[0] : 'all'); ?>';
    var MOBILE_SHIFT  = '<?php echo addslashes($shiftFilter ?? 'all'); ?>';
    var MOBILE_ACTIVE = '<?php echo addslashes(str_replace('_', '-', $activeTab ?? 'schedule')); ?>';

    /* ── CSS ── */
    var mobileStyleEl = null;
    var _reinjecting  = false;

    function getMobileCSS() {
        return [
            /* === Reset desktop layout === */
            'html.is-mobile #appSidebar{display:none!important}',
            'html.is-mobile .app-body{display:block!important;min-height:0!important}',
            'html.is-mobile .app-main{width:100%!important;max-width:100vw!important;padding:0!important;margin:0!important;padding-bottom:72px!important;overflow-x:hidden!important}',
            'html.is-mobile .container.with-sidebar{padding:0!important;max-width:100vw!important;overflow-x:hidden!important}',
            /* Compact header */
            'html.is-mobile .header{padding:10px 14px!important;min-height:unset!important}',
            'html.is-mobile .header h1{font-size:17px!important;margin:0!important;line-height:1.2!important}',
            'html.is-mobile .header p{font-size:11px!important;margin:0!important;opacity:0.85}',
            'html.is-mobile .header-clock{display:none!important}',
            'html.is-mobile .motd-banner{margin:0!important;border-radius:0!important}',
            /* Hide desktop controls + sidebar nav-tabs */
            'html.is-mobile .controls{display:none!important}',
            'html.is-mobile .team-filter-section{display:none!important}',
            'html.is-mobile .nav-tabs{display:none!important}',
            /* === Mobile filter strip (sticky) === */
            '#mobileFilterStrip{display:flex!important;gap:8px;padding:8px 10px;background:var(--card-bg,#fff);border-bottom:1px solid var(--border-color,#e2e8f0);position:sticky;top:0;z-index:200;box-sizing:border-box;width:100%;align-items:center}',
            '#mobileSearch{flex:1;min-width:0;padding:9px 12px;border:1px solid var(--border-color,#ccc);border-radius:8px;font-size:14px;background:var(--card-bg,#fff);color:var(--text-color,#333)!important;outline:none;box-sizing:border-box}',
            '#mobileFilterToggle{padding:9px 12px;border:1px solid var(--border-color,#ccc);border-radius:8px;background:var(--card-bg,#fff)!important;color:var(--text-color,#333)!important;font-size:14px;cursor:pointer;white-space:nowrap;flex-shrink:0;line-height:1;box-shadow:none!important}',
            '#mobileFilterToggle.mft-active{background:var(--primary-color,#3b82f6)!important;color:#fff!important;border-color:var(--primary-color,#3b82f6)!important}',
            /* === Filter drawer === */
            '#mobileFilterDrawer{display:none;padding:12px 14px;background:var(--card-bg,#fff);border-bottom:2px solid var(--primary-color,#3b82f6);box-sizing:border-box;width:100%}',
            '#mobileFilterDrawer.mfd-open{display:block!important}',
            '.mfd-row{display:flex;align-items:center;gap:8px;margin-bottom:10px}',
            '.mfd-lbl{width:56px;font-size:13px;font-weight:600;color:var(--text-color,#333)!important;flex-shrink:0}',
            '.mfd-sel{flex:1;padding:7px 10px;border:1px solid var(--border-color,#ccc);border-radius:6px;font-size:13px;background:var(--card-bg,#fff);color:var(--text-color,#333);box-sizing:border-box}',
            '.mfd-actions{display:flex;gap:8px;margin-top:4px}',
            '.mfd-apply{flex:1;padding:10px;background:var(--primary-color,#3b82f6)!important;color:#fff!important;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}',
            '.mfd-clear{padding:10px 16px;background:transparent!important;color:var(--text-color,#555)!important;border:1px solid var(--border-color,#ccc);border-radius:8px;font-size:14px;cursor:pointer;box-shadow:none!important}',
            /* === Schedule table — horizontal scroll + sticky first col === */
            'html.is-mobile .schedule-wrapper{overflow-x:auto!important;-webkit-overflow-scrolling:touch!important}',
            'html.is-mobile .schedule-table{font-size:11px!important;border-collapse:collapse!important}',
            'html.is-mobile .schedule-table th,html.is-mobile .schedule-table td{padding:3px 4px!important;min-width:26px!important;white-space:nowrap!important}',
            'html.is-mobile .schedule-table td.employee-name{min-width:110px!important;max-width:140px!important;overflow:hidden!important;text-overflow:ellipsis!important;position:sticky!important;left:0!important;z-index:3!important}',
            'html.is-mobile .schedule-table thead th:first-child{position:sticky!important;left:0!important;z-index:4!important}',
            /* === Stats section compact === */
            'html.is-mobile .stat-item{max-width:unset!important;width:calc(100% - 16px)!important;margin:8px!important;box-sizing:border-box!important}',
            'html.is-mobile .schedule-tab-inner>div:first-child{flex-wrap:wrap!important}',
            /* === Tab content padding === */
            'html.is-mobile .tab-content{padding:0!important}',
            'html.is-mobile .tab-content.active{display:block!important}',
            /* === Bottom nav === */
            '#mobileBottomNav{display:flex!important;position:fixed;bottom:0;left:0;right:0;background:var(--card-bg,#fff);border-top:1px solid var(--border-color,#e2e8f0);z-index:9999;box-shadow:0 -2px 14px rgba(0,0,0,0.13);height:60px}',
            '#mobileBottomNav button{flex:1;display:flex!important;flex-direction:column!important;align-items:center!important;justify-content:center!important;padding:6px 2px 8px!important;background:transparent!important;border:none!important;color:var(--text-muted,#888)!important;font-size:10px;cursor:pointer;gap:2px;border-radius:0!important;box-shadow:none!important;min-width:0;border-top:2px solid transparent!important}',
            '#mobileBottomNav button .mbn-icon{font-size:18px;line-height:1;display:block}',
            '#mobileBottomNav button span:not(.mbn-icon){display:block;font-size:10px;line-height:1.2}',
            '#mobileBottomNav button.mbn-active{color:var(--primary-color,#3b82f6)!important;border-top-color:var(--primary-color,#3b82f6)!important}',
            /* Employee hover card above nav */
            'html.is-mobile .employee-hover-card{bottom:68px!important;top:auto!important}',
        ].join('\n');
    }

    function injectMobileCSS() {
        if (!mobileStyleEl) {
            mobileStyleEl = document.createElement('style');
            mobileStyleEl.id = 'mobilePurposeCSS';
        }
        mobileStyleEl.textContent = getMobileCSS();
        document.head.appendChild(mobileStyleEl); /* append = move to last if already in DOM */
    }

    /* Stay last: whenever any new node is added to head, move our style to end */
    var headObs = new MutationObserver(function() {
        if (_reinjecting || !mobileStyleEl) return;
        var last = document.head.lastElementChild;
        if (last !== mobileStyleEl) {
            _reinjecting = true;
            document.head.appendChild(mobileStyleEl);
            _reinjecting = false;
        }
    });
    headObs.observe(document.head, { childList: true });

    /* Inject immediately (pre-DOMContentLoaded to avoid flash) */
    injectMobileCSS();

    /* ── DOM BUILD ── */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', buildMobileUI);
    } else {
        buildMobileUI();
    }

    function buildMobileUI() {
        buildFilterStrip();
        buildFilterDrawer();
        activateBottomNav();
        patchShowTab();
        injectMobileCSS(); /* re-append after DOMContentLoaded so theme doesn't win */
    }

    /* ── FILTER STRIP ── */
    function buildFilterStrip() {
        if (document.getElementById('mobileFilterStrip')) return;
        var strip = document.createElement('div');
        strip.id = 'mobileFilterStrip';
        strip.innerHTML =
            '<input type="text" id="mobileSearch" placeholder="🔍 Search employees…" autocomplete="off">' +
            '<button id="mobileFilterToggle" type="button">⚙️ Filters</button>';

        var appMain = document.querySelector('.app-main');
        if (appMain) appMain.insertBefore(strip, appMain.firstChild);

        /* Wire search */
        var ms = document.getElementById('mobileSearch');
        if (ms) {
            var saved = localStorage.getItem('employeeSearchTerm') || '';
            if (saved) ms.value = saved;
            ms.addEventListener('input', function() {
                if (typeof filterEmployees === 'function') filterEmployees(this.value);
            });
        }

        /* Wire drawer toggle */
        var toggle = document.getElementById('mobileFilterToggle');
        if (toggle) {
            toggle.addEventListener('click', function() {
                var d = document.getElementById('mobileFilterDrawer');
                if (d) d.classList.toggle('mfd-open');
                toggle.classList.toggle('mft-active');
            });
        }
    }

    /* ── FILTER DRAWER ── */
    function buildFilterDrawer() {
        if (document.getElementById('mobileFilterDrawer')) return;

        /* Build month/year selects for mobile */
        var mobileParts = MOBILE_MONTH.split('-');
        var mobileYear = mobileParts[0] || '2026';
        var mobileMonthIdx = parseInt(mobileParts[1] || '0', 10);
        var mobileMonthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        var mobileMonthOpts = mobileMonthNames.map(function(n, i) {
            return '<option value="' + i + '"' + (i === mobileMonthIdx ? ' selected' : '') + '>' + n + '</option>';
        }).join('');
        var mobileYearOpts = ['2025','2026','2027'].map(function(y) {
            return '<option value="' + y + '"' + (y === mobileYear ? ' selected' : '') + '>' + y + '</option>';
        }).join('');

        var drawer = document.createElement('div');
        drawer.id = 'mobileFilterDrawer';
        drawer.innerHTML =
            '<div class="mfd-row">' +
                '<span class="mfd-lbl">Month</span>' +
                '<div style="display:flex;gap:4px;flex:1;">' +
                    '<select id="mobileMonthNameSel" class="mfd-sel" style="flex:2;">' + mobileMonthOpts + '</select>' +
                    '<select id="mobileMonthYearSel" class="mfd-sel" style="flex:1;">' + mobileYearOpts + '</select>' +
                '</div>' +
            '</div>' +
            '<div class="mfd-row">' +
                '<span class="mfd-lbl">Team</span>' +
                '<select id="mobileTeamSel" class="mfd-sel">' +
                    '<option value="all">All Teams</option>' +
                    '<option value="esg">ESG</option>' +
                    '<option value="support">Support</option>' +
                    '<option value="windows">Windows</option>' +
                    '<option value="security">Security</option>' +
                    '<option value="secops_abuse">SecOps / Abuse</option>' +
                    '<option value="migrations">Migrations</option>' +
                    '<option value="learning_development">Learning &amp; Development</option>' +
                    '<option value="Implementations">Implementations</option>' +
                    '<option value="Account Services">Account Services</option>' +
                    '<option value="Account Services Stellar">Account Services Stellar</option>' +
                '</select>' +
            '</div>' +
            '<div class="mfd-row">' +
                '<span class="mfd-lbl">Shift</span>' +
                '<select id="mobileShiftSel" class="mfd-sel">' +
                    '<option value="all">All Shifts</option>' +
                    '<option value="1">1st Shift</option>' +
                    '<option value="2">2nd Shift</option>' +
                    '<option value="3">3rd Shift</option>' +
                '</select>' +
            '</div>' +
            '<div class="mfd-actions">' +
                '<button type="button" class="mfd-apply" onclick="mobileApplyFilters()">Apply Filters</button>' +
                '<button type="button" class="mfd-clear" onclick="mobileClearFilters()">Clear</button>' +
            '</div>';

        /* Insert right after filter strip */
        var strip = document.getElementById('mobileFilterStrip');
        if (strip && strip.parentNode) {
            strip.parentNode.insertBefore(drawer, strip.nextSibling);
        } else {
            var appMain = document.querySelector('.app-main');
            if (appMain) appMain.insertBefore(drawer, appMain.firstChild);
        }

        /* Set current filter values */
        var tSel = document.getElementById('mobileTeamSel');
        var sSel = document.getElementById('mobileShiftSel');
        if (tSel) tSel.value = MOBILE_TEAM;
        if (sSel) sSel.value = MOBILE_SHIFT;
    }

    /* ── BOTTOM NAV ── */
    function activateBottomNav() {
        var nav = document.getElementById('mobileBottomNav');
        if (!nav) return;
        nav.querySelectorAll('button').forEach(function(btn) {
            btn.classList.toggle('mbn-active', btn.dataset.tab === MOBILE_ACTIVE);
        });
    }

    /* ── PATCH showTab ── */
    function patchShowTab() {
        var orig = window.showTab;
        window.showTab = function(tabName) {
            if (orig) orig.call(window, tabName);
            var norm = tabName.replace(/_/g, '-');
            document.querySelectorAll('#mobileBottomNav button').forEach(function(btn) {
                btn.classList.toggle('mbn-active', btn.dataset.tab === norm);
            });
        };
    }

    /* ── PUBLIC API ── */
    window.mobileApplyFilters = function() {
        var mName = document.getElementById('mobileMonthNameSel');
        var mYear = document.getElementById('mobileMonthYearSel');
        var month = (mName && mYear) ? (mYear.value + '-' + mName.value) : MOBILE_MONTH;
        var team  = (document.getElementById('mobileTeamSel')  || {}).value || 'all';
        var shift = (document.getElementById('mobileShiftSel') || {}).value || 'all';
        var params = new URLSearchParams();
        if (month) params.set('monthSelect', month);
        if (team  && team  !== 'all') params.append('team[]', team);
        if (shift && shift !== 'all') params.set('shift', shift);
        window.location.href = '?' + params.toString();
    };

    window.mobileClearFilters = function() {
        window.location.href = '?';
    };

})();
</script>

<!-- ── View Profile Panel Modal ──────────────────────────────────────────
     Populated entirely from window.usersData + window.employeesData so
     clicking the 👁️ button in Settings is instant (no page reload).       -->
<div id="viewProfileModal" class="modal"
     style="display:none; align-items:center; justify-content:center;"
     onclick="if(event.target===this)window.closeViewProfilePanel()">
    <div style="background:var(--card-bg,white); border-radius:16px; max-width:900px;
                width:95%; max-height:90vh; overflow-y:auto; position:relative;
                box-shadow:0 16px 48px rgba(0,0,0,0.24);">
        <button onclick="window.closeViewProfilePanel()"
                style="position:absolute;top:12px;right:14px;background:rgba(0,0,0,0.25);
                       color:white;border:none;border-radius:50%;width:32px;height:32px;
                       font-size:20px;line-height:1;cursor:pointer;z-index:10;">×</button>
        <div id="viewProfileModalBody"></div>
    </div>
</div>

<script>
(function () {
    'use strict';

    /* ── tiny HTML-escape helper ── */
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    /* ── info row helper ── */
    function infoRow(label, valueHtml, noBorder) {
        return '<div style="display:flex;justify-content:space-between;align-items:center;' +
            'padding-bottom:12px;' + (noBorder ? '' : 'border-bottom:1px solid #e9ecef;') + '">' +
            '<span style="color:var(--text-muted,#6c757d);font-weight:500;">' + label + '</span>' +
            '<span style="font-weight:600;">' + valueHtml + '</span></div>';
    }

    /* ── shift label (mirrors PHP getShiftName) ── */
    function shiftLabel(n) {
        var m = {1:'1st Shift', 2:'2nd Shift', 3:'3rd Shift'};
        return m[parseInt(n, 10)] || (n + 'th Shift');
    }

    /* ── level label (mirrors PHP getLevelName) ── */
    function levelLabel(level) {
        var m = {
            ssa:'SSA', ssa2:'SSA2', tam:'TAM', tam2:'TAM2',
            'SR. Supervisor':'SR. Supervisor', 'SR. Manager':'SR. Manager',
            manager:'Manager', 'IMP Tech':'IMP Tech', 'IMP Coordinator':'IMP Coordinator',
            l1:'L1', l2:'L2', l3:'L3',
            'SecOps T1':'SecOps T1', 'SecOps T2':'SecOps T2', 'SecOps T3':'SecOps T3',
            SecEng:'SecEng', Supervisor:'Supervisor',
            technical_writer:'Technical Writer', trainer:'Trainer', tech_coach:'Tech Coach'
        };
        return level ? (m[level] || level.toUpperCase()) : 'Not Set';
    }

    window.openViewProfilePanel = function (userId) {
        var user = (window.usersData || []).find(function (u) { return u.id == userId; });
        if (!user) {
            /* data not loaded yet — fall back to server render */
            window.location.href = '?tab=view-profile&user_id=' + userId;
            return;
        }

        /* ── find linked employee by name match ── */
        var emp = null;
        if (Array.isArray(window.employeesData) && user.full_name) {
            var needle = user.full_name.toLowerCase().trim();
            emp = window.employeesData.find(function (e) {
                return (e.name || '').toLowerCase().trim() === needle;
            }) || null;
        }

        /* ── profile photo ── */
        var initials = (user.full_name || 'U').substring(0, 2).toUpperCase();
        var photoHtml;
        if (user.photo_url) {
            photoHtml =
                '<img src="' + esc(user.photo_url) + '" alt="Photo" ' +
                'style="width:100%;height:100%;object-fit:cover;" ' +
                'onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">' +
                '<div style="display:none;width:100%;height:100%;align-items:center;' +
                'justify-content:center;font-size:38px;font-weight:bold;color:white">' + initials + '</div>';
        } else {
            photoHtml =
                '<div style="display:flex;width:100%;height:100%;align-items:center;' +
                'justify-content:center;font-size:38px;font-weight:bold;color:white">' + initials + '</div>';
        }

        /* ── auth badge ── */
        var authBadge = (user.auth_method === 'google')
            ? '<span style="background:#ea4335;color:white;padding:3px 10px;border-radius:12px;font-size:12px;">🔗 Google SSO</span>'
            : '<span style="background:#6c757d;color:white;padding:3px 10px;border-radius:12px;font-size:12px;">🔑 Password</span>';

        /* ── created date ── */
        var createdDate = 'N/A';
        if (user.created_at) {
            try {
                createdDate = new Date(user.created_at)
                    .toLocaleDateString('en-US', {year:'numeric', month:'short', day:'numeric'});
            } catch (e) {}
        }

        /* ── supervisor name ── */
        var supervisorName = 'None';
        if (emp && emp.supervisor_id && Array.isArray(window.employeesData)) {
            var sup = window.employeesData.find(function (e) { return e.id == emp.supervisor_id; });
            if (sup) supervisorName = sup.name;
        }

        /* ── working days ── */
        var DAY_NAMES = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        var workingDaysHtml = '<div style="display:flex;flex-wrap:wrap;gap:6px;">';
        if (emp && Array.isArray(emp.schedule)) {
            emp.schedule.forEach(function (active, i) {
                workingDaysHtml +=
                    '<span style="padding:4px 10px;border-radius:12px;font-size:12px;font-weight:600;' +
                    (active
                        ? 'background:#d4edda;color:#155724;'
                        : 'background:#e9ecef;color:#adb5bd;') + '">' +
                    DAY_NAMES[i] + '</span>';
            });
        }
        workingDaysHtml += '</div>';

        /* ── employee info block ── */
        var empBlock = '';
        if (emp) {
            empBlock =
                '<div style="background:#f8f9fa;border-radius:12px;padding:24px;">' +
                '<h3 style="margin:0 0 20px;color:var(--success-color,#28a745);font-size:18px;' +
                'display:flex;align-items:center;gap:10px;"><span>👤</span> Employee Information</h3>' +
                '<div style="display:flex;flex-direction:column;gap:16px;">' +
                infoRow('🕒 Shift',     esc(shiftLabel(emp.shift))) +
                infoRow('⏰ Hours',     esc(emp.hours  || 'N/A')) +
                infoRow('🏷️ Level',    esc(levelLabel(emp.level))) +
                infoRow('👑 Reports To', esc(supervisorName)) +
                '<div style="padding-bottom:12px;">' +
                '<div style="color:var(--text-muted,#6c757d);font-weight:500;margin-bottom:8px;">📅 Working Days</div>' +
                workingDaysHtml + '</div>' +
                '</div></div>';
        }

        /* ── assemble modal body ── */
        var html =
            '<div class="view-profile-header" style="padding:24px;color:white;border-radius:16px 16px 0 0;">' +
            '<div style="display:flex;align-items:center;gap:20px;">' +
            '<div style="width:90px;height:90px;border-radius:50%;overflow:hidden;' +
            'border:3px solid rgba(255,255,255,0.3);background:rgba(255,255,255,0.2);flex-shrink:0;">' +
            photoHtml + '</div>' +
            '<div>' +
            '<h2 style="margin:0 0 10px;font-size:26px;color:white !important;">' + esc(user.full_name || 'Unknown') + '</h2>' +
            '<div style="display:flex;gap:8px;flex-wrap:wrap;">' +
            '<span style="background:rgba(255,255,255,0.25);padding:4px 12px;border-radius:16px;' +
            'font-size:13px;font-weight:600;text-transform:uppercase;">' + esc(user.role || 'Employee') + '</span>' +
            (emp ? '<span style="background:rgba(255,255,255,0.15);padding:4px 12px;border-radius:16px;font-size:13px;">' + esc((emp.team || '').toUpperCase()) + '</span>' : '') +
            '<span style="background:' + (user.active ? 'rgba(46,204,113,0.3)' : 'rgba(231,76,60,0.3)') +
            ';padding:4px 12px;border-radius:16px;font-size:13px;">' +
            (user.active ? '✅ Active' : '❌ Inactive') + '</span>' +
            '</div></div></div></div>' +

            '<div style="padding:24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;">' +

            /* account info */
            '<div style="background:#f8f9fa;border-radius:12px;padding:24px;">' +
            '<h3 style="margin:0 0 20px;color:var(--primary-color,#667eea);font-size:18px;' +
            'display:flex;align-items:center;gap:10px;"><span>🔐</span> Account Information</h3>' +
            '<div style="display:flex;flex-direction:column;gap:16px;">' +
            infoRow('Username',    esc(user.username || 'N/A')) +
            infoRow('Email',       esc(user.email    || 'N/A')) +
            '<div style="display:flex;justify-content:space-between;align-items:center;' +
            'padding-bottom:12px;border-bottom:1px solid #e9ecef;">' +
            '<span style="color:var(--text-muted,#6c757d);font-weight:500;">Auth Method</span>' +
            '<span>' + authBadge + '</span></div>' +
            infoRow('Team',    esc((user.team || 'N/A').toUpperCase())) +
            infoRow('Created', createdDate, true) +
            '</div></div>' +

            empBlock +
            '</div>';

        var body = document.getElementById('viewProfileModalBody');
        if (body) body.innerHTML = html;

        var modal = document.getElementById('viewProfileModal');
        if (modal) { modal.style.display = 'flex'; modal.classList.add('show'); }
    };

    window.closeViewProfilePanel = function () {
        var modal = document.getElementById('viewProfileModal');
        if (modal) { modal.style.display = 'none'; modal.classList.remove('show'); }
    };

    /* close on Escape key */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') window.closeViewProfilePanel();
    });
}());
</script>

</body>
</html>