
<?php
/**
 * إضافة متدرب جديد - مع دعم استيراد Excel + إصلاح مشكلة الدبلومات
 * نظام إدارة المتدربين والتحصيل المالي
 * 
 * @version 3.4.3
 */

require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Trainee.php';
require_once '../../models/Diploma.php';
require_once '../../models/Branch.php';

// مكتبة Excel
require_once '../../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

// التحقق من الصلاحيات
// require admin or explicit trainees create permission
requireAccessOrAdmin('trainees', 'create');

// الاتصال بقاعدة البيانات
try {
    $db = (new Database())->getConnection();
    $traineeModel = new Trainee($db);
    $diplomaModel = new Diploma($db);
    $branchModel  = new Branch($db);
} catch (Exception $e) {
    die('خطأ في الاتصال بقاعدة البيانات');
}

// جلب قائمة الدبلومات والفروع
try {
    $diplomas = $diplomaModel->list([], 'name ASC');
    $branches = $branchModel->list([], 'name ASC');
} catch (Exception $e) {
    $diplomas = [];
    $branches = [];
}

// جلب الموظفين/المشرفين للإسناد
$users = [];
try {
    $stmt = $db->query("SELECT id, full_name FROM users WHERE role IN ('employee','supervisor') ORDER BY full_name ASC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $users = [];
}

$error = '';
$success = '';
$importPreviewHtml = '';

// =======================
// معالجة إضافة متدرب يدوي
// =======================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_single'])) {
    try {
        $requiredFields = ['national_id', 'full_name', 'phone', 'diploma_id', 'branch_id', 'total_amount'];
        foreach ($requiredFields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("الحقل {$field} مطلوب");
            }
        }

        $nationalId = trim($_POST['national_id']);
        if (!preg_match('/^[0-9]{10}$/', $nationalId)) {
            throw new Exception('رقم الهوية يجب أن يتكون من 10 أرقام');
        }

        $phone = trim($_POST['phone']);

        $totalAmount = (float) $_POST['total_amount'];
        if ($totalAmount <= 0) {
            throw new Exception('المبلغ الإجمالي يجب أن يكون أكبر من صفر');
        }

        // التحقق من عدم التكرار
        $existing = $traineeModel->list(['national_id' => $nationalId]);
        if (!empty($existing)) {
            throw new Exception('رقم الهوية مسجل مسبقاً');
        }

        // البيانات
        $data = [
            'national_id'     => $nationalId,
            'full_name'       => trim($_POST['full_name']),
            'phone'           => $phone,
            'graduation_date' => $_POST['graduation_date'] ?? null,
            'total_amount'    => $totalAmount,
            'paid_amount'     => 0,
            'remaining_amount'=> $totalAmount,
            'diploma_id'      => (int) $_POST['diploma_id'],
            'branch_id'       => (int) $_POST['branch_id']
        ];

        // إذا وُجد تاريخ تخرّج ميلادي، حاول توليد الصيغة الهجرية وحفظها
        if (!empty($data['graduation_date'])) {
            $hijri = '';
            try {
                if (class_exists('IntlDateFormatter')) {
                    $fmt = new IntlDateFormatter('ar_SA@calendar=islamic', IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::TRADITIONAL);
                    $ts = strtotime($data['graduation_date']);
                    if ($ts !== false && $ts > 0) {
                        $hijri = $fmt->format($ts);
                    } else {
                        $dt = new DateTime($data['graduation_date']);
                        $hijri = $fmt->format($dt);
                    }
                }
            } catch (\Throwable $e) {
                $hijri = '';
            }
            if ($hijri !== '') {
                $data['graduation_date_hijri'] = $hijri;
            }
        }

        // إسناد: للمستخدمين العاديين، قم بالإسناد إلى نفس المستخدم دائماً (لا تعرض أو تقبل قيمة assigned_to)
        if (!isAdmin() && !isSupervisor()) {
            $data['assigned_to'] = (int)($_SESSION['user_id'] ?? 0);
        } else {
            // Admins/supervisors may assign manually or fall back to round-robin
            if (!empty($_POST['assigned_to'])) {
                $data['assigned_to'] = (int) $_POST['assigned_to'];
            } else {
                // توزيع تلقائي Round Robin
                if ($users) {
                    $countTrainees = $db->query("SELECT COUNT(*) FROM trainees")->fetchColumn();
                    $data['assigned_to'] = $users[$countTrainees % count($users)]['id'];
                }
            }
        }

        $traineeId = $traineeModel->create($data);

        if ($traineeId) {
            // سجل نشاط الإضافة
            try {
                require_once '../../includes/auth.php';
                logActivity($db, 'create', 'trainees', $traineeId, null, $data);
            } catch (Exception $e) {
                error_log('Failed to log trainee creation: ' . $e->getMessage());
            }

            redirectWithMessage('list.php', 'تم إضافة المتدرب بنجاح');
        } else {
            throw new Exception('فشل في إضافة المتدرب');
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// =======================
// معالجة الاستيراد من ملف
// =======================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['import_trainees']) || isset($_POST['use_uploaded']) || isset($_POST['confirm_import']))) {
    try {
        // بدء الجلسة في البداية لضمان توفر بيانات المعاينة
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();

        // If the user is confirming import, ensure we have preview data in session
        if (!empty($_POST['confirm_import'])) {
            if (empty($_SESSION['import_preview_data'])) {
                throw new Exception('لم يتم العثور على بيانات معاينة الاستيراد في الجلسة. الرجاء إعادة رفع الملف ثم التأكيد.');
            }
            
            // استخدام بيانات المعاينة من الجلسة مباشرة
            $traineesData = $_SESSION['import_preview_data'];
        } else {
            // معالجة رفع ملف جديد
            if (!isset($_FILES['trainees_file']) && empty($_POST['use_uploaded'])) {
                $umax = ini_get('upload_max_filesize');
                $pmax = ini_get('post_max_size');
                error_log('Import upload missing $_FILES["trainees_file"]. upload_max_filesize=' . $umax . ' post_max_size=' . $pmax);
                throw new Exception('فشل رفع الملف. يرجى محاولة ملف أصغر أو الاتصال بمسؤول النظام.');
            }

            // If the client used chunked upload, the assembled file path is stored in session under uploadId
            if (!empty($_POST['use_uploaded'])) {
                $uploadId = $_POST['use_uploaded'];
                $assembled = $_SESSION['uploaded_import_files'][$uploadId] ?? null;
                $assembledName = $_SESSION['uploaded_import_files_name'][$uploadId] ?? null;
                if (!$assembled || !is_file($assembled)) {
                    throw new Exception('الملف المُحمّل غير موجود على الخادم. حاول إعادة الرفع.');
                }
                // create a synthetic $_FILES entry pointing to assembled path so existing code can reuse it
                $file = [
                    'name' => $assembledName,
                    'type' => mime_content_type($assembled) ?: 'application/octet-stream',
                    'tmp_name' => $assembled,
                    'error' => UPLOAD_ERR_OK,
                    'size' => filesize($assembled)
                ];
            } else {
                $file = $_FILES['trainees_file'];
            }

            if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
                $errCode = isset($file['error']) ? (int)$file['error'] : -1;
                // Map common PHP upload error codes to user-friendly messages
                $errMessages = [
                    UPLOAD_ERR_INI_SIZE => 'حجم الملف يتجاوز upload_max_filesize في php.ini',
                    UPLOAD_ERR_FORM_SIZE => 'حجم الملف يتجاوز الحد المسموح به في النموذج',
                    UPLOAD_ERR_PARTIAL => 'الملف تم رفعه جزئياً فقط',
                    UPLOAD_ERR_NO_FILE => 'لم يتم اختيار أي ملف',
                    UPLOAD_ERR_NO_TMP_DIR => 'المجلد المؤقت مفقود على الخادم',
                    UPLOAD_ERR_CANT_WRITE => 'فشل في كتابة الملف إلى القرص',
                    UPLOAD_ERR_EXTENSION => 'تم إيقاف رفع الملف بواسطة امتداد في الخادم'
                ];
                $msg = isset($errMessages[$errCode]) ? $errMessages[$errCode] : 'خطأ غير معروف أثناء رفع الملف (code=' . $errCode . ')';
                $umax = ini_get('upload_max_filesize');
                $pmax = ini_get('post_max_size');
                error_log('Import upload error: code=' . $errCode . ' msg=' . $msg . ' file_info=' . json_encode($file) . ' upload_max_filesize=' . $umax . ' post_max_size=' . $pmax);
                if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
                    throw new Exception('حجم الملف كبير جداً. الرجاء تقليل حجم الملف وإعادة المحاولة.');
                } elseif ($errCode === UPLOAD_ERR_NO_FILE) {
                    throw new Exception('لم يتم اختيار ملف. الرجاء اختيار ملف صحيح ثم المحاولة.');
                } else {
                    throw new Exception('فشل رفع الملف. يرجى المحاولة مرة أخرى أو التواصل مع الدعم.');
                }
            }

            $fileTmpPath = $file['tmp_name'];
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            $traineesData = [];

            // helper: normalize arabic-indic digits and punctuation and remove thousands separators
            $normalizeNumber = function($v) {
                if ($v === null) return 0;
                $s = trim((string)$v);
                if ($s === '') return 0;
                // Arabic-Indic digits map
                $arabicNums = ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
                               '٫'=>'.','٬'=>',','٬'=>','];
                $s = strtr($s, $arabicNums);
                // Remove spaces
                $s = preg_replace('/\s+/', '', $s);
                // Replace comma used as thousands separator
                if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
                    $s = str_replace(',', '', $s);
                } else {
                    if (strpos($s, ',') !== false && substr_count($s, ',') === 1 && strpos($s, '.') === false) {
                        $parts = explode(',', $s);
                        if (strlen($parts[1]) <= 2) {
                            $s = $parts[0] . '.' . $parts[1];
                        } else {
                            $s = str_replace(',', '', $s);
                        }
                    } else {
                        $s = str_replace(',', '', $s);
                    }
                }
                $s = preg_replace('/[^0-9\.\-]/', '', $s);
                if ($s === '' || $s === '.' || $s === '-') return 0;
                return (float)$s;
            };

            if (in_array($fileExtension, ['txt', 'csv'])) {
                $lines = file($fileTmpPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $index => $line) {
                    $columns = preg_split('/\t|,|;/', trim($line));
                    if ($index === 0 && preg_match('/اسم|هوية|جوال/i', $line)) continue;
                    if (count($columns) < 9) continue;
                    $traineesData[] = [
                        'graduation_date_hijri' => trim($columns[0]),
                        'diploma_name'          => trim($columns[1]),
                        'full_name'             => trim($columns[2]),
                        'national_id'           => trim($columns[3]),
                        'phone'                 => trim($columns[4]),
                        'status_text'           => trim($columns[5]),
                        'total_amount'          => $normalizeNumber($columns[6]),
                        'remaining_amount'      => $normalizeNumber($columns[7]),
                        'branch_name'           => trim($columns[8]), // العمود الجديد للفرع
                        'notes'                 => isset($columns[9]) ? trim($columns[9]) : ''
                    ];
                }
            } elseif (in_array($fileExtension, ['xlsx', 'xls'])) {
                $spreadsheet = IOFactory::load($fileTmpPath);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, true);

                // Detect header row and build a mapping from logical field => column letter
                $headerMap = [];
                $firstRow = [];
                foreach ($rows as $r) {
                    $allEmpty = true;
                    foreach ($r as $cell) {
                        if (trim((string)$cell) !== '') { $allEmpty = false; break; }
                    }
                    if (!$allEmpty) { $firstRow = $r; break; }
                }

                if (!empty($firstRow)) {
                    foreach ($firstRow as $col => $val) {
                        $h = trim((string)$val);
                        $lc = mb_strtolower($h, 'UTF-8');
                        if ($lc === '') continue;
                        
                        if (mb_strpos($lc, 'دبلوم') !== false || mb_strpos($lc, 'diploma') !== false) {
                            $headerMap['diploma_name'] = $col;
                        } elseif (mb_strpos($lc, 'اسم') !== false || mb_strpos($lc, 'name') !== false || mb_strpos($lc, 'الاسم') !== false) {
                            $headerMap['full_name'] = $col;
                        } elseif (mb_strpos($lc, 'هوية') !== false || mb_strpos($lc, 'id') !== false || mb_strpos($lc, 'رقم الهوية') !== false) {
                            $headerMap['national_id'] = $col;
                        } elseif (mb_strpos($lc, 'جوال') !== false || mb_strpos($lc, 'phone') !== false || mb_strpos($lc, 'mobile') !== false || mb_strpos($lc, 'رقم الجوال') !== false) {
                            $headerMap['phone'] = $col;
                        } elseif (mb_strpos($lc, 'حالة') !== false || mb_strpos($lc, 'status') !== false) {
                            $headerMap['status_text'] = $col;
                        } elseif (mb_strpos($lc, 'متبقي') !== false || mb_strpos($lc, 'remaining') !== false || mb_strpos($lc, 'باقي') !== false) {
                            $headerMap['remaining_amount'] = $col;
                        } elseif (mb_strpos($lc, 'رسوم') !== false || mb_strpos($lc, 'مبلغ') !== false || mb_strpos($lc, 'إجمالي') !== false || mb_strpos($lc, 'total') !== false) {
                            $headerMap['total_amount'] = $col;
                        } elseif (mb_strpos($lc, 'تاريخ') !== false || mb_strpos($lc, 'date') !== false) {
                            $headerMap['graduation_date_hijri'] = $col;
                        } elseif (mb_strpos($lc, 'نتيجة') !== false || mb_strpos($lc, 'ملاحظ') !== false || mb_strpos($lc, 'note') !== false || mb_strpos($lc, 'ملاحظات') !== false) {
                            $headerMap['notes'] = $col;
                        } elseif (mb_strpos($lc, 'فرع') !== false || mb_strpos($lc, 'branch') !== false || mb_strpos($lc, 'الفرع') !== false) {
                            $headerMap['branch_name'] = $col; // التعرف على عمود الفرع
                        }
                    }
                }

                if (!isset($headerMap['total_amount']) && !isset($headerMap['remaining_amount'])) {
                    $headerMap = [
                        'graduation_date_hijri' => 'A',
                        'diploma_name' => 'B', 
                        'full_name' => 'C',
                        'national_id' => 'D',
                        'phone' => 'E',
                        'status_text' => 'F',
                        'total_amount' => 'G',
                        'remaining_amount' => 'H',
                        'branch_name' => 'I', // العمود الجديد للفرع
                        'notes' => 'J'
                    ];

                    $sampleCount = 0;
                    $g_ge_h = 0;
                    $rowsSampled = 0;
                    foreach ($rows as $rIndex => $rRow) {
                        $allEmpty = true;
                        foreach ($rRow as $cell) { if (trim((string)$cell) !== '') { $allEmpty = false; break; } }
                        if ($allEmpty) continue;

                        $valG = isset($rRow['G']) ? preg_replace('/[^0-9\.\-]/', '', (string)$rRow['G']) : '';
                        $valH = isset($rRow['H']) ? preg_replace('/[^0-9\.\-]/', '', (string)$rRow['H']) : '';
                        if ($valG === '' && $valH === '') continue;
                        $numG = $valG === '' ? null : (float)$valG;
                        $numH = $valH === '' ? null : (float)$valH;

                        if ($numG !== null && $numH !== null) {
                            $rowsSampled++;
                            if ($numG >= $numH) $g_ge_h++;
                        }

                        $sampleCount++;
                        if ($sampleCount >= 10) break;
                    }

                    if ($rowsSampled > 0 && $g_ge_h / $rowsSampled < 0.5) {
                        $headerMap['total_amount'] = 'H';
                        $headerMap['remaining_amount'] = 'G';
                        error_log(sprintf("Import header fallback swap: assumed G=total,H=remaining but detected G<->H in sample; swapping for file '%s'", basename($_FILES['trainees_file']['name'] ?? 'unknown')));
                    }
                }

                $useHeader = isset($headerMap['full_name']) && isset($headerMap['national_id']);

                foreach ($rows as $index => $row) {
                    if ($useHeader && $index === array_key_first($rows)) continue;

                    $allEmpty = true;
                    foreach ($row as $cell) { if (trim((string)$cell) !== '') { $allEmpty = false; break; } }
                    if ($allEmpty) continue;

                    if ($useHeader) {
                        $get = function($key) use ($row, $headerMap) {
                            if (!isset($headerMap[$key])) return '';
                            $col = $headerMap[$key];
                            return isset($row[$col]) ? $row[$col] : '';
                        };

                        $graduation = $get('graduation_date_hijri');
                        $diplomaName = $get('diploma_name');
                        $fullName = $get('full_name');
                        $nationalId = $get('national_id');
                        $phone = $get('phone');
                        $statusText = $get('status_text');
                        $totalAmount = $get('total_amount');
                        $remainingAmount = $get('remaining_amount');
                        $branchName = $get('branch_name'); // الحصول على اسم الفرع
                        $notes = $get('notes');
                        
                    } else {
                        $numRow = array_values($row);
                        if (count($numRow) < 9) continue;
                        $graduation = $numRow[0] ?? '';
                        $diplomaName = $numRow[1] ?? '';
                        $fullName = $numRow[2] ?? '';
                        $nationalId = $numRow[3] ?? '';
                        $phone = $numRow[4] ?? '';
                        $statusText = $numRow[5] ?? '';
                        $totalAmount = $numRow[6] ?? '0';
                        $remainingAmount = $numRow[7] ?? '0';
                        $branchName = $numRow[8] ?? ''; // الحصول على اسم الفرع
                        $notes = $numRow[9] ?? '';
                    }

                    $traineesData[] = [
                        'graduation_date_hijri' => $graduation ?? '',
                        'diploma_name'          => $diplomaName ?? '',
                        'full_name'             => $fullName ?? '',
                        'national_id'           => $nationalId ?? '',
                        'phone'                 => $phone ?? '',
                        'status_text'           => $statusText ?? '',
                        'total_amount'          => $normalizeNumber($totalAmount ?? '0'),
                        'remaining_amount'      => $normalizeNumber($remainingAmount ?? '0'),
                        'branch_name'           => $branchName ?? '', // اسم الفرع
                        'notes'                 => $notes ?? ''
                    ];
                }
            } else {
                throw new Exception('صيغة الملف غير مدعومة. فقط TXT, CSV, XLSX, XLS');
            }

            if (empty($traineesData)) {
                throw new Exception('الملف فارغ أو غير صالح');
            }
        }

        // If user hasn't confirmed the import yet, save parsed data to session and show a preview
        if (empty($_POST['confirm_import'])) {
            $_SESSION['import_preview_data'] = array_slice($traineesData, 0, 5000);

            $previewRows = array_slice($traineesData, 0, 20);
            $previewHtml = '<div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 mb-6">';
            $previewHtml .= '<h3 class="text-lg font-semibold mb-3">معاينة بيانات الاستيراد (' . count($traineesData) . ' صف)</h3>';
            $previewHtml .= '<p class="text-sm text-gray-600 mb-3">تحقق من أعمدة الرسوم والمبلغ المتبقي والفرع. إذا كانت صحيحة اضغط "تأكيد الاستيراد".</p>';
            $previewHtml .= '<div class="overflow-auto"><table class="w-full text-sm border-collapse">';
            // stronger contrast for fees/remaining columns to be more readable in preview
            $previewHtml .= '<thead><tr class="bg-gray-100"><th class="p-2 border">#</th><th class="p-2 border">الاسم</th><th class="p-2 border">الهوية</th><th class="p-2 border">الجوال</th><th class="p-2 border">الدبلوم</th><th class="p-2 border" style="background-color:#dbeafe;font-weight:600;color:#0f172a">الرسوم</th><th class="p-2 border" style="background-color:#fff1f2;font-weight:600;color:#0f172a">المتبقي</th><th class="p-2 border">الفرع</th><th class="p-2 border">ملاحظات</th></tr></thead><tbody>';
            $i = 1;
            foreach ($previewRows as $r) {
                $previewHtml .= '<tr><td class="p-2 border">' . $i++ . '</td>' .
                                '<td class="p-2 border">' . htmlspecialchars($r['full_name']) . '</td>' .
                                '<td class="p-2 border">' . htmlspecialchars($r['national_id']) . '</td>' .
                                '<td class="p-2 border">' . htmlspecialchars($r['phone']) . '</td>' .
                                '<td class="p-2 border">' . htmlspecialchars($r['diploma_name']) . '</td>' .
                                '<td class="p-2 border" style="background-color:#eff6ff;font-weight:600;color:#062a4f">' . number_format($r['total_amount'], 2) . '</td>' .
                                '<td class="p-2 border" style="background-color:#fff1f2;font-weight:600;color:#4b2b2b">' . number_format($r['remaining_amount'], 2) . '</td>' .
                                '<td class="p-2 border">' . htmlspecialchars($r['branch_name']) . '</td>' .
                                '<td class="p-2 border">' . htmlspecialchars(mb_substr($r['notes'],0,80)) . '</td></tr>';
            }
            $previewHtml .= '</tbody></table></div>';
            $previewHtml .= '<form method="post" class="mt-4">';
            $previewHtml .= '<input type="hidden" name="confirm_import" value="1">';
            $previewHtml .= '<button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg">تأكيد الاستيراد (' . count($traineesData) . ' صف)</button>';
            $previewHtml .= '<a href="list.php" class="px-4 py-2 bg-gray-200 rounded-lg ml-3">إلغاء</a>';
            $previewHtml .= '</form></div>';

            $success = 'تم تحميل الملف. الرجاء مراجعة المعاينة أدناه قبل تأكيد الاستيراد.';
            $importPreviewHtml = $previewHtml;
        } else {
            // Only perform insertion when user confirms import explicitly
            $successCount = 0;
            $errorCount = 0;
            $errorMessages = [];
            
            // دالة مساعدة للبحث عن الدبلوم أو إنشائه - التصحيح الرئيسي هنا
            function getOrCreateDiploma($diplomaName, $diplomaModel, &$diplomasCache) {
                if (empty($diplomaName)) return null;
                
                $diplomaName = trim($diplomaName);
                
                // البحث في الكاش أولاً (مقارنة دقيقة)
                foreach ($diplomasCache as $diploma) {
                    if (strcasecmp(trim($diploma['name']), $diplomaName) === 0) {
                        return $diploma['id'];
                    }
                }
                
                // إذا لم يتم العثور، ابحث في قاعدة البيانات
                try {
                    $existingDiplomas = $diplomaModel->list(['name' => $diplomaName]);
                    if (!empty($existingDiplomas)) {
                        $diplomaId = $existingDiplomas[0]['id'];
                        // إضافة إلى الكاش
                        $diplomasCache[] = ['id' => $diplomaId, 'name' => $diplomaName];
                        return $diplomaId;
                    }
                } catch (Exception $e) {
                    error_log("Error searching for diploma: " . $e->getMessage());
                }
                
                // إنشاء دبلوم جديد
                try {
                    $newDiplomaData = [
                        'name' => $diplomaName,
                        'code' => 'IMP_' . substr(md5($diplomaName . time()), 0, 8),
                        'duration' => 0,
                        'cost' => 0,
                        'description' => 'تم إنشاؤه تلقائياً من الاستيراد',
                        'is_active' => 1
                    ];
                    
                    $diplomaId = $diplomaModel->create($newDiplomaData);
                    
                    if ($diplomaId) {
                        // إضافة إلى الكاش
                        $diplomasCache[] = ['id' => $diplomaId, 'name' => $diplomaName];
                        error_log("Created new diploma: " . $diplomaName . " with ID: " . $diplomaId);
                        return $diplomaId;
                    }
                } catch (Exception $e) {
                    error_log("Failed to create diploma: " . $diplomaName . " - " . $e->getMessage());
                }
                
                return null;
            }
            
            // دالة مساعدة للبحث عن الفرع أو إنشائه
            function getOrCreateBranch($branchName, $branchModel, &$branchesCache) {
                if (empty($branchName)) return null;
                
                $branchName = trim($branchName);
                
                // البحث في الكاش أولاً (مقارنة دقيقة)
                foreach ($branchesCache as $branch) {
                    if (strcasecmp(trim($branch['name']), $branchName) === 0) {
                        return $branch['id'];
                    }
                }
                
                // إذا لم يتم العثور، ابحث في قاعدة البيانات
                try {
                    $existingBranches = $branchModel->list(['name' => $branchName]);
                    if (!empty($existingBranches)) {
                        $branchId = $existingBranches[0]['id'];
                        // إضافة إلى الكاش
                        $branchesCache[] = ['id' => $branchId, 'name' => $branchName];
                        return $branchId;
                    }
                } catch (Exception $e) {
                    error_log("Error searching for branch: " . $e->getMessage());
                }
                
                // إنشاء فرع جديد
                try {
                    $newBranchData = [
                        'name' => $branchName,
                        'code' => 'IMP_' . substr(md5($branchName . time()), 0, 8),
                        'address' => 'تم إنشاؤه تلقائياً من الاستيراد',
                        'phone' => '',
                        'is_active' => 1
                    ];
                    
                    $branchId = $branchModel->create($newBranchData);
                    
                    if ($branchId) {
                        // إضافة إلى الكاش
                        $branchesCache[] = ['id' => $branchId, 'name' => $branchName];
                        error_log("Created new branch: " . $branchName . " with ID: " . $branchId);
                        return $branchId;
                    }
                } catch (Exception $e) {
                    error_log("Failed to create branch: " . $branchName . " - " . $e->getMessage());
                }
                
                return null;
            }
            
            // كاش للدبلومات والفروع لتحسين الأداء
            $diplomasCache = $diplomas; // البدء بالدبلومات الموجودة
            $branchesCache = $branches; // البدء بالفروع الموجودة

            foreach ($traineesData as $trainee) {
                try {
                    if (empty($trainee['full_name']) || empty($trainee['phone'])) {
                        $errorCount++;
                        $errorMessages[] = "بيانات ناقصة للمتدرب: " . ($trainee['full_name'] ?: 'غير معروف');
                        continue;
                    }

                    $phone = trim($trainee['phone']);
                    $phone = preg_replace('/[^0-9]/', '', $phone);
                    
                    if (!preg_match('/^[0-9]{9,11}$/', $phone)) {
                        $errorCount++;
                        $errorMessages[] = "رقم الجوال غير صحيح للمتدرب {$trainee['full_name']} (يجب أن يكون بين 9 و 11 رقم)";
                        continue;
                    }

                    $nationalId = trim($trainee['national_id']);
                    if (empty($nationalId) || !preg_match('/^[0-9]{10}$/', $nationalId)) {
                        $errorCount++;
                        $errorMessages[] = "رقم الهوية غير صالح: {$trainee['diploma_name']} للمتدرب {$trainee['full_name']}";
                        continue;
                    }

                    $existing = $traineeModel->list(['national_id' => $nationalId]);
                    if (!empty($existing)) {
                        $errorCount++;
                        $errorMessages[] = "رقم الهوية {$nationalId} موجود مسبقاً للمتدرب {$trainee['full_name']}";
                        continue;
                    }

                    // === البحث عن الدبلوم ===
                    $diplomaId = null;
                    if (!empty($trainee['diploma_name'])) {
                        $diplomaId = getOrCreateDiploma($trainee['diploma_name'], $diplomaModel, $diplomasCache);
                    }

                    // إذا لم يتم العثور على دبلوم، استخدم أول دبلوم متاح كافتراضي
                    if (!$diplomaId && !empty($diplomasCache)) {
                        $diplomaId = $diplomasCache[0]['id'];
                        error_log("Used default diploma for trainee: " . $trainee['full_name']);
                    }

                    // === البحث عن الفرع ===
                    $branchId = null;
                    if (!empty($trainee['branch_name'])) {
                        $branchId = getOrCreateBranch($trainee['branch_name'], $branchModel, $branchesCache);
                    }

                    // إذا لم يتم العثور على فرع، استخدم أول فرع متاح كافتراضي
                    if (!$branchId && !empty($branchesCache)) {
                        $branchId = $branchesCache[0]['id'];
                        error_log("Used default branch for trainee: " . $trainee['full_name']);
                    }

                    $status = 'under_communication';
                    $statusText = strtolower($trainee['status_text']);
                    if (strpos($statusText, 'متعثر') !== false) {
                        $status = 'delayed';
                    } elseif (strpos($statusText, 'مسدد') !== false) {
                        $status = 'paid';
                    }

                    if (!isset($trainee['total_amount']) || $trainee['total_amount'] < 0) {
                        $trainee['total_amount'] = 0;
                    }
                    if (!isset($trainee['remaining_amount']) || $trainee['remaining_amount'] < 0) {
                        $trainee['remaining_amount'] = $trainee['total_amount'];
                    }
                    if ($trainee['remaining_amount'] > $trainee['total_amount']) {
                        $trainee['remaining_amount'] = $trainee['total_amount'];
                    }

                    $paidAmount = 0;
                    $total = (float)$trainee['total_amount'];
                    $remaining = (float)$trainee['remaining_amount'];
                    
                    if ($total >= 0 && $remaining >= 0 && $remaining <= $total) {
                        $paidAmount = $total - $remaining;
                    } else {
                        $paidAmount = 0;
                        $trainee['remaining_amount'] = $total;
                        error_log("Data inconsistency for trainee {$trainee['full_name']}: total=$total, remaining=$remaining");
                    }

                    $graduationDateGregorian = null;
                    $hijriRaw = trim((string)($trainee['graduation_date_hijri'] ?? ''));
                    if ($hijriRaw !== '') {
                        $arabicNums = ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
                                       '٫'=>'.','٬'=>','];
                        $normalized = strtr($hijriRaw, $arabicNums);
                        $normalized = preg_replace('/[\.\-\s\\_]+/', '/', $normalized);

                        $candidates = [];
                        $normalized = preg_replace('/[^0-9\/]/', '/', $normalized);
                        $normalized = preg_replace('/\/{2,}/', '/', $normalized);
                        $normalized = trim($normalized, '/');

                        if (preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $normalized)) {
                            $candidates[] = $normalized;
                        } elseif (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $normalized)) {
                            $parts = explode('/', $normalized);
                            $candidates[] = $parts[2] . '/' . $parts[1] . '/' . $parts[0];
                        } else {
                            $parts = preg_split('/\//', $normalized);
                            if (count($parts) === 3) {
                                if (strlen($parts[0]) === 4) {
                                    $candidates[] = $normalized;
                                } elseif (strlen($parts[2]) === 4) {
                                    $candidates[] = $parts[2] . '/' . $parts[1] . '/' . $parts[0];
                                }
                            }
                        }

                        $intlSucceeded = false;
                        foreach ($candidates as $cand) {
                            if ($cand === '') continue;
                            if (class_exists('IntlDateFormatter')) {
                                try {
                                    $fmt = new IntlDateFormatter('en_US@calendar=islamic', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::TRADITIONAL, 'yyyy/MM/dd');
                                    $parsedTs = @$fmt->parse($cand);
                                    if ($parsedTs !== false && $parsedTs !== null) {
                                        if ($parsedTs > 1000000000000) $parsedTs = (int)($parsedTs / 1000);
                                        $graduationDateGregorian = gmdate('Y-m-d', (int)$parsedTs);
                                        $intlSucceeded = true;
                                        break;
                                    }
                                } catch (Throwable $e) {
                                }
                            }
                        }

                        if (!$intlSucceeded && empty($graduationDateGregorian)) {
                            foreach ($candidates as $cand) {
                                $try = convertHijriToGregorian($cand);
                                if ($try !== false) {
                                    $graduationDateGregorian = $try;
                                    break;
                                }
                            }
                        }
                    }

                    $data = [
                        'full_name'             => $trainee['full_name'],
                        'national_id'           => $nationalId,
                        'phone'                 => $phone,
                        'graduation_date_hijri' => isset($trainee['graduation_date_hijri']) ? $trainee['graduation_date_hijri'] : null,
                        'graduation_date'       => $graduationDateGregorian,
                        'total_amount'          => $trainee['total_amount'],
                        'initial_paid_amount'   => $paidAmount,
                        'remaining_amount'      => max(0, $trainee['total_amount'] - $paidAmount),
                        'diploma_id'            => $diplomaId, // استخدام الدبلوم الصحيح
                        'branch_id'             => $branchId, // استخدام الفرع الصحيح
                        'status'                => $status,
                        'notes'                 => $trainee['notes']
                    ];

                    // Prefer assigning imported trainees to a manager/admin user if available
                    $assignedTo = null;
                    try {
                        $adminRow = $db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                        if ($adminRow && !empty($adminRow['id'])) {
                            $assignedTo = (int)$adminRow['id'];
                        }
                    } catch (Exception $e) {
                        error_log('Failed to lookup admin for import assignment: ' . $e->getMessage());
                    }

                    // fallback: round-robin across available users (legacy behavior)
                    if (!$assignedTo && $users) {
                        $countTrainees = $db->query("SELECT COUNT(*) FROM trainees")->fetchColumn();
                        $assignedTo = $users[$countTrainees % count($users)]['id'];
                    }

                    if ($assignedTo) {
                        $data['assigned_to'] = (int)$assignedTo;
                    }

                    $traineeId = $traineeModel->create($data);
                    if ($traineeId) {
                        $successCount++;
                        try {
                            require_once '../../includes/auth.php';
                            logActivity($db, 'create', 'trainees', $traineeId, null, $data);
                        } catch (Exception $e) {
                            error_log('Failed to log imported trainee: ' . $e->getMessage());
                        }
                        try {
                            $traineeModel->updateBalanceProcedure($traineeId);
                        } catch (Throwable $e) {
                            error_log("Failed to call UpdateTraineeBalance for imported trainee $traineeId: " . $e->getMessage());
                        }
                    } else {
                        $errorCount++;
                        $errorMessages[] = "فشل في إضافة المتدرب: {$trainee['full_name']}";
                    }
                } catch (Exception $e) {
                    $errorCount++;
                    $errorMessages[] = "خطأ في {$trainee['full_name']}: " . $e->getMessage();
                }
            }

            unset($_SESSION['import_preview_data']);

            if ($successCount > 0) {
                $success = "تم استيراد $successCount متدرب بنجاح.";
                if ($errorCount > 0) {
                    $success .= " فشل استيراد $errorCount متدرب.";
                }
                if (!empty($errorMessages)) {
                    $success .= "<br>الأخطاء: " . implode('; ', array_slice($errorMessages, 0, 5));
                    if (count($errorMessages) > 5) {
                        $success .= " ... والمزيد";
                    }
                }
                
                // إعادة التوجيه بعد النجاح
                echo "<script>
                    setTimeout(function() {
                        window.location.href = 'list.php?success=" . urlencode($success) . "';
                    }, 3000);
                </script>";
            } else {
                throw new Exception("لم يتم استيراد أي متدرب. " . implode('; ', $errorMessages));
            }
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<?php $page_title = 'إضافة متدرب'; include '../../includes/tw_layout_start.php'; ?>
<h1 class="text-xl font-bold mb-4">إضافة متدرب</h1>

