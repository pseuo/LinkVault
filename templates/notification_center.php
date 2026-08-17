<div class="status-title"><div><h2>通知中心</h2><p class="muted">汇总需要管理员处理的举报、投递和运行异常。</p></div><span class="status <?= $adminNotifications['unread'] > 0 ? 'off' : 'active' ?>"><?= (int)$adminNotifications['unread'] ?> 条未读</span></div>
<section class="panel notification-center">
    <?php if (!$adminNotifications['items']): ?>
        <div class="empty compact-empty">当前没有待处理通知。</div>
    <?php else: ?>
        <ol class="notification-list">
            <?php foreach ($adminNotifications['items'] as $notification): ?>
                <li class="notification-item notification-<?= e((string)$notification['severity']) ?><?= $notification['read_at'] === null ? ' is-unread' : '' ?>">
                    <div class="notification-content"><div class="notification-heading"><strong><?= e((string)$notification['title']) ?></strong><span class="status <?= e((string)$notification['severity']) ?>"><?= e((string)$notification['severity'] === 'error' ? '异常' : '关注') ?></span></div><p><?= e((string)$notification['body']) ?></p><time datetime="<?= e((string)$notification['created_at']) ?>" data-local-time><?= e((string)$notification['created_at']) ?> UTC</time></div>
                    <div class="notification-actions">
                        <?php if (is_string($notification['action_url']) && $notification['action_url'] !== ''): ?><a class="button button-secondary button-small" href="<?= e((string)$notification['action_url']) ?>">查看</a><?php endif; ?>
                        <?php if ($notification['read_at'] === null): ?><form method="post" action="<?= e(app_path('/notifications/read')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$notification['id'] ?>"><button class="button-secondary button-small" type="submit">标记已读</button></form><?php endif; ?>
                        <form method="post" action="<?= e(app_path('/notifications/dismiss')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$notification['id'] ?>"><button class="button-secondary button-small" type="submit">忽略</button></form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
