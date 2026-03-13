<?php
// modals.php
// Modal HTML Components for Schedule Management System v3.5 - FIXED VERSION

if (!defined('APP_VERSION')) {
    die('Access denied. This file must be included from the main application.');
}
?>

<!-- Edit Employee Modal - DISABLED (Using inline version in index.php instead) -->
<?php if (false && hasPermission('manage_employees')): ?>
<!--
OLD MODAL COMMENTED OUT TO PREVENT DUPLICATE IDS
The edit employee form is now inline in index.php at the edit-employee-tab
-->
<div id="editEmployeeModal_DISABLED" class="modal" style="display: none !important;">

    <div class="modal-content" style="max-width: 800px; max-height: 90vh; overflow-y: auto;">
        <h2>Edit Employee</h2>
        
        <form method="POST">
            <input type="hidden" name="action" value="edit_employee">
            <input type="hidden" name="empId" id="editEmpId">
            
            <div class="form-group">
                <label>Employee Name:</label>
                <input type="text" name="empName" id="editEmpName" required placeholder="Enter full name">
            </div>
            
            <div class="form-group">
                <label>Team:</label>
                <select name="empTeam" id="editEmpTeam" required>
                    <?php echo generateTeamOptions(); ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Level:</label>
                <select name="empLevel" id="editEmpLevel">
                    <?php echo generateLevelOptions(); ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Shift:</label>
                <select name="empShift" id="editEmpShift" required>
                    <option value="1">1st Shift</option>
                    <option value="2">2nd Shift</option>
                    <option value="3">3rd Shift</option>
                    
                </select>
            </div>
            
            <div class="form-group">
                <label>Default Working Hours:</label>
                <input type="text" name="empHours" id="editEmpHours" required placeholder="e.g., 9-17, 14-22, 8-12&14-18">
                <div style="font-size: 11px; color: #666; margin-top: 3px;">Standard working hours for this employee.</div>
            </div>
            
            <div class="form-group">
                <label>Supervisor/Manager:</label>
                <select name="empSupervisor" id="editEmpSupervisor">
                    <?php echo generateSupervisorOptions(); ?>
                </select>
            </div>
                         <div class="form-group">
    <label>Skills & Specializations:</label>
    <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; border: 1px solid #dee2e6;">
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0;">
                <input type="checkbox" name="skillMH" id="editSkillMH" style="transform: scale(1.2);">
                <span style="background: #e3f2fd; color: #1976d2; padding: 4px 8px; border-radius: 12px; font-weight: bold; font-size: 0.9em;">MH</span>
                <span>Managed Hosting</span>
            </label>
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0;">
                <input type="checkbox" name="skillMA" id="editSkillMA" style="transform: scale(1.2);">
                <span style="background: #f3e5f5; color: #7b1fa2; padding: 4px 8px; border-radius: 12px; font-weight: bold; font-size: 0.9em;">MA</span>
                <span>Managed Apps</span>
            </label>
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0;">
                <input type="checkbox" name="skillWin" id="editSkillWin" style="transform: scale(1.2);">
                <span style="background: #e8f5e8; color: #388e3c; padding: 4px 8px; border-radius: 12px; font-weight: bold; font-size: 0.9em;">Win</span>
                <span>Windows</span>
            </label>
        </div>
        <div style="font-size: 11px; color: #666; margin-top: 8px;">
            Select skills and specializations for this employee
        </div>
    </div>
