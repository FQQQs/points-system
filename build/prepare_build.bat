@echo off
setlocal enabledelayedexpansion

:: ============================================================
::  prepare_build.bat - Build Preparation Script
::  Read version, prepare files to output directory
:: ============================================================

set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
set "CONFIG_FILE=%SCRIPT_DIR%build_config.ini"

:: Read version
set /p APP_VERSION=<"%PROJECT_ROOT%\VERSION"
echo [INFO] Current version: v%APP_VERSION%

:: Set output directory
set "OUTPUT_DIR=%SCRIPT_DIR%output\PointsSystem_v%APP_VERSION%"
echo [INFO] Output dir: %OUTPUT_DIR%

:: Clean old output
if exist "%OUTPUT_DIR%" (
    echo [INFO] Cleaning old output dir...
    rmdir /S /Q "%OUTPUT_DIR%" 2>nul
)

:: Create output directory structure
mkdir "%OUTPUT_DIR%"
mkdir "%OUTPUT_DIR%\assets\css"
mkdir "%OUTPUT_DIR%\includes"
mkdir "%OUTPUT_DIR%\pages"
mkdir "%OUTPUT_DIR%\vendor"
mkdir "%OUTPUT_DIR%\data\uploads"
mkdir "%OUTPUT_DIR%\php"

:: Copy PHP files
echo [INFO] Copying PHP files...
copy /Y "%PROJECT_ROOT%\index.php" "%OUTPUT_DIR%\" >nul
copy /Y "%PROJECT_ROOT%\dashboard.php" "%OUTPUT_DIR%\" >nul
copy /Y "%PROJECT_ROOT%\employee.php" "%OUTPUT_DIR%\" >nul

:: Copy assets
echo [INFO] Copying assets...
xcopy /Y /Q "%PROJECT_ROOT%\assets\css\*" "%OUTPUT_DIR%\assets\css\" >nul

:: Copy includes
echo [INFO] Copying includes...
xcopy /Y /Q "%PROJECT_ROOT%\includes\*" "%OUTPUT_DIR%\includes\" >nul

:: Copy pages
echo [INFO] Copying pages...
xcopy /Y /Q "%PROJECT_ROOT%\pages\*" "%OUTPUT_DIR%\pages\" >nul

:: Copy vendor
echo [INFO] Copying vendor dependencies...
xcopy /Y /E /Q "%PROJECT_ROOT%\vendor\*" "%OUTPUT_DIR%\vendor\" >nul

:: Prepare data directory (no database - will be auto-created on first run)
echo [INFO] Preparing data directory...
if exist "%OUTPUT_DIR%\data\points.db" del "%OUTPUT_DIR%\data\points.db"

:: Copy start/stop scripts
echo [INFO] Copying launcher scripts...
copy /Y "%SCRIPT_DIR%start.bat" "%OUTPUT_DIR%\" >nul
copy /Y "%SCRIPT_DIR%stop.bat" "%OUTPUT_DIR%\" >nul

:: Copy Launcher.exe (the main desktop executable)
echo [INFO] Copying Launcher.exe...
if exist "%SCRIPT_DIR%Launcher.exe" (
    copy /Y "%SCRIPT_DIR%Launcher.exe" "%OUTPUT_DIR%\Launcher.exe" >nul
    echo [INFO] Launcher.exe copied
) else (
    echo [WARN] Launcher.exe not found! Compile with: csc.exe /target:winexe /out:build\Launcher.exe build\Launcher.cs
)

:: Copy PHP runtime
echo [INFO] Copying PHP runtime...
set "PHP_SOURCE=C:\Users\Administrator\AppData\Local\php"

if exist "%PHP_SOURCE%\php.exe" (
    copy /Y "%PHP_SOURCE%\php.exe" "%OUTPUT_DIR%\php\" >nul

    :: Copy DLL files
    for %%f in (
        php8.dll
        php8ts.dll
        php8sqlite.dll
        php_pdo_sqlite.dll
        php_sqlite3.dll
        libsqlite3.dll
        php_cli.dll
        icu*.dll
        libssh2.dll
        nghttp2.dll
        libcrypto*.dll
        libssl*.dll
        zlib*.dll
        libsodium.dll
    ) do (
        if exist "%PHP_SOURCE%\%%f" (
            copy /Y "%PHP_SOURCE%\%%f" "%OUTPUT_DIR%\php\" >nul 2>&1
        )
    )

    :: Copy php.ini if exists
    if exist "%PHP_SOURCE%\php.ini" (
        copy /Y "%PHP_SOURCE%\php.ini" "%OUTPUT_DIR%\php\" >nul
    )

    :: Copy ext directory
    if exist "%PHP_SOURCE%\ext" (
        mkdir "%OUTPUT_DIR%\php\ext" 2>nul
        xcopy /Y /Q "%PHP_SOURCE%\ext\*" "%OUTPUT_DIR%\php\ext\" >nul 2>&1
    )
    echo [INFO] PHP runtime copied successfully
) else (
    echo [WARN] PHP source not found: %PHP_SOURCE%
    echo [WARN] Please manually copy PHP runtime to %OUTPUT_DIR%\php\
)

:: Update build_config.ini
echo [INFO] Updating config file...
> "%CONFIG_FILE%" (
    echo [App]
    echo AppName=PointsManagementSystem
    echo AppId=CangJingSushiPointsSystem
    echo AppPublisher=FDE Engineer
    echo AppPublisherURL=
    echo AppVersion=%APP_VERSION%
    echo DefaultPort=8899
    echo DefaultGroupName=PointsManagementSystem
    echo.
    echo [Paths]
    echo PhpSourcePath=C:\Users\Administrator\AppData\Local\php
    echo ProjectRoot=%PROJECT_ROOT%
    echo OutputDir=%OUTPUT_DIR%
    echo ReleasesDir=%PROJECT_ROOT%\releases
)

echo.
echo [DONE] Build files ready!
echo   Version: v%APP_VERSION%
echo   Directory: %OUTPUT_DIR%
echo.

endlocal
