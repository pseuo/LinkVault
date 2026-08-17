<section class="panel stats-panel" data-overview-content data-recent-clicks-total="<?= $recentClicksTotal ?>">
    <div class="stats-heading">
        <span class="section-heading"><span class="section-icon"><svg class="icon"><use href="#icon-chart"/></svg></span><h2>当前视图近 14 日跳转统计</h2></span>
        <span class="muted">按 UTC 自然日聚合，累计 <?= $recentClicksTotal ?> 次</span>
    </div>
    <?php $trendMax = max(1, ...array_map(static fn (array $stat): int => (int)$stat['clicks'], $dailyStats)); ?>
    <div class="dashboard-trend" aria-label="近 14 日跳转趋势">
        <?php foreach ($dailyStats as $stat): ?>
            <div class="trend-row"><time datetime="<?= e((string)$stat['accessed_on']) ?>"><?= e(substr((string)$stat['accessed_on'], 5)) ?></time><progress max="<?= $trendMax ?>" value="<?= (int)$stat['clicks'] ?>"><?= (int)$stat['clicks'] ?></progress><strong><?= (int)$stat['clicks'] ?></strong></div>
        <?php endforeach; ?>
    </div>
    <div class="stats-grid">
        <section aria-labelledby="popular-title">
            <h3 id="popular-title">热门链接</h3>
            <?php if (!$popularLinks): ?><div class="empty compact-empty">当前范围暂无点击。</div><?php else: ?><ol class="rank-list"><?php foreach ($popularLinks as $popular): ?><li><a href="<?= e(app_path('/link') . '?id=' . (int)$popular['id']) ?>"><?= e((string)($popular['title'] !== '' ? $popular['title'] : $popular['slug'])) ?></a><strong><?= (int)$popular['recent_clicks'] ?></strong></li><?php endforeach; ?></ol><?php endif; ?>
        </section>
        <section aria-labelledby="distribution-title">
            <h3 id="distribution-title">状态分布</h3>
            <?php if (!$statusDistribution): ?><div class="empty compact-empty">回收站不计算状态分布。</div><?php else: ?><dl class="distribution-list"><?php foreach ($statusDistribution as $distributionKey => $count): ?><div><dt><a href="<?= e(list_path($search, 'active', 1, $distributionKey, (string)($sort ?? 'created_desc'), $tag, $favoritesOnly)) ?>"><?= e(match ($distributionKey) { 'active' => '启用中', 'scheduled' => '待启用', 'inactive' => '已停用', 'expired' => '已过期', default => '次数用尽' }) ?></a></dt><dd><?= (int)$count ?></dd></div><?php endforeach; ?></dl><?php endif; ?>
        </section>
        <section aria-labelledby="zero-click-title">
            <h3 id="zero-click-title">零点击链接</h3>
            <?php if (!$zeroClickLinks): ?><div class="empty compact-empty">当前范围没有零点击链接。</div><?php else: ?><ol class="rank-list zero-list"><?php foreach ($zeroClickLinks as $zeroLink): ?><li><a href="<?= e(app_path('/link') . '?id=' . (int)$zeroLink['id']) ?>"><?= e((string)($zeroLink['title'] !== '' ? $zeroLink['title'] : $zeroLink['slug'])) ?></a><time datetime="<?= e((string)$zeroLink['created_at']) ?>" data-local-time><?= e((string)$zeroLink['created_at']) ?> UTC</time></li><?php endforeach; ?></ol><?php endif; ?>
        </section>
    </div>
</section>