<?php if ($error): ?>
<div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="mb-4 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-lg"><?php echo $success; ?></div>
<?php endif; ?>

<!-- إضافة متدرب يدوي -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 mb-6">
    <h2 class="text-lg font-semibold mb-4">إضافة متدرب جديد</h2>
    <form method="post" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm mb-1">الاسم الكامل</label>
                <input name="full_name" required class="w-full px-3 py-2 rounded-lg border">
            </div>
            <div>
                <label class="block text-sm mb-1">رقم الهوية</label>
                <input name="national_id" required class="w-full px-3 py-2 rounded-lg border">
            </div>
            <div>
                <label class="block text-sm mb-1">رقم الجوال</label>
                <input name="phone" required class="w-full px-3 py-2 rounded-lg border">
            </div>
            <div>
                <label class="block text-sm mb-1">الدبلوم</label>
                <select name="diploma_id" class="w-full px-3 py-2 rounded-lg border" required>
                    <?php foreach ($diplomas as $diploma): ?>
                    <option value="<?php echo $diploma['id']; ?>"><?php echo htmlspecialchars($diploma['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm mb-1">الفرع</label>
                <select name="branch_id" class="w-full px-3 py-2 rounded-lg border" required>
                    <?php foreach ($branches as $branch): ?>
                    <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm mb-1">تاريخ التخرج</label>
                <input type="date" name="graduation_date" class="w-full px-3 py-2 rounded-lg border">
            </div>
            <div>
                <label class="block text-sm mb-1">رسوم الدبلوم</label>
                <input type="number" name="total_amount" step="0.01" value="0" class="w-full px-3 py-2 rounded-lg border">
            </div>
            <?php if (isAdmin() || isSupervisor()): ?>
            <div>
                <label class="block text-sm mb-1">إسناد إلى</label>
                <select name="assigned_to" class="w-full px-3 py-2 rounded-lg border">
                    <option value="">-- توزيع تلقائي --</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
                <!-- For regular employees hide the manual assignee field; assignment will default to current user -->
            <?php endif; ?>
        </div>
        <div class="mt-3">
            <button name="add_single" type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg">حفظ</button>
            <a href="list.php" class="px-4 py-2 bg-gray-200 rounded-lg">إلغاء</a>
        </div>
    </form>
</div>

<!-- استيراد متدربين من ملف -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 mb-6">
    <h2 class="text-lg font-semibold mb-4">استيراد متدربين من ملف</h2>
    <form method="post" enctype="multipart/form-data" class="space-y-4">
        <div>
            <label class="block text-sm mb-1">ملاحظة: سيتم أخذ الفرع تلقائياً من الملف</label>
            <p class="text-xs text-gray-500 mt-1">
                يجب أن يحتوي الملف على عمود "الفرع" أو أن يكون العمود التاسع في الترتيب
            </p>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">ملف المتدربين (Excel)</label>
            <div class="relative inline-block w-full max-w-xs">
                <input type="file" name="trainees_file" accept=".xlsx,.xls,.csv,.txt" required class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" onchange="document.getElementById('file-name').textContent = this.files[0]?.name || 'لم يتم اختيار ملف';">
                <div class="flex items-center justify-center px-4 py-2 bg-gradient-to-r from-blue-500 to-blue-700 text-white text-sm font-medium rounded-lg shadow-md hover:from-blue-600 hover:to-blue-800 transition-all duration-300">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5 mr-2 animate-spin">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h16M12 4v16" />
                    </svg>
                    اختر ملف
                </div>
            </div>
            <p id="file-name" class="mt-3 text-sm text-gray-600 bg-gray-100 p-2 rounded-lg border border-gray-300">لم يتم اختيار ملف</p>
            <p class="text-xs text-gray-500 mt-1">
                يجب أن يحتوي الملف على الأعمدة بالترتيب: 
                تاريخ آخر ترم، الدبلوم، اسم المتدرب، رقم الهوية، رقم الجوال، حالة الرسوم، الرسوم، المبلغ المتبقي، الفرع، نتيجة التواصل
            </p>
        </div>
        <button name="import_trainees" type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg">استيراد</button>
    </form>
</div>
 
<?php
// If a preview was prepared during parsing, show it here
if (!empty($importPreviewHtml)) {
    echo $importPreviewHtml;
}
?>
<?php include '../../includes/tw_layout_end.php'; ?>
