document.addEventListener('DOMContentLoaded', function() {
    const notificationsIcon = document.getElementById('notificationsIcon');
    const notificationsDropdown = document.getElementById('notificationsDropdown');
    const notificationsList = notificationsDropdown.querySelector('.notifications-list');
    const loadingIndicator = notificationsList.querySelector('.loading');
    let notifications = [];
    let isLoading = false;

    // Toggle notifications dropdown
    notificationsIcon.addEventListener('click', function(e) {
        e.stopPropagation();
        notificationsDropdown.classList.toggle('show');
        if (notificationsDropdown.classList.contains('show')) {
            loadNotifications();
        }
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!notificationsIcon.contains(e.target)) {
            notificationsDropdown.classList.remove('show');
        }
    });

    // Load notifications from the API
    function loadNotifications() {
        if (isLoading) return;
        isLoading = true;
        
        notificationsList.querySelector('.notifications-content')?.remove();
        loadingIndicator.classList.remove('d-none');

        fetch('/my-works/Trainee-Management-System/api/notifications.php')
            .then(response => response.json())
            .then(data => {
                isLoading = false;
                loadingIndicator.classList.add('d-none');
                
                if (data.success) {
                    notifications = data.data;
                    renderNotifications();
                }
            })
            .catch(error => {
                console.error('Error loading notifications:', error);
                isLoading = false;
                loadingIndicator.classList.add('d-none');
            });
    }

    // Render notifications in the dropdown
    function renderNotifications() {
        const content = document.createElement('div');
        content.className = 'notifications-content';

        if (notifications.length === 0) {
            content.innerHTML = `
                <div class="text-center p-3 text-muted">
                    <i class="fas fa-bell-slash fa-2x mb-2"></i>
                    <p class="mb-0">لا توجد إشعارات</p>
                </div>
            `;
        } else {
            notifications.forEach(notification => {
                const item = document.createElement('div');
                item.className = `notification-item ${notification.is_read ? '' : 'unread'}`;
                item.dataset.id = notification.id;

                var iconName = notification.icon ? notification.icon.replace(/[^a-z0-9-_]/gi, '') : getNotificationIcon(notification.type);
                var iconColor = getNotificationColor(notification.type);
                item.innerHTML = `
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-${iconName} text-${iconColor}"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="mb-1">${notification.message}</p>
                            <small class="text-muted">${formatDate(notification.created_at)}</small>
                        </div>
                    </div>
                `;

                if (!notification.is_read) {
                    item.addEventListener('click', () => markAsRead(notification.id));
                }

                content.appendChild(item);
            });
        }

        notificationsList.querySelector('.notifications-content')?.remove();
        notificationsList.appendChild(content);
    }

    // Mark a notification as read
    function markAsRead(id) {
        fetch('../api/notifications.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ alert_id: id })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const notification = notifications.find(n => n.id === id);
                if (notification) {
                    notification.is_read = true;
                    updateNotificationBadge();
                    renderNotifications();
                }
            }
        })
        .catch(error => console.error('Error marking notification as read:', error));
    }

    // Update the notification badge count
    function updateNotificationBadge() {
        const unreadCount = notifications.filter(n => !n.is_read).length;
        const badge = notificationsIcon.querySelector('.notifications-badge');
        
        if (unreadCount > 0) {
            if (badge) {
                badge.textContent = unreadCount;
            } else {
                const newBadge = document.createElement('span');
                newBadge.className = 'badge bg-danger notifications-badge';
                newBadge.textContent = unreadCount;
                notificationsIcon.appendChild(newBadge);
            }
        } else if (badge) {
            badge.remove();
        }
    }

    // Helper function to get notification icon based on type
    function getNotificationIcon(type) {
        const icons = {
            'info': 'info-circle',
            'success': 'check-circle',
            'warning': 'exclamation-triangle',
            'error': 'times-circle'
        };
        return icons[type] || 'bell';
    }

    // Helper function to get notification color based on type
    function getNotificationColor(type) {
        const colors = {
            'info': 'info',
            'success': 'success',
            'warning': 'warning',
            'error': 'danger'
        };
        return colors[type] || 'primary';
    }

    // Helper function to format date
    function formatDate(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleDateString('ar-SA', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    // Handle mark all as read button
    const markAllReadButton = notificationsDropdown.querySelector('.mark-all-read');
    if (markAllReadButton) {
        markAllReadButton.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const unreadNotifications = notifications.filter(n => !n.is_read);
            if (unreadNotifications.length === 0) return;

            Promise.all(unreadNotifications.map(n => 
                fetch('../api/notifications.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ alert_id: n.id })
                })
            ))
            .then(() => {
                notifications.forEach(n => n.is_read = true);
                updateNotificationBadge();
                renderNotifications();
            })
            .catch(error => console.error('Error marking all as read:', error));
        });
    }
});