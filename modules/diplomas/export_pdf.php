<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../models/Diploma.php';
require_once '../../vendor/autoload.php';

// Ensure session started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// require admin or explicit diplomas export permission
requireAccessOrAdmin('diplomas', 'export');
$db = (new Database())->getConnection();
$diplomaModel = new Diploma($db);

// start buffering
ob_start();

// Gather filters from GET (same as list)
$filters = [];
if (isset($_GET['search']) && $_GET['search'] !== '') $filters['search'] = $_GET['search'];
if (isset($_GET['cost_min']) && $_GET['cost_min'] !== '') $filters['cost_min'] = (float)$_GET['cost_min'];
if (isset($_GET['cost_max']) && $_GET['cost_max'] !== '') $filters['cost_max'] = (float)$_GET['cost_max'];
if (isset($_GET['duration_min']) && $_GET['duration_min'] !== '') $filters['duration_min'] = (int)$_GET['duration_min'];
if (isset($_GET['duration_max']) && $_GET['duration_max'] !== '') $filters['duration_max'] = (int)$_GET['duration_max'];
if (isset($_GET['status']) && $_GET['status'] !== '') $filters['status'] = $_GET['status'];

$diplomas = $diplomaModel->list($filters, 'id DESC', 10000);

// build HTML
$html = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family: DejaVu Sans, Tahoma, Arial, sans-serif;direction:rtl}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:6px;text-align:right}th{background:#f4f4f4}</style></head><body>';
$html .= '<h2 style="text-align:center">قائمة الدبلومات</h2>';
$html .= '<table dir="rtl"><thead><tr><th>#</th><th>اسم الدبلوم</th><th>المدة</th><th>التكلفة</th><th>المسجلين</th><th>الحالة</th></tr></thead><tbody>';

foreach ($diplomas as $d) {
    $html .= '<tr>';
    $html .= '<td>' . (int)$d['id'] . '</td>';
    $html .= '<td>' . htmlspecialchars($d['name']) . '</td>';
    $html .= '<td>' . (int)$d['duration'] . '</td>';
    $html .= '<td>' . number_format((float)$d['cost'], 2) . ' ر.س</td>';
    $html .= '<td>' . (int)($d['current_enrollments'] ?? 0) . '</td>';
    $html .= '<td>' . (((int)$d['is_active'] === 1) ? 'نشط' : 'غير نشط') . '</td>';
    $html .= '</tr>';
}

$html .= '</tbody></table></body></html>';

// Clear buffers before sending binary
if (ob_get_length()) ob_clean();

// Create mPDF instance
$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'orientation' => 'P',
    'tempDir' => __DIR__ . '/../../temp/mpdf'
]);

$mpdf->SetDirectionality('rtl');
$mpdf->WriteHTML($html);

$filename = 'diplomas_' . date('Y-m-d_H-i-s') . '.pdf';

// Output for download
$mpdf->Output($filename, 'D');
exit;

?>
