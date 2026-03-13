// Fallback stubs — loaded first so ScheduleApp timing issues can't cause call errors
window.toggleTemplateManagement = function() {
    setTimeout(() => {
        if (window.ScheduleApp && window.ScheduleApp.toggleTemplateManagement) {
            window.ScheduleApp.toggleTemplateManagement();
        }
    }, 100);
};

window.saveCurrentAsTemplate = function() {
    setTimeout(() => {
        if (window.ScheduleApp && window.ScheduleApp.saveCurrentAsTemplate) {
            window.ScheduleApp.saveCurrentAsTemplate();
        }
    }, 100);
};

window.deleteTemplate = function(id, name) {
    setTimeout(() => {
        if (window.ScheduleApp && window.ScheduleApp.deleteTemplate) {
            window.ScheduleApp.deleteTemplate(id, name);
        }
    }, 100);
};

// Schedule Management System v3.4 - Enhanced with Date Sorting Support
// Main application scripts with USER TIMEZONE detection

// Global configuration - will be set by PHP
let CONFIG = {
    sessionTimeoutMs: 30 * 60 * 1000, // Default 30 minutes
    warningTimeMs: 5 * 60 * 1000,     // Default 5 minutes (unused)
    autoRefreshMs: 10 * 60 * 1000,    // Changed to 10 minutes
    hasManageEmployees: false,
    hasEditSchedule: false,
    hasEditOwnSchedule: false,
    hasManageBackups: false,
    hasManageUsers: false
};

// User timezone variables
let userTimezone = null;
let userTodayInfo = null;

// Session management variables
let sessionStartTime = Date.now();
let lastActivityTime = Date.now();
let warningShown = false;
let autoRefreshTimer = null;

// Profile management variables
let currentEditingUserId = null;

// Initialize configuration from PHP data
function initializeConfig(config) {
    CONFIG = { ...CONFIG, ...config };
}

// Initialize timezone-aware today highlighting
function initializeTodayHighlighting() {
    // Detect user's timezone
    userTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    
    // Get current date/time in user's timezone - FIXED
    const now = new Date();
    
    // Get user's local date properly
const userLocalTime = new Date(now.toLocaleString("en-US", {timeZone: userTimezone}));
    
    userTodayInfo = {
        year: userLocalTime.getFullYear(),
        month: userLocalTime.getMonth(), // 0-based month (0 = January)
        day: userLocalTime.getDate(), // Day of month (1-31)
        dayOfWeek: userLocalTime.getDay(), // 0 = Sunday, 1 = Monday, etc.
        dayName: userLocalTime.toLocaleDateString('en-US', {weekday: 'long', timeZone: userTimezone}),
        formattedDate: userLocalTime.toLocaleDateString('en-US', {
            weekday: 'long',
            year: 'numeric', 
            month: 'long',
            day: 'numeric',
            timeZone: userTimezone
        })
    };
    
    // Update today display in header
    updateTodayDisplay();
    
    // Highlight today's column if we're viewing current month
    highlightTodayColumn();
    
    // Update working today stats
    updateWorkingTodayStats();
}

// Update today display in header
function updateTodayDisplay() {
    const todayDisplay = document.getElementById('todayDisplay');
    if (!todayDisplay) return;
    
    // Check if we're viewing the current month/year
    const isCurrentMonth = (userTodayInfo.year === window.currentYear && userTodayInfo.month === window.currentMonth);
    const autoRefreshMinutes = Math.floor(CONFIG.autoRefreshMs / 1000 / 60);
    
    if (isCurrentMonth) {
        todayDisplay.innerHTML = ` • 📍 Today: ${userTodayInfo.formattedDate} (${userTimezone.replace(/_/g, ' ')})`;
    } else {
        todayDisplay.innerHTML = ` • 🕒 User Time: ${userTimezone.replace(/_/g, ' ')}`;
    }
    
    // Add auto-refresh info on a new line
    todayDisplay.innerHTML += `<br><small style="opacity: 0.8;">🔄 Auto-refresh: ${autoRefreshMinutes}min • 🌍 Timezone: ${userTimezone.replace(/_/g, ' ')}</small>`;
}

// Highlight today's column in the schedule table
function highlightTodayColumn() {
    const table = document.querySelector('.schedule-table');
    if (!table) return;

    // Get all headers (first one is employee names, rest are dates)
    const headers = table.querySelectorAll('thead th');
    const tbody = table.querySelector('tbody');

    // Always clear PHP-rendered today highlights first (PHP uses server timezone,
    // which may differ from the user's timezone shown in the top-right).
    headers.forEach(header => header.classList.remove('today-header'));
    if (tbody) {
        tbody.querySelectorAll('td.today-cell').forEach(td => td.classList.remove('today-cell'));
    }

    // Check if we're viewing the current month/year in the user's own timezone
    const isCurrentMonth = (userTodayInfo.year === window.currentYear && userTodayInfo.month === window.currentMonth);
    if (!isCurrentMonth) return;

    // Column index = day of month (col 0 is the "Employee" name column, so day 1 → index 1)
    const todayHeaderIndex = userTodayInfo.day; // Day of month (1-31)

    // Highlight the correct day header
    if (todayHeaderIndex > 0 && todayHeaderIndex < headers.length) {
        headers[todayHeaderIndex].classList.add('today-header');
    }

    // Highlight every data cell in that same column
    if (tbody && todayHeaderIndex > 0) {
        tbody.querySelectorAll('tr').forEach(row => {
            const cells = row.querySelectorAll('td');
            if (todayHeaderIndex < cells.length) {
                cells[todayHeaderIndex].classList.add('today-cell');
            }
        });
    }
}

// Update working today statistics based on user's timezone
function updateWorkingTodayStats() {
    const statElement = document.querySelector('.stat-item.has-tooltip');
    if (!statElement) return;
    
    // Update the tooltip header with user's timezone info
    const tooltip = statElement.querySelector('.tooltip');
    if (tooltip) {
        const tooltipHeader = tooltip.querySelector('.tooltip-header');
        if (tooltipHeader) {
            const isCurrentMonth = (userTodayInfo.year === window.currentYear && userTodayInfo.month === window.currentMonth);
            
            if (isCurrentMonth) {
                tooltipHeader.innerHTML = `
                    Working Today (${userTodayInfo.dayName})<br>
                    <small style="font-weight: normal; font-size: 10px; opacity: 0.8;">
                        ${userTodayInfo.formattedDate}<br>
                        Timezone: ${userTimezone.replace(/_/g, ' ')}
                    </small>
                `;
            } else {
                tooltipHeader.innerHTML = `
                    Working Today<br>
                    <small style="font-weight: normal; font-size: 10px; opacity: 0.8;">
                        Viewing different month<br>
                        Your timezone: ${userTimezone.replace(/_/g, ' ')}
                    </small>
                `;
            }
        }
    }
}

// Enhanced Date Sorting Functions
function clearDateSort() {
    const form = document.querySelector('.controls form');
    if (form) {
        const sortDateInput = form.querySelector('input[name="sortDate"]');
        const sortBySelect = form.querySelector('select[name="sortBy"]');
        
        if (sortDateInput) sortDateInput.value = '';
        if (sortBySelect) sortBySelect.value = 'name';
        
        form.submit();
    }
}

function setQuickDateSort(dateOffset, sortType) {
    const form = document.querySelector('.controls form');
    if (!form) return;
    
    // Use user's timezone for accurate date calculation
    const today = new Date();
    const userToday = new Date(today.toLocaleString("en-US", {timeZone: userTimezone || Intl.DateTimeFormat().resolvedOptions().timeZone}));
    
    const targetDate = new Date(userToday);
    targetDate.setDate(userToday.getDate() + dateOffset);
    
    const sortDateInput = form.querySelector('input[name="sortDate"]');
    const sortBySelect = form.querySelector('select[name="sortBy"]');
    
    if (sortDateInput) {
        // Format as YYYY-MM-DD for date input
        const year = targetDate.getFullYear();
        const month = String(targetDate.getMonth() + 1).padStart(2, '0');
        const day = String(targetDate.getDate()).padStart(2, '0');
        sortDateInput.value = `${year}-${month}-${day}`;
    }
    if (sortBySelect) {
        sortBySelect.value = sortType;
    }
    
    // Show loading indicator
    showNotification('📊 Applying date-based sorting...', 'info');
    
    form.submit();
}

function validateDateSortForm() {
    const form = document.querySelector('.controls form');
    if (!form) return;
    
    const sortDateInput = form.querySelector('input[name="sortDate"]');
    const sortBySelect = form.querySelector('select[name="sortBy"]');
    
    if (!sortDateInput || !sortBySelect) return;
    
    // If a sort date is selected but sort type is 'name', change it to 'working'
    if (sortDateInput.value && sortBySelect.value === 'name') {
        sortBySelect.value = 'working';
        showNotification('📅 Switching to "Working First" sorting for selected date', 'info');
    }
    
    // If sort type is not 'name' but no date is selected, clear the sort type
    if (!sortDateInput.value && sortBySelect.value !== 'name') {
        sortBySelect.value = 'name';
        showNotification('📝 Switching to name sorting (no date selected)', 'info');
    }
}

// Session Management Functions - Updated with dynamic timezone
// Cached outside updateClock so they are built once, not on every tick
let _clockEl = null, _timezoneEl = null, _dateEl = null, _sessionInfoEl = null;
let _clockTimeOptions = null, _clockDateOptions = null;
let _lastTimeString = '', _lastDateString = '', _lastSessionText = '', _clockTimezoneSet = false;

function updateClock() {
    // Build format options once — timezone never changes after init
    if (!_clockTimeOptions) {
        const tz = userTimezone || Intl.DateTimeFormat().resolvedOptions().timeZone;
        _clockTimeOptions = { timeZone: tz, hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' };
        _clockDateOptions = { timeZone: tz, weekday: 'short', month: 'short', day: 'numeric' };
    }

    // Cache DOM refs once
    if (!_clockEl) {
        _clockEl      = document.getElementById('userTime');
        _timezoneEl   = document.getElementById('userTimezone');
        _dateEl       = document.getElementById('clockDate');
        _sessionInfoEl = document.getElementById('sessionInfo');
    }

    // Timezone label never changes — write it only on first run
    if (!_clockTimezoneSet && _timezoneEl) {
        _timezoneEl.textContent = _clockTimeOptions.timeZone.replace(/_/g, ' ');
        _clockTimezoneSet = true;
    }

    const now = new Date();

    // Time — write only when the displayed value changes
    if (_clockEl) {
        const timeString = now.toLocaleTimeString('en-US', _clockTimeOptions);
        if (timeString !== _lastTimeString) {
            _clockEl.textContent = timeString;
            _lastTimeString = timeString;
        }
    }

    // Date — changes at most once per day
    if (_dateEl) {
        const dateString = now.toLocaleDateString('en-US', _clockDateOptions);
        if (dateString !== _lastDateString) {
            _dateEl.textContent = dateString;
            _lastDateString = dateString;
        }
    }

    // Session countdown — minutes remaining changes at most once per minute
    if (_sessionInfoEl) {
        const remaining = Math.floor(CONFIG.sessionTimeoutMs / 1000 / 60) -
                          Math.floor((Date.now() - sessionStartTime) / 1000 / 60);
        const sessionText = remaining > 0 ? `🔒 Session: ${remaining}m remaining` : '🔒 Session expired';
        if (sessionText !== _lastSessionText) {
            _sessionInfoEl.textContent = sessionText;
            _lastSessionText = sessionText;
        }
    }
}

// Check if date has changed and update highlighting
function checkDateChange() {
    if (!userTimezone) return;
    
    const now = new Date();
    const currentUserDate = new Date(now.toLocaleString("en-US", {timeZone: userTimezone}));
    
    if (userTodayInfo && 
        (currentUserDate.getDate() !== userTodayInfo.day || 
         currentUserDate.getMonth() !== userTodayInfo.month || 
         currentUserDate.getFullYear() !== userTodayInfo.year)) {
        
        initializeTodayHighlighting();
    }
}

// Track user activity
function trackActivity() {
    lastActivityTime = Date.now();
    warningShown = false;
    
    // Send keep-alive to server
    fetch('', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=keep_alive'
    }).catch(function(error) {
    });
}

// Check for session timeout - SIMPLIFIED (No warnings)
function checkSessionTimeout() {
    const timeElapsed = Date.now() - lastActivityTime;
    
    if (timeElapsed >= CONFIG.sessionTimeoutMs) {
        // Session expired - direct to expired modal
        showSessionExpiredModal();
    }
    // No warning functionality - just let session expire
}

// Logout now
function logoutNow() {
    window.location.href = '?action=logout';
}

