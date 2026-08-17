const {test, expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const {readFile} = require('node:fs/promises');
const {get} = require('node:http');

function requestWithAuthority(url, authority) {
    return new Promise((resolve, reject) => {
        const request = get(url, {headers: {
            Host: authority,
            'X-Forwarded-Proto': 'https',
        }}, (response) => {
            let body = '';
            response.setEncoding('utf8');
            response.on('data', (chunk) => { body += chunk; });
            response.on('end', () => resolve({status: response.statusCode, body}));
        });
        request.on('error', reject);
    });
}

async function expectNoSeriousAccessibilityViolations(page) {
    const results = await new AxeBuilder({page}).analyze();
    const violations = results.violations.filter(({impact}) => impact === 'critical' || impact === 'serious');
    expect(violations, JSON.stringify(violations, null, 2)).toEqual([]);
}

async function expectNoHorizontalOverflow(page) {
    const dimensions = await page.evaluate(() => ({
        clientWidth: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
        overflowing: [...document.querySelectorAll('body *')]
            .filter((element) => element.getBoundingClientRect().right > document.documentElement.clientWidth + 1)
            .slice(0, 10)
            .map((element) => ({
                tag: element.tagName,
                className: element.className?.toString().slice(0, 120),
                width: Math.round(element.getBoundingClientRect().width),
                right: Math.round(element.getBoundingClientRect().right),
            })),
    }));
    expect(dimensions.scrollWidth, JSON.stringify(dimensions.overflowing)).toBeLessThanOrEqual(dimensions.clientWidth + 1);
}

async function login(page) {
    await page.goto('/login');
    await page.getByLabel('管理密码').fill('BrowserTest!234');
    await page.getByRole('button', {name: '安全登录'}).click();
    await expect(page.getByRole('heading', {name: '创建短链接'})).toBeVisible();
}

async function openAdvancedCreate(creator) {
    const details = creator.locator('.advanced-create');
    if (!await details.isVisible()) {
        await creator.page().getByRole('button', {name: '高级'}).click();
    }
    if (await details.getAttribute('open') === null) {
        await details.locator(':scope > summary').click();
    }
}

async function openDataTools(page) {
    const details = page.locator('.data-tools');
    if (!await details.isVisible()) {
        await page.getByRole('button', {name: '高级'}).click();
    }
    if (await details.getAttribute('open') === null) {
        await details.locator(':scope > summary').click();
    }
}

test('public homepage is the default entry', async ({page}) => {
    await page.goto('/');
    await expect(page.getByRole('heading', {name: '链匣 LinkVault', level: 1})).toBeVisible();
    await expect(page.getByLabel('管理密码')).toHaveCount(0);
    await expect(page.getByRole('link', {name: '进入管理后台'})).toHaveAttribute('href', '/login');
    const reportLink = page.getByRole('link', {name: '公开举报'});
    await expect(reportLink).toHaveAttribute('href', '/report');
    await expectNoHorizontalOverflow(page);
    await expectNoSeriousAccessibilityViolations(page);
    await page.getByRole('button', {name: '切换深色模式'}).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expectNoSeriousAccessibilityViolations(page);
});

test('login and dashboard are accessible', async ({page}) => {
    await page.goto('/login');
    await expectNoSeriousAccessibilityViolations(page);
    await login(page);
    await expectNoHorizontalOverflow(page);
    await expectNoSeriousAccessibilityViolations(page);
    await page.getByRole('button', {name: '切换深色模式'}).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expectNoSeriousAccessibilityViolations(page);
});

test('login errors stay adjacent to the form', async ({page}) => {
    await page.goto('/login');
    await page.getByLabel('管理密码').fill('incorrect-password');
    await page.getByRole('button', {name: '安全登录'}).click();

    const error = page.getByRole('alert');
    const panel = page.locator('.login-panel');
    await expect(error).toHaveText('密码错误。');

    const [errorBox, panelBox] = await Promise.all([error.boundingBox(), panel.boundingBox()]);
    expect(errorBox).not.toBeNull();
    expect(panelBox).not.toBeNull();
    expect(Math.abs(errorBox.x - panelBox.x)).toBeLessThanOrEqual(1);
    expect(panelBox.y - (errorBox.y + errorBox.height)).toBeGreaterThanOrEqual(-1);
    expect(panelBox.y - (errorBox.y + errorBox.height)).toBeLessThanOrEqual(20);
    await expectNoHorizontalOverflow(page);
});

test('login confirmation stays clear of the dashboard header', async ({page}) => {
    await page.goto('/login');
    await page.getByLabel('管理密码').fill('BrowserTest!234');
    await page.getByRole('button', {name: '安全登录'}).click();

    const toast = page.locator('.flash-toast');
    await expect(toast).toBeVisible();
    const [toastBox, headerBox] = await Promise.all([toast.boundingBox(), page.locator('.site-header').boundingBox()]);
    expect(toastBox).not.toBeNull();
    expect(headerBox).not.toBeNull();
    expect(toastBox.y).toBeGreaterThanOrEqual(headerBox.y + headerBox.height + 12);
    await expectNoHorizontalOverflow(page);
});

test('share uses the system sheet and falls back to copying the short URL', async ({page}, testInfo) => {
    await page.addInitScript(() => {
        Object.defineProperty(navigator, 'share', {
            configurable: true,
            value: async (data) => { window.__sharedData = data; },
        });
        Object.defineProperty(navigator, 'canShare', {
            configurable: true,
            value: () => true,
        });
    });
    await login(page);
    const creator = page.locator('.creator-panel');
    await creator.getByLabel('原始链接').fill(`https://example.com/share-${testInfo.project.name}`);
    await creator.getByRole('button', {name: '生成短链接'}).click();

    const shareButton = page.locator('[data-share]:visible').first();
    const expectedUrl = await shareButton.getAttribute('data-share-url');
    await shareButton.click();
    await expect(page.locator('#copy-feedback')).toHaveText('分享已完成。');
    expect(await page.evaluate(() => window.__sharedData?.url)).toBe(expectedUrl);

    await page.evaluate(() => {
        Object.defineProperty(navigator, 'share', {
            configurable: true,
            value: async () => { throw new DOMException('Unavailable', 'NotSupportedError'); },
        });
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: {writeText: async (value) => { window.__copiedShareUrl = value; }},
        });
    });
    await shareButton.click();
    await expect(page.locator('#copy-feedback')).toHaveText('当前设备不支持系统分享，短链接已复制。');
    expect(await page.evaluate(() => window.__copiedShareUrl)).toBe(expectedUrl);
});

