@echo off
echo Installing export libraries for Trainee Management System...
echo.

REM Check if composer is installed
composer --version >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: Composer is not installed!
    echo Please install Composer from https://getcomposer.org/download/
    pause
    exit /b 1
)

echo Composer found. Installing libraries...
echo.

REM Install libraries
composer install --no-dev --optimize-autoloader

if %errorlevel% equ 0 (
    echo.
    echo SUCCESS: Export libraries installed successfully!
    echo.
    echo You can now use Excel and PDF export features.
    echo.
) else (
    echo.
    echo ERROR: Failed to install libraries!
    echo Please check the error messages above.
    echo.
)

pause


