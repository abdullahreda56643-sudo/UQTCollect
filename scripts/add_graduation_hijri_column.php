<?php
require_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check if table exists
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'trainees'");
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        echo "ERROR: table 'trainees' does not exist.\n";
        exit(1);
    }

    // Check if column exists
    $stmt = $pdo->prepare("SHOW COLUMNS FROM trainees LIKE 'graduation_date_hijri'");
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        echo "Column 'graduation_date_hijri' already exists.\n";
        exit(0);
    }

    // Add column safely
    $sql = "ALTER TABLE trainees ADD COLUMN graduation_date_hijri VARCHAR(50) DEFAULT NULL COMMENT 'تاريخ التخرج بالهجري (نص من ملف الاستيراد)';";
    $pdo->exec($sql);
    echo "Column 'graduation_date_hijri' added successfully.\n";
    exit(0);
} catch (PDOException $e) {
    echo "DB ERROR: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
