What this migration does

- Creates `system_activities` table to store application activity logs (used by various modules).
- Creates `assignment_logs` table to track trainee assignments over time.

How to run (XAMPP / Windows)

1) Open XAMPP control panel and ensure MySQL (MariaDB) is running.
2) Open a terminal (PowerShell) and run the following (adjust paths and DB name if needed):

# Replace DB_NAME with your database name
mysql -u root -p DB_NAME < "c:\xampp\htdocs\my-works\Trainee-Management-System\sql\create_system_activities_and_assignment_logs.sql"

You will be prompted for your MySQL password.

Alternative: Use phpMyAdmin

- Open phpMyAdmin via http://localhost/phpmyadmin
- Select your application database
- Choose SQL and paste the contents of the SQL file, then execute.

Rollback

- To rollback, run:
  DROP TABLE IF EXISTS assignment_logs;
  DROP TABLE IF EXISTS system_activities;

Notes

- Always back up your database before running migrations.
- The SQL in this migration is intentionally permissive. If you prefer strict foreign keys, uncomment and adapt the optional ALTER TABLE block.
