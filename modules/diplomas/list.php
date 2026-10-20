<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Diploma.php';

// require admin or explicit diplomas view permission
requireAccessOrAdmin('diplomas', 'view');
$db = (new Database())->getConnection();
$diplomaModel = new Diploma($db);

// معالجة البحث والتصفية
$filters = [];
$search = $_GET['search'] ?? '';
$cost_min = $_GET['cost_min'] ?? '';
$cost_max = $_GET['cost_max'] ?? '';
$duration_min = $_GET['duration_min'] ?? '';
$duration_max = $_GET['duration_max'] ?? '';
$status = $_GET['status'] ?? '';

if (!empty($search)) {
    $filters['search'] = $search;
}
if (!empty($cost_min)) {
    $filters['cost_min'] = (float)$cost_min;
}
if (!empty($cost_max)) {
    $filters['cost_max'] = (float)$cost_max;
}
if (!empty($duration_min)) {
    $filters['duration_min'] = (int)$duration_min;
}
if (!empty($duration_max)) {
    $filters['duration_max'] = (int)$duration_max;
}
if (!empty($status)) {
    $filters['status'] = $status;
}

// الحصول على البيانات مع التصفية
$diplomas = $diplomaModel->list($filters, 'id DESC', 100);

// حساب الإحصائيات
$stats = [
    'total_diplomas' => count($diplomas),
    'active_diplomas' => 0,
    'total_cost' => 0,
    'avg_duration' => 0,
    'total_enrollments' => 0
];

$durations = [];
foreach ($diplomas as $d) {
    if ($d['is_active'] == 1) {
        $stats['active_diplomas']++;
    }
    $stats['total_cost'] += (float)$d['cost'];
    $durations[] = (int)$d['duration'];
    // Ensure current_enrollments is defined to avoid PHP notice
    $currentEnrollments = isset($d['current_enrollments']) ? (int)$d['current_enrollments'] : 0;
    $stats['total_enrollments'] += $currentEnrollments;
}

$stats['avg_duration'] = !empty($durations) ? array_sum($durations) / count($durations) : 0;
$stats['avg_cost'] = $stats['total_diplomas'] > 0 ? $stats['total_cost'] / $stats['total_diplomas'] : 0;

// معالجة التصدير
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    if ($exportType === 'excel') {
        // تصدير Excel
        if (!hasAccess('diplomas','export')) {
            // hide export functionality for users without export permission
        } else {
            // export code continues
        }
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="diplomas_' . date('Y-m-d') . '.xls"');
        // ... كود التصدير
        exit;
    } elseif ($exportType === 'pdf') {
        // تصدير PDF
        // ... كود التصدير
        exit;
    }
}
$page_title = 'الدبلومات'; include '../../includes/tw_layout_start.php'; ?>

