<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Payment.php';
require_once '../../models/Trainee.php';

checkPermission('employee');
$db = (new Database())->getConnection();
$paymentModel = new Payment($db);
$traineeModel = new Trainee($db);

$id = (int)($_GET['id'] ?? 0);
$isModal = isset($_GET['modal']) && $_GET['modal'] === 'true';

if ($id <= 0) {
    if ($isModal) {
        echo json_encode(['success' => false, 'message' => 'معرّف غير صالح']);
        exit;
    }
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/payments/list.php', 'معرّف غير صالح', 'error');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = [
            'amount' => (float)($_POST['amount'] ?? 0),
            'payment_date' => $_POST['payment_date'] ?? date('Y-m-d'),
            'payment_method' => $_POST['payment_method'] ?? 'cash',
            'receipt_number' => trim($_POST['receipt_number'] ?? ''),
            'notes' => trim($_POST['notes'] ?? ''),
        ];

        // Load existing payment row
        $existing = $paymentModel->findById($id);
        if (!$existing) throw new Exception('الدفعة غير موجودة');

        // Validate amount
        if ($data['amount'] <= 0) throw new Exception('المبلغ يجب أن يكون أكبر من صفر');

        // Update payment
        $paymentModel->update($id, $data);

        // Refresh row - balance update happens internally in Payment::update()
        $row = $paymentModel->findById($id);
        if ($row && isset($row['trainee_id'])) {
            // Attempt to use trainee balance procedure if available
            if (method_exists($traineeModel, 'updateBalanceProcedure')) {
                try {
                    $traineeModel->updateBalanceProcedure((int)$row['trainee_id']);
                } catch (Exception $e) {
                    error_log('Warning: failed to run updateBalanceProcedure: ' . $e->getMessage());
                }
            }
        }

        // Create user alert
        if (function_exists('createUserAlert')) {
            $title = 'تعديل دفعة';
            $msg = 'تم تعديل الدفعة #' . $id . ' بقيمة ' . number_format($data['amount'], 2) . ' ' . getCurrencySymbol();
            createUserAlert($db, (int)$_SESSION['user_id'], $title, $msg, 'info', rtrim(SITE_URL,'/') . '/modules/payments/edit.php?id=' . $id);
        }

        if ($isModal) {
            echo json_encode(['success' => true, 'message' => 'تم حفظ التعديلات']);
            exit;
        }
        redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/payments/edit.php?id=' . $id, 'تم حفظ التعديلات');
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load payment and prepare view data
$row = $paymentModel->findById($id);
if (!$row) {
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/payments/list.php', 'السجل غير موجود', 'error');
}

// Compute remaining for preview
$traineeDataForPreview = $traineeModel->findById((int)$row['trainee_id']);
$remainingExcludingCurrent = (float)($traineeDataForPreview['remaining_amount'] ?? 0) + (float)($row['amount'] ?? 0);

$page_title = 'تعديل دفعة';
if (!$isModal) include '../../includes/tw_layout_start.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">تعديل دفعة</h1>
            <p class="text-gray-600 dark:text-gray-400">رقم الدفعة: #<?php echo $id; ?></p>
        </div>

        <!-- Error Message -->
        <?php if($error): ?>
        <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-red-400 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                </svg>
                <span class="text-red-800 dark:text-red-200"><?php echo htmlspecialchars($error); ?></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="post" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <!-- Trainee ID -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">ID المتدرب</label>
                    <input 
                        value="<?php echo (int)$row['trainee_id']; ?>" 
                        disabled 
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-400"
                    >
                </div>

                <!-- Amount -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        المبلغ (<?php echo htmlspecialchars(getCurrencySymbol()); ?>)
                        <span class="text-red-500">*</span>
                    </label>
                    <input 
                        name="amount" 
                        type="number" 
                        step="0.01" 
                        min="0.01"
                        value="<?php echo htmlspecialchars($row['amount']); ?>" 
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        required
                    >
                </div>

                <!-- Payment Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">تاريخ الدفع</label>
                    <input 
                        name="payment_date" 
                        type="date" 
                        value="<?php echo htmlspecialchars($row['payment_date']); ?>" 
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    >
                </div>

                <!-- Payment Method -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">طريقة الدفع</label>
                    <select 
                        name="payment_method" 
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    >
                        <?php 
                        $methods = [
                            'cash' => 'نقدي',
                            'bank_transfer' => 'تحويل بنكي', 
                            'credit_card' => 'بطاقة ائتمان',
                            'check' => 'شيك'
                        ]; 
                        foreach ($methods as $k => $l): 
                        ?>
                        <option value="<?php echo $k; ?>" <?php echo ($row['payment_method'] === $k ? 'selected' : ''); ?>>
                            <?php echo $l; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Receipt Number -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">رقم الإيصال</label>
                    <input 
                        name="receipt_number" 
                        value="<?php echo htmlspecialchars($row['receipt_number']); ?>" 
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="أدخل رقم الإيصال..."
                    >
                </div>
            </div>

            <!-- Notes -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">ملاحظات</label>
                <textarea 
                    name="notes" 
                    rows="3" 
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"
                    placeholder="أدخل أي ملاحظات إضافية..."
                ><?php echo htmlspecialchars($row['notes']); ?></textarea>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-col sm:flex-row gap-3 justify-end items-center pt-6 border-t border-gray-200 dark:border-gray-700">
                <button 
                    type="submit" 
                    class="w-full sm:w-auto px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors duration-200 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                >
                    <div class="flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        حفظ التعديلات
                    </div>
                </button>
                
                <a 
                    href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/payments/list.php" 
                    class="w-full sm:w-auto px-6 py-2.5 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition-colors duration-200 text-center"
                >
                    <div class="flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                        عودة للقائمة
                    </div>
                </a>
            </div>
        </form>
    </div>
</div>

<?php if (!$isModal) include '../../includes/tw_layout_end.php'; ?>

<script>
// No discount functionality needed - removed
</script>