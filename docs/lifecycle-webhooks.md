# 生命周期 Webhook

配置 `LINKVAULT_LIFECYCLE_WEBHOOK_URL`、至少 32 个字符的 `LINKVAULT_LIFECYCLE_WEBHOOK_SIGNING_SECRET`，以及可选的 Bearer Token 后，系统会异步投递以下事件：

- `link.created`
- `link.expiring`
- `link.disabled`
- `link.target_unhealthy`

事件先写入 SQLite `webhook_outbox`，不会在链接写事务内发起网络请求。每分钟任务租约并发送最多 50 条，成功状态为 2xx；失败使用退避重试，8 次失败后进入 `dead`。接收方必须使用 `event_id` 去重，因为投递语义是至少一次。

请求包含 `X-LinkVault-Event-ID`、`X-LinkVault-Event-Type`、`X-LinkVault-Timestamp` 和 `X-LinkVault-Signature: v1=<hex>`。签名输入为：

```text
timestamp + "." + event_id + "." + raw_request_body
```

事件载荷只包含链接 ID、短码、标题、短链地址、过期时间、启用状态和目标主机，不包含完整目标 URL、密码、哈希、Token 或访问者标识。接收方应限制时间窗口、使用常量时间比较签名，并在业务处理前完成去重。
