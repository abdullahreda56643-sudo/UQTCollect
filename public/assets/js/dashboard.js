// تكوين عام للمخططات البيانية
Chart.defaults.font.family = "'Cairo', 'Tahoma', 'sans-serif'";
Chart.defaults.color = document.documentElement.classList.contains('dark') ? '#9CA3AF' : '#4B5563';

// تحديث المخططات عند تغيير الوضع المظلم/الفاتح
document.addEventListener('dark-mode-changed', function(e) {
    Chart.defaults.color = e.detail.darkMode ? '#9CA3AF' : '#4B5563';
    // إعادة تهيئة المخططات إذا لزم الأمر
    if (window.traineesDistributionChart) {
        window.traineesDistributionChart.update();
    }
});

// تهيئة البحث
function initSearch() {
    return {
        query: '',
        results: [],
        isLoading: false,
        open: false,
        debounceTimer: null,
        
        init() {
            // إغلاق نتائج البحث عند النقر خارج الحقل
            document.addEventListener('click', (e) => {
                if (!this.$refs.searchContainer.contains(e.target)) {
                    this.open = false;
                }
            });
        },
        
        onInput() {
            this.isLoading = true;
            this.open = true;
        },
        
        debounceSearch() {
            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => {
                this.fetchResults();
            }, 300);
        },
        
        async fetchResults(force = false) {
            if (this.query.trim().length < 2) {
                this.results = [];
                this.isLoading = false;
                return;
            }
            
            this.isLoading = true;
            this.open = true;
            
            try {
                const response = await fetch(`${SITE_URL}/api/search.php?q=${encodeURIComponent(this.query.trim())}`);
                const data = await response.json();
                
                this.results = Array.isArray(data) ? data : (data.results || []);
                
                // إذا كانت النتيجة واحدة ومطابقة تماماً، انتقل مباشرة
                if (this.results.length === 1 && this.query.trim().toLowerCase() === this.results[0].full_name.toLowerCase()) {
                    window.location.href = this.results[0].url;
                }
            } catch (error) {
                console.error('Search error:', error);
                this.results = [];
            } finally {
                this.isLoading = false;
            }
        }
    };
}

// وظيفة تحديث حالة الإشعارات
function markAlertAsRead(alertId, event) {
    if (!alertId) return;
    
    // منع السلوك الافتراضي للرابط
    event.preventDefault();
    
    const link = event.currentTarget.getAttribute('href');
    
    // إرسال طلب لتحديث حالة الإشعار
    fetch(`${SITE_URL}/api/notifications.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ alert_id: alertId }),
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // تحديث عدد الإشعارات غير المقروءة
            const badge = document.querySelector('.fa-bell').nextElementSibling;
            if (badge) {
                const count = parseInt(badge.textContent) - 1;
                if (count <= 0) {
                    badge.remove();
                } else {
                    badge.textContent = count;
                }
            }
            
            // إزالة الإشعار من القائمة
            event.currentTarget.remove();
            
            // التحقق مما إذا كانت القائمة فارغة الآن
            const notificationsList = document.querySelector('.max-h-80.overflow-auto');
            if (notificationsList && notificationsList.children.length === 0) {
                notificationsList.innerHTML = '<div class="p-4 text-center text-gray-500 dark:text-gray-400">لا توجد إشعارات جديدة</div>';
            }
            
            // الانتقال إلى الرابط
            if (link && link !== '#') {
                window.location.href = link;
            }
        }
    })
    .catch(error => {
        console.error('Error marking notification as read:', error);
        // الانتقال إلى الرابط حتى في حالة الخطأ
        if (link && link !== '#') {
            window.location.href = link;
        }
    });
}

// تصدير الدوال للاستخدام العام
window.initSearch = initSearch;
window.markAlertAsRead = markAlertAsRead;