<div class="space-y-6">
    <!-- البطاقات الإحصائية -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6" data-aos="fade-up">
        <!-- إجمالي الدبلومات -->
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl p-6 text-white shadow-lg transform hover:scale-105 transition-transform duration-300">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm">إجمالي الدبلومات</p>
                    <p class="text-3xl font-bold mt-2"><?php echo $stats['total_diplomas']; ?></p>
                </div>
                <div class="bg-blue-400/20 p-3 rounded-xl">
                    <i class="fas fa-graduation-cap text-2xl"></i>
                </div>
            </div>
            <div class="mt-4 flex items-center text-blue-100 text-sm">
                <i class="fas fa-chart-line ml-1"></i>
                <span>جميع البرامج التدريبية</span>
            </div>
        </div>

        <!-- الدبلومات النشطة -->
        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-2xl p-6 text-white shadow-lg transform hover:scale-105 transition-transform duration-300">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm">الدبلومات النشطة</p>
                    <p class="text-3xl font-bold mt-2"><?php echo $stats['active_diplomas']; ?></p>
                </div>
                <div class="bg-green-400/20 p-3 rounded-xl">
                    <i class="fas fa-check-circle text-2xl"></i>
                </div>
            </div>
            <div class="mt-4 flex items-center text-green-100 text-sm">
                <i class="fas fa-users ml-1"></i>
                <span>جاهزة للتسجيل</span>
            </div>
        </div>

        <!-- متوسط المدة -->
        <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-2xl p-6 text-white shadow-lg transform hover:scale-105 transition-transform duration-300">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-purple-100 text-sm">متوسط المدة</p>
                    <p class="text-3xl font-bold mt-2"><?php echo number_format($stats['avg_duration'], 1); ?></p>
                    <p class="text-purple-100 text-sm">شهر</p>
                </div>
                <div class="bg-purple-400/20 p-3 rounded-xl">
                    <i class="fas fa-clock text-2xl"></i>
                </div>
            </div>
            <div class="mt-4 flex items-center text-purple-100 text-sm">
                <i class="fas fa-calendar ml-1"></i>
                <span>مدة الدراسة</span>
            </div>
        </div>

        <!-- إجمالي المسجلين -->
        <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-2xl p-6 text-white shadow-lg transform hover:scale-105 transition-transform duration-300">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-orange-100 text-sm">إجمالي المسجلين</p>
                    <p class="text-3xl font-bold mt-2"><?php echo $stats['total_enrollments']; ?></p>
                </div>
                <div class="bg-orange-400/20 p-3 rounded-xl">
                    <i class="fas fa-user-graduate text-2xl"></i>
                </div>
            </div>
            <div class="mt-4 flex items-center text-orange-100 text-sm">
                <i class="fas fa-chart-bar ml-1"></i>
                <span>طلاب مسجلين</span>
            </div>
        </div>
    </div>

    <!-- أدوات البحث والتصفية -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg p-6" data-aos="fade-up" data-aos-delay="100">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <h2 class="text-base font-semibold text-gray-800 dark:text-white">أدوات البحث والتصفية</h2>

            <div class="flex flex-col sm:flex-row gap-3 flex-1 max-w-4xl text-sm">
                <!-- بحث بالاسم -->
                <div class="flex-1">
                    <input type="text"
                           name="search"
                           placeholder="ابحث باسم الدبلوم..."
                           value="<?php echo htmlspecialchars($search); ?>"
                           class="w-full px-3 py-2 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm">
                </div>

                <!-- تصفية بالتكلفة -->
                <div class="flex gap-2">
                    <input type="number"
                           name="cost_min"
                           placeholder="أقل تكلفة"
                           value="<?php echo htmlspecialchars($cost_min); ?>"
                           class="w-24 px-2 py-1 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm">
                    <input type="number"
                           name="cost_max"
                           placeholder="أعلى تكلفة"
                           value="<?php echo htmlspecialchars($cost_max); ?>"
                           class="w-24 px-2 py-1 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm">
                </div>

                <!-- تصفية بالمدة -->
                <div class="flex gap-2">
                    <input type="number"
                           name="duration_min"
                           placeholder="أقل مدة"
                           value="<?php echo htmlspecialchars($duration_min); ?>"
                           class="w-24 px-2 py-1 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm">
                    <input type="number"
                           name="duration_max"
                           placeholder="أعلى مدة"
                           value="<?php echo htmlspecialchars($duration_max); ?>"
                           class="w-24 px-2 py-1 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm">
                </div>

                <!-- تصفية بالحالة -->
                <select name="status" class="px-2 py-1 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm">
                    <option value="">جميع الحالات</option>
                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>نشط</option>
                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>غير نشط</option>
                </select>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="px-4 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded-xl transition-colors duration-200 text-sm">
                    <i class="fas fa-search ml-2"></i>بحث
                </button>
                <a href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/diplomas/list.php"
                   class="px-4 py-1 bg-gray-500 hover:bg-gray-600 text-white rounded-xl transition-colors duration-200 text-sm">
                    <i class="fas fa-redo ml-2"></i>إعادة تعيين
                </a>
            </div>
        </div>
    </div>

    <!-- الرسم البياني -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg p-6" data-aos="fade-up" data-aos-delay="200">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-lg font-bold text-gray-800 dark:text-white">توزيع الدبلومات حسب التكلفة</h2>
            <div class="flex gap-2">
                <button onclick="exportData('excel')" class="px-3 py-1 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm transition-colors duration-200">
                    <i class="fas fa-file-excel ml-1"></i>Excel
                </button>
                <button onclick="exportData('pdf')" class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm transition-colors duration-200">
                    <i class="fas fa-file-pdf ml-1"></i>PDF
                </button>
            </div>
        </div>

        <!-- مخطط بسيط للتوزيع -->
        <div class="h-64 bg-gray-50 dark:bg-gray-700/50 rounded-xl p-4 flex items-end justify-between gap-2">
            <?php 
            $costRanges = [0, 1000, 2000, 3000, 4000, 5000];
            $costCounts = [0, 0, 0, 0, 0, 0];

            foreach ($diplomas as $d) {
                $cost = (float)$d['cost'];
                for ($i = 0; $i < count($costRanges) - 1; $i++) {
                    if ($cost >= $costRanges[$i] && $cost < $costRanges[$i + 1]) {
                        $costCounts[$i]++;
                        break;
                    }
                }
                if ($cost >= $costRanges[count($costRanges) - 1]) {
                    $costCounts[count($costCounts) - 1]++;
                }
            }

            $maxCount = max($costCounts);
            $maxHeight = 180;
            ?>
            <?php for ($i = 0; $i < count($costRanges) - 1; $i++): ?>
                <div class="flex-1 flex flex-col items-center">
                    <div class="w-full bg-gradient-to-t from-blue-500 to-blue-400 rounded-t-lg transition-all duration-700 ease-out"
                         style="height: <?php echo $maxCount > 0 ? ($costCounts[$i] / $maxCount) * $maxHeight : 0; ?>px;">
                        <span class="text-white text-xs font-bold -mt-6"><?php echo $costCounts[$i]; ?></span>
                    </div>
                    <div class="text-xs text-gray-600 dark:text-gray-400 mt-1 text-center">
                        <?php echo number_format($costRanges[$i]); ?>-<?php echo number_format($costRanges[$i + 1]); ?>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <!-- إضافة دبلوم -->
    <div class="flex justify-end" data-aos="fade-up" data-aos-delay="300">
        <?php if (hasAccess('diplomas','create')): ?>
        <a href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/diplomas/add.php"
           class="inline-flex items-center px-6 py-2 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-xl shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 transition-all duration-200">
            <i class="fas fa-plus ml-2"></i> إضافة دبلوم
        </a>
        <?php endif; ?>
    </div>

    <!-- جدول الدبلومات -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg overflow-hidden" data-aos="fade-up" data-aos-delay="400">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700" id="diplomasTable">
                <thead class="bg-gray-50 dark:bg-gray-700/50">
                    <tr>

                        <th class="px-6 py-4 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider cursor-pointer sortable" data-sort="name">
                            الدبلوم <i class="fas fa-sort ml-1"></i>
                        </th>
                        <th class="px-6 py-4 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider cursor-pointer sortable" data-sort="duration">
                            المدة <i class="fas fa-sort ml-1"></i>
                        </th>
                        <th class="px-6 py-4 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider cursor-pointer sortable" data-sort="cost">
                            التكلفة <i class="fas fa-sort ml-1"></i>
                        </th>
                        <th class="px-6 py-4 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            المسجلين
                        </th>
                        <th class="px-6 py-4 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            الحالة
                        </th>
                        <th class="px-6 py-4 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            الإجراءات
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php foreach ($diplomas as $d): ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors duration-200" data-diploma-id="<?php echo $d['id']; ?>">

                        <td class="px-6 py-4">
                            <div class="flex items-center space-x-3 space-x-reverse">
                                <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center text-white font-bold text-sm">
                                    <?php echo substr($d['name'], 0, 2); ?>
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($d['name']); ?>
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 truncate max-w-xs">
                                        <?php echo htmlspecialchars($d['description'] ?? 'لا يوجد وصف'); ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-200">
                            <div class="flex items-center">
                                <i class="fas fa-clock text-gray-400 ml-2"></i>
                                <?php echo (int)$d['duration']; ?> شهر
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 dark:text-white">
                            <?php echo number_format((float)$d['cost'], 2); ?> ر.س
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <?php $rowEnrollments = isset($d['current_enrollments']) ? (int)$d['current_enrollments'] : 0; ?>
                                <span class="px-3 py-1 rounded-full text-xs font-medium
                                    <?php echo $rowEnrollments > 10 ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-400' :
                                           ($rowEnrollments > 5 ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-400' :
                                           'bg-gray-100 text-gray-800 dark:bg-gray-900/40 dark:text-gray-400'); ?>">
                                    <?php echo $rowEnrollments; ?> طالب
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-3 py-1 rounded-full text-xs font-medium
                                <?php echo $d['is_active'] == 1 ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-400' :
                                       'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-400'; ?>">
                                <?php echo ($d['is_active'] ?? 0) == 1 ? 'نشط' : 'غير نشط'; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-left text-sm">
                            <div class="flex items-center space-x-2 space-x-reverse">
                                <!-- معاينة -->
                                <button onclick="showDiplomaDetails(<?php echo $d['id']; ?>)"
                                        class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 transition-colors duration-200"
                                        title="معاينة">
                                    <i class="fas fa-eye"></i>
                                </button>

                                <?php if (hasAccess('diplomas','edit')): ?>
                                <!-- تعديل -->
                                <a href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/diplomas/edit.php?id=<?php echo (int)$d['id']; ?>"
                                   class="text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 transition-colors duration-200"
                                   title="تعديل">
                                    <i class="fas fa-edit"></i>
                                </a>

                                <!-- تفعيل/إيقاف -->
                                <button onclick="toggleDiplomaStatus(<?php echo $d['id']; ?>, <?php echo $d['is_active']; ?>)"
                                        class="<?php echo $d['is_active'] == 1 ? 'text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300' : 'text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300'; ?> transition-colors duration-200"
                                        title="<?php echo $d['is_active'] == 1 ? 'إيقاف' : 'تفعيل'; ?>">
                                    <i class="fas fa-power-off"></i>
                                </button>
                                <?php endif; ?>

                                <!-- حذف -->
                                <?php if (hasAccess('diplomas','delete')): ?>
                                <button onclick="confirmDelete(<?php echo $d['id']; ?>)"
                                        class="text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 transition-colors duration-200"
                                        title="حذف">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- نافذة معاينة الدبلوم -->
