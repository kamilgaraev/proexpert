@echo off
setlocal EnableExtensions

for %%I in ("%~dp0..\..\docker\geometry\geometry-sandbox.sh") do set "SANDBOX_WINDOWS=%%~fI"
for /f "usebackq delims=" %%I in (`wsl.exe -d Ubuntu-22.04 -- wslpath -a "%SANDBOX_WINDOWS%"`) do set "SANDBOX=%%I"
for /f "usebackq delims=" %%I in (`wsl.exe -d Ubuntu-22.04 -- wslpath -a "%~1"`) do set "WORKSPACE=%%I"
for /f "usebackq delims=" %%I in (`wsl.exe -d Ubuntu-22.04 -- wslpath -a "%~2"`) do set "STDOUT_PATH=%%I"
for /f "usebackq delims=" %%I in (`wsl.exe -d Ubuntu-22.04 -- wslpath -a "%~3"`) do set "STDERR_PATH=%%I"
wsl.exe -d Ubuntu-22.04 -- env "PATH=/home/%USERNAME%/.cache/most-geometry-sandbox:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" bash "%SANDBOX%" "%WORKSPACE%" "%STDOUT_PATH%" "%STDERR_PATH%" %4 %5 %6 %7 %8 "%~9"
exit /b %ERRORLEVEL%
