; ============================================================
;  CangJingSushi Points Management System - Inno Setup Script
;  Compiled with Inno Setup 6
; ============================================================

#define MyAppName "CangJingSushi Points System"
#define MyAppId "CangJingSushiPointsSystem"
#define MyAppPublisher "FDE Engineer"
#define MyAppURL ""
; Version is provided by build.bat via /DMyAppVersion command-line define.
; Fallback default if not defined on command line.
#ifndef MyAppVersion
  #define MyAppVersion "1.0"
#endif
#define MyAppExeName "Launcher.exe"

[Setup]
AppId={#MyAppId}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppPublisherURL={#MyAppURL}
AppSupportURL={#MyAppURL}

; Default install path
DefaultDirName={autopf}\CangJingSushiPointsSystem
DefaultGroupName=CangJingSushi Points System

; Output
OutputDir=..\releases\v{#MyAppVersion}
OutputBaseFilename=PointsSystem_v{#MyAppVersion}_setup
Compression=lzma2/ultra64
SolidCompression=yes

; UI
WizardStyle=modern
DisableWelcomePage=no
ShowLanguageDialog=no

; Permissions
PrivilegesRequired=admin

; Uninstall
UninstallDisplayIcon={app}\Launcher.exe
UninstallDisplayName={#MyAppName}
ChangesAssociations=no

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create desktop shortcut"; GroupDescription: "Shortcuts:"
Name: "startmenuicon"; Description: "Create Start Menu shortcut"; GroupDescription: "Shortcuts:"

[Files]
; Source directory - prepare_build.bat prepares files to output\PointsSystem_vX.X\
Source: "..\build\output\PointsSystem_v{#MyAppVersion}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs; Excludes: "*.pdb,*.tmp"

[Icons]
; Desktop shortcut
Name: "{autodesktop}\CangJingSushi Points System"; Filename: "{app}\Launcher.exe"; Tasks: desktopicon
; Start Menu shortcut
Name: "{autoprograms}\CangJingSushi Points System"; Filename: "{app}\Launcher.exe"; Tasks: startmenuicon
; Start Menu - Uninstall shortcut
Name: "{autoprograms}\Uninstall CangJingSushi Points System"; Filename: "{uninstallexe}"

[Run]
; Run after install
Filename: "{app}\Launcher.exe"; Description: "Launch CangJingSushi Points System"; Flags: nowait postinstall shellexec

[UninstallRun]
; Stop PHP server on uninstall
Filename: "taskkill"; Parameters: "/F /IM php.exe"; Flags: runhidden; StatusMsg: "Stopping server..."; RunOnceId: KillPHP