</div>
            <!-- Regular Schedule Section -->
            <div id="editRegularScheduleSection">
                <div class="form-group">
                    <label>Weekly Schedule:</label>
                    
                    <!-- Template Selection Section for Edit -->
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
                        <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">
                            <select id="editTemplateSelect" onchange="ScheduleApp.applyEditTemplate()" style="flex: 1;">
                                <option value="">Apply template...</option>
                                <?php foreach ($scheduleTemplates as $template): ?>
                                <option value="<?php echo $template['id']; ?>" 
                                        data-schedule="<?php echo implode(',', $template['schedule']); ?>"
                                        title="<?php echo escapeHtml($template['description']); ?>">
                                    <?php echo escapeHtml($template['name']); ?> 
                                    (<?php echo formatScheduleDisplay($template['schedule']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" onclick="ScheduleApp.clearEditSchedule()" style="background: #6c757d; color: white; border: none; padding: 5px 10px; border-radius: 3px; font-size: 12px;">Clear</button>
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
                        
                        <div><input type="checkbox" name="day0" id="editDay0"></div>
                        <div><input type="checkbox" name="day1" id="editDay1"></div>
                        <div><input type="checkbox" name="day2" id="editDay2"></div>
                        <div><input type="checkbox" name="day3" id="editDay3"></div>
                        <div><input type="checkbox" name="day4" id="editDay4"></div>
                        <div><input type="checkbox" name="day5" id="editDay5"></div>
                        <div><input type="checkbox" name="day6" id="editDay6"></div>
                    </div>
                    
                    <div class="template-btns">
                        Quick Templates: 
                        <button type="button" onclick="ScheduleApp.setEditTemplate('weekdays')">Mon-Fri</button>
                        <button type="button" onclick="ScheduleApp.setEditTemplate('weekend')">Weekends</button>
                        <button type="button" onclick="ScheduleApp.setEditTemplate('all')">All Days</button>
                    </div>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 20px;">
                <button type="submit">Update Employee</button>
                <button type="button" onclick="ScheduleApp.closeEditEmployeeModal()" style="background: #95a5a6; margin-left: 10px;">Cancel</button>
            </div>
        </form>
    </div>
</div>
-->
<?php endif; ?>

<!-- Bulk Schedule Changes Modal - DISABLED (Using inline version in index.php instead) -->
<?php if (false && hasPermission('edit_schedule')): ?>
<!--
OLD MODAL COMMENTED OUT TO PREVENT DUPLICATE IDS
The bulk schedule form is now inline in index.php at the bulk-schedule-tab
This modal is kept here for reference but disabled
-->
<div id="bulkModal_DISABLED" class="modal" style="display: none !important;">

    <div class="modal-content" style="max-width: 700px;">
        <h2>Bulk Schedule Changes</h2>
        
        <form method="POST">
            <input type="hidden" name="action" value="bulk_schedule_change">
            
            <div class="form-group">
                <label>Date Range (Any Month/Year):</label>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <div>
                        <label style="font-size: 12px;">Start Date:</label>
                        <input type="date" name="startDate" id="bulkStartDate" required>
                    </div>
                    <div>
                        <label style="font-size: 12px;">End Date:</label>
                        <input type="date" name="endDate" id="bulkEndDate" required>
                    </div>
                </div>
                <div style="font-size: 11px; color: #666; margin-top: 5px;">
                    Select any date range - can span multiple months and years
                </div>
            </div>
            
            <!-- FIXED: Skip Days Off Checkbox with proper styling and functionality -->
            <div style="margin-top: 15px; padding: 10px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: normal; cursor: pointer;">
                    <input type="checkbox" name="skipDaysOff" id="skipDaysOff" onchange="ScheduleApp.validateBulkForm(); ScheduleApp.updateSkipDaysOffInfo();" style="margin: 0;">
                    <span>🚫 Skip days off (only apply to scheduled working days)</span>
                </label>
                <div style="font-size: 11px; color: #666; margin-top: 5px; margin-left: 20px;">
                    When checked, changes will only be applied to days when employees are normally scheduled to work according to their weekly schedule.
                </div>
                <div id="skipDaysOffInfo" style="font-size: 11px; color: #007bff; margin-top: 8px; margin-left: 20px; font-weight: bold; display: none;">
                    ✅ Will skip non-working days and only modify scheduled work days
                </div>
            </div>
            
            <!-- NEW: Simple Set New Schedule -->
            <div style="margin-top: 15px; padding: 10px; background: #e8f4f8; border: 1px solid #17a2b8; border-radius: 4px;">
                <label style="font-size: 14px; font-weight: bold; display: block; margin-bottom: 10px;">
                    📅 Set New Weekly Schedule (Optional)
                </label>
                <div style="font-size: 11px; color: #666; margin-bottom: 10px;">
                    Define which days are working days. Changes will only apply to checked days. Leave all unchecked to apply to all days.
                </div>
                
                <div class="schedule-grid" style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; text-align: center;">
                    <div style="font-weight: bold; font-size: 12px;">Sun</div>
                    <div style="font-weight: bold; font-size: 12px;">Mon</div>
                    <div style="font-weight: bold; font-size: 12px;">Tue</div>
                    <div style="font-weight: bold; font-size: 12px;">Wed</div>
                    <div style="font-weight: bold; font-size: 12px;">Thu</div>
                    <div style="font-weight: bold; font-size: 12px;">Fri</div>
                    <div style="font-weight: bold; font-size: 12px;">Sat</div>
                    
                    <div><input type="checkbox" name="newSchedule[]" value="0" style="margin: 0;"></div>
                    <div><input type="checkbox" name="newSchedule[]" value="1" style="margin: 0;"></div>
                    <div><input type="checkbox" name="newSchedule[]" value="2" style="margin: 0;"></div>
                    <div><input type="checkbox" name="newSchedule[]" value="3" style="margin: 0;"></div>
                    <div><input type="checkbox" name="newSchedule[]" value="4" style="margin: 0;"></div>
                    <div><input type="checkbox" name="newSchedule[]" value="5" style="margin: 0;"></div>
                    <div><input type="checkbox" name="newSchedule[]" value="6" style="margin: 0;"></div>
                </div>
                
                <div style="margin-top: 10px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" onclick="ScheduleApp.setBulkSchedule('weekdays')" style="padding: 5px 10px; font-size: 11px; background: #17a2b8; color: white; border: none; border-radius: 3px; cursor: pointer;">Mon-Fri</button>
                    <button type="button" onclick="ScheduleApp.setBulkSchedule('weekends')" style="padding: 5px 10px; font-size: 11px; background: #17a2b8; color: white; border: none; border-radius: 3px; cursor: pointer;">Weekends</button>
                    <button type="button" onclick="ScheduleApp.setBulkSchedule('all')" style="padding: 5px 10px; font-size: 11px; background: #28a745; color: white; border: none; border-radius: 3px; cursor: pointer;">All Days</button>
                    <button type="button" onclick="ScheduleApp.setBulkSchedule('clear')" style="padding: 5px 10px; font-size: 11px; background: #dc3545; color: white; border: none; border-radius: 3px; cursor: pointer;">Clear</button>
                </div>
            </div>
            
            <div class="form-group">
                <label>Select Employees:</label>
                <div style="margin-bottom: 10px;">
                    <label>
                        <input type="checkbox" id="selectAllEmployees" onchange="ScheduleApp.toggleAllEmployees()"> 
                        <strong>Select All Employees</strong>
                    </label>
                </div>
                <div class="bulk-employee-list" style="max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 4px; padding: 10px; background: #f9f9f9;">
                    <?php foreach ($filteredEmployees as $employee): ?>
                    <div class="bulk-employee-item" style="margin-bottom: 8px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 4px; border-radius: 3px; transition: background 0.2s;" onmouseover="this.style.background='#e9ecef'" onmouseout="this.style.background='transparent'">
                            <input type="checkbox" name="selectedEmployees[]" value="<?php echo $employee['id']; ?>" class="employee-checkbox" onchange="ScheduleApp.validateBulkForm()" style="margin: 0;">
                            <div style="font-size: 13px;">
                                <div style="font-weight: bold;"><?php echo escapeHtml($employee['name']); ?></div>
                                <div style="font-size: 11px; color: #666;">
                                    <?php echo strtoupper($employee['team']); ?> - <?php echo getShiftName($employee['shift']); ?>
                                    <?php if (!empty($employee['level'])): ?>
                                    - <?php echo getLevelName($employee['level']); ?>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 10px; color: #999;">
                                    Schedule: <?php echo formatScheduleDisplay($employee['schedule']); ?>
                                </div>
                            </div>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="form-group">
                <label>Status to Apply:</label>
                <select name="bulkStatus" id="bulkStatusSelect" required onchange="ScheduleApp.handleBulkStatusChange()">
                    <option value="">Select status...</option>
                    <option value="on">✅ Override: Working</option>
                    <option value="off">❌ Override: Day Off</option>
                    <option value="pto">🏖️ PTO</option>
                    <option value="sick">🤒 Sick</option>
                    <option value="holiday">🎄 Holiday</option>
                    <option value="custom_hours">⏰ Custom Hours</option>
                    <option value="schedule">🔄 Reset to Default Schedule</option>
                </select>
            </div>
            
            <!-- NEW: Shift Change Option -->
            <div style="margin-top: 15px; padding: 10px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: bold; cursor: pointer;">
                    <input type="checkbox" name="changeShift" id="changeShiftCheckbox" 
                           onchange="if(typeof ScheduleApp !== 'undefined' && ScheduleApp.toggleShiftChange) { ScheduleApp.toggleShiftChange(); } else { toggleShiftChangeStandalone(); }" 
                           style="margin: 0;">
                    <span>🔄 Also Change Shift Assignment</span>
                </label>
                <div style="font-size: 11px; color: #666; margin-top: 5px; margin-left: 20px;">
                    Change shift assignment effective on the start date of this bulk change
                </div>
                
                <div id="shiftChangeDiv" style="display: none; margin-top: 10px; margin-left: 20px;">
                    <label style="font-size: 13px; font-weight: bold; display: block; margin-bottom: 5px;">New Shift:</label>
                    <select name="newShift" id="newShift" style="width: 100%; padding: 8px; border: 1px solid #ced4da; border-radius: 4px;">
                        <option value="">Select new shift...</option>
                        <option value="1">1st Shift</option>
                        <option value="2">2nd Shift</option>
                        <option value="3">3rd Shift</option>
                        
                    </select>
                    
                    <div style="margin-top: 10px; display: flex; gap: 10px; align-items: center;">
                        <label style="display: flex; align-items: center; gap: 5px; font-size: 12px; cursor: pointer;">
                            <input type="radio" name="shiftChangeWhen" value="now" checked style="margin: 0;">
                            <span>Change Now (Immediate)</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 5px; font-size: 12px; cursor: pointer;">
                            <input type="radio" name="shiftChangeWhen" value="start_date" style="margin: 0;">
                            <span>Change on Start Date</span>
                        </label>
                    </div>
                    
                    <div id="shiftEffectiveDate" style="margin-top: 8px; padding: 8px; background: #e7f3ff; border-radius: 3px; font-size: 11px; color: #0056b3;">
                        <strong>Note:</strong> Shift will change immediately when you submit this form.
                    </div>
                </div>
            </div>
            
            <script>
            // Standalone toggle function that doesn't require ScheduleApp
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
            
            // Update effective date message
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
            });
            </script>
            
            <div id="bulkCustomHoursDiv" style="display: none;" class="form-group">
                <label>Custom Hours:</label>
                <input type="text" name="bulkCustomHours" id="bulkCustomHours" placeholder="e.g., 9-13, 8-12&14-18">
                <div style="font-size: 11px; color: #666; margin-top: 3px;">
                    Examples: Half day (9-13), Split shift (8-12&14-18), Different hours (10-18)
                </div>
            </div>
            
            <div class="form-group">
                <label>Comment (Optional):</label>
                <input type="text" name="bulkComment" id="bulkComment" placeholder="Optional comment for this change">
            </div>
            
            <div class="bulk-warning" style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; padding: 10px; margin: 15px 0; color: #856404; font-size: 13px;">
                ⚠️ <strong>Warning:</strong> This will apply the selected status to ALL selected employees for the entire date range. 
                This action will override any existing schedule changes for those dates.
                <div id="bulkImpactSummary" style="margin-top: 8px; font-weight: bold; color: #dc3545;"></div>
            </div>
            
            <div style="text-align: center; margin-top: 20px;">
                <button type="submit" id="bulkSubmitBtn" disabled style="padding: 12px 24px; font-size: 14px; font-weight: bold;">Apply Bulk Changes</button>
                <button type="button" onclick="ScheduleApp.closeBulkModal()" style="background: #95a5a6; margin-left: 10px; padding: 12px 24px;">Cancel</button>
            </div>
        </form>
    </div>