test('capability mode hides advanced tools and persists the selection', async ({page}) => {
    await login(page);
    await expect(page.locator('.advanced-create')).toBeHidden();
    await expect(page.locator('.data-tools')).toBeHidden();
    await expect(page.getByRole('link', {name: 'Webhook'})).toBeHidden();

    await page.getByRole('button', {name: '高级'}).click();
    await expect(page.locator('.advanced-create')).toBeVisible();
    await expect(page.locator('.data-tools')).toBeVisible();
    await expect(page.getByRole('link', {name: 'Webhook'})).toBeVisible();
    await page.reload();
    await expect(page.getByRole('button', {name: '高级'})).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('.advanced-create')).toBeVisible();
});

test('presets can be saved, applied, and deleted end to end', async ({page}, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-chromium', 'Preset workflow runs once.');
    await login(page);
    await page.getByRole('button', {name: '高级'}).click();
    const presetName = `完整预设-${Date.now()}`;
    const creator = page.locator('.creator-panel');
    await openAdvancedCreate(creator);
    await creator.getByLabel('标签，可选').fill('preset, e2e');
    await creator.getByText('高级有效期', {exact: true}).click();
    await creator.getByLabel('最大点击次数').fill('7');
    await creator.locator('.preset-toolbar > details > summary').click();
    await creator.getByLabel('预设名称').fill(presetName);
    await creator.getByRole('button', {name: '保存预设'}).click();
    await expect(page.locator('.preset-list')).toContainText(presetName);
    await page.locator('[data-link-preset]').selectOption({label: presetName});
    await page.getByRole('button', {name: '应用'}).click();
    await expect(creator.getByLabel('标签，可选')).toHaveValue('preset, e2e');
    await expect(creator.getByLabel('最大点击次数')).toHaveValue('7');
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', {name: `删除预设 ${presetName}`}).click();
    await expect(page.getByRole('button', {name: `删除预设 ${presetName}`})).toHaveCount(0);
});

test('verified domain renders its configured brand', async ({page}, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-chromium', 'Brand rendering runs once.');
    await page.goto('/');
    const response = await requestWithAuthority(new URL('/', page.url()), 'brand.e2e.test');
    expect(response.status).toBe(200);
    await page.setContent(response.body, {waitUntil: 'networkidle'});
    await expect(page.getByRole('heading', {name: 'E2E Brand'})).toBeVisible();
    await expect(page.locator('.public-brand-tagline')).toHaveText('A real branded domain');
    await expect(page.locator('html')).toHaveAttribute('data-brand-color', '#006B4F');
    await expect.poll(() => page.locator('.public-brand-page h1').evaluate((element) => getComputedStyle(element).color)).toBe('rgb(0, 107, 79)');
});

test('dead lifecycle webhook can be replayed', async ({page}, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-chromium', 'Webhook replay runs once.');
    await login(page);
    await page.getByRole('button', {name: '高级'}).click();
    await page.goto('/?section=webhooks&webhook_status=dead');
    const row = page.locator('.webhook-table tbody tr').filter({hasText: 'E2E unhealthy target'});
    await expect(row).toContainText('死信');
    page.once('dialog', (dialog) => dialog.accept());
    await row.getByRole('button', {name: '重放'}).click();
    await expect(page.getByText('死信已重新加入投递队列。')).toBeVisible();
});

test('unhealthy target can be resolved from maintenance', async ({page}, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-chromium', 'Target repair runs once.');
    await login(page);
    await page.goto('/?section=maintenance&maintenance=target_health');
    const row = page.locator('.maintenance-table tbody tr').filter({hasText: 'e2e-target-health'});
    await expect(row).toContainText('anomaly');
    await row.locator('.repair-workflow > summary').click();
    const repair = row.locator('form').filter({has: page.getByRole('button', {name: '忽略'})});
    await repair.getByLabel('忽略原因').fill('E2E confirmed maintenance window');
    await repair.getByRole('button', {name: '忽略'}).click();
    await expect(page.getByText('异常处理已应用。')).toBeVisible();
    await expect(page.locator('.maintenance-table tbody tr').filter({hasText: 'e2e-target-health'})).toHaveCount(0);
});

