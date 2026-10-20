<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Payment.php';

checkPermission('employee');
$db = (new Database())->getConnection();
$paymentModel = new Payment($db);

// دوال مساعدة للعرض
function getPaymentTypeLabel($type) {
    $types = [
        'cash' => 'نقدي',
        'bank_transfer' => 'تحويل بنكي',
        'check' => 'شيك',
        'credit_card' => 'بطاقة'
    ];
    return $types[$type] ?? $type;
}

function getPaymentTypeBadgeClass($type) {
    $classes = [
        'cash' => 'bg-green-100 text-green-800',
        'bank_transfer' => 'bg-blue-100 text-blue-800',
        'check' => 'bg-purple-100 text-purple-800',
        'credit_card' => 'bg-yellow-100 text-yellow-800'
    ];
    return $classes[$type] ?? 'bg-gray-100 text-gray-800';
}

function getPaymentStatusLabel($status) {
    $statuses = [
        'completed' => 'مكتمل',
        'pending' => 'معلق',
        'failed' => 'فاشل'
    ];
    return $statuses[$status] ?? $status;
}

function getPaymentStatusBadgeClass($status) {
    $classes = [
        'completed' => 'bg-green-100 text-green-800',
        'pending' => 'bg-yellow-100 text-yellow-800',
        'failed' => 'bg-red-100 text-red-800'
    ];
    return $classes[$status] ?? 'bg-gray-100 text-gray-800';
}

// تجهيز الفلاتر
$filters = [];
if (isset($_GET['trainee_id']) && $_GET['trainee_id'] !== '') {
    $filters['trainee_id'] = (int)$_GET['trainee_id'];
}
if (isset($_GET['trainee_name']) && $_GET['trainee_name'] !== '') {
    $filters['trainee_name'] = trim($_GET['trainee_name']);
}
if (isset($_GET['trainee_national_id']) && $_GET['trainee_national_id'] !== '') {
    $filters['trainee_national_id'] = trim($_GET['trainee_national_id']);
}

// تجهيز نطاق التاريخ
$startDate = !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$endDate = !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

if (!empty($startDate)) {
    $filters['start_date'] = $startDate;
}
if (!empty($endDate)) {
    $filters['end_date'] = $endDate;
}

// البحث عن نوع الدفع
if (!empty($_GET['payment_type'])) {
    $filters['payment_type'] = $_GET['payment_type'];
}

// البحث عن حالة الدفع
if (!empty($_GET['status'])) {
    $filters['status'] = $_GET['status'];
}

// قيد: للمستخدمين غير الإداريين
if (!isAdmin()) {
    if (isSupervisor()) {
        // Supervisor: strictly scope to trainees assigned to the supervisor or their team
        try {
            $employeeIds = getEmployeesUnderSupervisor($db, (int)($_SESSION['user_id'] ?? 0));
            $employeeIds[] = (int)($_SESSION['user_id'] ?? 0);

            $traineeIds = [];
            if (!empty($employeeIds)) {
                $place = implode(',', array_fill(0, count($employeeIds), '?'));
                $stmt = $db->prepare("SELECT id FROM trainees WHERE assigned_to IN ($place)");
                $stmt->execute($employeeIds);
                $traineeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            // Also include trainees in the supervisor's branch (many installs use branch scoping)
            try {
                if (isset($_SESSION['branch_id']) && !empty($_SESSION['branch_id'])) {
                    $bstmt = $db->prepare("SELECT id FROM trainees WHERE branch_id = ?");
                    $bstmt->execute([(int)$_SESSION['branch_id']]);
                    $branchTrainees = $bstmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($branchTrainees)) {
                        $traineeIds = array_merge($traineeIds, $branchTrainees);
                    }
                }
            } catch (Exception $e) {
                error_log('Failed to include branch trainees in supervisor scope: ' . $e->getMessage());
            }

            // If supervisor has trainees, restrict to them. Otherwise, force empty result set.
            if (!empty($traineeIds)) {
                // deduplicate and cast to ints
                $traineeIds = array_values(array_unique(array_map('intval', $traineeIds)));
                $filters['trainee_id'] = $traineeIds;
                error_log('[payments/list.php] Supervisor traineeIds: ' . implode(',', $filters['trainee_id']));
            } else {
                // No trainees in scope -> ensure no payments are shown
                $filters['trainee_id'] = [0]; // impossible id to return empty
            }
        } catch (Exception $e) {
            error_log('Failed to apply supervisor payments scope: ' . $e->getMessage());
            // Fall back to showing nothing rather than leaking other trainees
            $filters['trainee_id'] = [0];
        }
    } else {
        // Regular employee: limit to payments they created
        $filters['created_by'] = (int)($_SESSION['user_id'] ?? 0);
    }
}

