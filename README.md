# Outbound API

这是一个给业务系统使用的 Outbound HTTP 投递服务。业务系统提交请求后，服务会先保存请求，再异步调用外部 HTTP(S) 接口，并记录投递状态。遇到可以恢复的失败，系统会自动重试；必要时也可以人工重放。

## 目标与边界

- 服务接收业务系统提交的 Outbound 请求，再把它投递给外部系统。它不产生业务事件，也不处理外部供应商的业务逻辑。
- 当前 MVP 只支持 JSON payload，API 通过 Sanctum Bearer Token 保护。
- 投递语义是 at-least-once。同一个调用方重复使用 `Idempotency-Key` 时，服务不会创建多条投递记录。但如果目标接口本身不支持幂等，网络超时后的重试仍可能造成重复副作用。
- 投递请求会带上 `X-Outbound-ID`，目标系统可以用它做关联和去重。

## 技术栈

- PHP 8.3+
- Laravel 13
- Laravel Sanctum
- Redis + `phpredis`：异步队列
- SQLite：本地默认数据库；生产环境可替换为 MySQL 或 PostgreSQL
- Pest、PHPStan、Laravel Pint
- `jiannei/laravel-response`：统一 API JSON 响应结构

## 本地运行

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
redis-cli ping
php artisan queue:work redis --queue=default --timeout=30 --tries=5
```

如果本机没有 Redis，可以临时将 `.env` 中的 `QUEUE_CONNECTION` 改为 `database`。默认配置仍使用 Redis，方便按生产环境的方式运行独立 Worker。

使用下面的受控命令为对方系统签发 API Token。服务账号还不存在时，再加上 `--create-user`：

```bash
php artisan outbounds:issue-token billing-service@example.com \
  --create-user \
  --user-name="Billing Service" \
  --name=billing-client \
  --ability=outbounds:create \
  --ability=outbounds:read \
  --ability=outbounds:replay
```

命令只会在当前终端显示一次明文 Token。生产环境请立即将它保存到 Secret Manager，不要写入代码仓库或日志。之后的请求使用 `Authorization: Bearer <token>`。

## API

### 提交 Outbound

`POST /api/v1/outbounds`

请求需要 `outbounds:create` ability，还必须带上 `Idempotency-Key` 请求头：

```bash
curl -X POST http://localhost:8000/api/v1/outbounds \
  -H 'Authorization: Bearer <token>' \
  -H 'Idempotency-Key: invoice-created-1001' \
  -H 'Content-Type: application/json' \
  -d '{
    "target_url": "https://example.com/webhooks/outbounds",
    "payload": {
      "event": "invoice.created",
      "resource_id": "1001"
    }
  }'
```

成功返回 `202 Accepted`，响应包含投递 ID 和当前状态。如果重复提交相同 `Idempotency-Key` 且完整请求的 fingerprint 相同，服务会返回原投递；fingerprint 不同则返回 `409 Conflict`。fingerprint 包含 `target_url`、`http_method`、`headers` 和 `payload`。

### 查询状态

`GET /api/v1/outbounds/{outbound}`

请求需要 `outbounds:read` ability。状态包括：

| 状态 | 含义 |
| --- | --- |
| `queued` | 等待首次投递或等待下一次重试 |
| `processing` | Worker 已取得该投递，正在调用外部接口 |
| `succeeded` | 外部接口返回 2xx；这只表示 HTTP 请求被接受，不表示对方业务事务已经完成 |
| `failed` | 收到不可重试的 4xx，或重试次数耗尽 |

### 人工重放

`POST /api/v1/outbounds/{outbound}/replay`

请求需要 `outbounds:replay` ability。只有状态为 `failed` 的投递可以重放；重放会清空当前轮次的尝试计数，但会保留历史尝试记录。

## 失败与恢复

- 2xx：标记 `succeeded`。
- 408、425、429、5xx，以及连接或超时异常：保持 `queued`，按 `60s、300s、900s、1800s` 退避重试。
- 其他 4xx：标记 `failed`，不自动重试。
- HTTP 请求连接超时为 5 秒，总超时为 15 秒；Job 超时为 30 秒，Redis `retry_after` 为 90 秒，避免 Worker 尚未结束时被另一 Worker 重复领取。
- 数据库保存投递状态和幂等记录，Redis 只负责排队。Redis 暂时不可用或 Worker 异常退出时，可以运行：

```bash
php artisan outbounds:requeue-stale
```

## 开发检查

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=1G
php artisan test --tia
```

更完整的边界、状态机和取舍见 [`docs/设计文档.md`](docs/设计文档.md)，AI 协作记录见 [`docs/AI 使用说明.md`](docs/AI%20使用说明.md)。
