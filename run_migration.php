<?php
require_once 'config.php';
require_once 'db/connection.php';

try {
    $db = (new Database())->getConnection();
    
    echo "Starting migration...\n";
    
    // Add original_amount column
    $db->exec('ALTER TABLE payments ADD COLUMN original_amount DECIMAL(12,2) NULL AFTER discount_amount');
    echo "Column original_amount added successfully\n";
    
    // Update existing records
    $db->exec('UPDATE payments SET original_amount = amount + COALESCE(discount_amount, 0) WHERE original_amount IS NULL');
    echo "Existing records updated\n";
    
    // Make column NOT NULL
    $db->exec('ALTER TABLE payments MODIFY COLUMN original_amount DECIMAL(12,2) NOT NULL');
    echo "Column made NOT NULL\n";
    
    echo "Migration completed successfully!";
    
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
?>