// الحصول على المدفوعات

// NOTE: we intentionally do NOT force a branch_id filter here for supervisors
// because supervisors are scoped by trainee assignment/team (trainee_id list) above.
// Forcing branch_id in addition to trainee_id produced empty results when trainees
// were assigned to team members who may belong to other branches. The trainee_id
// filter provides the correct scoping for supervisors.

// هل المشرف ليس لديه متدربين في النطاق؟ (تم ضبط trainee_id => [0] أعلاه للنتائج الفارغة)
$supervisorHasNoTrainees = false;
if (isSupervisor()) {
    if (isset($filters['trainee_id']) && is_array($filters['trainee_id']) && count($filters['trainee_id']) === 1 && (int)$filters['trainee_id'][0] === 0) {
        $supervisorHasNoTrainees = true;
    }
}

$payments = $paymentModel->getPaymentsWithDetails($filters);

// Debug logging to help diagnose why list can be empty
error_log("[payments/list.php] Filters: " . print_r($filters, true));
error_log("[payments/list.php] Payments fetched: " . (is_array($payments) ? count($payments) : 'not-array')); 

// حساب الإجماليات - استخدام نفس المنطق المعروض في الجدول
$totalAmount = array_sum(array_map(function($payment) {
    return $payment['amount'];
}, $payments));
$totalCount = count($payments);
?>
<?php 
$page_title = 'تقرير تحصيل المدفوعات'; 
include '../../includes/tw_layout_start.php'; 
?>

<div class="flex items-center justify-between mb-4">
    <h1 class="text-xl font-bold">تقرير تحصيل المدفوعات</h1>
    <?php if (hasAccess('payments','create') && !($supervisorHasNoTrainees ?? false)): ?>
    <a href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/payments/add.php" class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-lg shadow-md hover:shadow-lg transform hover:-translate-y-0.5 transition-all duration-200">
        <i class="fas fa-plus ml-2"></i> إضافة دفعة
    </a>
    <?php endif; ?>
</div>

