param(
    [string]$HostName = 'smsportal.book-my.eu'
)

$ErrorActionPreference = 'Stop'
$failures = [System.Collections.Generic.List[string]]::new()
$curl = (Get-Command curl.exe -ErrorAction Stop).Source

function Invoke-CurlText([string[]]$Arguments) {
    $output = & $curl @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) { throw "curl non riuscito: $($output -join ' ')" }
    return ($output -join "`n")
}

$httpsUrl = "https://$HostName/"
$httpHeaders = Invoke-CurlText @('--silent', '--show-error', '--head', '--max-time', '15', "http://$HostName/")
if ($httpHeaders -notmatch '(?im)^HTTP/\S+\s+(301|308)\b' -or $httpHeaders -notmatch '(?im)^Location:\s*https://') {
    $failures.Add('HTTP non viene reindirizzato in modo permanente a HTTPS.')
}

$httpsHeaders = Invoke-CurlText @('--silent', '--show-error', '--head', '--max-time', '15', $httpsUrl)
foreach ($header in @('Strict-Transport-Security', 'Content-Security-Policy', 'X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy')) {
    if ($httpsHeaders -notmatch "(?im)^$([regex]::Escape($header)):") { $failures.Add("Header mancante: $header") }
}
$cookies = [regex]::Matches($httpsHeaders, '(?im)^Set-Cookie:\s*(.+)$') | ForEach-Object { $_.Groups[1].Value }
if ($cookies.Count -eq 0) { $failures.Add('Cookie di sessione non rilevato.') }
foreach ($cookie in $cookies) {
    if ($cookie -match '^sms_portal_session=' -and ($cookie -notmatch '(?i);\s*Secure' -or $cookie -notmatch '(?i);\s*HttpOnly' -or $cookie -notmatch '(?i);\s*SameSite=')) {
        $failures.Add('Il cookie di sessione non contiene Secure, HttpOnly e SameSite.')
    }
}

$sensitivePaths = @(
    '/.git/HEAD', '/.git/config', '/.htaccess', '/web.config', '/classes/config.local.php',
    '/storage/config.local.php', '/storage/install.lock', '/storage/.installing.lock',
    '/app/Core/Router.php', '/inc/option.php', '/chrome-extension.pem', '/localhost.sql', '/docs/SECURITY.md',
    '/deployment/lighttpd-security.conf.example', '/deployment/openresty-security.conf.example', '/scripts/security-check.ps1'
)
foreach ($path in $sensitivePaths) {
    $status = Invoke-CurlText @('--silent', '--show-error', '--output', 'NUL', '--write-out', '%{http_code}', '--max-time', '15', "https://$HostName$path")
    if ($status -notin @('403', '404')) { $failures.Add("Percorso sensibile pubblicamente raggiungibile ($status): $path") }
}

if ($failures.Count -gt 0) {
    $failures | ForEach-Object { [Console]::Error.WriteLine("ERRORE: $_") }
    exit 1
}

Write-Host "Audit esterno superato per $HostName." -ForegroundColor Green
