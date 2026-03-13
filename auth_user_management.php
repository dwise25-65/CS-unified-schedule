<?php
// CRITICAL: Suppress ALL PHP warnings/notices/errors from displaying on page
@ini_set('display_errors', 0);
@ini_set('display_startup_errors', 0);
@error_reporting(0);

// Errors will still be logged to error_log for debugging
error_reporting(E_ALL);
ini_set('log_errors', 1);

require_once __DIR__ . '/google_auth_config.php';


// auth_user_management.php
// User Authentication and Management System with Profile Management, Photos, and Enhanced Search

// Enhanced session configuration for better browser compatibility (including Opera GX)
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_lifetime', 0); // Session cookies

// Start session with enhanced settings
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 0,
        'cookie_httponly' => true,
        'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true
    ]);
}

// Configuration
define('USERS_FILE', __DIR__ . '/users_data.json');
define('PROFILE_PHOTOS_DIR', __DIR__ . '/profile_photos');
define('SESSION_TIMEOUT_MINUTES', 30); // Session timeout in minutes

// Ensure profile photos directory exists
if (!file_exists(PROFILE_PHOTOS_DIR)) {
    mkdir(PROFILE_PHOTOS_DIR, 0755, true);
}

// Simple AJAX handler for session activity (must be very early)
if (isset($_POST['action']) && $_POST['action'] === 'keep_alive' && isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
    header('Content-Type: application/json');
    echo '{"status":"success"}';
    exit;
}


// Global variables
$users = [];
$nextUserId = 1;

// User roles and permissions
$roles = [
    'admin' => [
        'name' => 'Administrator',
        'permissions' => ['view_schedule', 'edit_schedule', 'manage_employees', 'manage_users', 'manage_backups', 'view_all_teams', 'manage_all_profiles']
    ],
    'manager' => [
        'name' => 'Manager', 
        'permissions' => ['view_schedule', 'edit_schedule', 'manage_employees', 'manage_users', 'manage_backups', 'view_all_teams', 'manage_all_profiles']
    ],
    'supervisor' => [
        'name' => 'Supervisor',
        'permissions' => ['view_schedule', 'edit_schedule', 'manage_employees', 'manage_users', 'manage_backups', 'view_all_teams', 'manage_all_profiles']
    ],
    'employee' => [
        'name' => 'Employee',
        'permissions' => ['view_schedule', 'edit_own_schedule', 'view_all_teams', 'view_own']
    ]
];

// Session Management Functions
function checkSessionTimeout() {
    if (!isset($_SESSION['user_id'])) {
        return true; // Not logged in, no timeout check needed
    }
    
    // Initialize last activity if not set
    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();
        return true;
    }
    
    $timeout = SESSION_TIMEOUT_MINUTES * 60; // Convert to seconds
    if (time() - $_SESSION['last_activity'] > $timeout) {
        session_destroy();
        return false; // Timed out
    }
    
    $_SESSION['last_activity'] = time(); // Update activity
    return true;
}

// Check if user is logged in
function requireLogin() {
    if (!checkSessionTimeout()) {
        header('Location: ?action=login&timeout=1');
        exit;
    }
    if (!isset($_SESSION['user_id'])) {
        header('Location: ?action=login');
        exit;
    }
}

// Check if user has permission
function hasPermission($permission) {
    global $roles;
    if (!isset($_SESSION['user_role'])) return false;
    return in_array($permission, $roles[$_SESSION['user_role']]['permissions'] ?? []);
}

// Get current user info
function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) return null;

    // Request-level cache: one DB query per request no matter how many times called
    static $_cuCache    = null;
    static $_cuCacheKey = null;
    $cacheKey = $_SESSION['user_id'] . ':' . ($_SESSION['auth_method_used'] ?? '');
    if ($_cuCache !== null && $_cuCacheKey === $cacheKey) {
        return $_cuCache;
    }

    // Load from database instead of JSON
    try {
        if (!file_exists(__DIR__ . '/Database.php')) {
            // Fallback to old method if Database.php doesn't exist
            global $users;
            foreach ($users as $user) {
                if ($user['id'] == $_SESSION['user_id']) {
                    $_cuCache = $user; $_cuCacheKey = $cacheKey;
                    return $user;
                }
            }
            return null;
        }

        require_once __DIR__ . '/Database.php';
        $db = Database::getInstance();

        // FIXED: Support BOTH Google AND password login for all users
        // If user logged in with Google, trust the email in session over user_id
        if (isset($_SESSION['auth_method_used']) && $_SESSION['auth_method_used'] === 'google') {
            $sessionEmail = $_SESSION['user_email'] ?? $_SESSION['email'] ?? null;

            if ($sessionEmail) {
                // Get user by EMAIL (more reliable for Google login)
                // REMOVED auth_method filter - users can use EITHER method
                $user = $db->fetchOne(
                    "SELECT u.*, e.name as full_name
                     FROM users u
                     LEFT JOIN employees e ON u.email = e.email
                     WHERE u.email = ?",
                    [$sessionEmail]
                );

                if ($user) {
                    // Update session with correct user_id if it was wrong
                    if ($_SESSION['user_id'] != $user['id']) {
                        $_SESSION['user_id'] = $user['id'];
                        error_log("Fixed Google login session: updated user_id from {$_SESSION['user_id']} to {$user['id']} for email {$sessionEmail}");
                        $cacheKey = $user['id'] . ':google'; // refresh key after fix
                    }

                    if (empty($user['full_name'])) {
                        $user['full_name'] = $user['username'];
                    }

                    $_cuCache = $user; $_cuCacheKey = $cacheKey;
                    return $user;
                }
            }
        }

        // Default: Get user by ID from database
        $user = $db->fetchOne(
            "SELECT u.*, e.name as full_name
             FROM users u
             LEFT JOIN employees e ON u.email = e.email
             WHERE u.id = ?",
            [$_SESSION['user_id']]
        );

        // If no full_name from employees, use username
        if ($user && empty($user['full_name'])) {
            $user['full_name'] = $user['username'];
        }

        $_cuCache = $user; $_cuCacheKey = $cacheKey;
        return $user;

    } catch (Exception $e) {
        error_log("getCurrentUser database error: " . $e->getMessage());
        // Fallback to old method on error
        global $users;
        foreach ($users as $user) {
            if ($user['id'] == $_SESSION['user_id']) {
                $_cuCache = $user; $_cuCacheKey = $cacheKey;
                return $user;
            }
        }
        return null;
    }
}

// Get user by ID
function getUserById($userId) {
    // Try database first
    try {
        if (file_exists(__DIR__ . '/Database.php')) {
            require_once __DIR__ . '/Database.php';
            $db = Database::getInstance();
            
            $user = $db->fetchOne(
                "SELECT u.*, e.name as full_name 
                 FROM users u 
                 LEFT JOIN employees e ON u.email = e.email 
                 WHERE u.id = ?",
                [$userId]
            );
            
            if ($user && empty($user['full_name'])) {
                $user['full_name'] = $user['username'];
            }
            
            return $user;
        }
    } catch (Exception $e) {
        error_log("getUserById database error: " . $e->getMessage());
    }
    
    // Fallback to global array
    global $users;
    foreach ($users as $user) {
        if ($user['id'] == $userId) {
            return $user;
        }
    }
    return null;
}

// Load users data
function loadUsers() {
    global $users, $nextUserId;

    // Guard: only run once per request (initializeAuth + handleAuthentication both call this)
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    // Try to load from database first
    try {
        if (file_exists(__DIR__ . '/Database.php')) {
            require_once __DIR__ . '/Database.php';
            $db = Database::getInstance();

            // Load users from database, joining employees to get real full name and team
            $users = $db->fetchAll(
                "SELECT u.*, COALESCE(e.name, u.username) as full_name,
                        e.team as emp_team
                 FROM users u
                 LEFT JOIN employees e ON e.email = u.email
                 ORDER BY u.id"
            );
            // Resolve team: use employee's team when user team is blank or 'all'
            foreach ($users as &$u) {
                if (empty($u['team']) || $u['team'] === 'all') {
                    $u['team'] = $u['emp_team'] ?? '';
                }
                unset($u['emp_team']);
            }
            unset($u);

            // NOTE: SELECT MAX(id) removed from here — only needed when creating users.
            // $nextUserId is set lazily via getNextUserId() to avoid a query on every page load.
            $nextUserId = null; // will be resolved on demand

            return; // Successfully loaded from database
        }
    } catch (Exception $e) {
        error_log("loadUsers database error, falling back to JSON: " . $e->getMessage());
    }
    
    // Fallback to JSON file
    if (file_exists(USERS_FILE)) {
        $data = json_decode(file_get_contents(USERS_FILE), true);
        if ($data && isset($data['users'])) {
            $users = $data['users'];
            $nextUserId = $data['nextUserId'] ?? 1;
        }
    }
    
    // Create default admin user if no users exist
    if (empty($users)) {
        $users[] = [
            'id' => $nextUserId++,
            'username' => 'admin',
            'password' => password_hash('admin123', PASSWORD_DEFAULT),
            'email' => 'admin@company.com',
            'full_name' => 'System Administrator',
            'role' => 'admin',
            'team' => 'all',
            'created_at' => date('c'),
            'active' => true,
            'profile_photo' => null
        ];
        saveUsers();
    }
    
    // Migrate existing users to include profile_photo field
    foreach ($users as &$user) {
        if (!isset($user['profile_photo'])) {
            $user['profile_photo'] = null;
        }
    }
}

// Lazily resolve $nextUserId only when actually creating a user (not on every page load)
function getNextUserId() {
    global $nextUserId;
    if ($nextUserId !== null) return $nextUserId;
    try {
        require_once __DIR__ . '/Database.php';
        $db    = Database::getInstance();
        $maxId = $db->fetchOne("SELECT MAX(id) as max_id FROM users");
        $nextUserId = ($maxId['max_id'] ?? 0) + 1;
    } catch (Exception $e) {
        $nextUserId = 1;
    }
    return $nextUserId;
}

// Save users data — writes to DB; JSON file is no longer used for persistence.
function saveUsers() {
    global $users, $nextUserId;

    try {
        require_once __DIR__ . '/Database.php';
        $db  = Database::getInstance();
        $pdo = $db->getConnection();

        foreach ($users as $user) {
            if (empty($user['id'])) continue;

            // Determine password hash column (support both legacy 'password' and 'password_hash')
            $hash = $user['password_hash'] ?? $user['password'] ?? '';

            // Upsert: update if exists, otherwise insert
            $exists = $pdo->prepare("SELECT id FROM users WHERE id = ?")->execute([$user['id']]);
            $row    = $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $row->execute([$user['id']]);
            $exists = $row->fetch();

            if ($exists) {
                $pdo->prepare(
                    "UPDATE users SET
                        username    = ?,
                        email       = ?,
                        role        = ?,
                        team        = ?,
                        active      = ?,
                        auth_method = ?,
                        password_hash = ?,
                        updated_at  = NOW()
                     WHERE id = ?"
                )->execute([
                    $user['username']   ?? ($user['full_name'] ?? ''),
                    $user['email']      ?? '',
                    $user['role']       ?? 'employee',
                    $user['team']       ?? '',
                    isset($user['active']) ? (int)$user['active'] : 1,
                    $user['auth_method'] ?? 'google',
                    $hash,
                    $user['id']
                ]);
            } else {
                $pdo->prepare(
                    "INSERT INTO users (id, username, email, role, team, active, auth_method, password_hash, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
                )->execute([
                    $user['id'],
                    $user['username']   ?? ($user['full_name'] ?? ''),
                    $user['email']      ?? '',
                    $user['role']       ?? 'employee',
                    $user['team']       ?? '',
                    isset($user['active']) ? (int)$user['active'] : 1,
                    $user['auth_method'] ?? 'google',
                    $hash,
                ]);
            }
        }
        return true;
    } catch (Exception $e) {
        error_log("saveUsers DB error: " . $e->getMessage());
        // Fallback: write JSON so data is not completely lost
        $data = ['users' => $users, 'nextUserId' => $nextUserId, 'savedDate' => date('c')];
        return file_put_contents(USERS_FILE, json_encode($data, JSON_PRETTY_PRINT)) !== false;
    }
}