// Show session expired modal
function showSessionExpiredModal() {
    const modal = document.createElement('div');
    modal.className = 'session-modal';
    modal.innerHTML = `
        <div class="session-modal-content">
            <h3 style="color: #e74c3c; margin-bottom: 15px;">🔒 Session Expired</h3>
            <p style="margin-bottom: 20px;">Your session has expired due to inactivity.</p>
            <p style="margin-bottom: 20px; font-size: 14px; color: #666;">You will be redirected to the login page.</p>
            <button onclick="window.location.href='?action=logout'" style="background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">Return to Login</button>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    // Auto redirect after 5 seconds
    setTimeout(() => {
        window.location.href = '?action=logout';
    }, 5000);
}

// Show notification
function showNotification(message, type = 'info') {
    const existing = document.querySelector('.auto-notification');
    if (existing) {
        existing.remove();
    }
    
    const notification = document.createElement('div');
    notification.className = 'auto-notification';
    
    // Set colors based on type
    switch(type) {
        case 'success':
            notification.style.background = '#d4edda';
            notification.style.color = '#155724';
            notification.style.border = '1px solid #c3e6cb';
            break;
        case 'warning':
            notification.style.background = '#fff3cd';
            notification.style.color = '#856404';
            notification.style.border = '1px solid #ffeaa7';
            break;
        case 'error':
            notification.style.background = '#f8d7da';
            notification.style.color = '#721c24';
            notification.style.border = '1px solid #f5c6cb';
            break;
        default:
            notification.style.background = '#d1ecf1';
            notification.style.color = '#0c5460';
            notification.style.border = '1px solid #bee5eb';
    }
    
    // Position notification
    notification.style.position = 'fixed';
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.padding = '12px 16px';
    notification.style.borderRadius = '6px';
    notification.style.fontSize = '14px';
    notification.style.zIndex = '9999';
    notification.style.maxWidth = '300px';
    notification.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
    notification.style.transition = 'all 0.3s ease';
    notification.style.transform = 'translateX(100%)';
    notification.style.opacity = '0';
    
    notification.textContent = message;
    document.body.appendChild(notification);
    
    // Trigger animation
    setTimeout(() => {
        notification.style.transform = 'translateX(0)';
        notification.style.opacity = '1';
    }, 10);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.style.opacity = '0';
            notification.style.transform = 'translateX(100%)';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 300);
        }
    }, 3000);
}

// Auto-dismiss the PHP session flash message (green/success bar)
document.addEventListener('DOMContentLoaded', function() {
    const flash = document.getElementById('flash-message');
    if (!flash) return;

    function dismissFlashMessage() {
        if (!flash.parentNode) return;
        flash.classList.add('fade-out');
        setTimeout(() => { if (flash.parentNode) flash.remove(); }, 550);
    }

    // Auto-dismiss after 5 seconds
    const flashTimer = setTimeout(dismissFlashMessage, 5000);

    // Also dismiss when user clicks any nav tab
    document.querySelectorAll('.nav-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            clearTimeout(flashTimer);
            dismissFlashMessage();
        });
    });
});

// Auto refresh functionality
function setupAutoRefresh() {
    autoRefreshTimer = setInterval(() => {
        // Only refresh if user hasn't been idle too long
        const timeSinceActivity = Date.now() - lastActivityTime;
        if (timeSinceActivity < 15 * 60 * 1000) { // Less than 15 minutes idle
            showNotification('🔄 Refreshing to show latest changes...', 'info');
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        }
    }, CONFIG.autoRefreshMs);
}

// Initialize session management
function initializeSessionManagement() {
    // Set up activity tracking
    const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
    events.forEach(event => {
        document.addEventListener(event, trackActivity, true);
    });
    
    // Single master tick drives all periodic checks — one timer instead of three
    let _masterTick = 0;
    setInterval(function() {
        _masterTick++;
        updateClock();                              // every second
        if (_masterTick % 10 === 0) checkSessionTimeout();  // every 10 s
        if (_masterTick % 60 === 0) checkDateChange();       // every 60 s
    }, 1000);
    
    // Set up auto refresh
    setupAutoRefresh();
    
    // Initial setup
    trackActivity();
    updateClock();
}

// Activity Log Functions
function toggleActivityLog() {
    const dropdown = document.getElementById('activityLogDropdown');
    if (dropdown) {
        dropdown.classList.toggle('show');
    }
}

// Tab Management
function showTab(tabName) {
    
    // Hide all tab contents
    const tabContents = document.querySelectorAll('.tab-content');
    tabContents.forEach(tab => tab.classList.remove('active'));
    
    // Remove active class from all tabs
    const tabs = document.querySelectorAll('.nav-tab');
    tabs.forEach(tab => tab.classList.remove('active'));
    
    // Show selected tab content
    const targetTab = document.getElementById(tabName + '-tab');
    if (targetTab) {
        targetTab.classList.add('active');
    }
    
    // Add active class to clicked tab
    if (event && event.target) {
        event.target.classList.add('active');
    }
    
    // Handle heatmap tab loading — render the grid if data was updated while tab was hidden
    if (tabName === 'heatmap') {
        if (window._heatmapDirty && typeof renderHeatmapGrid === 'function') {
            setTimeout(() => {
                renderHeatmapGrid();
                window._heatmapDirty = false;
            }, 50);
        } else if (typeof initializeHeatmap === 'function' && !window._heatmapInitialized) {
            setTimeout(() => {
                initializeHeatmap();
                window._heatmapInitialized = true;
            }, 50);
        }
    }
    
    // Update URL and clean parameters
    const url = new URL(window.location);
    
    // Clear ALL parameters
    url.search = '';
    
    // Set only the tab parameter
    url.searchParams.set('tab', tabName);
    
    // ONLY keep id/user_id if we're specifically on those tabs
    // This ensures parameters are cleared when navigating away
    const currentParams = new URLSearchParams(window.location.search);
    
    if (tabName === 'edit-employee') {
        const id = currentParams.get('id');
        if (id) {
            url.searchParams.set('id', id);
        }
    } else if (tabName === 'view-profile') {
        const userId = currentParams.get('user_id');
        if (userId) {
            url.searchParams.set('user_id', userId);
        }
    } else {
    }
    // For all other tabs, only keep 'tab' parameter
    
    window.history.replaceState({}, '', url);
}

// Profile Management Functions
function openProfileModal(userId = null) {
    const modal = document.getElementById('profileModal');
    if (!modal) return;
    
    // Hide any employee hover cards
    forceHideEmployeeCard();
    
    currentEditingUserId = userId;
    
    if (userId && userId !== getCurrentUserId()) {
        // Editing another user's profile (admin/supervisor/manager)
        document.getElementById('profileModalTitle').textContent = 'Edit User Profile';
        document.getElementById('profileAction').value = 'update_other_profile';
        document.getElementById('targetUserId').value = userId;
        const currentPasswordGroup = document.getElementById('currentPasswordGroup');
        if (currentPasswordGroup) currentPasswordGroup.style.display = 'none';
        
        // Load the target user's data into the form
        loadUserDataIntoForm(userId);
    } else {
        // Editing own profile
        document.getElementById('profileModalTitle').textContent = 'Edit Profile';
        document.getElementById('profileAction').value = 'update_profile';
        document.getElementById('targetUserId').value = '';
        const currentPasswordGroup = document.getElementById('currentPasswordGroup');
        if (currentPasswordGroup) currentPasswordGroup.style.display = 'block';
    }
    
    updatePhotoDisplay();
    modal.classList.add('show');
}

function loadUserDataIntoForm(userId) {
    // Find the user data
    const user = window.usersData ? window.usersData.find(u => u.id == userId) : null;
    
    if (user) {
        // Update form fields with user data
        const fullNameField = document.getElementById('profile_full_name');
        const emailField = document.getElementById('profile_email');
        
        if (fullNameField) fullNameField.value = user.full_name;
        if (emailField) emailField.value = user.email;

        // Populate Slack Member ID field
        const slackIdField = document.getElementById('profile_slack_id');
        if (slackIdField) slackIdField.value = user.slack_id || '';

        // Update photo display
        const photoDisplay = document.getElementById('currentPhotoDisplay');
        if (photoDisplay && user.profile_photo) {
            photoDisplay.innerHTML = `
                <img src="profile_photos/${user.profile_photo}" alt="Profile Photo" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid #ddd;">
            `;
        } else if (photoDisplay) {
            photoDisplay.innerHTML = `
                <div class="photo-placeholder">
                    ${user.full_name.substring(0, 2).toUpperCase()}
                </div>
            `;
        }
        
        // Show/hide delete photo button
        const deleteBtn = document.getElementById('deletePhotoBtn');
        if (deleteBtn) {
            deleteBtn.style.display = user.profile_photo ? 'block' : 'none';
        }
    }
}

function closeProfileModal() {
    const modal = document.getElementById('profileModal');
    if (modal) {
        modal.classList.remove('show');
    }
    
    // Reset form
    resetProfileForm();
    currentEditingUserId = null;
}

function getCurrentUserId() {
    return window.currentUserId || null;
}

// Photo Management Functions
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        
        // Validate file size (5MB max)
        if (file.size > 5 * 1024 * 1024) {
            showNotification('File too large. Maximum size is 5MB.', 'warning');
            input.value = '';
            return;
        }
        
        // Validate file type
        if (!file.type.match(/^image\/(jpeg|png|gif|webp)$/)) {
            showNotification('Invalid file type. Please upload JPG, PNG, GIF, or WebP images only.', 'warning');
            input.value = '';
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            updatePhotoPreview(e.target.result);
        };
        reader.readAsDataURL(file);
        
        // Hide delete photo option when new photo is selected
        const deleteBtn = document.getElementById('deletePhotoBtn');
        if (deleteBtn) {
            deleteBtn.style.display = 'none';
        }
        
        // Uncheck delete photo checkbox
        const deleteCheckbox = document.getElementById('delete_photo');
        if (deleteCheckbox) {
            deleteCheckbox.checked = false;
        }
    }
}

function updatePhotoPreview(imageSrc) {
    const photoDisplay = document.getElementById('currentPhotoDisplay');
    if (!photoDisplay) return;
    
    photoDisplay.innerHTML = `
        <div class="current-photo">
            <img src="${imageSrc}" alt="Profile Photo Preview" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid #27ae60;">
            <div style="position: absolute; top: -5px; right: -5px; background: #27ae60; color: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 12px;">✓</div>
        </div>
    `;
}

function updatePhotoDisplay() {
    const deleteBtn = document.getElementById('deletePhotoBtn');
    
    // Show delete button only if user has a photo
    if (window.currentUserHasPhoto) {
        if (deleteBtn) deleteBtn.style.display = 'block';
    }
}

function togglePhotoDelete(checkbox) {
    const photoDisplay = document.getElementById('currentPhotoDisplay');
    const photoInput = document.getElementById('profile_photo');
    
    if (checkbox.checked) {
        // Show deletion preview
        if (photoDisplay) {
            const placeholder = photoDisplay.querySelector('.photo-placeholder');
            if (placeholder) {
                placeholder.style.opacity = '0.5';
                placeholder.style.border = '2px dashed #e74c3c';
            }
            
            const currentImg = photoDisplay.querySelector('img');
            if (currentImg) {
                currentImg.style.opacity = '0.5';
                currentImg.style.border = '2px dashed #e74c3c';
            }
        }
        
        // Clear file input
        if (photoInput) {
            photoInput.value = '';
        }
    } else {
        // Restore normal display
        if (photoDisplay) {
            const placeholder = photoDisplay.querySelector('.photo-placeholder');
            if (placeholder) {
                placeholder.style.opacity = '1';
                placeholder.style.border = '2px solid #ddd';
            }
            
            const currentImg = photoDisplay.querySelector('img');
            if (currentImg) {
                currentImg.style.opacity = '1';
                currentImg.style.border = '2px solid #ddd';
            }
        }
    }
}

function resetProfileForm() {
    const form = document.getElementById('profileForm');
    if (!form) return;
    
    // Reset file input
    const photoInput = document.getElementById('profile_photo');
    if (photoInput) photoInput.value = '';
    
    // Reset delete checkbox
    const deleteCheckbox = document.getElementById('delete_photo');
    if (deleteCheckbox) deleteCheckbox.checked = false;
    
    // Reset photo display
    updatePhotoDisplay();
}

function openProfileViewerModal() {
    // Hide any employee hover cards
    forceHideEmployeeCard();
    
    const modal = document.getElementById('profileViewerModal');
    if (modal) {
        modal.classList.add('show');
        
        // Clear previous selection
        const userSelect = document.getElementById('userSelect');
        if (userSelect) userSelect.value = '';
        
        const profileDiv = document.getElementById('selectedUserProfile');
        if (profileDiv) {
            profileDiv.innerHTML = '<p style="text-align: center; color: #666; font-style: italic;">Select a user from the dropdown above to view their profile.</p>';
        }
    }
}

function closeProfileViewerModal() {
    const modal = document.getElementById('profileViewerModal');
    if (modal) {
        modal.classList.remove('show');
    }
}

// Enhanced viewUserProfile function - FIXED VERSION
function viewUserProfile(userId) {
    if (!userId) {
        const profileDiv = document.getElementById('selectedUserProfile');
        if (profileDiv) {
            profileDiv.innerHTML = '<p style="text-align: center; color: #666; font-style: italic;">Select a user from the dropdown above to view their profile.</p>';
        }
        return;
    }
    
    // Find the user in the stored data
    const user = window.usersData ? window.usersData.find(u => u.id == userId) : null;
    
    const profileDiv = document.getElementById('selectedUserProfile');
    if (!profileDiv) return;
    
    if (!user) {
        profileDiv.innerHTML = '<p style="text-align: center; color: #e74c3c;">User not found.</p>';
        return;
    }
    
    // Generate profile HTML
    const profilePhotoUrl = user.profile_photo ? `profile_photos/${user.profile_photo}` : null;
    const roleColors = {
        admin: '#e74c3c',
        manager: '#f39c12', 
        supervisor: '#3498db',
        employee: '#27ae60'
    };
    
    // Helper function to escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Helper function to format dates
    function formatDate(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short', 
            day: 'numeric'
        }) + ' at ' + date.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit'
        });
    }
    
    profileDiv.innerHTML = `
        <div class="profile-card">
            <div class="profile-header">
                <div class="profile-avatar">
                    ${profilePhotoUrl ? 
                        `<img src="${profilePhotoUrl}" alt="Profile Photo" class="profile-photo-img">` :
                        user.full_name.substring(0, 2).toUpperCase()
                    }
                </div>
                <div class="profile-info">
                    <h3>${escapeHtml(user.full_name)}</h3>
                    <span class="role-badge role-${user.role}" style="background: ${roleColors[user.role] || '#95a5a6'};">
                        ${user.role.charAt(0).toUpperCase() + user.role.slice(1)}
                    </span>
                </div>
                <button class="profile-edit-btn" onclick="openProfileModal(${user.id})" title="Edit Profile">
                    ✏️ Edit
                </button>
            </div>
            
            <div class="profile-details">
                <div class="profile-field">
                    <span class="field-label">📧 Email:</span>
                    <span class="field-value">${escapeHtml(user.email)}</span>
                </div>
                
                <div class="profile-field">
                    <span class="field-label">👤 Username:</span>
                    <span class="field-value">${escapeHtml(user.username)}</span>
                </div>
                
                <div class="profile-field">
                    <span class="field-label">🏢 Team Access:</span>
                    <span class="field-value">${user.team.toUpperCase()}</span>
                </div>
                
                <div class="profile-field">
                    <span class="field-label">📊 Status:</span>
                    <span class="field-value">
                        <span class="status-badge ${user.active ? 'status-active' : 'status-inactive'}">
                            ${user.active ? 'Active' : 'Inactive'}
                        </span>
                    </span>
                </div>
                
                <div class="profile-field">
                    <span class="field-label">📅 Member Since:</span>
                    <span class="field-value">${formatDate(user.created_at)}</span>
                </div>
                
                ${user.updated_at ? `
                <div class="profile-field">
                    <span class="field-label">🔄 Last Updated:</span>
                    <span class="field-value">${formatDate(user.updated_at)}</span>
                </div>
                ` : ''}
                
                <div class="profile-field">
                    <span class="field-label">📸 Profile Photo:</span>
                    <span class="field-value">${user.profile_photo ? 'Yes' : 'No photo uploaded'}</span>
                </div>

                ${user.slack_id ? `
                <div class="profile-field">
                    <span class="field-label">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="#4A154B" style="vertical-align:middle;margin-right:3px;"><path d="M5.042 15.165a2.528 2.528 0 0 1-2.52 2.523A2.528 2.528 0 0 1 0 15.165a2.527 2.527 0 0 1 2.522-2.52h2.52v2.52zM6.313 15.165a2.527 2.527 0 0 1 2.521-2.52 2.527 2.527 0 0 1 2.521 2.52v6.313A2.528 2.528 0 0 1 8.834 24a2.528 2.528 0 0 1-2.521-2.522v-6.313zM8.834 5.042a2.528 2.528 0 0 1-2.521-2.52A2.528 2.528 0 0 1 8.834 0a2.528 2.528 0 0 1 2.521 2.522v2.52H8.834zM8.834 6.313a2.528 2.528 0 0 1 2.521 2.521 2.528 2.528 0 0 1-2.521 2.521H2.522A2.528 2.528 0 0 1 0 8.834a2.528 2.528 0 0 1 2.522-2.521h6.312zM18.956 8.834a2.528 2.528 0 0 1 2.522-2.521A2.528 2.528 0 0 1 24 8.834a2.528 2.528 0 0 1-2.522 2.521h-2.522V8.834zM17.688 8.834a2.528 2.528 0 0 1-2.523 2.521 2.527 2.527 0 0 1-2.52-2.521V2.522A2.527 2.527 0 0 1 15.165 0a2.528 2.528 0 0 1 2.523 2.522v6.312zM15.165 18.956a2.528 2.528 0 0 1 2.523 2.522A2.528 2.528 0 0 1 15.165 24a2.527 2.527 0 0 1-2.52-2.522v-2.522h2.52zM15.165 17.688a2.527 2.527 0 0 1-2.52-2.523 2.526 2.526 0 0 1 2.52-2.52h6.313A2.527 2.527 0 0 1 24 15.165a2.528 2.528 0 0 1-2.522 2.523h-6.313z"/></svg>
                        Slack:
                    </span>
                    <span class="field-value">
                        <a href="https://app.slack.com/client/${escapeHtml(user.slack_id)}"
                           target="_blank" rel="noopener noreferrer"
                           style="display:inline-flex;align-items:center;gap:5px;background:#4A154B;color:#fff;padding:4px 10px;border-radius:5px;text-decoration:none;font-size:12px;font-weight:500;cursor:pointer;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="white"><path d="M5.042 15.165a2.528 2.528 0 0 1-2.52 2.523A2.528 2.528 0 0 1 0 15.165a2.527 2.527 0 0 1 2.522-2.52h2.52v2.52zM6.313 15.165a2.527 2.527 0 0 1 2.521-2.52 2.527 2.527 0 0 1 2.521 2.52v6.313A2.528 2.528 0 0 1 8.834 24a2.528 2.528 0 0 1-2.521-2.522v-6.313zM8.834 5.042a2.528 2.528 0 0 1-2.521-2.52A2.528 2.528 0 0 1 8.834 0a2.528 2.528 0 0 1 2.521 2.522v2.52H8.834zM8.834 6.313a2.528 2.528 0 0 1 2.521 2.521 2.528 2.528 0 0 1-2.521 2.521H2.522A2.528 2.528 0 0 1 0 8.834a2.528 2.528 0 0 1 2.522-2.521h6.312zM18.956 8.834a2.528 2.528 0 0 1 2.522-2.521A2.528 2.528 0 0 1 24 8.834a2.528 2.528 0 0 1-2.522 2.521h-2.522V8.834zM17.688 8.834a2.528 2.528 0 0 1-2.523 2.521 2.527 2.527 0 0 1-2.52-2.521V2.522A2.527 2.527 0 0 1 15.165 0a2.528 2.528 0 0 1 2.523 2.522v6.312zM15.165 18.956a2.528 2.528 0 0 1 2.523 2.522A2.528 2.528 0 0 1 15.165 24a2.527 2.527 0 0 1-2.52-2.522v-2.522h2.52zM15.165 17.688a2.527 2.527 0 0 1-2.52-2.523 2.526 2.526 0 0 1 2.52-2.52h6.313A2.527 2.527 0 0 1 24 15.165a2.528 2.528 0 0 1-2.522 2.523h-6.313z"/></svg>
                            Message on Slack
                        </a>
                    </span>
                </div>
                ` : `
                <div class="profile-field">
                    <span class="field-label" style="opacity:0.6;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="#aaa" style="vertical-align:middle;margin-right:3px;"><path d="M5.042 15.165a2.528 2.528 0 0 1-2.52 2.523A2.528 2.528 0 0 1 0 15.165a2.527 2.527 0 0 1 2.522-2.52h2.52v2.52zM6.313 15.165a2.527 2.527 0 0 1 2.521-2.52 2.527 2.527 0 0 1 2.521 2.52v6.313A2.528 2.528 0 0 1 8.834 24a2.528 2.528 0 0 1-2.521-2.522v-6.313zM8.834 5.042a2.528 2.528 0 0 1-2.521-2.52A2.528 2.528 0 0 1 8.834 0a2.528 2.528 0 0 1 2.521 2.522v2.52H8.834zM8.834 6.313a2.528 2.528 0 0 1 2.521 2.521 2.528 2.528 0 0 1-2.521 2.521H2.522A2.528 2.528 0 0 1 0 8.834a2.528 2.528 0 0 1 2.522-2.521h6.312zM18.956 8.834a2.528 2.528 0 0 1 2.522-2.521A2.528 2.528 0 0 1 24 8.834a2.528 2.528 0 0 1-2.522 2.521h-2.522V8.834zM17.688 8.834a2.528 2.528 0 0 1-2.523 2.521 2.527 2.527 0 0 1-2.52-2.521V2.522A2.527 2.527 0 0 1 15.165 0a2.528 2.528 0 0 1 2.523 2.522v6.312zM15.165 18.956a2.528 2.528 0 0 1 2.523 2.522A2.528 2.528 0 0 1 15.165 24a2.527 2.527 0 0 1-2.52-2.522v-2.522h2.52zM15.165 17.688a2.527 2.527 0 0 1-2.52-2.523 2.526 2.526 0 0 1 2.52-2.52h6.313A2.527 2.527 0 0 1 24 15.165a2.528 2.528 0 0 1-2.522 2.523h-6.313z"/></svg>
                        Slack:
                    </span>
                    <span class="field-value" style="color:#aaa;font-style:italic;">Not set — edit profile to add</span>
                </div>
                `}
            </div>
        </div>

        <div style="margin: 20px 0; text-align: center; background: #f8f9fa; padding: 15px; border-radius: 8px;">
            <p style="margin-bottom: 15px; color: #495057;"><strong>Admin Actions</strong></p>
            <button onclick="openProfileModal(${user.id})" class="btn-primary" style="margin-right: 10px;">✏️ Edit Profile & Photo</button>
            <button onclick="refreshUserProfile(${user.id})" class="btn-secondary">🔄 Refresh</button>
        </div>
    `;
}

// Function to refresh user profile data
function refreshUserProfile(userId) {
    // For now, just re-render with current data
    // In a more advanced implementation, this would fetch fresh data from server
    viewUserProfile(userId);
    if (typeof showNotification === 'function') {
        showNotification('👤 User profile refreshed', 'info');
    }
}

// Employee Management Functions
function openAddModal() {
    if (!CONFIG.hasManageEmployees) return;
    
    // Hide any employee hover cards
    forceHideEmployeeCard();
    
    const modal = document.getElementById('addModal');
    if (modal) {
        modal.classList.add('show');
        // Reset template states
        const templateSelect = document.getElementById('templateSelect');
        const templateManagement = document.getElementById('templateManagement');
        const newTemplateName = document.getElementById('newTemplateName');
        const newTemplateDescription = document.getElementById('newTemplateDescription');
        
        if (templateSelect) templateSelect.value = '';
        if (templateManagement) templateManagement.style.display = 'none';
        if (newTemplateName) newTemplateName.value = '';
        if (newTemplateDescription) newTemplateDescription.value = '';
    }
}

function closeModal() {
    const modal = document.getElementById('addModal');
    if (modal) {
        modal.classList.remove('show');
    }
}

function setTemplate(type) {
    // Clear all checkboxes first
    for (let i = 0; i < 7; i++) {
        const checkbox = document.getElementById(`day${i}`);
        if (checkbox) checkbox.checked = false;
    }
    
    if (type === 'weekdays') {
        // Monday-Friday (1-5)
        for (let i = 1; i <= 5; i++) {
            const checkbox = document.getElementById(`day${i}`);
            if (checkbox) checkbox.checked = true;
        }
    } else if (type === 'weekend') {
        // Saturday-Sunday (0,6)
        const sun = document.getElementById('day0');
        const sat = document.getElementById('day6');
        if (sun) sun.checked = true;
        if (sat) sat.checked = true;
    } else if (type === 'all') {
        // All days
        for (let i = 0; i < 7; i++) {
            const checkbox = document.getElementById(`day${i}`);
            if (checkbox) checkbox.checked = true;
        }
    }
}

function editEmployee(id, name, team, shift, hours, level, schedule, supervisorId, skills) {
    if (!CONFIG.hasManageEmployees) return;
    
    // Hide any employee hover cards
    forceHideEmployeeCard();
    
    const modal = document.getElementById('editEmployeeModal');
    if (!modal) return;
    
    document.getElementById('editEmpId').value = id;
    document.getElementById('editEmpName').value = name;
    document.getElementById('editEmpTeam').value = team;
    document.getElementById('editEmpShift').value = shift;
    document.getElementById('editEmpHours').value = hours;
    document.getElementById('editEmpLevel').value = level;
    document.getElementById('editEmpSupervisor').value = supervisorId || '';
    
    // Set schedule checkboxes
    for (let i = 0; i < 7; i++) {
        const checkbox = document.getElementById('editDay' + i);
        if (checkbox) {
            checkbox.checked = schedule[i] === 1;
        }
    }
    
    // Set skills checkboxes
    const skillMH = document.getElementById('editSkillMH');
    const skillMA = document.getElementById('editSkillMA');
    const skillWin = document.getElementById('editSkillWin');
    
    if (skillMH) skillMH.checked = skills && skills.mh === true;
    if (skillMA) skillMA.checked = skills && skills.ma === true;
    if (skillWin) skillWin.checked = skills && skills.win === true;
    
    // Reset template selection
    const templateSelect = document.getElementById('editTemplateSelect');
    if (templateSelect) templateSelect.value = '';
    
    modal.classList.add('show');
}

function closeEditEmployeeModal() {
    const modal = document.getElementById('editEmployeeModal');
    if (modal) {
        modal.classList.remove('show');
    }
}

function applyEditTemplate() {
    const select = document.getElementById('editTemplateSelect');
    if (!select) return;
    
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.value) {
        const schedule = selectedOption.getAttribute('data-schedule').split(',');
        
        // Apply the template schedule to edit checkboxes
        for (let i = 0; i < 7; i++) {
            const checkbox = document.getElementById('editDay' + i);
            if (checkbox) {
                checkbox.checked = schedule[i] === '1';
            }
        }
    }
}

function clearEditSchedule() {
    // Clear all edit checkboxes
    for (let i = 0; i < 7; i++) {
        const checkbox = document.getElementById('editDay' + i);
        if (checkbox) checkbox.checked = false;
    }
    
    // Reset template selection
    const templateSelect = document.getElementById('editTemplateSelect');
    if (templateSelect) templateSelect.value = '';
}

function setEditTemplate(type) {
    // Clear all checkboxes first
    for (let i = 0; i < 7; i++) {
        const checkbox = document.getElementById('editDay' + i);
        if (checkbox) checkbox.checked = false;
    }
    
    if (type === 'weekdays') {
        // Monday-Friday (1-5)
        for (let i = 1; i <= 5; i++) {
            const checkbox = document.getElementById('editDay' + i);
            if (checkbox) checkbox.checked = true;
        }
    } else if (type === 'weekend') {
        // Saturday-Sunday (0,6)
        const sun = document.getElementById('editDay0');
        const sat = document.getElementById('editDay6');
        if (sun) sun.checked = true;
        if (sat) sat.checked = true;
    } else if (type === 'all') {
        // All days
        for (let i = 0; i < 7; i++) {
            const checkbox = document.getElementById('editDay' + i);
            if (checkbox) checkbox.checked = true;
        }
    }
}

function toggleActions(employeeId) {
    // Close all other open dropdowns first
    const allDropdowns = document.querySelectorAll('.actions-dropdown');
    allDropdowns.forEach(dropdown => {
        if (dropdown.id !== `actions-${employeeId}`) {
            dropdown.classList.remove('show');
        }
    });
    
    // Toggle the clicked dropdown
    const dropdown = document.getElementById(`actions-${employeeId}`);
    if (dropdown) {
        dropdown.classList.toggle('show');
    }
}

function confirmDelete(employeeId, employeeName) {
    if (confirm(`Delete ${employeeName}?`)) {
        // Create and submit a form for deletion
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_employee">
            <input type="hidden" name="employeeId" value="${employeeId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
    
    // Close the dropdown
    const dropdown = document.getElementById(`actions-${employeeId}`);
    if (dropdown) {
        dropdown.classList.remove('show');
    }
}
// Add this new function to script.js
function editEmployeeWithSkills(button) {
    const id = parseInt(button.dataset.id);
    const name = button.dataset.name;
    const team = button.dataset.team;
    const shift = parseInt(button.dataset.shift);
    const hours = button.dataset.hours;
    const level = button.dataset.level;
    const schedule = button.dataset.schedule.split(',').map(n => parseInt(n));
    const supervisorId = button.dataset.supervisor ? parseInt(button.dataset.supervisor) : null;
    
    // Parse skills safely
    let skills = {};
    try {
        skills = JSON.parse(button.dataset.skills || '{}');
    } catch (e) {
        skills = {};
    }
    
    // Call the original editEmployee function
    editEmployee(id, name, team, shift, hours, level, schedule, supervisorId, skills);
}
// Schedule Template Functions
function toggleTemplateManagement() {
    const management = document.getElementById('templateManagement');
    if (management) {
        management.style.display = management.style.display === 'none' ? 'block' : 'none';
    }
}

function applyTemplate() {
    const select = document.getElementById('templateSelect');
    if (!select) return;
    
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.value) {
        const schedule = selectedOption.getAttribute('data-schedule').split(',');
        
        // Apply the template schedule to checkboxes
        for (let i = 0; i < 7; i++) {
            const checkbox = document.getElementById('day' + i);
            if (checkbox) {
                checkbox.checked = schedule[i] === '1';
            }
        }
    }
}

function clearSchedule() {
    // Clear all checkboxes
    for (let i = 0; i < 7; i++) {
        const checkbox = document.getElementById('day' + i);
        if (checkbox) checkbox.checked = false;
    }
    
    // Reset template selection
    const templateSelect = document.getElementById('templateSelect');
    if (templateSelect) templateSelect.value = '';
}

function saveCurrentAsTemplate() {
    const nameInput = document.getElementById('newTemplateName');
    const descInput = document.getElementById('newTemplateDescription');
    
    if (!nameInput || !descInput) return;
    
    const name = nameInput.value.trim();
    const description = descInput.value.trim();
    
    if (!name || !description) {
        showNotification('Please enter both template name and description.', 'warning');
        return;
    }
    
    // Get current schedule
    const schedule = [];
    for (let i = 0; i < 7; i++) {
        const checkbox = document.getElementById('day' + i);
        schedule.push(checkbox && checkbox.checked ? 1 : 0);
    }
    
    // Create form to submit template
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="create_template">
        <input type="hidden" name="templateName" value="${name}">
        <input type="hidden" name="templateDescription" value="${description}">
        ${schedule.map((day, index) => 
            day ? `<input type="hidden" name="templateDay${index}" value="1">` : ''
        ).join('')}
    `;
    
    document.body.appendChild(form);
    form.submit();
}

