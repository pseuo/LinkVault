# API 错误码

所有 `/api/*` 接口的错误统一为：

```json
{"error":{"code":"invalid_parameters","message":"One or more request parameters are invalid.","request_id":"0123456789abcdef"}}
```

| HTTP | code | 含义 |
|---:|---|---|
| 400 | `invalid_json` | 请求体不是 JSON 对象 |
| 400 | `invalid_idempotency_key` | `Idempotency-Key` 为空、过长或含非法字符 |
| 401 | `invalid_token` | Bearer Token 无效 |
| 403 | `insufficient_scope` | Token 有效，但不包含当前接口所需的作用域；响应的 `WWW-Authenticate` 会指出所需作用域 |
| 403 | `source_not_allowed` | Token 有效，但客户端地址不在该 Token 配置的 CIDR 白名单内 |
| 404 | `not_found` | 链接不存在、已删除或 API 路径不存在 |
| 405 | `method_not_allowed` | 接口使用了不支持的方法 |
| 409 | `idempotency_conflict` | 同一幂等键用于不同的规范化参数 |
| 409 | `slug_exists` | 自定义短码已存在 |
| 413 | `request_too_large` | 请求体超过配置上限 |
| 412 | `precondition_failed` | `If-Match` ETag 已过期，资源在读取后发生变化 |
| 428 | `precondition_required` | 编辑、停用或删除缺少 `If-Match` |
| 409 | `link_requires_password_reset` | 导入的受保护链接必须先在管理端重新设置访问密码 |
| 415 | `unsupported_media_type` | `Content-Type` 不是 `application/json` |
| 421 | `misdirected_request` | 请求 Host 与固定服务地址不匹配 |
| 422 | `invalid_parameters` | 字段类型、格式、范围或时间关系无效 |
| 429 | `rate_limited` | 当前 Bearer Token 在业务配额窗口内的请求数已达到上限；请等待 `Retry-After` 秒后重试 |
| 500 | `internal_error` | 未预期的服务错误 |
| 503 | `api_token_not_configured` | 服务端没有可用的环境变量或数据库 API Token |
| 503 | `service_unavailable` | 数据库、Schema 或服务暂不可用 |

每次响应都包含新的 `X-Request-ID`。429 响应还包含整数秒数的 `Retry-After`。带有效 `Idempotency-Key` 的成功响应还包含 `Idempotency-Replayed: false|true`；重放返回首次成功请求保存的 HTTP 状态和响应体。

数据库 Token 可设置作用域、失效时间、独立请求配额和 IPv4/IPv6 CIDR 白名单，并在管理端轮换或吊销。轮换后的 Token 继承原配额和 CIDR 策略。已过期、已吊销及无法识别的 Token 都返回 `401 invalid_token`；无效 Token 不消耗任何合法 Token 的业务配额。`DELETE /api/links/{id}` 只移入回收站。可归属到数据库 Token 的鉴权尝试会出现在管理端使用记录中，未认证流量应由边缘代理按 IP 限速。
