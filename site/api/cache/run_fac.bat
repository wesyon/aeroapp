@echo off
echo running>"Y:\Apps\AERO\api\cache\run_fac.status"
( "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" "Y:\Apps\AERO\api\sync\sync_fac.php" --since=2026-06-06 --safe && "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" "Y:\Apps\AERO\api\sync\parse_findings.php" && "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" "Y:\Apps\AERO\api\sync\compute_scores.php" ) > "Y:\Apps\AERO\api\cache\sync_fac.log" 2>&1
set RC=%errorlevel%
del /Q "Y:\Apps\AERO\api\cache\dashboard*.json" >nul 2>&1
if "%RC%"=="0" (echo done>"Y:\Apps\AERO\api\cache\run_fac.status") else (echo failed>"Y:\Apps\AERO\api\cache\run_fac.status")