test('operations workspaces are navigable and responsive', async ({page}) => {
    await login(page);
    for (const [section, heading, navigationLabel] of [
        ['analytics', '访问分析', '访问分析'],
        ['maintenance', '链接维护', '维护'],
        ['audit', '全局操作审计', '审计'],
        ['status', '系统状态中心', '系统状态'],
        ['security', '安全配置', '安全'],
        ['domains', '域名配置', '域名'],
        ['api', 'API 配置', 'API 配置'],
    ]) {
        await page.goto(`/?section=${section}`);
        await expect(page.getByRole('heading', {name: heading})).toBeVisible();
        await expect(page.getByRole('navigation', {name: '管理工作区'}).getByRole('link', {name: navigationLabel})).toHaveAttribute('aria-current', 'page');
        if (section === 'status') {
            const itemStates = await page.locator('.health-dot').allTextContents();
            expect(itemStates.length).toBeGreaterThan(0);
            expect(itemStates.every((state) => ['正常', '关注', '异常', '未配置'].includes(state.trim()))).toBe(true);
            await expect(page.locator('.status-title .live-status')).toHaveText(/^(系统正常|需要关注|存在异常|未配置)$/);
            await expect(page.getByRole('heading', {name: '运行手册'})).toBeVisible();
            await expect(page.getByRole('link', {name: '运行手册'}).first()).toBeVisible();
            await expect(page.getByRole('heading', {name: '发布版本中心'})).toBeVisible();
            await expect(page.locator('.release-center')).toContainText('2.4.0-e2e');
            await expect(page.locator('.release-center')).toContainText('2.3.1');
            await expect(page.locator('.synthetic-monitor-panel').getByRole('heading', {name: '合成监控'})).toBeVisible();
            const syntheticCard = page.locator('.status-item').filter({hasText: '合成监控'});
            await syntheticCard.getByRole('link', {name: '立即处理'}).click();
            await expect(page.locator('#status-runbook-synthetic_monitor')).toHaveAttribute('open', '');
        }
        await expectNoHorizontalOverflow(page);
        await expectNoSeriousAccessibilityViolations(page);
    }
});

test('analytics uses local dates and preserves filters across drill-down and export', async ({page}) => {
    await login(page);
    await page.goto('/?section=analytics');
    await expect(page).toHaveURL(/section=analytics.*timezone=/);

    const filters = page.locator('[data-analytics-filter]');
    await filters.getByLabel('日期范围').selectOption('custom');
    await expect(filters.getByLabel('开始日期')).toBeVisible();
    await filters.getByLabel('开始日期').fill('2026-08-01');
    await filters.getByLabel('结束日期').fill('2026-08-04');
    await filters.getByLabel('设备').selectOption('mobile');
    await filters.getByLabel('流量类型').selectOption('suspected_human');
    await filters.getByRole('button', {name: '应用筛选'}).click();

    await expect(page).toHaveURL(/range=custom/);
    await expect(page).toHaveURL(/start=2026-08-01/);
    await expect(page).toHaveURL(/end=2026-08-04/);
    await expect(page).toHaveURL(/device=mobile/);
    await expect(page).toHaveURL(/traffic=suspected_human/);
    await expect(page.getByRole('link', {name: /设备：手机.*移除此条件/})).toBeVisible();
    await expect(page.getByRole('link', {name: /流量类型：疑似人工.*移除此条件/})).toBeVisible();
    const viewName = `移动分析-${test.info().project.name}`;
    await page.locator('.analytics-saved-views').getByPlaceholder('视图名称').fill(viewName);
    await page.locator('.analytics-saved-views').getByRole('button', {name: '保存视图'}).click();
    await expect(page.getByRole('link', {name: viewName})).toBeVisible();
    await page.getByRole('link', {name: /流量类型：疑似人工.*移除此条件/}).click();
    expect(new URL(page.url()).searchParams.has('traffic')).toBe(false);
    expect(new URL(page.url()).searchParams.get('device')).toBe('mobile');
    await page.locator('.analytics-export-menu').click();
    const exportForm = page.locator('[data-analytics-export-form]');
    await expect(exportForm.getByRole('button', {name: '当前筛选结果'})).toBeVisible();
    await expect(exportForm.locator('input[name="device"]')).toHaveValue('mobile');

    await page.locator('.analytics-trend-row').first().click();
    const drilledUrl = new URL(page.url());
    expect(drilledUrl.searchParams.get('range')).toBe('custom');
    expect(drilledUrl.searchParams.get('start')).toBe(drilledUrl.searchParams.get('end'));
    expect(drilledUrl.searchParams.get('device')).toBe('mobile');
    await expectNoHorizontalOverflow(page);
    await expectNoSeriousAccessibilityViolations(page);
});

test('managed API tokens can be saved offline, rotated, and revoked', async ({page, context}, testInfo) => {
    await context.addInitScript(() => { window.print = () => {}; });
    await login(page);
    await page.goto('/?section=api');
    const tokenName = `Playwright ${testInfo.project.name}`;
    const manager = page.locator('.token-manager-panel');
    await manager.getByLabel('名称').fill(tokenName);
    await manager.getByRole('button', {name: '生成 Token'}).click();

    const generated = page.locator('#created-api-token');
    await expect(generated).toBeVisible();
    const firstToken = await generated.inputValue();
    expect(firstToken).toMatch(/^slt_[A-Za-z0-9_-]{43}$/);
    await expect(page.getByRole('button', {name: '下载'})).toBeVisible();
    await expect(page.getByRole('button', {name: '打印'})).toBeVisible();
    page.once('dialog', (dialog) => dialog.dismiss());
    await page.getByRole('navigation', {name: '管理工作区'}).getByRole('link', {name: '链接'}).click();
    await expect(page).toHaveURL(/section=api/);

    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('button', {name: '下载'}).click();
    const tokenDownload = await downloadPromise;
    expect(await readFile(await tokenDownload.path(), 'utf8')).toBe(`${firstToken}\n`);

    const popupPromise = page.waitForEvent('popup');
    await page.getByRole('button', {name: '打印'}).click();
    const printPage = await popupPromise;
    await expect(printPage.locator('pre')).toHaveText(firstToken);
    await printPage.close();
    await page.getByRole('checkbox', {name: /已离线保存/}).check();
    let currentRow = page.locator('.token-table tbody tr').filter({hasText: tokenName}).first();
    await expect(currentRow).toContainText('可用');
    await expectNoHorizontalOverflow(page);
    await expectNoSeriousAccessibilityViolations(page);

    page.once('dialog', (dialog) => dialog.accept());
    await currentRow.getByRole('button', {name: '轮换'}).click();
    await expect(generated).toBeVisible();
    await expect(generated).not.toHaveValue(firstToken);
    await page.getByRole('checkbox', {name: /已离线保存/}).check();
    currentRow = page.locator('.token-table tbody tr').filter({hasText: tokenName}).first();
    await expect(currentRow).toContainText('可用');

    page.once('dialog', (dialog) => dialog.accept());
    await currentRow.getByRole('button', {name: '吊销'}).click();
    currentRow = page.locator('.token-table tbody tr').filter({hasText: tokenName}).first();
    await expect(currentRow).toContainText('已吊销');
    await expectNoHorizontalOverflow(page);
    await expectNoSeriousAccessibilityViolations(page);
});

