@echo off
setlocal EnableExtensions
title MLBD Scraper - local run
cd /d "%~dp0"

REM ---------------------------------------------------------------------------
REM  One click: API (FastAPI) + UI (Next.js) on this machine.
REM
REM    start-local.bat              start everything and open the browser
REM    start-local.bat nobrowser    start everything, do not open a browser
REM
REM  Ports are picked automatically: Windows reserves some ranges (Hyper-V /
REM  WinNAT), so 3000 is not always usable even when nothing is listening.
REM  Each part gets its own window; close it (or Ctrl+C in it) to stop it.
REM ---------------------------------------------------------------------------

set "API_HOST=127.0.0.1"
set "API_PORT=8000"
REM 4300 avoids the ranges Windows/Hyper-V reserve (often 2800-3400 and 6600-7200).
set "UI_PORT=4300"
set "PS=powershell -NoProfile -ExecutionPolicy Bypass"

set "OPEN_BROWSER=1"
if /i "%~1"=="nobrowser" set "OPEN_BROWSER=0"

echo ==========================================================
echo   MLBD Scraper  -  local API + web UI
echo ==========================================================
echo.

where python >nul 2>nul
if errorlevel 1 (
  echo [X] Python was not found in PATH.
  echo     Install Python 3.11+ and tick "Add python.exe to PATH".
  goto :failed
)

where npm >nul 2>nul
if errorlevel 1 (
  echo [X] npm was not found in PATH.
  echo     Install Node.js 18+ from https://nodejs.org
  goto :failed
)

if not exist ".env" (
  echo [!] .env is missing - Supabase mirroring stays off until you create it:
  echo     copy .env.example .env
  echo.
)

REM -- 1. Python dependencies ------------------------------------------------
python -c "import fastapi, uvicorn, httpx, playwright" >nul 2>nul
if errorlevel 1 (
  echo [1/4] Installing Python dependencies ...
  python -m pip install -r requirements.txt
  if errorlevel 1 (
    echo [X] pip install failed. Fix the error above and run this file again.
    goto :failed
  )
) else (
  echo [1/4] Python dependencies OK
)

REM -- 2. Web dependencies ---------------------------------------------------
if not exist "web\node_modules" (
  echo [2/4] Installing web dependencies ^(first run, may take a minute^) ...
  call npm --prefix web install
  if errorlevel 1 (
    echo [X] npm install failed. Fix the error above and run this file again.
    goto :failed
  )
) else (
  echo [2/4] Web dependencies OK
)

REM -- 3. API ----------------------------------------------------------------
netstat -ano | findstr /c:":%API_PORT%" | findstr /c:"LISTENING" >nul
if not errorlevel 1 goto :api_running

for /f "delims=" %%p in ('%PS% -Command "$p=8000; while($p -lt 8100){ try { $l=[System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Any,$p); $l.Start(); $l.Stop(); break } catch { $p++ } }; $p"') do set "API_PORT=%%p"
if not "%API_PORT%"=="8000" echo       Port 8000 is reserved or busy - using %API_PORT% instead
set "API_BASE=http://%API_HOST%:%API_PORT%"
echo [3/4] Starting API on %API_BASE% ...
%PS% -Command "Start-Process cmd.exe -ArgumentList '/k','python -m uvicorn api.main:app --host %API_HOST% --port %API_PORT%' -WorkingDirectory '%CD%' -WindowStyle Normal"
call :wait "%API_BASE%/health" "API"
if errorlevel 1 goto :failed
goto :ui_section

:api_running
set "API_BASE=http://%API_HOST%:%API_PORT%"
echo [3/4] API already running on %API_BASE% - reusing it

REM -- 4. UI -----------------------------------------------------------------
:ui_section
netstat -ano | findstr /c:":%UI_PORT%" | findstr /c:"LISTENING" >nul
if not errorlevel 1 goto :ui_running

for /f "delims=" %%p in ('%PS% -Command "$p=4300; while($p -lt 4700){ try { $l=[System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Any,$p); $l.Start(); $l.Stop(); break } catch { $p++ } }; $p"') do set "UI_PORT=%%p"
if not "%UI_PORT%"=="4300" echo       Port 4300 is reserved or busy - using %UI_PORT% instead
set "UI_URL=http://%API_HOST%:%UI_PORT%"

REM The UI reads its API base from web\.env.local (gitignored).
>"web\.env.local" echo NEXT_PUBLIC_API_BASE=%API_BASE%

echo [4/4] Starting UI on %UI_URL% ...
%PS% -Command "Start-Process cmd.exe -ArgumentList '/k','npm --prefix web run dev -- --port %UI_PORT%' -WorkingDirectory '%CD%' -WindowStyle Normal"
call :wait "%UI_URL%" "UI"
if errorlevel 1 goto :failed
goto :ready

:ui_running
set "UI_URL=http://%API_HOST%:%UI_PORT%"
echo [4/4] UI already running on %UI_URL% - reusing it

:ready
echo.
echo ==========================================================
echo   Ready
echo.
echo   UI        %UI_URL%
echo   API       %API_BASE%
echo   API docs  %API_BASE%/docs
echo   Library   %API_BASE%/library/stats
echo   Mirror    %API_BASE%/db/status
echo.
echo   Use the Library tab in the UI to save every title locally.
echo.
echo   Two windows are running: "MLBD API" and "MLBD UI".
echo   Close them to stop. The first search can take ~20s while
echo   the catalogue warms up.
echo.
echo   Public URL for a deployed UI: scripts\serve-public.ps1
echo ==========================================================

if "%OPEN_BROWSER%"=="1" start "" "%UI_URL%"
exit /b 0

:failed
echo.
echo [!] Something did not come up. Read the error in the "MLBD API" or
echo     "MLBD UI" window, then run this file again.
if "%OPEN_BROWSER%"=="1" pause
exit /b 1

REM -- wait for a URL to answer, up to ~60 seconds ---------------------------
:wait
%PS% -Command "$d=(Get-Date).AddSeconds(60); while((Get-Date) -lt $d){ try { $r=Invoke-WebRequest -UseBasicParsing -TimeoutSec 2 '%~1'; if($r.StatusCode -lt 400){ exit 0 } } catch {}; Start-Sleep -Milliseconds 800 }; exit 1"
if errorlevel 1 (
  echo       [X] %~2 did not answer at %~1 within 60s
  exit /b 1
)
echo       %~2 is up
exit /b 0
