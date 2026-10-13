<?php

namespace Database\Seeders;

use App\Models\ApplicationStatusHistory;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CvDocument;
use App\Models\JobApplication;
use App\Models\JobCategory;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->assertLocalDatabase();

        DB::transaction(function (): void {
            $candidate = $this->demoUser('11111111-1111-4111-8111-111111111111', 'candidate', 'Nguyễn Minh Anh', 'candidate@example.test');
            $employer = $this->demoUser('22222222-2222-4222-8222-222222222222', 'employer', 'Trần Hoàng Nam', 'employer@example.test');
            $admin = $this->demoUser('33333333-3333-4333-8333-333333333333', 'admin', 'Quản trị viên', 'admin@example.test');
            $secondCandidate = $this->demoUser('44444444-4444-4444-8444-444444444444', 'candidate', 'Lê Thảo Vy', 'candidate2@example.test');
            $secondEmployer = $this->demoUser('55555555-5555-4555-8555-555555555555', 'employer', 'Phạm Đức Huy', 'employer2@example.test');

            foreach ([$candidate, $secondCandidate] as $person) {
                CandidateProfile::firstOrCreate(['user_id' => $person->id], [
                    'full_name' => $person->name, 'phone' => '0901234567',
                    'university' => 'Đại học Công nghệ Thành phố Hồ Chí Minh',
                    'major' => 'Công nghệ thông tin', 'study_year' => 3,
                    'introduction' => 'Sinh viên yêu thích giải quyết vấn đề, mong muốn học hỏi trong môi trường doanh nghiệp.',
                    'skills_text' => 'PHP, Laravel, SQL, Git, giao tiếp nhóm',
                ]);
            }

            $company = Company::firstOrCreate(['owner_user_id' => $employer->id], [
                'name' => 'Công ty Công nghệ Mầm Xanh',
                'description' => 'Doanh nghiệp demo phát triển phần mềm, đồng hành cùng sinh viên qua các dự án thực tế và chương trình mentoring.',
                'website' => 'https://example.com', 'address' => 'Thành phố Hồ Chí Minh',
                'contact_email' => 'employer@example.test', 'visibility' => 'VISIBLE',
            ]);
            $secondCompany = Company::firstOrCreate(['owner_user_id' => $secondEmployer->id], [
                'name' => 'Công ty Sáng tạo Cầu Nối',
                'description' => 'Doanh nghiệp demo cung cấp dịch vụ thiết kế, truyền thông và phân tích dữ liệu.',
                'website' => 'https://example.org', 'address' => 'Hà Nội',
                'contact_email' => 'employer2@example.test', 'visibility' => 'VISIBLE',
            ]);

            $jobSpecs = [
                ['Thực tập sinh Backend Laravel', 'Công nghệ thông tin', 'HCM', 'Thành phố Hồ Chí Minh', 'HYBRID', 3000000, 5000000],
                ['Thực tập sinh Kiểm thử phần mềm', 'Công nghệ thông tin', 'HCM', 'Thành phố Hồ Chí Minh', 'ONSITE', 2000000, 3500000],
                ['Thực tập sinh Thiết kế UI/UX', 'Thiết kế', 'HN', 'Hà Nội', 'HYBRID', 2500000, 4000000],
                ['Thực tập sinh Content Marketing', 'Marketing', 'DN', 'Đà Nẵng', 'REMOTE', 2000000, 3000000],
                ['Thực tập sinh Phân tích dữ liệu', 'Dữ liệu', 'HN', 'Hà Nội', 'HYBRID', 3000000, 5000000],
                ['Thực tập sinh Nhân sự', 'Nhân sự', 'HCM', 'Thành phố Hồ Chí Minh', 'ONSITE', 2000000, 3000000],
                ['Thực tập sinh Kế toán', 'Tài chính - Kế toán', 'CT', 'Cần Thơ', 'ONSITE', 2000000, 3500000],
                ['Thực tập sinh Digital Marketing', 'Marketing', 'HN', 'Hà Nội', 'HYBRID', 2500000, 4000000],
                ['Thực tập sinh Frontend — đợt tiếp theo', 'Công nghệ thông tin', 'HCM', 'Thành phố Hồ Chí Minh', 'HYBRID', 3000000, 4500000],
                ['Thực tập sinh Thiết kế đồ họa — đã đóng', 'Thiết kế', 'HN', 'Hà Nội', 'ONSITE', 2500000, 4000000],
            ];
            $jobs = [];
            foreach ($jobSpecs as $index => [$title, $categoryName, $locationCode, $locationName, $mode, $minimum, $maximum]) {
                $category = JobCategory::firstOrCreate(['name' => $categoryName]);
                $location = Location::firstOrCreate(['code' => $locationCode], ['name' => $locationName]);
                $job = JobPosting::firstOrCreate([
                    'company_id' => $index >= 6 ? $secondCompany->id : $company->id, 'title' => $title,
                ], [
                    'category_id' => $category->id, 'location_id' => $location->id,
                    'description' => 'Tham gia dự án thực tế cùng đội ngũ chuyên môn. Tìm hiểu quy trình làm việc, hỗ trợ nghiên cứu và hoàn thiện sản phẩm. Thời gian thực tập tối thiểu 3 tháng, có người hướng dẫn trực tiếp.',
                    'requirements' => 'Sinh viên năm 3 hoặc năm 4 chuyên ngành phù hợp; tinh thần chủ động, có kiến thức nền tảng và khả năng làm việc nhóm. Có thể thực tập tối thiểu 4 buổi mỗi tuần.',
                    'benefits' => 'Trợ cấp hàng tháng, hỗ trợ báo cáo và xác nhận thực tập; được mentor hướng dẫn, tham gia chia sẻ chuyên môn và đánh giá cơ hội làm việc sau kỳ thực tập.',
                    'work_mode' => $mode, 'allowance_min' => $minimum, 'allowance_max' => $maximum,
                    'currency' => 'VND', 'deadline_at' => now()->addDays(30 + $index),
                    'publication_state' => $index === 8 ? 'DRAFT' : ($index === 9 ? 'CLOSED' : 'OPEN'),
                    'moderation_state' => $index === 8 ? 'PENDING' : 'APPROVED',
                    'published_at' => $index === 8 ? null : now()->subDays(10 - $index), 'version' => 1,
                ]);
                $jobs[] = $job;
                if ($job->wasRecentlyCreated && $job->moderation_state === 'APPROVED') {
                    ModerationAction::create([
                        'admin_id' => $admin->id, 'job_id' => $job->id, 'action' => 'APPROVE',
                        'from_state' => 'PENDING', 'to_state' => 'APPROVED',
                        'reason' => 'Tin demo đủ thông tin thực tập.', 'reviewed_version' => 1,
                        'created_at' => $job->published_at,
                    ]);
                }
            }

            $cv = $this->demoCv($candidate);
            $secondCv = $this->demoCv($secondCandidate);
            $this->demoApplication($candidate, $cv, $jobs[0], 'SUBMITTED');
            $this->demoApplication($candidate, $cv, $jobs[1], 'REVIEWING');
            $this->demoApplication($secondCandidate, $secondCv, $jobs[0], 'SELECTED');
            Report::firstOrCreate(['reporter_id' => $candidate->id, 'job_id' => $jobs[2]->id], [
                'reason' => 'Thông tin chưa rõ ràng',
                'description' => 'Dữ liệu demo: đề nghị làm rõ số buổi thực tập bắt buộc mỗi tuần.', 'status' => 'OPEN',
            ]);
        });
    }

    private function assertLocalDatabase(): void
    {
        $connection = DB::connection();
        $isSqliteTest = app()->environment('testing') && $connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:';
        $isLocalPostgres = $connection->getDriverName() === 'pgsql'
            && in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
            && in_array($connection->getDatabaseName(), ['internship_dev', 'internship_test'], true);

        if (! app()->environment(['local', 'testing']) || (! $isSqliteTest && ! $isLocalPostgres)) {
            throw new \RuntimeException('Dữ liệu demo chỉ được seed vào database local/test riêng đã chỉ định.');
        }
    }

    private function demoUser(string $id, string $role, string $name, string $email): User
    {
        $user = User::find($id);
        if ($user && ! $user->is_demo) {
            throw new \RuntimeException('UUID demo đã thuộc tài khoản thật; dừng seed để bảo vệ dữ liệu.');
        }

        return User::firstOrCreate(['id' => $id], [
            'name' => $name, 'email' => $email, 'role' => $role, 'status' => 'ACTIVE', 'is_demo' => true,
        ]);
    }

    private function demoCv(User $candidate): CvDocument
    {
        $storageKey = 'cvs/demo/'.$candidate->id.'/internship.pdf';
        $disk = Storage::disk('local');
        if (! $disk->exists($storageKey)) {
            if (! $disk->put($storageKey, $this->samplePdf())) {
                throw new \RuntimeException('Không thể ghi CV demo vào kho riêng tư.');
            }
        }

        return CvDocument::firstOrCreate(['storage_key' => $storageKey], [
            'candidate_id' => $candidate->id, 'display_name' => 'CV thực tập — '.$candidate->name,
            'original_filename' => 'cv-thuc-tap.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => $disk->size($storageKey), 'uploaded_at' => now()->subDays(7),
        ]);
    }

    private function demoApplication(User $candidate, CvDocument $cv, JobPosting $job, string $status): void
    {
        $application = JobApplication::firstOrCreate(['candidate_id' => $candidate->id, 'job_id' => $job->id], [
            'cv_id' => $cv->id, 'name_snapshot' => $candidate->name, 'email_snapshot' => $candidate->email,
            'phone_snapshot' => $candidate->candidateProfile->phone,
            'university_snapshot' => $candidate->candidateProfile->university,
            'major_snapshot' => $candidate->candidateProfile->major,
            'job_title_snapshot' => $job->title, 'company_name_snapshot' => $job->company->name,
            'status' => $status, 'version' => $status === 'SUBMITTED' ? 1 : ($status === 'REVIEWING' ? 2 : 3),
            'applied_at' => now()->subDays(3),
        ]);
        if (! $application->wasRecentlyCreated) {
            return;
        }
        $states = $status === 'SUBMITTED' ? ['SUBMITTED'] : ($status === 'REVIEWING' ? ['SUBMITTED', 'REVIEWING'] : ['SUBMITTED', 'REVIEWING', 'SELECTED']);
        $previous = null;
        foreach ($states as $index => $state) {
            ApplicationStatusHistory::create([
                'application_id' => $application->id,
                'changed_by' => $index === 0 ? $candidate->id : $job->company->owner_user_id,
                'from_status' => $previous, 'to_status' => $state,
                'changed_at' => $application->applied_at->addHours($index),
            ]);
            $previous = $state;
        }
    }

    private function samplePdf(): string
    {
        $stream = 'BT /F1 18 Tf 55 760 Td (INTERNSHIP CV - DEMO DATA) Tj 0 -35 Td /F1 11 Tf (This sample contains no real personal information.) Tj 0 -24 Td (Education: Third-year Information Technology student.) Tj 0 -24 Td (Skills: PHP, Laravel, PostgreSQL, Git, teamwork.) Tj 0 -24 Td (Goal: Learn software development through an internship.) Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream).">>\nstream\n".$stream."\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