test('dashboard fits small phone and landscape viewports', async ({page}, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile-chromium', 'Responsive breakpoint check runs once.');
    await page.setViewportSize({width: 375, height: 812});
    await login(page);
    await expectNoHorizontalOverflow(page);
    await page.goto('/?section=webhooks');
    const webhookRegion = page.locator('.webhook-center .table-scroll');
    await expect(webhookRegion).toBeVisible();
    expect(await webhookRegion.evaluate((region) => region.scrollWidth > region.clientWidth)).toBe(true);
    await expectNoHorizontalOverflow(page);
    await page.setViewportSize({width: 812, height: 375});
    await page.goto('/');
    await expectNoHorizontalOverflow(page);
    await expect(page.getByRole('heading', {name: '创建短链接'})).toBeVisible();
});

test('tablet tables keep readable columns inside local scrolling regions', async ({page}, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-chromium', 'Tablet breakpoint check runs once.');
    await page.setViewportSize({width: 800, height: 900});
    await login(page);
    const creator = page.locator('.creator-panel');
    await openAdvancedCreate(creator);
    await creator.getByLabel('原始链接').fill('https://example.com/tablet-readable');
    await creator.getByLabel('自定义短码，可选').fill('tablet-readable');
    await creator.getByRole('button', {name: '生成短链接'}).click();
    const linkRegion = page.locator('.link-table-scroll');
    await expect(linkRegion).toBeVisible();
    const linkDimensions = await linkRegion.evaluate((region) => ({
        clientWidth: region.clientWidth,
        scrollWidth: region.scrollWidth,
        targetWidth: region.querySelector('tbody td:nth-child(3)')?.getBoundingClientRect().width || 0,
    }));
    expect(linkDimensions.scrollWidth).toBeGreaterThan(linkDimensions.clientWidth);
    expect(linkDimensions.targetWidth).toBeGreaterThan(240);
    await expectNoHorizontalOverflow(page);

    await page.goto('/?section=audit');
    const auditRegion = page.locator('.audit-table-scroll');
    await expect(auditRegion).toBeVisible();
    expect(await auditRegion.evaluate((region) => region.scrollWidth > region.clientWidth)).toBe(true);
    await expectNoHorizontalOverflow(page);
});

test('non-sensitive create draft returns after reauthentication', async ({page, context}) => {
    await login(page);
    const creator = page.locator('.creator-panel');
    await openAdvancedCreate(creator);
    await creator.getByLabel('原始链接').fill('https://example.com/restored-draft');
    await creator.getByLabel('标题，可选').fill('恢复后的草稿');
    await creator.getByLabel('标签，可选').fill('draft, local');
    await creator.getByText('访问保护与失效处理', {exact: true}).click();
    await creator.getByLabel('访问密码，可选').fill('DoNotPersist!234');
    await context.clearCookies();
    await creator.getByRole('button', {name: '生成短链接'}).click();
    await expect(page.getByRole('button', {name: '安全登录'})).toBeVisible();
    expect(await page.evaluate(() => Object.values(sessionStorage).join('\n'))).not.toContain('DoNotPersist!234');
    await page.getByLabel('管理密码').fill('BrowserTest!234');
    await page.getByRole('button', {name: '安全登录'}).click();
    await expect(page.getByRole('heading', {name: '创建短链接'})).toBeVisible();
    await expect(page.getByLabel('原始链接')).toHaveValue('https://example.com/restored-draft');
    await expect(page.getByLabel('标题，可选')).toHaveValue('恢复后的草稿');
    await expect(page.getByLabel('标签，可选')).toHaveValue('draft, local');
    await expect(page.getByLabel('访问密码，可选')).toHaveValue('');
    await expect(page.getByLabel('访问密码，可选')).toHaveAttribute('required', '');
    await expect(page.getByText('草稿已恢复，请重新输入此密码。')).toBeVisible();
});

