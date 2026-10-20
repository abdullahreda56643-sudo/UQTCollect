<?php
// تعريف المتغيرات الافتراضية
$row = $row ?? [
    'title' => '',
    'description' => '',
    'due_date' => date('Y-m-d H:i:s'),
    'priority' => 'medium',
    'status' => 'pending',
    'assigned_to' => $_SESSION['user_id'] ?? 0,
    'related_trainee' => ''
];
?>

<form method="post" class="space-y-4">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm mb-1">عنوان المهمة</label>
            <input name="title" value="<?php echo htmlspecialchars($row['title']); ?>" 
                   class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700" required>
        </div>

        <div>
            <label class="block text-sm mb-1">تاريخ الاستحقاق</label>
            <input name="due_date" type="datetime-local" 
                   value="<?php echo date('Y-m-d\TH:i', strtotime($row['due_date'])); ?>" 
                   class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
        </div>

        <div>
            <label class="block text-sm mb-1">الأولوية</label>
            <select name="priority" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                <?php 
                $priorities = ['low'=>'منخفضة','medium'=>'متوسطة','high'=>'عالية']; 
                foreach($priorities as $k=>$l): 
                ?>
                <option value="<?php echo $k; ?>" <?php echo ($row['priority']===$k?'selected':''); ?>>
                    <?php echo $l; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-sm mb-1">الحالة</label>
            <select name="status" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                <?php 
                $statuses = ['pending'=>'معلقة','in_progress'=>'قيد التنفيذ','completed'=>'منجزة','cancelled'=>'ملغاة']; 
                foreach($statuses as $k=>$l): 
                ?>
                <option value="<?php echo $k; ?>" <?php echo ($row['status']===$k?'selected':''); ?>>
                    <?php echo $l; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-sm mb-1">المعيّن له (User ID)</label>
            <input name="assigned_to" type="number" value="<?php echo (int)$row['assigned_to']; ?>" 
                   class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
        </div>

        <div>
            <label class="block text-sm mb-1">ID المتدرب (اختياري)</label>
            <input name="related_trainee" type="number" value="<?php echo htmlspecialchars($row['related_trainee']); ?>" 
                   class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
        </div>
    </div>

    <div>
        <label class="block text-sm mb-1">الوصف</label>
        <textarea name="description" rows="3" 
                  class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700"><?php echo htmlspecialchars($row['description']); ?></textarea>
    </div>

    <div class="mt-2 space-x-2 space-x-reverse">
        <button class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg" type="submit">حفظ</button>
        <a class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-lg" 
           href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/tasks/list.php">عودة</a>
    </div>
</form>