</div>
-->
<?php endif; ?>

<!-- Edit Cell Modal -->
<?php if (hasPermission('edit_schedule') || hasPermission('edit_own_schedule')): ?>
<div id="editModal" class="edit-modal">
    <div class="edit-modal-content">
        <h3 id="editTitle">Edit Schedule</h3>
        <p id="editInfo">Select status and add optional comment:</p>
        
        <form method="POST" id="editCellForm">
            <input type="hidden" name="action" value="edit_cell">
            <input type="hidden" name="employeeId" id="editEmployeeId">
            <input type="hidden" name="day" id="editDay">
            <input type="hidden" name="year" id="editYear" value="<?php echo $currentYear; ?>">
            <input type="hidden" name="month" id="editMonth" value="<?php echo $currentMonth; ?>">
            <input type="hidden" name="status" id="editStatus">
            
            <div class="status-buttons">
                <button type="button" class="status-btn status-on" onclick="ScheduleApp.selectStatus('on')">Override: Working</button>
                <button type="button" class="status-btn status-off" onclick="ScheduleApp.selectStatus('off')">Override: Day Off</button>
                <button type="button" class="status-btn status-pto" onclick="ScheduleApp.selectStatus('pto')">PTO</button>
                <button type="button" class="status-btn status-sick" onclick="ScheduleApp.selectStatus('sick')">Sick</button>
                <button type="button" class="status-btn status-holiday" onclick="ScheduleApp.selectStatus('holiday')">Holiday</button>
                <button type="button" class="status-btn status-custom_hours" onclick="ScheduleApp.selectStatus('custom_hours')">Custom Hours</button>
                <button type="button" class="status-btn status-schedule" onclick="ScheduleApp.selectStatus('schedule')">Reset to Schedule</button>
            </div>
            
            <div id="customHoursDiv" class="custom-hours-input">
                <label for="editCustomHours">Custom Working Hours:</label>
                <input type="text" name="customHours" id="editCustomHours" placeholder="e.g., 9-13, 8-12&14-18, 10-14">
                <div style="font-size: 11px; color: #666; margin-top: 5px;">
                    💡 Examples: Half day (9-13), Split shift (8-12&14-18), Different hours (10-18)
                </div>
            </div>
            
            <div class="form-group" style="margin-top: 15px;">
                <label for="editComment">Comment (Optional):</label>
                <input type="text" name="comment" id="editComment" placeholder="Optional comment about this change">
            </div>
            
            <div style="text-align: center; margin-top: 20px;">
                <button type="submit" id="saveBtn" style="display: none;">Save Changes</button>
                <button type="button" onclick="ScheduleApp.closeEditModal()" style="background: #95a5a6;">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- User Management Modals -->
