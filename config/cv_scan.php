<?php

return [
    'engine' => env('CV_SCAN_ENGINE', PHP_OS_FAMILY === 'Windows' ? 'defender' : 'clamav'),
    'executable' => env('CV_SCAN_EXECUTABLE', PHP_OS_FAMILY === 'Windows'
        ? 'C:/Program Files/Windows Defender/MpCmdRun.exe' : '/usr/bin/clamscan'),
    'timeout' => (int) env('CV_SCAN_TIMEOUT', 30),
];
