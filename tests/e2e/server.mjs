import {spawn, spawnSync} from 'node:child_process';
import {mkdtempSync, rmSync, statSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join, resolve} from 'node:path';

const root = resolve(import.meta.dirname, '..', '..');
const temporaryDirectory = mkdtempSync(join(tmpdir(), 'linkvault-e2e-'));
const php = process.env.PHP_BINARY || 'php';
const port = Number(process.env.LINKVAULT_E2E_PORT || 18080);
const baseUrl = `http://127.0.0.1:${port}`;
const analyticsLogPath = join(temporaryDirectory, 'analytics-access.log');
const analyticsStatePath = join(temporaryDirectory, 'analytics-state.json');
const environment = {
    ...process.env,
    LINKVAULT_ADMIN_PASSWORD: 'BrowserTest!234',
    LINKVAULT_BASE_URL: baseUrl,
    LINKVAULT_TRUSTED_PROXIES: '127.0.0.1',
    LINKVAULT_DATABASE_PATH: join(temporaryDirectory, 'linkvault.sqlite'),
    LINKVAULT_LOG_PATH: join(temporaryDirectory, 'application.log'),
    LINKVAULT_BACKUP_DIR: join(temporaryDirectory, 'backups'),
    LINKVAULT_RELEASE_VERSION: '2.4.0-e2e',
    LINKVAULT_BUILD_TIME: '2026-08-06T08:00:00Z',
    LINKVAULT_RELEASE_CHANGELOG: 'Added release center|Added synthetic probe detail',
    LINKVAULT_RELEASE_ROLLBACK_VERSION: '2.3.1',
    LINKVAULT_SYNTHETIC_STATUS_PATH: join(temporaryDirectory, 'synthetic-monitor-state.json'),
    LINKVAULT_ANALYTICS_LOG_PATH: analyticsLogPath,
    LINKVAULT_ANALYTICS_STATE_PATH: analyticsStatePath,
    LINKVAULT_TARGET_HEALTH_STATUS_PATH: join(temporaryDirectory, 'target-health-state.json'),
    LINKVAULT_E2E_FIXTURE_PATH: join(temporaryDirectory, 'fixtures.json'),
};

const migration = spawnSync(php, [join(root, 'bin', 'migrate.php')], {
    cwd: root,
    env: environment,
    stdio: 'inherit',
});
if (migration.status !== 0) {
    rmSync(temporaryDirectory, {recursive: true, force: true});
    process.exit(migration.status ?? 1);
}

const fixtures = spawnSync(php, [join(root, 'tests', 'e2e', 'seed.php')], {
    cwd: root,
    env: environment,
    stdio: 'inherit',
});
if (fixtures.status !== 0) {
    rmSync(temporaryDirectory, {recursive: true, force: true});
    process.exit(fixtures.status ?? 1);
}

writeFileSync(analyticsLogPath, '');
const analyticsLog = statSync(analyticsLogPath);
const now = Math.floor(Date.now() / 1000);
writeFileSync(analyticsStatePath, JSON.stringify({
    version: 1,
    inode: String(analyticsLog.ino),
    offset: 0,
    observed_size: 0,
    active_backlog_bytes: 0,
    backlog_bytes: 0,
    completed_at: now,
    log_exists: true,
    complete: true,
    read: 0,
    accepted: 0,
    skipped: 0,
    last_attempt_at: now,
    last_success_at: now,
    failure_count: 0,
    consecutive_failures: 0,
    last_failure_at: 0,
    last_error: '',
    latest_event_at: 0,
}) + '\n');

const server = spawn(php, [
    '-S', `127.0.0.1:${port}`,
    '-t', join(root, 'public'),
    join(root, 'public', 'router.php'),
], {cwd: root, env: environment, stdio: 'inherit'});

const stop = (signal) => {
    if (!server.killed) server.kill(signal);
    rmSync(temporaryDirectory, {recursive: true, force: true});
};
process.on('SIGINT', () => stop('SIGINT'));
process.on('SIGTERM', () => stop('SIGTERM'));
server.on('exit', (code) => {
    rmSync(temporaryDirectory, {recursive: true, force: true});
    process.exit(code ?? 0);
});
