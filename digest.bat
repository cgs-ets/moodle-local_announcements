cd c:\cron\announcements

rem Create a timestamp
for /f "usebackq tokens=1,2 delims=,=- " %%i in (`wmic os get LocalDateTime /value`) do @if %%i==LocalDateTime (
     set localtime=%%j
)
set "timestamp=%localtime:~0,8%-%localtime:~8,4%"
set "datestamp=%localtime:~0,8%"
for /F "tokens=1-2 delims=:.," %%a in ("%time%") do (
    set "startstring=%%a:%%b"
)
rem Create the tempfile filename
set "tempfile=digesttemp%timestamp%.txt"
set "logfile=digest%datestamp%.txt"

rem Run the digest cron.
echo %date% %startstring% - Running digest >> %tempfile%
C:\php\php8.3.9\php.exe C:\inetpub\wwwroot\moodle\local\announcements\cli\digest.php >> %tempfile%
echo ---------------------------------------- >> %tempfile%

type local_announcements\%logfile% >> %tempfile%
ren %tempfile% %logfile%
del local_announcements\%logfile%
move %logfile% local_announcements\