<div id="diplomaModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-bold text-gray-800 dark:text-white">معاينة الدبلوم</h3>
                <button onclick="closeModal()" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        <div class="p-6" id="diplomaModalContent">
            <!-- محتوى المعاينة سيتم تحميله هنا -->
        </div>
    </div>
</div>

<script>
// دالة معاينة الدبلوم
function showDiplomaDetails(diplomaId) {
    fetch(`../../api/diplomas/details.php?id=${diplomaId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const modalContent = document.getElementById('diplomaModalContent');
                modalContent.innerHTML = `
                    <div class="space-y-6">
                        <div class="flex items-center space-x-4 space-x-reverse">
                            <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center text-white font-bold text-lg">
                                ${data.diploma.name.substring(0, 2)}
                            </div>
                            <div>
                                <h4 class="text-lg font-bold text-gray-800 dark:text-white">${data.diploma.name}</h4>
                                <p class="text-gray-600 dark:text-gray-400">${data.diploma.description || 'لا يوجد وصف'}</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="bg-gray-50 dark:bg-gray-700/50 p-4 rounded-xl">
                                <p class="text-sm text-gray-600 dark:text-gray-400">المدة</p>
                                <p class="font-bold text-gray-800 dark:text-white">${data.diploma.duration} شهر</p>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-700/50 p-4 rounded-xl">
                                <p class="text-sm text-gray-600 dark:text-gray-400">التكلفة</p>
                                <p class="font-bold text-gray-800 dark:text-white">${parseFloat(data.diploma.cost).toLocaleString()} ر.س</p>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-700/50 p-4 rounded-xl">
                                <p class="text-sm text-gray-600 dark:text-gray-400">المسجلين</p>
                                <p class="font-bold text-gray-800 dark:text-white">${data.diploma.current_enrollments} طالب</p>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-700/50 p-4 rounded-xl">
                                <p class="text-sm text-gray-600 dark:text-gray-400">الحالة</p>
                                <p class="font-bold ${data.diploma.is_active == 1 ? 'text-green-600' : 'text-red-600'}">${data.diploma.is_active == 1 ? 'نشط' : 'غير نشط'}</p>
                            </div>
                        </div>

                        <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-xl">
                            <h5 class="font-bold text-blue-800 dark:text-blue-300 mb-2">الإيرادات المتوقعة</h5>
                            <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                                ${(parseFloat(data.diploma.cost) * parseInt(data.diploma.current_enrollments)).toLocaleString()} ر.س
                            </p>
                        </div>
                    </div>
                `;
                document.getElementById('diplomaModal').classList.remove('hidden');
                document.getElementById('diplomaModal').classList.add('flex');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('حدث خطأ في تحميل البيانات');
        });
}

// دالة إغلاق النافذة
function closeModal() {
    document.getElementById('diplomaModal').classList.add('hidden');
    document.getElementById('diplomaModal').classList.remove('flex');
}

// دالة تغيير حالة الدبلوم
function toggleDiplomaStatus(diplomaId, currentStatus) {
    if (confirm(`هل تريد ${currentStatus == 1 ? 'إيقاف' : 'تفعيل'} هذا الدبلوم؟`)) {
        fetch(`../../api/diplomas/toggle_status.php?id=${diplomaId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'حدث خطأ');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('حدث خطأ في الاتصال بالخادم');
            });
    }
}

// دالة تأكيد الحذف
function confirmDelete(diplomaId) {
    if (confirm('هل أنت متأكد من حذف هذا الدبلوم؟ لا يمكن التراجع عن هذا الإجراء.')) {
        window.location.href = `<?php echo rtrim(SITE_URL,'/'); ?>/modules/diplomas/delete.php?id=${diplomaId}`;
    }
}

// دالة التصدير
function exportData(type) {
    window.location.href = `<?php echo rtrim(SITE_URL,'/'); ?>/modules/diplomas/list.php?export=${type}`;
}

// فرز الجدول
document.querySelectorAll('.sortable').forEach(header => {
    header.addEventListener('click', function() {
        const sortBy = this.dataset.sort;
        // تنفيذ الفرز - يمكن إضافة منطق الفرز هنا
        alert(`سيتم الفرز حسب: ${sortBy}`);
    });
});

// إخفاء loader بعد تحميل الصفحة
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => {
        const preloader = document.getElementById('preloader');
        if (preloader) {
            preloader.style.opacity = '0';
            setTimeout(() => preloader.style.display = 'none', 500);
        }
    }, 1000);
});
</script>

<?php include '../../includes/tw_layout_end.php'; ?>