function deleteTemplate(templateId, templateName) {
    if (confirm(`Delete template "${templateName}"? This action cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_template">
            <input type="hidden" name="templateId" value="${templateId}">
        `;
        
        document.body.appendChild(form);
        form.submit();
    }
}

// Bulk Schedule Changes Functions
function openBulkModal() {
    if (!CONFIG.hasEditSchedule) return;
    
    // Hide any employee hover cards
    forceHideEmployeeCard();
    
    const modal = document.getElementById('bulkModal');
    if (modal) {
        modal.classList.add('show');
        validateBulkForm(); // Check initial state
    }
}

function closeBulkModal() {
    const modal = document.getElementById('bulkModal');
    if (!modal) return;
    
    modal.classList.remove('show');
    
    // Reset form
    const elements = {
        'bulkStartDate': '',
        'bulkEndDate': '',
        'bulkStatusSelect': '',
        'bulkComment': '',
        'bulkCustomHours': ''
    };
    
    Object.keys(elements).forEach(id => {
        const element = document.getElementById(id);
        if (element) element.value = elements[id];
    });
    
    const selectAll = document.getElementById('selectAllEmployees');
    if (selectAll) selectAll.checked = false;
    
    const customHoursDiv = document.getElementById('bulkCustomHoursDiv');
    if (customHoursDiv) customHoursDiv.style.display = 'none';
    
    // Uncheck all employee checkboxes
    const checkboxes = document.querySelectorAll('.employee-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
    
    validateBulkForm();
}

function toggleAllEmployees() {
    const selectAll = document.getElementById('selectAllEmployees');
    const checkboxes = document.querySelectorAll('.employee-checkbox');
    
    if (selectAll) {
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
        });
    }
    
    validateBulkForm();
}