<!-- نموذج التصفية -->
<form class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 mb-6" method="get">
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">ID المتدرب</label>
            <input name="trainee_id" placeholder="ID المتدرب" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700" value="<?php echo htmlspecialchars($_GET['trainee_id'] ?? ''); ?>">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">اسم المتدرب</label>
            <input name="trainee_name" placeholder="اسم المتدرب" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700" value="<?php echo htmlspecialchars($_GET['trainee_name'] ?? ''); ?>">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الهوية الوطنية</label>
            <input name="trainee_national_id" placeholder="الهوية الوطنية" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700" value="<?php echo htmlspecialchars($_GET['trainee_national_id'] ?? ''); ?>">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">من تاريخ</label>
            <input type="date" name="start_date" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700" value="<?php echo htmlspecialchars($_GET['start_date'] ?? ''); ?>">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">إلى تاريخ</label>
            <input type="date" name="end_date" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700" value="<?php echo htmlspecialchars($_GET['end_date'] ?? ''); ?>">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">نوع الدفع</label>
            <select name="payment_type" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                <option value="">الكل</option>
                <option value="cash" <?php echo ($_GET['payment_type'] ?? '') === 'cash' ? 'selected' : ''; ?>>نقدي</option>
                <option value="bank_transfer" <?php echo ($_GET['payment_type'] ?? '') === 'bank_transfer' ? 'selected' : ''; ?>>تحويل بنكي</option>
                <option value="credit_card" <?php echo ($_GET['payment_type'] ?? '') === 'credit_card' ? 'selected' : ''; ?>>بطاقة</option>
                <option value="check" <?php echo ($_GET['payment_type'] ?? '') === 'check' ? 'selected' : ''; ?>>شيك</option>
            </select>
        </div>

    </div>
    <div class="mt-4 flex justify-between items-center">
        <button class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700" type="submit">تصفية</button>
        <div class="flex gap-2">
            <?php if (hasAccess('payments','export') && !($supervisorHasNoTrainees ?? false)): ?>
            <a href="export_excel.php?<?php echo http_build_query($_GET); ?>" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                <i class="fas fa-file-excel ml-1"></i> تصدير Excel
            </a>
            <?php endif; ?>
            <?php if (!($supervisorHasNoTrainees ?? false)): ?>
            <a href="export_pdf.php?<?php echo http_build_query($_GET); ?>" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                <i class="fas fa-file-pdf ml-1"></i> تصدير PDF
            </a>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- صندوق الإجماليات -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
        <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-300 mb-2">إجمالي المدفوعات</h3>
        <p class="text-3xl font-bold text-green-600"><?php echo number_format($totalAmount, 2); ?> ريال</p>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">من <?php echo date('Y/m/d', strtotime($startDate)); ?> إلى <?php echo date('Y/m/d', strtotime($endDate)); ?></p>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
        <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-300 mb-2">عدد المدفوعات</h3>
        <p class="text-3xl font-bold text-blue-600"><?php echo number_format($totalCount); ?></p>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">متوسط المدفوعات: <?php echo $totalCount > 0 ? number_format($totalAmount / $totalCount, 2) : 0; ?> ريال</p>
    </div>
</div>

<!-- Modal Components -->
<div id="viewModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50" style="backdrop-filter: blur(4px);">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white dark:bg-gray-800">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">تفاصيل الدفعة</h3>
            <button onclick="closeModal('viewModal')" class="text-gray-400 hover:text-gray-500 focus:outline-none">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="viewModalContent" class="overflow-y-auto max-h-[60vh]">
            <div class="flex justify-center items-center py-12">
                <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
            </div>
        </div>
    </div>
</div>

<div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50" style="backdrop-filter: blur(4px);">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-2/3 shadow-lg rounded-md bg-white dark:bg-gray-800">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">تعديل الدفعة</h3>
            <button onclick="closeModal('editModal')" class="text-gray-400 hover:text-gray-500 focus:outline-none">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="editModalContent" class="overflow-y-auto max-h-[60vh]">
            <div class="flex justify-center items-center py-12">
                <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-green-500"></div>
            </div>
        </div>
    </div>
</div>

<div id="deleteAllModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50" style="backdrop-filter: blur(4px);">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 lg:w-1/3 shadow-lg rounded-md bg-white dark:bg-gray-800">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">حذف جميع المدفوعات</h3>
            <button onclick="closeModal('deleteAllModal')" class="text-gray-400 hover:text-gray-500 focus:outline-none">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post" action="delete_all.php" onsubmit="return validateDeleteAllForm();">
            <div class="mb-4">
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                    <div class="flex items-center mb-2">
                        <i class="fas fa-exclamation-triangle text-red-500 ml-2"></i>
                        <h4 class="text-red-800 font-semibold">تحذير</h4>
                    </div>
                    <p class="text-red-700 text-sm">
                        سيؤدي هذا الإجراء إلى حذف جميع سجلات المدفوعات نهائياً. لا يمكن التراجع عن هذا الإجراء.
                    </p>
                </div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    كلمة المرور للتأكيد
                </label>
                <input type="password" name="confirm_password" required 
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 dark:bg-gray-700 dark:border-gray-600"
                       placeholder="أدخل كلمة المرور للتأكيد">
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    يجب إدخال كلمة المرور للمسؤول لتأكيد عملية الحذف
                </p>
            </div>
            <div class="flex justify-end space-x-reverse space-x-2">
                <button type="button" onclick="closeModal('deleteAllModal')" 
                        class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                    إلغاء
                </button>
                <button type="submit" 
                        class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                    <i class="fas fa-trash-alt ml-2"></i> حذف الكل
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Loading Indicator -->
<div id="loadingOverlay" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white p-4 rounded-lg shadow-lg">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500"></div>
    </div>
