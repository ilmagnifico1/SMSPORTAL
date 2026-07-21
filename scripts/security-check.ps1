$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$failures = [System.Collections.Generic.List[string]]::new()

Get-ChildItem -LiteralPath $root -Recurse -Filter '*.php' | ForEach-Object {
    & php -l $_.FullName | Out-Null
    if ($LASTEXITCODE -ne 0) { $failures.Add("Sintassi PHP non valida: $($_.FullName)") }
}

$allowedGeneratedFiles = @('database/schema.sql', 'logs/.gitkeep', 'uploads/.gitkeep')
$trackedSensitive = & git -C $root ls-files |
    Where-Object { Test-Path -LiteralPath (Join-Path $root $_) } |
    Where-Object { $_ -notin $allowedGeneratedFiles } |
    Select-String -Pattern '^(logs|uploads)/|\.sql$|config\.local\.php$|\.env$|\.lnk$|\.(pem|key)$'
if ($trackedSensitive) { $failures.Add("File sensibili versionati:`n$($trackedSensitive -join "`n")") }

$schemaData = Select-String -LiteralPath (Join-Path $root 'database/schema.sql') -Pattern '^\s*(INSERT|REPLACE|LOAD\s+DATA)\s' -CaseSensitive:$false
if ($schemaData) { $failures.Add('Lo schema SQL contiene istruzioni di caricamento dati.') }

$knownSecrets = @((Get-Item -Path Env:SMS_SECRET_SCAN_VALUES -ErrorAction SilentlyContinue).Value -split '[,\r\n]+' | Where-Object { $_.Trim().Length -ge 8 })
foreach ($secret in $knownSecrets) {
    $secret = $secret.Trim()
    $matches = & git -C $root grep -n -I -- $secret -- . ':(exclude)scripts/security-check.ps1' ':(exclude)docs/SECURITY.md' 2>$null
    if ($LASTEXITCODE -eq 0 -and $matches) { $failures.Add('Uno dei segreti indicati in SMS_SECRET_SCAN_VALUES è ancora versionato.') }
}

if ($failures.Count -gt 0) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'Controlli statici di sicurezza superati.' -ForegroundColor Green
