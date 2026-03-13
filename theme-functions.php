usersDir = $usersDir;
        if (!file_exists($this->usersDir)) {
            mkdir($this->usersDir, 0755, true);
        }
    }
    
    private function getUserFile($userId) {
        return $this->usersDir . "/user_{$userId}.json";
    }
    
    private function loadUser($userId) {
        $userFile = $this->getUserFile($userId);
        if (file_exists($userFile)) {
            $data = json_decode(file_get_contents($userFile), true);
            return $data ?: null;
        }
        return null;
    }
    
    private function saveUser($userId, $userData) {
        $userFile = $this->getUserFile($userId);
        $userData['updated_at'] = date('Y-m-d H:i:s');
        
        // Create backup
        if (file_exists($userFile)) {
            copy($userFile, $userFile . '.backup');
        }
        
        // Use file locking to prevent corruption
        $tempFile = $userFile . '.tmp';
        if (file_put_contents($tempFile, json_encode($userData, JSON_PRETTY_PRINT), LOCK_EX) !== false) {
            rename($tempFile, $userFile);
            return true;
        }
        return false;
    }
    
    public function getUserTheme($userId) {
        $user = $this->loadUser($userId);
        return $user['theme_preference'] ?? 'default';
    }
    
    public function updateUserTheme($userId, $theme) {
        $validThemes = ['default', 'ocean', 'forest', 'sunset', 'royal', 'dark'];
        
        if (!in_array($theme, $validThemes)) {
            throw new Exception('Invalid theme selected');
        }
        
        $user = $this->loadUser($userId);
        if (!$user) {
            throw new Exception('User not found');
        }
        
        $user['theme_preference'] = $theme;
        
        if ($this->saveUser($userId, $user)) {
            return ['success' => true, 'theme' => $theme];
        } else {
            throw new Exception('Failed to save theme preference');
        }
    }
    
    public function getAllUserThemes() {
        $themes = [];
        $files = glob($this->usersDir . '/user_*.json');
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data) {
                $themes[] = [
                    'id' => $data['id'],
                    'username' => $data['username'],
                    'email' => $data['email'],
                    'full_name' => $data['full_name'],
                    'theme_preference' => $data['theme_preference'] ?? 'default'
                ];
            }
        }
        
        return $themes;
    }
    
    public function updateUserThemeByAdmin($adminId, $targetUserId, $theme) {
        // Check if admin has permission (implement your permission logic)
        $adminUser = $this->loadUser($adminId);
        if (!$adminUser || !in_array($adminUser['role'], ['admin', 'manager'])) {
            throw new Exception('Insufficient permissions');
        }
        
        return $this->updateUserTheme($targetUserId, $theme);
    }
    
    // Migration function to add theme_preference to existing users
    public function migrateExistingUsers() {
        $files = glob($this->usersDir . '/user_*.json');
        $updated = 0;
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && !isset($data['theme_preference'])) {
                $data['theme_preference'] = 'default';
                if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX)) {
                    $updated++;
                }
            }
        }
        
        return $updated;
    }
}

// Helper function to get current user's theme
function getCurrentUserTheme() {
    if (!isset($_SESSION['user_id'])) {
        return 'default';
    }
    
    $themeManager = new ThemeManager();
    return $themeManager->getUserTheme($_SESSION['user_id']);
}
?>