function handleBulkStatusChange() {
    const statusSelect = document.getElementById('bulkStatusSelect');
    const customHoursDiv = document.getElementById('bulkCustomHoursDiv');
    const commentField = document.getElementById('bulkComment');
    
    if (!statusSelect) return;
    
    const status = statusSelect.value;
    
    if (customHoursDiv) {
        customHoursDiv.style.display = status === 'custom_hours' ? 'block' : 'none';
    }
    
    const customHoursField = document.getElementById('bulkCustomHours');
    if (customHoursField && status !== 'custom_hours') {
        customHoursField.value = '';
    }
    
    if (commentField) {
        if (status === 'schedule') {
            commentField.value = '';
            commentField.disabled = true;
        } else {
            commentField.disabled = false;
        }
    }
    
    validateBulkForm();
}

function validateBulkForm() {
    const startDate = document.getElementById('bulkStartDate');
    const endDate = document.getElementById('bulkEndDate');
    const status = document.getElementById('bulkStatusSelect');
    const selectedEmployees = document.querySelectorAll('.employee-checkbox:checked');
    const submitBtn = document.getElementById('bulkSubmitBtn');
    
    if (!startDate || !endDate || !status || !submitBtn) return;
    
    // Check if all required fields are filled
    const isValid = startDate.value && endDate.value && status.value && 
                   selectedEmployees.length > 0 && 
                   startDate.value <= endDate.value;
    
    submitBtn.disabled = !isValid;
    
    if (isValid) {
        const employeeCount = selectedEmployees.length;
        const start = new Date(startDate.value);
        const end = new Date(endDate.value);
        const dayCount = Math.floor((end - start) / (1000 * 60 * 60 * 24)) + 1;
        const totalChanges = employeeCount * dayCount;
        submitBtn.textContent = `Apply ${totalChanges} Changes (${employeeCount} employees × ${dayCount} days)`;
    } else {
        submitBtn.textContent = 'Apply Bulk Changes';
    }
}

// Set Weekly Schedule helper function for bulk changes
function setBulkSchedule(preset) {
    // Get all schedule checkboxes (days 0-6 = Sun-Sat)
    const checkboxes = document.querySelectorAll('input[name="newSchedule[]"]');
    
    if (!checkboxes || checkboxes.length === 0) {
        return;
    }
    
    switch(preset) {
        case 'weekdays':
            // Monday-Friday (indices 1-5)
            checkboxes.forEach((cb, index) => {
                cb.checked = (index >= 1 && index <= 5);
            });
            break;
            
        case 'weekends':
            // Saturday-Sunday (indices 0 and 6)
            checkboxes.forEach((cb, index) => {
                cb.checked = (index === 0 || index === 6);
            });
            break;
            
        case 'all':
            // All days
            checkboxes.forEach(cb => cb.checked = true);
            break;
            
        case 'clear':
            // No days
            checkboxes.forEach(cb => cb.checked = false);
            break;
    }
}

// Schedule Editing Functions
function editCell(employeeId, day, employeeName, existingComment = '', currentStatus = '', hasOverride = false, isScheduled = false, employeeHours = '', dayOfWeek = 0, existingCustomHours = '') {
    if (!CONFIG.hasEditSchedule && !CONFIG.hasEditOwnSchedule) return;
    
    // Hide any employee hover cards
    forceHideEmployeeCard();
    
    const modal = document.getElementById('editModal');
    if (!modal) return;
    
    document.getElementById('editEmployeeId').value = employeeId;
    document.getElementById('editDay').value = day;
    document.getElementById('editTitle').textContent = `${employeeName} - ${window.currentMonthName || 'Month'} ${day}, ${window.currentYear || new Date().getFullYear()}`;
    
    // Update the "Override: Working" button to show actual hours
    const workingBtn = document.querySelector('.status-btn.status-on');
    if (workingBtn) {
        workingBtn.textContent = `Override: Working (${employeeHours})`;
    }
    
    // Determine what the default schedule would show
    let defaultText = isScheduled ? `Working (${employeeHours})` : 'Day Off';
    
    // Show current status and what default would be
    const statusNames = {
        'on': `Override: Working (${employeeHours})`, 
        'off': 'Override: Day Off', 
        'pto': 'PTO', 
        'sick': 'Sick', 
        'holiday': 'Holiday',
        'custom_hours': existingCustomHours ? `Custom Hours (${existingCustomHours})` : 'Custom Hours'
    };
    const currentStatusText = statusNames[currentStatus] || 'Unknown';
    
    const editInfo = document.getElementById('editInfo');
    if (editInfo) {
        if (hasOverride) {
            editInfo.textContent = `Current: ${currentStatusText}. Default schedule: ${defaultText}. Select action:`;
        } else {
            editInfo.textContent = `Current: Default schedule (${defaultText}). Select override:`;
        }
    }
    
    const commentField = document.getElementById('editComment');
    const customHoursField = document.getElementById('editCustomHours');
    
    if (commentField) commentField.value = existingComment;
    if (customHoursField) customHoursField.value = existingCustomHours;
    
    // Hide custom hours input initially
    const customHoursDiv = document.getElementById('customHoursDiv');
    if (customHoursDiv) customHoursDiv.style.display = 'none';
    
    // Highlight current status button if there's an override
    // Remove class from whichever button(s) currently hold it — no need to touch every button
    document.querySelectorAll('.status-btn-active, .status-btn-current')
        .forEach(btn => btn.classList.remove('status-btn-active', 'status-btn-current'));
    if (currentStatus && hasOverride) {
        const currentBtn = document.querySelector(`.status-${currentStatus}`);
        if (currentBtn) {
            currentBtn.classList.add('status-btn-current');
            if (currentStatus === 'custom_hours' && customHoursDiv) {
                customHoursDiv.style.display = 'block';
            }
        }
    }
    
    modal.classList.add('show');
}

function selectStatus(status) {
    const statusField = document.getElementById('editStatus');
    const saveBtn = document.getElementById('saveBtn');
    
    if (statusField) statusField.value = status;
    if (saveBtn) saveBtn.style.display = 'inline-block';
    
    // Highlight selected button — only touch buttons that actually carry a state class
    document.querySelectorAll('.status-btn-active, .status-btn-current')
        .forEach(btn => btn.classList.remove('status-btn-active', 'status-btn-current'));
    if (event && event.target) {
        event.target.classList.add('status-btn-active');
    }
    
    // Handle custom hours input visibility
    const customHoursDiv = document.getElementById('customHoursDiv');
    const commentField = document.getElementById('editComment');
    const customHoursField = document.getElementById('editCustomHours');
    
    if (status === 'custom_hours') {
        if (customHoursDiv) customHoursDiv.style.display = 'block';
        if (customHoursField) customHoursField.focus();
        if (commentField) commentField.disabled = false;
    } else {
        if (customHoursDiv) customHoursDiv.style.display = 'none';
        if (customHoursField) customHoursField.value = '';
        
        if (commentField) {
            if (status === 'schedule') {
                commentField.value = '';
                commentField.disabled = true;
            } else {
                commentField.disabled = false;
                commentField.focus();
            }
        }
    }
}

function closeEditModal() {
    const modal = document.getElementById('editModal');
    if (!modal) return;
    
    modal.classList.remove('show');
    
    const saveBtn = document.getElementById('saveBtn');
    const statusField = document.getElementById('editStatus');
    const commentField = document.getElementById('editComment');
    const customHoursField = document.getElementById('editCustomHours');
    const customHoursDiv = document.getElementById('customHoursDiv');
    
    if (saveBtn) saveBtn.style.display = 'none';
    if (statusField) statusField.value = '';
    if (commentField) {
        commentField.value = '';
        commentField.disabled = false;
    }
    if (customHoursField) customHoursField.value = '';
    if (customHoursDiv) customHoursDiv.style.display = 'none';
    
    // Reset button state — only touch the button(s) that actually have a state class
    document.querySelectorAll('.status-btn-active, .status-btn-current')
        .forEach(btn => btn.classList.remove('status-btn-active', 'status-btn-current'));
    
    // Reset the working button text
    const workingBtn = document.querySelector('.status-btn.status-on');
    if (workingBtn) workingBtn.textContent = 'Override: Working';
}

function toggleBackupList() {
    const backupList = document.getElementById('backupList');
    if (backupList) {
        backupList.classList.toggle('show');
    }
}

// User Management Functions
function openAddUserModal() {
    if (!CONFIG.hasManageUsers) return;
    
    // Hide any employee hover cards
    forceHideEmployeeCard();
    
    const modal = document.getElementById('addUserModal');
    if (modal) {
        modal.classList.add('show');
    }
}

function closeAddUserModal() {
    const modal = document.getElementById('addUserModal');
    if (modal) {
        modal.classList.remove('show');
    }
}

function toggleAddUserPassword(authMethod) {
    const group = document.getElementById('addUserPasswordGroup');
    const input = document.getElementById('addUserPassword');
    if (!group) return;
    const needsPassword = (authMethod === 'local' || authMethod === 'both');
    group.style.display = needsPassword ? 'block' : 'none';
    if (input) input.required = needsPassword;
}

function editUser(user) {
    if (!CONFIG.hasManageUsers) return;
    
    // Hide any employee hover cards
    forceHideEmployeeCard();
    
    const modal = document.getElementById('editUserModal');
    if (!modal) return;
    
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editUserFullName').value = user.full_name;
    document.getElementById('editUserUsername').value = user.username;
    document.getElementById('editUserEmail').value = user.email;
    document.getElementById('editUserRole').value = user.role;
    document.getElementById('editUserTeam').value = user.team;
    document.getElementById('editUserActive').checked = user.active;
    document.getElementById('editUserPassword').value = '';
    
    modal.classList.add('show');
}

