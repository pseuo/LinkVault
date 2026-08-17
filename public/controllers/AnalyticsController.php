<?php

declare(strict_types=1);

final class AnalyticsController
{
    public static function dispatch(
        string $method,
        string $path,
        PDO $pdo,
        array $config,
        AnalyticsReportService $analyticsReportService,
        AnalyticsExportJobService $analyticsExportJobService,
    ): void {
        if ($method === 'POST' && in_array($path, [
            '/analytics-views/save', '/analytics-views/rename', '/analytics-views/delete',
        ], true)) {
            require_login();
            require_csrf();
            try {
                $request = $analyticsReportService->normalizeRequest($_POST);
            } catch (AnalyticsInvalidDateRange) {
                flash('自定义分析日期无效，请重新选择日期范围。', 'error');
                redirect_to(app_path('/?section=analytics'));
            }
            $parameters = $analyticsReportService->queryParameters($request);
            $returnPath = app_path('/') . '?' . http_build_query($parameters);
            if ($path === '/analytics-views/save') {
                $name = trim((string)($_POST['name'] ?? ''));
                if (!self::validViewName($name)) {
                    flash('分析视图名称须为 1 至 60 个字符。', 'error');
                    redirect_to($returnPath);
                }
                $viewId = $analyticsReportService->saveView($name, $request);
                audit_event($pdo, $config, 'admin', 'save_analytics_view', 'success', 'saved_analytics_view', (string)$viewId, [
                    'name' => $name,
                    'parameters' => $parameters,
                ]);
                flash('分析视图已保存；同名视图会直接更新。');
                redirect_to($returnPath);
            }

            $viewId = max(0, (int)($_POST['id'] ?? 0));
            if ($path === '/analytics-views/delete') {
                $deleted = $analyticsReportService->deleteSavedView($viewId);
                audit_event($pdo, $config, 'admin', 'delete_analytics_view', $deleted ? 'success' : 'failure', 'saved_analytics_view', (string)$viewId);
                flash($deleted ? '分析视图已删除。' : '分析视图不存在。', $deleted ? 'ok' : 'error');
                redirect_to($returnPath);
            }

            $name = trim((string)($_POST['name'] ?? ''));
            if ($viewId <= 0 || !self::validViewName($name)) {
                flash('分析视图名称须为 1 至 60 个字符。', 'error');
                redirect_to($returnPath);
            }
            $renamed = $analyticsReportService->renameSavedView($viewId, $name);
            audit_event($pdo, $config, 'admin', 'rename_analytics_view', $renamed ? 'success' : 'failure', 'saved_analytics_view', (string)$viewId, [
                'name' => $name,
            ]);
            flash($renamed ? '分析视图已重命名。' : '分析视图不存在，或名称已被使用。', $renamed ? 'ok' : 'error');
            redirect_to($returnPath);
        }

        if ($method === 'POST' && $path === '/analytics-exports') {
            require_login();
            require_csrf();
            try {
                $request = $analyticsReportService->normalizeRequest($_POST);
            } catch (AnalyticsInvalidDateRange) {
                json_response(422, ['error' => '自定义分析日期无效。']);
            }
            $report = (string)($_POST['report'] ?? 'filtered');
            $id = $analyticsExportJobService->enqueue(self::ownerHash(), $report, $request);
            audit_event($pdo, $config, 'admin', 'queue_analytics_export', 'success', 'analytics_export', $id, [
                'report' => $report,
                'range' => ['start' => $request['start'], 'end' => $request['end']],
            ]);
            json_response(202, [
                'id' => $id,
                'status' => 'pending',
                'status_url' => app_path('/analytics-export-status') . '?id=' . rawurlencode($id),
            ]);
        }

        if ($method === 'GET' && $path === '/analytics-export-status') {
            require_login();
            $job = $analyticsExportJobService->status((string)($_GET['id'] ?? ''), self::ownerHash());
            if ($job === null) {
                json_response(404, ['error' => '导出任务不存在。']);
            }
            $completed = (string)$job['status'] === 'completed' && (string)$job['expires_at'] > utc_timestamp();
            json_response(200, [
                'id' => (string)$job['id'],
                'status' => $completed ? 'completed' : ((string)$job['status'] === 'completed' ? 'expired' : (string)$job['status']),
                'rows' => (int)$job['row_count'],
                'size_bytes' => $job['size_bytes'] === null ? null : (int)$job['size_bytes'],
                'error' => $job['last_error'],
                'download_url' => $completed
                    ? app_path('/analytics-export-download') . '?id=' . rawurlencode((string)$job['id'])
                    : null,
            ]);
        }

        if ($method === 'GET' && $path === '/analytics-export-download') {
            require_login();
            $job = $analyticsExportJobService->status((string)($_GET['id'] ?? ''), self::ownerHash());
            if ($job === null || (string)$job['status'] !== 'completed' || (string)$job['expires_at'] <= utc_timestamp()) {
                render_error_page(404, '导出文件不可用', '导出任务不存在、尚未完成或已经过期。');
            }
            $artifact = $analyticsExportJobService->artifactPath($job);
            if ($artifact === null) {
                render_error_page(404, '导出文件不可用', '导出文件不存在或已经清理。');
            }
            $downloadName = preg_replace('/[^A-Za-z0-9._-]/', '-', (string)$job['download_name']) ?: 'analytics.csv';
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('Content-Length: ' . (string)filesize($artifact));
            header('Cache-Control: no-store, private');
            readfile($artifact);
            exit;
        }

        if ($method === 'GET' && $path === '/export-analytics') {
            require_login();
            try {
                $analyticsExportRequest = $analyticsReportService->normalizeRequest($_GET);
            } catch (AnalyticsInvalidDateRange) {
                flash('自定义分析日期无效，未生成导出文件。', 'error');
                redirect_to(app_path('/?section=analytics'));
            }
            $report = (string)($_GET['report'] ?? 'filtered');
            try {
                $analyticsExport = $analyticsReportService->export($report, $analyticsExportRequest);
            } catch (AnalyticsExportLimitExceeded $exception) {
                audit_event($pdo, $config, 'admin', 'export_analytics', 'failure', 'statistics', null, [
                    'report' => $report,
                    'reason' => 'row_limit_exceeded',
                    'row_limit' => $exception->limit,
                    'range' => [
                        'start' => $analyticsExportRequest['start'],
                        'end' => $analyticsExportRequest['end'],
                        'timezone' => $analyticsExportRequest['timezone'],
                    ],
                    'filters' => $analyticsExportRequest['filters'],
                ]);
                flash(
                    '分析导出结果超过 ' . number_format($exception->limit)
                        . ' 行，未生成不完整文件。请缩小日期范围或增加筛选条件。',
                    'error'
                );
                redirect_to(app_path('/') . '?' . http_build_query(
                    $analyticsReportService->queryParameters($analyticsExportRequest)
                ));
            }
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $analyticsExport['filename'] . '"');
            header('Cache-Control: no-store');
            $output = fopen('php://output', 'wb');
            if (!is_resource($output)) {
                throw new RuntimeException('Cannot open analytics report output.');
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $analyticsExport['headers']);
            $exportedRows = 0;
            foreach ($analyticsExport['rows'] as $row) {
                fputcsv($output, array_map(
                    static fn (int|string $value): int|string => is_string($value) ? csv_safe_cell($value) : $value,
                    $row
                ));
                $exportedRows++;
            }
            audit_event($pdo, $config, 'admin', 'export_analytics', 'success', 'statistics', null, [
                'report' => $report,
                'range' => [
                    'start' => $analyticsExportRequest['start'],
                    'end' => $analyticsExportRequest['end'],
                    'timezone' => $analyticsExportRequest['timezone'],
                ],
                'filters' => $analyticsExportRequest['filters'],
                'rows' => $exportedRows,
            ]);
            fclose($output);
            exit;
        }
    }

    private static function validViewName(string $name): bool
    {
        return $name !== '' && text_length($name) <= 60 && preg_match('/[\x00-\x1F\x7F]/u', $name) !== 1;
    }

    private static function ownerHash(): string
    {
        return hash('sha256', session_id());
    }
}
