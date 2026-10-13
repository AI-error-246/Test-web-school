<!doctype html>
<html lang="vi">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Cập nhật hồ sơ ứng tuyển</title></head>
<body style="margin:0;background:#f4f7f4;color:#17313a;font-family:Arial,sans-serif;line-height:1.7">
<div style="max-width:600px;margin:32px auto;padding:32px;background:#ffffff;border:1px solid #dce5dc;border-radius:16px">
    @if($isPreview)
        <p style="padding:12px 16px;background:#fff2ce;border:1px solid #e6cb77;border-radius:8px;font-size:13px"><strong>MÔ PHỎNG — chưa gửi email.</strong> Đây là bản xem trước riêng tư để kiểm thử nội dung.</p>
    @endif
    <p style="font-size:14px;font-weight:bold;color:#08755e;letter-spacing:1px">INTERNBRIDGE</p>
    <h1 style="font-size:25px;line-height:1.4">Hồ sơ của bạn có cập nhật mới</h1>
    <p>Chào {{ $recipientName }},</p>
    <p>Hồ sơ ứng tuyển vị trí <strong>{{ $jobTitle }}</strong> tại <strong>{{ $companyName }}</strong> đã được cập nhật.</p>
    <p style="padding:14px 18px;background:#edf4eb;border-radius:10px"><strong>Trạng thái: {{ $statusLabel }}</strong></p>
    <p>Bạn có thể đăng nhập để xem lịch sử và thông tin hồ sơ đã nộp.</p>
    <p><a href="{{ $applicationUrl }}" style="display:inline-block;padding:12px 20px;background:#096b57;color:#ffffff;border-radius:8px;text-decoration:none;font-weight:bold">Xem hồ sơ ứng tuyển</a></p>
    <p style="color:#5e7477;font-size:13px">Đây là thông báo tự động về hồ sơ bạn đã nộp trên InternBridge.</p>
</div>
</body>
</html>