function closeEditUserModal() {
    const modal = document.getElementById('editUserModal');
    if (modal) {
        modal.classList.remove('show');
    }
}

function confirmDeleteUser(userId, userName) {
    if (confirm(`Delete user ${userName}? This action cannot be undone.`)) {
        // Create and submit a form for deletion
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="userId" value="${userId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Employee Profile Hover Card Functions - ENHANCED VERSION
function showEmployeeCard(element, employee) {
    // Accept either a full employee object OR just an employee ID (number/string).
    // When called with an ID we look up from window.employeesData to avoid embedding
    // large JSON objects in every onmouseover attribute in the HTML.
    if (typeof employee === 'number' || typeof employee === 'string') {
        var empId = parseInt(employee, 10);
        employee = window.employeesData
            ? window.employeesData.find(function(e) { return e.id === empId; })
            : null;
        if (!employee) return;
    }

    // If the same employee's card is already visible, just reposition it — no rebuild needed
    const existingCard = document.getElementById('employeeHoverCard');
    if (existingCard && window._lastCardEmployeeId === employee.id) {
        // Cancel any pending hide and keep the card alive
        if (window.hideCardTimeout) {
            clearTimeout(window.hideCardTimeout);
            window.hideCardTimeout = null;
        }
        if (window.hoverCardTimeout) {
            clearTimeout(window.hoverCardTimeout);
            window.hoverCardTimeout = null;
        }
        // Reposition in case the element moved (e.g. scroll)
        const rect = element.getBoundingClientRect();
        const cardWidth = 320;
        const cardHeight = 280;
        let left = rect.right + 10;
        let top = rect.top;
        if (left + cardWidth > window.innerWidth) left = rect.left - cardWidth - 10;
        if (top + cardHeight > window.innerHeight) top = window.innerHeight - cardHeight - 10;
        if (top < 10) top = 10;
        if (left < 10) left = 10;
        existingCard.style.left = left + 'px';
        existingCard.style.top = top + 'px';
        return;
    }

    // Different employee — remove the old card
    if (existingCard) {
        existingCard.remove();
    }
    
    // Clear any existing timeouts
    if (window.hoverCardTimeout) {
        clearTimeout(window.hoverCardTimeout);
        window.hoverCardTimeout = null;
    }
    
    // Add a small delay to prevent cards from showing too quickly
    window.hoverCardTimeout = setTimeout(() => {
        // Double-check that we're still hovering over the element
        if (!element.matches(':hover')) {
            return;
        }
        
        // Double-check no other card exists
        const doubleCheck = document.getElementById('employeeHoverCard');
        if (doubleCheck) {
            doubleCheck.remove();
        }
        
        // Find user data for this employee (if they have a user account)
        const linkedUser = window.usersData ? window.usersData.find(user => 
            user.full_name.toLowerCase().trim() === employee.name.toLowerCase().trim()
        ) : null;
        
        // Get supervisor name
        const supervisorName = employee.supervisor_id ? 
            (window.employeesData ? 
                (window.employeesData.find(emp => emp.id === employee.supervisor_id)?.name || 'Unknown') 
                : 'Unknown') 
            : 'None';
        
        // Create the hover card
        const card = document.createElement('div');
        card.className = 'employee-hover-card';
        card.id = 'employeeHoverCard';
        
        // Calculate position
        const rect = element.getBoundingClientRect();
        const cardWidth = 320;
        const cardHeight = 280;
        
        // Position to the right of the element, but adjust if it would go off screen
        let left = rect.right + 10;
        let top = rect.top;
        
        // Adjust if card would go off the right edge
        if (left + cardWidth > window.innerWidth) {
            left = rect.left - cardWidth - 10;
        }
        
        // Adjust if card would go off the bottom
        if (top + cardHeight > window.innerHeight) {
            top = window.innerHeight - cardHeight - 10;
        }
        
        // Ensure card doesn't go off the top
        if (top < 10) {
            top = 10;
        }
        
        // Ensure card doesn't go off the left
        if (left < 10) {
            left = 10;
        }
        
        card.style.left = left + 'px';
        card.style.top = top + 'px';
        
        // Get profile photo if user exists (photo_url is pre-resolved server-side and covers local, Google, etc.)
        const profilePhotoUrl = linkedUser && linkedUser.photo_url ? linkedUser.photo_url : null;
        
        // Get level display
        const levelDisplay = employee.level ? getLevelDisplayName(employee.level) : '';
        
        // Get shift name
        const shiftName = getShiftDisplayName(employee.shift);
        
        // Build card content
        card.innerHTML = `
            <div class="employee-card-header">
                <div class="employee-card-avatar">
                    ${profilePhotoUrl ? 
                        `<img src="${profilePhotoUrl}" alt="Profile Photo" class="employee-card-photo">` :
                        `<div class="employee-card-initials">${employee.name.substring(0, 2).toUpperCase()}</div>`
                    }
                </div>
                <div class="employee-card-info">
                    <h4 class="employee-card-name">${escapeHtml(employee.name)}</h4>
                    <div class="employee-card-team team-${employee.team}">${employee.team.toUpperCase()}</div>
                </div>
            </div>
            
            <div class="employee-card-details">
                <div class="employee-card-row">
                    <span class="card-label">🕒 Shift:</span>
                    <span class="card-value">${shiftName}</span>
                </div>
                <div class="employee-card-row">
                    <span class="card-label">⏰ Hours:</span>
                    <span class="card-value">${escapeHtml(employee.hours)}</span>
                </div>
                ${levelDisplay ? `
                <div class="employee-card-row">
                    <span class="card-label">🏷️ Level:</span>
                    <span class="card-value level-badge" style="background:${getLevelColor(employee.level)};color:white !important;">${levelDisplay}</span>
                </div>
                ` : ''}
                <div class="employee-card-row">
                    <span class="card-label">👑 Reports to:</span>
                    <span class="card-value">${escapeHtml(supervisorName)}</span>
                </div>
                <div class="employee-card-row">
                    <span class="card-label">🎯 Skills:</span>
                    <span class="card-value">
                        ${employee.skills && employee.skills.mh  ? '<span style="background:#1976d2;color:#fff !important;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:bold;">MH</span>'  : ''}
                        ${employee.skills && employee.skills.ma  ? '<span style="background:#7b1fa2;color:#fff !important;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:bold;">MA</span>'  : ''}
                        ${employee.skills && employee.skills.win ? '<span style="background:#2e7d32;color:#fff !important;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:bold;">Win</span>' : ''}
                        ${(!employee.skills || (!employee.skills.mh && !employee.skills.ma && !employee.skills.win)) ? '<span class="no-account">None</span>' : ''}
                    </span>
                </div>
            </div>

            <div class="employee-card-schedule">
                <div class="card-label">📅 Weekly Schedule:</div>
                <div class="schedule-display">
                    ${['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((day, index) => 
                        `<span class="schedule-day ${employee.schedule[index] ? 'working' : 'off'}">${day}</span>`
                    ).join('')}
                </div>
            </div>
        `;
        
        window._lastCardEmployeeId = employee.id;
        document.body.appendChild(card);

        // Keep card alive while mouse is over it — cancel any pending hide
        card.addEventListener('mouseenter', () => cancelHideEmployeeCard());
        // Schedule hide when mouse leaves the card itself
        card.addEventListener('mouseleave', () => scheduleHideEmployeeCard());

        // Add a small delay to trigger the fade-in animation
        setTimeout(() => {
            if (card.parentNode && card.id === 'employeeHoverCard') {
                card.classList.add('visible');
            }
        }, 10);

    }, 150);
}

function hideEmployeeCard() {
    // Clear any pending timeouts
    if (window.hoverCardTimeout) {
        clearTimeout(window.hoverCardTimeout);
        window.hoverCardTimeout = null;
    }
    if (window.hideCardTimeout) {
        clearTimeout(window.hideCardTimeout);
        window.hideCardTimeout = null;
    }

    // Remove any existing cards immediately
    window._lastCardEmployeeId = null;
    const existingCard = document.getElementById('employeeHoverCard');
    if (existingCard) {
        existingCard.classList.remove('visible');
        existingCard.remove();
    }

    // Safety cleanup - remove any orphaned hover cards
    document.querySelectorAll('.employee-hover-card').forEach(card => {
        card.remove();
    });
}

// Schedule a delayed hide — gives user time to move mouse onto the card
function scheduleHideEmployeeCard() {
    if (window.hideCardTimeout) {
        clearTimeout(window.hideCardTimeout);
    }
    window.hideCardTimeout = setTimeout(() => {
        window.hideCardTimeout = null;
        hideEmployeeCard();
    }, 5000);
}

// Cancel a pending delayed hide (called when mouse enters the card)
function cancelHideEmployeeCard() {
    if (window.hideCardTimeout) {
        clearTimeout(window.hideCardTimeout);
        window.hideCardTimeout = null;
    }
}

// Enhanced force hide for modals and other situations
function forceHideEmployeeCard() {
    // Clear all timeouts
    if (window.hoverCardTimeout) {
        clearTimeout(window.hoverCardTimeout);
        window.hoverCardTimeout = null;
    }
    if (window.hideCardTimeout) {
        clearTimeout(window.hideCardTimeout);
        window.hideCardTimeout = null;
    }

    // Remove all hover cards immediately
    document.querySelectorAll('.employee-hover-card').forEach(card => {
        card.remove();
    });

    // Also remove by ID for safety
    const cardById = document.getElementById('employeeHoverCard');
    if (cardById) {
        cardById.remove();
    }
}

// Helper functions for employee card
function getLevelColor(level) {
    const colors = {
        'ssa':              '#dc2626',
        'ssa2':             '#9b59b6',
        'tam':              '#f97316',
        'tam2':             '#ea580c',
        'manager':          '#7c3aed',
        'Supervisor':       '#0369a1',
        'SR. Supervisor':   '#0f766e',
        'SR. Manager':      '#c2410c',
        'IMP Tech':         '#1d4ed8',
        'IMP Coordinator':  '#6d28d9',
        'l1':               '#06b6d4',
        'l2':               '#f59e0b',
        'l3':               '#0891b2',
        'SecOps T1':        '#1a56db',
        'SecOps T2':        '#7e3af2',
        'SecOps T3':        '#e74694',
        'SecEng':           '#d61f69',
        'technical_writer': '#0d9488',
        'trainer':          '#16a34a',
        'tech_coach':       '#0284c7',
    };
    return colors[level] || '#64748b';
}

function getLevelDisplayName(level) {
    const levels = {
        'ssa': 'SSA',
        'ssa2': 'SSA2',
        'tam': 'TAM',
        'tam2': 'TAM2',
        'manager': 'Manager',
        'SR. Supervisor': 'SR. Supervisor',
        'SR. Manager': 'SR. Manager',
        'IMP Tech': 'IMP Tech',
        'IMP Coordinator': 'IMP Coordinator',
        'l1': 'L1',
        'l2': 'L2',
        'l3': 'L3',
        'SecOps T1': 'SecOps T1',
        'SecOps T2': 'SecOps T2',
        'SecOps T3': 'SecOps T3',
        'SecEng': 'SecEng',
        'Supervisor': 'Supervisor',
        'technical_writer': 'Technical Writer',
        'trainer': 'Trainer',
        'tech_coach': 'Tech Coach'
    };
    return levels[level] || level.toUpperCase();
}

function getShiftDisplayName(shift) {
    const shifts = {
        1: '1st Shift',
        2: '2nd Shift',
        3: '3rd Shift',
    };
    return shifts[shift] || `${shift}th Shift`;
}

// Helper function to escape HTML (if not already defined)
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Event Listeners and Initialization
function setupEventListeners() {
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(event) {
        // Close employee action dropdowns
        if (!event.target.closest('.employee-actions')) {
            const allDropdowns = document.querySelectorAll('.actions-dropdown');
            allDropdowns.forEach(dropdown => {
                dropdown.classList.remove('show');
            });
        }
        
        // Close activity log dropdown
        const activityDropdown = document.getElementById('activityLogDropdown');
        const activityButton = document.querySelector('.activity-log-btn');
        
        if (activityButton && activityDropdown && 
            !activityButton.contains(event.target) && 
            !activityDropdown.contains(event.target)) {
            activityDropdown.classList.remove('show');
        }
        
        // Hide employee card when clicking anywhere except employee names or the card itself
        if (!event.target.closest('.employee-name') && !event.target.closest('.employee-hover-card')) {
            hideEmployeeCard();
        }
    });

    // Hide employee card when scrolling
    document.addEventListener('scroll', hideEmployeeCard, true);

    // Hide employee card when hovering over schedule cells — but NOT if mouse is on the card
    // and NOT if the card is currently visible (user may be moving mouse toward it)
    document.addEventListener('mouseover', function(event) {
        // Never hide while the cursor is inside the hover card
        if (event.target.closest('.employee-hover-card')) return;
        // Never schedule hide if the card is currently shown — let the card's own mouseleave handle it
        if (document.getElementById('employeeHoverCard')) return;
        // Check if we're hovering over a schedule cell (has onclick with editCell)
        const target = event.target.closest('td');
        if (target && (target.onclick || target.className.includes('status-')) && !target.classList.contains('employee-name')) {
            scheduleHideEmployeeCard();
        }
    });
    
    // Hide employee card when any modal opens
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                const target = mutation.target;
                if (target.classList.contains('modal') && target.classList.contains('show')) {
                    forceHideEmployeeCard();
                }
                if (target.classList.contains('edit-modal') && target.classList.contains('show')) {
                    forceHideEmployeeCard();
                }
            }
        });
    });
    
    // Observe all modals for class changes
    document.querySelectorAll('.modal, .edit-modal').forEach(modal => {
        observer.observe(modal, { attributes: true });
    });
    
    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
        const modals = ['addModal', 'editModal', 'editEmployeeModal', 'addUserModal', 'editUserModal', 'bulkModal', 'profileModal', 'profileViewerModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal && event.target === modal) {
                modal.classList.remove('show');
                // Hide employee card when modal closes
                hideEmployeeCard();
            }
        });
    });
    
    // Add event listeners for bulk form validation
    const bulkStartDate = document.getElementById('bulkStartDate');
    const bulkEndDate = document.getElementById('bulkEndDate');
    
    if (bulkStartDate && bulkEndDate) {
        bulkStartDate.addEventListener('change', validateBulkForm);
        bulkEndDate.addEventListener('change', validateBulkForm);
        
        // Single delegated listener on the container — handles all current and future checkboxes
        const bulkEmployeeList = document.getElementById('bulkEmployeeList');
        if (bulkEmployeeList) {
            bulkEmployeeList.addEventListener('change', function(event) {
                if (event.target.matches('.employee-checkbox')) {
                    validateBulkForm();
                }
            });
        }
    }
    
    // Enhanced: Set up date sorting form validation
    const sortDateInput = document.querySelector('input[name="sortDate"]');
    const sortBySelect = document.querySelector('select[name="sortBy"]');
    
    if (sortDateInput && sortBySelect) {
        sortDateInput.addEventListener('change', validateDateSortForm);
        sortBySelect.addEventListener('change', validateDateSortForm);
    }
}

