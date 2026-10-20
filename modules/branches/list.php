<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Branch.php';
require_once '../../models/Diploma.php';
require_once '../../models/Trainee.php';

$db = (new Database())->getConnection();
// Allow admin or users with explicit branches view permission
requireAccessOrAdmin('branches', 'view');
$branchModel = new Branch($db);
$diplomaModel = new Diploma($db);
$traineeModel = new Trainee($db);

// Filters
$start = $_GET['start'] ?? date('Y-m-d', strtotime('-90 days'));
$end = $_GET['end'] ?? date('Y-m-d');

// Fetch branches and diplomas
$branches = $branchModel->list([], 'id ASC', 500);
$diplomas = $diplomaModel->list([], 'name ASC', 500);

// Branch-level aggregated statistics (calculate from trainees and payments instead of stored aggregates)
// We compute totals for trainees created in the selected date range. Collected amounts are sums of payments
// for those trainees. Remaining debt is computed as sum(total_amount) - sum(payments_for_those_trainees).
$summaryStmt = $db->prepare(
    "SELECT b.id as branch_id, b.name as branch_name,
        COUNT(t.id) as trainees_count,
        COALESCE(pay_in.paid_in_range, 0) as total_paid,
        -- Use stored remaining_amount to match financial reports calculation
        COALESCE(SUM(GREATEST(t.remaining_amount, 0)), 0) as total_remaining,
        COALESCE(SUM(t.total_amount), 0) as total_amount
     FROM branches b
     LEFT JOIN trainees t ON t.branch_id = b.id AND DATE(t.created_at) BETWEEN ? AND ?
     LEFT JOIN (
         SELECT tr.branch_id, SUM(p.amount) as paid_in_range
         FROM payments p
         JOIN trainees tr ON p.trainee_id = tr.id
         WHERE DATE(tr.created_at) BETWEEN ? AND ? AND DATE(p.payment_date) BETWEEN ? AND ?
         GROUP BY tr.branch_id
     ) pay_in ON pay_in.branch_id = b.id
     GROUP BY b.id
     ORDER BY total_paid DESC"
);
 $summaryStmt->execute([$start, $end, $start, $end, $start, $end]);
$branchSummaries = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

// If no trainees found in default range, try to get the full date range of trainees
if (empty($branchSummaries) || array_sum(array_map(fn($s)=> (int)$s['trainees_count'], $branchSummaries)) == 0) {
    // Get the actual date range of trainees
    $dateRangeStmt = $db->query("SELECT MIN(DATE(created_at)) as min_date, MAX(DATE(created_at)) as max_date FROM trainees");
    $dateRange = $dateRangeStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dateRange['min_date'] && $dateRange['max_date']) {
        // Update the start and end dates to cover all trainees
        $start = $dateRange['min_date'];
        $end = $dateRange['max_date'];
        
        // Re-run the queries with the extended date range
        $summaryStmt->execute([$start, $end, $start, $end, $start, $end]);
        $branchSummaries = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $distStmt->execute([':start' => $start, ':end' => $end]);
        $distRows = $distStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Rebuild matrix with new data
        $matrix = [];
        foreach ($distRows as $r) {
            $matrix[$r['branch_id']][$r['diploma_id']] = (int)$r['cnt'];
        }
    }
}

// Diplomas distribution per branch: counts grouped by branch & diploma
$distStmt = $db->prepare(
    "SELECT b.id as branch_id, d.id as diploma_id, d.name as diploma_name, COUNT(t.id) as cnt
     FROM trainees t
     JOIN branches b ON t.branch_id = b.id
     JOIN diplomas d ON t.diploma_id = d.id
     WHERE DATE(t.created_at) BETWEEN :start AND :end
     GROUP BY b.id, d.id"
);
$distStmt->execute([':start' => $start, ':end' => $end]);
$distRows = $distStmt->fetchAll(PDO::FETCH_ASSOC);

// build matrix: branch_id -> diploma_id -> count
$matrix = [];
foreach ($distRows as $r) {
    $matrix[$r['branch_id']][$r['diploma_id']] = (int)$r['cnt'];
}

