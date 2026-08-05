# AI 使用说明

## AI 在哪些关键地方提供了帮助

- 先读作业要求，确认边界：业务系统提交请求，服务异步投递到外部 HTTP API。
- 检查现有 Laravel 项目的版本、目录约定、测试基线和队列配置，设计不脱离仓库实际情况。
- 把任务拆成 API、幂等、状态机、重试、恢复命令和人工重放几部分。
- 根据现有代码生成迁移、模型、Form Request、Resource、Job 和 Pest 测试，再结合 PHPStan、Pint 和测试结果逐步调整。
- 区分 `Idempotency-Key` 和 `Request-ID`：前者用于防止 POST 重试重复创建，后者用于链路追踪。

## AI 给出过哪些你没有采纳的建议

- 一开始考虑先使用 Laravel 默认的数据库队列，以减少环境依赖。确认本机有 `phpredis` 和 Redis 服务后，改成 Redis 队列，数据库继续保存事实数据。
- 曾考虑把模型命名为 `OutboundNotification`。这个名字容易和 Laravel 的 `Notification` 概念混在一起，所以改用了 `Outbound`。
- Horizon 暂时没有加入。当前 MVP 只有一个投递队列，先用原生 Worker 和恢复命令；等有队列监控或多队列治理需求后再引入。

## 哪些关键决策是你自己做出的，以及原因

- 执行 `php artisan install:api` 并使用 Sanctum。这个 API 只给内部系统使用，所以采用 Bearer Token，再用 ability 区分调用权限。
- 用受控的 `outbounds:issue-token` Artisan 命令签发服务 Token。不做用户名密码登录或匿名申请；命令支持显式创建服务账号，也可以选择 `outbounds:create`、`outbounds:read`、`outbounds:replay` abilities。Token 只显示一次，交给 Secret Manager 保存。
- 选择 `jiannei/laravel-response` 统一 API JSON 响应结构，并用测试覆盖 202、401、409、422 等响应。它和 Laravel 13 的兼容性，以当前锁定版本和 CI 结果为准。
- 状态使用 `OutboundStatus`，不使用泛化的 `NotificationStatus`。状态只保留 `queued`、`processing`、`succeeded`、`failed`；重试次数和下一次执行时间放在字段里，不额外增加 `retrying` 状态。
- 入口幂等使用 `Idempotency-Key`，同时保留内部 ULID，并把 `X-Outbound-ID` 发给外部服务。`Request-ID` 只用于链路追踪，不承担幂等职责。
- 接受 at-least-once 语义，并把外部重复副作用的边界写清楚：服务能防止入口重复创建，但不能替不支持幂等的外部系统保证 exactly-once。
