-- إنشاء جدول الاتصالات
CREATE TABLE IF NOT EXISTS communications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainee_id INT NOT NULL,
    type ENUM('call', 'whatsapp') NOT NULL,
    contact_date DATETIME NOT NULL,
    result ENUM('promise', 'refused', 'paid', 'no_answer', 'other') NOT NULL,
    notes TEXT,
    next_action_date DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (trainee_id) REFERENCES trainees(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- إنشاء جدول للتذكيرات
CREATE TABLE IF NOT EXISTS reminders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    communication_id INT NOT NULL,
    reminder_date DATETIME NOT NULL,
    status ENUM('pending', 'completed', 'cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (communication_id) REFERENCES communications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;