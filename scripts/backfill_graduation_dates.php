<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/helpers.php';

$apply = in_array('--apply', $argv ?? []);

try {
    $db = (new Database())->getConnection();
    $stmt = $db->query("SELECT id, full_name, graduation_date, graduation_date_hijri FROM trainees WHERE (graduation_date IS NULL OR graduation_date = '') AND graduation_date_hijri IS NOT NULL AND graduation_date_hijri != '' ORDER BY id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "No rows found that need backfill.\n";
        exit(0);
    }

    echo "Found " . count($rows) . " rows to inspect.\n";
    foreach ($rows as $r) {
        $id = $r['id'];
        $name = $r['full_name'];
        $hijri = trim($r['graduation_date_hijri']);
        echo "#{$id} - {$name} - hijri raw: '{$hijri}'\n";
        $converted = false;
        // normalize similar to import code
        $arabicNums = ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9', '٫'=>'.','٬'=>','];
        $normalized = strtr($hijri, $arabicNums);
        $normalized = preg_replace('/[\.\-\s\\_]+/', '/', $normalized);
        $normalized = preg_replace('/[^0-9\/]/', '/', $normalized);
        $normalized = preg_replace('/\/{2,}/', '/', $normalized);
        $normalized = trim($normalized, '/');

        $candidates = [];
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

        foreach ($candidates as $cand) {
            if ($cand === '') continue;
            // try Intl first
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
            // fallback helper
            $try = convertHijriToGregorian($cand);
            if ($try !== false) {
                $converted = $try;
                break;
            }
        }

        if ($converted === false) {
            echo "  Could not convert '{$hijri}' -> no candidate produced.\n";
            continue;
        }

        echo "  Candidate conversion: {$converted}\n";
        if ($apply) {
            $u = $db->prepare("UPDATE trainees SET graduation_date = :g WHERE id = :id");
            $u->execute([':g' => $converted, ':id' => $id]);
            echo "  Updated DB.\n";
        } else {
            echo "  Dry-run (no DB update). Run with --apply to update.\n";
        }
    }
} catch (Exception $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
