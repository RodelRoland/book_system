@echo off
echo Running backup now...
echo.

C:\xampp\php\php.exe C:\xampp\htdocs\book_system\backup_worker.php

echo.
echo Backup completed. Check C:\xampp\mysql_backups\book_distribution_system\ for backup files.
echo.
pause
