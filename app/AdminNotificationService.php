<?php

declare(strict_types=1);

final class AdminNotificationService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function sync(): void
    {
        $openReports = (int)$this->pdo->query("SELECT COUNT(*) FROM abuse_reports WHERE status IN ('open', 'reviewing')")->fetchColumn();
        if ($openReports > 0) {
            $this->ensure('open_report', 'attention', '有待处理举报', '存在 ' . $openReports . ' 条公开举报需要审核。', 'trust');
        } else {
            $this->resolve('open_report');
        }
        $deadWebhooks = (int)$this->pdo->query("SELECT COUNT(*) FROM webhook_outbox WHERE status = 'dead'")->fetchColumn();
        if ($deadWebhooks > 0) {
            $this->ensure('dead_webhook', 'error', 'Webhook 投递进入死信', $deadWebhooks . ' 个生命周期事件投递失败。', 'webhooks');
        } else {
            $this->resolve('dead_webhook');
        }
        $failures = (int)$this->pdo->query("SELECT COUNT(*) FROM audit_events WHERE outcome = 'failure' AND created_at >= datetime('now', '-1 day')")->fetchColumn();
        if ($failures > 0) {
            $this->ensure('recent_failures', 'attention', '最近有操作失败', '过去 24 小时记录了 ' . $failures . ' 次失败操作，请检查审计记录。', 'audit');
        } else {
            $this->resolve('recent_failures');
        }
    }

    /** @return array{items: list<array<string, mixed>>, unread: int} */
    public function inbox(int $limit = 50): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT * FROM admin_notifications
            WHERE dismissed_at IS NULL
            ORDER BY CASE severity WHEN 'error' THEN 0 WHEN 'attention' THEN 1 ELSE 2 END,
                     read_at IS NOT NULL, created_at DESC, id DESC
            LIMIT :limit
        SQL);
        $statement->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $statement->execute();
        $unread = (int)$this->pdo->query('SELECT COUNT(*) FROM admin_notifications WHERE dismissed_at IS NULL AND read_at IS NULL')->fetchColumn();
        return ['items' => $statement->fetchAll(), 'unread' => $unread];
    }

    public function markRead(int $id): bool
    {
        $statement = $this->pdo->prepare('UPDATE admin_notifications SET read_at = :now WHERE id = :id AND dismissed_at IS NULL AND read_at IS NULL');
        $statement->execute(['now' => utc_timestamp(), 'id' => $id]);
        return $statement->rowCount() === 1;
    }

    public function dismiss(int $id): bool
    {
        $statement = $this->pdo->prepare('UPDATE admin_notifications SET dismissed_at = :now, read_at = COALESCE(read_at, :now) WHERE id = :id AND dismissed_at IS NULL');
        $statement->execute(['now' => utc_timestamp(), 'id' => $id]);
        return $statement->rowCount() === 1;
    }

    private function ensure(string $type, string $severity, string $title, string $body, string $section): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO admin_notifications (
                notification_key, notification_type, severity, title, body, action_url, created_at
            ) VALUES (:key, :type, :severity, :title, :body, :action_url, :created_at)
            ON CONFLICT(notification_key) DO UPDATE SET
                severity = excluded.severity, title = excluded.title, body = excluded.body,
                action_url = excluded.action_url
        SQL);
        $statement->execute([
            'key' => $type,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'body' => $body,
            'action_url' => app_path('/?section=' . $section),
            'created_at' => utc_timestamp(),
        ]);
    }

    private function resolve(string $type): void
    {
        $statement = $this->pdo->prepare('UPDATE admin_notifications SET dismissed_at = :now WHERE notification_key = :key AND dismissed_at IS NULL');
        $statement->execute(['now' => utc_timestamp(), 'key' => $type]);
    }
}