<?php echo getUserManagementModalsHTML(); ?>

<!-- Profile Modal -->
<?php echo getProfileModalHTML(); ?>

<!-- Template Delete Form (Hidden) -->
<form id="deleteTemplateForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete_template">
    <input type="hidden" name="templateId" id="deleteTemplateId">
</form>

<!-- Template Create Form (Hidden) -->
<form id="createTemplateForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="create_template">
    <input type="hidden" name="templateName" id="createTemplateName">
    <input type="hidden" name="templateDescription" id="createTemplateDescription">
    <!-- Template days will be added dynamically -->
</form>

<script>
// Additional modal-specific JavaScript functions - ENHANCED VERSION

// Initialize ScheduleApp if it doesn't exist
if (typeof ScheduleApp === 'undefined') {
    window.ScheduleApp = {};
}

// Template management functions
ScheduleApp.deleteTemplate = function(templateId, templateName) {
    if (confirm(`Delete template "${templateName}"? This action cannot be undone.`)) {
        document.getElementById('deleteTemplateId').value = templateId;
        document.getElementById('deleteTemplateForm').submit();
    }
};

ScheduleApp.saveCurrentAsTemplate = function() {
    const nameInput = document.getElementById('newTemplateName');
    const descInput = document.getElementById('newTemplateDescription');
    
    if (!nameInput || !descInput) return;
    
    const name = nameInput.value.trim();
    const description = descInput.value.trim();
    
    if (!name || !description) {
        alert('Please enter both template name and description.');
        return;
    }
    
    // Get current schedule
    const schedule = [];
    for (let i = 0; i < 7; i++) {
        const checkbox = document.getElementById('day' + i);
        schedule.push(checkbox && checkbox.checked ? 1 : 0);
    }
    
    // Set form values
    document.getElementById('createTemplateName').value = name;
    document.getElementById('createTemplateDescription').value = description;
    
    // Clear existing template day inputs
    const form = document.getElementById('createTemplateForm');
    const existingInputs = form.querySelectorAll('input[name^="templateDay"]');
    existingInputs.forEach(input => input.remove());
    
    // Add template day inputs
    schedule.forEach((day, index) => {
        if (day) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `templateDay${index}`;
            input.value = '1';
            form.appendChild(input);
        }
    });
    
    // Submit form
    form.submit();
};

