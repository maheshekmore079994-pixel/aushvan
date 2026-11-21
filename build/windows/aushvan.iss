; Inno Setup script for the AUSHVAN Windows installer. Built by make-desktop.ps1, which passes the defines.
; Installs for the current user (no administrator needed) under %LOCALAPPDATA%\Programs\AUSHVAN; the shop's
; data lives in %LOCALAPPDATA%\AUSHVAN\data and is never touched by install, update or uninstall.
#ifndef AppVersion
  #define AppVersion "0.9.0"
#endif
#ifndef SourceDir
  #define SourceDir "dist\AUSHVAN"
#endif
#ifndef OutDir
  #define OutDir "out"
#endif

[Setup]
AppId={{7E1C5B70-3A7B-4D5E-9C1A-AUSHVAN0001}
AppName=AUSHVAN
AppVersion={#AppVersion}
AppPublisher=TriMora Technologies
AppPublisherURL=https://github.com/maheshekmore079994-pixel/aushvan
DefaultDirName={localappdata}\Programs\AUSHVAN
DefaultGroupName=AUSHVAN
DisableProgramGroupPage=yes
PrivilegesRequired=lowest
OutputDir={#OutDir}
OutputBaseFilename=AUSHVAN-Setup-{#AppVersion}
Compression=lzma2/max
SolidCompression=yes
WizardStyle=modern
UninstallDisplayName=AUSHVAN — Medical & General Store Management
CloseApplications=yes
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Files]
Source: "{#SourceDir}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\AUSHVAN"; Filename: "{app}\AUSHVAN.exe"
Name: "{autodesktop}\AUSHVAN"; Filename: "{app}\AUSHVAN.exe"

[Run]
Filename: "{app}\AUSHVAN.exe"; Description: "Start AUSHVAN"; Flags: nowait postinstall skipifsilent

[UninstallDelete]
Type: filesandordirs; Name: "{app}\app\storage\framework"
Type: filesandordirs; Name: "{app}\app\bootstrap\cache"