test('import selection shows file details and labels preview issues', async ({page}) => {
    await login(page);
    await openDataTools(page);
    const importForm = page.locator('[data-import-form]');
    const preview = importForm.getByRole('button', {name: '预览导入'});
    await expect(preview).toBeDisabled();

    const payload = JSON.stringify({
        kind: 'link_export',
        version: 1,
        links: [{slug: '!invalid', target_url: 'not-a-url'}],
    });
    await importForm.locator('[data-import-file]').setInputFiles({
        name: 'invalid-links.json',
        mimeType: 'application/json',
        buffer: Buffer.from(payload),
    });
    await expect(importForm.locator('[data-file-summary]')).toContainText('invalid-links.json');
    await expect(importForm.locator('[data-file-summary]')).toContainText(' B');
    await expect(importForm.getByRole('button', {name: '清除已选择的导入文件'})).toBeVisible();
    await expect(preview).toBeEnabled();

    await page.route('**/import', async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 300));
        await route.continue();
    }, {times: 1});
    await preview.click();
    const uploadProgress = importForm.locator('[data-import-progress]');
    await expect(uploadProgress).toBeVisible();
    await expect(uploadProgress.locator('[data-import-progress-label]')).toHaveText(/正在上传|上传完成|正在分析/);
    const issueCells = page.locator('.preview-errors td');
    await expect(issueCells.nth(0)).toHaveAttribute('data-label', '行');
    await expect(issueCells.nth(1)).toHaveAttribute('data-label', '短码');
    await expect(issueCells.nth(2)).toHaveAttribute('data-label', '结果');
    const toast = page.locator('.flash-toast');
    await expect(toast).toContainText('Dry Run 已完成');
    await expect(toast).toHaveCSS('position', 'fixed');
    await toast.getByRole('button', {name: '关闭提示'}).click();
    await expect(toast).toBeHidden();
});

test('clearing an import selection disables preview again', async ({page}) => {
    await login(page);
    await openDataTools(page);
    const importForm = page.locator('[data-import-form]');
    await importForm.locator('[data-import-file]').setInputFiles({
        name: 'links.json',
        mimeType: 'application/json',
        buffer: Buffer.from('{"kind":"link_export","version":1,"links":[]}'),
    });
    await importForm.getByRole('button', {name: '清除已选择的导入文件'}).click();
    await expect(importForm.locator('[data-file-summary]')).toHaveText('未选择文件');
    await expect(importForm.getByRole('button', {name: '预览导入'})).toBeDisabled();
});

test('general import errors receive focus', async ({page}) => {
    await login(page);
    await openDataTools(page);
    const importForm = page.locator('[data-import-form]');
    await importForm.locator('[data-import-file]').setInputFiles({
        name: 'broken.json',
        mimeType: 'application/json',
        buffer: Buffer.from('{'),
    });
    await importForm.getByRole('button', {name: '预览导入'}).click();
    const error = page.locator('.flash.error');
    await expect(error).toContainText('导入失败');
    await expect(error).toBeFocused();
});

test('create form submits one short link', async ({page}, testInfo) => {
    await login(page);
    await openAdvancedCreate(page.locator('.creator-panel'));
    const suffix = testInfo.project.name.replace(/[^a-z0-9]/gi, '').toLowerCase();
    await page.getByLabel('原始链接').fill(`https://example.com/playwright/${suffix}`);
    await page.getByLabel('自定义短码，可选').fill(`pw${suffix}`.slice(0, 32));
    const submit = page.getByRole('button', {name: '生成短链接'});
    await submit.click();
    await expect(page.getByText('短链接已生成。')).toBeVisible();
    await expect(page.locator('table')).toContainText(`pw${suffix}`.slice(0, 32));
});

test('confirmed one-time link is consumed only after confirmation', async ({page}, testInfo) => {
    await login(page);
    const suffix = testInfo.project.name.startsWith('mobile') ? 'mobile' : 'desktop';
    const slug = `confirm-${suffix}`;
    const creator = page.locator('.creator-panel');
    await openAdvancedCreate(creator);
    const fullTarget = `https://example.com/confirmed-${suffix}/source?campaign=e2e#receipt`;
    await creator.getByLabel('原始链接').fill(fullTarget);
    await creator.getByLabel('自定义短码，可选').fill(slug);
    await creator.getByText('高级有效期', {exact: true}).click();
    await creator.getByRole('checkbox', {name: '一次性链接'}).check();
    await creator.getByLabel('一次性消费方式').selectOption('confirm');
    await creator.getByRole('button', {name: '生成短链接'}).click();

    await page.goto(`/${slug}`);
    await expect(page.getByRole('heading', {name: '确认访问'})).toBeVisible();
    await expect(page.locator('.confirmation-target-full code')).toHaveText(fullTarget);
    await page.getByRole('button', {name: '取消访问'}).click();
    await expect(page).toHaveURL('/');
    await page.goto(`/${slug}`);
    await page.reload();
    await expect(page.getByRole('button', {name: '确认并继续'})).toBeVisible();

    await page.route(`**/confirmed-${suffix}/source?campaign=e2e`, (route) => {
        return route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: '<h1>confirmed target</h1>',
        });
    });
    const confirmationResponse = page.waitForResponse((response) => response.url().endsWith(`/${slug}/confirm`));
    const targetResponse = page.waitForResponse((response) => response.url() === `https://example.com/confirmed-${suffix}/source?campaign=e2e`);
    await page.getByRole('button', {name: '确认并继续'}).click();
    const response = await confirmationResponse;
    expect(response.status()).toBe(303);
    const target = await targetResponse;
    expect(target.request().isNavigationRequest()).toBe(true);
    await expect(page).toHaveURL(fullTarget);

    await page.goto(`/${slug}`);
    await expect(page.getByRole('heading', {name: '短链接不存在'})).toBeVisible();
});