// Initialize when page loads
function initializeApp() {
    // Initialize timezone detection and today highlighting first
    highlightSelectedSortDate(); initializeTodayHighlighting();
	
    // Set default template on page load for add employee form
    setTemplate('weekdays');
    
    // Set up event listeners
    setupEventListeners();
    
    // Initialize session management
    initializeSessionManagement();
    
    // Show date sorting status if active
    const urlParams = new URLSearchParams(window.location.search);
    const sortDate = urlParams.get('sortDate');
    const sortBy = urlParams.get('sortBy');
    
    if (sortDate && sortBy && sortBy !== 'name') {
        showNotification(`📊 Employees sorted by ${sortBy} status for ${new Date(sortDate).toLocaleDateString()}`, 'info');
    }
}

// Main initialization
document.addEventListener('DOMContentLoaded', function() {
    initializeApp();
});

// Prevent hover colour from sticking after clicking sidebar links.
// mousedown preventDefault stops the browser giving focus to the anchor
// (which causes :focus to mimic the :hover highlight) without stopping
// the click event, so showTab() still fires normally.
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.sidebar-link[href="#"]').forEach(function(link) {
        link.addEventListener('mousedown', function(e) {
            e.preventDefault();
        });
    });
});

// Clean up intervals when page unloads
window.addEventListener('beforeunload', function() {
    if (autoRefreshTimer) {
        clearInterval(autoRefreshTimer);
    }
});

// Export functions for global access
window.ScheduleApp = {
    initializeConfig,
    initializeTodayHighlighting,
    forceRefreshToday: initializeTodayHighlighting,
    showTab,
    openAddModal,
    closeModal,
    setTemplate,
    editEmployee,
    editEmployeeWithSkills,
    closeEditEmployeeModal,
    applyEditTemplate,
    clearEditSchedule,
    setEditTemplate,
    toggleActions,
    confirmDelete,
    toggleTemplateManagement,
    applyTemplate,
    clearSchedule,
    saveCurrentAsTemplate,
    deleteTemplate,
    openBulkModal,
    closeBulkModal,
    toggleAllEmployees,
    handleBulkStatusChange,
    validateBulkForm,
    editCell,
    selectStatus,
    closeEditModal,
    toggleBackupList,
    toggleActivityLog,
    openAddUserModal,
    closeAddUserModal,
    editUser,
    closeEditUserModal,
    confirmDeleteUser,
    logoutNow,
    openProfileModal,
    closeProfileModal,
    openProfileViewerModal,
    closeProfileViewerModal,
    viewUserProfile,
    refreshUserProfile,
    previewPhoto,
    togglePhotoDelete,
    // Enhanced Date sorting functions
    clearDateSort,
    setQuickDateSort,
    validateDateSortForm,
    setBulkSchedule,
    // Heatmap functions
    openHeatmap: function() {
        window.open('heatmap.php', '_blank');
    },
    exportHeatmapData: function() {
        // Heatmap data export
    }
};

// Make functions globally available for onclick handlers
window.showTab = showTab;
window.openProfileModal = openProfileModal;
window.closeProfileModal = closeProfileModal;
window.openProfileViewerModal = openProfileViewerModal;
window.closeProfileViewerModal = closeProfileViewerModal;
window.viewUserProfile = viewUserProfile;
window.refreshUserProfile = refreshUserProfile;
window.previewPhoto = previewPhoto;
window.togglePhotoDelete = togglePhotoDelete;
window.logoutNow = logoutNow;
window.showEmployeeCard = showEmployeeCard;
window.hideEmployeeCard = hideEmployeeCard;
window.scheduleHideEmployeeCard = scheduleHideEmployeeCard;
window.cancelHideEmployeeCard = cancelHideEmployeeCard;
window.forceHideEmployeeCard = forceHideEmployeeCard;

function highlightSelectedSortDate() {
    var params = new URLSearchParams(window.location.search);
    var sortDate = params.get('sortDate') || params.get('preserveSortDate');
    
    if (!sortDate) return;
    
    try {
        var selectedDate = new Date(sortDate + 'T12:00:00');
        var selectedYear = selectedDate.getFullYear();
        var selectedMonth = selectedDate.getMonth();
        var selectedDay = selectedDate.getDate();
        
        if (selectedYear === window.currentYear && selectedMonth === window.currentMonth) {
            // Remove existing highlights
            var highlights = document.querySelectorAll('.sort-date-highlight');
            for (var i = 0; i < highlights.length; i++) {
                highlights[i].classList.remove('sort-date-highlight');
            }
            
            // Add highlights to the selected date
            var table = document.querySelector('.schedule-table');
            if (table) {
                var headers = table.querySelectorAll('thead th');
                var rows = table.querySelectorAll('tbody tr');
                
                if (headers[selectedDay]) {
                    headers[selectedDay].classList.add('sort-date-highlight');
                }
                
                for (var r = 0; r < rows.length; r++) {
                    var cells = rows[r].querySelectorAll('td');
                    if (cells[selectedDay]) {
                        cells[selectedDay].classList.add('sort-date-highlight');
                    }
                }
            }
        }
    } catch (error) {
    }
}

// ============================================================================
// THEME & TAB FIXES  (merged from theme_tab_fixes.js)
// ============================================================================

class ThemeManager {
    constructor() {
        this.currentTheme = 'default';
        this.themeStyleElement = null;
        
        this.themes = {
            default: { name: '🔵 Default Theme', colors: null },
            ocean: { name: '🌊 Ocean Blue', colors: this.getOceanColors() },
            forest: { name: '🌲 Forest Green', colors: this.getForestColors() },
            sunset: { name: '🌅 Sunset Orange', colors: this.getSunsetColors() },
            royal: { name: '👑 Royal Purple', colors: this.getRoyalColors() },
            dark: { name: '🌙 Dark Mode', colors: this.getDarkColors() },
            crimson: { name: '🔴 Crimson Red', colors: this.getCrimsonColors() },
            teal: { name: '🔵 Teal Cyan', colors: this.getTealColors() },
            amber: { name: '🟡 Amber Gold', colors: this.getAmberColors() },
            slate: { name: '⚪ Slate Gray', colors: this.getSlateColors() },
            emerald: { name: '🟢 Emerald Green', colors: this.getEmeraldColors() },
            midnight: { name: '🌙 Midnight Blue', colors: this.getMidnightColors() },
            rose: { name: '🌹 Rose Pink', colors: this.getRoseColors() },
            copper: { name: '🟤 Copper Bronze', colors: this.getCopperColors() }
        };
        
        this.init();
    }
    
    init() {
        this.loadSavedTheme();
    }
    
    applyTheme(themeName) {
        if (this.themeStyleElement) {
            this.themeStyleElement.remove();
            this.themeStyleElement = null;
        }

        if (themeName !== 'default' && this.themes[themeName]?.colors) {
            this.themeStyleElement = document.createElement('style');
            this.themeStyleElement.id = 'dynamic-theme-styles';
            this.themeStyleElement.textContent = this.generateThemeCSS(themeName);
            document.head.appendChild(this.themeStyleElement);
        }

        // Stamp the active theme name on <body> so CSS (e.g. api_docs.php) can
        // react with attribute selectors like [data-theme="dark"] without needing
        // JavaScript.  Remove the attribute entirely for the default theme so
        // no stale value is left behind.
        if (themeName && themeName !== 'default') {
            document.body.setAttribute('data-theme', themeName);
        } else {
            document.body.removeAttribute('data-theme');
        }

        this.currentTheme = themeName;
        this.saveTheme();
        
        if (typeof updateHeatmapData === 'function') {
            const heatmapTab = document.getElementById('heatmap-tab');
            if (heatmapTab && heatmapTab.classList.contains('active')) {
                setTimeout(() => updateHeatmapData(), 100);
            }
        }
    }
    