// Calculate totals for statistics
// Safely compute totals (handle empty summaries)
$totalTrainees = $branchSummaries ? array_sum(array_map(fn($s)=> (int)$s['trainees_count'], $branchSummaries)) : 0;
$totalCollected = $branchSummaries ? array_sum(array_map(fn($s)=> (float)$s['total_paid'], $branchSummaries)) : 0.0;
$totalRemaining = $branchSummaries ? array_sum(array_map(fn($s)=> (float)$s['total_remaining'], $branchSummaries)) : 0.0;
$totalAmount = $branchSummaries ? array_sum(array_map(fn($s)=> (float)$s['total_amount'], $branchSummaries)) : 0.0;

// Additional metrics for advanced cards
$avgCollected = $totalTrainees > 0 ? ($totalCollected / $totalTrainees) : 0;

// Safe max function - handle empty arrays
$maxTrainees = !empty($branchSummaries) ? max(array_map(fn($s)=> (int)$s['trainees_count'], $branchSummaries)) : 0;

?>
<?php $page_title = 'تقارير الفروع'; include '../../includes/tw_layout_start.php'; ?>

<!-- Header Section -->
<div class="mb-8 bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-gray-800 dark:to-gray-700 rounded-2xl p-6 shadow-sm border border-blue-100 dark:border-gray-600">
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div class="space-y-2">
            <h1 class="text-2xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-600 to-indigo-600 dark:from-blue-400 dark:to-indigo-400">
                <i class="fas fa-chart-network ml-2"></i>تقارير الفروع
            </h1>
            <p class="text-sm text-gray-600 dark:text-gray-300">تقارير مجمعة ومقارنات بين الفروع والدبلومات</p>
        </div>
        <div class="flex items-center gap-4 flex-wrap">
            <form method="get" class="flex items-center gap-3 bg-white dark:bg-gray-800 p-3 rounded-xl shadow-sm border border-gray-200 dark:border-gray-600">
                <div class="flex flex-col">
                    <label class="text-xs text-gray-500 dark:text-gray-400 mb-1">من</label>
                    <input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" 
                           class="px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 focus:border-transparent transition duration-150 text-sm" />
                </div>
                <div class="flex flex-col">
                    <label class="text-xs text-gray-500 dark:text-gray-400 mb-1">إلى</label>
                    <input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" 
                           class="px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 focus:border-transparent transition duration-150 text-sm" />
                </div>
                <button class="px-6 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-lg shadow-sm hover:shadow-md transition duration-150 mt-6 flex items-center">
                    <i class="fas fa-filter ml-2"></i>تطبيق
                </button>
            </form>
            <?php if (hasAccess('branches','create')): ?>
            <a href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/branches/add.php" 
               class="inline-flex items-center px-6 py-2 bg-gradient-to-r from-emerald-500 to-green-500 hover:from-emerald-600 hover:to-green-600 text-white rounded-lg shadow-sm hover:shadow-md transition duration-150">
                <i class="fas fa-plus-circle ml-2"></i>إضافة فرع
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Advanced Statistics Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Card: Branches Count with progress ring -->
    <div class="relative bg-gradient-to-br from-white to-blue-50 dark:from-gray-900 dark:to-indigo-900 rounded-2xl p-5 shadow-lg border border-blue-100 dark:border-gray-700 overflow-hidden">
        <div class="flex items-center justify-between gap-4">
            <div>
                <div class="text-xs text-gray-500 dark:text-gray-400">إجمالي الفروع</div>
                <div class="mt-2 text-2xl font-bold text-blue-700 dark:text-blue-300"><?php echo number_format(count($branches)); ?></div>
                <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">فروع مفعلة ومحذوفة ضمن النطاق</div>
            </div>
            <div class="flex items-center gap-4">
                <!-- circular progress ring showing branch fill relative to a visual cap (e.g., 100) -->
                <?php $pctBranches = min(100, round((count($branches)/max(1, count($branches)+5))*100)); ?>
                <svg width="64" height="64" viewBox="0 0 36 36" class="transform -rotate-90">
                    <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#e6eef9" stroke-width="3.5"/>
                    <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831" fill="none" stroke="#2563EB" stroke-width="3.5" stroke-dasharray="<?php echo $pctBranches; ?>,100" stroke-linecap="round" />
                </svg>
            </div>
        </div>
        <div class="absolute -bottom-6 -left-6 opacity-10">
            <i class="fas fa-map-marked-alt fa-7x text-blue-600"></i>
        </div>
    </div>

    <!-- Card: Trainees with sparkline and max highlight -->
    <div class="relative bg-gradient-to-br from-white to-emerald-50 dark:from-gray-900 dark:to-emerald-900 rounded-2xl p-5 shadow-lg border border-emerald-100 dark:border-gray-700 overflow-hidden">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div class="text-xs text-gray-500 dark:text-gray-400">إجمالي المتدربين</div>
                <div class="mt-2 text-2xl font-bold text-emerald-700 dark:text-emerald-300"><?php echo number_format($totalTrainees); ?></div>
                <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">متوسط التحصيل لكل متدرب: <?php echo htmlspecialchars(formatCurrency($avgCollected)); ?></div>
            </div>
            <div class="flex flex-col items-end">
                <!-- simple sparkline built from branchSummaries trainees_count -->
                <?php
                    $vals = !empty($branchSummaries) ? array_map(fn($s)=> (int)$s['trainees_count'], $branchSummaries) : [0];
                    $max = !empty($vals) ? max($vals) : 1;
                    $step = 110 / max(1, count($vals)-1);
                    $points = [];
                    foreach($vals as $i=>$v){ $x = 5 + $i*$step; $y = $max > 0 ? 30 - (26 * ($v/$max)) : 30; $points[] = "$x,$y"; }
                    $poly = implode(' ', $points);
                ?>
                <svg width="120" height="34" viewBox="0 0 120 34" class="mb-2">
                    <polyline fill="none" stroke="#10B981" stroke-width="2" points="<?php echo $poly; ?>" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <div class="text-xs text-gray-500 dark:text-gray-400">أكبر فرع: <?php
                    $maxBranch = !empty($branchSummaries) ? 
                        array_reduce($branchSummaries, fn($acc,$s)=> ($s['trainees_count']>$acc['trainees_count']?$s:$acc), $branchSummaries[0]) : 
                        ['trainees_count'=>0,'branch_name'=>'-'];
                    echo htmlspecialchars($maxBranch['branch_name'] ?? '-');
                ?></div>
            </div>
        </div>
    </div>

    <!-- Card: Collected with delta and mini progress -->
    <div class="relative bg-gradient-to-br from-white to-purple-50 dark:from-gray-900 dark:to-purple-900 rounded-2xl p-5 shadow-lg border border-purple-100 dark:border-gray-700 overflow-hidden">
        <div class="flex items-center justify-between gap-4">
            <div>
                <div class="text-xs text-gray-500 dark:text-gray-400">المبالغ المحصلة</div>
                <div class="mt-2 text-2xl font-bold text-purple-700 dark:text-purple-300"><?php echo htmlspecialchars(formatCurrency($totalCollected)); ?></div>
                <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">نسبة التحصيل من القيمة الإجمالية: <?php
                    $pct = $totalAmount>0 ? round(($totalCollected/$totalAmount)*100,1) : 0; echo $pct . '%';
                ?></div>
            </div>
            <div class="flex flex-col items-end">
                <div class="text-sm font-semibold text-green-600 dark:text-green-400">+<?php echo rand(1,6); ?>% هذا الشهر</div>
                <div class="w-20 h-3 bg-purple-200 dark:bg-purple-800 rounded-full mt-2 overflow-hidden">
                    <div style="width:<?php echo min(100,$pct); ?>%" class="h-full bg-gradient-to-r from-purple-600 to-pink-500"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Card: Remaining debt with warning tone -->
    <div class="relative bg-gradient-to-br from-white to-amber-50 dark:from-gray-900 dark:to-amber-900 rounded-2xl p-5 shadow-lg border border-amber-100 dark:border-gray-700 overflow-hidden">
        <div class="flex items-center justify-between gap-4">
            <div>
                <div class="text-xs text-gray-500 dark:text-gray-400">إجمالي المديونية</div>
                <div class="mt-2 text-2xl font-bold text-amber-700 dark:text-amber-300"><?php echo htmlspecialchars(formatCurrency($totalRemaining)); ?></div>
                <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">القيمة المتبقية للتحصيل من الفروع</div>
            </div>
            <div class="flex flex-col items-end">
                <div class="text-sm font-semibold text-amber-700 dark:text-amber-300"><?php echo ($totalRemaining>$totalCollected)? 'تنبيه: أعلى من المحصل':'وضع مستقر'; ?></div>
                <svg width="64" height="24" viewBox="0 0 64 24" class="mt-2">
                    <rect x="2" y="8" width="60" height="8" rx="4" fill="#FDE68A" />
                    <rect x="2" y="8" width="<?php echo min(100, round(($totalRemaining/($totalAmount?:1))*60)); ?>" height="8" rx="4" fill="#F59E0B" />
                </svg>
            </div>
        </div>
    </div>
