<?php

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

try {
    $connection = DB::connection();
    if (! $app->environment('testing') || getenv('RUN_PG_CONCURRENCY') !== '1'
        || $connection->getDriverName() !== 'pgsql'
        || $connection->getDatabaseName() !== 'internship_concurrency_test'
        || $connection->getConfig('host') !== '127.0.0.1'
        || (string) $connection->getConfig('port') !== '54329') {
        throw new RuntimeException('The worker may run only in its isolated local concurrency database.');
    }
    [$script, $workerName, $action, $userId, $jobId, $cvId, $hold] = $argv;
    DB::select('SELECT set_config(?, ?, false)', ['application_name', $workerName]);
    DB::statement("SET statement_timeout = '15s'");
    DB::statement("SET lock_timeout = '12s'");
    [$uri, $data] = match ($action) {
        'apply' => ["/jobs/{$jobId}/apply", ['cv_id' => (int) $cvId, 'consent' => 1]],
        'close' => ["/employer/jobs/{$jobId}/close", ['version' => 1]],
        'hide' => ["/admin/jobs/{$jobId}/moderate", ['action' => 'hide', 'version' => 1, 'reason' => 'Kiểm thử giao dịch cạnh tranh']],
        default => throw new InvalidArgumentException('Unsupported worker action.'),
    };
    if ($hold === 'hold') {
        DB::beginTransaction();
    }
    $request = Request::create($uri, 'POST', $data, [], [], ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1']);
    $app->instance('request', $request);
    $actor = User::findOrFail($userId);
    Auth::guard('web')->setUser($actor);
    if ($actor->role === 'admin') {
        $session = $app->make('session')->driver();
        $session->start();
        $session->put('admin_confirmation', ['user_id' => $actor->id, 'confirmed_at' => now()->timestamp, 'mfa' => true]);
        $claims = rtrim(strtr(base64_encode(json_encode(['sub' => $actor->id, 'aal' => 'aal2', 'exp' => now()->addHour()->timestamp])), '+/', '-_'), '=');
        $session->put('supabase_session', ['access_token' => 'eyJhbGciOiJIUzI1NiJ9.'.$claims.'.test-signature']);
        $session->save();
        $request->cookies->set($session->getName(), $session->getId());
    }
    $response = $kernel->handle($request);
    if ($hold === 'hold' && $response->getStatusCode() < 400) {
        echo json_encode(['phase' => 'held', 'status' => $response->getStatusCode()], JSON_THROW_ON_ERROR).PHP_EOL;
        flush();
        if (trim((string) fgets(STDIN)) !== 'COMMIT') {
            throw new RuntimeException('The parent did not release the transaction barrier.');
        }
        DB::commit();
    } elseif ($hold === 'hold') {
        DB::rollBack();
    }
    $body = json_decode($response->getContent(), true);
    echo json_encode([
        'phase' => 'completed', 'status' => $response->getStatusCode(),
        'location' => $response->headers->get('Location'),
        'errors' => is_array($body) ? ($body['errors'] ?? []) : [],
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    $kernel->terminate($request, $response);
} catch (Throwable $exception) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $detail = $exception instanceof TypeError ? ': '.$exception->getMessage() : '';
    fwrite(STDERR, 'Concurrency worker failed: '.$exception::class.$detail.' at '.basename($exception->getFile()).':'.$exception->getLine().PHP_EOL);
    exit(1);
}
