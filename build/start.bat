@echo off
title CangJingSushi Points System

:: Set app directory to current bat location
set "APP_DIR=%~dp0"
cd /d "%APP_DIR%"

:: PHP executable path
set "PHP_EXE=%APP_DIR%php\php.exe"

:: Check PHP exists
if not exist "%PHP_EXE%" (
    echo [ERROR] PHP runtime not found!
    echo Please ensure php\php.exe exists in the installation directory.
    pause
    exit /b 1
)

:: Check if already running
netstat -aon | findstr ":8899" | findstr "LISTENING" >nul 2>&1
if %ERRORLEVEL%==0 (
    echo.
    echo ========================================
    echo   CangJingSushi Points System
    echo ========================================
    echo.
    echo   System is already running!
    echo   Access: http://localhost:8899
    echo.
    echo   To restart, please run Stop first.
    echo ========================================
    echo.
    pause
    exit /b 0
)

:: Start PHP built-in server
echo.
echo ========================================
echo   CangJingSushi Points System
echo ========================================
echo.
echo   Starting server...

start /B "" "%PHP_EXE%" -S localhost:8899 -t "%APP_DIR%"

:: Wait for server startup
timeout /t 2 /nobreak >nul

:: Open default browser
start http://localhost:8899/dashboard.php

echo   Started successfully!
echo   If browser did not open, visit:
echo   http://localhost:8899
echo.
echo   Closing this window will stop the system.
echo ========================================
echo.

:: Wait for user to close
pause >nul

:: Stop PHP processes
for /f "tokens=5" %%a in ('netstat -aon ^| findstr ":8899" ^| findstr "LISTENING"') do (
    taskkill /F /PID %%a >nul 2>&1
)

echo System stopped.
