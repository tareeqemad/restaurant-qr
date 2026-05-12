@echo off
REM ─────────────────────────────────────────────────────────────────
REM  Reverb WebSocket server — required for realtime updates.
REM  Keep this terminal window open while the restaurant app is in use.
REM  Close the window to stop Reverb.
REM
REM  To use: double-click this file, or run `start-reverb.bat` from cmd.
REM ─────────────────────────────────────────────────────────────────
cd /d "%~dp0"
title Reverb (realtime server) — 127.0.0.1:9090
echo.
echo  Starting Reverb on 127.0.0.1:9090 ...
echo  (keep this window open; close it to stop)
echo.
php artisan reverb:start --host=127.0.0.1 --port=9090
pause
