<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/Communication.php';

checkPermission('employee');

$db = (new Database())->getConnection();
$communicationModel = new Communication($db);

// استلام المعايير من الرابط
$searchTerm = trim($_GET['search'] ?? '');
$filters = [
    'type' => $_GET['type'] ?? '',
    'result' => $_GET['result'] ?? '',
    'start_date' => $_GET['start_date'] ?? '',
    'end_date' => $_GET['end_date'] ?? '',
    'created_by' => $_GET['created_by'] ?? ''
];

// If the list view persists filters in session (and the browser URL doesn't contain them),
// merge them so export uses the same effective filters as the listing page.
$sessKey = 'communications_filters';
if (empty($_GET) || isset($_SESSION[$sessKey])) {
    $sessFilters = $_SESSION[$sessKey] ?? [];
    // keys we care about for export
    $mergeKeys = ['assigned_to', 'trainee_id', 'type', 'result', 'start_date', 'end_date', 'created_by'];
    foreach ($mergeKeys as $k) {
        if ((empty($filters[$k]) || $filters[$k] === '') && isset($sessFilters[$k])) {
            $filters[$k] = $sessFilters[$k];
        }
    }
    // Also bring trainee_ids array if present (used by searchCommunications)
    if (empty($filters['trainee_ids']) && !empty($sessFilters['trainee_ids']) && is_array($sessFilters['trainee_ids'])) {
        $filters['trainee_ids'] = $sessFilters['trainee_ids'];
    }
    // If search term not provided in GET, try to use session saved search if any
    if ($searchTerm === '' && !empty($sessFilters['search'])) {
        $searchTerm = $sessFilters['search'];
    }
}

