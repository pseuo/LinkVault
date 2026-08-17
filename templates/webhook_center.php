<section class="panel webhook-center">
    <div class="stats-heading">
        <span class="section-heading"><span class="section-icon"><svg class="icon"><use href="#icon-share"/></svg></span><div><h2>Webhook 投递中心</h2><p class="muted">查看生命周期事件的投递状态与失败历史。</p></div></span>
        <span class="muted">最多显示最近 100 条</span>
    </div>
    <nav class="maintenance-tabs" aria-label="Webhook 状态">
        <?php foreach (['all' => '全部', 'pending' => '待投递', 'delivered' => '已送达', 'dead' => '死信'] as $key => $label): ?>
            <?php $count = $key === 'all' ? array_sum($webhookCounts) : $webhookCounts[$key]; ?>
            <a href="<?= e(app_path('/?section=webhooks' . ($key === 'all' ? '' : '&webhook_status=' . $key))) ?>"<?= $webhookStatus === $key ? ' aria-current="page"' : '' ?>><span><?= e($label) ?></span><strong><?= (int)$count ?></strong></a>
        <?php endforeach; ?>
    </nav>
    <?php if (!$webhookDeliveries): ?><div class="empty">当前状态没有投递事件。</div><?php else: ?>
    <div class="table-scroll" role="region" aria-label="Webhook 投递记录，可横向滚动" tabindex="0"><table class="webhook-table">
        <thead><tr><th>事件</th><th>状态</th><th>投递计划</th><th>最近失败</th><th>操作</th></tr></thead>
        <tbody><?php foreach ($webhookDeliveries as $delivery): ?><tr>
            <td data-label="事件"><strong><?= e((string)$delivery['event_type']) ?></strong><div><code><?= e((string)$delivery['event_id']) ?></code></div><div class="muted"><?= e((string)($delivery['title'] ?: $delivery['slug'] ?: '链接已删除')) ?></div><time class="muted" datetime="<?= e((string)$delivery['created_at']) ?>" data-local-time><?= e((string)$delivery['created_at']) ?> UTC</time></td>
            <td data-label="状态"><span class="status <?= e((string)$delivery['status']) ?>"><?= e(match ((string)$delivery['status']) { 'delivered' => '已送达', 'dead' => '死信', default => '待投递' }) ?></span><div class="muted">尝试 <?= (int)$delivery['attempts'] ?> 次 · 重放 <?= (int)$delivery['replay_count'] ?> 次</div></td>
            <td data-label="投递计划"><?php if ($delivery['status'] === 'pending'): ?><time datetime="<?= e((string)$delivery['available_at']) ?>" data-local-time><?= e((string)$delivery['available_at']) ?> UTC</time><?php elseif (!empty($delivery['delivered_at'])): ?><time datetime="<?= e((string)$delivery['delivered_at']) ?>" data-local-time><?= e((string)$delivery['delivered_at']) ?> UTC</time><?php else: ?><span class="muted">停止自动重试</span><?php endif; ?></td>
            <td data-label="最近失败"><?php if (!empty($delivery['last_error'])): ?><span class="field-error"><?= e((string)$delivery['last_error']) ?></span><?php else: ?><span class="muted">无</span><?php endif; ?><?php if ($delivery['attempt_history']): ?><details class="delivery-attempts"><summary>查看 <?= count($delivery['attempt_history']) ?> 次记录</summary><ol><?php foreach ($delivery['attempt_history'] as $attempt): ?><li><time datetime="<?= e((string)$attempt['attempted_at']) ?>" data-local-time><?= e((string)$attempt['attempted_at']) ?> UTC</time><span>#<?= (int)$attempt['attempt_number'] ?> · <?= $attempt['http_status'] === null ? '网络错误' : 'HTTP ' . (int)$attempt['http_status'] ?> · <?= (int)$attempt['duration_ms'] ?> ms</span><?php if ($attempt['error']): ?><small><?= e((string)$attempt['error']) ?></small><?php endif; ?></li><?php endforeach; ?></ol></details><?php endif; ?></td>
            <td data-label="操作"><?php if ($delivery['status'] === 'dead'): ?><form method="post" action="<?= e(app_path('/webhooks/replay')) ?>" data-confirm="确认重放这条死信？接收方应按事件 ID 去重。"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="event_id" value="<?= e((string)$delivery['event_id']) ?>"><button class="button-secondary button-small" type="submit"><svg class="icon"><use href="#icon-refresh"/></svg>重放</button></form><?php else: ?><span class="muted">无需操作</span><?php endif; ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>
