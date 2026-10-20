<?php 
require_once __DIR__ . '/notifications.php'; 
require_once __DIR__ . '/../db/connection.php';  
$database = new Database(); 
$db = $database->getConnection(); 
$unreadCount = isset($_SESSION['user_id']) ? getUnreadNotificationsCount($db, $_SESSION['user_id']) : 0; 
?>  

<div 
  x-data="notificationsMenu"
  x-init="loadNotifications(); unreadCount = <?php echo $unreadCount; ?>"
  class="relative"
>     
  <!-- زر الإشعارات -->
  <button 
    @click="toggleMenu"
    class="relative p-2 text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white rounded-lg transition-colors duration-200"
    aria-expanded="false"
    aria-label="قائمة الإشعارات"
  >
    <i class="fas fa-bell text-xl"></i>
    <template x-if="unreadCount > 0">
      <span
        class="notification-badge absolute -top-1 -right-1 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white bg-red-600 rounded-full"
        x-text="unreadCount"
      ></span>
    </template>
  </button>

  <!-- قائمة الإشعارات -->
  <div 
    x-show="isOpen"
    @click.away="isOpen = false"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="transform opacity-0 scale-95"
    x-transition:enter-end="transform opacity-100 scale-100"
    x-transition:leave="transition ease-in duration-75"
    x-transition:leave-start="transform opacity-100 scale-100"
    x-transition:leave-end="transform opacity-0 scale-95"
    class="absolute left-0 mt-2 w-80 bg-white dark:bg-gray-800 rounded-lg shadow-lg overflow-hidden z-50"
    style="display: none;"
  >
    <!-- رأس قائمة الإشعارات -->
    <div class="p-3 bg-gradient-to-l from-blue-600 to-blue-700 dark:from-blue-700 dark:to-blue-800 text-white">
      <h3 class="text-lg font-semibold">الإشعارات</h3>
    </div>

    <!-- محتوى الإشعارات -->
    <div class="max-h-96 overflow-y-auto divide-y divide-gray-200 dark:divide-gray-700">
      <template x-if="loading">
        <div class="p-4 text-center text-gray-500 dark:text-gray-400">
          <i class="fas fa-circle-notch fa-spin ml-2"></i>
          جاري التحميل...
        </div>
      </template>

      <template x-if="!loading && notifications.length === 0">
        <div class="p-4 text-center text-gray-500 dark:text-gray-400">
          <i class="far fa-bell-slash ml-2"></i>
          لا توجد إشعارات جديدة
        </div>
      </template>

      <template x-for="notification in notifications" :key="notification.id">
        <div
          class="flex items-start p-4 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors duration-200 cursor-pointer space-x-4 rtl:space-x-reverse"
          :class="{ 'bg-blue-50 dark:bg-blue-900/20': !notification.is_read }"
          @click="markAsRead(notification.id)"
        >
          <!-- أيقونة نوع الإشعار -->
          <div
            class="flex-shrink-0 w-9 h-9 rounded-full flex items-center justify-center"
            :class="getNotificationIconClass(notification.type)"
          >
            <i class="fas text-base" :class="getNotificationIcon(notification.type)"></i>
          </div>

          <!-- محتوى الإشعار -->
          <div class="flex-1 space-y-1">
            <div class="flex items-center justify-between">
              <div 
                class="font-semibold text-gray-900 dark:text-gray-100 text-sm"
                x-text="notification.title"
              ></div>
              <div class="flex items-center space-x-2 rtl:space-x-reverse">
                <button 
                  x-show="!notification.is_read"
                  @click.stop="markAsRead(notification.id)"
                  class="p-1 text-gray-400 hover:text-green-500 dark:text-gray-500 dark:hover:text-green-400 transition-colors duration-200"
                  title="تعليم كمقروء"
                >
                  <i class="far fa-check-circle text-lg"></i>
                </button>
                <i 
                  x-show="notification.is_read"
                  class="fas fa-check-circle text-green-500 text-sm"
                ></i>
              </div>
            </div>
            <div 
              class="text-sm text-gray-600 dark:text-gray-300"
              x-text="notification.message"
            ></div>
            <div 
              class="text-xs text-gray-500 dark:text-gray-400"
              x-text="formatTime(notification.created_at)"
            ></div>
          </div>
        </div>
      </template>
    </div>

    <!-- رابط عرض جميع الإشعارات -->
    <div class="p-2 bg-gray-50 dark:bg-gray-700/50 text-center border-t border-gray-200 dark:border-gray-600">
      <a 
        href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/notifications/all.php"
        class="text-sm text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 transition-colors duration-200"
      >
        عرض جميع الإشعارات
      </a>
    </div>
  </div>