test('saved filters and scoped exports preserve the current selection', async ({page}, testInfo) => {
    await login(page);
    const suffix = testInfo.project.name.startsWith('mobile') ? 'mobile' : 'desktop';
    const slug = `scope-${suffix}`;
    const creator = page.locator('.creator-panel');
    await openAdvancedCreate(creator);
    await creator.getByLabel('原始链接').fill(`https://example.com/scoped-${suffix}`);
    await creator.getByLabel('自定义短码，可选').fill(slug);
    await creator.getByRole('button', {name: '生成短链接'}).click();

    const filters = page.locator('.filter-form');
    await filters.getByPlaceholder('搜索标题、短码、标签或目标域名').fill(slug);
    await filters.getByRole('button', {name: '筛选'}).click();
    await openDataTools(page);
    const currentDownloadPromise = page.waitForEvent('download');
    await page.getByRole('link', {name: '导出当前筛选'}).click();
    const currentDownload = await currentDownloadPromise;
    const currentPayload = JSON.parse(await readFile(await currentDownload.path(), 'utf8'));
    expect(currentPayload.scope).toBe('current');
    expect(currentPayload.links.map((link) => link.slug)).toEqual([slug]);

    const filterName = `筛选-${suffix}`;
    await page.locator('.save-filter-form').getByPlaceholder('筛选名称').fill(filterName);
    await page.locator('.save-filter-form').getByRole('button', {name: '保存筛选'}).click();
    await expect(page.getByRole('link', {name: filterName})).toBeVisible();

    const renamedFilter = `${filterName}-重命名`;
    await page.getByRole('button', {name: `重命名常用筛选 ${filterName}`}).click();
    const renameDialog = page.getByRole('dialog', {name: '重命名常用筛选'});
    await expect(renameDialog.getByLabel('筛选名称')).toBeFocused();
    await renameDialog.getByLabel('筛选名称').fill(renamedFilter);
    await renameDialog.getByRole('button', {name: '保存名称'}).click();
    await expect(page.getByRole('link', {name: renamedFilter})).toBeVisible();
    await expect(page.getByRole('link', {name: filterName, exact: true})).toHaveCount(0);

    const row = page.locator('tbody tr').filter({hasText: slug});
    await row.getByRole('checkbox', {name: `选择 ${slug}`}).check();
    const selectedDownloadPromise = page.waitForEvent('download');
    const selectedExport = page.getByRole('button', {name: '导出所选链接（1）'});
    await selectedExport.click();
    const selectedDownload = await selectedDownloadPromise;
    const selectedPayload = JSON.parse(await readFile(await selectedDownload.path(), 'utf8'));
    expect(selectedPayload.scope).toBe('selected');
    expect(selectedPayload.links.map((link) => link.slug)).toEqual([slug]);
    await expect(selectedExport).toBeEnabled();
    await expect(selectedExport).toHaveText('导出所选链接（1）');
});

test('server validation focuses and describes the first invalid field', async ({page}) => {
    await login(page);
    const creator = page.locator('.creator-panel');
    await openAdvancedCreate(creator);
    await creator.getByLabel('原始链接').fill('not-a-url');
    await creator.locator('form').evaluate((form) => {
        form.noValidate = true;
        form.requestSubmit();
    });
    const invalidUrl = creator.getByLabel('原始链接');
    await expect(invalidUrl).toBeFocused();
    await expect(invalidUrl).toHaveAttribute('aria-invalid', 'true');
    await expect(invalidUrl).toHaveAttribute('aria-describedby', 'create-target-url-error');
    await expect(page.locator('#create-target-url-error')).toBeVisible();
    await expect(page.getByRole('alert')).toContainText('请修正标出的字段');
    await expectNoSeriousAccessibilityViolations(page);
});

test('bulk actions track selection and allow a soft-delete undo', async ({page}, testInfo) => {
    await login(page);
    const suffix = testInfo.project.name.replace(/[^a-z0-9]/gi, '').toLowerCase();
    const slug = `bulkui${suffix}`.slice(0, 32);
    const creator = page.locator('.creator-panel');
    await openAdvancedCreate(creator);
    await creator.getByLabel('原始链接').fill(`https://example.com/bulk-ui/${suffix}`);
    await creator.getByLabel('自定义短码，可选').fill(slug);
    await creator.getByRole('button', {name: '生成短链接'}).click();

    const row = page.locator('tbody tr').filter({hasText: slug});
    const apply = page.getByRole('button', {name: '预览影响'});
    await expect(apply).toBeDisabled();
    await row.getByRole('checkbox', {name: `选择 ${slug}`}).check();
    await expect(page.getByText('已选择 1 条')).toBeVisible();
    await expect(apply).toBeEnabled();
    await page.getByLabel('批量操作', {exact: true}).selectOption('delete');
    await apply.click();
    const preview = page.getByRole('dialog', {name: '批量操作影响预览'});
    await expect(preview).toBeVisible();
    await expect(preview.locator('[data-bulk-preview-change]')).toHaveText('1');
    await expect(preview).toContainText(slug);
    await preview.getByRole('button', {name: '确认应用'}).click();
    await expect(page.getByText('已删除 1 条，可撤销。')).toBeVisible();
    await page.getByRole('button', {name: '撤销批量操作'}).click();
    await expect(page.locator('tbody')).toContainText(slug);
});

