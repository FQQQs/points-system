@echo off
setlocal enabledelayedexpansion

:: ============================================================
::  build.bat - Points System Build Script
::  Usage: build.bat              (use version from VERSION file)
::         build.bat 1.1          (use specified version)
::         build.bat 1.1 "Bugfix" (specify version and release notes)
:: ============================================================

set "SCRIPT_DIR=%~dp0"
set "BUILD_DIR=%SCRIPT_DIR%build"
set "PROJECT_ROOT=%SCRIPT_DIR%"
set "NOTES=%~2"

echo.
echo ========================================================
echo   CangJingSushi Points System - Build Tool
echo ========================================================
echo.

:: Determine version
if "%~1"=="" (
    :: Read from VERSION file
    set /p CURRENT_VERSION=<"%PROJECT_ROOT%\VERSION"
    if "!CURRENT_VERSION!"=="" (
        echo [ERROR] Cannot read version from VERSION file.
        pause
        exit /b 1
    )
) else (
    :: Use parameter as version
    set "CURRENT_VERSION=%~1"
    :: Also update VERSION file (using > with proper escaping)
    > "%PROJECT_ROOT%\VERSION" echo %~1
    echo [INFO] Version updated in VERSION file: v%~1
)

echo [INFO] Building version: v!CURRENT_VERSION!

:: Check Inno Setup installation
set "ISCC_PATH="
if exist "C:\Program Files (x86)\Inno Setup 6\ISCC.exe" (
    set "ISCC_PATH=C:\Program Files (x86)\Inno Setup 6\ISCC.exe"
)
if exist "C:\Program Files\Inno Setup 6\ISCC.exe" (
    set "ISCC_PATH=C:\Program Files\Inno Setup 6\ISCC.exe"
)
if exist "%LOCALAPPDATA%\Programs\Inno Setup 6\ISCC.exe" (
    set "ISCC_PATH=%LOCALAPPDATA%\Programs\Inno Setup 6\ISCC.exe"
)

if "%ISCC_PATH%"=="" (
    echo [ERROR] Inno Setup not found!
    echo [HINT] Install Inno Setup 6: https://jrsoftware.org/isdl.php
    pause
    exit /b 1
)
echo [INFO] Inno Setup: %ISCC_PATH%

:: Step 0: Compile Launcher.exe (desktop launcher)
echo.
echo [Step 0/4] Compiling Launcher.exe...
set "CSC_PATH=C:\Windows\Microsoft.NET\Framework64\v4.0.30319\csc.exe"
set "LAUNCHER_CS=%BUILD_DIR%\Launcher.cs"
set "LAUNCHER_EXE=%BUILD_DIR%\Launcher.exe"

if not exist "!LAUNCHER_EXE!" (
    echo [INFO] Compiling Launcher.cs...
    powershell.exe -Command "& '!CSC_PATH!' /nologo /target:winexe /out:'!LAUNCHER_EXE!' /reference:'System.Windows.Forms.dll' /reference:'System.Drawing.dll' '!LAUNCHER_CS!'" 2>&1
    if !ERRORLEVEL! neq 0 (
        echo [WARN] Launcher compilation failed! Using start.bat instead.
    ) else (
        echo [INFO] Launcher.exe compiled successfully
    )
) else (
    echo [INFO] Launcher.exe already exists, skipping compilation
)

:: Step 1: Prepare build files
echo.
echo [Step 1/4] Preparing build files...
call "%BUILD_DIR%\prepare_build.bat"
if %ERRORLEVEL% neq 0 (
    echo [ERROR] Build preparation failed!
    pause
    exit /b 1
)

:: Step 2: Compile installer
echo.
echo [Step 2/4] Compiling installer...

set "ISS_FILE=%BUILD_DIR%\installer.iss"
set "RELEASE_DIR=%PROJECT_ROOT%\releases\v!CURRENT_VERSION!"

:: Create release directory
if not exist "!RELEASE_DIR!" mkdir "!RELEASE_DIR!"

:: Compile via PowerShell to avoid path issues
powershell.exe -Command "& '%ISCC_PATH%' '/O!RELEASE_DIR!' '/DMyAppVersion=!CURRENT_VERSION!' '%ISS_FILE%'" 2>&1

if %ERRORLEVEL% neq 0 (
    echo [ERROR] Installer compilation failed!
    pause
    exit /b 1
)

:: Step 3: Verify output
echo.
echo [Step 3/4] Verifying output...

set "SETUP_EXE=!RELEASE_DIR!\PointsSystem_v!CURRENT_VERSION!_setup.exe"

if exist "!SETUP_EXE!" (
    for %%A in ("!SETUP_EXE!") do set "FILE_SIZE=%%~zA"
    set /a "FILE_SIZE_MB=!FILE_SIZE! / 1048576"
    echo.
    echo ========================================================
    echo   Build Complete!
    echo ========================================================
    echo.
    echo   Version:   v!CURRENT_VERSION!
    if not "!NOTES!"=="" echo   Notes: !NOTES!
    echo   File:      !SETUP_EXE!
    echo   Size:      !FILE_SIZE_MB! MB
    echo   Directory: !RELEASE_DIR!\
    echo.
    echo ========================================================
) else (
    echo [WARN] Setup file not found!
    echo   Expected: !SETUP_EXE!
)

echo.
endlocal
