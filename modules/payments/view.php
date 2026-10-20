<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Payment.php';

checkPermission('employee');

// Helper functions for payment display
function getPaymentTypeLabel($type) {
    $types = [
        'cash' => 'نقدي',
        'bank_transfer' => 'تحويل بنكي',
        'check' => 'شيك',
        'card' => 'بطاقة',
        'credit_card' => 'بطاقة'
    ];
    return $types[$type] ?? $type;
}

function getPaymentTypeBadgeClass($type) {
    $classes = [
        'cash' => 'bg-green-100 text-green-800',
        'bank_transfer' => 'bg-blue-100 text-blue-800',
        'check' => 'bg-purple-100 text-purple-800',
        'card' => 'bg-yellow-100 text-yellow-800',
        'credit_card' => 'bg-yellow-100 text-yellow-800'
    ];
    return $classes[$type] ?? 'bg-gray-100 text-gray-800';
}

$db = (new Database())->getConnection();
$paymentModel = new Payment($db);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isModal = isset($_GET['modal']) && $_GET['modal'] === 'true';

if (!$id) {
    if ($isModal) {
        echo 'معرف الدفعة غير صالح';
        exit;
    }
    header('Location: list.php');
    exit;
}

// Get payment details with joined trainee information
$sql = "SELECT p.*, t.full_name as trainee_name, t.national_id as trainee_national_id, 
               t.phone as trainee_phone, u.full_name as created_by_name
        FROM payments p
        LEFT JOIN trainees t ON t.id = p.trainee_id
        LEFT JOIN users u ON u.id = p.created_by
        WHERE p.id = ?";
$stmt = $db->prepare($sql);
$stmt->execute([$id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    if ($isModal) {
        echo 'الدفعة غير موجودة';
        exit;
    }
    header('Location: list.php');
    exit;
}

if (!$isModal) {
    $page_title = 'عرض تفاصيل الدفعة';
    include '../../includes/tw_layout_start.php';
}
?>

<div class="<?php echo $isModal ? '' : 'max-w-4xl mx-auto py-6'; ?>">
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">معلومات المتدرب</h3>
                    <dl class="grid grid-cols-1 gap-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">اسم المتدرب</dt>
                            <dd class="text-base text-gray-900 dark:text-white"><?php echo htmlspecialchars($payment['trainee_name']); ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">رقم الهوية</dt>
                            <dd class="text-base text-gray-900 dark:text-white dir-ltr"><?php echo htmlspecialchars($payment['trainee_national_id'] ?? '-'); ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">الجوال</dt>
                            <dd class="text-base text-gray-900 dark:text-white dir-ltr"><?php echo htmlspecialchars($payment['trainee_phone'] ?? '-'); ?></dd>
                        </div>
                    </dl>
                </div>
                
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">تفاصيل الدفعة</h3>
                    <dl class="grid grid-cols-1 gap-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">المبلغ</dt>
                            <dd class="text-base text-gray-900 dark:text-white"><?php echo number_format($payment['amount'], 2); ?> ريال</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">نوع الدفع</dt>
                            <dd class="text-base text-gray-900 dark:text-white">
                                <span class="px-2 py-1 text-xs rounded-full <?php echo getPaymentTypeBadgeClass($payment['payment_method']); ?>">
                                    <?php echo getPaymentTypeLabel($payment['payment_method']); ?>
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">رقم المرجع</dt>
                            <dd class="text-base text-gray-900 dark:text-white"><?php echo htmlspecialchars($payment['receipt_number'] ?? '-'); ?></dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">معلومات إضافية</h3>
            <dl class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">تاريخ الدفع</dt>
                    <dd class="text-base text-gray-900 dark:text-white"><?php echo date('Y/m/d H:i', strtotime($payment['payment_date'])); ?></dd>
                </div>

                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">تم الإنشاء بواسطة</dt>
                    <dd class="text-base text-gray-900 dark:text-white"><?php echo htmlspecialchars($payment['created_by_name'] ?? '-'); ?></dd>
                </div>
            </dl>
        </div>

        <?php if (!empty($payment['notes'])): ?>
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">الملاحظات</h3>
            <p class="text-gray-700 dark:text-gray-300 whitespace-pre-line"><?php echo nl2br(htmlspecialchars($payment['notes'])); ?></p>
        </div>
        <?php endif; ?>

        <?php if (!$isModal): ?>
        <div class="px-6 py-4 bg-gray-50 dark:bg-gray-700/50 flex justify-end gap-4">
            <a href="list.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500">
                عودة للقائمة
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!$isModal) include '../../includes/tw_layout_end.php'; ?>