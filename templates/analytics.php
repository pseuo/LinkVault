<?php
$analyticsTotals = (array)($analyticsData['totals'] ?? []);
$analyticsPreviousTotals = (array)($analyticsData['previous_totals'] ?? []);
$analyticsDeltas = (array)($analyticsData['deltas'] ?? []);
$analyticsPercentChanges = (array)($analyticsData['percent_changes'] ?? []);
$analyticsReconciliation = (array)($analyticsData['reconciliation'] ?? []);
$analyticsTrend = (array)($analyticsData['trend'] ?? []);
$analyticsHours = (array)($analyticsData['hours'] ?? array_fill(0, 24, 0));
$analyticsRankings = (array)($analyticsData['rankings'] ?? []);
$analyticsCoverage = (array)($analyticsData['coverage'] ?? []);
$analyticsStatus = (array)($analyticsStatus ?? []);
$analyticsTimezone = (string)$analyticsRequest['timezone'];
$analyticsTrendMax = max(1, ...array_map(static fn (array $row): int => (int)$row['proxy_requests'], $analyticsTrend));
$analyticsHourMax = max(1, ...array_map('intval', $analyticsHours));
$proxyRequests = (int)($analyticsTotals['proxy_requests'] ?? 0);
$suspectedHumanRate = $proxyRequests > 0
    ? round((int)($analyticsTotals['suspected_human_requests'] ?? 0) * 100 / $proxyRequests, 1) : 0;
$unknownRate = $proxyRequests > 0
    ? round((int)($analyticsTotals['unknown_requests'] ?? 0) * 100 / $proxyRequests, 1) : 0;
$analyticsAggregationAt = max(
    0,
    (int)($analyticsStatus['completed_at'] ?? 0),
    (int)($analyticsStatus['last_success_at'] ?? 0)
);
$analyticsAggregationIso = $analyticsAggregationAt > 0 ? gmdate('Y-m-d\TH:i:s\Z', $analyticsAggregationAt) : '';
$analyticsDataUpdatedAt = max(0, (int)($analyticsStatus['latest_event_at'] ?? 0));
$analyticsDataUpdatedIso = $analyticsDataUpdatedAt > 0 ? gmdate('Y-m-d\TH:i:s\Z', $analyticsDataUpdatedAt) : '';
$analyticsHealthy = !empty($analyticsStatus['data_complete']);
$analyticsCollectionState = (string)($analyticsStatus['collection_state'] ?? 'missing_marker');
$analyticsAggregationLabel = match ($analyticsCollectionState) {
    'current' => '正常',
    'backlogged' => '处理中',
    'failed' => '失败（连续 ' . (int)($analyticsStatus['consecutive_failures'] ?? 0) . ' 次）',
    'stale' => '状态过期',
    'log_missing' => '采集日志缺失',
    'invalid_marker' => '状态记录无效',
    default => '尚无聚合记录',
};
$analyticsZeroUncertain = !$analyticsHealthy && $proxyRequests === 0;
$analyticsActualCoverage = is_string($analyticsCoverage['actual_start'] ?? null)
    && is_string($analyticsCoverage['actual_end'] ?? null)
    ? (string)$analyticsCoverage['actual_start'] . ' 至 ' . (string)$analyticsCoverage['actual_end']
    : '暂无已保留请求';
$analyticsMetricValue = static fn (int $value): string => $analyticsZeroUncertain && $value === 0
    ? '不可判定'
    : (string)$value;