// Employee modal management
ScheduleApp.closeEditEmployeeModal = function() {
    const modal = document.getElementById('editEmployeeModal');
    if (modal) {
        modal.classList.remove('show');
    }
};

// FIXED: Enhanced bulk modal management with skip days off functionality
ScheduleApp.closeBulkModal = function() {
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
    
    const skipDaysOff = document.getElementById('skipDaysOff');
    if (skipDaysOff) skipDaysOff.checked = false;
    
    const customHoursDiv = document.getElementById('bulkCustomHoursDiv');
    if (customHoursDiv) customHoursDiv.style.display = 'none';
    
    const skipInfo = document.getElementById('skipDaysOffInfo');
    if (skipInfo) skipInfo.style.display = 'none';
    
    // Uncheck all employee checkboxes
    const checkboxes = document.querySelectorAll('.employee-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
    
    ScheduleApp.validateBulkForm();
};

ScheduleApp.toggleAllEmployees = function() {
    const selectAll = document.getElementById('selectAllEmployees');
    const checkboxes = document.querySelectorAll('.employee-checkbox');
    
    if (selectAll) {
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
        });
    }
    
    ScheduleApp.validateBulkForm();
};

ScheduleApp.handleBulkStatusChange = function() {
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
    
    ScheduleApp.validateBulkForm();
};

// FIXED: New function to update skip days off info
ScheduleApp.updateSkipDaysOffInfo = function() {
    const skipDaysOff = document.getElementById('skipDaysOff');
    const skipInfo = document.getElementById('skipDaysOffInfo');
    
    if (skipDaysOff && skipInfo) {
        skipInfo.style.display = skipDaysOff.checked ? 'block' : 'none';
    }
};

// FIXED: Enhanced validation function with proper skip days off handling
ScheduleApp.validateBulkForm = function() {
    const startDate = document.getElementById('bulkStartDate');
    const endDate = document.getElementById('bulkEndDate');
    const status = document.getElementById('bulkStatusSelect');
    const selectedEmployees = document.querySelectorAll('.employee-checkbox:checked');
    const submitBtn = document.getElementById('bulkSubmitBtn');
    const skipDaysOff = document.getElementById('skipDaysOff');
    const impactSummary = document.getElementById('bulkImpactSummary');
    
    if (!startDate || !endDate || !status || !submitBtn) return;
    
    let isValid = false;
    let dayCount = 0;
    
    if (startDate.value && endDate.value && status.value && selectedEmployees.length > 0) {
        const start = new Date(startDate.value);
        const end = new Date(endDate.value);
        
        if (start <= end) {
            isValid = true;
            dayCount = Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1;
            
            // Warn for very large ranges
            if (dayCount > 365) {
                const confirmMsg = `You've selected ${dayCount} days (over a year). This may take considerable time to process. Continue?`;
                if (!confirm(confirmMsg)) {
                    isValid = false;
                }
            }
        }
    }
    
    submitBtn.disabled = !isValid;
    
    if (isValid) {
        const employeeCount = selectedEmployees.length;
        const totalPotentialChanges = employeeCount * dayCount;
        const startStr = new Date(startDate.value).toLocaleDateString();
        const endStr = new Date(endDate.value).toLocaleDateString();
        const skipText = skipDaysOff && skipDaysOff.checked ? ' (skipping non-working days)' : '';
        
        // Update submit button text
        submitBtn.textContent = `Apply Changes to ${employeeCount} employees × ${dayCount} days${skipText}`;
        
        // Update impact summary
        if (impactSummary) {
            let impactText = `Up to ${totalPotentialChanges.toLocaleString()} total changes possible`;
            if (skipDaysOff && skipDaysOff.checked) {
                impactText += ` (actual changes will be fewer due to skipping non-working days)`;
            }
            impactSummary.textContent = impactText;
        }
    } else {
        submitBtn.textContent = 'Apply Bulk Changes';
        if (impactSummary) {
            impactSummary.textContent = '';
        }
    }
};

