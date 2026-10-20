<?php
// modules/reports/index.php

require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';

// require admin or explicit reports view permission
requireAccessOrAdmin('reports', 'view');

$db = (new Database())->getConnection();
$currentYear = date('Y');

$query = "SELECT 
            DATE_FORMAT(payment_date, '%Y-%m') as month,
            SUM(amount) as total
          FROM payments 
          WHERE YEAR(payment_date) = ?
          GROUP BY month 
          ORDER BY month ASC";

$stmt = $db->prepare($query);
$stmt->execute([$currentYear]);
$monthlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$labels = [];
$values = [];
$arabicMonths = [
    '01' => 'يناير', '02' => 'فبراير', '03' => 'مارس',
    '04' => 'أبريل', '05' => 'مايو', '06' => 'يونيو',
    '07' => 'يوليو', '08' => 'أغسطس', '09' => 'سبتمبر',
    '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر'
];

foreach ($monthlyData as $data) {
    $month = explode('-', $data['month'])[1];
    $labels[] = $arabicMonths[$month];
    $values[] = (float)$data['total'];
}

$page_title = '📊 مركز التقارير';
include '../../includes/tw_layout_start.php';
?>

<!-- إضافة Boxicons -->
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

<div class="mb-10">
    <h1 class="text-4xl font-extrabold tracking-tight mb-2 flex items-center gap-3">
        <i class="bx bx-pie-chart-alt-2 text-blue-600"></i> مركز التقارير
    </h1>
    <p class="text-gray-600 text-lg">إحصائيات التحصيل المالي الشهري بالريال السعودي (SAR)</p>
</div>

<!-- كروت احصائيات -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-12">
    <!-- إجمالي السنة -->
    <div class="relative bg-white/70 dark:bg-gray-900/60 backdrop-blur-lg rounded-3xl p-8 shadow-lg hover:shadow-2xl transition-all hover:-translate-y-1">
        <div class="flex justify-between items-center">
            <div>
                <p class="text-gray-500 text-sm">إجمالي السنة</p>
                <h2 class="text-4xl font-extrabold mt-2 text-gray-900 dark:text-white">
                    <?php echo number_format(array_sum($values), 2) . " ر.س"; ?>
                </h2>
            </div>
            <div class="p-4 rounded-full bg-gradient-to-br from-green-400 to-green-600 text-white shadow-lg">
                <i class="bx bx-wallet text-3xl"></i>
            </div>
        </div>
    </div>

    <!-- أعلى شهر -->
    <div class="relative bg-white/70 dark:bg-gray-900/60 backdrop-blur-lg rounded-3xl p-8 shadow-lg hover:shadow-2xl transition-all hover:-translate-y-1">
        <div class="flex justify-between items-center">
            <div>
                <p class="text-gray-500 text-sm">أعلى شهر</p>
                <h2 class="text-2xl font-bold mt-2 text-gray-900 dark:text-white">
                    <?php
                        if ($values) {
                            $maxVal = max($values);
                            $maxMonth = $labels[array_search($maxVal, $values)];
                            echo $maxMonth . " — " . number_format($maxVal, 2) . " ر.س";
                        } else {
                            echo 'لا توجد بيانات';
                        }
                    ?>
                </h2>
            </div>
            <div class="p-4 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 text-white shadow-lg">
                <i class="bx bx-crown text-3xl"></i>
            </div>
        </div>
    </div>

    <!-- عدد الأشهر -->
    <div class="relative bg-white/70 dark:bg-gray-900/60 backdrop-blur-lg rounded-3xl p-8 shadow-lg hover:shadow-2xl transition-all hover:-translate-y-1">
        <div class="flex justify-between items-center">
            <div>
                <p class="text-gray-500 text-sm">عدد الأشهر</p>
                <h2 class="text-4xl font-extrabold mt-2 text-gray-900 dark:text-white">
                    <?php echo count($values); ?> شهر
                </h2>
            </div>
            <div class="p-4 rounded-full bg-gradient-to-br from-purple-400 to-purple-600 text-white shadow-lg">
                <i class="bx bx-calendar text-3xl"></i>
            </div>
        </div>
    </div>
</div>


<div class="flex flex-wrap gap-4 items-center justify-start mb-10">
    <!-- زر العودة للملف المالي -->
    <a href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/reports/financial.php"
       class="flex items-center px-6 py-3 rounded-xl 
              bg-gradient-to-r from-indigo-500 to-purple-600 text-white shadow-lg 
              hover:scale-105 transition-transform duration-200 gap-2">
        <i class="bx bx-credit-card text-xl"></i>
        <span class="text-base font-medium">الملف المالي</span>
    </a>

    <!-- زر تنزيل Excel -->
    <a href="?export=excel"
       class="flex items-center px-6 py-3 rounded-xl 
              bg-gradient-to-r from-green-500 to-emerald-600 text-white shadow-lg 
              hover:scale-105 transition-transform duration-200 gap-2">
        <i class="bx bxs-file-export text-xl"></i>
        <span class="text-base font-medium">تحميل Excel</span>
    </a>
</div>


<!-- الرسم البياني -->
<div class="bg-white dark:bg-gray-800 rounded-3xl shadow-xl p-6">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-2xl font-bold flex items-center gap-2">
            <i class="bx bx-bar-chart-alt-2 text-blue-500"></i> الرسم البياني للتحصيل الشهري
        </h2>
    </div>

    <div class="h-[480px]">
        <canvas id="revenueChart"></canvas>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const labels = <?php echo json_encode($labels, JSON_UNESCAPED_UNICODE); ?>;
    const values = <?php echo json_encode($values); ?>;

    const ctx = document.getElementById('revenueChart').getContext('2d');
    new Chart(ctx, {
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'التحصيل الشهري (ر.س)',
                    data: values,
                    backgroundColor: 'rgba(59,130,246,0.6)',
                    borderColor: 'rgba(59,130,246,1)',
                    borderWidth: 1,
                    borderRadius: 10
                },
                {
                    type: 'line',
                    label: 'الاتجاه',
                    data: values,
                    borderColor: 'rgba(236,72,153,1)',
                    backgroundColor: 'rgba(236,72,153,0.2)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.35
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return new Intl.NumberFormat('ar-SA', { style: 'currency', currency: 'SAR' }).format(value);
                        }
                    }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return new Intl.NumberFormat('ar-SA', { style: 'currency', currency: 'SAR' }).format(context.raw);
                        }
                    }
                }
            }
        }
    });
});
</script>

<?php include '../../includes/tw_layout_end.php'; ?>