$signedValue = static fn (int $value): string => ($value > 0 ? '+' : '') . (string)$value;
$percentageValue = static function (mixed $value, string $zeroBaseline): string {
    return is_int($value) || is_float($value)
        ? ($value > 0 ? '+' : '') . number_format((float)$value, 1) . '%'
        : $zeroBaseline . '为 0，不计算百分比';
};
$barValue = static fn (int $value, int $maximum): string => number_format(
    min(100, max(0, $value * 100 / max(1, $maximum))),
    3,
    '.',
    ''
);
$queryUrl = static function (array $overrides = []) use ($analyticsQueryParameters): string {
    $query = array_merge($analyticsQueryParameters, $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null || $value === 0) {
            unset($query[$key]);
        }
    }
    return app_path('/') . '?' . http_build_query($query);
};
$defaultLabel = static fn (string $label): string => match ($label) {
    'desktop' => '桌面', 'mobile' => '手机', 'tablet' => '平板', 'other' => '其他',
    'direct' => '直接访问', 'ZZ' => '未知地区', default => $label,
};
$renderOptions = static function (array $values, string $selected, callable $label): void {
    foreach ($values as $value) {
        $value = (string)$value;
        ?><option value="<?= e($value) ?>"<?= $selected === $value ? ' selected' : '' ?>><?= e($label($value)) ?></option><?php
    }
};
$renderAnalyticsContext = static function () use ($analyticsQueryParameters): void {
    foreach ($analyticsQueryParameters as $name => $value) {
        ?><input type="hidden" name="<?= e((string)$name) ?>" value="<?= e((string)$value) ?>"><?php
    }
};
$filterLabels = [
    'link' => '链接', 'tag' => '标签', 'campaign' => '活动', 'source' => '来源', 'medium' => '媒介',
    'referrer' => '来源域名', 'browser' => '浏览器', 'operating_system' => '操作系统',
    'device' => '设备', 'country' => '地区', 'traffic' => '流量类型',
];
$filterValueLabels = [
    'desktop' => '桌面', 'mobile' => '手机', 'tablet' => '平板', 'other' => '其他',
    'suspected_human' => '疑似人工', 'automated' => '自动化', 'bot' => '机器人',
    'scanner' => '安全扫描', 'unknown' => '未知',
];
$activeAnalyticsFilters = [];
foreach ($analyticsFilters as $key => $value) {
    if ($value === '' || $value === 0 || !isset($filterLabels[$key])) {
        continue;
    }
    $display = (string)$value;
    if ($key === 'link') {
        foreach ($analyticsLinks as $linkOption) {
            if ((int)$linkOption['id'] === (int)$value) {
                $display = (string)($linkOption['title'] !== '' ? $linkOption['title'] : '未命名')
                    . ' · ' . (string)$linkOption['slug'];
                break;
            }
        }
    } else {
        $display = $filterValueLabels[$display] ?? $defaultLabel($display);
    }
    $activeAnalyticsFilters[] = ['key' => $key, 'label' => $filterLabels[$key], 'value' => $display];
}
$renderBreakdown = static function (
    string $title,
    string $filter,
    array $rows,
    callable $label
) use ($queryUrl): void {
    $maximum = $rows ? max(1, max(array_map(static fn (array $row): int => (int)$row['clicks'], $rows))) : 1;
    ?>
    <section class="analytics-breakdown">
        <h3><?= e($title) ?></h3>
        <?php if (!$rows): ?><div class="empty compact-empty">当前条件暂无请求。</div><?php else: ?>
            <ol><?php foreach ($rows as $row): ?><?php $value = (string)$row['label']; ?>
                <li><a href="<?= e($queryUrl([$filter => $value])) ?>" title="筛选 <?= e($value) ?>"><span><?= e($label($value)) ?></span><progress max="<?= $maximum ?>" value="<?= (int)$row['clicks'] ?>"><?= (int)$row['clicks'] ?></progress><strong><?= (int)$row['clicks'] ?></strong></a></li>
            <?php endforeach; ?></ol>
        <?php endif; ?>
    </section>
    <?php
};
$rankingDefinitions = [
    'growth' => ['增长最快', static fn (array $row): string => $signedValue((int)$row['delta'])],
    'decline' => ['下降最多', static fn (array $row): string => $signedValue((int)$row['delta'])],
    'bot_share' => ['机器人占比最高', static fn (array $row): string => number_format((float)$row['bot_ratio'], 1) . '%'],
    'first_traffic' => ['首次有流量', static fn (array $row): string => (int)$row['proxy_requests'] . ' 次'],
    'long_zero' => ['长期无流量', static fn (array $row): string => empty($row['last_bucket']) ? '从未有流量' : '最后 ' . substr((string)$row['last_bucket'], 0, 10)],
];
?>
<section class="analytics-heading">
    <div><h2>访问分析</h2><p class="muted">匿名聚合；“疑似人工”仅是 User-Agent 规则判断，不等同于独立访客。</p></div>
    <details class="analytics-export-menu"><summary class="button button-secondary"><svg class="icon" aria-hidden="true"><use href="#icon-download"/></svg>导出</summary><form class="analytics-export-options" method="post" action="<?= e(app_path('/analytics-exports')) ?>" data-analytics-export-form><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><?php $renderAnalyticsContext(); ?><button type="submit" name="report" value="filtered">当前筛选结果</button><button type="submit" name="report" value="trend">趋势</button><button type="submit" name="report" value="sources">来源</button><button type="submit" name="report" value="devices">设备</button><button type="submit" name="report" value="countries">地区</button><button type="submit" name="report" value="campaigns">活动</button></form></details>
