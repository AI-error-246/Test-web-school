# InternBridge — Laravel + Supabase

Website tuyển dụng thực tập với giao diện Blade tiếng Việt/Anh/Nhật, CSS/JS trực tiếp, backend Laravel 13 và database PostgreSQL. Supabase Auth quản lý đăng ký, xác nhận email và đăng nhập thật. Không lưu mật khẩu người dùng trong PostgreSQL ứng dụng.

## Chuẩn bị GitHub và host chạy thử — D40 (14/10/2026)

Repository: [AI-error-246/Test-web-school](https://github.com/AI-error-246/Test-web-school), đã chuyển **private** và đưa mã lên `main` theo xác nhận của người dùng. Thư mục gốc repository là **Laravel hiện tại**, không phải toàn bộ workspace. Giữ `composer.lock`, `package-lock.json` và nguồn ThreeUI; không đưa `.env`, key, CV/ảnh, database dump, session/log, `vendor`, `node_modules` hoặc asset build vào Git. Các báo cáo lịch sử liên kết `../../06_Testing` bên dưới chỉ có trong workspace gốc, không kèm theo repository ứng dụng.

Workflow `.github/workflows/ci.yml` cài dependency theo lock, build frontend (kèm sáu SHA nguồn/font) và chạy PHPUnit với PostgreSQL riêng. PHP 8.4, Node 24, PostgreSQL 18; database test tại `127.0.0.1:54329/internship_test`, không dùng secrets Supabase. CI chỉ kiểm thử, không deploy hoặc chạy migration cloud. Bộ browser smoke Windows vẫn cần `.tools` của workspace, chưa đưa vào Linux CI. Các bài concurrency dùng database riêng và hiện tắt trong CI đầu tiên.

Kiểm tra local trước khi đưa lên Git: Composer strict validate đạt; build production và 6 hash đạt; toàn suite có **620 cases: 615 PASS, 5 concurrency SKIP, 3.647 assertions**. Đây chưa phải bằng chứng GitHub Actions hoặc host Linux đã chạy thành công.

Xem kết quả Linux của từng commit tại [GitHub Actions](https://github.com/AI-error-246/Test-web-school/actions). CI đã giúp phát hiện thư mục `tests/Unit` rỗng không được Git lưu và bài kiểm tra chuyển dữ liệu phụ thuộc scanner thật của Windows. Đã giữ thư mục bằng placeholder, mô phỏng scanner trong test transfer và thêm hai trường hợp chặn khi `INFECTED`/`ERROR`. Bài transfer riêng: **8 PASS / 105 assertions**, kể cả khi máy không có scanner. Không thay cơ chế quét thật của website; CI này chưa nghiệm thu ClamAV thực tế trên host.

Người dùng chọn **miễn phí trước nhưng phải thử đủ nộp CV, ảnh và quét mã độc**. Vì vậy chưa chọn Render Free làm host đầy đủ: dịch vụ này không giữ tệp local qua restart và chỉ có 512 MB RAM, chưa phù hợp bộ quét hiện tại. Phương án đề xuất là một VM Always Free có volume bền vững, chạy Laravel và ClamAV, tiếp tục dùng Supabase Auth/Database/private CV. Ví dụ Oracle A1 còn phụ thuộc tài khoản, quota và khả năng cấp máy; chưa tạo tài nguyên hoặc bật gói trả phí. Xem [giới hạn Render](https://render.com/docs/free), [compute Render](https://render.com/docs/compute-plans), [Oracle Always Free](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm).

Các việc bắt buộc trước mở host:

1. Host Linux chạy PHP 8.4 + các extension dự án, ClamAV thật có database chữ ký cập nhật; kiểm file sạch, file thử phát hiện và trường hợp scanner không hoạt động. Giữ cơ chế chặn CV khi chưa quét được.
2. Giữ `storage/app/private` và runtime trên volume bền vững; ảnh hiện vẫn lưu riêng tư local, CV có adapter Supabase. Không chỉ đổi `SUPABASE_CV_DISK` rồi cho rằng ảnh đã chuyển cloud.
3. HTTPS, webroot `public`, `APP_ENV=production`, `APP_DEBUG=false`, `DEMO_MODE=false`, cookie secure; cấu hình domain/callback Supabase và kiểm email đăng ký, recovery, MFA thật. Nếu dùng reverse proxy/CDN, cấu hình trusted proxy và IP khách đúng; hiện lớp chống lạm dụng lấy `REMOTE_ADDR`.
4. Scheduler mỗi phút cho outbox email, cấu hình SMTP nếu cần gửi thật. Chế độ mặc định preview chưa gửi email; chưa cần thêm queue worker cho outbox hiện có.
5. Sao lưu và thử khôi phục DB/CV/ảnh; giữ bản phát hành để rollback. Chạy thử ba role và kiểm dữ liệu còn nguyên sau redeploy. `/health` chỉ là liveness, chưa chứng minh scanner/storage/DB sẵn sàng.

Khi clone sang máy mới, cài PHP/Composer/Node/PostgreSQL, chạy `composer install`, `npm ci`, `npm run build`, sao chép `.env.example` thành `.env` riêng tư và cấu hình môi trường; tạo APP_KEY riêng. Chỉ chạy migration vào database đích đã kiểm tra. Không dùng `run.ps1` hoặc đường `.tools` khi chưa có runtime workspace; dùng trực tiếp Composer/Artisan. Không dùng GitHub Pages để chạy backend PHP: [Pages phục vụ website tĩnh](https://docs.github.com/en/pages/getting-started-with-github-pages/what-is-github-pages).

Nguồn ThreeUI exact vẫn giữ nguyên. Bản Community công khai chỉ có `living-green`, trong khi bundle exact đã được cung cấp có thêm variants; chưa xác minh quyền phân phối công khai toàn bộ bundle đó. Không tự gán giấy phép MIT của Community cho phần nguồn khác; giữ các thông báo tác giả/font và xác minh điều kiện nguồn trước khi công khai mã. [Thông tin tài sản của ThreeUI](https://github.com/MengTo/threeui/blob/main/ASSET-LICENSES.md).

### Tạo tài khoản Oracle Always Free

1. Mở [trang đăng ký Oracle Cloud](https://signup.cloud.oracle.com/), chọn quốc gia cư trú thật, nhập thông tin cá nhân và xác minh email.
2. Chọn **Home Region** cẩn thận: tài nguyên Always Free phải được tạo trong vùng này. Chọn vùng gần người dùng nếu có; tài nguyên A1 có thể tạm hết chỗ.
3. Hoàn tất xác minh điện thoại/thẻ trực tiếp trên Oracle. Không gửi mật khẩu, OTP, số thẻ hoặc khóa SSH vào chat. Oracle có thể giữ khoản xác minh thẻ tạm thời; đây khác với phí dịch vụ. Xem [FAQ chính thức](https://www.oracle.com/cloud/free/faq/).
4. Khi vào được Console, giữ tài khoản Free Tier; không chọn nâng cấp Pay As You Go. Báo lại tên Home Region để chuẩn bị máy chủ. Đề xuất một VM Ubuntu A1 2 OCPU / 8 GB RAM / 50 GB đĩa, chỉ tạo khi Console xác nhận nằm trong hạn mức miễn phí; đây chưa phải tài nguyên đã tạo.

Theo [hạn mức Oracle hiện hành](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm) kiểm ngày 14/10/2026, A1 Always Free tương đương tổng 2 OCPU / 12 GB RAM, ổ boot/block tổng 200 GB. Không dùng thông số 4 OCPU / 24 GB của hướng dẫn cũ. Máy ít hoạt động có thể bị thu hồi; cần sao lưu, không coi gói miễn phí là cam kết uptime. Nếu báo hết tài nguyên, thử availability domain khác hoặc chờ; không tự nâng cấp trả phí.

## Nền toàn trang và sáng/tối — D39 (14/10/2026)

Trang chủ hiện dùng một cảnh Sylva **cố định phủ toàn viewport**, xuất hiện xuyên suốt khi cuộn tới danh mục, quy trình và footer. Tự chạy sau khi nội dung đầu tiên được vẽ, không còn nút tạm dừng. Nội dung nằm trên các bảng nền kem hoặc xanh rừng để vẫn đọc và thao tác được; phần cảnh không bị bảng che vẫn nhận chuột. Bốn tệp ThreeUI và hai font vẫn nguyên hash của D38.

Icon mặt trăng/mặt trời trên header đổi giao diện ngay, không tải lại trang hoặc dựng lại cảnh. Lựa chọn lưu tại `localStorage.internbridge-theme` của trình duyệt, không lưu Supabase; lần đầu theo chế độ hệ điều hành. Cùng lựa chọn áp dụng khi chuyển menu, đổi Việt/Anh/Nhật và tải lại. Cổng 3001 và 3002 là hai origin nên có lựa chọn riêng. Các trang ngoài trang chủ chỉ dùng hai lớp CSS với 16 điểm lấp lánh, không tải bundle 3D.

Khi tab bị ẩn, component nguyên bản gỡ iframe để dừng renderer; khi quay lại, cảnh được dựng và chạy trở lại. Đây **không phải tiếp tục đúng frame trước khi ẩn**. Cuộn trang và đổi sáng/tối thì giữ nguyên iframe cùng đồng hồ chuyển động. Tôn trọng `prefers-reduced-motion`: cảnh tĩnh và không nhấp nháy. Không thêm canvas chồng nhau, blur nền hoặc vòng lặp JavaScript cho lấp lánh; cảnh 3D gốc vẫn có chi phí GPU khi trang hiển thị.

Kiểm chứng giao diện: 6 hash nguồn/font và build production đạt; [11 nhóm Edge mô phỏng](../../06_Testing/full-world-theme/browser.json) và [11 nhóm trên website 3001](../../06_Testing/full-world-theme/live/browser.json) đạt, không lỗi asset/JavaScript/shader. Đã kiểm mobile 390×844, cuộn giữ cảnh, chuột, đổi màu không reset, ẩn/hiện tab, ngôn ngữ và reduced motion. Xem [trang đầu](../../06_Testing/full-world-theme/live/desktop.png), [khi cuộn xuống](../../06_Testing/full-world-theme/live/desktop-bottom.png), [mobile](../../06_Testing/full-world-theme/live/mobile-top.png).

Hồi quy cuối: **64 tests PostgreSQL / 748 assertions PASS**, [8 nhóm menu/ba role/MFA/VI-EN-JA](../../06_Testing/full-world-theme/navigation/browser.json) đạt, gồm kiểm header mobile ở ba role. Khi chạy thử, đã phát hiện đổi ngôn ngữ tiêu hao bộ đếm đăng nhập chung của Laravel; thêm prefix riêng cho `/language`, giữ nguyên 30 lần/phút và giới hạn đăng nhập/đăng ký. Ba regression mới chứng minh quota độc lập và phục hồi sau thời gian chờ. Không thay schema/env, tạo CV/đơn hoặc ghi dữ liệu tuyển dụng Supabase.

Các mô tả khung hero riêng, mobile 400px và nút tạm dừng trong D38 bên dưới là lịch sử, đã được D39 thay thế. Nguồn, đường asset và quy trình build D38 vẫn áp dụng.

## Cảnh Sylva Living Green — D38 (14/10/2026, lịch sử khung ban đầu)

Trang chủ tích hợp đúng `SylvaLivingWorldScene variant="living-green"` từ [bundle đăng ký ThreeUI](https://threeui.com/source-code/sylva-living-world.json), revision `fd922291297d`, Three.js r149. Bốn tệp nguồn được giữ nguyên từng byte tại `resources/vendor/threeui`; `tests/Frontend/sylva-source.test.mjs` chặn build nếu SHA-256 lệch. Package cục bộ xuất đúng `@designcodeio/threeui` và `@designcodeio/threeui/style.css`; React/React DOM 19.3.0 chỉ phục vụ vùng cảnh trên trang chủ. Backend, route tuyển dụng, Supabase, hồ sơ/CV, thông báo, bản đồ, Admin MFA và trợ lý miễn phí giữ nguyên.

Component gốc dựng HTML/canvas bằng `iframe srcDoc` với **`sandbox="allow-scripts"`**, không mở quyền same-origin và không nhúng trang tài liệu ThreeUI. Cảnh có rễ phủ rêu, hoa, dương xỉ, phấn hoa, quét sáng và bướm theo nguồn gốc. Desktop khung 1240×740 kích hoạt bướm; mobile dành vùng cao 400px dưới nội dung, không có bướm theo điều kiện kích thước gốc. Di chuột vào phần cảnh để tương tác. Nút **Tạm dừng cảnh** gỡ renderer, bấm lại để tiếp tục; component tự gỡ iframe khi khuất hoặc ẩn tab. Reduced motion chỉ vẽ tĩnh. Không sửa mật độ hình học, shader hay chuyển động của nguồn.

Tải bộ khởi tạo khoảng 3 KB trước; cảnh/React/runtime được nạp động khi gần vùng nhìn sau khi trang đã vẽ. Chunk cảnh khoảng **1,10 MB trước nén, 299 KB gzip**; gzip là số Vite ước tính, máy chủ hosting phải bật nén mới có dung lượng truyền tương ứng. Cảnh 3D vẫn dùng GPU khi đang hiển thị, không cam kết nhanh hơn giao diện 2D trên mọi máy. Tìm kiếm và nội dung vẫn dùng được khi JS bị tắt hoặc bundle cảnh không tải được.

Hai font phụ của stylesheet/HTML được lấy đúng tài nguyên chính thức, không thay font khác:

- [Lexend Latin](https://threeui.com/landing-pages/inner-green-assets/lexend-latin.woff2): SHA-256 `1ec8f6ee2750554b4bc59ff0b507d316a82a7ba37e0e5bebc41d3bd9b9faad46`, khớp asset trong [sylva-hero.json](https://threeui.com/source-code/sylva-hero.json). Phục vụ đường gốc `/inner-green-assets/lexend-latin.woff2`; CORS `*` chỉ cho tệp font công khai này để sandbox origin `null` đọc được.
- [Fragment Mono](https://threeui.com/assets/fragment-mono-DI4ZVuWr.woff2): SHA-256 `4f4dc27f4a770c0d02fde800daa836c8adc0d1e423b28da74baaf0d1cc3ab96c`, khớp asset trong [animated-top-dock.json](https://threeui.com/source-code/animated-top-dock.json), đóng gói đúng đường `src/shaders/fonts/fragment-mono.woff2`. Cả hai có kiểm tra hash cùng bốn tệp gốc.

Sau khi lấy source hoặc sửa React island, chạy trong thư mục Laravel với Node/npm trong PATH:

```powershell
npm ci --ignore-scripts
npm run build
```

`npm run build` tạo asset Laravel rồi tạo `public/build/sylva`; không chỉ gọi `vite build` vì build mặc định dọn thư mục đầu ra cha. Khi chỉ sửa vùng Sylva, dùng `npm run build:sylva`. Vite có cảnh báo chunk >500 KB do đóng gói nguyên nguồn, không phải lỗi build. Cảnh báo optional `fontaine` của cấu hình font Laravel cũ không chặn build. Các CSS/JS nghiệp vụ trực tiếp trong `public/` vẫn hoạt động như trước.

Kiểm chứng: 6 hash nguồn/font, build production và 51 tests Laravel / 561 assertions đạt. Edge: [8 nhóm cảnh trên mô phỏng 3002](../../06_Testing/sylva-scene/browser.json), [8 nhóm trên website hiện tại 3001](../../06_Testing/sylva-scene/live/browser.json), [hồi quy 8 nhóm menu/ba role/ngôn ngữ/MFA](../../06_Testing/smooth-navigation/browser.json); không lỗi JavaScript/shader hoặc asset cảnh bị thiếu. Đã xem [ảnh desktop thật](../../06_Testing/sylva-scene/live/desktop.png) và [ảnh mobile thật](../../06_Testing/sylva-scene/live/mobile.png). Cổng 3002 dùng Auth/database mô phỏng; cổng 3001 kiểm tra công khai chỉ đọc. Không chạy migration, tạo CV/đơn hoặc ghi tuyển dụng Supabase trong D38.

Kiểm thử cuối dùng Edge headless qua `SYLVA_TEST_HEADLESS=1`; đã có các lượt kiểm trực tiếp bằng cửa sổ Edge trước đó. Một số lượt visible sau đó nhận cuộn ngoài kịch bản, chưa xác định nguyên nhân; không tái hiện trong headless. Test cuối kiểm thêm vị trí cuộn giữ ổn định khi scene mobile xuất hiện, không tự cuộn lại để che lỗi. Đây là giới hạn môi trường kiểm thử, chưa đủ bằng chứng quy nguyên nhân cho người dùng hoặc coi là lỗi nguồn đã được sửa. Test không sửa component gốc; instrumentation chỉ nằm trong browser test realm.

## Hồ sơ, thông báo và bản đồ — D37 (14/10/2026)

- Candidate: menu tài khoản → **Xem hồ sơ của tôi** (`/candidate/profile/view`) hoặc **Chỉnh sửa hồ sơ** (`/candidate/profile`). Sửa thông tin, tải/thay/xóa JPG/PNG/WebP ≤2 MB, tối đa 4096×4096; email đăng nhập chỉ đọc.
- Chuông trên header mở 8 cập nhật gần nhất, đếm tất cả chưa đọc và làm nổi bật tuyển chọn. Có nút đánh dấu từng mục; kiểm tra mỗi 60 giây khi tab hiển thị, dừng khi ẩn hoặc hết phiên. Trang danh sách đầy đủ vẫn giữ.
- Công ty, chi tiết việc và hồ sơ Employer có bản đồ lấy địa chỉ đã lưu; chỉ gửi request Google khi bấm tải/mở. Không API key/Places; link Maps ngoài luôn có dự phòng. Địa chỉ cần đầy đủ để kết quả đúng.
- Header gọn, menu tài khoản native/keyboard, logo có hover/focus animation hữu hạn; giữ VI/EN/JA và reduced motion.

**Lưu trữ ảnh:** database Supabase giữ `candidate_profiles.avatar_key`; ảnh nằm trên máy chủ tại `storage/app/private/profile-avatars`, chỉ chủ hồ sơ tải được. Ảnh chưa đưa vào Supabase Storage; khi hosting cần giữ thư mục này trên volume bền vững. Migration cột ảnh đã áp dụng Supabase batch 6; các môi trường khác chạy migration thông thường sau khi kiểm tra database đích. Không cần `storage:link` cho ảnh riêng tư.

[Kiểm thử và giới hạn](../../06_Testing/profile-toolbar-map/REPORT.md): 69 tests PostgreSQL / 596 assertions, 16 nhóm Edge đạt. Kiểm thử upload/xóa ảnh và UI thông báo ở môi trường mô phỏng; website thật đã smoke GET home/jobs/job 200.

## Đa ngôn ngữ và trợ lý nội bộ miễn phí — D36 (14/10/2026)

Chọn **Tiếng Việt / English / 日本語** trên thanh đầu trang là áp dụng ngay cho toàn bộ giao diện, không cần bấm thêm nút. Trang tải lại để lấy bản dịch đầy đủ và giữ nguyên đường dẫn/bộ lọc/điểm neo. Lựa chọn được nhớ bằng session và cookie 180 ngày khi qua các menu hoặc đăng xuất. Nếu tắt JavaScript, nút **Đổi / Apply / 変更** vẫn dùng được. Trang công khai, đăng nhập, Candidate/Employer/Admin, biểu mẫu và thông báo nghiệp vụ đã có bộ dịch; nội dung do người dùng nhập, danh mục trong database, CV và email đã tạo vẫn giữ ngôn ngữ gốc. Một số lỗi bảo vệ yêu cầu trước khi khởi tạo phiên dùng ngôn ngữ mặc định.

Cập nhật độ mượt 14/10: chuyển trang cùng website bằng native View Transition 180ms trên trình duyệt hỗ trợ; menu/hộp trợ lý 180ms, nội dung xuất hiện 240ms. Không trì hoãn yêu cầu điều hướng. Hiệu ứng cuộn chỉ đo các vùng gần màn hình, bỏ qua ghi style không đổi; nền hạt tái sử dụng vị trí/màu, dừng khi nghỉ/khuất/ẩn tab. Mobile và reduced motion được kiểm riêng. [Bằng chứng kiểm thử](../../06_Testing/smooth-navigation/browser.json), [ảnh mobile Nhật](../../06_Testing/smooth-navigation/mobile-ja.png). Đây là tối ưu giao diện, không phải cam kết thời gian phản hồi Supabase hoặc FPS trên mọi thiết bị.

Nút **Trợ lý InternBridge** mở hộp hỗ trợ trên trang công khai và workspace. Bot hướng dẫn sử dụng, tìm tối đa 6 tin công khai đang nhận hồ sơ; không đọc CV/hồ sơ riêng, không nộp đơn, sửa dữ liệu hoặc quyết định tuyển dụng. Tìm kiếm dùng từ khóa/tên gọi tương đương Việt–Anh–Nhật; chưa phải tìm kiếm ngữ nghĩa đầy đủ. Khi đổi tiêu chí, hãy ghi lại cả ngành và địa điểm/hình thức mong muốn, vì bộ tìm tin dùng câu hỏi hiện tại.

**Không cần khóa API và không phát sinh phí API AI.** Người dùng đã chọn trợ lý nội bộ theo hướng dẫn và từ khóa, không phải mô hình AI tạo sinh. Tích hợp OpenAI cùng cấu hình key/model đã được gỡ khỏi đường xử lý và env. Câu hỏi ngoài phạm vi website/tìm thực tập được chuyển về thông báo phạm vi; không trả lời kiến thức chung, viết code hoặc thực hiện thao tác thay người dùng. Cấu hình chống lạm dụng giữ tại backend:

```dotenv
ASSISTANT_ENABLED=true
ASSISTANT_REQUESTS_PER_MINUTE=6
ASSISTANT_DAILY_REQUEST_LIMIT=100
```

Mở trang chủ, bấm trợ lý và chọn câu hỏi mẫu hoặc hỏi “Làm sao để nộp CV?”, “Tìm thực tập frontend từ xa ở Hà Nội”. Hỏi “Thời tiết hôm nay?” để xem giới hạn phạm vi. Giao diện và câu trả lời hỗ trợ Việt/Anh/Nhật. Mỗi câu được xử lý độc lập; hãy nêu đủ ngành, địa điểm và hình thức làm việc. Cổng 3002 chỉ mô phỏng database/Auth, dùng cùng bộ trả lời nội bộ thật như cổng 3001. Sau khi thay cấu hình, chạy `../../../.tools/php-8.4.26/php.exe artisan config:clear`.

Mặc định tối đa 6 câu/phút/IP và tài khoản, 3 yêu cầu đồng thời, 100 lượt/ngày UTC toàn website để giới hạn công việc tìm kiếm. Khi chạy nhiều máy cần cache dùng chung. Chỉ câu hỏi hiện tại được gửi tới backend của website, không gửi cho nhà cung cấp AI; lịch sử hiển thị chỉ nằm trong bộ nhớ trang và không ghi database. Không có request bot lúc tải trang. Tìm tin vẫn đọc database của website trên Supabase; đây không phải chế độ offline toàn website.

Hoạt ảnh bổ sung: minh họa hero xuất hiện theo nhịp tối đa 360 ms, icon phản hồi hover/focus và tin nhắn mới hiện nhẹ 180 ms. Không có hoạt ảnh trang trí lặp vô hạn; tạm dừng khi ẩn trang/hero khuất và tôn trọng reduced motion. [Báo cáo kiểm thử và ảnh](../../06_Testing/multilingual-assistant/REPORT.md). Website hiện chạy tại cổng 3001 với Supabase; không có migration hoặc ghi dữ liệu tuyển dụng cloud cho tính năng này.

## GitHub và hosting

Trạng thái hiện hành nằm ở mục **D40** đầu tài liệu: mã đã ở repository private, người dùng chọn miễn phí và cần hướng dẫn đăng ký Oracle; chưa có VM/domain hoặc bản website online. **GitHub Pages không chạy backend PHP/Laravel.** Chỉ đưa mã nguồn và cấu hình mẫu lên Git; `.env`, API key, tài khoản Admin, CV/ảnh, database/backup, log, session và `.tools` được giữ ngoài repository.

Khi chọn host cần thống nhất HTTPS, cache/session bền vững, SMTP/worker và bộ quét CV tương thích hệ điều hành. Không tắt cơ chế chặn CV chưa được quét để deploy. Phí hosting/database (nếu có) độc lập với trợ lý nội bộ không dùng API AI trả phí.

## Chạy ngay trong workspace này

Từ `D:\code\DO_AN_PY`:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\PROJECT\04_Code\laravel\run.ps1 db-start
powershell -NoProfile -ExecutionPolicy Bypass -File .\PROJECT\04_Code\laravel\run.ps1 start
```

Mở **http://127.0.0.1:3001**. Backend hiện dùng Supabase Auth, PostgreSQL và Storage; đăng nhập bằng tài khoản thật. Trang quản trị: **http://127.0.0.1:3001/admin/login**, chỉ nhận Admin đang hoạt động và yêu cầu mật khẩu + mã từ ứng dụng xác thực (TOTP). Lần đầu phải thiết lập ứng dụng; quyền quản trị hết hạn sau 30 phút. Đăng nhập Admin qua `/login` cũng phải qua MFA; `/demo/admin` luôn bị vô hiệu hóa. Demo Candidate/Employer chỉ còn dành cho môi trường local bật tường minh, hiện `DEMO_MODE=false`.

Ctrl+C dừng web server. `run.ps1 db-status` kiểm tra database; `run.ps1 db-stop` chỉ dừng cluster riêng của đồ án. Các lệnh không thay đổi PostgreSQL service có sẵn của máy. Không chạy `migrate:fresh` trên database phát triển.

## MFA và email trạng thái — D34

MFA dùng Supabase TOTP; xác nhận mật khẩu có hiệu lực 10 phút để hoàn tất bước mã. Tài khoản đã bật MFA phải nhập mã cả trong luồng khôi phục mật khẩu; recovery không tự đăng nhập. Nếu mất thiết bị, người quản lý project cần xác minh danh tính rồi xử lý factor trong Supabase; website không có nút bỏ qua MFA. Không lưu khóa TOTP vào tài liệu/tệp tài khoản Admin.

Email trạng thái mặc định `APPLICATION_MAIL_MODE=preview`: mỗi lần Employer đổi trạng thái hợp lệ tạo một hàng riêng trong `application_status_emails`, cùng giao dịch với lịch sử. Chạy từ thư mục Laravel:

```powershell
../../../.tools/php-8.4.26/php.exe artisan app:deliver-application-emails --limit=50
../../../.tools/php-8.4.26/php.exe artisan schedule:work
```

Lệnh đầu xử lý một đợt; lệnh sau chạy lịch mỗi phút cho tới khi Ctrl+C. Ứng viên mở **Thông báo → Xem email mô phỏng** sau khi xử lý. HTML nằm trong `storage/app/private/status-email-previews`, chỉ chủ đơn xem qua route có đăng nhập. Không có backfill hay gửi lại lịch sử cũ. `PREVIEWED` chưa gửi thư; `SENT` chỉ nghĩa SMTP đã tiếp nhận, chưa chứng minh vào hộp thư.

Gửi thật cần cấu hình riêng SMTP, sender, `APPLICATION_MAIL_MAILER` (mặc định `smtp`) và `APPLICATION_MAIL_MODE=send`. Không dùng `log`, `array` hoặc `failover` làm bằng chứng gửi thật; timeout SMTP tối đa 10 giây. Thư hàng chờ mô phỏng không tự chuyển thành thư thật khi đổi cấu hình. Hiện chỉ nghiệm thu mô phỏng, chưa xác minh SMTP/hộp thư.

`--retry-preview-failed` chỉ thử lại lỗi ghi bản xem thử/APP_URL, không thử lại SMTP. Hàng `PROCESSING` bị treo hoặc `FAILED/MAIL_DELIVERY_UNCERTAIN` cần kiểm tra thủ công trước khi quyết định gửi lại vì không thể bảo đảm SMTP đúng một lần khi mất kết nối. Tắt chế độ `send` cũng chặn các hàng gửi thật đang chờ.

Môi trường trình diễn riêng (không kết nối Supabase, không phải một chế độ của website thật):

```powershell
$env:INTERNBRIDGE_SECURITY_DEMO='1'
../../../.tools/php-8.4.26/php.exe -S 127.0.0.1:3002 -t public tests/Support/security_demo_router.php
```

Tại `http://127.0.0.1:3002`: tài khoản giả `admin@simulation.test`, `employer@simulation.test`, `candidate@simulation.test`; mật khẩu giả chung `Simulation-only-2026`, OTP giả `123456`. Dữ liệu riêng trong `storage/app/private/security-demo`; router chỉ chạy bằng PHP CLI server trên loopback cổng 3002 và không được nạp trong route production. Xử lý email mô phỏng riêng bằng `php tests/Support/security_demo_router.php deliver` với biến môi trường trên. Các dữ liệu này hoàn toàn không phải thông tin đăng nhập thật.

## Cấu hình đã liên kết

| Thành phần | Cấu hình |
|---|---|
| PHP | 8.4.26 portable trong `.tools/php-8.4.26` |
| Backend + frontend | Laravel 13.33.0, Blade; `public/app.css`, `public/app.js` |
| PostgreSQL | 18.6, `127.0.0.1:54329`, cluster `.tools/pg-test-runtime/data` |
| Database ứng dụng hiện hành | Supabase PostgreSQL, schema `internbridge`; bản nguồn local `internship_dev` được giữ để khôi phục |
| Database kiểm thử | `internship_test`; kiểm thử cạnh tranh dùng `internship_concurrency_test` |
| Danh tính thật | Supabase Auth, URL/public key trong `.env` |
| Phiên web | File session mã hóa phía server; refresh token không gửi vào HTML |
| CV | Supabase Storage private; chỉ tải qua route backend kiểm tra quyền |
| Thời gian | Lưu và kết nối PostgreSQL UTC; giao diện hiển thị giờ Việt Nam |

`.env` đã được cấu hình riêng trong workspace; không in hoặc commit. `SUPABASE_DATA_ENABLED=true`, `SUPABASE_CV_DISK=supabase`; DB URI/secret key chỉ nằm phía backend. Xem [bằng chứng chuyển cloud](../../06_Testing/cloud-transfer/REPORT.md). Giao diện nghiệp vụ phục vụ trực tiếp `public/app.css` và `public/app.js`, có phiên bản asset theo thời gian sửa tệp; riêng cảnh Sylva trang chủ cần `npm run build` khi lấy source hoặc thay phần React. Bundle đã được build trong workspace này. PostgreSQL local vẫn dùng cho kiểm thử độc lập.

## Chức năng đã triển khai

- **Public:** danh sách, từ khóa, ngành, địa điểm, hình thức, sắp xếp, phân trang, chi tiết tin và công ty.
- **Candidate:** hồ sơ, tải/đổi tên/lưu trữ CV PDF tối đa 5 MB (5 bản đang dùng), ứng tuyển, theo dõi trạng thái và lịch sử. CV và thông tin tại thời điểm nộp được giữ nguyên.
- **Employer:** hồ sơ công ty/logo, tin nháp/chỉnh sửa/gửi duyệt/đóng, danh sách và chi tiết ứng viên, xem CV đúng đơn, cập nhật kết quả.
- **Admin:** dashboard, danh sách tài khoản, khóa/mở khóa, ẩn công ty, duyệt/từ chối/ẩn tin, xử lý báo cáo vi phạm và thống kê theo kỳ; các thao tác có lịch sử.
- **Bảo vệ:** CSRF, phân quyền và sở hữu dữ liệu, giới hạn đăng nhập/đăng ký, phiên đã khóa bị từ chối, ràng buộc FK/CHECK/unique, giao dịch và khóa hàng khi ứng tuyển/đóng/ẩn, kiểm tra phiên bản khi cập nhật.

Bốn trạng thái đơn: `SUBMITTED → REVIEWING → SELECTED/REJECTED`; có thể từ chối trực tiếp đơn mới. Tin phải được admin duyệt trước khi công khai. Mỗi tài khoản có một vai trò; mỗi employer quản lý một công ty.

## Chạy thử một quy trình

1. Thử Nhà tuyển dụng → tạo tin đầy đủ → lưu nháp → gửi duyệt.
2. Đăng xuất → Thử Admin → Kiểm duyệt tin → chọn tin → duyệt với ghi chú.
3. Đăng xuất → Thử Sinh viên → tìm tin → ứng tuyển → chọn CV → xác nhận.
4. Đăng xuất → Thử Nhà tuyển dụng → Tin tuyển dụng & ứng viên → xem hồ sơ → Đang xem xét → Được lựa chọn.
5. Thử Sinh viên để xem lịch sử kết quả; thử Admin để xem số liệu hoặc tiếp nhận báo cáo.

Dữ liệu mẫu ban đầu có 5 tài khoản, 2 công ty, 10 tin, 2 CV và 3 đơn. Kiểm thử trình duyệt tạo thêm tin/đơn có tên “kiểm thử”; được giữ lại để xem quy trình đã chạy. Chạy seed lại không đặt lại trạng thái bạn đã sửa.

## Đăng ký thật và cấp Admin

Đăng ký chỉ nhận Candidate/Employer. Cấu hình Supabase hiện yêu cầu xác nhận email; xác nhận theo email rồi trở lại trang đăng nhập. User/Profile cục bộ chỉ được tạo sau đăng nhập thành công, tránh lưu UUID che giấu khi email đã tồn tại. Email bị trùng nhận thông báo chung, không tạo thêm tài khoản cục bộ.

Để cấp Admin cho người vận hành: tạo tài khoản Supabase riêng, xác nhận email, đăng nhập một lần; sau đó người có quyền CLI chạy `php artisan app:grant-admin UUID`. Không có đăng ký Admin công khai. Nên dùng tài khoản quản trị riêng chưa có hoạt động tuyển dụng/ứng tuyển.

Kiểm thử tự động mô phỏng phản hồi Supabase và chặn request ngoài. Luồng xác nhận email/đăng nhập tài khoản thật chưa được chạy end-to-end vì chưa có tài khoản thật được cung cấp; demo dùng để kiểm tra toàn bộ nghiệp vụ local.

## Kiểm thử

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\PROJECT\04_Code\laravel\run.ps1 test
powershell -NoProfile -ExecutionPolicy Bypass -File .\PROJECT\04_Code\laravel\run.ps1 test-pg
powershell -NoProfile -ExecutionPolicy Bypass -File .\PROJECT\04_Code\laravel\run.ps1 test-concurrency
powershell -NoProfile -ExecutionPolicy Bypass -File .\PROJECT\04_Code\laravel\run.ps1 check
```

`test` dùng SQLite memory, bỏ qua CHECK dành riêng PostgreSQL và kiểm thử cạnh tranh. `test-pg` chạy nghiệp vụ và ràng buộc thật; `test-concurrency` dùng hai tiến trình HTTP riêng để kiểm tra tranh chấp. Guard ở `tests/TestCase.php` từ chối database phát triển/từ xa trước khi reset dữ liệu test. Script đọc mật khẩu cluster test từ file riêng, không in và khôi phục biến môi trường khi kết thúc.

Kiểm thử Edge desktop/mobile khi website đang chạy:

```powershell
.tools\node-v24.21.0-win-x64\node.exe PROJECT\04_Code\laravel\tests\Browser\website-smoke.mjs
```

Puppeteer dùng bản đã có trong `.tools/diagram-renderer`, Edge có sẵn trên máy. Script chỉ trỏ localhost, thao tác qua form thật, lưu ảnh và `result.json` tại `PROJECT/06_Testing/browser`. Báo cáo tổng hợp: [LOCAL_VERIFICATION.md](../../06_Testing/LOCAL_VERIFICATION.md).

## Chạy trên máy khác

Cài PHP 8.4 với PDO pgsql, openssl, mbstring, curl, fileinfo và Composer; cài PostgreSQL, tạo database và role riêng. `composer install`, sao chép `.env.example` thành `.env`, điền kết nối thật và public key Supabase; chạy `php artisan key:generate`, `php artisan migrate --no-interaction`, rồi `php artisan serve --host=127.0.0.1 --port=3001`. Không dùng mật khẩu mẫu.

Seed demo chỉ cho môi trường local/testing và database loopback tên `internship_dev`/`internship_test`; chạy `php artisan db:seed --no-interaction` rồi bật `DEMO_MODE=true` nếu muốn dữ liệu mẫu. Trên máy khác điều chỉnh đường dẫn PHP/PostgreSQL của script hoặc dùng lệnh Artisan trực tiếp. Để nhận CV đủ 5 MB, cấu hình PHP `upload_max_filesize >= 5M`, `post_max_size >= 8M`.

Trước khi triển khai public: tắt demo, dùng HTTPS/cookie secure, sao lưu DB+CV, cấu hình email/redirect Supabase, đánh giá tải thực tế. Phạm vi bàn giao hiện tại là website chạy cục bộ, chưa phải triển khai production.

## API nền tảng còn giữ

`GET /health` kiểm tra liveness. `GET /api/me` xác minh JWT Supabase Bearer bằng JWKS và trả danh tính; đây không phải API CRUD tuyển dụng. Các chức năng nghiệp vụ dùng route web/session/Blade. API này không thay việc kiểm tra trạng thái tài khoản nội bộ trên các route nghiệp vụ.

## Bộ mẫu đa dạng và Supabase Database/Storage (D29)

**Cập nhật D30 (28/09): backend đang dùng Supabase thật.** Đã chuyển 18 tài khoản, 6 công ty, 31 tin, 16 CV và 25 đơn từ local, giữ nguyên lịch sử và đọc lại dữ liệu/file thành công. `SUPABASE_DATA_ENABLED=true`, `SUPABASE_CV_DISK=supabase`, `DEMO_MODE=false`. Thông tin "thiếu credential/chưa có cloud" bên dưới mô tả giai đoạn D29 trước đó. [Báo cáo hiện hành, cách đăng nhập và khôi phục local](../../06_Testing/cloud-transfer/REPORT.md).

Đợt 28/09 đã thêm 8 ứng viên/CV khác nhau, 4 công ty, 12 tin và nộp 13 đơn qua trình duyệt local. Chọn người thử ở trang chủ. Không xóa dữ liệu mẫu trước đó.

`php artisan app:import-recruitment-samples --local --no-interaction` nhập bộ mẫu vào đúng database local đã giới hạn; chạy lại không đặt lại trạng thái đã sửa. Lệnh không tạo đơn ứng tuyển.

Hỗ trợ cloud đã có nhưng mặc định tắt: cần `SUPABASE_DB_URL` và `SUPABASE_SECRET_KEY` đầy đủ, chuẩn bị schema/bucket bằng `app:supabase-readiness --prepare`, rồi mới chuyển `SUPABASE_DATA_ENABLED=true` và `SUPABASE_CV_DISK=supabase`. Hiện hai credential còn thiếu; chưa có bằng chứng cloud.

Xem [báo cáo, quy trình chuyển môi trường và cách kiểm tra trên Dashboard](../../06_Testing/diverse-samples/VERIFICATION.md). Lệnh readiness không tự chuyển database. Không dùng seeder demo local trên cloud; công cụ `--supabase` tạo Auth identity thật cho mẫu và lưu PDF private. Không sao chép UUID demo thành tài khoản Auth.
