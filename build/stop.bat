@echo off
title Stop Points System

echo.
echo Stopping CangJingSushi Points System...

:: Find and kill PHP process by port
for /f "tokens=5" %%a in ('netstat -aon ^| findstr ":8899" ^| findstr "LISTENING"') do (
    echo   Stopping process PID: %%a
    taskkill /F /PID %%a >nul 2>&1
)

:: Cleanup any remaining php.exe processes
tasklist /FI "IMAGENAME eq php.exe" 2>NUL | find /I "php.exe" >NUL
if %ERRORLEVEL%==0 (
    echo   Cleaning up residual PHP processes...
    taskkill /F /IM php.exe >nul 2>&1
)

echo.
echo System stopped.
timeout /t 2 /nobreak >nul