// Photo Management Functions
function uploadProfilePhoto($userId, $photoFile) {
    if (!$photoFile || $photoFile['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'No photo uploaded or upload error.'];
    }
    
    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $fileType = $photoFile['type'];
    
    if (!in_array($fileType, $allowedTypes)) {
        return ['success' => false, 'message' => 'Invalid file type. Please upload JPG, PNG, GIF, or WebP images only.'];
    }
    
    // Validate file size (max 5MB)
    $maxSize = 5 * 1024 * 1024;
    if ($photoFile['size'] > $maxSize) {
        return ['success' => false, 'message' => 'File too large. Maximum size is 5MB.'];
    }
    
    // Generate unique filename
    $extension = pathinfo($photoFile['name'], PATHINFO_EXTENSION);
    $filename = 'profile_' . $userId . '_' . time() . '.' . $extension;
    $targetPath = PROFILE_PHOTOS_DIR . '/' . $filename;
    
    // Create directory if it doesn't exist
    if (!file_exists(PROFILE_PHOTOS_DIR)) {
        mkdir(PROFILE_PHOTOS_DIR, 0755, true);
    }
    
    // Move uploaded file
    if (move_uploaded_file($photoFile['tmp_name'], $targetPath)) {
        // Remove old photo if exists
        $user = getUserById($userId);
        if ($user && $user['profile_photo']) {
            $oldPhotoPath = PROFILE_PHOTOS_DIR . '/' . $user['profile_photo'];
            if (file_exists($oldPhotoPath)) {
                unlink($oldPhotoPath);
            }
        }
        
        return ['success' => true, 'filename' => $filename];
    }
    
    return ['success' => false, 'message' => 'Failed to save uploaded photo.'];
}

function deleteProfilePhoto($userId) {
    $user = getUserById($userId);
    if ($user && $user['profile_photo']) {
        $photoPath = PROFILE_PHOTOS_DIR . '/' . $user['profile_photo'];
        if (file_exists($photoPath)) {
            unlink($photoPath);
        }
        return true;
    }
    return false;
}

function getProfilePhotoUrl($user) {
    if (!$user) {
        return null;
    }

    // 1. Locally-uploaded file stored in profile_photo (never a URL) — always valid if file exists
    if (isset($user['profile_photo']) && $user['profile_photo']) {
        $photo = $user['profile_photo'];
        if (strpos($photo, 'http://') !== 0 && strpos($photo, 'https://') !== 0) {
            $photoPath = PROFILE_PHOTOS_DIR . '/' . $photo;
            if (@file_exists($photoPath)) {
                return 'profile_photos/' . $photo;
            }
        }
    }

    // 2. profile_photo_url — refreshed on every Google login
    if (isset($user['profile_photo_url']) && $user['profile_photo_url']) {
        $url = $user['profile_photo_url'];
        if (strpos($url, 'https://') === 0) {
            return $url;
        }
    }

    // 3. google_profile_photo — the ORIGINAL field from JSON storage; most users have data here
    if (isset($user['google_profile_photo']) && $user['google_profile_photo']) {
        $url = $user['google_profile_photo'];
        if (strpos($url, 'https://') === 0) {
            return $url;
        }
    }

    // 4. google_picture — added during DB migration work
    if (isset($user['google_picture']) && $user['google_picture']) {
        $googlePic = $user['google_picture'];
        if (strpos($googlePic, 'https://') === 0) {
            return $googlePic;
        }
    }

    // 5. profile_photo as a URL — last resort
    if (isset($user['profile_photo']) && $user['profile_photo']) {
        $photo = $user['profile_photo'];
        if (strpos($photo, 'https://') === 0) {
            return $photo;
        }
    }

    return null;
}

// Helper function to add employee from user management context
// Inserts directly into the database; schedule_data.json is no longer used.
function addEmployee($employeeData) {
    // Prefer the primary createEmployeeRecord function if available (index.php context)
    if (function_exists('createEmployeeRecord')) {
        return createEmployeeRecord($employeeData);
    }

    // Direct DB insert (runs when called outside index.php, e.g. during SSO signup)
    try {
        require_once __DIR__ . '/Database.php';
        $db = Database::getInstance();

        $schedule  = $employeeData['schedule'] ?? [0,1,1,1,1,1,0];
        $weeklyStr = is_array($schedule) ? implode(',', $schedule) : (string)$schedule;
        $skills    = json_encode($employeeData['skills'] ?? ['mh'=>false,'ma'=>false,'win'=>false]);

        $newId = $db->insert(
            'INSERT INTO employees
               (name, email, team, level, shift, hours, weekly_schedule, skills,
                user_id, hire_date, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [
                $employeeData['name']         ?? '',
                $employeeData['email']         ?? '',
                $employeeData['team']          ?? '',
                $employeeData['level']         ?? '',
                (int)($employeeData['shift']   ?? 1),
                $employeeData['hours']         ?? '',
                $weeklyStr,
                $skills,
                $employeeData['user_id']       ?? null,
                $employeeData['start_date']    ?? null,
            ]
        );

        return ['success' => true, 'id' => (int)$newId];

    } catch (Exception $e) {
        error_log('addEmployee DB error: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Check if user can edit schedules (own or all)
function canEditSchedules() {
    return hasPermission('edit_schedule') || hasPermission('edit_own_schedule');
}

// Check if user can edit their own schedule specifically
function canEditOwnSchedule() {
    return hasPermission('edit_own_schedule') || hasPermission('edit_schedule');
}

// Get schedule access message for user
function getScheduleAccessMessage() {
    $currentUser = getCurrentUser();
    if (!$currentUser) return '';
    
    $linkedEmployee = getCurrentUserEmployee();
    
    if (hasPermission('edit_schedule')) {
        if ($linkedEmployee) {
            return "✅ **Full Schedule Access:** You can edit all employee schedules and your linked employee record.";
        } else {
            return "✅ **Full Schedule Access:** You can edit all employee schedules. No personal employee record linked.";
        }
    } elseif (hasPermission('edit_own_schedule')) {
        if ($linkedEmployee) {
            return "✅ **Personal Schedule Access:** You can edit your own schedule and view others.";
        } else {
            return "✅ **Personal Schedule Access:** You can create and edit your own schedule entries.";
        }
    } else {
        return "👁️ **View Only Access:** You can view schedules but cannot make changes.";
    }
}

// Activity Log Functions (if needed for user actions)
function addUserActivityLog($action, $details = '', $targetType = 'user', $targetId = null) {
    // This function can be called from the main application if activity logging is needed
    if (function_exists('addActivityLog')) {
        addActivityLog($action, $details, $targetType, $targetId);
    }
}

// Handle Authentication
function handleAuthentication() {
    global $users, $message, $messageType;
    if (isset($_GET['code']) && !isset($_GET['action'])) {
        // Regenerate session ID before any output — needs to send a Set-Cookie header.
        session_regenerate_id(false);

        // ── Token exchange first, then render a personalised welcome screen ──
        // The browser shows its native loading indicator while the exchange runs
        // (~1-2 s). Once we have the user's name and profile photo we render a
        // short welcome screen that auto-redirects to the app.
        // handleGoogleCallback() queries the DB directly — loadUsers() not needed.
        $result = handleGoogleCallback();

        if ($result && $result['success']) {
            $_SESSION['user_id']         = $result['user']['id'];
            $_SESSION['user_role']        = $result['user']['role'];
            $_SESSION['user_name']        = $result['user']['full_name'] ?? $result['user']['username'];
            $_SESSION['user_team']        = $result['user']['team'] ?? 'all';
            $_SESSION['login_time']       = time();
            $_SESSION['last_activity']    = time();
            $_SESSION['auth_method_used'] = 'google';

            // Check if user has a schedule
            if (isset($result['has_schedule']) && $result['has_schedule'] === false) {
                $_SESSION['message']     = "Welcome! Your account has been created. Please contact your leader to set up your schedule.";
                $_SESSION['messageType'] = 'info';
                $_SESSION['no_schedule'] = true;
            }

            // Write session now so the next page load doesn't have to wait for the lock.
            session_write_close();

            // ── Build display values ──────────────────────────────────────────
            $rawName     = $result['user']['full_name'] ?? $result['user']['username'] ?? 'there';
            $displayName = htmlspecialchars($rawName, ENT_QUOTES, 'UTF-8');
            $firstName   = htmlspecialchars(explode(' ', trim($rawName))[0], ENT_QUOTES, 'UTF-8');
            $pictureUrl  = $result['user']['google_profile_photo']
                        ?? $result['user']['profile_photo_url']
                        ?? $result['user']['google_picture']
                        ?? '';
            $safePicture = htmlspecialchars($pictureUrl, ENT_QUOTES, 'UTF-8');
            $hasPicture  = !empty($pictureUrl);

            // ── Welcome screen ────────────────────────────────────────────────
            echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome, {$firstName}!</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #1a1a2e;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            color: #e0e0e0;
        }
        .wrap {
            text-align: center;
            padding: 40px;
            animation: fadeIn 0.4s ease;
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* ── Avatar with spinning ring ── */
        .avatar-ring {
            position: relative;
            width: 88px; height: 88px;
            margin: 0 auto 28px;
        }
        .avatar-ring svg.ring {
            position: absolute;
            top: 0; left: 0;
            width: 88px; height: 88px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .avatar-ring img.photo {
            position: absolute;
            top: 6px; left: 6px;
            width: 76px; height: 76px;
            border-radius: 50%;
            object-fit: cover;
        }
        .avatar-ring .initials {
            position: absolute;
            top: 6px; left: 6px;
            width: 76px; height: 76px;
            border-radius: 50%;
            background: #2a3a5c;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 600;
            color: #7ba7d8;
            letter-spacing: 1px;
        }

        /* ── Fallback: plain spinner (no photo) ── */
        .spinner {
            width: 52px; height: 52px;
            border: 4px solid rgba(255,255,255,0.12);
            border-top-color: #4285f4;
            border-radius: 50%;
            animation: spin 0.75s linear infinite;
            margin: 0 auto 28px;
        }

        h2 { font-size: 22px; font-weight: 500; margin-bottom: 10px; letter-spacing: -0.3px; }
        p  { font-size: 14px; color: #888; }
    </style>
</head>
<body>
    <div class="wrap">
HTML;

            if ($hasPicture) {
                // Spinning blue arc around the profile photo
                $initial = mb_strtoupper(mb_substr(trim($rawName), 0, 1));
                echo <<<HTML
        <div class="avatar-ring">
            <!-- Spinning arc: only the top quarter is coloured -->
            <svg class="ring" viewBox="0 0 88 88" fill="none">
                <circle cx="44" cy="44" r="41" stroke="rgba(255,255,255,0.08)" stroke-width="4"/>
                <path d="M44 3 A41 41 0 0 1 85 44" stroke="#4285f4" stroke-width="4" stroke-linecap="round"/>
            </svg>
            <div class="initials" style="display:flex;">{$initial}</div>
            <img class="photo"
                 src="{$safePicture}"
                 alt="{$displayName}"
                 style="display:none;"
                 onload="this.style.display='';this.previousElementSibling.style.display='none';"
                 onerror="this.style.display='none';this.previousElementSibling.style.display='flex';">
        </div>
        <h2>Welcome back, {$firstName}!</h2>
        <p>Taking you to your schedule&hellip;</p>
HTML;
            } else {
                // No photo — fall back to the original Google-icon + spinner layout
                echo <<<HTML
        <svg style="margin-bottom:24px" width="40" height="40" viewBox="0 0 48 48">
            <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
            <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
            <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
            <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.18 1.48-4.97 2.31-8.16 2.31-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
        </svg>
        <div class="spinner"></div>
        <h2>Signing you in&hellip;</h2>
        <p>Completing your Google sign-in, please wait.</p>
HTML;
            }

            echo <<<HTML
    </div>
    <script>
        // Short pause so the user can see the welcome screen, then redirect.
        setTimeout(function() { window.location.replace("index.php"); }, 1100);
    </script>
</body>
</html>
HTML;
            exit;

        } else {
            // Google login failed — send a minimal error page that redirects back to login
            $errorMsg = urlencode($result['error'] ?? 'Google authentication failed');
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
            echo '<script>window.location.replace("?action=login&google_error=' . $errorMsg . '");</script>';
            echo '</body></html>';
            exit;
        }
    }
    
    
    if (isset($_GET['action'])) {
        $action = $_GET['action'];
        
        if ($action === 'login') {
            loadUsers();
            
            // Check for timeout message
            if (isset($_GET['timeout'])) {
                $message = "⏰ Your session has expired due to inactivity (" . SESSION_TIMEOUT_MINUTES . " minutes). Please log in again.";
                $messageType = 'warning';
            }
            
            // Check for Google error message
            if (isset($_GET['google_error'])) {
                $message = "Google Sign-In Error: " . htmlspecialchars(urldecode($_GET['google_error']));
                $messageType = 'error';
            }
            
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';

                if (empty($username) || empty($password)) {
                    $message = "Please enter both username and password.";
                    $messageType = 'error';
                } else {
                    $loginSuccess = false;
                    $user = null;

                    // Fast path: query directly for this specific user — avoids loading all users
                    if (file_exists(__DIR__ . '/Database.php')) {
                        try {
                            require_once __DIR__ . '/Database.php';
                            $db = Database::getInstance();
                            $user = $db->fetchOne(
                                "SELECT u.*, COALESCE(e.name, u.username) as full_name, e.team as emp_team
                                 FROM users u
                                 LEFT JOIN employees e ON e.email = u.email
                                 WHERE u.active = 1 AND (u.username = ? OR u.email = ?)
                                 LIMIT 1",
                                [$username, $username]
                            );
                            if ($user) {
                                if (empty($user['team']) || $user['team'] === 'all') {
                                    $user['team'] = $user['emp_team'] ?? '';
                                }
                            }
                        } catch (Exception $e) {
                            // DB unavailable — fall through to the pre-loaded $users array
                        }
                    }

                    // Fallback: scan pre-loaded users array (JSON mode or DB failure)
                    if (!$user) {
                        foreach ($users as $u) {
                            $usernameMatch = ($u['username'] === $username);
                            $emailMatch    = (strcasecmp($u['email'] ?? '', $username) === 0);
                            if (($usernameMatch || $emailMatch) && $u['active']) {
                                $user = $u;
                                break;
                            }
                        }
                    }

                    if ($user) {
                        $storedHash = $user['password_hash'] ?? $user['password'] ?? '';
                        if ($storedHash && password_verify($password, $storedHash)) {
                            session_regenerate_id(false); // false = let old session expire naturally, avoids blocking disk I/O

                            $_SESSION['user_id']          = $user['id'];
                            $_SESSION['user_role']        = $user['role'];
                            $_SESSION['user_name']        = $user['full_name'] ?? $user['username'];
                            $_SESSION['user_team']        = $user['team'] ?? 'all';
                            $_SESSION['login_time']       = time();
                            $_SESSION['last_activity']    = time();
                            $_SESSION['user_agent']       = $_SERVER['HTTP_USER_AGENT'] ?? '';
                            $_SESSION['auth_method_used'] = 'password';

                            $loginSuccess = true;
                        }
                    }

                    if ($loginSuccess) {
                        // Write session and redirect immediately — no HTML output = no white page flash
                        session_write_close();
                        header('Location: ' . $_SERVER['PHP_SELF'], true, 302);
                        exit;
                    } else {
                        $message = "Invalid username or password.";
                        $messageType = 'error';
                    }
                }
            }
            
            // Show login form
            showLoginForm($message, $messageType);
            exit;
        }
        
        if ($action === 'logout') {
            // Log the logout before destroying session
            addUserActivityLog('logout', 'User logged out');

            // ── Suppress any deprecation/notice output so headers stay clean ──
            // PHP 8.4 host configs sometimes have display_errors=On; any stray
            // output before setcookie() would break cookie clearing and produce
            // an empty/broken response ("page can't be displayed").
            $prevErrorReporting = error_reporting(0);
            $prevDisplayErrors  = ini_get('display_errors');
            ini_set('display_errors', '0');

            // Discard any output already buffered (e.g. from a notice printed
            // before this point) so the Set-Cookie header can still be sent.
            while (ob_get_level() > 0) { ob_end_clean(); }

            session_destroy();

            // Clear the session cookie — belt-and-suspenders for all browsers
            $cookieName   = session_name();
            $cookieParams = session_get_cookie_params();
            setcookie($cookieName, '', time() - 3600, $cookieParams['path'] ?: '/',
                      $cookieParams['domain'], $cookieParams['secure'], $cookieParams['httponly']);
            if (isset($_COOKIE[$cookieName])) {
                unset($_COOKIE[$cookieName]);
            }

            // Restore error reporting now that headers are queued
            error_reporting($prevErrorReporting);
            ini_set('display_errors', $prevDisplayErrors);

            // JS redirect — header() can't be used after any prior output
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
            echo '<meta http-equiv="refresh" content="0;url=?action=login">';
            echo '</head><body>';
            echo '<script>window.location.replace("?action=login");</script>';
            echo '<p><a href="?action=login">Logged out. Click here to sign in again.</a></p>';
            echo '</body></html>';
            exit;
        }
    }
}

// Show Login Form
function showLoginForm($message = '', $messageType = '') {
    $googleClient = getGoogleClient();
    $googleAuthUrl = $googleClient->createAuthUrl();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>CS Unified Schedule — Sign In</title>
        <style>
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                min-height: 100vh;
                display: flex;
                background: #0f172a;
                color: #1e293b;
            }

            /* ── Left panel ─────────────────────────────── */
            .lp-left {
                display: none;
                flex: 1;
                background: linear-gradient(145deg, #1e1b4b 0%, #312e81 40%, #1e3a8a 100%);
                position: relative;
                overflow: hidden;
                align-items: center;
                justify-content: center;
                flex-direction: column;
                padding: 60px 50px;
            }
            @media (min-width: 900px) { .lp-left { display: flex; } }

            /* Decorative circles */
            .lp-left::before {
                content: '';
                position: absolute;
                width: 500px; height: 500px;
                border-radius: 50%;
                background: rgba(99,102,241,.15);
                top: -120px; left: -100px;
            }
            .lp-left::after {
                content: '';
                position: absolute;
                width: 350px; height: 350px;
                border-radius: 50%;
                background: rgba(147,197,253,.08);
                bottom: -80px; right: -60px;
            }

            .lp-brand {
                position: relative;
                z-index: 2;
                text-align: center;
            }
            .lp-logo-ring {
                width: 90px; height: 90px;
                border-radius: 24px;
                background: linear-gradient(135deg, #6366f1, #3b82f6);
                display: flex; align-items: center; justify-content: center;
                margin: 0 auto 24px;
                box-shadow: 0 20px 60px rgba(99,102,241,.4);
                font-size: 36px; font-weight: 800; color: #fff;
                letter-spacing: -1px;
            }
            .lp-brand h2 {
                font-size: 28px; font-weight: 700;
                color: #fff;
                line-height: 1.25;
                margin-bottom: 12px;
            }
            .lp-brand p {
                color: rgba(255,255,255,.6);
                font-size: 15px;
                line-height: 1.6;
                max-width: 280px;
            }

            .lp-feature-list {
                position: relative; z-index: 2;
                margin-top: 48px;
                list-style: none;
                display: flex; flex-direction: column; gap: 16px;
            }
            .lp-feature-list li {
                display: flex; align-items: center; gap: 12px;
                color: rgba(255,255,255,.75);
                font-size: 14px;
            }
            .lp-feature-list li span.dot {
                width: 8px; height: 8px; border-radius: 50%;
                background: #818cf8; flex-shrink: 0;
            }

            /* ── Right panel (form) ───────────────────── */
            .lp-right {
                width: 100%;
                max-width: 480px;
                margin: 0 auto;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 40px 24px;
                background: #fff;
            }
            @media (min-width: 900px) {
                .lp-right {
                    margin: 0;
                    min-height: 100vh;
                    border-radius: 0;
                }
            }

            .lp-form-wrap {
                width: 100%;
                max-width: 380px;
            }

            /* Mobile logo shown only < 900px */
            .lp-mobile-logo {
                display: flex;
                align-items: center;
                gap: 14px;
                margin-bottom: 36px;
            }
            @media (min-width: 900px) { .lp-mobile-logo { display: none; } }

            .lp-mobile-logo .mini-ring {
                width: 46px; height: 46px; border-radius: 12px;
                background: linear-gradient(135deg, #6366f1, #3b82f6);
                display: flex; align-items: center; justify-content: center;
                font-size: 18px; font-weight: 800; color: #fff;
            }
            .lp-mobile-logo span {
                font-size: 17px; font-weight: 700; color: #1e293b;
            }

            .lp-heading {
                font-size: 26px;
                font-weight: 700;
                color: #0f172a;
                margin-bottom: 6px;
            }
            .lp-subheading {
                font-size: 14px;
                color: #64748b;
                margin-bottom: 32px;
            }

            /* ── Alert / message ── */
            .lp-alert {
                border-radius: 10px;
                padding: 12px 16px;
                font-size: 13.5px;
                font-weight: 500;
                margin-bottom: 20px;
                display: flex; align-items: flex-start; gap: 8px;
            }
            .lp-alert.success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
            .lp-alert.error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
            .lp-alert.info    { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
            .lp-alert.warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }

            /* ── Google button ── */
            .lp-google-btn {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                width: 100%;
                padding: 12px 20px;
                background: #fff;
                border: 1.5px solid #e2e8f0;
                border-radius: 10px;
                text-decoration: none;
                color: #374151;
                font-size: 14px;
                font-weight: 600;
                transition: all .2s ease;
                box-shadow: 0 1px 3px rgba(0,0,0,.06);
            }
            .lp-google-btn:hover {
                border-color: #a5b4fc;
                box-shadow: 0 4px 12px rgba(99,102,241,.15);
                transform: translateY(-1px);
            }

            /* ── Divider ── */
            .lp-divider {
                display: flex; align-items: center; gap: 14px;
                margin: 22px 0;
            }
            .lp-divider hr {
                flex: 1; border: none;
                border-top: 1px solid #e2e8f0;
            }
            .lp-divider span {
                font-size: 12px; color: #94a3b8;
                font-weight: 500; white-space: nowrap;
                text-transform: uppercase; letter-spacing: .5px;
            }

            /* ── Form fields ── */
            .lp-field {
                margin-bottom: 18px;
            }
            .lp-field label {
                display: block;
                font-size: 13px;
                font-weight: 600;
                color: #374151;
                margin-bottom: 6px;
            }
            .lp-field input {
                width: 100%;
                padding: 11px 14px;
                border: 1.5px solid #e2e8f0;
                border-radius: 10px;
                font-size: 14.5px;
                color: #0f172a;
                background: #f8fafc;
                outline: none;
                transition: border-color .2s, box-shadow .2s, background .2s;
            }
            .lp-field input::placeholder { color: #94a3b8; }
            .lp-field input:focus {
                border-color: #6366f1;
                background: #fff;
                box-shadow: 0 0 0 3px rgba(99,102,241,.12);
            }

            /* ── Submit button ── */
            .lp-submit {
                width: 100%;
                padding: 12px;
                background: linear-gradient(135deg, #4f46e5, #3b82f6);
                color: #fff;
                border: none;
                border-radius: 10px;
                font-size: 15px;
                font-weight: 600;
                cursor: pointer;
                transition: all .2s ease;
                letter-spacing: .2px;
                box-shadow: 0 4px 15px rgba(79,70,229,.35);
                margin-top: 6px;
            }
            .lp-submit:hover {
                transform: translateY(-1px);
                box-shadow: 0 6px 20px rgba(79,70,229,.45);
                background: linear-gradient(135deg, #4338ca, #2563eb);
            }
            .lp-submit:active {
                transform: translateY(0);
            }

            /* ── Footer note ── */
            .lp-footer {
                text-align: center;
                margin-top: 28px;
                font-size: 12px;
                color: #94a3b8;
            }
        </style>
    </head>
    <body>

        <!-- Left decorative panel -->
        <div class="lp-left">
            <div class="lp-brand">
                <div class="lp-logo-ring">CS</div>
                <h2>CS Unified Schedule</h2>
                <p>Your all-in-one platform for managing team coverage, shifts, and scheduling.</p>
            </div>
            <ul class="lp-feature-list">
                <li><span class="dot"></span>Real-time coverage heatmaps</li>
                <li><span class="dot"></span>Skills-based scheduling insights</li>
                <li><span class="dot"></span>Shift override management</li>
                <li><span class="dot"></span>Team &amp; supervisor tracking</li>
            </ul>
        </div>

        <!-- Right form panel -->
        <div class="lp-right">
            <div class="lp-form-wrap">

                <!-- Mobile-only logo -->
                <div class="lp-mobile-logo">
                    <div class="mini-ring">CS</div>
                    <span>CS Unified Schedule</span>
                </div>

                <h1 class="lp-heading">Welcome back</h1>
                <p class="lp-subheading">Sign in to your account to continue</p>

                <?php if ($message): ?>
                <div class="lp-alert <?php echo htmlspecialchars($messageType); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
                <?php endif; ?>

                <!-- Google SSO -->
                <a href="<?php echo htmlspecialchars($googleAuthUrl); ?>" class="lp-google-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                    </svg>
                    Continue with Google
                </a>

                <div class="lp-divider">
                    <hr><span>or sign in with credentials</span><hr>
                </div>

                <!-- Username / password form -->
                <form method="POST" id="loginForm">
                    <div class="lp-field">
                        <label for="lp_username">Username</label>
                        <input type="text" id="lp_username" name="username" placeholder="Enter your username"
                               required autofocus autocomplete="username">
                    </div>
                    <div class="lp-field">
                        <label for="lp_password">Password</label>
                        <input type="password" id="lp_password" name="password" placeholder="Enter your password"
                               required autocomplete="current-password">
                    </div>
                    <button type="submit" class="lp-submit">Sign In</button>
                </form>

                <p class="lp-footer">CS Unified Schedule &bull; Customer Support</p>
            </div>
        </div>

    </body>
    </html>
    <?php
}

// Handle Settings Operations
function handleUserManagement() {
    global $users, $nextUserId, $message, $messageType;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add_user':
                if (!hasPermission('manage_users')) {
                    $message = "⛔ You don't have permission to manage users.";
                    $messageType = 'error';
                    break;
                }

                $username   = trim($_POST['username'] ?? '');
                $password   = $_POST['password'] ?? '';
                $email      = trim($_POST['email'] ?? '');
                $fullName   = trim($_POST['full_name'] ?? '');
                $role       = $_POST['role'] ?? 'employee';
                $team       = $_POST['team'] ?? 'all';
                $authMethod = in_array($_POST['auth_method'] ?? '', ['google','local','both']) ? $_POST['auth_method'] : 'google';

                $needsPassword = in_array($authMethod, ['local', 'both']);

                if ($username && $email && $fullName && (!$needsPassword || $password)) {
                    try {
                        $db  = Database::getInstance();
                        $pdo = $db->getConnection();

                        // Check username and email uniqueness
                        $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
                        $chk->execute([$username, $email]);
                        if ($chk->fetch()) {
                            $message = "⛔ Username or email already exists.";
                            $messageType = 'error';
                            break;
                        }

                        $passwordHash = $needsPassword ? password_hash($password, PASSWORD_DEFAULT) : '';

                        $pdo->prepare(
                            "INSERT INTO users (username, password_hash, email, role, team, auth_method, active, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())"
                        )->execute([$username, $passwordHash, $email, $role, $team, $authMethod]);

                        $newId = (int)$pdo->lastInsertId();
                        addUserActivityLog('user_add', "Created user: $fullName ($username) role: $role auth: $authMethod", 'user', $newId);
                        $message = "✅ User '$fullName' has been created successfully!";
                        $messageType = 'success';
                    } catch (Exception $e) {
                        error_log('add_user error: ' . $e->getMessage());
                        $message = "⛔ Failed to create user: " . $e->getMessage();
                        $messageType = 'error';
                    }
                } else {
                    $message = "⛔ Please fill in all required fields" . ($needsPassword ? " (password required for this auth method)." : ".");
                    $messageType = 'error';
                }
                break;

            case 'edit_user':
                if (!hasPermission('manage_users')) {
                    $message = "⛔ You don't have permission to manage users.";
                    $messageType = 'error';
                    break;
                }

                $userId      = intval($_POST['userId'] ?? 0);
                $username    = trim($_POST['username'] ?? '');
                $email       = trim($_POST['email'] ?? '');
                $fullName    = trim($_POST['full_name'] ?? '');
                $role        = $_POST['role'] ?? 'employee';
                $team        = $_POST['team'] ?? 'all';
                $active      = isset($_POST['active']) ? 1 : 0;
                $newPassword = $_POST['new_password'] ?? '';

                if ($username && $email && $fullName && $userId) {
                    try {
                        $db  = Database::getInstance();
                        $pdo = $db->getConnection();

                        if ($newPassword) {
                            $pdo->prepare(
                                "UPDATE users SET username=?, email=?, role=?, team=?, active=?, password_hash=?, updated_at=NOW() WHERE id=?"
                            )->execute([$username, $email, $role, $team, $active, password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
                        } else {
                            $pdo->prepare(
                                "UPDATE users SET username=?, email=?, role=?, team=?, active=?, updated_at=NOW() WHERE id=?"
                            )->execute([$username, $email, $role, $team, $active, $userId]);
                        }

                        // If editing current user, update session
                        if ($userId === (int)$_SESSION['user_id']) {
                            $_SESSION['user_role'] = $role;
                            $_SESSION['user_name'] = $fullName;
                            $_SESSION['user_team'] = $team;
                        }

                        $changes = [];
                        if ($newPassword) $changes[] = "password updated";
                        $changes[] = "role: $role";
                        $changes[] = "team: $team";
                        $changes[] = "status: " . ($active ? 'active' : 'inactive');
                        addUserActivityLog('user_edit', "Updated user: $fullName (" . implode(', ', $changes) . ")", 'user', $userId);
                        $message = "✅ User '$fullName' has been updated successfully!";
                        $messageType = 'success';
                    } catch (Exception $e) {
                        error_log('edit_user error: ' . $e->getMessage());
                        $message = "⛔ Failed to update user: " . $e->getMessage();
                        $messageType = 'error';
                    }
                } else {
                    $message = "⛔ Please fill in all required fields.";
                    $messageType = 'error';
                }
                break;
                
            case 'delete_user':
                if (!hasPermission('manage_users')) {
                    $message = "⛔ You don't have permission to manage users.";
                    $messageType = 'error';
                    break;
                }

                $userId = intval($_POST['userId'] ?? 0);

                // Don't allow deleting the current user
                if ($userId == $_SESSION['user_id']) {
                    $message = "⛔ You cannot delete your own account.";
                    $messageType = 'error';
                    break;
                }

                try {
                    $db  = Database::getInstance();
                    $pdo = $db->getConnection();

                    // Get the user's name before deleting
                    $row = $pdo->prepare("SELECT COALESCE(e.name, u.username) AS full_name
                                          FROM users u
                                          LEFT JOIN employees e ON e.email = u.email
                                          WHERE u.id = ? LIMIT 1");
                    $row->execute([$userId]);
                    $targetUser = $row->fetch(PDO::FETCH_ASSOC);

                    if (!$targetUser) {
                        $message = "⛔ User not found.";
                        $messageType = 'error';
                        break;
                    }

                    $deletedUserName = $targetUser['full_name'];
                    deleteProfilePhoto($userId);

                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$userId]);

                    if ($stmt->rowCount() > 0) {
                        addUserActivityLog('user_delete', "Deleted user: $deletedUserName", 'user', $userId);
                        $message = "✅ User '$deletedUserName' has been deleted successfully.";
                        $messageType = 'success';
                    } else {
                        $message = "⛔ Failed to delete user.";
                        $messageType = 'error';
                    }
                } catch (Exception $e) {
                    error_log('delete_user error: ' . $e->getMessage());
                    $message = "⛔ Failed to delete user: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'link_user_employee':
                if (!hasPermission('manage_employees') || !hasPermission('manage_users')) {
                    $message = "⛔ You don't have permission to link employees.";
                    $messageType = 'error';
                    break;
                }
                $userId     = intval($_POST['userId'] ?? 0);
                $employeeId = intval($_POST['employeeId'] ?? 0);
                if (!$userId || !$employeeId) {
                    $message = "⛔ Invalid user or employee ID.";
                    $messageType = 'error';
                    break;
                }
                try {
                    $db  = Database::getInstance();
                    $pdo = $db->getConnection();

                    $userRow = $pdo->prepare("SELECT id, email, username FROM users WHERE id = ?");
                    $userRow->execute([$userId]);
                    $targetUser = $userRow->fetch(PDO::FETCH_ASSOC);
                    if (!$targetUser) { $message = "⛔ User not found."; $messageType = 'error'; break; }

                    $empRow = $pdo->prepare("SELECT id, name, email FROM employees WHERE id = ?");
                    $empRow->execute([$employeeId]);
                    $targetEmp = $empRow->fetch(PDO::FETCH_ASSOC);
                    if (!$targetEmp) { $message = "⛔ Employee not found."; $messageType = 'error'; break; }

                    $userEmail   = $targetUser['email'];
                    $oldEmpEmail = $targetEmp['email'];

                    // Update employee email in MySQL to match user account email
                    $pdo->prepare("UPDATE employees SET email = ? WHERE id = ?")->execute([$userEmail, $employeeId]);

                    // Also update schedule_data.json
                    $jsonPath = __DIR__ . '/schedule_data.json';
                    if (file_exists($jsonPath)) {
                        $jsonData = json_decode(file_get_contents($jsonPath), true);
                        if ($jsonData && isset($jsonData['employees'])) {
                            foreach ($jsonData['employees'] as &$emp) {
                                if ($emp['id'] == $employeeId) { $emp['email'] = $userEmail; break; }
                            }
                            unset($emp);
                            file_put_contents($jsonPath, json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        }
                    }

                    addUserActivityLog('employee_edit',
                        "Manually linked {$targetEmp['name']} to user '{$targetUser['username']}' — employee email updated from {$oldEmpEmail} to {$userEmail}",
                        'user', $userId);
                    $message = "✅ Successfully linked {$targetEmp['name']} to '{$targetUser['username']}'.";
                    $messageType = 'success';
                } catch (Exception $e) {
                    error_log('link_user_employee error: ' . $e->getMessage());
                    $message = "⛔ Failed to link: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'link_user_employee_by_email':
                if (!hasPermission('manage_employees') || !hasPermission('manage_users')) {
                    $message = "⛔ You don't have permission to link employees.";
                    $messageType = 'error';
                    break;
                }
                $userId        = intval($_POST['userId'] ?? 0);
                $employeeEmail = strtolower(trim($_POST['employeeEmail'] ?? ''));
                if (!$userId) { $message = "⛔ Invalid user ID."; $messageType = 'error'; break; }
                try {
                    $db  = Database::getInstance();
                    $pdo = $db->getConnection();

                    // Get the user account
                    $userRow = $pdo->prepare("SELECT id, email, username FROM users WHERE id = ?");
                    $userRow->execute([$userId]);
                    $targetUser = $userRow->fetch(PDO::FETCH_ASSOC);
                    if (!$targetUser) { $message = "⛔ User not found."; $messageType = 'error'; break; }

                    $userEmail = strtolower($targetUser['email']);
                    $targetEmp = null;

                    // 1. If admin entered an employee email, look up that employee
                    if (!empty($employeeEmail)) {
                        $empRow = $pdo->prepare("SELECT id, name, email FROM employees WHERE LOWER(email) = ? AND active = 1 LIMIT 1");
                        $empRow->execute([$employeeEmail]);
                        $targetEmp = $empRow->fetch(PDO::FETCH_ASSOC);
                        if (!$targetEmp) { $message = "⛔ No active employee found with email '{$employeeEmail}'."; $messageType = 'error'; break; }
                    }

                    // 2. Auto-match by name derived from user email (firstname.lastname@ or firstname_lastname@)
                    if (!$targetEmp) {
                        $local = explode('@', $userEmail)[0];
                        $parts = preg_split('/[._]/', $local);
                        if (count($parts) >= 2) {
                            $derivedName = implode(' ', array_map('ucfirst', $parts));
                            $empRow = $pdo->prepare("SELECT id, name, email FROM employees WHERE LOWER(name) = ? AND active = 1 LIMIT 1");
                            $empRow->execute([strtolower($derivedName)]);
                            $targetEmp = $empRow->fetch(PDO::FETCH_ASSOC);
                        }
                    }

                    if (!$targetEmp) { $message = "⛔ Could not find a matching employee. Please enter the employee's current email."; $messageType = 'error'; break; }

                    $oldEmpEmail = $targetEmp['email'];

                    // Update MySQL employee email to match the user account email
                    $pdo->prepare("UPDATE employees SET email = ? WHERE id = ?")->execute([$targetUser['email'], $targetEmp['id']]);

                    // Update schedule_data.json as well (kept in sync as a fallback)
                    $jsonPath = __DIR__ . '/schedule_data.json';
                    if (file_exists($jsonPath)) {
                        $jsonData = json_decode(file_get_contents($jsonPath), true);
                        if ($jsonData && isset($jsonData['employees'])) {
                            foreach ($jsonData['employees'] as &$emp) {
                                if ($emp['id'] == $targetEmp['id']) { $emp['email'] = $targetUser['email']; break; }
                            }
                            unset($emp);
                            file_put_contents($jsonPath, json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        }
                    }

                    addUserActivityLog('employee_edit',
                        "Manually linked {$targetEmp['name']} to user '{$targetUser['username']}' — employee email updated from {$oldEmpEmail} to {$targetUser['email']}",
                        'user', $userId);
                    $message = "✅ {$targetEmp['name']} is now linked to '{$targetUser['username']}'.";
                    $messageType = 'success';
                } catch (Exception $e) {
                    error_log('link_user_employee_by_email error: ' . $e->getMessage());
                    $message = "⛔ Failed to link: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'unlink_user_employee':
                if (!hasPermission('manage_employees') || !hasPermission('manage_users')) {
                    $message = "⛔ You don't have permission to unlink employees.";
                    $messageType = 'error';
                    break;
                }
                $userId = intval($_POST['userId'] ?? 0);
                if (!$userId) { $message = "⛔ Invalid user ID."; $messageType = 'error'; break; }
                try {
                    $db  = Database::getInstance();
                    $pdo = $db->getConnection();

                    $userRow = $pdo->prepare("SELECT id, email, username FROM users WHERE id = ?");
                    $userRow->execute([$userId]);
                    $targetUser = $userRow->fetch(PDO::FETCH_ASSOC);
                    if (!$targetUser) { $message = "⛔ User not found."; $messageType = 'error'; break; }

                    // Clear employee email so it no longer matches this user account
                    $pdo->prepare("UPDATE employees SET email = NULL WHERE email = ? LIMIT 1")
                        ->execute([$targetUser['email']]);

                    // Also update schedule_data.json
                    $jsonPath = __DIR__ . '/schedule_data.json';
                    if (file_exists($jsonPath)) {
                        $jsonData = json_decode(file_get_contents($jsonPath), true);
                        if ($jsonData && isset($jsonData['employees'])) {
                            foreach ($jsonData['employees'] as &$emp) {
                                if (isset($emp['email']) && strtolower($emp['email']) === strtolower($targetUser['email'])) {
                                    $emp['email'] = '';
                                    break;
                                }
                            }
                            unset($emp);
                            file_put_contents($jsonPath, json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        }
                    }

                    addUserActivityLog('employee_edit', "Unlinked employee from user '{$targetUser['username']}'", 'user', $userId);
                    $message = "✅ Employee unlinked from '{$targetUser['username']}'.";
                    $messageType = 'success';
                } catch (Exception $e) {
                    error_log('unlink_user_employee error: ' . $e->getMessage());
                    $message = "⛔ Failed to unlink: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
        }

        return ['message' => $message, 'messageType' => $messageType];
    }

    return ['message' => '', 'messageType' => ''];
}

// Profile Management Functions
function handleProfileUpdate() {
    global $users;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'update_profile' || $action === 'update_other_profile') {
            $currentUser = getCurrentUser();
            if (!$currentUser) {
                return ['message' => "⛔ Please log in to update profiles.", 'messageType' => 'error'];
            }
            
            // Determine which user we're updating
            $targetUserId = ($action === 'update_other_profile') ? intval($_POST['target_user_id'] ?? 0) : $currentUser['id'];
            
            // Check permissions for editing other users' profiles
            if ($action === 'update_other_profile' && !hasPermission('manage_all_profiles')) {
                return ['message' => "⛔ You don't have permission to edit other users' profiles.", 'messageType' => 'error'];
            }
            
            $targetUser = getUserById($targetUserId);
            if (!$targetUser) {
                return ['message' => "⛔ User not found.", 'messageType' => 'error'];
            }
            
            $fullName = trim($_POST['profile_full_name'] ?? '');
            $email = trim($_POST['profile_email'] ?? '');
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            $deletePhoto = isset($_POST['delete_photo']);
            // Sanitise Slack Member ID: uppercase, alphanumeric only, must start with U or W
            $rawSlackId = strtoupper(trim($_POST['profile_slack_id'] ?? ''));
            $slackId = (!empty($rawSlackId) && preg_match('/^[UW][A-Z0-9]{6,}$/', $rawSlackId)) ? $rawSlackId : null;
            
            // Validate required fields
            if (empty($fullName) || empty($email)) {
                return ['message' => "⛔ Please fill in all required fields.", 'messageType' => 'error'];
            }
            
            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['message' => "⛔ Please enter a valid email address.", 'messageType' => 'error'];
            }
            
            // Check if email is already taken by another user
            foreach ($users as $user) {
                if ($user['id'] != $targetUserId && strtolower($user['email']) === strtolower($email)) {
                    return ['message' => "⛔ This email address is already in use by another user.", 'messageType' => 'error'];
                }
            }
            
            // Password validation (only required when editing own profile)
            $passwordUpdate = false;
            if (!empty($newPassword)) {
                if ($action === 'update_profile') {
                    // Editing own profile - require current password
                    if (empty($currentPassword)) {
                        return ['message' => "⛔ Please enter your current password to set a new password.", 'messageType' => 'error'];
                    }
                    
                    $storedHash = $currentUser['password_hash'] ?? $currentUser['password'] ?? '';
                    if (!$storedHash || !password_verify($currentPassword, $storedHash)) {
                        return ['message' => "⛔ Current password is incorrect.", 'messageType' => 'error'];
                    }
                }
                
                if ($newPassword !== $confirmPassword) {
                    return ['message' => "⛔ New passwords do not match.", 'messageType' => 'error'];
                }
                
                if (strlen($newPassword) < 6) {
                    return ['message' => "⛔ New password must be at least 6 characters long.", 'messageType' => 'error'];
                }
                
                $passwordUpdate = true;
            }
            
            // Handle photo upload
            $photoUpdated = false;
            if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = uploadProfilePhoto($targetUserId, $_FILES['profile_photo']);
                if ($uploadResult['success']) {
                    $photoUpdated = true;
                    $newPhotoFilename = $uploadResult['filename'];
                } else {
                    return ['message' => "⛔ Photo upload failed: " . $uploadResult['message'], 'messageType' => 'error'];
                }
            }
            
            // Handle photo deletion
            if ($deletePhoto) {
                deleteProfilePhoto($targetUserId);
                $photoUpdated = true;
                $newPhotoFilename = null;
            }
            
            // Save changes directly to DB
            try {
                require_once __DIR__ . '/Database.php';
                $db  = Database::getInstance();
                $pdo = $db->getConnection();

                $setClauses = ["username = ?", "email = ?", "updated_at = NOW()"];
                $params     = [$fullName, $email];

                if ($passwordUpdate) {
                    $setClauses[] = "password_hash = ?";
                    $params[]     = password_hash($newPassword, PASSWORD_DEFAULT);
                }

                // Save Slack Member ID (always update — allows clearing it too)
                // Only write the column if it exists (safe for older installs)
                $slackColExists = $pdo->query("SHOW COLUMNS FROM users LIKE 'slack_id'")->fetch();
                if ($slackColExists) {
                    $setClauses[] = "slack_id = ?";
                    $params[]     = $slackId;
                }

                $params[] = $targetUserId;
                $pdo->prepare(
                    "UPDATE users SET " . implode(', ', $setClauses) . " WHERE id = ?"
                )->execute($params);

                // Also keep $users global in sync so the rest of this request sees the new values
                foreach ($users as &$u) {
                    if ($u['id'] === $targetUserId) {
                        $u['full_name'] = $fullName;
                        $u['username']  = $fullName;
                        $u['email']     = $email;
                        if ($passwordUpdate) {
                            $u['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                        }
                        $u['slack_id']   = $slackId;
                        $u['updated_at'] = date('c');
                        break;
                    }
                }
                unset($u);
                $dbSaveOk = true;
            } catch (Exception $e) {
                error_log("update_profile DB error: " . $e->getMessage());
                $dbSaveOk = false;
            }

            if ($dbSaveOk) {
                // Update session data if editing own profile
                if ($targetUserId === $currentUser['id']) {
                    $_SESSION['user_name'] = $fullName;
                }
                
                // Log the profile update
                if (function_exists('addActivityLog')) {
                    $changes = ['profile updated'];
                    if ($passwordUpdate) {
                        $changes[] = 'password changed';
                    }
                    if ($photoUpdated) {
                        $changes[] = $deletePhoto ? 'photo removed' : 'photo updated';
                    }
                    
                    $logMessage = ($action === 'update_other_profile') 
                        ? "Updated {$targetUser['full_name']}'s profile: " . implode(', ', $changes)
                        : "Updated profile: " . implode(', ', $changes);
                    
                    addActivityLog('profile_update', $logMessage, 'user', $targetUserId);
                }
                
                $message = "✅ Profile updated successfully!";
                if ($passwordUpdate) $message .= " Password has been changed.";
                if ($photoUpdated) $message .= $deletePhoto ? " Photo has been removed." : " Photo has been updated.";
                
                return ['message' => $message, 'messageType' => 'success'];
            } else {
                return ['message' => "⛔ Failed to save profile changes.", 'messageType' => 'error'];
            }
        }
    }
    
    return ['message' => '', 'messageType' => ''];
}

// Get linked employee for current user
if (!function_exists('getCurrentUserEmployee')) {
function getCurrentUserEmployee() {
    $currentUser = getCurrentUser();

    if (!$currentUser) return null;

    // Request-level cache
    static $_cueCache    = null;
    static $_cueCacheKey = null;
    $cacheKey = $currentUser['id'];
    if ($_cueCache !== false && $_cueCacheKey === $cacheKey) {
        return $_cueCache ?: null;
    }

    // Load from database instead of global $employees
    try {
        if (file_exists(__DIR__ . '/Database.php')) {
            require_once __DIR__ . '/Database.php';
            $db = Database::getInstance();

            // First try to match by email (most reliable)
            if (isset($currentUser['email'])) {
                $employee = $db->fetchOne(
                    "SELECT * FROM employees WHERE email = ? AND active = 1",
                    [$currentUser['email']]
                );

                if ($employee) {
                    $_cueCache = $employee; $_cueCacheKey = $cacheKey;
                    return $employee;
                }
            }

            // Fallback: Try to match by employee_id if it exists in user record
            if (isset($currentUser['employee_id'])) {
                $employee = $db->fetchOne(
                    "SELECT * FROM employees WHERE id = ? AND active = 1",
                    [$currentUser['employee_id']]
                );

                if ($employee) {
                    $_cueCache = $employee; $_cueCacheKey = $cacheKey;
                    return $employee;
                }
            }

            $_cueCache = false; $_cueCacheKey = $cacheKey; // cache "not found"
            return null;
        }
    } catch (Exception $e) {
        error_log("getCurrentUserEmployee database error: " . $e->getMessage());
    }
    
    // Fallback to old method with global $employees
    global $employees;
    
    // Check if employees array exists
    if (!isset($employees) || !is_array($employees)) return null;
    
    // First try to match by employee_id if it exists in user record
    if (isset($currentUser['employee_id'])) {
        foreach ($employees as $employee) {
            if ($employee['id'] == $currentUser['employee_id']) {
                return $employee;
            }
        }
    }
    
    // Try to match by last name extracted from username (format: first initial + lastname)
    $username = strtolower(trim($currentUser['username']));
    if (strlen($username) > 1) {
        // Extract lastname from username (everything after first character)
        $usernameLastName = substr($username, 1);
        
        foreach ($employees as $employee) {
            $employeeNameParts = explode(' ', trim($employee['name']));
            $employeeLastName = strtolower(end($employeeNameParts)); // Get last part of name
            
            if ($employeeLastName === $usernameLastName) {
                return $employee;
            }
        }
    }
    
    // Fallback: match by full name (case-insensitive) - keep for backwards compatibility
    $userName = strtolower(trim($currentUser['full_name']));
    foreach ($employees as $employee) {
        if (strtolower(trim($employee['name'])) === $userName) {
            return $employee;
        }
    }
    
    return null;
}
}

// Get Profile Card HTML
function getProfileCardHTML($userId = null) {
    $currentUser = getCurrentUser();
    if (!$currentUser) return '';
    
    // If userId is provided and user has permission, show that user's profile
    $targetUser = $currentUser;
    $canEditOtherProfiles = hasPermission('manage_all_profiles');
    
    if ($userId && $canEditOtherProfiles) {
        $targetUser = getUserById($userId);
        if (!$targetUser) {
            $targetUser = $currentUser;
        }
    }
    
    $linkedEmployee = getCurrentUserEmployee();
    $profilePhotoUrl = getProfilePhotoUrl($targetUser);
    
    ob_start();
    ?>
    <div class="profile-card">
        <div class="profile-header">
            <div class="profile-avatar">
                <?php $profileInitials = strtoupper(substr($targetUser['full_name'], 0, 2)); ?>
                <?php if ($profilePhotoUrl): ?>
                    <img src="<?php echo htmlspecialchars($profilePhotoUrl); ?>" alt="Profile Photo" class="profile-photo-img"
                         onerror="this.style.display='none';">
                <?php else: ?>
                    <?php echo $profileInitials; ?>
                <?php endif; ?>
            </div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars($targetUser['full_name']); ?></h3>
                <span class="role-badge role-<?php echo $targetUser['role']; ?>">
                    <?php echo ucfirst($targetUser['role']); ?>
                </span>
            </div>
            <button class="profile-edit-btn" onclick="openProfileModal(<?php echo $targetUser['id']; ?>)" title="Edit Profile">
                ✏️ Edit
            </button>
        </div>
        
        <div class="profile-details">
            <div class="profile-field">
                <span class="field-label">📧 Email:</span>
                <span class="field-value"><?php echo htmlspecialchars($targetUser['email']); ?></span>
            </div>
            
            <div class="profile-field">
                <span class="field-label">👤 Username:</span>
                <span class="field-value"><?php echo htmlspecialchars($targetUser['username']); ?></span>
            </div>
            
            <div class="profile-field">
                <span class="field-label">🏢 Team Access:</span>
                <span class="field-value"><?php echo strtoupper($targetUser['team']); ?></span>
            </div>
            
            <div class="profile-field">
                <span class="field-label">📅 Member Since:</span>
                <span class="field-value"><?php echo date('M j, Y', strtotime($targetUser['created_at'])); ?></span>
            </div>
            
            <?php if ($linkedEmployee && $targetUser['id'] === $currentUser['id']): ?>
            <div class="employee-link">
                <div class="employee-link-header">
                    <span class="field-label">👷 Linked Employee:</span>
                </div>
                <div class="employee-details">
                    <div class="employee-name"><?php echo htmlspecialchars($linkedEmployee['name']); ?></div>
                    <div class="employee-info">
                        <span class="team-badge team-<?php echo $linkedEmployee['team']; ?>">
                            <?php echo strtoupper($linkedEmployee['team']); ?>
                        </span>
                        <?php if (function_exists('getShiftName')): ?>
                        <span class="shift-info"><?php echo getShiftName($linkedEmployee['shift']); ?></span>
                        <?php else: ?>
                        <span class="shift-info">Shift <?php echo $linkedEmployee['shift']; ?></span>
                        <?php endif; ?>
                        <span class="hours-info"><?php echo htmlspecialchars($linkedEmployee['hours']); ?></span>
                        <?php if (!empty($linkedEmployee['level'])): ?>
                        <span class="level-badge level-<?php echo $linkedEmployee['level']; ?>">
                            <?php if (function_exists('getLevelName')): ?>
                                <?php echo getLevelName($linkedEmployee['level']); ?>
                            <?php else: ?>
                                <?php echo strtoupper($linkedEmployee['level']); ?>
                            <?php endif; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php elseif ($targetUser['id'] === $currentUser['id']): ?>
            <div class="profile-field">
                <span class="field-label">👷 Employee Link:</span>
                <span class="field-value no-link">Not linked to an employee record</span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if ($canEditOtherProfiles && $targetUser['id'] !== $currentUser['id']): ?>
    <div style="margin: 20px; text-align: center;">
        <p><em>You are viewing <?php echo htmlspecialchars($targetUser['full_name']); ?>'s profile as <?php echo ucfirst($currentUser['role']); ?>.</em></p>
    </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

// Get Profile Modal HTML - FIXED VERSION
function getProfileModalHTML() {
    global $users;
    $currentUser = getCurrentUser();
    if (!$currentUser) return '';
    
    ob_start();
    ?>
    <!-- Profile Edit Modal -->
    <div id="profileModal" class="modal">
        <div class="modal-content profile-modal">
            <h2 id="profileModalTitle">Edit Profile</h2>
            
            <form method="POST" id="profileForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile" id="profileAction">
                <input type="hidden" name="target_user_id" value="" id="targetUserId">
                
                <!-- Photo Section (Left Column) -->
                <div class="form-group">
                    <label>Profile Photo:</label>
                    <div class="current-photo-container">
                        <div class="current-photo" id="currentPhotoDisplay">
                            <?php $profilePhotoUrl = getProfilePhotoUrl($currentUser); ?>
                            <?php $initials2 = strtoupper(substr($currentUser['full_name'], 0, 2)); ?>
                            <?php if ($profilePhotoUrl): ?>
                                <img src="<?php echo htmlspecialchars($profilePhotoUrl); ?>" alt="Profile Photo" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 2px solid #ddd; margin-bottom: 10px;"
                                     onerror="this.style.display='none';document.getElementById('photoPlaceholder').style.display='flex';">
                                <div class="photo-placeholder" id="photoPlaceholder" style="display:none;width: 100px; height: 100px; border-radius: 50%; background: var(--primary-color, #333399); color: white; align-items: center; justify-content: center; font-size: 32px; font-weight: bold; margin-bottom: 10px;">
                                    <?php echo $initials2; ?>
                                </div>
                            <?php else: ?>
                                <div class="photo-placeholder" id="photoPlaceholder" style="width: 100px; height: 100px; border-radius: 50%; background: var(--primary-color, #333399); color: white; display: flex; align-items: center; justify-content: center; font-size: 32px; font-weight: bold; margin-bottom: 10px;">
                                    <?php echo $initials2; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-note" style="font-size: 11px; color: #666; margin-top: 6px;">Profile photo is synced from your Google account.</div>
                </div>
                
                <!-- Form Fields (Right Column) -->
                <div class="form-group">
                    <label for="profile_full_name">Full Name:</label>
                    <input type="text" 
                           name="profile_full_name" 
                           id="profile_full_name" 
                           value="<?php echo htmlspecialchars($currentUser['full_name']); ?>" 
                           required>
                </div>
                
                <div class="form-group">
                    <label for="profile_email">Email:</label>
                    <input type="email" 
                           name="profile_email" 
                           id="profile_email" 
                           value="<?php echo htmlspecialchars($currentUser['email']); ?>" 
                           required>
                </div>
                
                <div class="form-group">
                    <label>Username:</label>
                    <input type="text"
                           value="<?php echo htmlspecialchars($currentUser['username']); ?>"
                           disabled
                           title="Username cannot be changed">
                    <div class="form-note">Cannot be changed</div>
                </div>

                <div class="form-group">
                    <label>Role:</label>
                    <input type="text"
                           value="<?php echo ucfirst($currentUser['role']); ?>"
                           disabled
                           title="Role can only be changed by administrators">
                    <div class="form-note">Admin only</div>
                </div>

                <!-- Slack Member ID Section -->
                <div class="form-group">
                    <label for="profile_slack_id" style="display:flex;align-items:center;gap:5px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="#4A154B" style="flex-shrink:0;"><path d="M5.042 15.165a2.528 2.528 0 0 1-2.52 2.523A2.528 2.528 0 0 1 0 15.165a2.527 2.527 0 0 1 2.522-2.52h2.52v2.52zM6.313 15.165a2.527 2.527 0 0 1 2.521-2.52 2.527 2.527 0 0 1 2.521 2.52v6.313A2.528 2.528 0 0 1 8.834 24a2.528 2.528 0 0 1-2.521-2.522v-6.313zM8.834 5.042a2.528 2.528 0 0 1-2.521-2.52A2.528 2.528 0 0 1 8.834 0a2.528 2.528 0 0 1 2.521 2.522v2.52H8.834zM8.834 6.313a2.528 2.528 0 0 1 2.521 2.521 2.528 2.528 0 0 1-2.521 2.521H2.522A2.528 2.528 0 0 1 0 8.834a2.528 2.528 0 0 1 2.522-2.521h6.312zM18.956 8.834a2.528 2.528 0 0 1 2.522-2.521A2.528 2.528 0 0 1 24 8.834a2.528 2.528 0 0 1-2.522 2.521h-2.522V8.834zM17.688 8.834a2.528 2.528 0 0 1-2.523 2.521 2.527 2.527 0 0 1-2.52-2.521V2.522A2.527 2.527 0 0 1 15.165 0a2.528 2.528 0 0 1 2.523 2.522v6.312zM15.165 18.956a2.528 2.528 0 0 1 2.523 2.522A2.528 2.528 0 0 1 15.165 24a2.527 2.527 0 0 1-2.52-2.522v-2.522h2.52zM15.165 17.688a2.527 2.527 0 0 1-2.52-2.523 2.526 2.526 0 0 1 2.52-2.52h6.313A2.527 2.527 0 0 1 24 15.165a2.528 2.528 0 0 1-2.522 2.523h-6.313z"/></svg>
                        Slack Member ID:
                    </label>
                    <input type="text"
                           name="profile_slack_id"
                           id="profile_slack_id"
                           value="<?php echo htmlspecialchars($currentUser['slack_id'] ?? ''); ?>"
                           placeholder="e.g. U01AB2CD3EF"
                           maxlength="50">
                    <div class="form-note" style="margin-top:6px;line-height:1.6;">
                        <strong>How to find your Slack Member ID:</strong><br>
                        1. Open Slack and click your <strong>profile picture</strong> or name in the sidebar.<br>
                        2. Click <strong>Profile</strong> to open your profile panel.<br>
                        3. Click the <strong>⋯ More</strong> button (three dots) at the top of the panel.<br>
                        4. Select <strong>"Copy member ID"</strong> — it looks like <code style="background:#f0f0f0;padding:1px 4px;border-radius:3px;">U01AB2CD3EF</code>.<br>
                        5. Paste it above and click <em>Update Profile</em>.<br>
                        <span style="color:#27ae60;font-weight:500;">✅ Once saved, your Slack link will appear on the schedule so anyone can click it to message you directly.</span>
                    </div>
                </div>

                <!-- Password Section (Spans Both Columns) -->
                <div class="password-section">
                    <h4>Change Password (Optional)</h4>
                    <div class="form-note">Leave blank to keep current password</div>
                    
                    <div class="form-group" id="currentPasswordGroup">
                        <label for="current_password">Current Password:</label>
                        <input type="password" 
                               name="current_password" 
                               id="current_password" 
                               placeholder="Enter current password">
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">New Password:</label>
                        <input type="password" 
                               name="new_password" 
                               id="new_password" 
                               placeholder="Min 6 characters"
                               minlength="6">
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password:</label>
                        <input type="password" 
                               name="confirm_password" 
                               id="confirm_password" 
                               placeholder="Confirm password">
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <button type="submit" class="btn-primary">Update Profile</button>
                    <button type="button" onclick="closeProfileModal()" class="btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Profile Viewer Modal for Admin/Supervisors/Managers -->
    <?php if (hasPermission('manage_all_profiles')): ?>
    <div id="profileViewerModal" class="modal">
        <div class="modal-content profile-modal">
            <h2>User Profile Viewer</h2>
            <div style="margin-bottom: 20px;">
                <label>Select User to View/Edit:</label>
                <select id="userSelect" onchange="viewUserProfile(this.value)" style="width: 100%; padding: 8px; margin-top: 5px;">
                    <option value="">Select a user...</option>
                    <?php foreach ($users as $user): ?>
                    <option value="<?php echo $user['id']; ?>">
                        <?php echo htmlspecialchars($user['full_name']); ?> 
                        (<?php echo htmlspecialchars($user['username']); ?>) 
                        - <?php echo ucfirst($user['role']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="selectedUserProfile">
                <p style="text-align: center; color: #666; font-style: italic;">Select a user from the dropdown above to view their profile.</p>
            </div>
            <div style="text-align: center; margin-top: 20px;">
                <button type="button" onclick="closeProfileViewerModal()" class="btn-secondary">Close</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

// Enhanced Settings HTML with Search
function getUserManagementHTML() {
    global $users, $roles, $employees;

    if (!hasPermission('manage_users')) {
        return '<div class="permission-denied">
                    <h3>Access Denied</h3>
                    <p>You don\'t have permission to access user management.</p>
                </div>';
    }

    // ── Load MOTD data from DB for the settings UI ─────────────────────────
    $motdDataFull = function_exists('loadMOTD') ? loadMOTD() : ['messages' => [], 'show_anniversaries_global' => false];
    $allMOTDs     = $motdDataFull['messages'] ?? [];
    $today        = date('Y-m-d');

    ob_start();

    ?>
    <style>
        /* ══════════════════════════════════════════════════
           SETTINGS REVAMP STYLES
           All colours reference CSS vars → fully theme-aware.
           The .sr wrapper scopes everything so we don't
           collide with parent app styles.
        ══════════════════════════════════════════════════ */
        .sr *, .sr *::before, .sr *::after { box-sizing: border-box; }

        .sr {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--body-bg, #f8f9fa);
            color: var(--text-color, #212529);
            padding: 28px 32px;
        }

        /* ── Page header ──────────────────────────────── */
        .sr-page-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:28px; flex-wrap:wrap; }
        .sr-page-title  { display:flex; align-items:center; gap:10px; }
        .sr-page-title h2 { font-size:22px; font-weight:700; color:var(--text-color,#212529); margin:0; }
        .sr-page-subtitle { font-size:13px; color:var(--text-muted,#6c757d); margin-top:3px; }
        .sr-page-actions  { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }

        /* ── Buttons ──────────────────────────────────── */
        .sr-btn {
            display:inline-flex; align-items:center; gap:7px;
            padding:9px 18px; border-radius:8px; font-size:13.5px;
            font-weight:600; border:none; cursor:pointer;
            transition:all 0.18s ease; white-space:nowrap;
            font-family:inherit; line-height:1; text-decoration:none;
        }
        .sr-btn-primary { background:var(--primary-color, #333399); color:#fff !important; }
        .sr-btn-primary:hover { filter:brightness(1.1); transform:translateY(-1px); box-shadow:0 4px 12px rgba(0,0,0,0.2); }
        .sr-btn-primary span, .sr-btn-primary * { color:#fff !important; }
        .sr-btn-outline { background:transparent; color:var(--primary-color, #333399); border:1.5px solid var(--primary-color, #333399); }
        .sr-btn-outline:hover { background:var(--primary-color, #333399); color:#fff; }
        .sr-btn-ghost { background:transparent; color:var(--text-muted,#6c757d); border:1.5px solid var(--border-color,#dee2e6); }
        .sr-btn-ghost:hover { background:var(--surface-color,#f8f9fa); border-color:var(--text-muted,#6c757d); color:var(--text-color,#212529); }
        .sr-btn-sm { padding:6px 12px; font-size:12.5px; }
        .sr-btn-danger { background:#dc3545; color:#fff; border:none; }
        .sr-btn-danger:hover { background:#bb2d3b; }

        /* ── Section headings ─────────────────────────── */
        .sr-section { margin-bottom:28px; }
        .sr-section-header { display:flex; align-items:center; gap:9px; margin-bottom:16px; }
        .sr-section-header h3 { font-size:16px; font-weight:700; color:var(--text-color,#212529); margin:0; }
        .sr-section-divider { flex:1; height:1px; background:var(--border-color,#dee2e6); margin-left:8px; }

        /* ── Card grid ────────────────────────────────── */
        .sr-card-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(380px,1fr)); gap:18px; }

        /* ── Cards ────────────────────────────────────── */
        .sr-card {
            background:var(--card-bg,#fff); border:1px solid var(--border-color,#dee2e6);
            border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,0.06);
            overflow:hidden; transition:box-shadow 0.18s ease,transform 0.18s ease;
        }
        .sr-card:hover { box-shadow:0 6px 20px rgba(0,0,0,0.1); transform:translateY(-1px); }
        .sr-card-accented { border-top:3px solid var(--primary-color); }

        .sr-card-head {
            padding:18px 22px 14px; border-bottom:1px solid var(--border-color,#dee2e6);
            display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
        }
        .sr-card-head-left { display:flex; align-items:center; gap:12px; }
        .sr-icon-badge {
            width:40px; height:40px; border-radius:10px; display:flex;
            align-items:center; justify-content:center; font-size:18px;
            flex-shrink:0; background:var(--primary-color); color:#fff; opacity:0.9;
        }
        .sr-icon-badge.soft { background:rgba(0,0,0,0.06); }
        .sr-card-title { font-size:15px; font-weight:700; color:var(--text-color,#212529); }
        .sr-card-sub   { font-size:12px; color:var(--text-muted,#6c757d); margin-top:2px; }
        .sr-card-body  { padding:20px 22px; }

        /* ── Stats ────────────────────────────────────── */
        .sr-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:14px; margin-bottom:28px; }
        .sr-stat {
            background:var(--card-bg,#fff); border:1px solid var(--border-color,#dee2e6);
            border-radius:12px; padding:18px 20px; display:flex; align-items:center;
            gap:14px; box-shadow:0 1px 3px rgba(0,0,0,0.05); transition:all 0.18s;
        }
        .sr-stat:hover { border-color:var(--primary-color); transform:translateY(-2px); box-shadow:0 4px 14px rgba(0,0,0,0.09); }
        .sr-stat-ico { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
        .sr-stat-ico.pri    { background:color-mix(in srgb, var(--primary-color) 12%, transparent); }
        .sr-stat-ico.green  { background:#f0fdf4; }
        .sr-stat-ico.amber  { background:#fffbeb; }
        .sr-stat-ico.purple { background:#f5f3ff; }
        .sr-stat-val { font-size:24px; font-weight:800; color:var(--text-color,#212529); letter-spacing:-0.5px; line-height:1; }
        .sr-stat-lbl { font-size:12px; color:var(--text-muted,#6c757d); margin-top:3px; font-weight:500; }

        /* ── Toggle switch ────────────────────────────── */
        .sr-sw { position:relative; width:46px; height:25px; flex-shrink:0; display:inline-block; }
        .sr-sw input { opacity:0; width:0; height:0; position:absolute; }
        .sr-sw-track { position:absolute; inset:0; background:var(--border-color,#adb5bd); border-radius:25px; cursor:pointer; transition:background 0.25s; }
        .sr-sw-track::after { content:''; position:absolute; top:3px; left:3px; width:19px; height:19px; background:#fff; border-radius:50%; transition:transform 0.25s; box-shadow:0 1px 3px rgba(0,0,0,0.25); }
        .sr-sw input:checked ~ .sr-sw-track { background:var(--primary-color); }
        .sr-sw input:checked ~ .sr-sw-track::after { transform:translateX(21px); }

        /* ── Forms ────────────────────────────────────── */
        .sr-form-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; margin-bottom:14px; }
        .sr-form-row.cols-3 { grid-template-columns:repeat(3,1fr); }
        .sr-form-row.cols-1 { grid-template-columns:1fr; }
        .sr-fg { display:flex; flex-direction:column; gap:5px; }
        .sr-lbl { font-size:12px; font-weight:600; color:var(--text-color,#495057); }
        .sr-ctrl {
            padding:9px 12px; border:1.5px solid var(--border-color,#dee2e6);
            border-radius:7px; font-size:13.5px; font-family:inherit;
            color:var(--text-color,#212529); background:var(--card-bg,#fff);
            transition:border-color 0.2s,box-shadow 0.2s; width:100%;
        }
        .sr-ctrl:focus { outline:none; border-color:var(--primary-color); box-shadow:0 0 0 3px color-mix(in srgb, var(--primary-color) 12%, transparent); }
        .sr-ctrl::placeholder { color:var(--text-muted,#adb5bd); }
        textarea.sr-ctrl { min-height:90px; resize:vertical; }
        /* ── Scheduled Messages: Add form + Edit modal — always white boxes, black text ── */
        #addMOTDForm { background: #fff !important; color: #212529 !important; }
        #addMOTDForm .sr-ctrl { background: #fff !important; color: #212529 !important; border-color: #dee2e6 !important; }
        #addMOTDForm .sr-lbl, #addMOTDForm label, #addMOTDForm span { color: #212529 !important; }
        #editMOTDModal > div { background: #fff !important; color: #212529 !important; }
        #editMOTDModal h3 { color: #212529 !important; }
        #editMOTDModal .sr-ctrl { background: #fff !important; color: #212529 !important; border-color: #dee2e6 !important; }
        #editMOTDModal .sr-lbl, #editMOTDModal label, #editMOTDModal span { color: #212529 !important; }
        .sr-iw { position:relative; }
        .sr-iw .sr-ctrl { padding-left:36px; }
        .sr-iw-icon { position:absolute; left:11px; top:50%; transform:translateY(-50%); font-size:14px; pointer-events:none; color:var(--text-muted,#adb5bd); }
        .sr-slbl { font-size:10.5px; font-weight:700; letter-spacing:0.8px; text-transform:uppercase; color:var(--text-muted,#6c757d); margin-bottom:10px; }

        /* ── MOTD message items ───────────────────────── */
        .sr-msg-list { display:flex; flex-direction:column; gap:10px; margin-bottom:16px; }
        .sr-msg-item { background:#ffffff !important; border:1px solid #dee2e6 !important; border-radius:9px; padding:13px 16px; display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
        .sr-msg-body { flex:1; min-width:0; }
        .sr-msg-badges { display:flex; gap:6px; margin-bottom:6px; flex-wrap:wrap; }
        .sr-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:600; }
        .sr-badge-active   { background:#d1fae5; color:#065f46; }
        .sr-badge-scheduled{ background:#dbeafe; color:#1e40af; }
        .sr-badge-expired  { background:#e9ecef !important; color:#6c757d !important; border:1px solid #dee2e6 !important; }
        .sr-badge-type     { background:#e9ecef !important; color:#6c757d !important; border:1px solid #dee2e6 !important; }
        .sr-badge-dot      { width:6px; height:6px; border-radius:50%; background:currentColor; }
        .sr-msg-text { font-size:13.5px; font-weight:600; color:#212529 !important; margin-bottom:4px; }
        .sr-msg-meta { font-size:12px; color:#6c757d !important; }
        .sr-msg-actions { display:flex; flex-direction:column; gap:6px; flex-shrink:0; }

        /* ── User search bar ──────────────────────────── */
        .sr-users-bar { display:flex; gap:10px; align-items:center; margin-bottom:18px; flex-wrap:wrap; }
        .sr-users-bar .sr-iw { flex:1; min-width:220px; }
        .sr-user-count { font-size:13px; color:var(--text-muted,#6c757d); font-weight:500; white-space:nowrap; }

        /* ── User TABLE (existing styling preserved) ──── */
        /* The existing .users-table, .role-badge, .status-badge etc. CSS
           lives in the app's main stylesheet — we don't override it here.
           We just wrap it in the .sr shell. */
        #usersListContainer { margin-top:16px; }

        /* ── Email export status ──────────────────────── */
        .sr-export-out { margin-top:14px; padding:13px 15px; background:var(--surface-color,#f8f9fa); border:1.5px solid var(--border-color,#dee2e6); border-radius:8px; font-size:13px; color:var(--text-muted,#6c757d); display:none; }
        .sr-export-out.on { display:block; }

        /* ── Responsive ───────────────────────────────── */
        @media(max-width:860px) {
            .sr { padding:20px 16px; }
            .sr-card-grid { grid-template-columns:1fr; }
            .sr-form-row.cols-3 { grid-template-columns:1fr 1fr; }
            .sr-stats { grid-template-columns:1fr 1fr; }
        }
        @media(max-width:560px) {
            .sr-stats { grid-template-columns:1fr 1fr; }
            .sr-form-row.cols-3 { grid-template-columns:1fr; }
        }

        /* ── Tooltip ──────────────────────────────────── */
        .sr-tooltip-wrap { display:inline-block; }
        .sr-tooltip {
            display: none;
            position: absolute;
            bottom: calc(100% + 8px);
            right: 0;
            background: rgba(30,30,30,0.93);
            color: #fff;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
            padding: 6px 10px;
            border-radius: 6px;
            pointer-events: none;
            z-index: 9999;
            box-shadow: 0 2px 8px rgba(0,0,0,0.25);
        }
        .sr-tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            right: 14px;
            border: 5px solid transparent;
            border-top-color: rgba(30,30,30,0.93);
        }
        .sr-tooltip-wrap:hover .sr-tooltip { display: block; }
    </style>

    <div class="sr">

        <!-- ── Page header ──────────────────────────────── -->
        <div class="sr-page-header">
            <div>
                <div class="sr-page-title">
                    <span style="font-size:22px;">⚙️</span>
                    <h2>Settings</h2>
                </div>
                <div class="sr-page-subtitle">Manage users, integrations, and system configuration</div>
            </div>
            <div class="sr-page-actions">
                <div style="position:relative;display:inline-block;" class="sr-tooltip-wrap">
                    <button class="sr-btn sr-btn-primary" onclick="ScheduleApp.openAddUserModal()">➕ Add User</button>
                    <div class="sr-tooltip">This is only used for non-Google SSO access</div>
                </div>
            </div>
        </div>

        <!-- ── Stats strip ──────────────────────────────── -->
        <div class="sr-stats">
            <div class="sr-stat">
                <div class="sr-stat-ico pri">👥</div>
                <div>
                    <div class="sr-stat-val"><?php echo count($users); ?></div>
                    <div class="sr-stat-lbl">Total Users</div>
                </div>
            </div>
            <div class="sr-stat">
                <div class="sr-stat-ico green">✅</div>
                <div>
                    <div class="sr-stat-val"><?php echo count(array_filter($users, fn($u) => $u['active'] ?? true)); ?></div>
                    <div class="sr-stat-lbl">Active Users</div>
                </div>
            </div>
            <div class="sr-stat">
                <div class="sr-stat-ico amber">📢</div>
                <div>
                    <div class="sr-stat-val"><?php echo count(array_filter($allMOTDs ?? [], fn($m) => empty($m['end_date']) || $m['end_date'] >= $today)); ?></div>
                    <div class="sr-stat-lbl">Active Messages</div>
                </div>
            </div>
            <div class="sr-stat">
                <div class="sr-stat-ico purple">👥</div>
                <div>
                    <div class="sr-stat-val"><?php echo isset($employees) ? count($employees) : 0; ?></div>
                    <div class="sr-stat-lbl">Employees</div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════
             SECTION 1: Communication & Announcements
        ═════════════════════════════════════════════════ -->
        <div class="sr-section">
            <div class="sr-section-header">
                <span style="font-size:18px;">📢</span>
                <h3>Communication &amp; Announcements</h3>
                <div class="sr-section-divider"></div>
            </div>

            <div class="sr-card-grid">

                <!-- Email Export Card -->
                <div class="sr-card sr-card-accented">
                    <div class="sr-card-head">
                        <div class="sr-card-head-left">
                            <div class="sr-icon-badge">📧</div>
                            <div>
                                <div class="sr-card-title">Email Export Tools</div>
                                <div class="sr-card-sub">Export addresses filtered by role, team, or level</div>
                            </div>
                        </div>
                    </div>
                    <div class="sr-card-body">
                        <div style="text-align:center;margin-bottom:14px;">
                            <img src="Images/export.png" alt="Email Export" style="max-width:100%;max-height:140px;object-fit:contain;border-radius:8px;opacity:0.85;">
                        </div>
                        <!-- Team filter for export -->
                        <?php $exportTeams = ['esg'=>'ESG','support'=>'Support','windows'=>'Windows','security'=>'Security','secops_abuse'=>'SecOps/Abuse','migrations'=>'Migrations','learning_development'=>'Learning and Development','Implementations'=>'Implementations','Account Services'=>'Account Services','Account Services Stellar'=>'Account Services Stellar']; ?>
                        <div style="margin-bottom:10px;">
                            <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted,#6c757d);margin-bottom:5px;">Filter by Team</label>
                            <select id="emailExportTeamFilter" class="sr-ctrl" style="width:100%;" onchange="updateEmailExportButtons()">
                                <option value="">All Teams</option>
                                <?php foreach ($exportTeams as $teamKey => $teamLabel): ?>
                                <option value="<?php echo htmlspecialchars($teamKey); ?>"><?php echo htmlspecialchars($teamLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="display:flex;flex-direction:column;gap:9px;">
                            <button id="emailExportTxtBtn" class="sr-btn sr-btn-primary" style="width:100%;justify-content:center;" onclick="exportAllUserEmailsToFile()">
                                📥 Export All to TXT
                            </button>
                            <button id="emailExportCopyBtn" class="sr-btn sr-btn-outline" style="width:100%;justify-content:center;" onclick="exportFilteredTeamEmails()">
                                📋 Copy All Emails
                            </button>
                        </div>

                        <div id="emailExportStatus" class="sr-export-out"></div>
                    </div>
                </div>

                <!-- Scheduled Messages Card -->
                <div class="sr-card sr-card-accented">
                    <div class="sr-card-head">
                        <div class="sr-card-head-left">
                            <div class="sr-icon-badge">📣</div>
                            <div>
                                <div class="sr-card-title">Scheduled Messages</div>
                                <div class="sr-card-sub">Manage announcements with optional date ranges</div>
                            </div>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <!-- Anniversaries toggle — real POST form -->
                            <form method="POST" style="margin:0;display:flex;align-items:center;gap:8px;">
                                <input type="hidden" name="action" value="toggle_global_anniversaries">
                                <label class="sr-sw" title="Always Show Anniversaries">
                                    <input type="checkbox" name="show_anniversaries_global"
                                           <?php echo ($motdDataFull['show_anniversaries_global'] ?? false) ? 'checked' : ''; ?>
                                           onchange="this.form.submit()">
                                    <span class="sr-sw-track"></span>
                                </label>
                                <span style="font-size:12.5px;font-weight:600;color:var(--text-muted,#6c757d);">🎉 Anniversaries</span>
                            </form>
                            <button onclick="showAddMOTDForm()" class="sr-btn sr-btn-primary sr-btn-sm">➕ Add Message</button>
                        </div>
                    </div>
                    <div class="sr-card-body">

                        <!-- Add MOTD Form (hidden by default) -->
                        <div id="addMOTDForm" style="display:none;border:1.5px solid var(--border-color,#dee2e6);border-radius:10px;padding:18px;margin-bottom:16px;background:var(--surface-color,#f8f9fa);">
                            <div class="sr-slbl" style="margin-bottom:12px;">New Message</div>
                            <form method="POST" action="index.php" style="margin:0;">
                                <input type="hidden" name="action" value="add_motd">
                                <div class="sr-form-row cols-1">
                                    <div class="sr-fg">
                                        <label class="sr-lbl">Message <span style="font-weight:400;color:var(--text-muted,#adb5bd)">(optional — leave blank for anniversaries only)</span></label>
                                        <textarea name="motd_message" class="sr-ctrl" rows="3" placeholder="Enter your announcement…"></textarea>
                                    </div>
                                </div>
                                <div class="sr-form-row" style="margin-top:10px;">
                                    <div class="sr-fg">
                                        <label class="sr-lbl">Start Date <span style="font-weight:400;color:var(--text-muted,#adb5bd)">(optional)</span></label>
                                        <input type="date" name="start_date" class="sr-ctrl">
                                    </div>
                                    <div class="sr-fg">
                                        <label class="sr-lbl">End Date <span style="font-weight:400;color:var(--text-muted,#adb5bd)">(optional)</span></label>
                                        <input type="date" name="end_date" class="sr-ctrl">
                                    </div>
                                </div>
                                <div style="margin:12px 0;">
                                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;color:var(--text-color,#212529);">
                                        <input type="checkbox" name="include_anniversaries" checked style="width:16px;height:16px;accent-color:var(--primary-color);cursor:pointer;">
                                        <span style="font-weight:600;">🎉 Include work anniversaries</span>
                                    </label>
                                </div>
                                <div style="display:flex;gap:8px;margin-top:4px;">
                                    <button type="submit" class="sr-btn sr-btn-primary">💾 Add Message</button>
                                    <button type="button" onclick="hideAddMOTDForm()" class="sr-btn sr-btn-ghost">Cancel</button>
                                </div>
                            </form>
                        </div>

                        <!-- Existing messages -->
                        <div class="sr-msg-list">
                        <?php if (empty($allMOTDs)): ?>
                            <p style="color:var(--text-muted,#6c757d);font-size:13px;text-align:center;padding:24px 0;">No messages scheduled. Click "Add Message" to create one.</p>
                        <?php else: ?>
                            <?php foreach ($allMOTDs as $motd):
                                $mStatus = 'active'; $mLabel = 'ACTIVE';
                                if (!empty($motd['start_date']) && $today < $motd['start_date']) { $mStatus = 'scheduled'; $mLabel = 'SCHEDULED'; }
                                if (!empty($motd['end_date'])   && $today > $motd['end_date'])   { $mStatus = 'expired';   $mLabel = 'EXPIRED'; }
                            ?>
                            <div class="sr-msg-item">
                                <div class="sr-msg-body">
                                    <div class="sr-msg-badges">
                                        <span class="sr-badge sr-badge-<?php echo $mStatus; ?>">
                                            <span class="sr-badge-dot"></span>
                                            <?php echo $mLabel; ?>
                                        </span>
                                        <?php if ($motd['include_anniversaries']): ?>
                                            <span class="sr-badge sr-badge-type">🎉 Anniversaries</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="sr-msg-text"><?php echo nl2br(htmlspecialchars($motd['message'])); ?></div>
                                    <div class="sr-msg-meta">
                                        <?php if (!empty($motd['start_date']) || !empty($motd['end_date'])): ?>
                                            📅
                                            <?php if (!empty($motd['start_date'])): ?> From <?php echo date('M j, Y', strtotime($motd['start_date'])); ?><?php endif; ?>
                                            <?php if (!empty($motd['end_date'])): ?> to <?php echo date('M j, Y', strtotime($motd['end_date'])); ?><?php endif; ?>
                                        <?php else: ?>
                                            📅 No date restrictions (always active)
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="sr-msg-actions">
                                    <button onclick="editMOTD('<?php echo $motd['id']; ?>')" class="sr-btn sr-btn-ghost sr-btn-sm">✏️ Edit</button>
                                    <button onclick="deleteMOTD('<?php echo $motd['id']; ?>', '<?php echo addslashes(substr($motd['message'], 0, 50)); ?>')" class="sr-btn sr-btn-danger sr-btn-sm">🗑️ Delete</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </div>

                    </div>
                </div>

            </div>
        </div>

        <!-- ════════════════════════════════════════════════
             SECTION 2: User Management
        ═════════════════════════════════════════════════ -->
        <div class="sr-section">
            <div class="sr-section-header">
                <span style="font-size:18px;">👥</span>
                <h3>User Management</h3>
                <div class="sr-section-divider"></div>
            </div>

            <div class="sr-card">
                <div class="sr-card-head">
                    <div class="sr-card-head-left">
                        <div class="sr-icon-badge soft">🔍</div>
                        <div>
                            <div class="sr-card-title">Search Users &amp; Employees</div>
                            <div class="sr-card-sub">Find user accounts and linked employee records quickly</div>
                        </div>
                    </div>
                    <button onclick="toggleUsersList()" id="toggleUsersBtn"
                            class="sr-btn sr-btn-primary sr-btn-sm">
                        <span id="toggleUsersIcon">▼</span> <span id="toggleUsersText">Collapse List</span>
                    </button>
                </div>
                <div class="sr-card-body">

                    <!-- Search + filter toolbar -->
                    <div class="sr-users-bar">
                        <div class="sr-iw">
                            <span class="sr-iw-icon">🔍</span>
                            <input type="text" id="userSearchInput" class="sr-ctrl"
                                   placeholder="Search by name, username, email, or role…"
                                   oninput="filterUsers()" autocomplete="off">
                        </div>
                        <select class="sr-ctrl" id="roleFilter" onchange="filterUsers()" style="max-width:130px;" title="Filter by role">
                            <option value="">All Roles</option>
                            <option value="admin">Admin</option>
                            <option value="manager">Manager</option>
                            <option value="supervisor">Supervisor</option>
                            <option value="employee">Employee</option>
                        </select>
                        <select class="sr-ctrl" id="levelFilter" onchange="filterUsers()" style="max-width:130px;" title="Filter by level">
                            <option value="">All Levels</option>
                            <option value="ssa">SSA</option>
                            <option value="ssa2">SSA2</option>
                            <option value="tam">TAM</option>
                            <option value="tam2">TAM2</option>
                            <option value="l1">L1</option>
                            <option value="l2">L2</option>
                            <option value="l3">L3</option>
                            <option value="manager">Manager</option>
                            <option value="supervisor">Supervisor</option>
                            <option value="SR. Supervisor">SR. Supervisor</option>
                            <option value="SR. Manager">SR. Manager</option>
                            <option value="IMP Tech">IMP Tech</option>
                            <option value="IMP Coordinator">IMP Coordinator</option>
                            <option value="technical_writer">Technical Writer</option>
                            <option value="trainer">Trainer</option>
                            <option value="tech_coach">Tech Coach</option>
                            <option value="none">No Level</option>
                        </select>
                        <select class="sr-ctrl" id="statusFilter" onchange="filterUsers()" style="max-width:130px;" title="Filter by status">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <select class="sr-ctrl" id="linkFilter" onchange="filterUsers()" style="max-width:140px;" title="Filter by employee link">
                            <option value="">All Users</option>
                            <option value="linked">Linked</option>
                            <option value="unlinked">Not Linked</option>
                        </select>
                        <button onclick="resetUserFilters()" class="sr-btn sr-btn-outline sr-btn-sm">🔄 Reset</button>
                    </div>

                    <div class="sr-user-count">
                        <span id="searchResults">Showing all <?php echo count($users); ?> users</span>
                    </div>

                    <!-- User table (collapsible, expanded by default) -->
                    <div id="usersListContainer" style="display:block; transition:all 0.3s ease; margin-top:14px;">
                        <table class="users-table" id="userTable">
                            <thead>
                                <tr>
                                    <th onclick="sortUserTable('photo')" class="sortable">Photo</th>
                                    <th onclick="sortUserTable('full_name')" class="sortable">Name <span class="sort-indicator" data-sort="full_name"></span></th>
                                    <th onclick="sortUserTable('username')" class="sortable">Username <span class="sort-indicator" data-sort="username"></span></th>
                                    <th onclick="sortUserTable('email')" class="sortable">Email <span class="sort-indicator" data-sort="email"></span></th>
                                    <th onclick="sortUserTable('role')" class="sortable">Role <span class="sort-indicator" data-sort="role"></span></th>
                                    <th onclick="sortUserTable('level')" class="sortable">Level <span class="sort-indicator" data-sort="level"></span></th>
                                    <th>Employee Link</th>
                                    <th onclick="sortUserTable('active')" class="sortable">Status <span class="sort-indicator" data-sort="active"></span></th>
                                    <th onclick="sortUserTable('created_at')" class="sortable">Created <span class="sort-indicator" data-sort="created_at"></span></th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="userTableBody">
                                <?php foreach ($users as $user):
                                    $linkedEmployee = null;
                                    $userFullName = $user['full_name'] ?? $user['username'] ?? '';
                                    if (isset($user['employee_id']) && isset($employees) && is_array($employees)) {
                                        foreach ($employees as $emp) {
                                            if (isset($emp['id']) && $emp['id'] == $user['employee_id']) { $linkedEmployee = $emp; break; }
                                        }
                                    }
                                    if (!$linkedEmployee && !empty($userFullName) && isset($employees) && is_array($employees)) {
                                        foreach ($employees as $emp) {
                                            if (isset($emp['name']) && strtolower(trim($emp['name'])) === strtolower(trim($userFullName))) { $linkedEmployee = $emp; break; }
                                        }
                                    }
                                    // Final fallback: match by email (most reliable link between users and employees)
                                    if (!$linkedEmployee && !empty($user['email']) && isset($employees) && is_array($employees)) {
                                        foreach ($employees as $emp) {
                                            if (!empty($emp['email']) && strtolower(trim($emp['email'])) === strtolower(trim($user['email']))) { $linkedEmployee = $emp; break; }
                                        }
                                    }
                                ?>
                                <tr class="user-row"
                                    data-name="<?php echo strtolower($userFullName); ?>"
                                    data-username="<?php echo strtolower($user['username'] ?? ''); ?>"
                                    data-email="<?php echo strtolower($user['email'] ?? ''); ?>"
                                    data-role="<?php echo $user['role'] ?? 'employee'; ?>"
                                    data-team="<?php echo $user['team'] ?? ($linkedEmployee['team'] ?? ''); ?>"
                                    data-level="<?php echo strtolower($linkedEmployee['level'] ?? ''); ?>"
                                    data-status="<?php echo ($user['active'] ?? true) ? 'active' : 'inactive'; ?>"
                                    data-linked="<?php echo $linkedEmployee ? 'linked' : 'unlinked'; ?>"
                                    data-created="<?php echo $user['created_at']; ?>">

                                    <td class="user-photo-cell">
                                        <?php $photoUrl = getProfilePhotoUrl($user); ?>
                                        <?php $listInitials = strtoupper(substr($userFullName, 0, 2)); ?>
                                        <div class="user-photo-small">
                                            <?php if ($photoUrl): ?>
                                                <div class="user-photo-placeholder" id="ph-<?php echo (int)$user['id']; ?>"><?php echo $listInitials; ?></div>
                                                <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="<?php echo htmlspecialchars($userFullName); ?>" class="user-photo-img" style="display:none;"
                                                     onload="this.style.display='';this.previousElementSibling.style.display='none';"
                                                     onerror="this.style.display='none';this.previousElementSibling.style.display='flex';">
                                            <?php else: ?>
                                                <div class="user-photo-placeholder"><?php echo $listInitials; ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="user-name">
                                        <div class="name-container">
                                            <strong><?php echo htmlspecialchars($userFullName); ?></strong>
                                            <?php if ($linkedEmployee): ?><span class="employee-link-indicator" title="Linked to employee record">👤 Linked</span><?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="username"><?php echo htmlspecialchars($user['username'] ?? ''); ?></td>
                                    <td class="email"><a href="mailto:<?php echo htmlspecialchars($user['email']); ?>"><?php echo htmlspecialchars($user['email']); ?></a></td>
                                    <td class="role"><span class="role-badge role-<?php echo $user['role']; ?>"><?php echo htmlspecialchars($roles[$user['role']]['name']); ?></span></td>
                                    <td class="level">
                                        <?php if ($linkedEmployee && !empty($linkedEmployee['level'])): ?>
                                            <span class="level-badge level-<?php echo htmlspecialchars($linkedEmployee['level']); ?>"><?php echo function_exists('getLevelName') ? htmlspecialchars(getLevelName($linkedEmployee['level'])) : strtoupper(htmlspecialchars($linkedEmployee['level'])); ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted,#6c757d);font-size:12px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="employee-link">
                                        <?php if ($linkedEmployee): ?>
                                        <div class="linked-employee">
                                            <strong><?php echo htmlspecialchars($linkedEmployee['name']); ?></strong><br>
                                            <small>
                                                <?php echo strtoupper($linkedEmployee['team']); ?> -
                                                <?php echo function_exists('getShiftName') ? getShiftName($linkedEmployee['shift']) : 'Shift '.$linkedEmployee['shift']; ?>
                                                <?php if (!empty($linkedEmployee['level'])): ?>
                                                    - <?php echo function_exists('getLevelName') ? getLevelName($linkedEmployee['level']) : strtoupper($linkedEmployee['level']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <?php else: ?>
                                            <?php if (hasPermission('manage_employees')): ?>
                                            <form method="POST" action="" style="margin:0;" onsubmit="return this.querySelector('input[name=employeeEmail]').value.trim() !== '' || confirm('No email entered — auto-match by name?');">
                                                <input type="hidden" name="action" value="link_user_employee_by_email">
                                                <input type="hidden" name="userId" value="<?php echo (int)$user['id']; ?>">
                                                <div style="display:flex;gap:4px;align-items:center;">
                                                    <input type="text" name="employeeEmail" placeholder="old emp email…"
                                                           style="width:140px;padding:3px 6px;font-size:11px;border:1px solid #ccc;border-radius:3px;"
                                                           title="Enter the employee's current email (e.g. szangaruche@liquidweb.com) then click 🔗">
                                                    <button type="submit" title="Link employee" style="background:#6f42c1;color:#fff;border:none;border-radius:3px;padding:3px 7px;cursor:pointer;font-size:12px;">🔗</button>
                                                </div>
                                            </form>
                                            <?php else: ?>
                                            <span class="no-link">Not linked</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="status"><span class="status-badge status-<?php echo $user['active'] ? 'active' : 'inactive'; ?>"><?php echo $user['active'] ? 'Active' : 'Inactive'; ?></span></td>
                                    <td class="created-date"><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                    <td class="actions">
                                        <?php if (hasPermission('manage_users')): ?>
                                        <div class="user-actions">
                                            <?php if ($linkedEmployee): ?>
                                                <?php if (hasPermission('manage_employees')): ?>
                                                    <button class="action-btn edit" onclick="openEditEmployeeInline(<?php echo $linkedEmployee['id']; ?>)" title="Edit Employee Schedule">⚙️</button>
                                                    <button class="action-btn delete" onclick="ScheduleApp.confirmDelete(<?php echo $linkedEmployee['id']; ?>, '<?php echo addslashes($linkedEmployee['name']); ?>')" title="Delete Employee Record">🗑️</button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                    <button class="action-btn delete" onclick="ScheduleApp.confirmDeleteUser(<?php echo $user['id']; ?>, '<?php echo addslashes($userFullName); ?>')" title="Delete User Account">🗑️</button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <button class="action-btn view" onclick="openViewProfilePanel(<?php echo $user['id']; ?>)" title="View Profile">👁️</button>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div id="noSearchResults" class="no-results" style="display:none;">
                            <div class="no-results-content">
                                <h3>No users found</h3>
                                <p>Try adjusting your search criteria or filters</p>
                                <button onclick="clearUserSearch()" class="clear-search-btn">Clear Search</button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── Activity Log Section ── -->
        <?php if (function_exists('hasPermission') && hasPermission('manage_backups')): ?>
        <div class="sr-section" style="margin-top:28px;">
            <div class="sr-section-header">
                <span style="font-size:18px;">📋</span>
                <h3>Activity Log</h3>
                <div class="sr-section-divider"></div>
                <a href="?tab=settings" class="sr-btn sr-btn-outline sr-btn-sm" style="margin-left:8px; text-decoration:none;">🔄 Refresh</a>
                <form method="POST" action="" style="display:inline; margin-left:8px;"
                      onsubmit="return confirm('Clear all activity log entries? This cannot be undone.');">
                    <input type="hidden" name="action" value="clear_activity_log">
                    <button type="submit" class="sr-btn sr-btn-sm"
                            style="background:#dc2626; border-color:#dc2626; color:#fff; cursor:pointer;">
                        🗑️ Clear Log
                    </button>
                </form>
            </div>

            <div class="sr-card">
                <div class="sr-card-head">
                    <div class="sr-card-head-left">
                        <div class="sr-icon-badge soft">📋</div>
                        <div>
                            <div class="sr-card-title">Recent Activity</div>
                            <div class="sr-card-sub">Last 20 actions performed in the system</div>
                        </div>
                    </div>
                </div>
                <div class="sr-card-body" style="padding:0; max-height:520px; overflow-y:auto;">
                    <?php
                    // Load only the 20 rows we actually display.
                    // Try DB first (cheap, authoritative); fall back to JSON only if DB is unavailable.
                    $recentActivity = [];
                    $alDbLoaded     = false;

                    if (class_exists('Database')) {
                        try {
                            $alPdo = Database::getInstance()->getConnection();
                            $recentActivity = $alPdo->query(
                                "SELECT id, timestamp, user_name, user_role, action, details
                                 FROM activity_log
                                 ORDER BY timestamp DESC
                                 LIMIT 20"
                            )->fetchAll(PDO::FETCH_ASSOC);
                            $alDbLoaded = true;
                        } catch (Exception $e) { /* fall through to JSON */ }
                    }

                    // JSON fallback — only used when DB is unavailable
                    if (!$alDbLoaded) {
                        $alJsonFile = __DIR__ . '/activity_log.json';
                        if (file_exists($alJsonFile)) {
                            $alJsonData = json_decode(file_get_contents($alJsonFile), true);
                            if (!empty($alJsonData['logs'])) {
                                $alEntries = $alJsonData['logs'];
                                usort($alEntries, function($a, $b) {
                                    return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
                                });
                                $recentActivity = array_slice($alEntries, 0, 20);
                            }
                        }
                    }
                    if (empty($recentActivity)): ?>
                    <div style="padding:40px; text-align:center; color:var(--text-muted,#6c757d);">
                        <div style="font-size:42px; margin-bottom:10px;">📋</div>
                        <div style="font-size:14px;">No recent activity to display</div>
                    </div>
                    <?php else: ?>
                    <table style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr style="position:sticky; top:0; z-index:1; background:var(--card-bg,#f8f9fa); border-bottom:2px solid var(--border-color,#dee2e6);">
                                <th style="padding:10px 14px; width:36px;"></th>
                                <th style="padding:10px 14px; text-align:left; font-size:11px; font-weight:700; color:var(--text-muted,#6c757d); text-transform:uppercase; letter-spacing:0.5px;">Action</th>
                                <th style="padding:10px 14px; text-align:left; font-size:11px; font-weight:700; color:var(--text-muted,#6c757d); text-transform:uppercase; letter-spacing:0.5px;">Details</th>
                                <th style="padding:10px 14px; text-align:left; font-size:11px; font-weight:700; color:var(--text-muted,#6c757d); text-transform:uppercase; letter-spacing:0.5px;">User</th>
                                <th style="padding:10px 14px; text-align:left; font-size:11px; font-weight:700; color:var(--text-muted,#6c757d); text-transform:uppercase; letter-spacing:0.5px;">Time</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentActivity as $log):
                            $icon    = function_exists('getActivityIcon')    ? getActivityIcon($log['action'])         : '📌';
                            $timeStr = function_exists('formatActivityTime') ? formatActivityTime($log['timestamp'])   : $log['timestamp'];
                        ?>
                        <tr style="border-bottom:1px solid var(--border-color,#dee2e6);" onmouseover="this.style.background='rgba(0,0,0,0.025)'" onmouseout="this.style.background=''">
                            <td style="padding:10px 14px; font-size:18px; text-align:center;"><?php echo $icon; ?></td>
                            <td style="padding:10px 14px;">
                                <span style="font-size:13px; font-weight:600; color:var(--text-color,#212529);"><?php echo ucwords(str_replace('_', ' ', $log['action'])); ?></span>
                            </td>
                            <td style="padding:10px 14px; font-size:13px; color:var(--text-color,#212529); max-width:300px;"><?php echo function_exists('escapeHtml') ? escapeHtml($log['details']) : htmlspecialchars($log['details']); ?></td>
                            <td style="padding:10px 14px; font-size:13px; color:var(--text-muted,#6c757d); white-space:nowrap;">👤 <?php echo htmlspecialchars($log['user_name']); ?></td>
                            <td style="padding:10px 14px; font-size:12px; color:var(--text-muted,#6c757d); white-space:nowrap;"><?php echo $timeStr; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════════
             SECTION 3: Schedule Export
        ═════════════════════════════════════════════════ -->
        <!-- xlsx-js-style: SheetJS fork with full cell colour/font support -->
        <script src="https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js"></script>

        <style>
        /* ── Schedule Export Table ─────────────────────────── */
        .se-wrap {
            overflow-x:auto; border:1.5px solid var(--border-color,#dee2e6);
            border-radius:10px; max-height:460px; overflow-y:auto;
            background:var(--card-bg,#fff);
        }
        .se-table { border-collapse:collapse; width:100%; font-family:Arial,sans-serif; font-size:12.5px; }
        .se-table thead th {
            background:var(--primary-color,#1e3a5f); color:#fff; font-weight:700; padding:9px 11px;
            white-space:nowrap; position:sticky; top:0; z-index:2;
            border-right:1px solid rgba(255,255,255,0.15); letter-spacing:0.3px;
        }
        .se-table thead th:first-child { border-radius:8px 0 0 0; }
        .se-table thead th:last-child  { border-radius:0 8px 0 0; border-right:none; }
        .se-table tbody tr:nth-child(odd)  { background:var(--card-bg,#f8faff); }
        .se-table tbody tr:nth-child(even) { background:var(--body-bg,#ffffff); }
        .se-table tbody tr:hover           { background:var(--border-color,#eef4ff) !important; }
        .se-table td {
            padding:7px 11px;
            border-bottom:1px solid var(--border-color,#e9ecef);
            border-right:1px solid var(--border-color,#e9ecef);
            white-space:nowrap;
            color:var(--text-color,#1e293b);
        }
        .se-table td:last-child { border-right:none; }
        .se-td-name { font-weight:600; color:var(--text-color,#1e293b); min-width:140px; }
        /* Work/Off cells keep their semantic colours but stay readable on any background */
        .se-td-work { background:#c6efce !important; color:#276221 !important; font-weight:600; text-align:center; font-size:11px; }
        .se-td-off  { background:var(--border-color,#e2e8f0) !important; color:var(--text-muted,#666) !important; text-align:center; font-size:11px; }
        .se-team-badge {
            display:inline-block; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:600;
            white-space:nowrap;
        }
        /* ── Export section controls & labels ── */
        .se-export-section { color:var(--text-color,#1e293b); }
        .se-export-section label { color:var(--text-color,#333) !important; }
        </style>

        <div class="sr-section" style="margin-top:28px;">
            <div class="sr-section-header">
                <span style="font-size:18px;">📊</span>
                <h3>Schedule Export</h3>
                <div class="sr-section-divider"></div>
            </div>

            <div class="sr-card">
                <div class="sr-card-head">
                    <div class="sr-card-head-left">
                        <div class="sr-icon-badge soft">📋</div>
                        <div>
                            <div class="sr-card-title">Export to Google Sheets / Excel</div>
                            <div class="sr-card-sub">Colour-coded preview — download as .xlsx with formatting or copy as TSV to paste directly into Google Sheets</div>
                        </div>
                    </div>
                </div>
                <div class="sr-card-body">

                    <!-- Controls row -->
                    <div style="display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end; margin-bottom:18px;">

                        <!-- Team filter -->
                        <div class="sr-fg" style="min-width:170px;">
                            <label class="sr-lbl">Filter by Team</label>
                            <select id="exportTeamFilter" class="sr-ctrl" onchange="buildScheduleExport()">
                                <option value="">All Teams</option>
                                <?php
                                $exportTeams = [];
                                foreach ($employees as $emp) {
                                    $t = trim($emp['team'] ?? '');
                                    if ($t !== '' && !in_array($t, $exportTeams)) $exportTeams[] = $t;
                                }
                                sort($exportTeams);
                                foreach ($exportTeams as $t):
                                ?>
                                <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Skill filter -->
                        <div class="sr-fg" style="min-width:150px;">
                            <label class="sr-lbl">Filter by Skill</label>
                            <select id="exportSkillFilter" class="sr-ctrl" onchange="buildScheduleExport()">
                                <option value="">All Employees</option>
                                <option value="mh">MH Only</option>
                                <option value="ma">MA Only</option>
                                <option value="win">WIN Only</option>
                            </select>
                        </div>

                        <!-- Sort by -->
                        <div class="sr-fg" style="min-width:150px;">
                            <label class="sr-lbl">Sort By</label>
                            <select id="exportSortBy" class="sr-ctrl" onchange="buildScheduleExport()">
                                <option value="name">Name</option>
                                <option value="team">Team</option>
                                <option value="shift">Shift</option>
                                <option value="level">Level</option>
                            </select>
                        </div>

                        <!-- Optional columns -->
                        <div class="sr-fg">
                            <label class="sr-lbl">Include Columns</label>
                            <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:4px;">
                                <?php
                                $exportCols = ['email'=>'Email','level'=>'Level','shift'=>'Shift','hours'=>'Hours','supervisor'=>'Supervisor','skills'=>'Skills'];
                                foreach ($exportCols as $colKey => $colLabel):
                                ?>
                                <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;color:var(--text-color,#333);">
                                    <input type="checkbox" class="export-col-toggle" value="<?php echo $colKey; ?>"
                                           <?php echo in_array($colKey, ['level','shift','hours']) ? 'checked' : ''; ?>
                                           onchange="buildScheduleExport()"
                                           style="accent-color:var(--primary-color);width:14px;height:14px;">
                                    <?php echo $colLabel; ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Action buttons -->
                    <div style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap; align-items:center;">
                        <button onclick="downloadScheduleXLSX()" class="sr-btn sr-btn-primary sr-btn-sm">⬇️ Download .xlsx</button>
                        <button onclick="copyScheduleExport()"    class="sr-btn sr-btn-outline sr-btn-sm">📋 Copy TSV for Sheets</button>
                        <button onclick="downloadScheduleCSV()"   class="sr-btn sr-btn-outline sr-btn-sm">⬇️ Download CSV</button>
                        <span id="exportStatus" style="font-size:12px;color:var(--text-muted,#666);"></span>
                        <span id="exportRowCount" style="margin-left:auto;font-size:12px;color:var(--text-muted,#666);"></span>
                    </div>

                    <!-- Colour-coded preview table -->
                    <div class="se-wrap">
                        <table class="se-table" id="scheduleExportTable">
                            <thead><tr id="seHead"></tr></thead>
                            <tbody id="seBody"></tbody>
                        </table>
                    </div>
                    <div id="seEmpty" style="display:none;padding:30px;text-align:center;color:var(--text-muted,#888);font-size:14px;">No employees match the selected filter.</div>

                </div>
            </div>
        </div>

        <?php
        // Build JS employee data array
        $exportData = [];
        foreach ($employees as $emp) {
            $shiftNum  = intval($emp['shift'] ?? 1);
            $shiftName = function_exists('getShiftName') ? getShiftName($shiftNum) : "Shift $shiftNum";
            $levelName = function_exists('getLevelName') ? getLevelName($emp['level'] ?? '') : ($emp['level'] ?? '');
            $supervisorName = '';
            if (!empty($emp['supervisor_id'])) {
                foreach ($employees as $s) {
                    if (isset($s['id']) && $s['id'] == $emp['supervisor_id']) { $supervisorName = $s['name']; break; }
                }
            }
            if ($supervisorName === '' && !empty($emp['supervisor'])) $supervisorName = $emp['supervisor'];
            $rawSkills = $emp['skills'] ?? [];
            if (is_string($rawSkills)) $rawSkills = json_decode($rawSkills, true) ?? [];
            $exportData[] = [
                'name'       => $emp['name'] ?? '',
                'email'      => $emp['email'] ?? '',
                'team'       => $emp['team'] ?? '',
                'level'      => $levelName,
                'shift'      => $shiftName,
                'hours'      => $emp['hours'] ?? '',
                'supervisor' => $supervisorName,
                'schedule'   => array_values(array_slice(array_map('intval', (array)($emp['schedule'] ?? [])), 0, 7)),
                'skills'     => [
                    'mh'  => !empty($rawSkills['mh']),
                    'ma'  => !empty($rawSkills['ma']),
                    'win' => !empty($rawSkills['win']),
                ],
            ];
        }
        ?>
        <script>
        const scheduleExportData = <?php echo json_encode($exportData, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        const seDAYS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

        // Assign a stable colour to each team name — adapts to dark/light theme
        const seTeamColours = {};
        const seTeamPalettLight = [
            ['#dbeafe','#1e40af'], // blue
            ['#dcfce7','#166534'], // green
            ['#fce7f3','#9d174d'], // pink
            ['#fef3c7','#92400e'], // amber
            ['#ede9fe','#5b21b6'], // violet
            ['#ffedd5','#c2410c'], // orange
            ['#cffafe','#0e7490'], // cyan
            ['#f0fdf4','#15803d'], // emerald
            ['#fee2e2','#991b1b'], // red
            ['#f1f5f9','#334155'], // slate
        ];
        const seTeamPaletteDark = [
            ['#1e3a5f','#93c5fd'], // blue
            ['#14532d','#86efac'], // green
            ['#4a044e','#f0abfc'], // pink
            ['#451a03','#fcd34d'], // amber
            ['#2e1065','#c4b5fd'], // violet
            ['#431407','#fdba74'], // orange
            ['#083344','#67e8f9'], // cyan
            ['#052e16','#6ee7b7'], // emerald
            ['#450a0a','#fca5a5'], // red
            ['#0f172a','#94a3b8'], // slate
        ];
        function seIsDark() {
            // Check if a dark theme is active by looking at body background brightness
            const bg = getComputedStyle(document.body).backgroundColor;
            const m = bg.match(/\d+/g);
            if (!m || m.length < 3) return false;
            return (parseInt(m[0])*299 + parseInt(m[1])*587 + parseInt(m[2])*114) / 1000 < 80;
        }
        function seTeamColour(team) {
            const dark = seIsDark();
            const palette = dark ? seTeamPaletteDark : seTeamPalettLight;
            if (!seTeamColours[team]) {
                const idx = Object.keys(seTeamColours).length % palette.length;
                seTeamColours[team] = idx; // store index
            }
            return palette[seTeamColours[team] % palette.length];
        }

        function seGetRows() {
            const tf   = document.getElementById('exportTeamFilter')?.value || '';
            const sf   = document.getElementById('exportSkillFilter')?.value || '';
            const sort = document.getElementById('exportSortBy')?.value || 'name';
            const cols = Array.from(document.querySelectorAll('.export-col-toggle:checked')).map(el => el.value);
            let rows = scheduleExportData.filter(e => {
                if (tf && e.team !== tf) return false;
                if (sf && !e.skills?.[sf]) return false;
                return true;
            });
            rows.sort((a,b) => (a[sort]||'').toString().toLowerCase().localeCompare((b[sort]||'').toString().toLowerCase()));
            return { rows, cols };
        }

        function buildScheduleExport() {
            const { rows, cols } = seGetRows();

            // Build header
            const headers = ['Name','Team'];
            if (cols.includes('email'))      headers.push('Email');
            if (cols.includes('level'))      headers.push('Level');
            if (cols.includes('shift'))      headers.push('Shift');
            if (cols.includes('hours'))      headers.push('Hours');
            if (cols.includes('supervisor')) headers.push('Supervisor');
            headers.push(...seDAYS);

            // Render header
            const thead = document.getElementById('seHead');
            thead.innerHTML = '';
            headers.forEach(h => {
                const th = document.createElement('th');
                th.textContent = h;
                thead.appendChild(th);
            });

            // Render rows
            const tbody = document.getElementById('seBody');
            tbody.innerHTML = '';
            rows.forEach((e, ri) => {
                const sched = e.schedule && e.schedule.length === 7 ? e.schedule : [0,0,0,0,0,0,0];
                const tr = document.createElement('tr');

                // Name
                const tdName = document.createElement('td');
                tdName.className = 'se-td-name';
                tdName.textContent = e.name;
                tr.appendChild(tdName);

                // Team badge
                const tdTeam = document.createElement('td');
                const [bg, fg] = seTeamColour(e.team);
                tdTeam.innerHTML = `<span class="se-team-badge" style="background:${bg} !important;color:${fg} !important;">${e.team||'—'}</span>`;
                tr.appendChild(tdTeam);

                if (cols.includes('email'))      { const td=document.createElement('td'); td.textContent=e.email;      tr.appendChild(td); }
                if (cols.includes('level'))      { const td=document.createElement('td'); td.textContent=e.level;      tr.appendChild(td); }
                if (cols.includes('shift'))      { const td=document.createElement('td'); td.textContent=e.shift;      tr.appendChild(td); }
                if (cols.includes('hours'))      { const td=document.createElement('td'); td.textContent=e.hours;      tr.appendChild(td); }
                if (cols.includes('supervisor')) { const td=document.createElement('td'); td.textContent=e.supervisor; tr.appendChild(td); }

                // Day cells
                sched.forEach(d => {
                    const td = document.createElement('td');
                    td.className = d ? 'se-td-work' : 'se-td-off';
                    td.textContent = d ? '✔ Working' : 'Off';
                    tr.appendChild(td);
                });

                tbody.appendChild(tr);
            });

            const empty = document.getElementById('seEmpty');
            const wrap  = document.querySelector('.se-wrap');
            if (rows.length === 0) { wrap.style.display='none'; empty.style.display='block'; }
            else                   { wrap.style.display='';     empty.style.display='none'; }

            document.getElementById('exportRowCount').textContent = `${rows.length} employee${rows.length!==1?'s':''}`;

            return { rows, cols, headers };
        }

        /* ── Copy TSV to clipboard (pastes into Google Sheets) ── */
        function copyScheduleExport() {
            const { rows, cols, headers } = buildScheduleExport();
            const lines = [headers.join('\t')];
            rows.forEach(e => {
                const r = [e.name, e.team];
                if (cols.includes('email'))      r.push(e.email);
                if (cols.includes('level'))      r.push(e.level);
                if (cols.includes('shift'))      r.push(e.shift);
                if (cols.includes('hours'))      r.push(e.hours);
                if (cols.includes('supervisor')) r.push(e.supervisor);
                const sched = e.schedule && e.schedule.length===7 ? e.schedule : [0,0,0,0,0,0,0];
                sched.forEach(d => r.push(d ? 'Working' : 'Off'));
                lines.push(r.join('\t'));
            });
            const tsv = lines.join('\n');
            const statusEl = document.getElementById('exportStatus');
            (navigator.clipboard?.writeText(tsv) || Promise.reject())
                .then(() => { statusEl.textContent='✓ Copied! Paste directly into Google Sheets.'; setTimeout(()=>statusEl.textContent='',4000); })
                .catch(() => { statusEl.textContent='Select the preview table and copy manually.'; setTimeout(()=>statusEl.textContent='',4000); });
        }

        /* ── Download CSV ── */
        function downloadScheduleCSV() {
            const { rows, cols, headers } = buildScheduleExport();
            const escape = s => { const v=String(s); return (v.includes(',')||v.includes('"')||v.includes('\n')) ? '"'+v.replace(/"/g,'""')+'"' : v; };
            const lines  = [headers.map(escape).join(',')];
            rows.forEach(e => {
                const r = [e.name, e.team];
                if (cols.includes('email'))      r.push(e.email);
                if (cols.includes('level'))      r.push(e.level);
                if (cols.includes('shift'))      r.push(e.shift);
                if (cols.includes('hours'))      r.push(e.hours);
                if (cols.includes('supervisor')) r.push(e.supervisor);
                const sched = e.schedule && e.schedule.length===7 ? e.schedule : [0,0,0,0,0,0,0];
                sched.forEach(d => r.push(d ? 'Working' : 'Off'));
                lines.push(r.map(escape).join(','));
            });
            const tf  = document.getElementById('exportTeamFilter')?.value || '';
            const fn  = 'schedule_export'+(tf?'_'+tf.replace(/\s+/g,'_'):'')+'_'+new Date().toISOString().split('T')[0]+'.csv';
            const url = URL.createObjectURL(new Blob([lines.join('\n')], {type:'text/csv;charset=utf-8;'}));
            const a   = Object.assign(document.createElement('a'),{href:url,download:fn});
            document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
            const statusEl = document.getElementById('exportStatus');
            statusEl.textContent=`✓ Downloaded ${fn}`; setTimeout(()=>statusEl.textContent='',4000);
        }

        /* ── Download coloured .xlsx ── */
        function downloadScheduleXLSX() {
            if (typeof XLSXStyle === 'undefined' && typeof XLSX === 'undefined') {
                alert('XLSX library not loaded yet — please wait a moment and try again.');
                return;
            }
            const XL = typeof XLSXStyle !== 'undefined' ? XLSXStyle : XLSX;

            const { rows, cols, headers } = buildScheduleExport();

            // Style helpers
            const hdrStyle = { font:{bold:true,color:{rgb:'FFFFFF'},name:'Arial',sz:11}, fill:{fgColor:{rgb:'1E3A5F'}}, alignment:{horizontal:'center',vertical:'center'}, border:{bottom:{style:'medium',color:{rgb:'FFFFFF'}}} };
            const workStyle = { font:{bold:true,color:{rgb:'276221'},name:'Arial',sz:10}, fill:{fgColor:{rgb:'C6EFCE'}}, alignment:{horizontal:'center'} };
            const offStyle  = { font:{color:{rgb:'999999'},name:'Arial',sz:10},          fill:{fgColor:{rgb:'F2F2F2'}}, alignment:{horizontal:'center'} };
            const nameStyle = { font:{bold:true,color:{rgb:'1E293B'},name:'Arial',sz:10} };
            const teamStyle = { font:{bold:true,name:'Arial',sz:10}, fill:{fgColor:{rgb:'DBEAFE'}} };
            const baseStyle = { font:{name:'Arial',sz:10} };
            const oddStyle  = { font:{name:'Arial',sz:10}, fill:{fgColor:{rgb:'F8FAFF'}} };

            const wsData = [];

            // Header row
            wsData.push(headers.map(h => ({ v:h, t:'s', s:hdrStyle })));

            // Data rows
            rows.forEach((e, ri) => {
                const sched = e.schedule && e.schedule.length===7 ? e.schedule : [0,0,0,0,0,0,0];
                const bg    = ri%2===0 ? baseStyle : oddStyle;
                const cells = [
                    { v:e.name,  t:'s', s:nameStyle },
                    { v:e.team,  t:'s', s:{...teamStyle, fill:{fgColor:{rgb:'DBEAFE'}}} },
                ];
                if (cols.includes('email'))      cells.push({ v:e.email,      t:'s', s:bg });
                if (cols.includes('level'))      cells.push({ v:e.level,      t:'s', s:bg });
                if (cols.includes('shift'))      cells.push({ v:e.shift,      t:'s', s:bg });
                if (cols.includes('hours'))      cells.push({ v:e.hours,      t:'s', s:bg });
                if (cols.includes('supervisor')) cells.push({ v:e.supervisor, t:'s', s:bg });
                sched.forEach(d => cells.push({ v: d ? 'Working' : 'Off', t:'s', s: d ? workStyle : offStyle }));
                wsData.push(cells);
            });

            const ws = XL.utils.aoa_to_sheet(wsData.map(r => r.map(c => c.v)));

            // Apply styles cell by cell
            wsData.forEach((row, ri) => {
                row.forEach((cell, ci) => {
                    const addr = XL.utils.encode_cell({r:ri, c:ci});
                    if (!ws[addr]) ws[addr] = {};
                    ws[addr].s = cell.s;
                });
            });

            // Column widths
            const colW = headers.map((h,i) => {
                if (h==='Name')  return {wch:24};
                if (h==='Email') return {wch:28};
                if (seDAYS.includes(h)) return {wch:11};
                return {wch:14};
            });
            ws['!cols'] = colW;
            ws['!rows'] = wsData.map(()=>({hpt:18}));

            const tf  = document.getElementById('exportTeamFilter')?.value || '';
            const fn  = 'schedule_export'+(tf?'_'+tf.replace(/\s+/g,'_'):'')+'_'+new Date().toISOString().split('T')[0]+'.xlsx';
            const wb  = XL.utils.book_new();
            XL.utils.book_append_sheet(wb, ws, 'Schedule');
            XL.writeFile(wb, fn);

            const statusEl = document.getElementById('exportStatus');
            statusEl.textContent=`✓ Downloaded ${fn}`; setTimeout(()=>statusEl.textContent='',4000);
        }

        document.addEventListener('DOMContentLoaded', buildScheduleExport);
        // Rebuild export table when theme changes so team badge colours update
        // Use subtree+characterData to catch style element text content changes (how ThemeManager applies themes)
        const _seObserver = new MutationObserver(() => {
            Object.keys(seTeamColours).forEach(k => delete seTeamColours[k]); // clear colour cache
            requestAnimationFrame(buildScheduleExport); // defer one frame so new CSS is painted first
        });
        _seObserver.observe(document.head, { childList: true, subtree: true, characterData: true });
        </script>

    </div><!-- /sr -->

    <!-- Edit MOTD Modal -->
    <div id="editMOTDModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:10000;align-items:center;justify-content:center;">
        <div style="background:var(--card-bg,#fff);padding:30px;border-radius:14px;max-width:600px;width:90%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <h3 style="margin:0 0 20px;color:var(--text-color,#333);font-weight:700;">✏️ Edit Message</h3>
            <form method="POST" action="index.php" id="editMOTDForm">
                <input type="hidden" name="action" value="edit_motd">
                <input type="hidden" name="motd_id" id="edit_motd_id">
                <div style="margin-bottom:14px;">
                    <label class="sr-lbl" style="display:block;margin-bottom:6px;">Message:</label>
                    <textarea name="motd_message" id="edit_motd_message" class="sr-ctrl" rows="3" required></textarea>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                        <label class="sr-lbl" style="display:block;margin-bottom:6px;">Start Date:</label>
                        <input type="date" name="start_date" id="edit_start_date" class="sr-ctrl">
                    </div>
                    <div>
                        <label class="sr-lbl" style="display:block;margin-bottom:6px;">End Date:</label>
                        <input type="date" name="end_date" id="edit_end_date" class="sr-ctrl">
                    </div>
                </div>
                <div style="margin-bottom:20px;">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;color:var(--text-color,#333);">
                        <input type="checkbox" name="include_anniversaries" id="edit_include_anniversaries" style="width:16px;height:16px;accent-color:var(--primary-color);cursor:pointer;">
                        <span style="font-weight:600;">🎉 Include work anniversaries</span>
                    </label>
                </div>
                <div style="display:flex;gap:10px;">
                    <button type="submit" class="sr-btn sr-btn-primary">💾 Update Message</button>
                    <button type="button" onclick="closeEditMOTDModal()" class="sr-btn sr-btn-ghost">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // ── MOTD Data for JS editing ──────────────────────────
    const motdData = <?php echo json_encode($allMOTDs ?? []); ?>;

    function showAddMOTDForm()  { document.getElementById('addMOTDForm').style.display = 'block'; }
    function hideAddMOTDForm()  { document.getElementById('addMOTDForm').style.display = 'none'; }

    function editMOTD(motdId) {
        const motd = motdData.find(m => m.id === motdId);
        if (!motd) return;
        document.getElementById('edit_motd_id').value = motd.id;
        document.getElementById('edit_motd_message').value = motd.message;
        document.getElementById('edit_start_date').value = motd.start_date || '';
        document.getElementById('edit_end_date').value = motd.end_date || '';
        document.getElementById('edit_include_anniversaries').checked = motd.include_anniversaries;
        document.getElementById('editMOTDModal').style.display = 'flex';
    }

    function closeEditMOTDModal() { document.getElementById('editMOTDModal').style.display = 'none'; }

    function deleteMOTD(motdId, preview) {
        if (!confirm('Delete this message?\n\n"' + preview + '..."\n\nThis cannot be undone.')) return;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'index.php';
        form.innerHTML = '<input type="hidden" name="action" value="delete_motd"><input type="hidden" name="motd_id" value="' + motdId + '">';
        document.body.appendChild(form);
        form.submit();
    }

    document.getElementById('editMOTDModal')?.addEventListener('click', function(e) { if (e.target === this) closeEditMOTDModal(); });

    // ── User Search & Filter ──────────────────────────────
    let currentSort = { field: null, direction: 'asc' };

    function filterUsers() {
        const q      = (document.getElementById('userSearchInput')?.value || '').toLowerCase();
        const role   = document.getElementById('roleFilter')?.value || '';
        const level  = document.getElementById('levelFilter')?.value || '';
        const status = document.getElementById('statusFilter')?.value || '';
        const link   = document.getElementById('linkFilter')?.value || '';

        // Always ensure the list is visible when filterUsers() runs
        const c = document.getElementById('usersListContainer');
        if (c && c.style.display === 'none') setUsersListState(true);

        const rows = document.querySelectorAll('#userTableBody .user-row');
        let visible = 0;

        rows.forEach(row => {
            const match =
                (!q      || row.dataset.name.includes(q) || row.dataset.username.includes(q) || row.dataset.email.includes(q) || row.dataset.role.includes(q) || row.dataset.level.includes(q)) &&
                (!role   || row.dataset.role   === role) &&
                (!level  || (level === 'none' && row.dataset.level === '') || row.dataset.level === level.toLowerCase()) &&
                (!status || row.dataset.status === status) &&
                (!link   || row.dataset.linked === link);

            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });

        const total = rows.length;
        document.getElementById('searchResults').textContent =
            visible === total ? `Showing all ${total} users` : `Showing ${visible} of ${total} users`;

        const noRes = document.getElementById('noSearchResults');
        if (noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
    }

    function clearUserSearch() { document.getElementById('userSearchInput').value = ''; resetUserFilters(); }

    function resetUserFilters() {
        ['userSearchInput','roleFilter','levelFilter','statusFilter','linkFilter'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        filterUsers();
    }

    function setUsersListState(open) {
        const c    = document.getElementById('usersListContainer');
        const icon = document.getElementById('toggleUsersIcon');
        const txt  = document.getElementById('toggleUsersText');
        if (!c) return;
        c.style.display = open ? 'block' : 'none';
        icon.textContent = open ? '▼' : '▶';
        txt.textContent  = open ? 'Collapse List' : 'Expand List';
        try { sessionStorage.setItem('usersListOpen', open ? '1' : '0'); } catch(e) {}
    }
    window.setUsersListState = setUsersListState;

    function toggleUsersList() {
        const c = document.getElementById('usersListContainer');
        if (!c) return;
        const isOpen = c.style.display !== 'none';
        setUsersListState(!isOpen);
    }
    window.toggleUsersList = toggleUsersList;

    function sortUserTable(field) {
        const tbody = document.getElementById('userTableBody');
        const rows  = Array.from(tbody.querySelectorAll('.user-row'));
        if (currentSort.field === field) { currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc'; }
        else { currentSort.field = field; currentSort.direction = 'asc'; }

        document.querySelectorAll('.sort-indicator').forEach(i => { i.textContent = ''; i.classList.remove('sort-asc','sort-desc'); });
        const ind = document.querySelector(`[data-sort="${field}"]`);
        if (ind) { ind.textContent = currentSort.direction === 'asc' ? '▲' : '▼'; ind.classList.add(`sort-${currentSort.direction}`); }

        rows.sort((a, b) => {
            const map = { full_name:'name', username:'username', email:'email', role:'role', level:'level', active:'status', created_at:'created' };
            const key = map[field];
            if (field === 'created_at') {
                return currentSort.direction === 'asc' ? new Date(a.dataset.created) - new Date(b.dataset.created) : new Date(b.dataset.created) - new Date(a.dataset.created);
            }
            const av = a.dataset[key] || '', bv = b.dataset[key] || '';
            return currentSort.direction === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
        });
        rows.forEach(r => tbody.appendChild(r));
    }

    // ── Email Export ──────────────────────────────────────────────────────────

    function showEmailExportStatus(msg, type) {
        const el = document.getElementById('emailExportStatus');
        if (!el) return;
        el.textContent = msg;
        el.className   = 'sr-export-out on';
        el.style.background = type === 'success' ? 'color-mix(in srgb, #10b981 15%, transparent)' : 'color-mix(in srgb, #ef4444 15%, transparent)';
        setTimeout(() => { el.className = 'sr-export-out'; }, 4000);
    }

    // Returns the selected export team filter value (empty string = all teams)
    function getExportTeam() {
        return document.getElementById('emailExportTeamFilter')?.value || '';
    }

    // Update button labels when team filter changes
    function updateEmailExportButtons() {
        const team = getExportTeam();
        const label = team ? team.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()) + ' Team' : 'All';
        const txtBtn  = document.getElementById('emailExportTxtBtn');
        const copyBtn = document.getElementById('emailExportCopyBtn');
        if (txtBtn)  txtBtn.innerHTML  = '📥 Export ' + label + ' to TXT';
        if (copyBtn) copyBtn.innerHTML = '📋 Copy ' + label + ' Emails';
    }

    // Filter all user rows by the export team selector
    function getExportRows() {
        const team = getExportTeam();
        const allRows = Array.from(document.querySelectorAll('#userTable tbody tr.user-row'));
        if (!team) return allRows;
        return allRows.filter(row => (row.dataset.team || '').toLowerCase() === team.toLowerCase());
    }

    function exportAllUserEmailsToFile() {
        const rows    = getExportRows();
        const team    = getExportTeam();
        const userData = rows.map(row => {
            const email = (row.dataset.email || row.querySelector('.email a')?.textContent || '').trim();
            return email ? { email, name:row.dataset.name, role:row.dataset.role, team:row.dataset.team, level:row.dataset.level||'none' } : null;
        }).filter(e => e);
        if (!userData.length) { showEmailExportStatus('No emails found' + (team ? ' for team: ' + team : ''), 'error'); return; }
        const teamLabel = team ? team.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()) : 'All Teams';
        const content =
            '═══════════════════════════════════════\n    USER EMAIL LIST WITH DETAILS\n═══════════════════════════════════════\n\n' +
            'Generated: ' + new Date().toLocaleString() + '\nTotal Users: ' + userData.length + '\nTeam: ' + teamLabel + '\n\n' +
            '═══════════════════════════════════════\n\nEMAIL LIST:\n───────────────────────────────────────\n' +
            userData.map(u => u.email).join('\n') + '\n\n' +
            '═══════════════════════════════════════\n\nDETAILED LIST:\n───────────────────────────────────────\n' +
            userData.map(u => u.email+'\n  Name: '+u.name+'\n  Role: '+u.role+'\n  Team: '+u.team+'\n  Level: '+(u.level==='none'||!u.level?'Not set':u.level.toUpperCase())+'\n').join('\n');
        const filename = team
            ? 'emails_' + team.replace(/\s+/g,'_') + '_' + new Date().toISOString().split('T')[0] + '.txt'
            : 'user_emails_' + new Date().toISOString().split('T')[0] + '.txt';
        const blob = new Blob([content], {type:'text/plain'});
        const url  = URL.createObjectURL(blob);
        const a    = Object.assign(document.createElement('a'), {href:url, download:filename});
        document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
        showEmailExportStatus('✓ Downloaded ' + userData.length + ' emails (' + teamLabel + ')!', 'success');
    }

    function exportFilteredTeamEmails() {
        const rows  = getExportRows();
        const team  = getExportTeam();
        const emails = rows.map(row => (row.dataset.email || row.querySelector('.email a')?.textContent || '').trim()).filter(e => e);
        if (!emails.length) { showEmailExportStatus('No emails found' + (team ? ' for team: ' + team : ''), 'error'); return; }
        const teamLabel = team ? team.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()) : 'All Teams';
        copyToClipboard(emails.join('; '), emails.length, teamLabel);
    }

    // Legacy — kept for compatibility
    function exportAllUserEmails() { exportFilteredTeamEmails(); }
    function exportFilteredUserEmails() { exportFilteredTeamEmails(); }

    function copyToClipboard(text, count, teamLabel) {
        const label = teamLabel ? ' (' + teamLabel + ')' : '';
        const msg   = '✓ Copied ' + count + ' email' + (count>1?'s':'') + label + '!';
        (navigator.clipboard?.writeText(text) || Promise.reject())
            .then(() => showEmailExportStatus(msg, 'success'))
            .catch(() => {
                const ta = Object.assign(document.createElement('textarea'), {value:text, style:'position:fixed;opacity:0'});
                document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); showEmailExportStatus(msg, 'success'); }
                catch { showEmailExportStatus('Copy failed. Try manually.', 'error'); }
                document.body.removeChild(ta);
            });
    }

    // ── Init ──────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function() {
        // Restore expand/collapse state from sessionStorage (default = open)
        try {
            const saved = sessionStorage.getItem('usersListOpen');
            // If explicitly set to closed, collapse; otherwise default to open
            if (saved === '0') setUsersListState(false);
        } catch(e) {}

        // Clear all filters on load first so browser form-restoration can't
        // leave a stale filter (e.g. statusFilter='active') that hides rows.
        ['userSearchInput','roleFilter','levelFilter','statusFilter','linkFilter'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        filterUsers();

        const si = document.getElementById('userSearchInput');
        if (si) si.focus();
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'f') { e.preventDefault(); si?.focus(); si?.select(); }
            if (e.key === 'Escape') clearUserSearch();
        });
    });
    </script>

    <?php
    // Append the modals HTML (link/unlink employee modal, edit user modal, etc.)
    echo getUserManagementModalsHTML();

    return ob_get_clean();
}

// Get Settings Modals HTML
function getUserManagementModalsHTML() {
    global $roles, $employees;

    if (!hasPermission('manage_users')) {
        return '';
    }

    // Ensure $employees is loaded — fall back to a direct DB query if the global is empty
    if (empty($employees) && class_exists('Database')) {
        try {
            $employees = Database::getInstance()->fetchAll(
                "SELECT id, name, team, email FROM employees WHERE active = 1 ORDER BY name"
            );
        } catch (Exception $e) { $employees = []; }
    }
    
    ob_start();
    ?>
    <!-- Add User Modal -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <h2>Add New User</h2>

            <form method="POST">
                <input type="hidden" name="action" value="add_user">

                <div class="form-group">
                    <label>Full Name:</label>
                    <input type="text" name="full_name" required placeholder="Enter full name">
                </div>

                <div class="form-group">
                    <label>Username:</label>
                    <input type="text" name="username" required placeholder="Enter username">
                </div>

                <div class="form-group">
                    <label>Email:</label>
                    <input type="email" name="email" required placeholder="Enter email address">
                </div>

                <div class="form-group">
                    <label>Authentication Method:</label>
                    <select name="auth_method" id="addUserAuthMethod" onchange="toggleAddUserPassword(this.value)">
                        <option value="google">Google SSO only</option>
                        <option value="both">Google SSO + Password</option>
                        <option value="local">Password only</option>
                    </select>
                </div>

                <div class="form-group" id="addUserPasswordGroup" style="display:none;">
                    <label>Password:</label>
                    <input type="password" name="password" id="addUserPassword" placeholder="Enter password">
                </div>

                <div class="form-group">
                    <label>Role:</label>
                    <select name="role" required>
                        <?php foreach ($roles as $roleKey => $roleInfo): ?>
                        <option value="<?php echo $roleKey; ?>"><?php echo $roleInfo['name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Team Access:</label>
                    <select name="team" required>
                        <option value="all">All Teams</option>
                        <option value="tams">TAMS</option>
                        <option value="esg">ESG</option>
                        <option value="support">Support</option>
                        <option value="windows">Windows</option>
                        <option value="security">Security</option>
                        <option value="secops_abuse">SecOps/Abuse</option>
                        <option value="supervisor">Supervisors</option>
                        <option value="migrations">Migrations</option>
                        <option value="manager">Manager</option>
                        <option value="Implementations">Implementations</option>
                        <option value="Account Services">Account Services</option>
                    </select>
                </div>

                <div style="text-align: center; margin-top: 20px;">
                    <button type="submit">Add User</button>
                    <button type="button" onclick="ScheduleApp.closeAddUserModal()" style="background: var(--secondary-color, #6c757d); margin-left: 10px;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <h2>Edit User</h2>
            
            <form method="POST">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="userId" id="editUserId">
                
                <div class="form-group">
                    <label>Full Name:</label>
                    <input type="text" name="full_name" id="editUserFullName" required placeholder="Enter full name">
                </div>
                
                <div class="form-group">
                    <label>Username:</label>
                    <input type="text" name="username" id="editUserUsername" required placeholder="Enter username">
                </div>
                
                <div class="form-group">
                    <label>Email:</label>
                    <input type="email" name="email" id="editUserEmail" required placeholder="Enter email address">
                </div>
                
                <div class="form-group">
                    <label>New Password (leave blank to keep current):</label>
                    <input type="password" name="new_password" id="editUserPassword" placeholder="Enter new password">
                </div>
                
                <div class="form-group">
                    <label>Role:</label>
                    <select name="role" id="editUserRole" required>
                        <?php foreach ($roles as $roleKey => $roleInfo): ?>
                        <option value="<?php echo $roleKey; ?>"><?php echo $roleInfo['name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Team Access:</label>
                    <select name="team" id="editUserTeam" required>
                        <option value="all">All Teams</option>
                        <option value="tams">TAMS</option>
                        <option value="esg">ESG</option>
                        <option value="support">Support</option>
                        <option value="windows">Windows</option>
                        <option value="security">Security</option>
                        <option value="secops_abuse">SecOps/Abuse</option>
                        <option value="supervisor">Supervisors</option>
                        <option value="migrations">Migrations</option>
                        <option value="manager">Manager</option>
                        <option value="Implementations">Implementations</option>
                        <option value="Account Services">Account Services</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="active" id="editUserActive" checked> Active
                    </label>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <button type="submit">Update User</button>
                    <button type="button" onclick="ScheduleApp.closeEditUserModal()" style="background: var(--secondary-color, #6c757d); margin-left: 10px;">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Link Employee Modal -->
    <div id="linkEmployeeModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:var(--card-bg,#fff);border-radius:8px;padding:28px;max-width:460px;width:90%;box-shadow:0 4px 24px rgba(0,0,0,0.2);">
            <h3 style="margin:0 0 6px;font-size:18px;">🔗 Link Employee Record</h3>
            <p style="margin:0 0 4px;color:var(--text-muted,#666);font-size:14px;">
                Linking user: <strong><span id="linkUserName"></span></strong>
            </p>
            <p style="margin:0 0 18px;color:var(--text-muted,#666);font-size:13px;">
                Enter the employee's <strong>current email</strong> in the system (the one on their employee record). The system will update it to match this user account email so they link up.
            </p>
            <form method="POST" action="">
                <input type="hidden" name="action" value="link_user_employee_by_email">
                <input type="hidden" name="userId" id="linkUserId" value="">
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;font-size:14px;">
                        Employee's current email <span style="font-weight:400;color:#888;">(e.g. szangaruche@liquidweb.com)</span>
                    </label>
                    <input type="email" name="employeeEmail" id="linkEmployeeEmail" placeholder="employee@liquidweb.com"
                           style="width:100%;padding:9px 12px;border:1px solid var(--border-color,#ddd);border-radius:5px;font-size:14px;box-sizing:border-box;">
                    <div id="linkEmailHint" style="margin-top:6px;font-size:12px;color:#888;">Leave blank to auto-match by name from the user's email address.</div>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:4px;">
                    <button type="button" onclick="closeLinkEmployeeModal()"
                            style="padding:8px 18px;border:1px solid var(--border-color,#ddd);background:transparent;border-radius:5px;cursor:pointer;font-size:14px;">Cancel</button>
                    <button type="submit"
                            style="padding:8px 18px;background:#6f42c1;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:14px;font-weight:600;">🔗 Link</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Manual User-Employee Linking Functions
    function openLinkEmployeeModal(userId, userName) {
        const modal       = document.getElementById('linkEmployeeModal');
        const nameSpan    = document.getElementById('linkUserName');
        const userIdInput = document.getElementById('linkUserId');
        const emailInput  = document.getElementById('linkEmployeeEmail');

        if (modal && nameSpan && userIdInput) {
            nameSpan.textContent  = userName;
            userIdInput.value     = userId;
            if (emailInput) emailInput.value = '';
            modal.style.display   = 'flex';
            if (emailInput) setTimeout(() => emailInput.focus(), 100);
        }
    }

    function closeLinkEmployeeModal() {
        const modal = document.getElementById('linkEmployeeModal');
        if (modal) modal.style.display = 'none';
    }

    function filterLinkEmployees() {} // kept for backward compat, no-op
    
    function unlinkUserEmployee(userId, userName) {
        if (confirm(`Unlink ${userName} from their employee record?\n\nThis will remove the connection between their user account and employee schedule.`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="unlink_user_employee">
                <input type="hidden" name="userId" value="${userId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        const linkModal = document.getElementById('linkEmployeeModal');
        if (linkModal && event.target === linkModal) {
            closeLinkEmployeeModal();
        }
    });
    </script>

    <!-- CSS for this section is in styles.css (search for "AUTH_USER_MANAGEMENT.PHP BLOCK 2 EXTRACTED STYLES") -->
    <?php
    return ob_get_clean();
}

// Initialize authentication system
function initializeAuth() {
    loadUsers();
    handleAuthentication();
}

// Initialize and run if this file is accessed directly for testing
if (basename($_SERVER['PHP_SELF']) === 'auth_user_management.php') {
    initializeAuth();
}
?>