// Edit cell modal functions
ScheduleApp.selectStatus = function(status) {
    const statusField = document.getElementById('editStatus');
    const saveBtn = document.getElementById('saveBtn');
    
    if (statusField) statusField.value = status;
    if (saveBtn) saveBtn.style.display = 'inline-block';
    
    // Highlight selected button
    const buttons = document.querySelectorAll('.status-btn');
    buttons.forEach(btn => btn.style.border = '2px solid transparent');
    if (event && event.target) {
        event.target.style.border = '2px solid #333';
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
};

ScheduleApp.closeEditModal = function() {
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
    
    // Reset button borders
    const buttons = document.querySelectorAll('.status-btn');
    buttons.forEach(btn => btn.style.border = '2px solid transparent');
    
    // Reset the working button text
    const workingBtn = document.querySelector('.status-btn.status-on');
    if (workingBtn) workingBtn.textContent = 'Override: Working';
};

/*
 * BULK MODAL FUNCTIONS DISABLED - Using inline bulk schedule form instead
 * These functions are kept for reference but commented out to avoid conflicts
 *
// FIXED: Enhanced bulk modal opening with better date defaults and validation
ScheduleApp.openBulkModal = function() {
    if (!CONFIG.hasEditSchedule) return;
    
    // Hide any employee hover cards
    if (typeof forceHideEmployeeCard === 'function') {
        forceHideEmployeeCard();
    }
    
    const modal = document.getElementById('bulkModal');
    if (modal) {
        modal.classList.add('show');
        
        // Set smart default dates
        const today = new Date();
        const currentYear = today.getFullYear();
        const currentMonth = today.getMonth();
        
        // Default to current month range
        const firstDay = new Date(currentYear, currentMonth, 1);
        const lastDay = new Date(currentYear, currentMonth + 1, 0);
        
        const startDateInput = document.getElementById('bulkStartDate');
        const endDateInput = document.getElementById('bulkEndDate');
        
        if (startDateInput && endDateInput) {
            if (!startDateInput.value) {
                startDateInput.value = firstDay.toISOString().split('T')[0];
            }
            if (!endDateInput.value) {
                endDateInput.value = lastDay.toISOString().split('T')[0];
            }
        }
        
        // Reset skip days off option
        const skipDaysOff = document.getElementById('skipDaysOff');
        if (skipDaysOff) {
            skipDaysOff.checked = false;
            ScheduleApp.updateSkipDaysOffInfo();
        }
        
        // Clear new schedule checkboxes
        const scheduleCheckboxes = document.querySelectorAll('input[name="newSchedule[]"]');
        scheduleCheckboxes.forEach(cb => cb.checked = false);
        
        // Reset shift change controls
        const changeShiftCheckbox = document.getElementById('changeShiftCheckbox');
        const shiftDiv = document.getElementById('shiftChangeDiv');
        const newShiftSelect = document.getElementById('newShift');
        if (changeShiftCheckbox) changeShiftCheckbox.checked = false;
        if (shiftDiv) shiftDiv.style.display = 'none';
        if (newShiftSelect) {
            newShiftSelect.value = '';
            newShiftSelect.required = false;
        }
        
        // Focus on first field
        if (startDateInput) {
            setTimeout(() => startDateInput.focus(), 100);
        }
        
        ScheduleApp.validateBulkForm(); // Check initial state
    }
};

// NEW: Helper function to set schedule pattern
ScheduleApp.setBulkSchedule = function(type) {
    const checkboxes = document.querySelectorAll('input[name="newSchedule[]"]');
    
    if (type === 'weekdays') {
        // Check Monday (1) through Friday (5)
        checkboxes.forEach(cb => {
            const day = parseInt(cb.value);
            cb.checked = (day >= 1 && day <= 5);
        });
    } else if (type === 'weekends') {
        // Check Sunday (0) and Saturday (6)
        checkboxes.forEach(cb => {
            const day = parseInt(cb.value);
            cb.checked = (day === 0 || day === 6);
        });
    } else if (type === 'all') {
        // Check all days
        checkboxes.forEach(cb => cb.checked = true);
    } else if (type === 'clear') {
        // Clear all
        checkboxes.forEach(cb => cb.checked = false);
    }
};

// NEW: Toggle shift change section
ScheduleApp.toggleShiftChange = function() {
    console.log('toggleShiftChange called');
    const checkbox = document.getElementById('changeShiftCheckbox');
    const shiftDiv = document.getElementById('shiftChangeDiv');
    const shiftSelect = document.getElementById('newShift');
    
    console.log('Checkbox:', checkbox, 'Checked:', checkbox ? checkbox.checked : 'not found');
    console.log('ShiftDiv:', shiftDiv);
    
    if (checkbox && shiftDiv) {
        if (checkbox.checked) {
            shiftDiv.style.display = 'block';
            console.log('Showing shift div');
            if (shiftSelect) shiftSelect.required = true;
        } else {
            shiftDiv.style.display = 'none';
            console.log('Hiding shift div');
            if (shiftSelect) {
                shiftSelect.required = false;
                shiftSelect.value = '';
            }
        }
    } else {
        console.error('Could not find checkbox or shiftDiv', {checkbox, shiftDiv});
    }
};

// Enhanced event listeners for better UX
document.addEventListener('DOMContentLoaded', function() {
    // Add event listeners for bulk form inputs
    const startDate = document.getElementById('bulkStartDate');
    const endDate = document.getElementById('bulkEndDate');
    const skipDaysOff = document.getElementById('skipDaysOff');
    
    if (startDate) {
        startDate.addEventListener('change', ScheduleApp.validateBulkForm);
    }
    
    if (endDate) {
        endDate.addEventListener('change', ScheduleApp.validateBulkForm);
    }
    
    if (skipDaysOff) {
        skipDaysOff.addEventListener('change', function() {
            ScheduleApp.updateSkipDaysOffInfo();
            ScheduleApp.validateBulkForm();
        });
    }
    
    console.log('🔧 Enhanced modal components with skip days off functionality loaded successfully!');
});

// Fallback for immediate execution
if (document.readyState !== 'loading') {
    const startDate = document.getElementById('bulkStartDate');
    const endDate = document.getElementById('bulkEndDate');
    const skipDaysOff = document.getElementById('skipDaysOff');
    
    if (startDate) {
        startDate.addEventListener('change', ScheduleApp.validateBulkForm);
    }
    
    if (endDate) {
        endDate.addEventListener('change', ScheduleApp.validateBulkForm);
    }
    
    if (skipDaysOff) {
        skipDaysOff.addEventListener('change', function() {
            ScheduleApp.updateSkipDaysOffInfo();
            ScheduleApp.validateBulkForm();
        });
    }
    
    console.log('🔧 Enhanced modal components loaded successfully (immediate execution)!');
}
*/
// END OF COMMENTED OUT BULK MODAL FUNCTIONS

// ─── AJAX Override Save ───────────────────────────────────────────────────────
// Intercepts the edit_cell form submit and saves via api.php (single-row
// UPDATE/INSERT) instead of a full-page POST + saveData() (which rewrites all
// overrides). On success, the affected cell is updated in the DOM directly so
// the page never needs to reload.

(function () {
    const STATUS_TEXT = {
        on:           'ON',
        off:          'OFF',
        pto:          'PTO',
        sick:         'SICK',
        holiday:      'HOL',
        custom_hours: 'CUSTOM',
        schedule:     'DEFAULT'
    };

    const STATUS_CLASSES = ['status-on','status-off','status-pto','status-sick',
                             'status-holiday','status-custom_hours','status-schedule'];

    function showToast(msg, type) {
        // Re-use existing flash-message element if present, otherwise create one
        let el = document.getElementById('ajaxToast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'ajaxToast';
            el.style.cssText = 'position:fixed;top:18px;left:50%;transform:translateX(-50%);' +
                'z-index:99999;padding:10px 22px;border-radius:6px;font-weight:bold;' +
                'font-size:14px;box-shadow:0 3px 10px rgba(0,0,0,.25);transition:opacity .4s;';
            document.body.appendChild(el);
        }
        el.textContent = msg;
        el.style.background = type === 'error' ? '#dc3545' : '#155724';
        el.style.color = '#fff';
        el.style.opacity = '1';
        clearTimeout(el._t);
        el._t = setTimeout(() => { el.style.opacity = '0'; }, 3200);
    }

    function updateCell(td, status, customHours, employeeHours, comment) {
        // Update status CSS class
        STATUS_CLASSES.forEach(c => td.classList.remove(c));
        if (status === 'schedule') {
            td.classList.remove('has-comment');
            td.removeAttribute('data-ovr');
            td.removeAttribute('data-comment');
            td.removeAttribute('data-ch');
            td.removeAttribute('title');
            // Restore default status class based on whether this employee is scheduled
            const dow = parseInt(td.dataset.dow, 10);
            const empId = parseInt(td.dataset.eid, 10);
            const emp = window.employeesData ? window.employeesData.find(e => e.id === empId) : null;
            const isScheduled = emp && emp.schedule ? emp.schedule[dow] == 1 : false;
            td.classList.add(isScheduled ? 'status-on' : 'status-off');
            const ct = td.querySelector('.status-text');
            if (ct) ct.textContent = isScheduled ? (emp.hours || 'ON') : 'OFF';
            return;
        }

        td.classList.add('status-' + status);
        td.dataset.ovr = '1';

        let displayText;
        if (status === 'custom_hours') {
            displayText = customHours || employeeHours || 'CUSTOM';
            td.dataset.ch = customHours || '';
        } else if (status === 'on') {
            displayText = employeeHours || 'ON';
            td.removeAttribute('data-ch');
        } else {
            displayText = STATUS_TEXT[status] || status.toUpperCase();
            td.removeAttribute('data-ch');
        }

        const ct = td.querySelector('.status-text');
        if (ct) ct.textContent = displayText;

        // Comment / tooltip — only for genuine comments (not hours strings)
        const isHoursStatus = (status === 'custom_hours' || status === 'on');
        if (comment && !isHoursStatus) {
            td.dataset.comment = comment;
            td.setAttribute('title', comment);
            td.classList.add('has-comment');
        } else {
            td.removeAttribute('data-comment');
            td.removeAttribute('title');
            td.classList.remove('has-comment');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('editCellForm');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            const empId      = document.getElementById('editEmployeeId').value;
            const day        = document.getElementById('editDay').value;
            const year       = document.getElementById('editYear').value;
            const month0     = parseInt(document.getElementById('editMonth').value, 10); // 0-indexed
            const status     = document.getElementById('editStatus').value;
            const customHours = (document.getElementById('editCustomHours').value || '').trim();
            const comment    = (document.getElementById('editComment').value || '').trim();

            if (!status) { showToast('Please select a status first.', 'error'); return; }

            const calMonth = month0 + 1; // convert to 1-indexed for date string
            const dateStr  = year + '-' + String(calMonth).padStart(2,'0') + '-' + String(day).padStart(2,'0');

            // Find the cell in the DOM (data-eid + data-day match)
            const td = document.querySelector(
                `.schedule-table td[data-eid="${empId}"][data-day="${day}"]`
            );

            const saveBtn = document.getElementById('saveBtn');
            if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }

            const emp = window.employeesData ? window.employeesData.find(e => e.id == empId) : null;
            const employeeHours = emp ? (emp.hours || '') : '';
            const employeeName  = emp ? (emp.name  || 'Employee') : 'Employee';

            // ── Reset to schedule → DELETE the override ───────────────────────
            if (status === 'schedule') {
                fetch('api.php?action=delete_override&employee_id=' + encodeURIComponent(empId) +
                      '&date=' + encodeURIComponent(dateStr), { method: 'DELETE' })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            if (td) updateCell(td, 'schedule', '', employeeHours, '');
                            if (typeof ScheduleApp.closeEditModal === 'function') ScheduleApp.closeEditModal();
                            showToast('✅ Reset ' + employeeName + ' to default schedule', 'success');
                        } else {
                            showToast('⛔ ' + (data.error || 'Failed to reset'), 'error');
                        }
                    })
                    .catch(() => showToast('⛔ Network error — please try again', 'error'))
                    .finally(() => {
                        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save Changes'; }
                    });
                return;
            }

            // ── Save override → POST to api.php save_override ────────────────
            const payload = {
                employee_id:  parseInt(empId, 10),
                date:         dateStr,
                type:         status,
                custom_hours: status === 'custom_hours' ? customHours : null,
                notes:        (status !== 'custom_hours' && status !== 'on') ? (comment || null) : null
            };

            fetch('api.php?action=save_override', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (td) updateCell(td, status, customHours, employeeHours, comment);
                    if (typeof ScheduleApp.closeEditModal === 'function') ScheduleApp.closeEditModal();
                    let label = status === 'custom_hours' ? 'Custom Hours (' + customHours + ')' :
                                (STATUS_TEXT[status] || status);
                    showToast('✅ ' + employeeName + ': ' + label + (comment ? ' — ' + comment : ''), 'success');
                } else {
                    showToast('⛔ ' + (data.error || 'Failed to save'), 'error');
                }
            })
            .catch(() => showToast('⛔ Network error — please try again', 'error'))
            .finally(() => {
                if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save Changes'; }
            });
        });
    });
}());
</script>