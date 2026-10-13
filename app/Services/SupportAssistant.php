<?php

namespace App\Services;

use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class SupportAssistant
{
    public function __construct(private PublicCatalog $catalog) {}

    public function configured(): bool
    {
        return (bool) config('assistant.enabled');
    }

    public function timeout(): int
    {
        return max(5, min(30, (int) config('assistant.timeout_seconds')));
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @param  array<string, mixed>  $filters
     * @return array{answer: string, jobs: array, source: string, locale: string, topic: string}
     */
    public function answer(string $message, array $history = [], array $filters = []): array
    {
        $locale = in_array(app()->getLocale(), ['vi', 'en', 'ja'], true) ? app()->getLocale() : 'vi';
        $topic = $this->topic($this->normalize($message), $filters);
        $jobs = $topic === 'jobs' ? $this->publicJobs($message, $filters) : [];
        $key = match ($topic) {
            'jobs' => $jobs === [] ? 'no_results' : 'found_jobs',
            'scope' => 'scope_only',
            'welcome' => 'welcome',
            default => 'help.'.$topic,
        };

        return ['answer' => __('assistant.'.$key, ['count' => count($jobs)], $locale), 'jobs' => $jobs, 'source' => 'local', 'locale' => $locale, 'topic' => $topic];
    }

    /** @param array<string, mixed> $filters */
    private function topic(string $message, array $filters): string
    {
        if ($this->containsAny($message, [
            'ignore all', 'ignore previous', 'system prompt', 'reveal', 'bypass', 'hack', 'read every', 'read all cv', 'delete account',
            'api key', 'secret key', 'write code', 'write a script', 'generate code', 'solve', 'calculate', 'weather', 'politics', 'recipe',
            'bo qua quy tac', 'bo qua chi dan', 'mat khau cua', 'doc cv cua', 'xem cv cua', 'doi trang thai ho', 'xoa tai khoan ho',
            'viet code', 'viet ma', 'giai toan', 'tinh toan', 'thoi tiet', 'chinh tri', 'nau an',
            '秘密を', '指示を無視', '全員の履歴書', '他人の', 'コードを書', 'コードを生成', '計算して', '天気', '政治', 'レシピ',
        ])) {
            return 'scope';
        }

        $hasFilters = array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== '') !== [];
        $employment = $this->containsAny($message, ['internship', 'internships', 'intern', 'jobs', 'job', 'openings', 'viec', 'tin tuyen', 'thuc tap', 'インターン', '求人', '仕事']);
        $search = $this->containsAny($message, ['find', 'search', 'looking for', 'show', 'tim', 'goi y', '探', '検索']);
        $usageQuestion = $this->containsAny($message, ['how do i', 'how to', 'cach', 'lam sao', 'nhu the nao', '探し方', '検索方法', 'どうやって']);
        if ($employment && $search && ! $usageQuestion) {
            return 'jobs';
        }

        $topics = [
            'privacy' => ['privacy', 'chat history', 'paid', 'free', 'openai', 'bao mat', 'rieng tu', 'luu hoi thoai', 'luu lich su', 'mien phi', 'co phi', 'プライバシー', '履歴の保存', '無料', '有料'],
            'password' => ['forgot password', 'forgot my password', 'reset password', 'password reset', 'quen mat khau', 'dat lai mat khau', 'khoi phuc mat khau', 'パスワードを忘', 'パスワードのリセット', 'パスワードをリセット'],
            'admin' => ['admin', 'mfa', 'otp', 'two factor', 'two-factor', 'quan tri', 'quan tri vien', '管理者', '二要素', '認証コード'],
            'tracking' => ['application status', 'my application', 'track application', 'track my', 'notifications', 'trang thai', 'theo doi', 'thong bao', 'don ung tuyen cua', '応募状況', '応募の状態', '通知', '選考状況'],
            'apply' => ['apply', 'submit my cv', 'submit a cv', 'submit cv', 'send my cv', 'send cv', 'ung tuyen', 'nop cv', 'gui cv', 'nop ho so', '応募', '履歴書を提出', '履歴書を送', '履歴書はどうやって提出'],
            'cv' => ['cv', 'resume', 'pdf', 'upload', 'tai len', 'quan ly cv', '履歴書', 'アップロード'],
            'profile' => ['profile', 'ho so', 'university', 'skills', 'ky nang', 'プロフィール'],
            'employer' => ['post a job', 'post jobs', 'publish a job', 'job posting', 'my company', 'employer', 'recruiter', 'dang tin', 'duyet tin', 'nha tuyen dung', 'cong ty cua', '求人を掲載', '求人掲載', '企業情報', '採用担当', '募集を掲載'],
            'language' => ['language', 'ngon ngu', 'tieng anh', 'tieng nhat', 'tieng viet', '言語', '日本語に', '英語に'],
            'saved' => ['saved jobs', 'save a job', 'saved search', 'recent views', 'viec da luu', 'luu tin', 'bo loc da luu', 'da xem', '保存した求人', '求人を保存', '保存した検索', '閲覧履歴'],
            'account' => ['register', 'sign up', 'sign in', 'log in', 'login', 'account', 'dang ky', 'dang nhap', 'tai khoan', '登録', 'ログイン', 'アカウント'],
            'search_help' => ['how to search', 'how do i search', 'how do i find', 'cach tim viec', 'tim viec nhu the nao', 'cach loc', 'sort jobs', 'sap xep', '探し方', '検索方法', '絞り込み'],
        ];
        foreach ($topics as $topic => $terms) {
            if ($this->containsAny($message, $terms)) {
                return $topic;
            }
        }

        if ($hasFilters) {
            return 'jobs';
        }
        if ($this->containsAny($message, ['internship', 'internships', 'intern', 'jobs', 'job', 'openings', 'tim viec', 'tim tin', 'thuc tap', 'co hoi nghe nghiep', 'インターン', '求人', '仕事を探'])) {
            return 'jobs';
        }
        if ($this->containsAny($message, ['hello', 'hi', 'help', 'xin chao', 'chao', 'huong dan', 'tro giup', 'ho tro', 'こんにちは', '使い方', 'ヘルプ'])) {
            return 'welcome';
        }

        return 'scope';
    }

    /** @param array<string, mixed> $filters */
    private function publicJobs(string $message, array $filters): array
    {
        $normalized = $this->normalize($message);
        $groups = [];
        $aliases = [
            ['frontend', 'front-end', 'front end', 'giao diện', 'フロントエンド'],
            ['backend', 'back-end', 'back end', 'バックエンド'],
            ['fullstack', 'full-stack', 'full stack', 'フルスタック'],
            ['data', 'dữ liệu', 'データ'],
            ['marketing', 'tiếp thị', 'マーケティング'],
            ['design', 'thiết kế', 'デザイン', 'ui/ux'],
            ['accounting', 'kế toán', '経理', '会計'],
            ['human resources', 'nhân sự', '人事'],
            ['tester', 'testing', 'kiểm thử', 'テスト', 'qa'],
            ['sales', 'kinh doanh', 'bán hàng', '営業'],
            ['logistics', 'chuỗi cung ứng', '物流'],
            ['content', 'nội dung', 'コンテンツ'],
            ['translation', 'biên dịch', 'phiên dịch', '翻訳', '通訳'],
            ['software', 'lập trình', 'プログラミング', 'ソフトウェア'],
            ['japanese', 'tiếng Nhật', '日本語'],
            ['english', 'tiếng Anh', '英語'],
        ];
        foreach ($aliases as $terms) {
            if ($this->containsAny($normalized, $terms)) {
                $groups[] = $terms;
            }
        }
        foreach (['laravel', 'php', 'react', 'vue', 'python', 'java', 'javascript', 'sql', 'figma', 'excel', 'flutter', 'android', 'ios', 'nodejs', 'angular', 'csharp'] as $skill) {
            if ($this->containsAny($normalized, [$skill])) {
                $groups[] = [$skill];
            }
        }

        $contextTerms = [];
        $mode = $filters['work_mode'] ?? null;
        if (! $mode) {
            foreach (['REMOTE' => ['remote', 'từ xa', 'リモート', '在宅'], 'HYBRID' => ['hybrid', 'kết hợp', 'ハイブリッド'], 'ONSITE' => ['onsite', 'on-site', 'tại văn phòng', '出社']] as $value => $terms) {
                if ($this->containsAny($normalized, $terms)) {
                    $mode = $value;
                    $contextTerms = array_merge($contextTerms, $terms);
                    break;
                }
            }
        }
        $location = $filters['location_id'] ?? null;
        if (! $location) {
            $locationAliases = [
                'ho chi minh' => ['hcm', 'saigon', 'sai gon', 'ho chi minh', 'ホーチミン'],
                'ha noi' => ['hanoi', 'ha noi', 'ハノイ'],
                'da nang' => ['danang', 'da nang', 'ダナン'],
                'can tho' => ['cantho', 'can tho', 'カントー'],
            ];
            foreach ($this->catalog->locations() as $item) {
                $name = $this->normalize($item->name);
                $terms = [$item->name];
                foreach ($locationAliases as $city => $variants) {
                    if (str_contains($name, $city)) {
                        $terms = array_merge($terms, $variants);
                    }
                }
                if ($this->containsAny($normalized, $terms)) {
                    $location = $item->id;
                    $contextTerms = array_merge($contextTerms, $terms);
                    break;
                }
            }
        }

        $keyword = trim((string) ($filters['q'] ?? ''));
        $query = JobPosting::accepting()->with(['company:id,name', 'category:id,name', 'location:id,name']);
        foreach (['category_id' => $filters['category_id'] ?? null, 'location_id' => $location, 'work_mode' => $mode] as $column => $value) {
            if ($value) {
                $query->where($column, $value);
            }
        }
        if ($keyword !== '') {
            $groups = [[$keyword]];
        } elseif ($groups === [] && empty($filters['category_id'])) {
            $remainder = $normalized;
            foreach ($contextTerms as $contextTerm) {
                $remainder = str_replace($this->normalize($contextTerm), ' ', $remainder);
            }
            $remainder = preg_replace('/\b(find|me|some|a|an|the|please|looking|for|in|at|search|show|available|current|open|jobs?|internships?|opportunities|intern|toi|minh|hay|giup|tim|viec|lam|thuc|tap|sinh|co|hoi|phu|hop|cho|o|tai)\b/u', ' ', $remainder);
            $remainder = str_replace(['インターンシップ', 'インターン', '求人', '仕事', 'を探しています', 'を探す', '探して', 'ください', 'を', 'の', 'で'], '', $remainder);
            $remainingTerms = preg_split('/[^\p{L}\p{N}+#.]+/u', trim($remainder), -1, PREG_SPLIT_NO_EMPTY);
            foreach (array_slice(array_filter($remainingTerms, fn (string $term): bool => mb_strlen($term) >= 2), 0, 4) as $term) {
                $groups[] = [$term];
            }
        }
        foreach (array_slice($groups, 0, 4) as $terms) {
            $query->where(function (Builder $group) use ($terms): void {
                foreach ($terms as $term) {
                    $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
                    $group->orWhereRaw('LOWER(title) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(requirements) LIKE ?', [$like])
                        ->orWhereHas('category', fn (Builder $category): Builder => $category->whereRaw('LOWER(name) LIKE ?', [$like]))
                        ->orWhereHas('company', fn (Builder $company): Builder => $company->whereRaw('LOWER(name) LIKE ?', [$like]));
                }
            });
        }

        return $query->orderByDesc('published_at')->orderByDesc('id')->limit(6)
            ->get(['id', 'company_id', 'category_id', 'location_id', 'title', 'work_mode', 'allowance_min', 'allowance_max', 'currency', 'deadline_at'])
            ->map(fn (JobPosting $job): array => [
                'id' => $job->id,
                'title' => mb_substr($job->title, 0, 180),
                'company' => mb_substr($job->company->name, 0, 150),
                'category' => mb_substr($job->category->name, 0, 100),
                'location' => mb_substr($job->location->name, 0, 100),
                'work_mode' => $job->work_mode,
                'allowance_min' => $job->allowance_min,
                'allowance_max' => $job->allowance_max,
                'currency' => $job->currency,
                'deadline' => $job->deadline_at->toIso8601String(),
                'url' => route('jobs.show', $job->id, false),
            ])->all();
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(preg_replace_callback('/\p{Latin}+/u', fn (array $match): string => Str::ascii($match[0]), $text));
    }

    /** @param array<int, string> $terms */
    private function containsAny(string $normalized, array $terms): bool
    {
        foreach ($terms as $term) {
            $needle = $this->normalize($term);
            if ($needle !== '' && preg_match('/(?<![a-z0-9])'.preg_quote($needle, '/').'(?![a-z0-9])/u', $normalized)) {
                return true;
            }
        }

        return false;
    }
}
