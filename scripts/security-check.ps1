$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$failures = [System.Collections.Generic.List[string]]::new()

Get-ChildItem -LiteralPath $root -Recurse -Filter '*.php' | ForEach-Object {
    & php -l $_.FullName | Out-Null
    if ($LASTEXITCODE -ne 0) { $failures.Add("Sintassi PHP non valida: $($_.FullName)") }
}

$trackedSensitive = & git -C $root ls-files |
    Where-Object { Test-Path -LiteralPath (Join-Path $root $_) } |
    Select-String -Pattern '^(logs|uploads)/|\.sql$|config\.local\.php$|\.env$|\.lnk$|\.(pem|key)$'
if ($trackedSensitive) { $failures.Add("File sensibili versionati:`n$($trackedSensitive -join "`n")") }

$knownSecrets = @('Ust2lm10bz', '8M1rYS1UNsjNr32v')
foreach ($secret in $knownSecrets) {
    $matches = & git -C $root grep -n -I -- $secret -- . ':(exclude)scripts/security-check.ps1' ':(exclude)docs/SECURITY.md' 2>$null
    if ($LASTEXITCODE -eq 0 -and $matches) { $failures.Add("Segreto storico ancora versionato: $secret") }
}

if ($failures.Count -gt 0) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'Controlli statici di sicurezza superati.' -ForegroundColor Green
