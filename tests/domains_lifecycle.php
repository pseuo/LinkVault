<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
require $root . '/app/LinkService.php';
require $root . '/app/ShortDomainService.php';
require_once $root . '/lib/database_schema.php';

final class LifecycleTestResolver implements TargetHealthResolver
{
    public function resolve(string $host): array
    {
        return ['93.184.216.34'];
    }
}

final class LifecycleTestTransport implements WebhookTransport
{
    public array $requests = [];

    public function post(
        string $url,
        string $host,
        int $port,
        string $pinnedIp,
        string $payload,
        array $headers
    ): array {
        $this->requests[] = compact('url', 'host', 'port', 'pinnedIp', 'payload', 'headers');
        return ['ok' => true, 'status' => 204, 'primary_ip' => $pinnedIp, 'effective_url' => $url];
    }
}

function policy_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$appearanceRoute = fixed_routes()['/domains/update-appearance'] ?? null;
policy_assert(
    is_array($appearanceRoute)
        && $appearanceRoute['methods'] === ['POST']
        && $appearanceRoute['scope'] === 'admin',
    'The domain appearance endpoint is missing from the fixed route table.'
);

$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'linkvault-domains-' . bin2hex(random_bytes(8)) . '.sqlite';
$pdo = new PDO('sqlite:' . $databasePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (glob($root . '/migrations/*.sql') ?: [] as $migrationPath) {
    $version = (int)basename($migrationPath, '.sql');
    $pdo->exec(linkvault_verified_migration_sql($migrationPath, $version));
    $pdo->exec('PRAGMA user_version = ' . $version);
}
policy_assert(linkvault_schema_problems($pdo) === [], 'Migrated domain test schema failed integrity checks.');

$config = [
    'base_url' => 'https://s.example.test',
    'lifecycle_webhook_url' => 'https://hooks.example.test/events',
    'lifecycle_webhook_signing_secret' => str_repeat('s', 32),
    'lifecycle_webhook_bearer_token' => '',
];
$domains = new ShortDomainService($pdo, $config);
foreach (['#FFFFFF', '#F7F7F5', '#FFFF00'] as $lowContrastColor) {
    $rejected = false;
    try {
        $domains->create('low-contrast-' . substr($lowContrastColor, 1) . '.example.test', 'Unreadable', '', 'graphite', $lowContrastColor);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    policy_assert($rejected, 'Low-contrast brand color was accepted: ' . $lowContrastColor);
}
$domainId = $domains->create(
    'GO.Example.Test.',
    'Docs',
    'Verified links',
    'emerald',
    '#126b34',
    'https://cdn.example.test/logo.png',
    'https://cdn.example.test/favicon.ico',
    'Link unavailable',
    'This branded link is no longer available.'
);
$domain = $domains->find($domainId);
policy_assert(is_array($domain) && $domain['hostname'] === 'go.example.test', 'Domain normalization failed.');
policy_assert(
    $domain['brand_color'] === '#126B34'
        && $domain['logo_url'] === 'https://cdn.example.test/logo.png'
        && $domain['invalid_page_title'] === 'Link unavailable',
    'Advanced branding was not stored during domain creation.'
);
policy_assert($domains->updateBrand(
    $domainId,
    'Docs updated',
    'Updated links',
    'crimson',
    '#345678',
    'https://cdn.example.test/new-logo.png',
    '',
    'Expired link',
    'This link has expired.'
), 'Advanced branding update failed.');
$domain = $domains->find($domainId);
policy_assert(
    is_array($domain) && $domain['brand_color'] === '#345678'
        && $domain['logo_url'] === 'https://cdn.example.test/new-logo.png'
        && $domain['favicon_url'] === ''
        && $domain['invalid_page_message'] === 'This link has expired.',
    'Advanced branding was not persisted during domain update.'
);
policy_assert($domains->updateAppearance(
    $domainId,
    '#102030',
    'https://cdn.example.test/appearance-logo.png',
    'https://cdn.example.test/appearance-icon.png',
    'Unavailable',
    'This domain-specific link is unavailable.'
), 'Dedicated appearance update failed.');
$domain = $domains->find($domainId);
policy_assert(
    is_array($domain) && $domain['brand_color'] === '#102030'
        && $domain['logo_url'] === 'https://cdn.example.test/appearance-logo.png'
        && $domain['favicon_url'] === 'https://cdn.example.test/appearance-icon.png'
        && $domain['invalid_page_title'] === 'Unavailable'
        && $domain['invalid_page_message'] === 'This domain-specific link is unavailable.',
    'Dedicated appearance fields were not persisted.'
);
policy_assert($domains->selectable($domainId) === null, 'Pending domain became selectable.');
$pdo->exec("UPDATE short_domains SET verified_at = '2026-08-06T00:00:00Z', is_enabled = 1 WHERE id = {$domainId}");
policy_assert($domains->selectable($domainId) !== null, 'Verified domain is not selectable.');

$service = new LinkService($pdo, 2048, 100, 5000, $config);
$linkId = $service->create(
    'domain01',
    'https://target.example.test/private/path?secret=hidden',
    'Domain link',
    gmdate('Y-m-d\TH:i:s\Z', time() + 86400),
    [],
    false,
    null,
    null,
    false,
    'immediate',
    '',
    '',
    '',
    '',
    null,
    '',
    null,
    $domainId
);
$created = $pdo->query("SELECT * FROM webhook_outbox WHERE event_type = 'link.created'")->fetch();
policy_assert(is_array($created), 'Link creation did not enqueue a lifecycle event.');
policy_assert(str_contains((string)$created['payload_json'], 'https://go.example.test/domain01'), 'Lifecycle event used the wrong short domain.');
policy_assert(!str_contains((string)$created['payload_json'], '/private/path'), 'Lifecycle event exposed the target path.');
policy_assert($domains->deleteUnused($domainId) === 'in_use', 'A domain with assigned links was deleted.');
policy_assert($domains->find($domainId) !== null, 'In-use domain disappeared after a rejected deletion.');
policy_assert(short_url_base($config, $service->getAdminLink($linkId) ?: []) === 'https://go.example.test', 'Admin URL generation ignored the custom domain.');

policy_assert($domains->setEnabled($domainId, false), 'Domain disable failed.');
$disabledLink = $service->getAdminLink($linkId);
policy_assert(is_array($disabledLink) && link_status_label($disabledLink) === '域名已停用', 'Admin link status ignored the disabled domain.');
policy_assert($service->listForAdmin('active', '', 1, 20, 'active')['total'] === 0, 'Active filter included a disabled-domain link.');
policy_assert($service->listForAdmin('active', '', 1, 20, 'inactive')['total'] === 1, 'Inactive filter omitted a disabled-domain link.');
$pdo->exec("UPDATE links SET starts_at = datetime('now', '+1 hour') WHERE id = {$linkId}");
policy_assert($service->listForAdmin('active', '', 1, 20, 'scheduled')['total'] === 0, 'Scheduled filter included a disabled-domain link.');
policy_assert($service->statusDistribution()['inactive'] === 1, 'Status distribution did not classify a disabled-domain link as inactive.');
$pdo->exec("UPDATE links SET starts_at = NULL, expires_at = datetime('now', '-1 hour') WHERE id = {$linkId}");
policy_assert($service->listForAdmin('active', '', 1, 20, 'expired')['total'] === 0, 'Expired filter included a disabled-domain link.');
$pdo->exec("UPDATE links SET expires_at = NULL, max_clicks = 1, clicks = 1 WHERE id = {$linkId}");
policy_assert($service->listForAdmin('active', '', 1, 20, 'exhausted')['total'] === 0, 'Exhausted filter included a disabled-domain link.');
$pdo->exec("UPDATE links SET max_clicks = NULL, clicks = 0 WHERE id = {$linkId}");
policy_assert($domains->setEnabled($domainId, true), 'Domain re-enable failed.');

$unusedDomainId = $domains->create('unused.example.test', 'Unused', '', 'graphite');
policy_assert($domains->deleteUnused($unusedDomainId) === 'deleted', 'Unused domain could not be deleted.');
policy_assert($domains->find($unusedDomainId) === null, 'Deleted domain remained in storage.');

unset($GLOBALS['linkvault_short_domain']);
policy_assert($service->find('domain01') === null, 'Canonical host resolved a custom-domain link.');
$GLOBALS['linkvault_short_domain'] = $domains->find($domainId);
policy_assert((int)($service->find('domain01')['id'] ?? 0) === $linkId, 'Custom domain did not resolve its assigned link.');
$aliasInsert = $pdo->prepare('INSERT INTO link_aliases (alias, link_id, created_at) VALUES (:alias, :link_id, :created_at)');
$aliasInsert->execute(['alias' => 'domain-alias', 'link_id' => $linkId, 'created_at' => utc_timestamp()]);
policy_assert((int)($service->find('domain-alias')['id'] ?? 0) === $linkId, 'Custom domain did not resolve its link alias.');
unset($GLOBALS['linkvault_short_domain']);
policy_assert($service->find('domain-alias') === null, 'Canonical host resolved a custom-domain link alias.');

$link = $service->getAdminLink($linkId);
policy_assert(is_array($link) && $service->toggle($linkId, false, (string)$link['updated_at']), 'Disabling the link failed.');
policy_assert((int)$pdo->query("SELECT COUNT(*) FROM webhook_outbox WHERE event_type = 'link.disabled'")->fetchColumn() === 1, 'Disable event was not queued.');
policy_assert(LifecycleWebhookService::enqueueExpiring($pdo, $config, 7) === 0, 'Disabled link produced an expiring event.');

$transport = new LifecycleTestTransport();
$client = new WebhookClient(new LifecycleTestResolver(), $transport);
$dispatch = (new LifecycleWebhookService($pdo, $config, $client))->dispatch(50);
policy_assert($dispatch['delivered'] === 2 && count($transport->requests) === 2, 'Lifecycle outbox did not deliver queued events.');
foreach ($transport->requests as $request) {
    $headerText = implode("\n", $request['headers']);
    policy_assert(str_contains($headerText, 'X-LinkVault-Event-ID:'), 'Event ID header is missing.');
    policy_assert(str_contains($headerText, 'X-LinkVault-Signature: v1='), 'Signature header is missing.');
}
policy_assert((int)$pdo->query("SELECT COUNT(*) FROM webhook_outbox WHERE status = 'delivered'")->fetchColumn() === 2, 'Delivered events remained pending.');

$destinationId = $domains->create('new.example.test', 'New', '', 'graphite');
$pdo->exec("UPDATE short_domains SET verified_at = '2026-08-06T00:00:00Z', is_enabled = 1 WHERE id = {$destinationId}");
$v3Analysis = $service->analyzeImport([[
    'slug' => 'domain01',
    'target_url' => 'https://target.example.test/private/path?secret=hidden',
    'title' => 'Domain link',
    'is_active' => 0,
    'password_protected' => 0,
    'invalid_message' => '',
    'fallback_url' => null,
    'short_domain' => 'NEW.Example.Test.',
    'tags' => [],
]], 3, 'overwrite');
policy_assert(
    $v3Analysis['overwritten'] === 1
        && in_array('short_domain', array_column($v3Analysis['changes'][0]['diffs'], 'field'), true),
    'V3 overwrite preview omitted the domain migration.'
);
$service->importPrepared($v3Analysis['items']);
policy_assert((int)$pdo->query("SELECT short_domain_id FROM links WHERE id = {$linkId}")->fetchColumn() === $destinationId, 'V3 overwrite did not migrate the link domain.');
$v1Analysis = $service->analyzeImport([[
    'slug' => 'domain01',
    'target_url' => 'https://target.example.test/private/path?secret=hidden',
    'title' => 'Legacy overwrite',
]], 1, 'overwrite');
$service->importPrepared($v1Analysis['items']);
policy_assert((int)$pdo->query("SELECT short_domain_id FROM links WHERE id = {$linkId}")->fetchColumn() === $destinationId, 'Legacy overwrite cleared the existing domain.');
$pdo->exec("UPDATE links SET short_domain_id = {$domainId}, updated_at = '2026-08-07T00:00:00Z' WHERE id = {$linkId}");
$trashedId = $service->create('domain02', 'https://target.example.test/trashed', 'Trashed domain link', null, [], false, null, null, false, 'immediate', '', '', '', '', null, '', null, $domainId);
policy_assert($service->softDelete($trashedId), 'Domain retirement fixture could not enter the trash.');
policy_assert($service->retireShortDomain($domainId, $domainId)['status'] === 'same_domain', 'Domain retirement accepted its source as destination.');
$retirement = $service->retireShortDomain($domainId, $destinationId);
policy_assert($retirement === ['status' => 'queued', 'migrated' => 0], 'Domain retirement was not queued.');
policy_assert((int)$pdo->query("SELECT is_enabled FROM short_domains WHERE id = {$domainId}")->fetchColumn() === 0, 'Queued retirement did not disable the source domain.');
policy_assert($service->controlShortDomainRetirement(1, 'pause'), 'Domain retirement could not be paused.');
policy_assert($service->processShortDomainRetirementBatch(10) === ['status' => 'idle', 'migrated' => 0], 'Paused retirement was processed.');
policy_assert($service->controlShortDomainRetirement(1, 'resume'), 'Domain retirement could not be resumed.');
$pdo->exec("UPDATE short_domain_retirement_jobs SET status = 'failed', last_error = 'test failure' WHERE id = 1");
policy_assert($service->controlShortDomainRetirement(1, 'retry'), 'Failed domain retirement could not be retried.');
policy_assert($service->processShortDomainRetirementBatch(10) === ['status' => 'completed', 'migrated' => 2], 'Domain retirement batch did not migrate every assigned link.');
$retirementJobs = $service->shortDomainRetirementJobs();
policy_assert((int)$retirementJobs[0]['total_count'] === 2 && (int)$retirementJobs[0]['migrated_count'] === 2, 'Domain retirement progress is incorrect.');
policy_assert($domains->find($domainId) === null, 'Retired domain remained in storage.');
policy_assert((int)$pdo->query("SELECT COUNT(*) FROM links WHERE short_domain_id = {$destinationId}")->fetchColumn() === 2, 'Retirement omitted active or trashed links.');

$failedSourceId = $domains->create('retire-source.example.test', 'Retire source', '', 'graphite');
$failedDestinationId = $domains->create('retire-target.example.test', 'Retire target', '', 'graphite');
$pdo->exec("UPDATE short_domains SET verified_at = '2026-08-06T00:00:00Z', is_enabled = 1 WHERE id IN ({$failedSourceId}, {$failedDestinationId})");
$failedLinkId = $service->create('domain03', 'https://target.example.test/failed-retirement', 'Failed retirement', null, [], false, null, null, false, 'immediate', '', '', '', '', null, '', null, $failedSourceId);
policy_assert($service->retireShortDomain($failedSourceId, $failedDestinationId)['status'] === 'queued', 'Failure-path retirement was not queued.');
policy_assert($domains->deleteUnused($failedDestinationId) === 'deleted', 'Unused retirement destination could not be removed.');
policy_assert($service->processShortDomainRetirementBatch(10) === ['status' => 'failed', 'migrated' => 0], 'Missing retirement destination did not fail the job.');
$failedJob = $pdo->query("SELECT status, last_error FROM short_domain_retirement_jobs WHERE source_id = {$failedSourceId}")->fetch();
policy_assert(
    is_array($failedJob) && $failedJob['status'] === 'failed' && (string)$failedJob['last_error'] !== '',
    'Missing-destination retirement did not persist terminal failure details.'
);
policy_assert((int)$pdo->query("SELECT short_domain_id FROM links WHERE id = {$failedLinkId}")->fetchColumn() === $failedSourceId, 'Failed retirement partially migrated its link.');

@unlink($databasePath);
fwrite(STDOUT, "Domain and lifecycle policy tests passed.\n");
