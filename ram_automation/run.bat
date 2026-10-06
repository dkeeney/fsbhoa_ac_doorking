@echo off
rem Manual test trigger for DK_Sync.ahk. Run from Y:\Automation\DoorKing.
rem Usage: run.bat ^<testbed^|production^> ^<host^>   e.g. run.bat testbed testbed.fsbhoa.com
if "%~2"=="" (echo Usage: run.bat ^<testbed^|production^> ^<host^> & exit /b 1)
if /i "%~1"=="testbed" (set DIR=To_RAM_Testbed) else if /i "%~1"=="production" (set DIR=To_RAM_Production) else (echo Unknown environment "%~1" & exit /b 1)
del /q logs\automation_%~1.log
ren %DIR%\updates*.csv updates.csv
(echo env=%~1& echo host=%~2) > %DIR%\import_now.flag
