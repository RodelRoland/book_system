@echo off
echo Installing MySQL 8.0...
echo.

REM Check if running as administrator
net session >nul 2>&1
if %errorLevel% == 0 (
    echo Running as Administrator - Good!
) else (
    echo Please run this script as Administrator!
    echo Right-click the file and select "Run as administrator"
    pause
    exit
)

echo.
echo Step 1: Initializing MySQL 8.0...
cd /d C:\mysql8\bin
mysqld.exe --initialize --console

echo.
echo Step 2: Installing MySQL 8.0 service...
mysqld.exe --install MySQL8 --defaults-file=C:\mysql8\my.ini

echo.
echo Step 3: Starting MySQL 8.0 service...
net start MySQL8

echo.
echo MySQL 8.0 installation complete!
echo.
echo IMPORTANT: Look above for the temporary root password
echo It will be shown after "A temporary password is generated for root@localhost: "
echo Save this password - you'll need it to set your permanent password
echo.
echo Next steps:
echo 1. Connect: mysql -u root -p -P 3307
echo 2. Set password: ALTER USER 'root'@'localhost' IDENTIFIED BY 'your_password';
echo 3. Create database: CREATE DATABASE book_distribution_system;
echo.
pause
