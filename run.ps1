param(
    [ValidateSet('start', 'dev', 'test', 'test-pg', 'test-concurrency', 'check', 'db-start', 'db-status', 'db-stop')]
    [string]$Task = 'start'
)
$ErrorActionPreference = 'Stop'
$runtime = Join-Path $PSScriptRoot '..\..\..\.tools\php-8.4.26'
$php = Join-Path $runtime 'php.exe'
if (!(Test-Path -LiteralPath $php)) { $php = (Get-Command php -ErrorAction Stop).Source }
$oldOpenSsl = $env:OPENSSL_CONF
$savedEnvironment = @{}
$databaseVariables = @('APP_ENV','DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','DB_URL','RUN_PG_CONCURRENCY')
foreach ($name in $databaseVariables) { $savedEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
try {
    $sslConfig = Join-Path $runtime 'extras\ssl\openssl.cnf'
    if (Test-Path -LiteralPath $sslConfig) { $env:OPENSSL_CONF = (Resolve-Path -LiteralPath $sslConfig).Path }
    Push-Location -LiteralPath $PSScriptRoot
    try {
        switch ($Task) {
            'test' { & $php artisan test --compact }
            { $_ -in 'test-pg', 'test-concurrency' } {
                $env:APP_ENV = 'testing'
                $env:DB_CONNECTION = 'pgsql'
                $env:DB_HOST = '127.0.0.1'
                $env:DB_PORT = '54329'
                $env:DB_DATABASE = if ($Task -eq 'test-concurrency') { 'internship_concurrency_test' } else { 'internship_test' }
                $env:DB_USERNAME = 'internship_test'
                $env:DB_PASSWORD = [IO.File]::ReadAllText((Join-Path $PSScriptRoot '../../../.tools/pg-test-runtime/credential.txt')).Trim()
                $env:DB_URL = ''
                if ($Task -eq 'test-concurrency') {
                    $env:RUN_PG_CONCURRENCY = '1'
                    & $php artisan test --compact --group=postgres-concurrency
                } else {
                    $env:RUN_PG_CONCURRENCY = '0'
                    & $php artisan test --compact --exclude-group=postgres-concurrency
                }
            }
            'check' { & $php artisan route:list --except-vendor }
            { $_ -in 'db-start', 'db-status', 'db-stop' } {
                $clusterRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../../.tools/pg-test-runtime'))
                $clusterData = Join-Path $clusterRoot 'data'
                $pgControl = 'C:/Program Files/PostgreSQL/18/bin/pg_ctl.exe'
                if (!(Test-Path -LiteralPath (Join-Path $clusterData 'PG_VERSION'))) { throw 'Local cluster is not initialized. See README.md.' }
                if ($Task -eq 'db-start') {
                    & $pgControl -D $clusterData status
                    if ($LASTEXITCODE -ne 0) { & $pgControl -D $clusterData -l (Join-Path $clusterRoot 'server.log') -o '-h 127.0.0.1 -p 54329' -w -t 30 start }
                } elseif ($Task -eq 'db-stop') {
                    & $pgControl -D $clusterData -m fast -w -t 30 stop
                } else { & $pgControl -D $clusterData status }
            }
            default { & $php artisan serve --host=127.0.0.1 --port=3001 }
        }
        $result = $LASTEXITCODE
    } finally { Pop-Location }
} finally {
    $env:OPENSSL_CONF = $oldOpenSsl
    foreach ($name in $databaseVariables) { [Environment]::SetEnvironmentVariable($name, $savedEnvironment[$name], 'Process') }
}
exit $result
