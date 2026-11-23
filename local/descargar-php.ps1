# Descarga PHP 8.3 portatil a la carpeta php\ (los binarios no se guardan en Git).
# Lo ejecuta iniciar.bat cuando falta php\php.exe. Verifica el SHA-256 oficial.
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$archivo = 'php-8.3.37-nts-Win32-vs16-x64.zip'
$sha256 = '38808a798485e1c0938dfcce7e942131415fc6c7834f83a5a85a4c4edb5539d6'
$destino = Join-Path (Split-Path $PSScriptRoot -Parent) 'php'
$zip = Join-Path $env:TEMP $archivo

# Las versiones anteriores se mueven a /archives/, por eso se prueban ambas rutas.
$descargado = $false
foreach ($base in 'https://windows.php.net/downloads/releases/', 'https://windows.php.net/downloads/releases/archives/') {
    try {
        Write-Host "Descargando $base$archivo ..."
        Invoke-WebRequest ($base + $archivo) -OutFile $zip -UserAgent 'Mozilla/5.0'
        $descargado = $true
        break
    } catch {
        Write-Host '  no disponible en esta ruta.'
    }
}
if (-not $descargado) { throw 'No se pudo descargar PHP. Revisa tu conexion a Internet.' }

if ((Get-FileHash $zip -Algorithm SHA256).Hash.ToLower() -ne $sha256) {
    Remove-Item $zip -Force
    throw 'El archivo descargado no coincide con el SHA-256 esperado. No se instalo.'
}

Expand-Archive -LiteralPath $zip -DestinationPath $destino -Force
Remove-Item $zip -Force
Write-Host "PHP instalado en $destino"