test('row icon actions identify their link and mobile uses an overflow menu', async ({page}, testInfo) => {
    await login(page);
    const suffix = testInfo.project.name.replace(/[^a-z0-9]/gi, '').toLowerCase();
    const slug = `actions${suffix}`.slice(0, 32);
    const creator = page.locator('.creator-panel');
    await openAdvancedCreate(creator);
    await creator.getByLabel('原始链接').fill(`https://example.com/actions/${suffix}`);
    await creator.getByLabel('标题，可选').fill('操作测试');
    await creator.getByLabel('自定义短码，可选').fill(slug);
    await creator.getByRole('button', {name: '生成短链接'}).click();

    const row = page.locator('tbody tr').filter({hasText: slug});
    await expect(row.getByRole('button', {name: `复制 ${slug}`, exact: true})).toBeVisible();
    await expect(row.getByRole('button', {name: new RegExp(`^复制原始地址 https://example\\.com/actions/${suffix}`)})).toBeVisible();
    await expect(row.getByRole('link', {name: `查看详情 ${slug}`, exact: true})).toBeVisible();
    await expect(row.locator(`.desktop-secondary-action[aria-label="编辑 ${slug}"]`)).toHaveCount(1);
    await expect(row.locator(`.mobile-action-menu [aria-label="编辑 ${slug}"]`)).toHaveCount(1);
    if (testInfo.project.name === 'mobile-chromium') {
        await expect(row.locator('.desktop-secondary-action').first()).toBeHidden();
        const menuTrigger = row.locator('.mobile-action-menu > summary');
        await expect(menuTrigger).toHaveAttribute('aria-label', `更多操作 ${slug}`);
        await menuTrigger.click();
        await expect(row.locator('.mobile-action-menu-items').getByRole('link', {name: `编辑 ${slug}`})).toBeVisible();
        await page.getByRole('heading', {name: '链接列表'}).click();
        await expect(row.locator('.mobile-action-menu')).not.toHaveAttribute('open', '');
    } else {
        await expect(row.locator('.mobile-action-menu')).toBeHidden();
    }
});

test('slash focuses link search and target URLs can be copied', async ({page}, testInfo) => {
    await login(page);
    const suffix = testInfo.project.name.replace(/[^a-z0-9]/gi, '').toLowerCase();
    const slug = `quick${suffix}`.slice(0, 32);
    const targetUrl = `https://example.com/quick-actions/${suffix}?source=e2e#result`;
    const creator = page.locator('.creator-panel');
    await openAdvancedCreate(creator);
    await creator.getByLabel('原始链接').fill(targetUrl);
    await creator.getByLabel('自定义短码，可选').fill(slug);
    await creator.getByRole('button', {name: '生成短链接'}).click();

    await page.getByRole('heading', {name: '链接列表'}).click();
    await page.keyboard.press('/');
    const search = page.getByPlaceholder('搜索标题、短码、标签或目标域名');
    await expect(search).toBeFocused();
    await search.fill('kept/value');
    await page.keyboard.press('/');
    await expect(search).toHaveValue('kept/value/');

    await page.evaluate(() => {
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: {writeText: async (value) => { window.__copiedTargetUrl = value; }},
        });
    });
    const row = page.locator('tbody tr').filter({hasText: slug});
    await row.getByRole('button', {name: new RegExp(`^复制原始地址 ${targetUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`)}).click();
    expect(await page.evaluate(() => window.__copiedTargetUrl)).toBe(targetUrl);
    await expect(page.locator('#copy-feedback')).toHaveText('原始地址已复制。');
});

test('permanent-delete confirmation names the link and defaults to cancel', async ({page}, testInfo) => {
    await login(page);
    const suffix = testInfo.project.name.replace(/[^a-z0-9]/gi, '').toLowerCase();
    const slug = `purgeui${suffix}`.slice(0, 32);
    const creator = page.locator('.creator-panel');
    await openAdvancedCreate(creator);
    await creator.getByLabel('原始链接').fill(`https://example.com/purge-ui/${suffix}`);
    await creator.getByLabel('标题，可选').fill('待永久删除');
    await creator.getByLabel('自定义短码，可选').fill(slug);
    await creator.getByRole('button', {name: '生成短链接'}).click();
    const row = page.locator('tbody tr').filter({hasText: slug});
    await row.getByRole('checkbox', {name: `选择 ${slug}`}).check();
    await page.getByLabel('批量操作', {exact: true}).selectOption('delete');
    await page.getByRole('button', {name: '预览影响'}).click();
    await page.getByRole('dialog', {name: '批量操作影响预览'}).getByRole('button', {name: '确认应用'}).click();
    await page.goto(`/?view=trash&q=${encodeURIComponent(slug)}`);

    const trashRow = page.locator('tbody tr').filter({hasText: slug});
    await expect(trashRow.getByRole('button', {name: `复制 ${slug}`})).toBeVisible();
    await expect(trashRow.getByRole('link', {name: `查看详情 ${slug}`})).toBeVisible();
    if (testInfo.project.name === 'mobile-chromium') {
        await trashRow.locator('.mobile-action-menu > summary').click();
        await expect(trashRow.locator('.mobile-action-menu-items').getByRole('button', {name: `恢复 ${slug}`})).toBeVisible();
        await trashRow.locator('.mobile-action-menu-items').getByRole('button', {name: `永久删除 ${slug}`}).click();
    } else {
        await trashRow.getByRole('button', {name: `永久删除 ${slug}`}).click();
    }
    const confirmation = page.getByRole('dialog', {name: '永久删除链接？'});
    await expect(confirmation).toBeVisible();
    await expect(confirmation.locator('[data-purge-count]')).toHaveText('1 条链接');
    await expect(confirmation.locator('code')).toHaveText(slug);
    await expect(confirmation).toContainText('待永久删除');
    await expect(confirmation.getByRole('button', {name: '取消'})).toBeFocused();
    await confirmation.getByRole('button', {name: '取消'}).click();
    await expect(confirmation).toBeHidden();
    await expect(trashRow).toBeVisible();
});

test('submit loading state recovers after a failed navigation', async ({page}) => {
    await login(page);
    const creator = page.locator('.creator-panel');
    await openAdvancedCreate(creator);
    await creator.getByLabel('原始链接').fill('https://example.com/failed-navigation');
    await creator.getByLabel('自定义短码，可选').fill('failednav');
    await creator.locator('form').evaluate((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            setTimeout(() => {
                window.dispatchEvent(new Event('pageshow'));
            }, 0);
        }, {once: true});
    });
    const submit = creator.locator('form button[type="submit"]');
    await submit.click();
    await expect(submit).toBeEnabled();
    await expect(submit).toHaveText('生成短链接');
    await expect(submit).not.toHaveAttribute('aria-busy', 'true');
});