</div>

<!-- جدول التقرير -->
<div class="flex items-center justify-between mb-3">
    <h2 class="text-lg font-semibold">قائمة المدفوعات</h2>
    <?php if (isAdmin() || hasAccess('payments','delete')): ?>
    <button onclick="openDeleteAllModal()" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg shadow-md hover:bg-red-700">
        <i class="fas fa-trash-alt ml-2"></i> حذف جميع المدفوعات
    </button>
    <?php endif; ?>
</div>
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700/50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">#</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">المتدرب</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">رقم الهوية</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">المبلغ المدفوع</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">نوع الدفع</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">رقم المرجع</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">تاريخ الدفع</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">الموظف</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">الإجراءات</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($payments)): ?>
                <tr>
                    <td colspan="11" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">
                        <i class="fas fa-info-circle ml-2"></i>
                        لا توجد مدفوعات مسجلة
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($payments as $index => $payment): ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                            <?php echo $index + 1; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars($payment['trainee_name']); ?>
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                ID: <?php echo htmlspecialchars($payment['trainee_id']); ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white dir-ltr text-left">
                            <?php echo htmlspecialchars($payment['trainee_national_id'] ?? '-'); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <div class="font-medium text-green-600">
                                <?php echo number_format($payment['amount'], 2); ?> ريال
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-xs rounded-full <?php echo getPaymentTypeBadgeClass($payment['payment_method']); ?>">
                                <?php echo getPaymentTypeLabel($payment['payment_method']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                            <?php echo htmlspecialchars($payment['receipt_number'] ?? '-'); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                            <?php echo formatDateTime($payment['payment_date']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                            <?php echo htmlspecialchars($payment['created_by_name'] ?? '-'); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <?php if (hasAccess('payments', 'view')): ?>
                            <button onclick="openViewModal(<?php echo $payment['id']; ?>)" class="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300 ml-3">
                                <i class="fas fa-eye"></i>
                            </button>
                            <?php endif; ?>
                            <?php if (hasAccess('payments', 'edit')): ?>
                            <button onclick="openEditModal(<?php echo $payment['id']; ?>)" class="text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300 ml-3">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php endif; ?>
                            <?php if (hasAccess('payments', 'delete')): ?>
                            <a href="delete.php?id=<?php echo $payment['id']; ?>" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300 ml-3"
                               onclick="return confirm('هل أنت متأكد من حذف هذه الدفعة؟');">
                                <i class="fas fa-trash"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>       
        </table>
        </table>
    </div>
</div>

<script>
// تهيئة المتغيرات العامة
let currentModal = null;
let isLoading = false;

// دالة فتح النافذة المنبثقة
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        currentModal = modalId;
        
        // تحريك التركيز إلى النافذة المنبثقة
        const modalContent = modal.querySelector('.relative');
        if (modalContent) {
            modalContent.focus();
        }
    }
}

// دالة إغلاق النافذة المنبثقة
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
        currentModal = null;
    }
}

// دالة إظهار مؤشر التحميل
function showLoading() {
    if (!isLoading) {
        isLoading = true;
        document.getElementById('loadingOverlay').classList.remove('hidden');
    }
}

