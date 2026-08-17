<?php

declare(strict_types=1);

final class ImportExportController
{
    public static function dispatch(
        string $method,
        string $path,
        PDO $pdo,
        array $config,
        LinkService $service,
    ): void {
        if (in_array($method, ['GET', 'POST'], true) && $path === '/export-links') {
            require_login();
            if ($method === 'POST') {
                require_csrf();
            }
            $input = $method === 'POST' ? $_POST : $_GET;
            $scope = $method === 'POST' ? 'selected' : ((string)($input['scope'] ?? '') === 'current' ? 'current' : 'all');
            $exportView = (string)($input['view'] ?? '') === 'trash' ? 'trash' : 'active';
            $exportSearch = limit_text(trim((string)($input['q'] ?? '')), 200);
            $exportStatus = in_array((string)($input['status'] ?? 'all'), ['all', 'active', 'inactive', 'scheduled', 'expired', 'exhausted'], true)
                ? (string)($input['status'] ?? 'all') : 'all';
            $exportSort = in_array((string)($input['sort'] ?? 'created_asc'), [
                'created_desc', 'created_asc', 'clicks_desc', 'clicks_asc', 'last_accessed_desc', 'title_asc',
            ], true) ? (string)($input['sort'] ?? 'created_asc') : 'created_asc';
            $exportTag = limit_text(trim((string)($input['tag'] ?? '')), 24);
            $exportFavorites = (string)($input['favorite'] ?? '') === '1';
            if ($scope === 'all') {
                $exportView = 'active';
                $exportSearch = '';
                $exportStatus = 'all';
                $exportSort = 'created_asc';
                $exportTag = '';
                $exportFavorites = false;
            }
            if ($exportView === 'trash') {
                $exportStatus = 'all';
                $exportFavorites = false;
            }
            $selectedIds = $scope === 'selected' && is_array($input['selected'] ?? null) ? $input['selected'] : null;
            if ($scope === 'selected' && !$selectedIds) {
                flash('请先选择要导出的链接。', 'error');
                redirect_to(posted_list_path());
            }
            $exportedLinks = $service->exportLinks(
                $exportView,
                $exportSearch,
                $exportStatus,
                $exportSort,
                $exportTag,
                $exportFavorites,
                $selectedIds
            );
            $payload = json_encode([
                'version' => 3,
                'kind' => 'link_export',
                'exported_at' => gmdate('c'),
                'scope' => $scope,
                'links' => $exportedLinks,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($payload === false) {
                throw new RuntimeException('Cannot encode link export.');
            }
            header('Content-Type: application/json; charset=UTF-8');
            audit_event($pdo, $config, 'admin', 'export_links', 'success', 'link', null, [
                'scope' => $scope,
                'count' => count($exportedLinks),
                'filters' => $scope === 'all' ? null : [
                    'view' => $exportView,
                    'search' => $exportSearch,
                    'status' => $exportStatus,
                    'sort' => $exportSort,
                    'tag' => $exportTag,
                    'favorites_only' => $exportFavorites,
                ],
            ]);
            header('Content-Disposition: attachment; filename="linkvault-export-' . $scope . '-' . gmdate('Ymd-His') . '.json"');
            header('Cache-Control: no-store');
            exit($payload);
        }

        if ($method === 'GET' && $path === '/export-snapshot') {
            require_login();
            header('Content-Type: application/json; charset=UTF-8');
            header('Content-Disposition: attachment; filename="linkvault-audit-snapshot-' . gmdate('Ymd-His') . '.json"');
            header('Cache-Control: no-store');
            header('X-Accel-Buffering: no');
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $output = fopen('php://output', 'wb');
            if (!is_resource($output)) {
                throw new RuntimeException('Cannot open audit data snapshot output.');
            }
            $snapshotCounts = $service->streamFullSnapshot(static function (string $chunk) use ($output): void {
                if (fwrite($output, $chunk) === false) {
                    throw new RuntimeException('Cannot write audit data snapshot output.');
                }
            }, gmdate('c'));
            audit_event($pdo, $config, 'admin', 'export_snapshot', 'success', 'database', null, [
                'links' => $snapshotCounts['links'] ?? 0,
                'daily_stats' => $snapshotCounts['link_daily_stats'] ?? 0,
                'archived_daily_stats' => $snapshotCounts['link_daily_stats_archive'] ?? 0,
                'visitor_hourly_stats' => $snapshotCounts['visitor_hourly_stats'] ?? 0,
                'visitor_daily_stats' => $snapshotCounts['visitor_daily_stats'] ?? 0,
            ]);
            fclose($output);
            exit;
        }

        if ($method === 'GET' && $path === '/import-report') {
            require_login();
            $preview = $_SESSION['import_preview'] ?? null;
            $reportType = (string)($_GET['type'] ?? '') === 'changes' ? 'changes' : 'errors';
            $reportRows = $reportType === 'changes'
                ? ($preview['report_changes'] ?? null)
                : ($preview['report_issues'] ?? null);
            if (!is_array($preview) || time() > (int)($preview['expires_at'] ?? 0)
                || !is_array($reportRows) || !$reportRows) {
                render_error_page(404, '错误报告不可用', '导入预览已过期，或本次预览没有需要报告的问题。');
            }
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="linkvault-import-' . $reportType . '-' . gmdate('Ymd-His') . '.csv"');
            header('Cache-Control: no-store');
            $output = fopen('php://output', 'wb');
            if (!is_resource($output)) {
                throw new RuntimeException('Cannot open import report output.');
            }
            fwrite($output, "\xEF\xBB\xBF");
            if ($reportType === 'changes') {
                fputcsv($output, ['行', '操作', '原短码', '结果短码', '字段', '变更前', '变更后']);
                foreach ($reportRows as $change) {
                    if (!is_array($change)) {
                        continue;
                    }
                    foreach ($change['diffs'] ?? [] as $diff) {
                        if (!is_array($diff)) {
                            continue;
                        }
                        fputcsv($output, [
                            (int)($change['row'] ?? 0),
                            csv_safe_cell((string)($change['action'] ?? '')),
                            csv_safe_cell((string)($change['source_slug'] ?? '')),
                            csv_safe_cell((string)($change['result_slug'] ?? '')),
                            csv_safe_cell((string)($diff['field'] ?? '')),
                            csv_safe_cell(self::formatImportDiffValue($diff['before'] ?? null)),
                            csv_safe_cell(self::formatImportDiffValue($diff['after'] ?? null)),
                        ]);
                    }
                }
            } else {
                fputcsv($output, ['行', '短码', '结果']);
                foreach ($reportRows as $issue) {
                    if (!is_array($issue)) {
                        continue;
                    }
                    fputcsv($output, [
                        (int)($issue['row'] ?? 0),
                        csv_safe_cell((string)($issue['slug'] ?? '')),
                        csv_safe_cell((string)($issue['reason'] ?? '')),
                    ]);
                }
            }
            fclose($output);
            exit;
        }

        if ($method === 'POST' && $path === '/import') {
            require_login();
            require_csrf();
            $conflictMode = (string)($_POST['conflict_mode'] ?? 'skip');
            if (!in_array($conflictMode, ['skip', 'overwrite', 'new_slug'], true)) {
                flash('导入冲突策略无效。', 'error');
                redirect_to(app_path('/'));
            }
            $upload = $_FILES['import_file'] ?? null;
            $maxBytes = max(1024, (int)($config['import_max_bytes'] ?? 2 * 1024 * 1024));
            if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
                flash('请选择有效的 JSON 导入文件。', 'error');
                redirect_to(app_path('/'));
            }
            if ((int)($upload['size'] ?? 0) > $maxBytes) {
                flash('导入文件不能超过 ' . round($maxBytes / 1024 / 1024, 2) . ' MB。', 'error');
                redirect_to(app_path('/'));
            }
            $raw = file_get_contents($upload['tmp_name']);
            if (!is_string($raw) || strlen($raw) > $maxBytes) {
                flash('导入文件不能超过 ' . round($maxBytes / 1024 / 1024, 2) . ' MB。', 'error');
                redirect_to(app_path('/'));
            }
            try {
                $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($payload) && ($payload['kind'] ?? null) === 'full_data_snapshot') {
                    flash('审计数据快照不能导入或恢复，请使用 SQLite 在线备份恢复流程。', 'error');
                    redirect_to(app_path('/'));
                }
                $formatVersion = is_array($payload) ? ($payload['version'] ?? null) : null;
                if (!is_array($payload)
                    || ($payload['kind'] ?? null) !== 'link_export'
                    || !is_int($formatVersion)
                    || !in_array($formatVersion, [1, 2, 3], true)) {
                    flash('仅支持 version=1、version=2 或 version=3 的 link_export 导入文件。', 'error');
                    redirect_to(app_path('/'));
                }
                $items = $payload['links'] ?? null;
                if (!is_array($items) || !array_is_list($items)) {
                    throw new RuntimeException('Invalid link JSON.');
                }
                $analysis = $service->analyzeImport($items, $formatVersion, $conflictMode);
                $issues = array_merge($analysis['duplicates'], $analysis['errors']);
                usort($issues, static fn (array $left, array $right): int => (int)$left['row'] <=> (int)$right['row']);
                $planJson = json_encode($analysis['items'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $_SESSION['import_preview'] = [
                    'token' => bin2hex(random_bytes(24)),
                    'expires_at' => time() + 900,
                    'mode' => $analysis['mode'],
                    'plan_hash' => hash('sha256', $planJson),
                    'new' => $analysis['new'],
                    'renamed' => $analysis['renamed'],
                    'overwritten' => $analysis['overwritten'],
                    'unchanged' => $analysis['unchanged'],
                    'writes' => $analysis['writes'],
                    'duplicate' => $analysis['duplicate'],
                    'invalid' => $analysis['invalid'],
                    'password_reset_required' => $analysis['password_reset_required'],
                    'items' => $analysis['items'],
                    'total' => count($items),
                    'issue_count' => count($issues),
                    'issues' => array_slice($issues, 0, 100),
                    'report_issues' => $issues,
                    'change_count' => count($analysis['changes']),
                    'changes' => array_slice($analysis['changes'], 0, 100),
                    'report_changes' => $analysis['changes'],
                ];
                $resetNotice = (int)$analysis['password_reset_required'] > 0
                    ? ' 其中 ' . (int)$analysis['password_reset_required'] . ' 条受保护链接将保持停用，且必须重新设置密码。'
                    : '';
                flash('Dry Run 已完成，请核对预览后确认导入。' . $resetNotice, 'ok');
            } catch (InvalidArgumentException) {
                flash('导入记录数不能超过 ' . (int)($config['import_max_records'] ?? 5000) . ' 条。', 'error');
            } catch (Throwable $exception) {
                if (is_database_unavailable($exception)) {
                    throw $exception;
                }
                log_event($config, 'link_import_failed', ['error' => limit_text($exception->getMessage(), 300)]);
                flash('导入失败，请检查文件格式。', 'error');
            }
            redirect_to(app_path('/'));
        }

        if ($method === 'POST' && $path === '/import-confirm') {
            require_login();
            require_csrf();
            $preview = $_SESSION['import_preview'] ?? null;
            $token = $_POST['preview_token'] ?? null;
            if (!is_array($preview) || !is_string($token) || !is_string($preview['token'] ?? null)
                || time() > (int)($preview['expires_at'] ?? 0) || !hash_equals($preview['token'], $token)
                || !is_array($preview['items'] ?? null)) {
                unset($_SESSION['import_preview']);
                flash('导入预览已过期，请重新上传。', 'error');
                redirect_to(app_path('/'));
            }
            try {
                $planJson = json_encode($preview['items'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if (!is_string($preview['plan_hash'] ?? null)
                    || !hash_equals($preview['plan_hash'], hash('sha256', $planJson))) {
                    throw new RuntimeException('Import preview is stale: the prepared plan is invalid.');
                }
                $result = $service->importPrepared($preview['items']);
                unset($_SESSION['import_preview']);
                $imported = $result['imported'];
                $skipped = $result['skipped'] + (int)($preview['duplicate'] ?? 0) + (int)($preview['invalid'] ?? 0);
                audit_event($pdo, $config, 'admin', 'import', 'success', 'link', null, [
                    'mode' => (string)($preview['mode'] ?? 'skip'),
                    'plan_hash' => (string)($preview['plan_hash'] ?? ''),
                    'imported' => $imported,
                    'renamed' => (int)$result['renamed'],
                    'overwritten' => (int)$result['overwritten'],
                    'unchanged' => (int)($preview['unchanged'] ?? 0),
                    'skipped' => $skipped,
                ]);
                $resetCount = max(0, (int)($result['password_reset_required'] ?? 0));
                $resetNotice = $resetCount > 0
                    ? " 其中 {$resetCount} 条受保护链接已停用，须重新设置密码后才能启用。"
                    : '';
                flash(
                    "导入完成：新增 {$imported} 条（其中新短码 " . (int)$result['renamed']
                        . " 条），覆盖 " . (int)$result['overwritten'] . " 条，跳过 {$skipped} 条。{$resetNotice}",
                    $imported > 0 || (int)$result['overwritten'] > 0 || $skipped === 0 ? 'ok' : 'error'
                );
            } catch (Throwable $exception) {
                if (is_database_unavailable($exception)) {
                    throw $exception;
                }
                log_event($config, 'link_import_confirm_failed', ['error' => limit_text($exception->getMessage(), 300)]);
                $stale = str_starts_with($exception->getMessage(), 'Import preview is stale:');
                audit_event($pdo, $config, 'admin', 'import', 'failure', 'link', null, [
                    'mode' => (string)($preview['mode'] ?? 'skip'),
                    'plan_hash' => (string)($preview['plan_hash'] ?? ''),
                    'reason_code' => $stale ? 'preview_conflicted' : 'write_failed',
                    'reason' => limit_text($exception->getMessage(), 200),
                ]);
                if ($stale) {
                    unset($_SESSION['import_preview']);
                    flash('导入预览后数据已变化，未写入任何记录。请重新执行 Dry Run。', 'error');
                } else {
                    flash('导入失败，预览仍保留，可稍后重试。', 'error');
                }
            }
            redirect_to(app_path('/'));
        }

        if ($method === 'POST' && $path === '/import-cancel') {
            require_login();
            require_csrf();
            unset($_SESSION['import_preview']);
            flash('已取消本次导入。');
            redirect_to(app_path('/'));
        }
    }

    private static function formatImportDiffValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return implode(', ', array_map(static fn (mixed $item): string => (string)$item, $value));
        }
        return (string)$value;
    }
}