</section>
<div class="analytics-export-status" data-analytics-export-status role="status" aria-live="polite" hidden></div>

<section class="saved-filter-bar analytics-saved-views" aria-label="已保存分析视图">
    <div class="saved-filter-list">
        <span class="muted">分析视图</span>
        <?php foreach ($savedAnalyticsViews as $savedView): ?><?php $savedViewUrl = app_path('/') . '?' . http_build_query((array)$savedView['parameters']); ?>
            <span class="saved-filter-item"><a href="<?= e($savedViewUrl) ?>"><?= e((string)$savedView['name']) ?></a><button class="icon-button button-secondary button-small" type="button" data-rename-analytics-view data-view-id="<?= (int)$savedView['id'] ?>" data-view-name="<?= e((string)$savedView['name']) ?>" title="重命名分析视图 <?= e((string)$savedView['name']) ?>" aria-label="重命名分析视图 <?= e((string)$savedView['name']) ?>"><svg class="icon" aria-hidden="true"><use href="#icon-pencil"/></svg></button><form method="post" action="<?= e(app_path('/analytics-views/delete')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$savedView['id'] ?>"><?php $renderAnalyticsContext(); ?><button class="icon-button button-secondary button-small" type="submit" title="删除分析视图 <?= e((string)$savedView['name']) ?>" aria-label="删除分析视图 <?= e((string)$savedView['name']) ?>"><svg class="icon" aria-hidden="true"><use href="#icon-x"/></svg></button></form></span>
        <?php endforeach; ?>
    </div>
    <form class="save-filter-form" method="post" action="<?= e(app_path('/analytics-views/save')) ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><?php $renderAnalyticsContext(); ?>
        <label><span class="sr-only">分析视图名称</span><input type="text" name="name" maxlength="60" placeholder="视图名称" required></label><button class="button-secondary" type="submit"><svg class="icon" aria-hidden="true"><use href="#icon-save"/></svg>保存视图</button>
    </form>
</section>
<dialog id="rename-analytics-view-dialog" aria-labelledby="rename-analytics-view-title">
    <form class="rename-filter-form" method="post" action="<?= e(app_path('/analytics-views/rename')) ?>">
        <div class="dialog-header"><span class="dialog-title"><svg class="icon" aria-hidden="true"><use href="#icon-pencil"/></svg><h2 id="rename-analytics-view-title">重命名分析视图</h2></span><button class="button-secondary icon-button" type="button" data-close-dialog title="关闭重命名窗口" aria-label="关闭重命名窗口"><svg class="icon" aria-hidden="true"><use href="#icon-x"/></svg></button></div>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="" data-rename-analytics-view-id><?php $renderAnalyticsContext(); ?>
        <label>视图名称<input type="text" name="name" maxlength="60" required data-rename-analytics-view-name></label>
        <div class="dialog-actions"><button class="button-secondary" type="button" data-close-dialog>取消</button><button type="submit"><svg class="icon" aria-hidden="true"><use href="#icon-save"/></svg>保存名称</button></div>
    </form>
