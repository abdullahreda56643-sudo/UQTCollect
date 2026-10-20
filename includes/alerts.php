<?php
// Reusable alert partial
// Expected session payload: $_SESSION['flash'] = [ 'type'=>'success'|'error'|'info', 'title'=>'...', 'message'=>'...', 'autoHide'=>true|false ];
$flash = $_SESSION['flash'] ?? null;
// Fallback to legacy keys for backwards compatibility
if (!$flash) {
    if (!empty($_SESSION['success_message'])) {
        $flash = ['type' => 'success', 'title' => 'تم بنجاح', 'message' => $_SESSION['success_message'], 'autoHide' => true];
    } elseif (!empty($_SESSION['error_message'])) {
        $flash = ['type' => 'error', 'title' => 'حدث خطأ', 'message' => $_SESSION['error_message'], 'autoHide' => false];
    } elseif (!empty($_SESSION['info_message'])) {
        $flash = ['type' => 'info', 'title' => 'معلومة', 'message' => $_SESSION['info_message'], 'autoHide' => true];
    }
}
if ($flash):
    $type = $flash['type'] ?? 'info';
    $title = $flash['title'] ?? ($type === 'success' ? 'تم بنجاح' : ($type === 'error' ? 'حدث خطأ' : 'معلومة'));
    $message = $flash['message'] ?? '';
    $autoHide = array_key_exists('autoHide', $flash) ? (bool)$flash['autoHide'] : true;
    $color = 'blue';
    $icon = 'fa-info-circle';
    $timeout = 3800;
    if ($type === 'success') { $color = 'green'; $icon = 'fa-check-circle'; $timeout = 3200; }
    if ($type === 'error') { $color = 'red'; $icon = 'fa-exclamation-circle'; $timeout = 5000; }
?>
<div class="fixed top-20 right-4 z-[60] space-y-2" dir="rtl">
    <div x-data="{ open: true }" x-show="open" <?php if ($autoHide): ?> x-init="setTimeout(()=>open=false, <?php echo (int)$timeout; ?>)" <?php endif; ?>
         role="status" aria-live="polite" tabindex="-1"
         class="max-w-sm bg-white dark:bg-gray-800 border border-<?php echo $color; ?>-200 dark:border-<?php echo $color; ?>-800 rounded-xl shadow-2xl overflow-hidden animate-[fadeIn_.4s_ease-out]">
        <div class="flex items-start p-4">
            <div class="ml-3 mt-0.5 text-<?php echo $color; ?>-600"><i class="fas <?php echo $icon; ?>"></i></div>
            <div class="mr-3 flex-1">
                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100"><?php echo htmlspecialchars($title); ?></p>
                <p class="text-sm text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($message); ?></p>
            </div>
            <button class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" @click="open=false" aria-label="إغلاق الإشعار">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div class="h-1 bg-<?php echo $color; ?>-200 dark:bg-<?php echo $color; ?>-900">
            <div class="h-1 bg-<?php echo $color; ?>-600 dark:bg-<?php echo $color; ?>-500" style="animation: shrink <?php echo max(2.8, ($timeout/1000)-0.2); ?>s linear forwards"></div>
        </div>
    </div>
</div>
<style>
    @keyframes shrink { from { width:100%; } to { width:0%; } }
</style>
<?php
    // clear both new and legacy keys
    unset($_SESSION['flash']);
    unset($_SESSION['flash_message']);
    unset($_SESSION['success_message']);
    unset($_SESSION['error_message']);
    unset($_SESSION['info_message']);
endif;