try {
    // === DIAGNOSTIC TEST - اختبار تشخيصي ===
    error_log('=== DIAGNOSTIC TEST START ===');
    error_log('Search term: "' . $searchTerm . '"');
    error_log('Filters: ' . print_r($filters, true));
    
    // اختبار مباشر من قاعدة البيانات
    $directQuery = $db->query("SELECT COUNT(*) as total FROM communications");
    $totalRecords = $directQuery->fetch(PDO::FETCH_ASSOC);
    error_log('Total records in database: ' . $totalRecords['total']);
    
    // محاولة جلب البيانات باستخدام searchCommunications
    $communications = $communicationModel->searchCommunications($searchTerm, $filters);
    error_log('Communications from searchCommunications: ' . count($communications));
    
    // إذا كانت النتائج فارغة، استخدم الاستعلام المباشر
    if (empty($communications)) {
        error_log('searchCommunications returned empty, using direct query...');
        
        try {
            $stmt = $db->prepare("
                SELECT 
                    c.*,
                    t.full_name as trainee_name,
                    t.national_id,
                    u.full_name as created_by_name
                FROM communications c
                LEFT JOIN trainees t ON c.trainee_id = t.id
                LEFT JOIN users u ON c.created_by = u.id
                ORDER BY c.created_at DESC
                LIMIT 50
            ");
            $stmt->execute();
            $communications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log('Direct query returned: ' . count($communications) . ' records');
            
            if (!empty($communications)) {
                error_log('First record keys: ' . print_r(array_keys($communications[0]), true));
                error_log('First record sample: ' . print_r(array_slice($communications[0], 0, 5), true));
            }
        } catch (Exception $e) {
            error_log('Direct query failed: ' . $e->getMessage());
            $communications = [];
        }
    }
    
    error_log('Final communications count: ' . count($communications));
    error_log('=== DIAGNOSTIC TEST END ===');

    // تحضير مصفوفة البيانات للتصدير
    $exportData = [];
    $exportData[] = [
        '#',
        'المتدرب',
        'رقم الهوية',
        'نوع التواصل',
        'النتيجة',
        'تاريخ التواصل',
        'موعد الإجراء التالي',
        'ملاحظات التواصل',
        'ملاحظات الإجراء التالي',
        'الموظف المسؤول'
    ];

    foreach ($communications as $index => $comm) {
        // Debug خاص بـ next_action_notes
        error_log('=== NEXT_ACTION_NOTES DEBUG ===');
        error_log('Communication ID: ' . ($comm['id'] ?? 'unknown'));
        error_log('Raw next_action_notes: ' . print_r($comm['next_action_notes'] ?? 'NULL', true));
        error_log('Isset next_action_notes: ' . (isset($comm['next_action_notes']) ? 'YES' : 'NO'));
        error_log('Empty next_action_notes: ' . (empty($comm['next_action_notes']) ? 'YES' : 'NO'));
        error_log('Length next_action_notes: ' . strlen($comm['next_action_notes'] ?? ''));
        
        // التحقق من السجل 1251 تحديدًا
        if ($comm['id'] == 1251) {
            error_log('=== RECORD 1251 SPECIFIC DEBUG ===');
            error_log('All data for record 1251: ' . print_r($comm, true));
        }
        error_log('==================================');
        
        // استخراج الملاحظات بشكل صحيح
        $communicationNotes = '';
        $nextActionNotes = '';
        
        // محاولة الحصول على ملاحظات التواصل
        if (isset($comm['communication_notes'])) {
            $communicationNotes = $comm['communication_notes'];
        } elseif (isset($comm['notes'])) {
            $communicationNotes = $comm['notes'];
        }
        
        // محاولة الحصول على ملاحظات الإجراء التالي بطرق متعددة
        if (isset($comm['next_action_notes']) && !empty($comm['next_action_notes'])) {
            $nextActionNotes = $comm['next_action_notes'];
        } else {
            // للسجل 1251، جلب البيانات مباشرة من قاعدة البيانات
            if ($comm['id'] == 1251) {
                try {
                    $directQuery = $db->prepare("SELECT next_action_notes FROM communications WHERE id = ?");
                    $directQuery->execute([$comm['id']]);
                    $directResult = $directQuery->fetch(PDO::FETCH_ASSOC);
                    $nextActionNotes = $directResult['next_action_notes'] ?? '';
                    error_log('Direct query for 1251 next_action_notes: "' . $nextActionNotes . '"');
                } catch (Exception $e) {
                    error_log('Direct query failed: ' . $e->getMessage());
                }
            }
        }
        
        error_log('Final next_action_notes for export: "' . $nextActionNotes . '"');
        
        $exportData[] = [
            $index + 1,
            $comm['trainee_name'] ?? 'متدرب #' . ($comm['trainee_id'] ?? 'unknown'),
            $comm['national_id'] ?? '-',
            getTypeLabel($comm['type']),
            getResultLabel($comm['result']),
            formatDateTime($comm['contact_date']),
            $comm['next_action_date'] ? formatDateTime($comm['next_action_date']) : '-',
            $communicationNotes,  // ← ملاحظات التواصل
            $nextActionNotes,    // ← ملاحظات الإجراء التالي
            $comm['created_by_name'] ?? 'غير معروف'
        ];
    }

    $format = $_GET['format'] ?? 'excel';
    
    if ($format === 'excel') {
        // تصدير إلى Excel
        require_once '../../vendor/autoload.php';
        
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // تعيين اتجاه الورقة من اليمين إلى اليسار
        $sheet->setRightToLeft(true);
        
        // إضافة البيانات
        $sheet->fromArray($exportData, NULL, 'A1');
        
        // تنسيق العناوين
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($exportData[0]));
        $lastRow = count($exportData);

        // تنسيق العناوين
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 12,
                'name' => 'Arial'
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4']
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ]);

        // تنسيق محتوى الجدول
        $sheet->getStyle("A2:{$lastColumn}{$lastRow}")->applyFromArray([
            'font' => [
                'size' => 11,
                'name' => 'Arial'
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => 'CCCCCC']
                ]
            ]
        ]);

        // تلوين الصفوف بالتناوب
        for ($row = 2; $row <= $lastRow; $row++) {
            if ($row % 2 == 0) {
                $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->setStartColor(new \PhpOffice\PhpSpreadsheet\Style\Color('F5F5F5'));
            }
        }

        // ضبط ارتفاع الصفوف
        $sheet->getRowDimension(1)->setRowHeight(30);
        for ($row = 2; $row <= $lastRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(25);
        }

        // ضبط عرض الأعمدة تلقائياً مع حد أقصى
        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
            $sheet->getColumnDimension($column)->setWidth(
                min($sheet->getColumnDimension($column)->getWidth(), 30)
            );
        }

        // تجميد الصف الأول
        $sheet->freezePane('A2');
        
        // إنشاء كائن Writer وتصدير الملف
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        
        // تنظيف مخرجات الذاكرة المؤقتة
        ob_end_clean();
        
        // تعيين الترميز وإرسال الترويسات
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8');
        header('Content-Disposition: attachment; filename="communications_' . date('Y-m-d') . '.xlsx"');
        header('Cache-Control: max-age=0');
        header('Cache-Control: must-revalidate');
        
        // حفظ الملف مباشرة إلى المخرجات
        $writer->save('php://output');
        exit();
        
    } elseif ($format === 'pdf') {
        // تصدير إلى PDF
        require_once '../../vendor/autoload.php';
        
        // إعداد الستايل
        $style = '
        <style>
            body { font-family: DejaVu Sans, sans-serif; direction: rtl; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
            th { background-color: #f3f4f6; }
            th, td { border: 1px solid #e5e7eb; padding: 8px; text-align: right; }
            .title { text-align: center; font-size: 18px; margin-bottom: 20px; }
        </style>';
        
        // بناء محتوى HTML
        $html = $style . '<div class="title">سجلات التواصل</div><table>';
        
        // إضافة الصفوف
        foreach ($exportData as $index => $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= $index === 0 ? "<th>{$cell}</th>" : "<td>{$cell}</td>";
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
        
        // إنشاء ملف PDF
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'L',
            'direction' => 'rtl'
        ]);
        
        $mpdf->WriteHTML($html);
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment;filename="communications_' . date('Y-m-d') . '.pdf"');
        
        $mpdf->Output('communications_' . date('Y-m-d') . '.pdf', 'D');
    }

} catch (Exception $e) {
    error_log('Export error: ' . $e->getMessage());
    // في حالة حدوث خطأ، إعادة التوجيه مع رسالة الخطأ
    header('Location: list.php?error=' . urlencode($e->getMessage()));
    exit;
}

// دوال مساعدة
function getTypeLabel($type) {
    $types = [
        'call' => 'اتصال',
        'whatsapp' => 'واتساب',
        'email' => 'بريد',
        'meeting' => 'اجتماع',
        'phone' => 'اتصال',
        'communication' => 'تواصل',
        'payment' => 'دفع'
    ];
    return $types[$type] ?? $type;
}

function getResultLabel($result) {
    $results = [
        'promise' => 'وعد',
        'refused' => 'رفض',
        'paid' => 'تم السداد',
        'no_answer' => 'لم يرد',
        'follow_up' => 'متابعة',
        'not_interested' => 'غير مهتم',
        'other' => 'أخرى'
    ];
    return $results[$result] ?? $result;
}