 'Not authenticated']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$themeManager = new ThemeManager();

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['action']) && $_GET['action'] === 'all') {
                // Get all users' themes (admin only)
                $userFile = "users/user_{$_SESSION['user_id']}.json";
                $currentUser = file_exists($userFile) ? json_decode(file_get_contents($userFile), true) : null;
                
                if (!$currentUser || !in_array($currentUser['role'], ['admin', 'manager'])) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Insufficient permissions']);
                    exit;
                }
                
                $themes = $themeManager->getAllUserThemes();
                echo json_encode(['success' => true, 'themes' => $themes]);
            } else {
                // Get current user's theme
                $theme = $themeManager->getUserTheme($_SESSION['user_id']);
                echo json_encode(['success' => true, 'theme' => $theme]);
            }
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['theme'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Theme not specified']);
                exit;
            }
            
            if (isset($input['target_user_id'])) {
                // Admin updating another user's theme
                $result = $themeManager->updateUserThemeByAdmin(
                    $_SESSION['user_id'], 
                    $input['target_user_id'], 
                    $input['theme']
                );
            } else {
                // User updating their own theme
                $result = $themeManager->updateUserTheme($_SESSION['user_id'], $input['theme']);
            }
            
            echo json_encode($result);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>