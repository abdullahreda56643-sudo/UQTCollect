/**
 * نظام إسناد المتدربين - Trainee Assignment System
 * يوفر هذا الملف وظائف إدارة إسناد المتدربين للموظفين
 */

document.addEventListener('DOMContentLoaded', function() {
    // عناصر DOM
    const toggleAssignmentBtn = document.getElementById('toggleAssignment');
    const assignmentSection = document.getElementById('assignmentSection');
    const checkboxColumns = document.querySelectorAll('.checkbox-column');
    const traineeCheckboxes = document.querySelectorAll('.trainee-checkbox');
    const selectAllBtn = document.getElementById('selectAllTrainees');
    const deselectAllBtn = document.getElementById('deselectAllTrainees');
    const selectedCountSpan = document.getElementById('selectedCount').querySelector('span');
    const confirmAssignmentBtn = document.getElementById('confirmAssignment');
    const cancelAssignmentBtn = document.getElementById('cancelAssignment');
    const assignedToSelect = document.getElementById('assignedTo');
    const assignmentForm = document.getElementById('assignmentForm');
    
    // متغيرات النظام
    let isAssignmentMode = false;
    let selectedTrainees = 0;
    
    // تهيئة النظام
    initAssignmentSystem();
    
    /**
     * تهيئة نظام الإسناد
     */
    function initAssignmentSystem() {
        // إخفاء قسم الإسناد في البداية
        if (assignmentSection) {
            assignmentSection.classList.remove('active');
        }
        
        // إخفاء أعمدة الاختيار في البداية
        checkboxColumns.forEach(col => {
            col.style.display = 'none';
        });
        
        // تفعيل الأحداث
        setupEventListeners();
        
        // تحديث عداد المتدربين المحددين
        updateSelectedCount();
    }
    
    /**
     * إعداد مستمعي الأحداث
     */
    function setupEventListeners() {
        // زر تبديل وضع الإسناد
        if (toggleAssignmentBtn) {
            toggleAssignmentBtn.addEventListener('click', toggleAssignmentMode);
        }
        
        // زر تحديد الكل
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', selectAllTrainees);
        }
        
        // زر إلغاء تحديد الكل
        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', deselectAllTrainees);
        }
        
        // زر تأكيد الإسناد
        if (confirmAssignmentBtn) {
            confirmAssignmentBtn.addEventListener('click', confirmAssignment);
        }
        
        // زر إلغاء الإسناد
        if (cancelAssignmentBtn) {
            cancelAssignmentBtn.addEventListener('click', resetAssignmentMode);
        }
        
        // مربعات اختيار المتدربين
        traineeCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateSelectedCount();
                updateAssignButtonState();
            });
        });
        
        // تغيير الموظف المسند إليه
        if (assignedToSelect) {
            assignedToSelect.addEventListener('change', updateAssignButtonState);
        }
    }
    
    /**
     * تبديل وضع الإسناد
     */
    function toggleAssignmentMode() {
        isAssignmentMode = !isAssignmentMode;
        
        if (isAssignmentMode) {
            // تفعيل وضع الإسناد
            assignmentSection.classList.add('active');
            toggleAssignmentBtn.classList.remove('from-blue-600', 'to-blue-700');
            toggleAssignmentBtn.classList.add('from-red-600', 'to-red-700');
            toggleAssignmentBtn.innerHTML = '<i class="fas fa-times ml-2"></i> إلغاء الإسناد';
            
            // إظهار أعمدة الاختيار
            checkboxColumns.forEach(col => {
                col.style.display = 'table-cell';
            });
            
            // تمرير للأعلى لإظهار قسم الإسناد
            assignmentSection.scrollIntoView({ behavior: 'smooth' });
        } else {
            // إلغاء وضع الإسناد
            resetAssignmentMode();
        }
    }
    
    /**
     * إعادة تعيين وضع الإسناد
     */
    function resetAssignmentMode() {
        isAssignmentMode = false;
        assignmentSection.classList.remove('active');
        toggleAssignmentBtn.classList.remove('from-red-600', 'to-red-700');
        toggleAssignmentBtn.classList.add('from-blue-600', 'to-blue-700');
        toggleAssignmentBtn.innerHTML = '<i class="fas fa-user-check ml-2"></i> إسناد متدربين';
        
        // إخفاء أعمدة الاختيار
        checkboxColumns.forEach(col => {
            col.style.display = 'none';
        });
        
        // إلغاء تحديد جميع المتدربين
        deselectAllTrainees();
    }
    
    /**
     * تحديد جميع المتدربين
     */
    function selectAllTrainees() {
        traineeCheckboxes.forEach(checkbox => {
            checkbox.checked = true;
        });
        updateSelectedCount();
        updateAssignButtonState();
    }
    
    /**
     * إلغاء تحديد جميع المتدربين
     */
    function deselectAllTrainees() {
        traineeCheckboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        updateSelectedCount();
        updateAssignButtonState();
    }
    
    /**
     * تحديث عداد المتدربين المحددين
     */
    function updateSelectedCount() {
        selectedTrainees = Array.from(traineeCheckboxes).filter(checkbox => checkbox.checked).length;
        if (selectedCountSpan) {
            selectedCountSpan.textContent = selectedTrainees;
        }
    }
    
    /**
     * تحديث حالة زر الإسناد
     */
    function updateAssignButtonState() {
        if (confirmAssignmentBtn) {
            const hasSelectedTrainees = selectedTrainees > 0;
            const hasSelectedEmployee = assignedToSelect && assignedToSelect.value;
            
            confirmAssignmentBtn.disabled = !(hasSelectedTrainees && hasSelectedEmployee);
            
            if (hasSelectedTrainees && hasSelectedEmployee) {
                confirmAssignmentBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                confirmAssignmentBtn.classList.add('hover:bg-green-700');
            } else {
                confirmAssignmentBtn.classList.add('opacity-50', 'cursor-not-allowed');
                confirmAssignmentBtn.classList.remove('hover:bg-green-700');
            }
        }
    }
    
    /**
     * تأكيد عملية الإسناد
     */
    function confirmAssignment() {
        if (selectedTrainees === 0) {
            showNotification('يرجى تحديد متدرب واحد على الأقل', 'error');
            return;
        }
        
        if (!assignedToSelect || !assignedToSelect.value) {
            showNotification('يرجى اختيار موظف للإسناد', 'error');
            return;
        }
        
        // إرسال النموذج
        if (assignmentForm) {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'assign_trainees';
            hiddenInput.value = '1';
            assignmentForm.appendChild(hiddenInput);
            assignmentForm.submit();
        }
    }
    
    /**
     * عرض إشعار للمستخدم
     */
    function showNotification(message, type = 'info') {
        // التحقق من وجود مكتبة toastr
        if (typeof toastr !== 'undefined') {
            toastr[type](message);
        } else {
            alert(message);
        }
    }
});