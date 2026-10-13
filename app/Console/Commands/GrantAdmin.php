<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GrantAdmin extends Command
{
    protected $signature = 'app:grant-admin {user : UUID của tài khoản ứng dụng đã đăng nhập}';

    protected $description = 'Cấp Admin cho tài khoản đã có bằng thao tác quản trị cục bộ';

    public function handle(): int
    {
        $id = $this->argument('user');
        if (! Str::isUuid($id)) {
            $this->error('UUID không hợp lệ.');

            return self::FAILURE;
        }
        $user = User::find($id);
        if (! $user || $user->is_demo) {
            $this->error('Cần tài khoản Supabase thật đã có hồ sơ ứng dụng.');

            return self::FAILURE;
        }
        $user->update(['role' => 'admin']);
        $this->info('Đã cấp quyền Admin cho UUID được chỉ định.');

        return self::SUCCESS;
    }
}
