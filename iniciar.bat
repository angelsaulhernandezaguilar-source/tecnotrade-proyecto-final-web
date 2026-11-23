@echo off
rem ============================================================
rem  TecnoTrade - servidor local (PHP incluido + tu MySQL)
rem  Doble clic para iniciar. Cierra esta ventana para detener.
rem ============================================================
title TecnoTrade - servidor local
cd /d "%~dp0"

set "PUERTO=8088"
set "URL=http://localhost:%PUERTO%/"

if not exist "php\php.exe" (
  echo Falta PHP en la carpeta php. Se descargara una sola vez ^(unos 30 MB^).
  powershell -NoProfile -ExecutionPolicy Bypass -File "local\descargar-php.ps1"
)
if not exist "php\php.exe" (
  echo No se pudo instalar PHP. Revisa tu conexion y vuelve a intentar.
  pause
  exit /b 1
)

sc query MySQL80 2>nul | find "RUNNING" >nul
if errorlevel 1 (
  echo AVISO: el servicio MySQL80 no parece estar iniciado.
  echo Abre "Servicios" de Windows e inicia MySQL80, o inicia MySQL desde XAMPP.
  echo.
)

if exist "server\config.local.php" (
  set "ABRIR=%URL%"
) else (
  set "ABRIR=%URL%instalar.php"
  echo Primera vez: se abrira el instalador para crear la base de datos.
  echo.
)

echo TecnoTrade en %URL%
echo Deja esta ventana abierta mientras usas la tienda.
echo Para detener el servidor cierra la ventana o pulsa Ctrl+C.
echo.

rem Abre el navegador dos segundos despues, cuando el servidor ya responde.
start "" /b cmd /c "ping -n 3 127.0.0.1 >nul & start %ABRIR%"

"php\php.exe" -c "php\php.ini" -S 127.0.0.1:%PUERTO% -t . "local\router.php"

echo.
echo El servidor se detuvo. Si el puerto %PUERTO% estaba ocupado, cierra la otra ventana de TecnoTrade.
pause
