<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/helpers.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if ($argc < 2) {
    echo "Usage: php update_from_excel_hijri.php <path-to-file> [--apply]\n";
    exit(1);
}

$path = $argv[1];
$apply = in_array('--apply', $argv ?? []);

if (!file_exists($path)) {
    echo "File not found: $path\n";
    exit(1);
}

try {
    $db = (new Database())->getConnection();
    $spreadsheet = IOFactory::load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();

    echo "Total rows in file: " . count($rows) . "\n";
    $updated = 0;
    $skipped = 0;
    $notFound = 0;

    foreach ($rows as $index => $row) {
        if ($index === 0) continue; // header
        $hijriRaw = isset($row[0]) ? trim((string)$row[0]) : '';
        $nationalId = isset($row[3]) ? trim((string)$row[3]) : '';
        if ($nationalId === '') {
            echo "Row " . ($index+1) . " - no national id, skipping\n";
            $skipped++;
            continue;
        }
        // find trainee by national id
        $stmt = $db->prepare("SELECT id, full_name, graduation_date, graduation_date_hijri FROM trainees WHERE national_id = :nid LIMIT 1");
        $stmt->execute([':nid' => $nationalId]);
        $trainee = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$trainee) {
            echo "Row " . ($index+1) . " - national_id {$nationalId} - trainee not found\n";
            $notFound++;
            continue;
        }
        $tid = $trainee['id'];
        $currentG = $trainee['graduation_date'];
        $currentH = $trainee['graduation_date_hijri'];

        if ($hijriRaw === '') {
            echo "Row " . ($index+1) . " - trainee {$tid} ({$trainee['full_name']}) - hijri cell empty, skipping\n";
            $skipped++;
            continue;
        }

        // attempt conversion using same logic as import
        // normalize
        $arabicNums = ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٫'=>'.','٬'=>','];
        $normalized = strtr($hijriRaw, $arabicNums);
        $normalized = preg_replace('/[\.\-\s\\_]+/', '/', $normalized);
        $normalized = preg_replace('/[^0-9\/]/', '/', $normalized);
        $normalized = preg_replace('/\/{2,}/', '/', $normalized);
        $normalized = trim($normalized, '/');

        $candidates = [];
        if (preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $normalized)) {
            $candidates[] = $normalized; // yyyy/mm/dd
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

        $converted = false;
        foreach ($candidates as $cand) {
            if (class_exists('IntlDateFormatter')) {
                try {
                    $fmt = new IntlDateFormatter('en_US@calendar=islamic', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::TRADITIONAL, 'yyyy/MM/dd');
                    $parsedTs = @$fmt->parse($cand);
                    if ($parsedTs !== false && $parsedTs !== null) {
                        if ($parsedTs > 1000000000000) $parsedTs = (int)($parsedTs / 1000);
                        $converted = gmdate('Y-m-d', (int)$parsedTs);
                        break;
                    }
                } catch (Throwable $e) {
                    // ignore
                }
            }
            $try = convertHijriToGregorian($cand);
            if ($try !== false) {
                $converted = $try;
                break;
            }
        }

        if ($converted === false) {
            echo "Row " . ($index+1) . " - trainee {$tid} ({$trainee['full_name']}) - could not convert '{$hijriRaw}'\n";
            $skipped++;
            continue;
        }

        echo "Row " . ($index+1) . " - trainee {$tid} ({$trainee['full_name']}) - hijri '{$hijriRaw}' -> gregorian {$converted} (current g: '{$currentG}', current h: '{$currentH}')\n";

        if ($apply) {
            $u = $db->prepare("UPDATE trainees SET graduation_date = :g, graduation_date_hijri = :h, updated_at = NOW() WHERE id = :id");
            $u->execute([':g' => $converted, ':h' => $hijriRaw, ':id' => $tid]);
            echo "  Updated trainee {$tid}.\n";
            $updated++;
        } else {
            echo "  Dry-run: no DB update (use --apply to persist).\n";
        }
    }

    echo "\nSummary: updated={$updated}, skipped={$skipped}, notFound={$notFound}\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
