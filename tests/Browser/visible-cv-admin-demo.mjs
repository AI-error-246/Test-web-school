import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import puppeteer from '../../../../../.tools/diagram-renderer/node_modules/puppeteer/lib/puppeteer/puppeteer.js';

// Only run after explicit authorization to show the browser and submit synthetic test data.
if (!process.argv.includes('--run')) {
  console.log('Chưa chạy. Thêm --run khi đã được phép mở trình duyệt và nộp CV kiểm thử.');
  process.exit(0);
}
const root = fileURLToPath(new URL('../../../../../', import.meta.url));
const app = root + 'PROJECT/04_Code/laravel/';
const output = root + 'PROJECT/06_Testing/visible-demo/';
const base = 'http://127.0.0.1:3001';
const pdf = root + 'output/pdf/diverse/minh-khoi.pdf';
const date = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Bangkok' }).format(new Date()).replaceAll('-', '');
const displayName = `Demo trực tiếp - Nguyễn Minh Khôi - ${date}`;
const input = JSON.parse(await readFile(app + 'storage/app/private/cloud-transfer-smoke-input.json', 'utf8'));
const env = await readFile(app + '.env', 'utf8');
if (!/^SUPABASE_DATA_ENABLED=true\s*$/m.test(env) || !/^SUPABASE_CV_DISK=supabase\s*$/m.test(env)) throw new Error('Website phải dùng Supabase Database và Storage trước khi chạy.');
if (input.candidate?.role !== 'candidate' || input.admin?.role !== 'admin') throw new Error('Thiếu tài khoản kiểm thử đúng vai trò.');
const expectedHash = createHash('sha256').update(await readFile(pdf)).digest('hex');
await mkdir(output, { recursive: true });
let previous = null;
try { previous = JSON.parse(await readFile(output + 'result.json', 'utf8')); } catch {}
const result = { status: 'running', startedAt: new Date().toISOString(), displayName, candidate_id: input.candidate.id, checks: [], errors: [] };
const save = () => writeFile(output + 'result.json', JSON.stringify(result, null, 2));
let step = 'Chuẩn bị';
const ensure = (condition, message) => { if (!condition) { const error = new Error(message); error.safe = true; throw error; } };
const pause = () => new Promise(resolve => setTimeout(resolve, 5000));
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: false, defaultViewport: null, args: ['--no-first-run', '--window-size=1440,1000'] });
const candidateContext = await browser.createBrowserContext();
const adminContext = await browser.createBrowserContext();
const candidatePage = await candidateContext.newPage();
const adminPage = await adminContext.newPage();
for (const page of [candidatePage, adminPage]) {
  page.setDefaultNavigationTimeout(90000);
  page.on('pageerror', () => result.errors.push('Lỗi JavaScript trên trang'));
}
let current = candidatePage;
const mark = async (page, label) => {
  current = page; step = label;
  await page.bringToFront();
  await page.evaluate(text => {
    let el = document.getElementById('visible-demo-step');
    if (!el) { el = document.createElement('div'); el.id = 'visible-demo-step'; el.setAttribute('role', 'status'); document.body.append(el); }
    el.textContent = text;
    el.style.cssText = 'position:fixed;bottom:22px;right:22px;z-index:2147483647;background:#123d35;color:white;border:2px solid #d9f27e;padding:18px 24px;border-radius:16px;max-width:540px;font:600 17px/1.5 system-ui;box-shadow:0 8px 40px #0005;pointer-events:none';
  }, label);
  console.log(label);
  await pause();
};
const go = async (page, path) => {
  const response = await page.goto(base + path, { waitUntil: 'networkidle0' });
  ensure(response?.status() === 200, 'Không mở được trang yêu cầu. Kiểm tra cửa sổ trình duyệt.');
};
const submit = async (page, selector) => {
  const button = selector + ' button:not([type]), ' + selector + ' button[type="submit"]';
  const [response] = await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click(button)]);
  ensure(response?.status() === 200 && !(await page.$('.alert-danger')), 'Biểu mẫu chưa thành công. Dữ liệu hiện có được giữ nguyên; xem thông báo trên trang.');
};
const login = async (page, user, path) => {
  await go(page, path);
  await mark(page, user.role === 'admin' ? '5/6 · Đăng nhập quản trị bằng email và mật khẩu' : '1/6 · Đăng nhập tài khoản ứng viên kiểm thử');
  await page.type('[name="email"]', user.email, { delay: 30 });
  await page.type('[name="password"]', user.password, { delay: 10 });
  await pause();
  await submit(page, `form[action$="${path}"]`);
  ensure(new URL(page.url()).pathname === '/' + user.role, 'Tài khoản chưa vào được không gian đúng vai trò.');
};
const findCv = () => candidatePage.$$eval('article.panel', (cards, name) => {
  const card = cards.find(el => el.querySelector('h2')?.textContent.trim() === name && el.querySelector('.badge')?.textContent.trim() === 'Đang sử dụng');
  return Number(card?.querySelector('a[href*="/cvs/"]')?.href.match(/\/cvs\/(\d+)/)?.[1]) || null;
}, displayName);
const verifyPdf = async cvId => {
  const download = await candidatePage.evaluate(async id => {
    const response = await fetch('/cvs/' + id);
    const digest = await crypto.subtle.digest('SHA-256', await response.arrayBuffer());
    return { status: response.status, type: response.headers.get('content-type'), sha256: Array.from(new Uint8Array(digest), value => value.toString(16).padStart(2, '0')).join('') };
  }, cvId);
  ensure(download.status === 200 && download.type?.includes('application/pdf') && download.sha256 === expectedHash, 'PDF đọc lại chưa khớp file gốc. Dừng để kiểm tra.');
};
try {
  await save();
  await login(candidatePage, input.candidate, '/login');
  await go(candidatePage, '/candidate/cvs');
  await mark(candidatePage, '2/6 · Tải một CV mẫu lên website — giữ nguyên các CV hiện có');
  let cvId = await findCv();
  if (!cvId) {
    const activeCount = await candidatePage.$$eval('article.panel .badge', badges => badges.filter(el => el.textContent.trim() === 'Đang sử dụng').length);
    ensure(activeCount < 5, 'Ứng viên đã có 5 CV hoạt động. Không tự lưu trữ hoặc xóa; cần người dùng chọn cách xử lý.');
    await candidatePage.type('form[action$="/candidate/cvs"] [name="display_name"]', displayName, { delay: 35 });
    await (await candidatePage.$('input[name="cv"]')).uploadFile(pdf);
    await pause();
    await submit(candidatePage, 'form[action$="/candidate/cvs"]');
    cvId = await findCv();
  }
  ensure(cvId, 'Chưa tìm thấy CV kiểm thử sau khi tải lên.');
  result.cv = { id: cvId, candidate_id: input.candidate.id, sha256: expectedHash };
  await verifyPdf(cvId);
  result.checks.push('Đọc lại PDF qua phiên đăng nhập: HTTP200 và SHA-256 khớp file gốc');
  await candidatePage.screenshot({ path: output + '02-candidate-cvs.png', fullPage: true }); await save();
  let jobId = null;
  let applicationId = null;
  if (previous?.candidate_id === input.candidate.id && previous.displayName === displayName && previous.cv?.id === cvId && Number.isInteger(previous.application?.job_id)) {
    jobId = previous.application.job_id;
    const response = await candidatePage.goto(`${base}/jobs/${jobId}/apply`, { waitUntil: 'networkidle0' });
    const existingId = Number(new URL(candidatePage.url()).pathname.match(/^\/applications\/(\d+)$/)?.[1]);
    if (response?.status() === 200 && existingId && (!previous.application.id || previous.application.id === existingId) && await candidatePage.$(`a[href$="/cvs/${cvId}"]`)) applicationId = existingId;
    else jobId = null;
  }
  if (!applicationId) {
    await go(candidatePage, '/jobs?q=Laravel');
    await mark(candidatePage, '3/6 · Tìm vị trí Laravel hoặc Frontend đang mở mà ứng viên chưa nộp');
    const jobs = await candidatePage.evaluate(async () => {
      const ids = new Set();
      for (const term of ['Laravel', 'Frontend']) {
        let path = '/jobs?q=' + term;
        for (let number = 0; number < 5 && path; number++) {
          const response = await fetch(path); if (!response.ok) break;
          const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
          for (const link of doc.querySelectorAll('.job-card a[href]')) { const id = new URL(link.href, location.origin).pathname.match(/^\/jobs\/(\d+)$/)?.[1]; if (id) ids.add(Number(id)); }
          const next = doc.querySelector('.pagination a[rel="next"]'); path = next ? new URL(next.href, location.origin).pathname + new URL(next.href, location.origin).search : null;
        }
      }
      return Array.from(ids);
    });
    for (const id of jobs) {
      const response = await candidatePage.goto(`${base}/jobs/${id}/apply`, { waitUntil: 'networkidle0' });
      if (response?.status() === 200 && new URL(candidatePage.url()).pathname === `/jobs/${id}/apply` && await candidatePage.$('select[name="cv_id"]')) { jobId = id; break; }
    }
    ensure(jobId, 'Không còn vị trí Laravel hoặc Frontend đang mở chưa ứng tuyển. Không tạo thêm tin hoặc nộp trùng.');
    result.application = { candidate_id: input.candidate.id, job_id: jobId, cv_id: cvId }; await save();
    await candidatePage.select('[name="cv_id"]', String(cvId));
    const cover = await candidatePage.$('textarea[name="cover_letter"], textarea[name="message"]');
    if (cover) await cover.type('Đây là hồ sơ giả lập để kiểm thử chức năng website, không phải ứng tuyển thực tế.');
    await candidatePage.click('[name="consent"]');
    await mark(candidatePage, '4/6 · Đã chọn CV và xác nhận — chuẩn bị gửi đơn kiểm thử');
    await candidatePage.screenshot({ path: output + '03-before-submit.png', fullPage: true });
    await submit(candidatePage, `form[action$="/jobs/${jobId}/apply"]`);
    applicationId = Number(new URL(candidatePage.url()).pathname.match(/^\/applications\/(\d+)$/)?.[1]);
  }
  ensure(applicationId && await candidatePage.$(`a[href$="/cvs/${cvId}"]`), 'Trang xác nhận chưa liên kết đúng CV đã chọn.');
  await verifyPdf(cvId);
  result.application = { id: applicationId, candidate_id: input.candidate.id, job_id: jobId, cv_id: cvId, url: candidatePage.url() };
  await mark(candidatePage, `4/6 · Hồ sơ #${applicationId} đã được ghi nhận; PDF đọc lại khớp bản gốc`);
  await candidatePage.screenshot({ path: output + '04-application-confirmed.png', fullPage: true }); await save();
  await login(adminPage, input.admin, '/admin/login');
  await mark(adminPage, '5/6 · Đã xác thực Admin — xem dashboard quản trị');
  await adminPage.screenshot({ path: output + '05-admin-dashboard.png', fullPage: true });
  await go(adminPage, '/admin/users'); await mark(adminPage, '6/6 · Kiểm tra danh sách người dùng — chỉ xem dữ liệu');
  await adminPage.screenshot({ path: output + '06-admin-users.png', fullPage: true });
  await go(adminPage, '/admin/jobs'); await mark(adminPage, '6/6 · Kiểm tra tin tuyển dụng — không duyệt hoặc đổi nội dung');
  await adminPage.screenshot({ path: output + '06-admin-jobs.png', fullPage: true });
  await go(adminPage, '/admin/statistics'); await mark(adminPage, '6/6 · Xem thống kê hệ thống sau khi nộp đơn kiểm thử');
  await adminPage.screenshot({ path: output + '06-admin-statistics.png', fullPage: true });
  await go(adminPage, '/admin'); await mark(adminPage, `Hoàn tất · Đơn #${applicationId} ở cửa sổ ứng viên; Admin đang mở để bạn xem`);
  ensure(result.errors.length === 0, 'Có lỗi JavaScript cần kiểm tra thêm.');
  result.status = 'passed'; result.completedAt = new Date().toISOString(); result.admin = { id: input.admin.id, verified: true, url: base + '/admin' }; await save();
  console.log(JSON.stringify({ status: 'passed', cvId, applicationId, report: 'PROJECT/06_Testing/visible-demo/result.json' }));
} catch (error) {
  result.status = 'failed'; result.step = step; result.failure = error.safe ? error.message : 'Thao tác chưa hoàn tất; xem cửa sổ trình duyệt tại bước đang hiển thị.';
  await save();
  await current.screenshot({ path: output + 'failure.png', fullPage: true }).catch(() => {});
  await mark(current, 'Tạm dừng · ' + result.failure).catch(() => {});
  console.error(JSON.stringify({ status: 'failed', step, message: result.failure })); process.exitCode = 1;
} finally {
  browser.disconnect();
}