// دالة إخفاء مؤشر التحميل
function hideLoading() {
    if (isLoading) {
        isLoading = false;
        document.getElementById('loadingOverlay').classList.add('hidden');
    }
}

// دالة عرض رسالة خطأ في النافذة المنبثقة
function showModalError(modalId, message) {
    const contentDiv = document.getElementById(`${modalId}Content`);
    if (contentDiv) {
        contentDiv.innerHTML = `
            <div class="p-4 text-center">
                <div class="text-red-500 mb-2">
                    <i class="fas fa-exclamation-circle text-3xl"></i>
                </div>
                <div class="text-red-600 font-semibold">${message}</div>
                <button onclick="closeModal('${modalId}')" class="mt-4 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                    إغلاق
                </button>
            </div>
        `;
    }
}

// دالة فتح نافذة عرض التفاصيل
async function openViewModal(paymentId) {
    try {
        openModal('viewModal');
        const contentDiv = document.getElementById('viewModalContent');
        
        const response = await fetch(`view.php?id=${paymentId}&modal=true`);
        if (!response.ok) throw new Error('فشل تحميل البيانات');
        
        const html = await response.text();
        contentDiv.innerHTML = html;
    } catch (error) {
        console.error('Error:', error);
        showModalError('viewModal', 'حدث خطأ أثناء تحميل البيانات');
    }
}

// دالة فتح نافذة التعديل
async function openEditModal(paymentId) {
    try {
        openModal('editModal');
        const contentDiv = document.getElementById('editModalContent');
        
        const response = await fetch(`edit.php?id=${paymentId}&modal=true`);
        if (!response.ok) throw new Error('فشل تحميل البيانات');
        
        const html = await response.text();
        contentDiv.innerHTML = html;
        initializeModalForm();
    } catch (error) {
        console.error('Error:', error);
        showModalError('editModal', 'حدث خطأ أثناء تحميل البيانات');
    }
}

// دالة تهيئة نموذج التعديل
function initializeModalForm() {
    const form = document.querySelector('#editModalContent form');
    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            showLoading();
            
            try {
                const formData = new FormData(form);
                
                // تحقق من أن النموذج لم يتم إرساله بالفعل
                if (form.dataset.submitting === 'true') {
                    return;
                }
                form.dataset.submitting = 'true';

                const response = await fetch(form.action, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                if (data.success) {
                    closeModal('editModal');
                    window.location.reload();
                } else {
                    throw new Error(data.message || 'حدث خطأ أثناء حفظ البيانات');
                }
            } catch (error) {
                console.error('Error:', error);
                alert(error.message || 'حدث خطأ أثناء حفظ البيانات');
            } finally {
                hideLoading();
                // إعادة تعيين حالة النموذج
                form.dataset.submitting = 'false';
            }
        });
    }
}

// إضافة مستمعي الأحداث عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', function() {
    // إغلاق النافذة عند النقر خارجها
    document.addEventListener('click', function(e) {
        if (currentModal) {
            const modal = document.getElementById(currentModal);
            const modalContent = modal.querySelector('.relative');
            if (e.target === modal && !modalContent.contains(e.target)) {
                closeModal(currentModal);
            }
        }
    });

    // إغلاق النافذة بمفتاح Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && currentModal) {
            closeModal(currentModal);
        }
    });
});
</script>

    <script>
    function confirmDeleteAllPayments() {
        return confirm('هل أنت متأكد من حذف جميع المدفوعات؟\n\nسيؤدي هذا الإجراء إلى حذف كل سجلات المدفوعات ولا يمكن التراجع عنه. تأكد من وجود نسخة احتياطية قبل المتابعة.');
    }

    function openDeleteAllModal() {
        openModal('deleteAllModal');
    }

    function validateDeleteAllForm() {
        const password = document.querySelector('input[name="confirm_password"]').value;
        if (!password) {
            alert('يجب إدخال كلمة المرور للتأكيد');
            return false;
        }
        return true;
    }
    </script>

<?php include '../../includes/tw_layout_end.php'; ?>