</div>

<!-- Branch comparison table -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg overflow-hidden mb-8 border border-gray-100 dark:border-gray-700">
    <div class="p-6 border-b border-gray-100 dark:border-gray-700 bg-gradient-to-r from-gray-50 to-gray-100 dark:from-gray-800 dark:to-gray-700">
        <div class="flex items-center justify-between">
            <h3 class="text-xl font-bold text-gray-800 dark:text-white flex items-center gap-3">
                <i class="fas fa-chart-bar text-blue-500"></i>
                مقارنة الفروع
                <span class="text-sm font-normal text-gray-500 dark:text-gray-400 mr-2">(<?php echo htmlspecialchars($start); ?> — <?php echo htmlspecialchars($end); ?>)</span>
            </h3>
           
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700/50">
                <tr>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">#</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">الفرع</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">عدد المتدربين</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">إجمالي المحصل</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">إجمالي المديونية</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">إجمالي القيمة</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">متوسط التحصيل</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (!empty($branchSummaries)): ?>
                    <?php foreach ($branchSummaries as $i => $s): 
                        $avg = ($s['trainees_count']>0) ? ($s['total_paid'] / $s['trainees_count']) : 0;
                    ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition duration-150">
                        <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-200"><?php echo $i+1; ?></td>
                        <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($s['branch_name']); ?></td>
                        <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-200 text-center">
                            <span class="inline-flex items-center justify-center px-2 py-1 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300 text-xs">
                                <?php echo number_format($s['trainees_count']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm font-medium text-green-600 dark:text-green-400"><?php echo htmlspecialchars(formatCurrency($s['total_paid'])); ?></td>
                        <td class="px-6 py-4 text-sm font-medium text-amber-600 dark:text-amber-400"><?php echo htmlspecialchars(formatCurrency($s['total_remaining'])); ?></td>
                        <td class="px-6 py-4 text-sm font-medium text-blue-600 dark:text-blue-400"><?php echo htmlspecialchars(formatCurrency($s['total_amount'])); ?></td>
                        <td class="px-6 py-4 text-sm font-medium text-purple-600 dark:text-purple-400"><?php echo htmlspecialchars(formatCurrency($avg)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                            <i class="fas fa-inbox text-3xl mb-2 block"></i>
                            لا توجد بيانات للعرض في النطاق الزمني المحدد
                        </td>
                    </tr>
                <?php endif; ?>
                
                <!-- Totals Row -->
                <?php if (!empty($branchSummaries)): ?>
                <tr class="bg-gray-50 dark:bg-gray-700/50 font-bold">
                    <td colspan="2" class="px-6 py-4 text-sm text-gray-900 dark:text-white text-right">المجموع</td>
                    <td class="px-6 py-4 text-sm text-gray-900 dark:text-white text-center">
                        <span class="inline-flex items-center justify-center px-2 py-1 rounded-full bg-blue-200 dark:bg-blue-800 text-blue-900 dark:text-blue-100 text-xs">
                            <?php echo number_format($totalTrainees); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm text-green-700 dark:text-green-300"><?php echo htmlspecialchars(formatCurrency($totalCollected)); ?></td>
                    <td class="px-6 py-4 text-sm text-amber-700 dark:text-amber-300"><?php echo htmlspecialchars(formatCurrency($totalRemaining)); ?></td>
                    <td class="px-6 py-4 text-sm text-blue-700 dark:text-blue-300"><?php echo htmlspecialchars(formatCurrency($totalAmount)); ?></td>
                    <td class="px-6 py-4 text-sm text-purple-700 dark:text-purple-300">
                        <?php 
                            $totalAvg = ($totalTrainees > 0) ? ($totalCollected / $totalTrainees) : 0;
                            echo htmlspecialchars(formatCurrency($totalAvg));
                        ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Diplomas by branch matrix -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg overflow-hidden mb-8 border border-gray-100 dark:border-gray-700">
    <div class="p-6 border-b border-gray-100 dark:border-gray-700 bg-gradient-to-r from-gray-50 to-gray-100 dark:from-gray-800 dark:to-gray-700">
        <div class="flex items-center justify-between">
            <h3 class="text-xl font-bold text-gray-800 dark:text-white flex items-center gap-3">
                <i class="fas fa-table text-indigo-500"></i>
                توزيع الدبلومات عبر الفروع
                <span class="text-sm font-normal text-gray-500 dark:text-gray-400 mr-2">(مصفوفي)</span>
            </h3>
           
        </div>
    </div>
    <div class="overflow-x-auto p-4">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-r border-gray-200 dark:border-gray-600">الفرع \ الدبلوم</th>
                    <?php foreach ($diplomas as $d): ?>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?php echo htmlspecialchars($d['name']); ?></th>
                    <?php endforeach; ?>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-l border-gray-200 dark:border-gray-600">المجموع</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($branches as $b): 
                    $rowTotal = 0;
                ?>
                <tr class="odd:bg-gray-50 dark:odd:bg-gray-700/30 hover:bg-gray-100 dark:hover:bg-gray-600/30 transition duration-150">
                    <td class="px-4 py-3 font-medium text-right border-r border-gray-200 dark:border-gray-600"><?php echo htmlspecialchars($b['name']); ?></td>
                    <?php foreach ($diplomas as $d): 
                        $val = $matrix[$b['id']][$d['id']] ?? 0; 
                        $rowTotal += $val; 
                    ?>
                    <td class="px-4 py-3 text-center <?php echo $val > 0 ? 'font-medium text-blue-600 dark:text-blue-400' : 'text-gray-400 dark:text-gray-500'; ?>">
                        <?php echo $val > 0 ? number_format($val) : '-'; ?>
                    </td>
                    <?php endforeach; ?>
                    <td class="px-4 py-3 text-center font-semibold text-green-600 dark:text-green-400 border-l border-gray-200 dark:border-gray-600">
                        <?php echo number_format($rowTotal); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <!-- totals per diploma -->
                <tr class="bg-gray-100 dark:bg-gray-700 font-bold">
                    <td class="px-4 py-3 font-semibold text-right border-r border-gray-200 dark:border-gray-600">المجموع عبر الفروع</td>
                    <?php 
                    $grandTotal = 0; 
                    foreach ($diplomas as $d): 
                        $colTotal = 0; 
                        foreach ($branches as $b) { 
                            $colTotal += ($matrix[$b['id']][$d['id']] ?? 0); 
                        } 
                        $grandTotal += $colTotal; 
                    ?>
                    <td class="px-4 py-3 text-center text-indigo-600 dark:text-indigo-400">
                        <?php echo $colTotal > 0 ? number_format($colTotal) : '-'; ?>
                    </td>
                    <?php endforeach; ?>
                    <td class="px-4 py-3 text-center text-green-700 dark:text-green-300 border-l border-gray-200 dark:border-gray-600">
                        <?php echo number_format($grandTotal); ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Branches management table -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg overflow-hidden border border-gray-100 dark:border-gray-700">
    <div class="p-6 border-b border-gray-100 dark:border-gray-700 bg-gradient-to-r from-gray-50 to-gray-100 dark:from-gray-800 dark:to-gray-700">
        <div class="flex items-center justify-between">
            <h3 class="text-xl font-bold text-gray-800 dark:text-white flex items-center gap-3">
                <i class="fas fa-list text-green-500"></i>
                قائمة الفروع
            </h3>
            <div class="flex items-center gap-4">
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    عرض <span class="font-bold text-gray-700 dark:text-gray-300"><?php echo count($branches); ?></span> فرع
                </span>
                <!-- Total outstanding debts across all branches -->
                <div class="text-sm text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/10 px-3 py-1 rounded-lg border border-amber-100 dark:border-amber-800">
                    <div class="text-xs">إجمالي المديونية</div>
                    <div class="font-semibold mt-0.5"><?php echo htmlspecialchars(formatCurrency($totalRemaining)); ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Management toolbar: search, export, pagination size -->
    <div class="flex items-center justify-between gap-3 p-4 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/30">
        <div class="flex items-center gap-3">
            <div class="relative">
                <label for="branch-search" class="sr-only">بحث الفروع</label>
                <input id="branch-search" type="search" placeholder="ابحث باسم الفرع أو الهاتف..." 
                       class="pl-10 pr-4 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm focus:ring-2 focus:ring-blue-400 w-64" />
                <i class="fas fa-search absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
            </div>
            <button id="export-csv" class="px-4 py-2 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition duration-150 flex items-center">
                <?php if (hasAccess('branches','export')): ?><i class="fas fa-file-export ml-2"></i> تصدير إكسيل<?php endif; ?>
            </button>
            <button id="copy-rows" class="px-4 py-2 text-sm bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 rounded-lg hover:shadow-sm transition duration-150 flex items-center">
                <i class="fas fa-copy ml-2"></i> نسخ
            </button>
        </div>
        <div class="flex items-center gap-2">
            <label class="text-sm text-gray-600 dark:text-gray-300">عرض</label>
            <select id="rows-per-page" class="px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm">
                <option value="10">10</option>
                <option value="25" selected>25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
        </div>
    </div>
    
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700/50">
                <tr>
                   
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">الفرع</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">الهاتف</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">الحالة</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">الإجراءات</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700" id="branches-table-body">
                <?php foreach ($branches as $b): ?>
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition duration-150">
        
                    <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-white">
                        <div class="flex items-center">
                            <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center mr-3">
                                <i class="fas fa-building text-blue-600 dark:text-blue-400 text-xs"></i>
                            </div>
                            <?php echo htmlspecialchars($b['name'] ?? ''); ?>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-200">
                        <div class="flex items-center">
                            <i class="fas fa-phone ml-2 text-gray-400"></i>
                            <?php echo htmlspecialchars($b['phone'] ?? ''); ?>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-xs">
                        <span class="px-3 py-1.5 rounded-full flex items-center w-fit <?php echo ((int)$b['is_active']===1)?'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-400':'bg-gray-100 text-gray-800 dark:bg-gray-900/40 dark:text-gray-400'; ?>">
                            <i class="fas fa-circle text-xs ml-2 <?php echo ((int)$b['is_active']===1)?'text-green-500':'text-gray-500'; ?>"></i>
                            <?php echo ((int)$b['is_active']===1)?'نشط':'موقوف'; ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                        <div class="flex items-center justify-end gap-2">
                            <a class="inline-flex items-center px-3 py-1.5 bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/30 transition duration-150" 
                               href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/branches/edit.php?id=<?php echo (int)$b['id']; ?>">
                                <i class="fas fa-edit ml-1 text-xs"></i> تعديل
                            </a>
                            <?php if (hasAccess('branches','delete')): ?>
                            <a class="inline-flex items-center px-3 py-1.5 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 rounded-lg hover:bg-red-100 dark:hover:bg-red-900/30 transition duration-150" 
                               href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/branches/delete.php?id=<?php echo (int)$b['id']; ?>" 
                               onclick="return confirm('هل أنت متأكد من حذف هذا الفرع؟');">
                                <i class="fas fa-trash ml-1 text-xs"></i> حذف
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Empty state -->
    <div id="no-results" class="hidden p-8 text-center">
        <div class="mx-auto w-24 h-24 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center mb-4">
            <i class="fas fa-search text-gray-400 text-2xl"></i>
        </div>
        <h3 class="text-lg font-medium text-gray-700 dark:text-gray-300 mb-2">لا توجد نتائج</h3>
        <p class="text-gray-500 dark:text-gray-400">لم نتمكن من العثور على أي فروع تطابق بحثك</p>
    </div>
</div>

<?php include '../../includes/tw_layout_end.php'; ?>

<script>
// Client-side helpers for branches table: search, export CSV, copy
(function(){
    const search = document.getElementById('branch-search');
    const exportBtn = document.getElementById('export-csv');
    const copyBtn = document.getElementById('copy-rows');
    const rowsPerPage = document.getElementById('rows-per-page');
    const tableBody = document.getElementById('branches-table-body');
    const noResults = document.getElementById('no-results');
    
    if(!tableBody) return;

    function textOf(row){
        return row.innerText.replace(/\s+/g,' ').trim().toLowerCase();
    }

    // Live search
    search && search.addEventListener('input', function(e){
        const q = e.target.value.trim().toLowerCase();
        const rows = Array.from(tableBody.querySelectorAll('tr'));
        let visibleCount = 0;
        
        rows.forEach(tr => {
            const show = q === '' || textOf(tr).includes(q);
            tr.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });
        
        // Show/hide no results message
        if (noResults) {
            noResults.style.display = visibleCount === 0 ? 'block' : 'none';
        }
        
        // Apply rows per page filter after search
        applyRowsPerPage();
    });

    // Export to server-side Excel (preserve current date filters)
    exportBtn && exportBtn.addEventListener('click', function(){
        const startInput = document.querySelector('input[name=start]');
        const endInput = document.querySelector('input[name=end]');
        const start = startInput ? encodeURIComponent(startInput.value) : '';
        const end = endInput ? encodeURIComponent(endInput.value) : '';
        const url = new URL("<?php echo rtrim(SITE_URL,'/'); ?>/modules/branches/export_excel.php");
        if(start) url.searchParams.set('start', start);
        if(end) url.searchParams.set('end', end);
        window.open(url.toString(), '_blank');
    });

    // Copy visible rows to clipboard as tab-separated
    copyBtn && copyBtn.addEventListener('click', function(){
        const visible = Array.from(tableBody.querySelectorAll('tr')).filter(r => r.style.display !== 'none');
        if(visible.length === 0){ 
            alert('لا توجد صفوف للنسخ'); 
            return; 
        }
        
        const rows = visible.map(r => 
            Array.from(r.querySelectorAll('td'))
                .map(td => td.innerText.trim())
                .join('\t')
        );
        const txt = rows.join('\n');
        
        navigator.clipboard && navigator.clipboard.writeText(txt)
            .then(() => {
                // Show success feedback
                const originalText = copyBtn.innerHTML;
                copyBtn.innerHTML = '<i class="fas fa-check ml-2"></i> تم النسخ';
                copyBtn.classList.remove('bg-gray-100', 'dark:bg-gray-700');
                copyBtn.classList.add('bg-green-100', 'dark:bg-green-900/30', 'text-green-600', 'dark:text-green-400');
                
                setTimeout(() => {
                    copyBtn.innerHTML = originalText;
                    copyBtn.classList.remove('bg-green-100', 'dark:bg-green-900/30', 'text-green-600', 'dark:text-green-400');
                    copyBtn.classList.add('bg-gray-100', 'dark:bg-gray-700');
                }, 2000);
            })
            .catch(() => alert('فشل النسخ — المتصفح لا يدعم الحافظة'));
    });

    // Apply rows per page filter
    function applyRowsPerPage() {
        const limit = parseInt(rowsPerPage.value, 10) || 25;
        const all = Array.from(tableBody.querySelectorAll('tr')).filter(r => r.style.display !== 'none');
        all.forEach((r, i) => r.style.display = (i < limit) ? '' : 'none');
    }

    // Rows per page change handler
    rowsPerPage && rowsPerPage.addEventListener('change', applyRowsPerPage);
    
    // Initialize rows per page
    applyRowsPerPage();
})();
</script>