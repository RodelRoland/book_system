@echo off
echo Setting up automated backup task for Book System...
echo.

REM Remove any existing task
schtasks /delete /tn "BookSystem_Backup" /f >nul 2>&1

REM Create new task to run every 15 minutes
schtasks /create /tn "BookSystem_Backup" /tr "C:\xampp\php\php.exe C:\xampp\htdocs\book_system\backup_worker.php" /sc minute /mo 15 /f /ru "SYSTEM" /rl highest

if %ERRORLEVEL% EQU 0 (
    echo.
    echo SUCCESS: Backup task created!
    echo.
    echo Task details:
    echo - Name: BookSystem_Backup
    echo - Schedule: Every 15 minutes
    echo - Runs as: SYSTEM account
    echo - Command: C:\xampp\php\php.exe C:\xampp\htdocs\book_system\backup_worker.php
    echo.
    echo Backups will be saved to: C:\xampp\mysql_backups\book_distribution_system\
    echo.
    echo To test the backup immediately, run:
    echo   C:\xampp\php\php.exe C:\xampp\htdocs\book_system\backup_worker.php
    echo.
    echo To view scheduled tasks:
    echo   schtasks /query /tn "BookSystem_Backup"
    echo.
    echo To delete this task later:
    echo   schtasks /delete /tn "BookSystem_Backup" /f
    echo.
) else (
    echo.
    echo FAILED: Could not create task.
    echo Please run this file as Administrator.
    echo.
    pause
)
