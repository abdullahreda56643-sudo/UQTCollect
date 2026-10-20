<?php
require_once '../../includes/communication_helpers.php';

function render_communications_list($communications, $traineeInfo = null, $includeSearch = false) {
    $traineeName = $traineeInfo['full_name'] ?? 'المتدرب';
    $traineeId = $traineeInfo['id'] ?? '';
    
    ob_start();
    ?>
    <div class="space-y-6">
        <!-- Header -->
        <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-xl p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold mb-2">سجل التواصل</h2>
                    <?php if ($traineeId): ?>
                        <p class="opacity-90"><?php echo htmlspecialchars($traineeName); ?> - <?php echo htmlspecialchars($traineeId); ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($includeSearch): ?>
                    <form method="get" class="flex-shrink-0">
                        <input type="text" name="q" 
                               placeholder="ابحث في السجلات..." 
                               value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>"
                               class="px-4 py-2 rounded bg-white/10 text-white placeholder-white/70 border border-white/20">
                    </form>
                <?php else: ?>
                    <div class="text-right">
                        <div class="text-3xl font-bold"><?php echo count($communications); ?></div>
                        <div class="text-sm opacity-90">تواصل مسجل</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($communications)): ?>
            <div class="text-center py-12">
                <i class="fas fa-comments-slash text-4xl text-gray-400 mb-4"></i>
                <h3 class="text-lg font-medium text-gray-700 dark:text-gray-300 mb-2">لا توجد تواصلات مسجلة</h3>
                <p class="text-gray-500 dark:text-gray-400">لم يتم تسجيل أي تواصل مع هذا المتدرب بعد</p>
            </div>
        <?php else: ?>
            <!-- تحليل إحصائي للتواصلات -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <?php
                $totalSuccessful = 0;
                $totalPending = 0;
                $totalFailed = 0;
                $totalAttempts = 0;
                foreach ($communications as $c) {
                    $s = $c['status'] ?? 'pending';
                    if ($s === 'successful') $totalSuccessful++;
                    elseif ($s === 'pending') $totalPending++;
                    elseif ($s === 'failed') $totalFailed++;
                    $totalAttempts += isset($c['contact_count']) ? (int)$c['contact_count'] : 1;
                }
                ?>
                <div class="bg-white dark:bg-gray-700 rounded-lg p-4 text-center">
                    <div class="text-2xl font-bold text-green-600"><?php echo $totalSuccessful; ?></div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">تواصل ناجح</div>
                </div>
                <div class="bg-white dark:bg-gray-700 rounded-lg p-4 text-center">
                    <div class="text-2xl font-bold text-yellow-600"><?php echo $totalPending; ?></div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">قيد المتابعة</div>
                </div>
                <div class="bg-white dark:bg-gray-700 rounded-lg p-4 text-center">
                    <div class="text-2xl font-bold text-red-600"><?php echo $totalFailed; ?></div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">غير ناجح</div>
                </div>
                <div class="bg-white dark:bg-gray-700 rounded-lg p-4 text-center">
                    <div class="text-2xl font-bold text-blue-600"><?php echo $totalAttempts; ?></div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">محاولات التواصل</div>
                </div>
            </div>
            <div class="space-y-4">
                <?php foreach ($communications as $comm): ?>
                    <div class="bg-white dark:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-600 p-4 shadow-sm hover:shadow-md transition-shadow">
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex items-center gap-3">
                                <?php 
                                $statusColors = [
                                    'successful' => 'green',
                                    'pending' => 'yellow',
                                    'failed' => 'red',
                                    'no_response' => 'gray',
                                    'callback' => 'blue',
                                    'not_interested' => 'orange'
                                ];
                                $statusIcons = [
                                    'successful' => 'fas fa-check-circle',
                                    'pending' => 'fas fa-clock',
                                    'failed' => 'fas fa-times-circle',
                                    'no_response' => 'fas fa-phone-slash',
                                    'callback' => 'fas fa-phone-alt',
                                    'not_interested' => 'fas fa-ban'
                                ];
                                $statusTexts = [
                                    'successful' => 'تواصل ناجح',
                                    'pending' => 'قيد المتابعة',
                                    'failed' => 'تواصل غير ناجح',
                                    'no_response' => 'لا يرد',
                                    'callback' => 'طلب إعادة اتصال',
                                    'not_interested' => 'غير مهتم'
                                ];
                                
                                $contactTypes = [
                                    'communication' => 'تواصل',
                                    'phone' => 'اتصال هاتفي',
                                    'whatsapp' => 'واتساب',
                                    'visit' => 'زيارة شخصية',
                                    'email' => 'بريد إلكتروني',
                                    'sms' => 'رسالة نصية',
                                    'telegram' => 'تلغرام',
                                    'other' => 'وسيلة أخرى'
                                ];
                                
                                // Use unified translation functions from list.php
                                $resultText = function_exists('getResultLabel') ? getResultLabel($comm['result']) : ($comm['result'] ?? '');
                                $resultBadgeClass = function_exists('getResultBadgeClass') ? getResultBadgeClass($comm['result']) : 'px-1.5 py-0.5 rounded-md bg-gray-100 text-gray-800';
                                $contactTypeKey = strtolower(trim($comm['type'] ?? ''));
                                $contactTypeText = $contactTypes[$contactTypeKey] ?? ($comm['type'] ?? '');
                                $statusKey = strtolower(trim($comm['status'] ?? 'pending'));
                                $color = $statusColors[$statusKey] ?? 'gray';
                                $icon = $statusIcons[$statusKey] ?? 'fas fa-comment';
                                $statusText = $statusTexts[$statusKey] ?? 'تواصل';
                                ?>
                                <div class="w-10 h-10 bg-gradient-to-r from-<?php echo $color; ?>-500 to-<?php echo $color; ?>-600 rounded-full flex items-center justify-center text-white">
                                    <i class="<?php echo $icon; ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-semibold text-gray-900 dark:text-white flex items-center gap-2 flex-wrap">
                                        <span class="bg-<?php echo $color; ?>-100 dark:bg-<?php echo $color; ?>-900/30 text-<?php echo $color; ?>-800 dark:text-<?php echo $color; ?>-400 px-2 py-1 rounded">
                                            <?php echo htmlspecialchars($statusText); ?>
                                        </span>
                                        <?php if ($contactTypeText): ?>
                                            <span class="text-gray-600 dark:text-gray-300">
                                                <i class="fas fa-phone-alt ml-1"></i>
                                                <?php echo htmlspecialchars($contactTypeText); ?>
                                            </span>
                                        <?php endif; ?>
                                    </h4>
                                    
                                    <!-- تفاصيل التواصل -->
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-2 text-sm">
                                        <?php if (!empty($comm['employee_name'])): ?>
                                            <div class="flex items-center gap-1 text-gray-600 dark:text-gray-300">
                                                <i class="fas fa-user-tie text-gray-400"></i>
                                                <span>الموظف: <?php echo htmlspecialchars($comm['employee_name']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($resultText): ?>
                                            <div class="flex items-center gap-1 text-gray-600 dark:text-gray-300">
                                                <i class="fas fa-flag-checkered text-gray-400"></i>
                                                <span>النتيجة: <?php echo htmlspecialchars($resultText); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($comm['contact_count'])): ?>
                                            <div class="flex items-center gap-1 text-gray-600 dark:text-gray-300">
                                                <i class="fas fa-phone text-gray-400"></i>
                                                <span>عدد المحاولات: <?php echo (int)$comm['contact_count']; ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="bg-gray-100 dark:bg-gray-600 px-3 py-2 rounded-lg">
                                    <div class="font-semibold text-gray-900 dark:text-white">
                                        <?php
                                        $date = $comm['contact_date'] ?? date('Y-m-d', strtotime($comm['created_at']));
                                        try {
                                            $formatted = (new DateTime($date))->format('Y/m/d');
                                            echo htmlspecialchars($formatted);
                                        } catch (Exception $e) {
                                            echo htmlspecialchars($date);
                                        }
                                        ?>
                                    </div>
                                    <?php if (!empty($comm['contact_time'])): ?>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            <i class="far fa-clock ml-1"></i>
                                            <?php echo htmlspecialchars($comm['contact_time']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($comm['notes'])): ?>
                            <div class="bg-gray-50 dark:bg-gray-600 rounded-lg p-3 mt-2">
                                <div class="text-sm text-gray-600 dark:text-gray-300">
                                    <?php echo nl2br(htmlspecialchars($comm['notes'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($comm['next_follow_up'])): ?>
                            <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-600">
                                <div class="text-xs text-blue-600 dark:text-blue-400">
                                    <i class="fas fa-calendar-check ml-1"></i>
                                    متابعة قادمة: <?php echo htmlspecialchars($comm['next_follow_up']); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
?>