    generateThemeCSS(themeName) {
        const colors = this.themes[themeName]?.colors;
        if (!colors) return '';
        const isDark = themeName === 'dark';
        const surfaceBg  = isDark ? '#253549' : (colors.card === '#ffffff' ? '#f8f9fa' : colors.card);
        const inputBg    = isDark ? '#253549' : '#ffffff';
        return `
            /* ── CSS variable injection — fills all var(--...) inline-style fallbacks ── */
            body {
                --card-bg: ${colors.card};
                --text-color: ${colors.text};
                --text-muted: ${colors.textMuted};
                --border-color: ${colors.border};
                --body-bg: ${colors.surface};
                --surface-bg: ${surfaceBg};
                --secondary-bg: ${surfaceBg};
                --surface-color: ${colors.surface};
                --primary-color: ${colors.primary};
                --secondary-color: ${colors.secondary};
                --success-color: ${colors.success};
                --warning-color: ${colors.warning};
                --danger-color: ${colors.danger};
                --accent-color: ${colors.accent};
                --input-bg: ${inputBg};
                --search-bg: ${inputBg};
                --search-border: ${colors.border};
                --search-text: ${colors.text};
            }
            .header { background: ${colors.primary} !important; color: white !important; }
            .header, .header *, .header *:before, .header *:after { color: white !important; text-shadow: 0 1px 2px rgba(0,0,0,0.5) !important; }
            .theme-dropdown, .theme-dropdown * { color: #333 !important; text-shadow: none !important; }
            body { background: ${colors.surface} !important; color: ${colors.text} !important; }
            .container { background: ${colors.card} !important; color: ${colors.text} !important; }
            .nav-tabs { background: ${isDark ? colors.secondary : '#ecf0f1'} !important; border-bottom-color: ${colors.border} !important; }
            .nav-tab { color: ${colors.text} !important; background: ${isDark ? colors.secondary : 'transparent'} !important; }
            .nav-tab.active { background: ${colors.card} !important; border-bottom-color: ${colors.accent} !important; color: ${colors.accent} !important; }
            .nav-tab:hover { background: ${isDark ? colors.accent + '33' : '#d5dbdb'} !important; }
            button:not(.status-btn), .btn { background: ${colors.accent} !important; color: ${isDark ? '#0f172a' : 'white'} !important; border-color: ${colors.accent} !important; }
            button:not(.status-btn):hover, .btn:hover { background: ${colors.primary} !important; color: white !important; }
            /* Status buttons keep their fixed semantic colours across all themes */
            .status-btn { color: #fff !important; }
            .status-btn.status-on           { background: #4B59B4 !important; }
            .status-btn.status-off          { background: #10598A !important; }
            .status-btn.status-pto          { background: #E76418 !important; }
            .status-btn.status-sick         { background: #dc3545 !important; }
            .status-btn.status-holiday      { background: #28D765 !important; color: #000 !important; }
            .status-btn.status-custom_hours { background: #714BB4 !important; }
            .status-btn.status-schedule     { background: #3730a3 !important; }
            .btn-green { background: ${colors.success} !important; }
            .btn-orange { background: ${colors.warning} !important; }
            .btn-red { background: ${colors.danger} !important; }
            .btn-purple { background: ${colors.primary} !important; }
            .schedule-table th { background: ${colors.primary} !important; color: white !important; border-color: ${colors.border} !important; }
            .schedule-table td { border-color: ${colors.border} !important; color: ${colors.text} !important; }
            ${isDark ? `.schedule-day.off { background: #253549 !important; color: #94a3b8 !important; }` : ''}
            .employee-name { background: ${isDark ? '#253549' : '#95a5a6'} !important; color: ${isDark ? colors.text : 'white'} !important; }
            ${this.generateHeatmapColors(colors, isDark)}
            .stat-card { background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.secondary} 100%) !important; color: ${isDark ? colors.text : 'white'} !important; }
            .stat-value, .stat-label { color: ${isDark ? colors.text : 'white'} !important; }
            .modal-content, .edit-modal-content { background: ${colors.card} !important; color: ${colors.text} !important; border: 1px solid ${colors.border} !important; }
            select, input[type="text"], input[type="email"], input[type="password"], input[type="date"] { background: ${inputBg} !important; color: ${colors.text} !important; border-color: ${colors.border} !important; }
            .message.success { background: ${colors.success}22 !important; color: ${colors.success} !important; border-color: ${colors.success}66 !important; }
            .message.error { background: ${colors.danger}22 !important; color: ${colors.danger} !important; border-color: ${colors.danger}66 !important; }
            ${isDark ? this.getDarkModeScrollbars(colors) : ''}
            /* ── Export table (Google Sheets / Excel preview) ── */
            .se-wrap { background: ${colors.card} !important; border-color: ${colors.border} !important; }
            .se-table thead th { background: ${colors.primary} !important; color: #ffffff !important; }
            .se-table td { color: ${colors.text} !important; border-color: ${colors.border} !important; }
            .se-td-name { color: ${colors.text} !important; }
            .se-table tbody tr:nth-child(odd)  { background: ${colors.card} !important; }
            .se-table tbody tr:nth-child(even) { background: ${isDark ? '#0f172a' : colors.primary + '0d'} !important; }
            .se-table tbody tr:hover           { background: ${colors.primary}22 !important; }
            .se-td-off { background: ${isDark ? colors.secondary : 'var(--border-color,#e2e8f0)'} !important; color: ${colors.textMuted} !important; }
            .se-export-section, .se-export-section label { color: ${colors.text} !important; }
            ${isDark ? `
            /* ── High-specificity dark overrides — beat hardcoded !important in page <style> blocks ── */
            /* API Reference shell */
            body[data-theme="dark"] .api-doc-shell {
                background-color: #1e293b !important;
                color: #f1f5f9 !important;
                box-shadow: 0 2px 12px rgba(0,0,0,0.5) !important;
            }
            /* Propagate shell text color to all descendants except code blocks (which have their own syntax palette) */
            body[data-theme="dark"] .api-doc-shell *:not(.api-code):not(.api-code *) { color: inherit !important; }
            /* User Manual shell */
            body[data-theme="dark"] .man-doc-shell {
                background-color: #1e293b !important;
                color: #f1f5f9 !important;
                box-shadow: 0 2px 12px rgba(0,0,0,0.5) !important;
            }
            body[data-theme="dark"] .man-doc-shell * { color: inherit !important; }
            /* Skill form container backgrounds */
            body[data-theme="dark"] .skill-form-badges {
                background: #253549 !important;
                border-color: #334155 !important;
            }
            /* Skill badge pills */
            body[data-theme="dark"] .skill-badge-mh {
                background: #1e3a5f !important;
                color: #93c5fd !important;
            }
            body[data-theme="dark"] .skill-badge-ma {
                background: #3b1f4e !important;
                color: #c4b5fd !important;
            }
            body[data-theme="dark"] .skill-badge-win {
                background: #14532d !important;
                color: #86efac !important;
            }
            body[data-theme="dark"] .skill-label-text { color: #f1f5f9 !important; }
            /* Checklist warning box */
            body[data-theme="dark"] .checklist-warning-box {
                background: #2d2510 !important;
                border-color: #78350f !important;
                color: #fde68a !important;
            }
            body[data-theme="dark"] .checklist-warning-box * { color: #fde68a !important; }
            /* Checklist step card surface elements */
            body[data-theme="dark"] .checklist-step-surface {
                background: #253549 !important;
                border-color: #334155 !important;
                color: #f1f5f9 !important;
            }
            body[data-theme="dark"] .checklist-step-surface strong,
            body[data-theme="dark"] .checklist-step-surface p,
            body[data-theme="dark"] .checklist-step-surface em { color: #cbd5e1 !important; }
            ` : ''}
        `;
    }
    
    generateHeatmapColors(colors, isDark) {
        if (isDark) {
            return `
                .intensity-0 { background: ${colors.secondary} !important; color: ${colors.textMuted} !important; }
                .intensity-1 { background: #1e3a5f !important; color: ${colors.text} !important; }
                .intensity-2 { background: #2563eb !important; color: ${colors.text} !important; }
                .intensity-3 { background: #3b82f6 !important; color: white !important; }
                .intensity-4 { background: #60a5fa !important; color: white !important; }
                .intensity-5 { background: #93c5fd !important; color: ${colors.card} !important; }
                .intensity-6 { background: ${colors.primary} !important; color: white !important; }
                .intensity-7 { background: ${colors.accent} !important; color: white !important; }
                .intensity-8 { background: ${colors.success} !important; color: white !important; }
            `;
        }
        return `
            .intensity-0 { background: #f8f9fa !important; color: #6c757d !important; }
            .intensity-1 { background: ${colors.primary}22 !important; color: ${colors.primary} !important; }
            .intensity-2 { background: ${colors.primary}33 !important; color: ${colors.primary} !important; }
            .intensity-3 { background: ${colors.primary}55 !important; color: ${colors.primary} !important; }
            .intensity-4 { background: ${colors.primary}77 !important; color: white !important; }
            .intensity-5 { background: ${colors.primary}99 !important; color: white !important; }
            .intensity-6 { background: ${colors.primary}BB !important; color: white !important; }
            .intensity-7 { background: ${colors.primary}DD !important; color: white !important; }
            .intensity-8 { background: ${colors.primary} !important; color: white !important; }
        `;
    }
    
    getDarkModeScrollbars(colors) {
        return `
            ::-webkit-scrollbar { width: 8px; }
            ::-webkit-scrollbar-track { background: ${colors.secondary}; }
            ::-webkit-scrollbar-thumb { background: ${colors.accent}; border-radius: 4px; }
            ::-webkit-scrollbar-thumb:hover { background: ${colors.primary}; }
        `;
    }
    
    saveTheme() {
        try { localStorage.setItem('scheduleSystemTheme', this.currentTheme); } catch (e) {}
    }
    
    loadSavedTheme() {
        try {
            const saved = localStorage.getItem('scheduleSystemTheme');
            if (saved && this.themes[saved]) { this.applyTheme(saved); }
        } catch (e) {}
    }
    
    getOceanColors()   { return { primary: '#0066ff', secondary: '#003366', accent: '#0ea5e9', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#003366', card: '#ffffff', text: '#003366', textMuted: '#000000', border: '#cbd5e1' }; }
    getForestColors()  { return { primary: '#065f46', secondary: '#059669', accent: '#10b981', success: '#059669', warning: '#d97706', danger: '#dc2626', surface: '#003300', card: '#ffffff', text: '#1e293b', textMuted: '#1e293b', border: '#a7f3d0' }; }
    getSunsetColors()  { return { primary: '#c2410c', secondary: '#f97316', accent: '#fb923c', success: '#059669', warning: '#996600', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#fed7aa' }; }
    getRoyalColors()   { return { primary: '#6600ff', secondary: '#ffffff', accent: '#c084fc', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#e9d5ff' }; }
    getDarkColors()    { return { primary: '#3b82f6', secondary: '#1e293b', accent: '#60a5fa', success: '#10b981', warning: '#f59e0b', danger: '#ef4444', surface: '#0f172a', card: '#1e293b', text: '#f1f5f9', textMuted: '#94a3b8', border: '#334155' }; }
    getCrimsonColors() { return { primary: '#991b1b', secondary: '#dc2626', accent: '#ef4444', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#ffffff' }; }
    getTealColors()    { return { primary: '#134e4a', secondary: '#0f766e', accent: '#14b8a6', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#ffffff' }; }
    getAmberColors()   { return { primary: '#92400e', secondary: '#d97706', accent: '#f59e0b', success: '#059669', warning: '#d97706', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#ffffff' }; }
    getSlateColors()   { return { primary: '#1e293b', secondary: '#475569', accent: '#64748b', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#ffffff' }; }
    getEmeraldColors() { return { primary: '#065f46', secondary: '#059669', accent: '#10b981', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#ffffff' }; }
    getMidnightColors(){ return { primary: '#312e81', secondary: '#4338ca', accent: '#6366f1', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#ffffff' }; }
    getRoseColors()    { return { primary: '#9f1239', secondary: '#e11d48', accent: '#f43f5e', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#ffffff' }; }
    getCopperColors()  { return { primary: '#9a3412', secondary: '#ea580c', accent: '#fb923c', success: '#059669', warning: '#f59e0b', danger: '#dc2626', surface: '#000000', card: '#ffffff', text: '#1e293b', textMuted: '#64748b', border: '#ffffff' }; }
}

// ── Theme dropdown helpers ────────────────────────────────────────────────────
function toggleThemeDropdown() {
    const dropdown = document.getElementById('themeDropdown');
    if (dropdown) {
        const isVisible = dropdown.style.display === 'block';
        dropdown.style.display = isVisible ? 'none' : 'block';
    }
}

function handleThemeSelection(themeName) {
    const dropdown = document.getElementById('themeDropdown');
    if (dropdown) dropdown.style.display = 'none';
    if (window.themeManager) { window.themeManager.applyTheme(themeName); }
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.theme-selector')) {
        const dropdown = document.getElementById('themeDropdown');
        if (dropdown && dropdown.style.display === 'block') { dropdown.style.display = 'none'; }
    }
});

// ── Init ──────────────────────────────────────────────────────────────────────
if (!window.themeManager) { window.themeManager = new ThemeManager(); }
document.addEventListener('DOMContentLoaded', function() {
    if (!window.themeManager) { window.themeManager = new ThemeManager(); }
});

// ── Schedule table: event delegation for cell clicks ─────────────────────────
// Previously every cell had a large inline onclick="ScheduleApp.editCell(empId, day, name, ...)"
// which caused the browser to parse & compile JS for 1000+ cells on load.
// Now we attach ONE listener on the table body and read data-* attributes.
(function() {
    function attachScheduleTableDelegate() {
        var tbody = document.querySelector('.schedule-table tbody');
        if (!tbody || tbody._schedDelegateAttached) return;
        tbody._schedDelegateAttached = true;

        tbody.addEventListener('click', function(e) {
            var td = e.target.closest('td[data-eid]');
            if (!td) return;

            var empId  = parseInt(td.dataset.eid,  10);
            var day    = parseInt(td.dataset.day,   10);
            var dow    = parseInt(td.dataset.dow,   10);
            var hasOvr = td.dataset.ovr === '1';
            var comment    = td.dataset.comment || '';
            var customHours = td.dataset.ch    || '';

            // Derive status from CSS class (status-on / status-off / status-pto …)
            var statusClass = Array.from(td.classList).find(function(c) { return c.startsWith('status-'); });
            var status = statusClass ? statusClass.replace('status-', '') : 'off';

            // Look up employee from the globally available employeesData array
            var emp = window.employeesData
                ? window.employeesData.find(function(e) { return e.id == empId; })
                : null;
            var name        = emp ? (emp.name  || '') : '';
            var hours       = emp ? (emp.hours || '') : '';
            var isScheduled = emp && emp.schedule ? (emp.schedule[dow] == 1) : false;

            if (typeof editCell === 'function') {
                editCell(empId, day, name, comment, status, hasOvr, isScheduled, hours, dow, customHours);
            }
        });
    }

    // Try immediately (script may run after DOM is ready)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachScheduleTableDelegate);
    } else {
        attachScheduleTableDelegate();
    }
}());

// ============================================================================
// END THEME & TAB FIXES
// ============================================================================

// ── AJAX intercept for Edit Employee form ─────────────────────────────────────
// Submits the form via fetch() instead of a full-page POST, eliminating the
// ~4 s page-reload round-trip and keeping the user on the same tab.
(function () {
    function attachEditEmployeeAjax() {
        var form = document.getElementById('editEmployeeForm');
        if (!form || form._ajaxAttached) return;
        form._ajaxAttached = true;

        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            var data = new FormData(this);
            data.append('_ajax', '1');

            var btn = this.querySelector('button[type="submit"]');
            var origText = btn ? btn.textContent : '';
            if (btn) { btn.disabled = true; btn.textContent = '⏳ Saving…'; }

            try {
                var res = await fetch(window.location.pathname, { method: 'POST', body: data });

                // Check HTTP status before parsing — a 5xx/4xx page is HTML, not JSON
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }

                // Guard against the server returning a redirect page (HTML) instead of JSON
                var contentType = res.headers.get('Content-Type') || '';
                if (!contentType.includes('application/json')) {
                    // Parse failed — fall back to a full page reload so the user sees
                    // whatever the server sent (e.g. a PHP error or session timeout page)
                    window.location.reload();
                    return;
                }

                var json = await res.json();
                if (json.success) {
                    if (typeof showNotification === 'function') {
                        showNotification(json.message || '✅ Employee updated', 'success');
                    }

                    // ── Helper: get display text from a <select> element ──────────
                    function _selText(id) {
                        var el = document.getElementById(id);
                        if (!el) return '';
                        var opt = el.options[el.selectedIndex];
                        return opt ? opt.text : '';
                    }

                    // ── Helper: build skill badge HTML (mirrors PHP generateSkillsBadges) ──
                    function _skillBadges(skills) {
                        var html = '';
                        var defs = [
                            { key: 'mh',  label: 'MH',  bg: '#1976d2' },
                            { key: 'ma',  label: 'MA',  bg: '#7b1fa2' },
                            { key: 'win', label: 'Win', bg: '#2e7d32' }
                        ];
                        defs.forEach(function (d) {
                            if (skills[d.key]) {
                                html += '<span class="skill-badge" style="background:' + d.bg +
                                    ' !important;color:#fff !important;padding:2px 6px;border-radius:8px;' +
                                    'font-size:0.75em;font-weight:bold;margin:1px 2px;display:inline-block;">' +
                                    d.label + '</span>';
                            }
                        });
                        return html;
                    }

                    // ── Helper: build Slack "DM in Slack" link HTML ───────────────
                    function _slackLink(slackId) {
                        if (!slackId) return '';
                        var href = 'https://slack.com/app_redirect?channel=' +
                            encodeURIComponent(slackId) + '&team=T024FSSFY';
                        return '<a href="' + href + '" target="_blank" rel="noopener" ' +
                            'onclick="event.stopPropagation();" ' +
                            'style="display:inline-block;background:#4A154B;color:#fff !important;' +
                            'font-size:9px;font-weight:600;padding:2px 5px;border-radius:8px;' +
                            'text-decoration:none;margin-left:3px;vertical-align:middle;white-space:nowrap;">' +
                            'DM in Slack</a>';
                    }

                    // ── Helper: CSS class slug for level (mirrors PHP preg_replace) ──
                    function _levelClass(level) {
                        return 'level-' + (level || '').replace(/[^a-zA-Z0-9_-]/g, '-');
                    }

                    // ── Shift name lookup (mirrors PHP getShiftName) ──────────────
                    function _shiftName(n) {
                        var map = { 1: '1st Shift', 2: '2nd Shift', 3: '3rd Shift' };
                        return map[parseInt(n, 10)] || (n + 'th Shift');
                    }

                    // ── Level display name lookup (mirrors PHP getLevelName) ───────
                    function _levelName(level) {
                        var map = {
                            '': '', 'ssa': 'SSA', 'ssa2': 'SSA2', 'tam': 'TAM', 'tam2': 'TAM2',
                            'SR. Supervisor': 'SR. Supervisor', 'SR. Manager': 'SR. Manager',
                            'manager': 'Manager', 'IMP Tech': 'IMP Tech',
                            'IMP Coordinator': 'IMP Coordinator',
                            'l1': 'L1', 'l2': 'L2', 'l3': 'L3',
                            'SecOps T1': 'SecOps T1', 'SecOps T2': 'SecOps T2',
                            'SecOps T3': 'SecOps T3', 'SecEng': 'SecEng',
                            'Supervisor': 'Supervisor',
                            'technical_writer': 'Technical Writer',
                            'trainer': 'Trainer', 'tech_coach': 'Tech Coach'
                        };
                        return (level in map) ? map[level] : level.toUpperCase();
                    }

                    // ── Read ALL form fields ──────────────────────────────────────
                    var empId       = data.get('empId')       || '';
                    var newName     = (data.get('empName')     || '').trim();
                    var newTeam     = (data.get('empTeam')     || '').trim();
                    var newShift    = data.get('empShift')     || '1';
                    var newHours    = (data.get('empHours')    || '').trim();
                    var newLevel    = (data.get('empLevel')    || '').trim();
                    var newEmail    = (data.get('empEmail')    || '').trim();
                    var newSlackId  = (data.get('empSlackId')  || '').trim() || null;
                    var newStart    = (data.get('empStartDate')|| '').trim();
                    var newSupId    = data.get('empSupervisor') || '';
                    var newSkills   = {
                        mh:  data.get('skillMH')  !== null,
                        ma:  data.get('skillMA')  !== null,
                        win: data.get('skillWin') !== null
                    };
                    // Days 0-6 (checkboxes — present in FormData only when checked)
                    var newDays = [];
                    for (var d = 0; d <= 6; d++) {
                        newDays.push(data.get('day' + d) !== null);
                    }

                    // Diagnostic: log what was submitted vs what the server saved
                    console.log('[EditEmployee] Submitted days (FormData):', newDays,
                        '| Server savedSchedule:', json.savedSchedule || '(not returned)',
                        '| Server savedHours:', json.savedHours || '(not returned)',
                        '| empId:', empId);


                    // Use the server-returned schedule as the authoritative source.
                    // If PHP returned savedSchedule, use it directly; otherwise fall back
                    // to deriving from FormData so the cache still reflects the new state.
                    var serverDays = Array.isArray(json.savedSchedule)
                        ? json.savedSchedule                              // authoritative
                        : newDays.map(function(b) { return b ? 1 : 0; }); // fallback

                    // Use the server-returned hours as the authoritative source.
                    var serverHours = (json.savedHours !== undefined && json.savedHours !== null && json.savedHours !== '')
                        ? json.savedHours   // authoritative from DB
                        : newHours;         // fallback to FormData value

                    if (empId && Array.isArray(window.employeesData)) {
                        var empIdx = window.employeesData.findIndex(function (e) {
                            return String(e.id) === String(empId);
                        });
                        if (empIdx !== -1) {
                            var emp = window.employeesData[empIdx];

                            // Snapshot the old schedule AND shift BEFORE updating the cache
                            // so we can detect what changed and decide if a reload is needed.
                            var oldSchedule = emp.schedule
                                ? emp.schedule.slice()
                                : [0, 0, 0, 0, 0, 0, 0];
                            var oldShift = emp.shift;

                            // Fall back to existing values if the field was empty
                            if (!newName)    newName    = emp.name  || newName;
                            if (!newTeam)    newTeam    = emp.team  || newTeam;
                            if (!serverHours) serverHours = emp.hours || serverHours;

                            // Resolve supervisor name from the select element
                            // (ID is editEmpSupervisor in the inline form, not empSupervisor)
                            var supEl = document.getElementById('editEmpSupervisor');
                            var newSupName = '';
                            if (supEl && newSupId) {
                                var supOpt = supEl.querySelector('option[value="' + newSupId + '"]');
                                newSupName = supOpt ? supOpt.textContent.trim() : '';
                            }

                            // ── Persist ALL values into the JS cache ─────────────
                            emp.name         = newName;
                            emp.team         = newTeam;
                            emp.shift        = parseInt(newShift, 10);
                            emp.hours        = serverHours;
                            emp.level        = newLevel;
                            emp.email        = newEmail;
                            emp.slack_id     = newSlackId;
                            emp.start_date   = newStart;
                            // Sync the date input so if the form is re-populated
                            // via openEditEmployeeInline() it shows the saved value.
                            var sdEl = document.getElementById('editEmpStartDate');
                            if (sdEl) sdEl.value = newStart;
                            emp.supervisor_id= newSupId ? parseInt(newSupId, 10) : null;
                            emp.skills       = newSkills;

                            // ── Sync window.usersData for role / auth_method ──────
                            // Without this, re-opening the edit form shows the old
                            // role in the User Role dropdown (openEditEmployeeInline
                            // reads role from window.usersData, not the DB).
                            var newUserRole   = (data.get('empUserRole')   || '').trim();
                            var newAuthMethod = (data.get('empAuthMethod') || 'both').trim();
                            if (Array.isArray(window.usersData)) {
                                var uIdx = -1;
                                // 1) match by user_id on the employee record
                                if (emp.user_id) {
                                    uIdx = window.usersData.findIndex(function(u) { return u.id == emp.user_id; });
                                }
                                // 2) fallback: match by employee_id on the user record
                                if (uIdx === -1) {
                                    uIdx = window.usersData.findIndex(function(u) { return u.employee_id == emp.id; });
                                }
                                // 3) last-resort: match by email
                                if (uIdx === -1 && emp.email) {
                                    uIdx = window.usersData.findIndex(function(u) {
                                        return u.email && u.email.toLowerCase() === emp.email.toLowerCase();
                                    });
                                }
                                if (uIdx !== -1) {
                                    if (newUserRole) window.usersData[uIdx].role = newUserRole;
                                    window.usersData[uIdx].auth_method = newAuthMethod;
                                    // Also heal emp.user_id in the JS cache so next save uses fast path
                                    if (!emp.user_id) emp.user_id = window.usersData[uIdx].id;
                                }
                            }

                            // Update the schedule array in the JS cache.
                            // Use serverDays (PHP-authoritative) to update the cache.
                            // serverDays comes from json.savedSchedule (the exact
                            // weekly_schedule PHP wrote to DB), so the cache always
                            // matches the DB — no more stale checkboxes on re-open.
                            emp.schedule = serverDays;

                            // Determine which days actually changed in the DB so we
                            // can detect a DOM/DB mismatch below.
                            var dbDaysChanged = serverDays.some(function(v, i) {
                                return (v === 1 ? 1 : 0) !== (oldSchedule[i] === 1 ? 1 : 0);
                            });

                            // ── Refresh schedule day cells in the home-page grid ──
                            // Walk every cell belonging to this employee.
                            // • Cells with data-ovr="1" and a meaningful override type
                            //   (PTO, sick, holiday, custom_hours) take priority over
                            //   the weekly schedule — skip those.
                            // • Plain 'off' overrides are stale when the weekly schedule
                            //   now includes that day — PHP already deleted them from the
                            //   DB, so we clear the DOM marker too for an immediate
                            //   visual update.
                            // • Explicit 'on' overrides still show the employee as
                            //   working; we skip their state change but DO refresh the
                            //   hours text so it stays current.
                            // If NO cells are found (employee filtered out or on a
                            // different month view), fall back to a page reload so
                            // the server-rendered table reflects the saved schedule.
                            var cellsFound   = 0;
                            var cellsChanged = 0;
                            document.querySelectorAll('td[data-eid="' + empId + '"]').forEach(function (td) {
                                cellsFound++;

                                var dow = parseInt(td.getAttribute('data-dow'), 10);
                                if (isNaN(dow) || dow < 0 || dow > 6) return;

                                var isOverride   = (td.getAttribute('data-ovr') === '1');
                                var overrideType = td.getAttribute('data-ovr-type') || '';

                                // Skip PTO / sick / holiday / custom_hours — intentional,
                                // take priority over schedule changes.
                                // Allow 'off' overrides (stale — PHP deleted them in DB).
                                // Allow 'on' overrides — only refresh hours text, not state.
                                if (isOverride && overrideType !== 'off' && overrideType !== 'on') return;

                                var shouldBeOn    = (serverDays[dow] == 1);
                                var isCurrentlyOn = td.classList.contains('status-on');
                                var st = td.querySelector('.status-text');

                                if (shouldBeOn && !isCurrentlyOn && overrideType !== 'on') {
                                    // OFF → ON (handles both regular and plain-'off' override cells)
                                    td.classList.remove('status-off');
                                    td.classList.add('status-on');
                                    if (st) st.textContent = serverHours;
                                    // Strip the stale override markers from the DOM element
                                    if (isOverride) {
                                        td.removeAttribute('data-ovr');
                                        td.removeAttribute('data-ovr-type');
                                    }
                                    cellsChanged++;
                                } else if (!shouldBeOn && isCurrentlyOn && !isOverride) {
                                    // ON → OFF (regular cells only; never strip intentional overrides)
                                    td.classList.remove('status-on');
                                    td.classList.add('status-off');
                                    if (st) st.textContent = 'OFF';
                                    cellsChanged++;
                                } else if (shouldBeOn) {
                                    // Still ON (regular cell or 'on' override) —
                                    // refresh hours text in case hours also changed.
                                    if (st) st.textContent = serverHours;
                                }
                            });
                            console.log('[EditEmployee] Grid update — empId=' + empId +
                                ' cellsFound=' + cellsFound +
                                ' cellsChanged=' + cellsChanged +
                                ' serverDays=' + JSON.stringify(serverDays) +
                                ' serverHours="' + serverHours + '"' +
                                ' dbDaysChanged=' + dbDaysChanged);

                            // Detect if the shift number changed — the Working Today
                            // tooltip is PHP-rendered HTML and groups employees by shift,
                            // so it must be rebuilt by a full reload when shift changes.
                            var shiftChanged = (oldShift !== parseInt(newShift, 10));

                            // Fallback reload cases:
                            // 1. No cells found at all (employee filtered / different month).
                            // 2. The DB saved a different schedule than the cache had, but
                            //    the DOM loop changed nothing — the grid is out of sync.
                            // 3. The shift changed — Working Today grouping must refresh.
                            if (cellsFound === 0 || (dbDaysChanged && cellsChanged === 0) || shiftChanged) {
                                console.log('[EditEmployee] Reloading schedule tab — cellsFound=' + cellsFound + ' dbDaysChanged=' + dbDaysChanged + ' cellsChanged=' + cellsChanged);
                                setTimeout(function () {
                                    window.location.href = window.location.pathname + '?tab=schedule';
                                }, 1400); // let the success notification be visible first
                            }

                            // ── Update the schedule row header ────────────────────
                            document.querySelectorAll('td.employee-name').forEach(function (td) {
                                if (!td.querySelector('[onclick*="openEditEmployeeInline(' + empId + ')"]')) return;

                                // Name
                                var nameEl = td.querySelector('.employee-name-text');
                                if (nameEl) nameEl.textContent = newName;

                                // Team + Shift line
                                var teamEl = td.querySelector('.team-info');
                                if (teamEl) {
                                    teamEl.textContent = newTeam.toUpperCase() + ' - ' + _shiftName(newShift);
                                }

                                // Level badge — update text + CSS class
                                var lvlEl = td.querySelector('.level-info');
                                if (newLevel) {
                                    if (!lvlEl) {
                                        lvlEl = document.createElement('div');
                                        // Insert after team-info
                                        var teamRef = td.querySelector('.team-info');
                                        if (teamRef && teamRef.nextSibling) {
                                            td.insertBefore(lvlEl, teamRef.nextSibling);
                                        } else {
                                            td.appendChild(lvlEl);
                                        }
                                    }
                                    // Remove all existing level-* classes, keep base class
                                    lvlEl.className = 'level-info ' + _levelClass(newLevel);
                                    lvlEl.textContent = _levelName(newLevel);
                                } else if (lvlEl) {
                                    lvlEl.remove();
                                }

                                // Supervisor line
                                var supInfoEl = td.querySelector('.supervisor-info');
                                if (newSupName) {
                                    if (!supInfoEl) {
                                        supInfoEl = document.createElement('div');
                                        supInfoEl.className = 'supervisor-info';
                                        // Append before skills-info if it exists
                                        var skillsRef = td.querySelector('.skills-info');
                                        if (skillsRef) {
                                            td.insertBefore(supInfoEl, skillsRef);
                                        } else {
                                            td.appendChild(supInfoEl);
                                        }
                                    }
                                    supInfoEl.textContent = '👑 Reports to: ' + newSupName;
                                } else if (supInfoEl) {
                                    supInfoEl.remove();
                                }

                                // Skills badges + Slack link — rebuild the whole div
                                var skillsDiv = td.querySelector('.skills-info');
                                var badgesHTML = _skillBadges(newSkills);
                                var slackHTML  = _slackLink(newSlackId);
                                if (badgesHTML || slackHTML) {
                                    if (!skillsDiv) {
                                        skillsDiv = document.createElement('div');
                                        skillsDiv.className = 'skills-info';
                                        skillsDiv.style.cssText = 'margin-top:5px;line-height:1.2;';
                                        td.appendChild(skillsDiv);
                                    }
                                    skillsDiv.innerHTML = badgesHTML + slackHTML;
                                } else if (skillsDiv) {
                                    skillsDiv.remove();
                                }
                            });
                        }
                    }
                } else {
                    if (typeof showNotification === 'function') {
                        showNotification(json.message || '❌ Save failed', 'error');
                    }
                }
            } catch (err) {
                // Log to console for debugging; show a user-friendly message
                console.error('[EditEmployee AJAX] Save error:', err);
                if (typeof showNotification === 'function') {
                    showNotification('❌ Save failed (' + (err.message || 'network error') + ') — reloading…', 'error');
                }
                // Fall back to a full page reload after a short delay so the user
                // sees the error message, then gets the page in a known-good state
                setTimeout(function () { window.location.reload(); }, 2500);
            } finally {
                if (btn) { btn.disabled = false; btn.textContent = origText; }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachEditEmployeeAjax);
    } else {
        attachEditEmployeeAjax();
    }
}());
