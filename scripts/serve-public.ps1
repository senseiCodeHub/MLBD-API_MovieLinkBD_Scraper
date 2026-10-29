<#
.SYNOPSIS
  Run the MLBD API on this machine and expose it on a public HTTPS URL.

.DESCRIPTION
  MovieLinkBD serves a Cloudflare challenge to cloud/datacentre IPs, so the API
  cannot read the catalogue on Render, Railway, Fly.io or Vercel. From a normal
  residential connection it works. This script starts the API locally and opens
  a Cloudflare quick tunnel so the deployed UI (Vercel, Netlify, …) can reach it.

  The tunnel URL is random and changes on every run: after restarting, update
  NEXT_PUBLIC_API_BASE on your host and redeploy the frontend.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts/serve-public.ps1
  powershell -ExecutionPolicy Bypass -File scripts/serve-public.ps1 -Port 8000
#>
param(
  [int]$Port = 8000,
  [string]$Python = "python"
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$logDir = Join-Path $env:TEMP "mlbd-public"
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$apiLog = Join-Path $logDir "api.log"
$apiErr = Join-Path $logDir "api.err.log"
$tunnelLog = Join-Path $logDir "tunnel.log"
$tunnelErr = Join-Path $logDir "tunnel.err.log"

Write-Host "Starting API on http://127.0.0.1:$Port ..."
$api = Start-Process -FilePath $Python `
  -ArgumentList @("-m", "uvicorn", "api.main:app", "--host", "127.0.0.1", "--port", "$Port") `
  -WorkingDirectory $root -PassThru -NoNewWindow `
  -RedirectStandardOutput $apiLog -RedirectStandardError $apiErr

$tunnel = $null
try {
  $ready = $false
  for ($i = 0; $i -lt 120; $i++) {
    try {
      $res = Invoke-WebRequest -UseBasicParsing -TimeoutSec 2 "http://127.0.0.1:$Port/health"
      if ($res.StatusCode -eq 200) { $ready = $true; break }
    } catch {
      Start-Sleep -Milliseconds 500
    }
  }
  if (-not $ready) {
    Write-Host "The API did not start. Check $apiErr"
    exit 1
  }
  Write-Host "API ready. Warming the catalogue in the background (first search can take ~20 s)."

  Write-Host "Opening a Cloudflare quick tunnel ..."
  $tunnel = Start-Process -FilePath "npx.cmd" `
    -ArgumentList @("--yes", "cloudflared", "tunnel", "--url", "http://127.0.0.1:$Port") `
    -PassThru -NoNewWindow `
    -RedirectStandardOutput $tunnelLog -RedirectStandardError $tunnelErr

  $url = $null
  for ($i = 0; $i -lt 120; $i++) {
    foreach ($file in @($tunnelErr, $tunnelLog)) {
      if (Test-Path $file) {
        $match = Select-String -Path $file -Pattern "https://[a-z0-9-]+\.trycloudflare\.com" -AllMatches |
          Select-Object -First 1
        if ($match) { $url = $match.Matches[0].Value; break }
      }
    }
    if ($url) { break }
    Start-Sleep -Milliseconds 500
  }
  if (-not $url) {
    Write-Host "Could not read the tunnel URL yet. Check $tunnelErr"
    exit 1
  }

  Write-Host ""
  Write-Host "  Public API: $url" -ForegroundColor Green
  Write-Host ""
  Write-Host "  Set NEXT_PUBLIC_API_BASE = $url on your frontend host, then redeploy."
  Write-Host "  This URL changes every time the tunnel restarts."
  Write-Host ""
  Write-Host "  Keep this window open. Press Ctrl+C to stop."
  Write-Host ""

  while (-not $api.HasExited) { Start-Sleep -Seconds 2 }
}
finally {
  foreach ($proc in @($tunnel, $api)) {
    if ($proc -and -not $proc.HasExited) {
      Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue
    }
  }
}