test('managed link opens a responsive detail view', async ({page}, testInfo) => {
    await login(page);
    const suffix = testInfo.project.name.replace(/[^a-z0-9]/gi, '').toLowerCase();
    const slug = `detail${suffix}`.slice(0, 32);
    const creator = page.locator('.creator-panel');
    await openAdvancedCreate(creator);
    await creator.getByLabel('原始链接').fill(`https://example.com/detail/${suffix}`);
    await creator.getByLabel('自定义短码，可选').fill(slug);
    await creator.getByLabel('标签，可选').fill('mobile, docs');
    await creator.getByText('高级有效期', {exact: true}).click();
    await creator.getByLabel('最大点击次数').fill('5');
    await creator.getByLabel('创建后收藏').check();
    await creator.getByRole('button', {name: '生成短链接'}).click();
    await page.goto(`/?q=${encodeURIComponent(slug)}`);
    await page.evaluate(() => {
        window.scrollTo(0, document.documentElement.scrollHeight);
    });
    const row = page.locator('tbody tr').filter({hasText: slug});
    await row.getByRole('link', {name: '查看详情'}).click();
    const detailUrl = new URL(page.url());
    expect(detailUrl.searchParams.get('return_q')).toBe(slug);
    const savedScroll = Number(detailUrl.searchParams.get('return_scroll'));
    expect(savedScroll).toBeGreaterThan(0);
    await expect(page.getByRole('heading', {name: slug})).toBeVisible();
    await expect(page.locator('.qr-code svg')).toBeVisible();
    await expect(page.getByRole('img', {name: new RegExp(`${slug}.*二维码`)})).toBeVisible();
    await expect(page.getByRole('link', {name: '下载二维码'})).toHaveAttribute('href', /^blob:/);
    await expect(page.getByRole('link', {name: '下载二维码'})).toHaveAttribute('download', `${slug}-qr.svg`);
    await expect(page.getByRole('navigation', {name: '趋势周期'})).toContainText('30 天');
    await expect(page.getByRole('link', {name: '14 天'})).toHaveAttribute('aria-current', 'page');
    await expect(page.getByRole('link', {name: '编辑'})).toHaveAttribute('href', new RegExp(`/edit\\?id=\\d+.*return_to_detail=1.*return_q=${slug}`));
    await page.getByRole('link', {name: '编辑'}).click();
    await expect(page.locator('body')).toHaveClass(/edit-page/);
    await expect(page.getByRole('heading', {name: `编辑短链接：${slug}`})).toBeVisible();
    await page.getByRole('link', {name: '取消'}).click();
    await expect(page.getByRole('heading', {name: slug})).toBeVisible();
    await expect(page.getByRole('link', {name: '跳到主要内容'})).toHaveAttribute('href', '#main-content');
    await expectNoHorizontalOverflow(page);
    if (testInfo.project.name === 'mobile-chromium') {
        const undersizedControls = await page.locator('button, a.button, .tabs a, .range-tabs a').evaluateAll((controls) => controls
            .filter((control) => {
                const box = control.getBoundingClientRect();
                return box.width < 44 || box.height < 44;
            })
            .map((control) => control.getAttribute('aria-label') || control.textContent.trim()));
        expect(undersizedControls).toEqual([]);
    }
    await expectNoSeriousAccessibilityViolations(page);
    await page.getByRole('link', {name: '返回链接列表'}).click();
    await expect(page).toHaveURL(new RegExp(`\\?q=${slug}$`));
    if (savedScroll > 0) {
        await expect.poll(() => page.evaluate((scroll) => {
            const expected = Math.min(scroll, document.documentElement.scrollHeight - window.innerHeight);
            return window.scrollY >= Math.max(0, expected - 2);
        }, savedScroll)).toBe(true);
    }
});

test('QR generation failure has an accessible state and no invalid download', async ({page}, testInfo) => {
    await login(page);
    const suffix = testInfo.project.name.replace(/[^a-z0-9]/gi, '').toLowerCase();
    const slug = `qrfail${suffix}`.slice(0, 32);
    const creator = page.locator('.creator-panel');
    await openAdvancedCreate(creator);
    await creator.getByLabel('原始链接').fill(`https://example.com/qr-failure/${suffix}`);
    await creator.getByLabel('自定义短码，可选').fill(slug);
    await creator.getByRole('button', {name: '生成短链接'}).click();
    await page.route(/\/assets\/qrcode\.min(?:\.[0-9a-f]{12})?\.js$/, (route) => route.abort());
    await page.locator('tbody tr').filter({hasText: slug}).getByRole('link', {name: '查看详情'}).click();
    await expect(page.getByRole('alert')).toHaveText('二维码生成失败，请稍后重试。');
    await expect(page.locator('[data-qr-download]')).toBeHidden();
    await expect(page.locator('[data-qr-download]')).not.toHaveAttribute('href', /.+/);
});

test('error page offers recovery actions', async ({page}) => {
    const response = await page.goto('/router.php');
    expect(response.status()).toBe(404);
    const recovery = page.getByRole('navigation', {name: '错误恢复操作'});
    await expect(recovery.getByRole('link', {name: '重试'})).toHaveAttribute('href', '');
    await expect(recovery.getByRole('link', {name: '返回首页'})).toHaveAttribute('href', '/');
    await expect(recovery.getByRole('link', {name: '管理员登录'})).toHaveAttribute('href', '/login');
    await expectNoSeriousAccessibilityViolations(page);
});
