@echo off
( "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" "Y:\Apps\AERO\api\sync\sync_reference.php" ) > "Y:\Apps\AERO\api\cache\sync_reference.log" 2>&1
del /Q "Y:\Apps\AERO\api\cache\dashboard*.json"