</dialog>

<section class="analytics-runtime<?= $analyticsHealthy ? '' : ' warning' ?>" aria-label="分析数据运行状态"<?= $analyticsHealthy ? ' role="status"' : ' role="alert"' ?>>
    <dl>
        <div><dt>数据更新时间</dt><dd><?php if ($analyticsDataUpdatedIso !== ''): ?><time datetime="<?= e($analyticsDataUpdatedIso) ?>" data-local-time><?= e($analyticsDataUpdatedIso) ?> UTC</time><?php else: ?>暂无已聚合请求<?php endif; ?></dd></div>
        <div><dt>聚合完成时间</dt><dd><?php if ($analyticsAggregationIso !== ''): ?><time datetime="<?= e($analyticsAggregationIso) ?>" data-local-time><?= e($analyticsAggregationIso) ?> UTC</time><?php else: ?>暂无<?php endif; ?></dd></div>
        <div><dt>待处理积压</dt><dd><?= !empty($analyticsStatus['available']) ? e(format_bytes((int)($analyticsStatus['backlog_bytes'] ?? 0))) : '不可用' ?></dd></div>
        <div><dt>聚合吞吐</dt><dd><?= (int)($analyticsStatus['throughput_per_second'] ?? 0) ?> 行/秒</dd></div>
        <div><dt>任务耗时</dt><dd><?= (int)($analyticsStatus['duration_ms'] ?? 0) ?> ms</dd></div>
        <div><dt>锁等待</dt><dd><?= (int)($analyticsStatus['lock_wait_ms'] ?? 0) ?> ms</dd></div>
        <div><dt>聚合状态</dt><dd><?= e($analyticsAggregationLabel) ?></dd></div>
        <div><dt>实际数据覆盖时间</dt><dd><?= e($analyticsActualCoverage) ?></dd></div>
    </dl>
    <?php if (in_array((string)($analyticsCoverage['retention_state'] ?? ''), ['partially_pruned', 'fully_pruned'], true)): ?><p role="alert">所选范围<?= ($analyticsCoverage['retention_state'] ?? '') === 'fully_pruned' ? '全部' : '部分' ?>早于 <?= (int)($analyticsCoverage['retention_days'] ?? 365) ?> 天保留期，相关数据已清理；该部分的零值不代表零流量。</p><?php elseif ($analyticsHealthy && ($analyticsCoverage['result_state'] ?? '') === 'zero_traffic'): ?><p>所选范围在数据保留期内，当前结果为零流量。</p><?php endif; ?>
    <?php if (!$analyticsHealthy): ?><p>采集或聚合状态不完整，暂不展示流量结论；零值不可用于判断没有流量。</p><?php endif; ?>
</section>

