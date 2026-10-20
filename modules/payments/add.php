<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Trainee.php';
require_once '../../models/Payment.php';

checkPermission('employee');

$db = (new Database())->getConnection();
$traineeModel = new Trainee($db);
$paymentModel = new Payment($db);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // التحقق من البيانات
        $traineeId = (int)($_POST['trainee_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $paymentMethod = $_POST['payment_method'] ?? 'cash';
        $receiptNumber = trim($_POST['receipt_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($traineeId <= 0) {
            throw new Exception('يرجى اختيار المتدرب');
        }
        
        if ($amount <= 0) {
            throw new Exception('المبلغ يجب أن يكون أكبر من صفر');
        }
        
        // التحقق من وجود المتدرب
        $trainee = $traineeModel->findById($traineeId);
        if (!$trainee) {
            throw new Exception('المتدرب غير موجود');
        }
        
        // تجهيز بيانات الدفعة
        $data = [
            'trainee_id' => $traineeId,
            'amount' => $amount,
            'payment_date' => $paymentDate,
            'payment_method' => $paymentMethod,
            'receipt_number' => $receiptNumber,
            'notes' => $notes,
            'created_by' => (int)$_SESSION['user_id']
        ];
        
        // إضافة الدفعة
        $paymentId = $paymentModel->addPayment($data);
        
        $success = 'تم إضافة الدفعة بنجاح';
        
        // إعادة التوجيه
        header('Location: list.php?success=' . urlencode($success));
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// جلب قائمة المتدربين
try {
    $stmt = $db->prepare("SELECT id, full_name, national_id, remaining_amount FROM trainees ORDER BY full_name");
    $stmt->execute();
    $trainees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $trainees = [];
    error_log("Error loading trainees: " . $e->getMessage());
}

$page_title = 'إضافة دفعة';
include '../../includes/tw_layout_start.php';

?>

<div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 mb-6">
    <h1 class="text-xl font-bold mb-4">إضافة دفعة جديدة</h1>

    <?php if ($error): ?>
    <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="mb-4 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-lg"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <form method="post" id="paymentForm" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm mb-1">
                    <i class="fas fa-user ml-1"></i>
                    المتدرب <span class="text-red-500">*</span>
                </label>
                <input type="text" name="trainee_search" id="traineeSearch" required
                       class="w-full px-3 py-2 rounded-lg border"
                       placeholder="اكتب اسم المتدرب أو الرقم الوطني أو ID">
                <input type="hidden" name="trainee_id" id="traineeId">
                <div id="traineeSuggestions" class="hidden mt-1 bg-white border rounded-lg shadow-lg max-h-40 overflow-y-auto"></div>
            </div>
            <div>
                <label class="block text-sm mb-1">
                    <i class="fas fa-wallet ml-1"></i>
                    المبلغ المتبقي
                </label>
                <div class="w-full px-3 py-2 rounded-lg border bg-gray-50">
                    <span id="remainingAmount" class="font-bold text-blue-600">
                        -- اختر المتدرب --
                    </span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm mb-1">
                    <i class="fas fa-money-bill-wave ml-1"></i>
                    المبلغ <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                    <input type="number" name="amount" id="amountInput" 
                           step="0.01" min="0.01" required
                           class="w-full px-3 py-2 rounded-lg border pl-12"
                           placeholder="0.00">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <span class="text-gray-500 text-sm">ر.س</span>
                    </div>
                </div>
            </div>
            <div>
                <label class="block text-sm mb-1">
                    <i class="fas fa-calendar ml-1"></i>
                    تاريخ الدفع <span class="text-red-500">*</span>
                </label>
                <input type="date" name="payment_date" required
                       value="<?php echo date('Y-m-d'); ?>"
                       class="w-full px-3 py-2 rounded-lg border">
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm mb-1">
                    <i class="fas fa-credit-card ml-1"></i>
                    طريقة الدفع <span class="text-red-500">*</span>
                </label>
                <select name="payment_method" required
                        class="w-full px-3 py-2 rounded-lg border">
                    <option value="cash">نقدي</option>
                    <option value="bank_transfer">تحويل بنكي</option>
                    <option value="credit_card">بطاقة ائتمان</option>
                    <option value="check">شيك</option>
                </select>
            </div>
            <div>
                <label class="block text-sm mb-1">
                    <i class="fas fa-receipt ml-1"></i>
                    رقم الإيصال
                </label>
                <input type="text" name="receipt_number"
                       class="w-full px-3 py-2 rounded-lg border"
                       placeholder="اختياري">
            </div>
        </div>

        <div>
            <label class="block text-sm mb-1">
                <i class="fas fa-comment ml-1"></i>
                ملاحظات
            </label>
            <textarea name="notes" rows="3"
                      class="w-full px-3 py-2 rounded-lg border"
                      placeholder="أي ملاحظات إضافية..."></textarea>
        </div>


        <div class="flex justify-between items-center pt-4">
            <a href="list.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                <i class="fas fa-times ml-2"></i>
                إلغاء
            </a>
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fas fa-save ml-2"></i>
                حفظ الدفعة
            </button>
        </div>
    </form>
</div>

... -->

<script>
const API_BASE = '<?php echo rtrim(SITE_URL, '/'); ?>';

// بيانات المتدربين
const traineesData = <?php echo json_encode($trainees); ?>;

// عناصر DOM
const traineeSearch = document.getElementById('traineeSearch');
const traineeId = document.getElementById('traineeId');
const traineeSuggestions = document.getElementById('traineeSuggestions');
const remainingAmount = document.getElementById('remainingAmount');
const amountInput = document.getElementById('amountInput');

// البحث عن المتدرب
traineeSearch.addEventListener('input', function() {
    const query = this.value.trim().toLowerCase();
    
    if (query.length < 2) {
        traineeSuggestions.classList.add('hidden');
        traineeId.value = '';
        remainingAmount.textContent = '-- اختر المتدرب --';
        return;
    }
    
    const results = traineesData.filter(trainee => {
        return trainee.full_name.toLowerCase().includes(query) ||
               trainee.national_id.toLowerCase().includes(query) ||
               trainee.id.toString().includes(query);
    });
    
    if (results.length > 0) {
        let html = '';
        results.forEach(trainee => {
            html += `
                <div class="p-2 hover:bg-gray-100 cursor-pointer border-b last:border-b-0" 
                     onclick="selectTrainee(${trainee.id}, '${trainee.full_name.replace(/'/g, "\\'")}', ${trainee.remaining_amount || 0})">
                    <div class="font-semibold">${trainee.full_name}</div>
                    <div class="text-sm text-gray-600">ID: ${trainee.id} | الهوية: ${trainee.national_id}</div>
                    <div class="text-sm text-blue-600">المتبقي: ${number_format(trainee.remaining_amount || 0, 2)} ر.س</div>
                </div>
            `;
        });
        traineeSuggestions.innerHTML = html;
        traineeSuggestions.classList.remove('hidden');
    } else {
        traineeSuggestions.innerHTML = '<div class="p-2 text-gray-500">لا توجد نتائج</div>';
        traineeSuggestions.classList.remove('hidden');
    }
});

// اختيار المتدرب
function selectTrainee(id, name, remaining) {
    traineeId.value = id;
    traineeSearch.value = name;
    remainingAmount.textContent = number_format(remaining, 2) + ' ر.س';
    traineeSuggestions.classList.add('hidden');
}

// إغلاق قائمة الاقتراحات عند النقر خارجها
document.addEventListener('click', function(e) {
    if (!traineeSearch.contains(e.target) && !traineeSuggestions.contains(e.target)) {
        traineeSuggestions.classList.add('hidden');
    }
});


// دالة مساعدة للتنسيق
function number_format(num, decimals) {
    return parseFloat(num || 0).toLocaleString('ar-SA', { 
        minimumFractionDigits: decimals, 
        maximumFractionDigits: decimals 
    });
}
</script>

<?php include '../../includes/tw_layout_end.php'; ?>
