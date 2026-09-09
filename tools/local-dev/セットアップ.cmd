@echo off
setlocal
title RISE GATE Development Setup
echo RISE GATE Development Setup
echo.
if not exist "%~dp0setup.ps1" (
    echo Setup files are missing. Extract ALL files from the ZIP, then try again.
    pause
    exit /b 1
)
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup.ps1"
set "SETUP_EXIT=%ERRORLEVEL%"
echo.
if not "%SETUP_EXIT%"=="0" echo Setup failed. Please keep the error message above.
echo Press any key to close this window.
pause
exit /b %SETUP_EXIT%
