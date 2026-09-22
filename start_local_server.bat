@echo off
title Mudra Archive - Local Development Server
echo ========================================================
echo Starting Mudra Archive on http://localhost:8000
echo Clean URLs are handled by router.php (Apache uses .htaccess).
echo Press Ctrl+C in this window to stop the server.
echo ========================================================
echo.

REM Uses whichever PHP is on your PATH.
REM If PHP is not on your PATH, set PHP_EXE to its full path, e.g.
REM   set PHP_EXE="C:\php\php.exe"
if not defined PHP_EXE set PHP_EXE=php

where %PHP_EXE% >nul 2>nul
if errorlevel 1 (
    echo ERROR: PHP was not found on your PATH.
    echo Install PHP 7.4+ with the pdo_sqlite and gd extensions, then try again.
    pause
    exit /b 1
)

start http://localhost:8000
%PHP_EXE% -S localhost:8000 router.php
pause