</div>


<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('notificationsMenu', () => ({
        isOpen: false,
        loading: false,
        notifications: [],
        unreadCount: <?php echo $unreadCount; ?>,
        
        async toggleMenu() {
            this.isOpen = !this.isOpen;
            if (this.isOpen && this.notifications.length === 0) {
                await this.loadNotifications();
            }
        },
        
        async loadNotifications() {
            this.loading = true;
            try {
                const response = await fetch('<?php echo rtrim(SITE_URL, '/'); ?>/api/notifications.php');
                const data = await response.json();
                if (data.success) {
              this.notifications = data.data || [];
              // if API returned unreadCount use it (more accurate), otherwise compute locally
              if (typeof data.unreadCount !== 'undefined') {
                this.unreadCount = parseInt(data.unreadCount, 10) || 0;
              } else {
                this.updateUnreadCount();
              }
                }
            } catch (error) {
                console.error('Error loading notifications:', error);
            } finally {
                this.loading = false;
            }
        },
        
        async markAsRead(id) {
            try {
        const response = await fetch('<?php echo rtrim(SITE_URL, '/'); ?>/api/notifications.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
          // ensure cookies/session are sent on same-origin requests (important on some cPanel setups)
          credentials: 'same-origin',
                    body: JSON.stringify({
                        action: 'mark_read',
                        notification_id: id
                    })
                });
                
                const data = await response.json();
                if (data.success) {
                    // تحديث حالة الإشعار محلياً
                    this.notifications = this.notifications.map(n => 
                        n.id === id ? {...n, is_read: true} : n
                    );
                    
                    // تحديث العداد
                    this.updateUnreadCount();
                }
            } catch (error) {
                console.error('Error marking notification as read:', error);
            }
        },
        
        updateUnreadCount() {
      // debounce visual updates slightly to avoid quick add/remove flicker
      this.unreadCount = this.notifications.filter(n => !n.is_read).length;
      const badge = document.querySelector('.notification-badge');
      if (badge) {
        badge.textContent = this.unreadCount.toString();
      } else if (this.unreadCount > 0) {
        // create badge if missing (first time)
        const btn = document.querySelector('.relative > button') || document.querySelector('button[aria-label="قائمة الإشعارات"]');
        if (btn) {
          const span = document.createElement('span');
          span.className = 'notification-badge absolute -top-1 -right-1 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white bg-red-600 rounded-full';
          span.textContent = this.unreadCount.toString();
          btn.appendChild(span);
        }
      }
        },
        
        getNotificationIcon(type) {
            const icons = {
                'info': 'fa-info-circle',
                'success': 'fa-check-circle',
                'warning': 'fa-exclamation-circle',
                'error': 'fa-times-circle'
            };
            return icons[type] || 'fa-bell';
        },
        
        getNotificationIconClass(type) {
            const classes = {
                'info': 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-300',
                'success': 'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-300',
                'warning': 'bg-yellow-100 text-yellow-600 dark:bg-yellow-900 dark:text-yellow-300',
                'error': 'bg-red-100 text-red-600 dark:bg-red-900 dark:text-red-300'
            };
            return classes[type] || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';
        },
        
        formatTime(timestamp) {
            const date = new Date(timestamp);
            const now = new Date();
            const diff = (now - date) / 1000; // بالثواني

            if (diff < 60) return 'منذ لحظات';
            if (diff < 3600) return `منذ ${Math.floor(diff / 60)} دقيقة`;
            if (diff < 86400) return `منذ ${Math.floor(diff / 3600)} ساعة`;
            return date.toLocaleDateString('ar-SA');
        }
    }));
});
</script>