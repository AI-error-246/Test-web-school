<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class SupabaseReadiness extends Command
{
    protected $signature = 'app:supabase-readiness {--prepare : Tạo schema riêng, migration và bucket CV riêng tư nếu chưa có}';

    protected $description = 'Kiểm tra Supabase thật, không in credential và không tự chuyển database đang chạy';

    public function handle(): int
    {
        $project = 'xwkfsxohvpebnaqqhuxz';
        $url = (string) config('database.connections.supabase.url');
        $key = (string) config('supabase.secret_key');
        if ($url === '' || ! preg_match('/^sb_secret_[A-Za-z0-9_-]+$/D', $key)) {
            $this->error('Thiếu SUPABASE_DB_URL hoặc SUPABASE_SECRET_KEY đầy đủ. Chưa kết nối hoặc ghi dữ liệu cloud.');

            return self::FAILURE;
        }
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $username = rawurldecode($parts['user'] ?? '');
        $targetMatches = $host === 'db.'.$project.'.supabase.co'
            || (str_ends_with($host, '.pooler.supabase.com') && str_ends_with($username, '.'.$project));
        if (! $targetMatches || ! in_array($parts['scheme'] ?? '', ['postgres', 'postgresql', 'pgsql'], true)
            || (int) ($parts['port'] ?? 5432) !== 5432
            || rtrim((string) config('supabase.url'), '/') !== 'https://'.$project.'.supabase.co') {
            $this->error('Kết nối phải thuộc đúng project, dùng PostgreSQL direct hoặc Session pooler cổng 5432.');

            return self::FAILURE;
        }
        $bucket = (string) config('supabase.storage_bucket');
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/D', $bucket)) {
            $this->error('SUPABASE_STORAGE_BUCKET chưa hợp lệ.');

            return self::FAILURE;
        }
        $stage = 'kết nối database';
        try {
            $db = DB::connection('supabase');
            $db->selectOne('SELECT current_database() AS name');
            $stage = 'kiểm tra bucket riêng tư';
            $client = Http::withHeaders(['apikey' => $key])->asJson()->connectTimeout(3)->timeout(15)->withoutRedirecting();
            $endpoint = 'https://'.$project.'.supabase.co/storage/v1/bucket';
            $response = $client->get($endpoint.'/'.$bucket);
            $missing = $response->status() === 404 || (string) $response->json('statusCode') === '404';
            if ($missing && $this->option('prepare')) {
                $response = $client->post($endpoint, ['id' => $bucket, 'name' => $bucket, 'public' => false, 'file_size_limit' => 5242880, 'allowed_mime_types' => ['application/pdf']]);
                if (! $response->successful()) {
                    throw new \RuntimeException('BUCKET_CREATE_FAILED');
                }
                $response = $client->get($endpoint.'/'.$bucket);
            }
            if (! $response->successful() || $response->json('public') !== false) {
                $this->error('Bucket chưa tồn tại/không truy cập được hoặc không private; chưa migration.');

                return self::FAILURE;
            }
            if ($this->option('prepare')) {
                $stage = 'chuẩn bị schema và migration';
                $db->statement('CREATE SCHEMA IF NOT EXISTS internbridge');
                $db->statement('REVOKE ALL ON SCHEMA internbridge FROM PUBLIC, anon, authenticated');
                $code = Artisan::call('migrate', ['--database' => 'supabase', '--force' => true, '--no-interaction' => true]);
                if ($code !== 0) {
                    throw new \RuntimeException('MIGRATION_FAILED');
                }
                $db->statement('REVOKE ALL ON ALL TABLES IN SCHEMA internbridge FROM anon, authenticated');
                $db->statement('REVOKE ALL ON ALL SEQUENCES IN SCHEMA internbridge FROM anon, authenticated');
            }
            $stage = 'đọc lại schema';
            $exists = $db->selectOne("SELECT to_regclass('internbridge.cv_documents') IS NOT NULL AS ready");
            if (! $exists->ready) {
                $this->error('Kết nối và bucket đạt nhưng schema chưa có. Chạy lại với --prepare để migration vào internbridge.');

                return self::FAILURE;
            }
            $authCount = $db->selectOne('SELECT count(*) AS total FROM auth.users')->total;
            $this->info('Supabase kết nối được; schema internbridge và bucket riêng tư đã đọc lại thành công.');
            $this->line('Auth users: '.$authCount.'; CV: '.$db->table('internbridge.cv_documents')->count().'; applications: '.$db->table('internbridge.applications')->count());
            $this->line('Lệnh này không tự bật SUPABASE_DATA_ENABLED hoặc thay đổi database local.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Supabase chưa xác minh được ở bước: '.$stage.'. Kiểm tra credential/kết nối/quyền; chưa xác nhận hoàn thành.');

            return self::FAILURE;
        }
    }
}
