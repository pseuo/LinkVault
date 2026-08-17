<?php

declare(strict_types=1);

require_once __DIR__ . '/LinkService/LinkManagementTrait.php';
require_once __DIR__ . '/LinkService/LinkImportExportTrait.php';
require_once __DIR__ . '/LinkService/LinkAnalyticsTrait.php';
require_once __DIR__ . '/LinkService/LinkOperationsTrait.php';
require_once __DIR__ . '/LinkService/LinkServiceSupportTrait.php';
require_once __DIR__ . '/LifecycleWebhookService.php';

final class IdempotencyConflict extends RuntimeException
{
}

final class LinkService
{
    use LinkManagementTrait;
    use LinkImportExportTrait;
    use LinkAnalyticsTrait;
    use LinkOperationsTrait;
    use LinkServiceSupportTrait;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxUrlLength = 2048,
        private readonly int $importBatchSize = 100,
        private readonly int $importMaxRecords = 5000,
        private readonly array $config = [],
    ) {
    }
}
