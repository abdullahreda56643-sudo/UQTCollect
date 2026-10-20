// وظائف إسناد المتدربين
document.addEventListener('DOMContentLoaded', function() {
    // تعريف دالة hasAccess للاستخدام في JavaScript
    window.hasAccess = function(module, permission) {
        // الحصول على الصلاحيات من البيانات المخزنة في الصفحة
        const userPermissions = document.body.getAttribute('data-permissions');
        if (!userPermissions) return false;
        
        try {
            const permissions = JSON.parse(userPermissions);
            return permissions[module] && permissions[module].includes(permission);
        } catch (e) {
            console.error('خطأ في تحليل بيانات الصلاحيات:', e);
            return false;
        }
    };

    // العناصر الرئيسية
    const toggleAssignmentBtn = document.getElementById('toggleAssignment');
    const assignmentSection = document.getElementById('assignmentSection');
    const selectAllBtn = document.getElementById('selectAllTrainees');
    const deselectAllBtn = document.getElementById('deselectAllTrainees');
    const cancelAssignmentBtn = document.getElementById('cancelAssignment');
    const confirmAssignmentBtn = document.getElementById('confirmAssignment');
    const assignedToSelect = document.getElementById('assignedTo');
    const selectedCountSpan = document.getElementById('selectedCount')?.querySelector('span');
    const checkboxColumns = document.querySelectorAll('.checkbox-column');
    const traineeCheckboxes = document.querySelectorAll('.trainee-checkbox');
    
    // التحقق من وجود رابط إعادة توجيه في الجلسة
    const redirectUrl = document.body.getAttribute('data-redirect-url');
    if (redirectUrl) {
        window.location.href = redirectUrl;
        return;
    }
    
    // حالة وضع الإسناد
    let assignmentMode = false;
    
    // تبديل وضع الإسناد
    if (toggleAssignmentBtn) {
        toggleAssignmentBtn.addEventListener('click', function() {
            assignmentMode = !assignmentMode;
            
            // إظهار/إخفاء قسم الإسناد
            if (assignmentSection) {
                assignmentSection.style.display = assignmentMode ? 'block' : 'none';
            }
            
            // إظهار/إخفاء أعمدة الاختيار
            checkboxColumns.forEach(column => {
                column.style.display = assignmentMode ? 'table-cell' : 'none';
            });
            
            // تغيير نص الزر
            this.innerHTML = assignmentMode ? 
                '<i class="fas fa-times ml-2"></i> إلغاء الإسناد' : 
                '<i class="fas fa-user-check ml-2"></i> إسناد متدربين';
                
            // تغيير لون الزر
            if (assignmentMode) {
                this.classList.remove('from-blue-600', 'to-blue-700');
                this.classList.add('from-red-600', 'to-red-700');
            } else {
                this.classList.remove('from-red-600', 'to-red-700');
                this.classList.add('from-blue-600', 'to-blue-700');
            }
            
            // إعادة تعيين التحديدات عند الإلغاء
            if (!assignmentMode) {
                traineeCheckboxes.forEach(checkbox => {
                    checkbox.checked = false;
                });
                updateSelectedCount();
            }
        });
    }
    
    // تحديد الكل
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            traineeCheckboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            updateSelectedCount();
        });
    }
    
    // إلغاء تحديد الكل
    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function() {
            traineeCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            updateSelectedCount();
        });
    }
    
    // إلغاء وضع الإسناد
    if (cancelAssignmentBtn) {
        cancelAssignmentBtn.addEventListener('click', function() {
            if (toggleAssignmentBtn) {
                toggleAssignmentBtn.click();
            }
        });
    }
    
    // تحديث عدد المتدربين المحددين
    function updateSelectedCount() {
        let count = 0;
        traineeCheckboxes.forEach(checkbox => {
            if (checkbox.checked) {
                count++;
            }
        });
        
        if (selectedCountSpan) {
            selectedCountSpan.textContent = count;
        }
        
        // تفعيل/تعطيل زر التأكيد
        if (confirmAssignmentBtn) {
            confirmAssignmentBtn.disabled = count === 0 || !assignedToSelect || assignedToSelect.value === '';
        }
    }
    
    // مراقبة تغييرات الاختيار
    traineeCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedCount);
    });
    
    // مراقبة تغيير الموظف المسند إليه
    if (assignedToSelect) {
        assignedToSelect.addEventListener('change', updateSelectedCount);
    }
    
    // تحديث العداد عند التحميل
    updateSelectedCount();
});
    
    // تبديل وضع الإسناد
    if (toggleAssignmentBtn) {
        toggleAssignmentBtn.addEventListener('click', function() {
            assignmentMode = !assignmentMode;
            
            // إظهار/إخفاء قسم الإسناد
            if (assignmentSection) {
                assignmentSection.style.display = assignmentMode ? 'block' : 'none';
            }
            
            // إظهار/إخفاء أعمدة الاختيار
            checkboxColumns.forEach(column => {
                column.style.display = assignmentMode ? 'table-cell' : 'none';
            });
            
            // تغيير نص الزر
            this.innerHTML = assignmentMode ? 
                '<i class="fas fa-times ml-2"></i> إلغاء الإسناد' : 
                '<i class="fas fa-user-check ml-2"></i> إسناد متدربين';
                
            // تغيير لون الزر
            if (assignmentMode) {
                this.classList.remove('from-blue-600', 'to-blue-700');
                this.classList.add('from-red-600', 'to-red-700');
            } else {
                this.classList.remove('from-red-600', 'to-red-700');
                this.classList.add('from-blue-600', 'to-blue-700');
            }
            
            // إعادة تعيين التحديدات عند الإلغاء
            if (!assignmentMode) {
                traineeCheckboxes.forEach(checkbox => {
                    checkbox.checked = false;
                });
                updateSelectedCount();
            }
        });
    }
    
    // تحديد الكل
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            traineeCheckboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            updateSelectedCount();
        });
    }
    
    // إلغاء تحديد الكل
    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function() {
            traineeCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            updateSelectedCount();
        });
    }
    
    // إلغاء وضع الإسناد
    if (cancelAssignmentBtn) {
        cancelAssignmentBtn.addEventListener('click', function() {
            if (toggleAssignmentBtn) {
                toggleAssignmentBtn.click();
            }
        });
    }
    
    // تحديث عدد المتدربين المحددين
    function updateSelectedCount() {
        let count = 0;
        traineeCheckboxes.forEach(checkbox => {
            if (checkbox.checked) {
                count++;
            }
        });
        
        if (selectedCountSpan) {
            selectedCountSpan.textContent = count;
        }
        
        // تفعيل/تعطيل زر التأكيد
        if (confirmAssignmentBtn) {
            confirmAssignmentBtn.disabled = count === 0 || !assignedToSelect || assignedToSelect.value === '';
        }
    }
    
    // مراقبة تغييرات الاختيار
    traineeCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedCount);
    });
    
    // مراقبة تغيير الموظف المسند إليه
    if (assignedToSelect) {
        assignedToSelect.addEventListener('change', updateSelectedCount);
    }
    
    // تحديث العداد عند التحميل
    updateSelectedCount();
});