<form class="panel analytics-filter" method="get" action="<?= e(app_path('/')) ?>" data-analytics-filter data-timezone-current="<?= e($analyticsTimezone) ?>">
    <input type="hidden" name="section" value="analytics">
    <input type="hidden" name="timezone" value="<?= e($analyticsTimezone) ?>" data-analytics-timezone>
    <div class="analytics-date-fields">
        <label>日期范围<select name="range" data-analytics-range><option value="7"<?= $analyticsRequest['preset'] === '7' ? ' selected' : '' ?>>近 7 日</option><option value="30"<?= $analyticsRequest['preset'] === '30' ? ' selected' : '' ?>>近 30 日</option><option value="90"<?= $analyticsRequest['preset'] === '90' ? ' selected' : '' ?>>近 90 日</option><option value="custom"<?= $analyticsRequest['preset'] === 'custom' ? ' selected' : '' ?>>自定义</option></select></label>
        <label data-custom-range>开始日期<input type="date" name="start" value="<?= e((string)$analyticsRequest['start']) ?>" max="<?= e((string)$analyticsRequest['end']) ?>"></label>
        <label data-custom-range>结束日期<input type="date" name="end" value="<?= e((string)$analyticsRequest['end']) ?>" min="<?= e((string)$analyticsRequest['start']) ?>"></label>
        <span class="analytics-timezone-label"><strong data-timezone-label><?= e($analyticsTimezone) ?></strong><span class="muted">浏览器时区</span></span>
    </div>
    <div class="analytics-dimension-fields">
        <label>链接<select name="link"><option value="">全部</option><?php foreach ($analyticsLinks as $linkOption): ?><option value="<?= (int)$linkOption['id'] ?>"<?= $analyticsLinkId === (int)$linkOption['id'] ? ' selected' : '' ?>><?= e((string)($linkOption['title'] !== '' ? $linkOption['title'] : '未命名') . ' · ' . (string)$linkOption['slug']) ?></option><?php endforeach; ?></select></label>
        <label>标签<select name="tag"><option value="">全部</option><?php $renderOptions((array)($analyticsFilterOptions['tags'] ?? []), (string)$analyticsFilters['tag'], $defaultLabel); ?></select></label>
        <label>活动<select name="campaign"><option value="">全部</option><?php $renderOptions((array)($analyticsFilterOptions['campaigns'] ?? []), (string)$analyticsFilters['campaign'], $defaultLabel); ?></select></label>
        <label>来源<select name="source"><option value="">全部</option><?php $renderOptions((array)($analyticsFilterOptions['sources'] ?? []), (string)$analyticsFilters['source'], $defaultLabel); ?></select></label>
        <label>媒介<select name="medium"><option value="">全部</option><?php $renderOptions((array)($analyticsFilterOptions['mediums'] ?? []), (string)$analyticsFilters['medium'], $defaultLabel); ?></select></label>
        <label>设备<select name="device"><option value="">全部</option><?php foreach (['desktop' => '桌面', 'mobile' => '手机', 'tablet' => '平板', 'other' => '其他'] as $value => $label): ?><option value="<?= $value ?>"<?= $analyticsFilters['device'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
        <label>地区<select name="country"><option value="">全部</option><?php $renderOptions((array)($analyticsFilterOptions['countries'] ?? []), (string)$analyticsFilters['country'], $defaultLabel); ?></select></label>
        <label>流量类型<select name="traffic"><option value="">全部</option><?php foreach (['suspected_human' => '疑似人工', 'automated' => '自动化', 'bot' => '机器人', 'scanner' => '安全扫描', 'unknown' => '未知'] as $value => $label): ?><option value="<?= $value ?>"<?= $analyticsFilters['traffic'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
    </div>
    <?php foreach (['referrer', 'browser', 'operating_system'] as $drillFilter): ?><?php if ((string)$analyticsFilters[$drillFilter] !== ''): ?><input type="hidden" name="<?= e($drillFilter) ?>" value="<?= e((string)$analyticsFilters[$drillFilter]) ?>"><?php endif; ?><?php endforeach; ?>
    <div class="analytics-filter-actions"><button type="submit"><svg class="icon" aria-hidden="true"><use href="#icon-filter"/></svg>应用筛选</button><a class="button button-secondary" href="<?= e(app_path('/') . '?section=analytics&timezone=' . rawurlencode($analyticsTimezone)) ?>">清除</a></div>
</form>

<?php if ($activeAnalyticsFilters): ?>
<div class="active-filter-bar" aria-label="当前活动筛选">
    <span class="muted">当前筛选</span>
    <?php foreach ($activeAnalyticsFilters as $activeFilter): ?><a class="active-filter-chip" href="<?= e($queryUrl([(string)$activeFilter['key'] => ''])) ?>" title="移除<?= e((string)$activeFilter['label']) ?>筛选"><span><?= e((string)$activeFilter['label']) ?>：<?= e((string)$activeFilter['value']) ?></span><svg class="icon" aria-hidden="true"><use href="#icon-x"/></svg><span class="sr-only">移除此条件</span></a><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$analyticsHealthy): ?>
<section class="empty analytics-unavailable" role="status"><h3>流量数据暂不可判定</h3><p class="muted">请等待积压处理完成，或先恢复分析日志采集与聚合任务。</p></section>
<?php else: ?>
<section class="analytics-metrics" aria-label="访客统计摘要">
    <div class="metric"><span>代理请求</span><strong><?= e($analyticsMetricValue($proxyRequests)) ?></strong><small>302 / 303 日志</small></div>
    <div class="metric"><span>疑似人工访问</span><strong><?= e($analyticsMetricValue((int)($analyticsTotals['suspected_human_requests'] ?? 0))) ?></strong><small><?= $analyticsZeroUncertain ? '不可判定' : $suspectedHumanRate . '%' ?></small></div>
    <div class="metric"><span>机器人</span><strong><?= e($analyticsMetricValue((int)($analyticsTotals['bot_requests'] ?? 0))) ?></strong></div>
    <div class="metric"><span>安全扫描</span><strong><?= e($analyticsMetricValue((int)($analyticsTotals['scanner_requests'] ?? 0))) ?></strong></div>
    <div class="metric"><span>未知 / 无法分类</span><strong><?= e($analyticsMetricValue((int)($analyticsTotals['unknown_requests'] ?? 0))) ?></strong><small><?= $analyticsZeroUncertain ? '不可判定' : $unknownRate . '%' ?></small></div>
    <?php if (!empty($analyticsReconciliation['available'])): ?><div class="metric"><span>差异对账</span><strong><?= e($signedValue((int)$analyticsReconciliation['difference'])) ?></strong><small>分析 <?= (int)$analyticsReconciliation['proxy_requests'] ?> · 跳转 <?= (int)$analyticsReconciliation['redirects'] ?> · <?= e($percentageValue($analyticsReconciliation['difference_percent'] ?? null, '跳转')) ?></small></div><?php endif; ?>
</section>

<section class="analytics-comparison" aria-labelledby="analytics-comparison-title">
    <div class="analytics-comparison-heading"><h3 id="analytics-comparison-title">较上一周期</h3><span class="muted"><?= e((string)$analyticsData['periods']['previous']['start']) ?> 至 <?= e((string)$analyticsData['periods']['previous']['end']) ?> · <?= e($analyticsTimezone) ?></span></div>
    <div class="analytics-comparison-grid"><?php foreach (['proxy_requests' => '代理请求', 'suspected_human_requests' => '疑似人工', 'bot_requests' => '机器人', 'scanner_requests' => '安全扫描', 'unknown_requests' => '未知 / 无法分类'] as $metric => $label): ?><?php $delta = (int)($analyticsDeltas[$metric] ?? 0); $percent = $analyticsPercentChanges[$metric] ?? null; ?><div class="metric"><span><?= e($label) ?></span><strong><?= e($signedValue($delta)) ?></strong><small>上期 <?= (int)($analyticsPreviousTotals[$metric] ?? 0) ?> · <?= e($percentageValue($percent, '上期')) ?></small></div><?php endforeach; ?></div>
</section>

<?php if (!empty($analyticsReconciliation['available'])): ?><section class="panel analytics-reconciliation-panel">
    <div class="stats-heading"><span class="section-heading"><span class="section-icon"><svg class="icon" aria-hidden="true"><use href="#icon-chart"/></svg></span><h2>差异对账</h2></span><span class="muted">所选日期范围</span></div>
    <p class="muted reconciliation-note">应用跳转来自业务数据库，代理请求来自 302 / 303 日志；聚合延迟或跳转统计写入失败会形成未对账差异。</p>
    <dl class="reconciliation-grid">
        <div><dt>GET 跳转请求</dt><dd><?= (int)($analyticsReconciliation['get_requests'] ?? 0) ?></dd></div>
        <div><dt>HEAD 请求</dt><dd><?= (int)($analyticsReconciliation['head_requests'] ?? 0) ?></dd></div>
        <div><dt>确认 POST 跳转</dt><dd><?= (int)($analyticsReconciliation['confirmation_requests'] ?? 0) ?></dd></div>
        <div><dt>历史未分类请求</dt><dd><?= (int)($analyticsReconciliation['legacy_unknown_requests'] ?? 0) ?></dd></div>
        <div><dt>总差异（代理 - 跳转）</dt><dd><?= e($signedValue((int)($analyticsReconciliation['difference'] ?? 0))) ?></dd></div>
        <div><dt>排除 HEAD 后差异</dt><dd><?= e($signedValue((int)($analyticsReconciliation['difference_excluding_head'] ?? 0))) ?></dd></div>
    </dl>
</section><?php endif; ?>

<div class="analytics-main-grid">
    <section class="panel analytics-trend-panel">
        <div class="stats-heading"><span class="section-heading"><span class="section-icon"><svg class="icon" aria-hidden="true"><use href="#icon-chart"/></svg></span><h2>请求趋势</h2></span><span class="muted"><?= e($analyticsTimezone) ?> 自然日 · 日归档按 UTC 口径</span></div>
        <div class="analytics-trend" role="region" aria-label="请求趋势" tabindex="0"><?php foreach ($analyticsTrend as $row): ?><?php $suspected = (int)$row['suspected_human_requests']; $automated = (int)$row['automated_requests']; $unknown = (int)$row['unknown_requests']; ?><a class="analytics-trend-row" href="<?= e($queryUrl(['range' => 'custom', 'start' => $row['accessed_on'], 'end' => $row['accessed_on']])) ?>"><time datetime="<?= e((string)$row['accessed_on']) ?>"><?= e(substr((string)$row['accessed_on'], 5)) ?></time><span class="stacked-progress" role="img" aria-label="疑似人工 <?= $suspected ?>，自动化 <?= $automated ?>，未知 <?= $unknown ?>"><svg viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect class="trend-suspected" x="0" y="0" width="<?= $barValue($suspected, $analyticsTrendMax) ?>" height="10"/><rect class="trend-automated" x="<?= $barValue($suspected, $analyticsTrendMax) ?>" y="0" width="<?= $barValue($automated, $analyticsTrendMax) ?>" height="10"/><rect class="trend-unknown" x="<?= $barValue($suspected + $automated, $analyticsTrendMax) ?>" y="0" width="<?= $barValue($unknown, $analyticsTrendMax) ?>" height="10"/></svg></span><strong><?= (int)$row['proxy_requests'] ?></strong></a><?php endforeach; ?></div>
        <div class="analytics-legend"><span class="suspected">疑似人工</span><span class="automated">自动化</span><span class="unknown">未知</span></div>
    </section>
    <section class="panel activity-panel">
        <div class="stats-heading"><span class="section-heading"><span class="section-icon"><svg class="icon" aria-hidden="true"><use href="#icon-chart"/></svg></span><h2>活跃时段</h2></span><span class="muted">活跃时段仅覆盖最近 <?= (int)($analyticsCoverage['hourly_retention_days'] ?? 90) ?> 天小时数据 · <?= e($analyticsTimezone) ?></span></div>
        <div class="activity-hours" aria-label="小时请求分布"><?php foreach ($analyticsHours as $hour => $clicks): ?><?php $height = (int)$clicks > 0 ? max(2, (float)$barValue((int)$clicks, $analyticsHourMax)) : 0; ?><div aria-label="<?= sprintf('%02d:00，%d 次请求', $hour, $clicks) ?>"><span class="activity-count" aria-hidden="true"><?= (int)$clicks ?></span><span class="activity-bar"><svg viewBox="0 0 10 100" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect class="activity-fill" x="0" y="<?= 100 - $height ?>" width="10" height="<?= $height ?>"/></svg></span><strong><?= sprintf('%02d', $hour) ?></strong></div><?php endforeach; ?></div>
    </section>
</div>

<section class="panel analytics-ranking-panel">
    <div class="stats-heading"><span class="section-heading"><span class="section-icon"><svg class="icon" aria-hidden="true"><use href="#icon-chart"/></svg></span><h2>链接表现排行</h2></span><span class="muted">当前筛选范围</span></div>
    <div class="analytics-ranking-grid"><?php foreach ($rankingDefinitions as $key => [$title, $valueLabel]): ?><?php $rankingRows = (array)($analyticsRankings[$key] ?? []); ?><section><h3><?= e($title) ?></h3><?php if (!$rankingRows): ?><div class="empty compact-empty">暂无符合条件的链接。</div><?php else: ?><ol><?php foreach ($rankingRows as $row): ?><li><a href="<?= e($queryUrl(['link' => (int)$row['link_id']])) ?>"><span><strong><?= e((string)($row['title'] !== '' ? $row['title'] : $row['slug'])) ?></strong><code><?= e((string)$row['slug']) ?></code></span><b><?= e($valueLabel($row)) ?></b></a></li><?php endforeach; ?></ol><?php endif; ?></section><?php endforeach; ?></div>
</section>

<section class="panel analytics-profile-panel">
    <div class="stats-heading"><span class="section-heading"><span class="section-icon"><svg class="icon" aria-hidden="true"><use href="#icon-globe"/></svg></span><h2>流量画像</h2></span><span class="muted">点击维度可继续下钻</span></div>
    <div class="analytics-breakdown-grid">
        <?php $renderBreakdown('设备', 'device', (array)$analyticsData['devices'], $defaultLabel); ?>
        <?php $renderBreakdown('浏览器', 'browser', (array)$analyticsData['browsers'], $defaultLabel); ?>
        <?php $renderBreakdown('操作系统', 'operating_system', (array)$analyticsData['operating_systems'], $defaultLabel); ?>
        <?php $renderBreakdown('国家 / 地区', 'country', (array)$analyticsData['countries'], $defaultLabel); ?>
        <?php $renderBreakdown('来源域名', 'referrer', (array)$analyticsData['referrers'], $defaultLabel); ?>
    </div>
</section>

<section class="panel campaign-report-panel">
    <div class="stats-heading"><span class="section-heading"><span class="section-icon"><svg class="icon" aria-hidden="true"><use href="#icon-chart"/></svg></span><h2>活动归因</h2></span><span class="muted">按访问发生时的活动快照汇总</span></div>
    <?php $campaignRows = (array)$analyticsData['campaigns']; if (!$campaignRows): ?><div class="empty">当前条件暂无活动请求。</div><?php else: ?><div class="campaign-table-wrap"><table class="campaign-table"><thead><tr><th>活动</th><th>来源 / 媒介</th><th>内容</th><th>代理请求</th><th>疑似人工</th><th>机器人</th><th>扫描</th><th>未知</th></tr></thead><tbody><?php foreach ($campaignRows as $row): ?><tr><td data-label="活动"><a href="<?= e($queryUrl(['campaign' => (string)$row['campaign_name']])) ?>"><strong><?= e((string)($row['campaign_name'] !== '' ? $row['campaign_name'] : '未命名活动')) ?></strong></a></td><td data-label="来源 / 媒介"><?= e((string)($row['campaign_source'] !== '' ? $row['campaign_source'] : '-')) ?> / <?= e((string)($row['campaign_medium'] !== '' ? $row['campaign_medium'] : '-')) ?></td><td data-label="内容"><?= e((string)($row['campaign_content'] !== '' ? $row['campaign_content'] : '-')) ?></td><td data-label="代理请求"><?= (int)$row['proxy_requests'] ?></td><td data-label="疑似人工"><?= (int)$row['suspected_human_requests'] ?></td><td data-label="机器人"><?= (int)$row['bot_requests'] ?></td><td data-label="扫描"><?= (int)$row['scanner_requests'] ?></td><td data-label="未知"><?= (int)$row['unknown_requests'] ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php endif; ?>
