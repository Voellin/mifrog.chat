# ADR-001: Feishu 接入边界 — CLI vs HTTP 决策记录

**日期**: 2026-04-05
**状态**: 已采纳
**决策者**: Lin

## 背景

Mifrog 通过两种方式与飞书 API 交互：
1. **飞书 CLI（lark-cli）**：通过 `FeishuCliClient` 调用本地二进制
2. **HTTP 直连**：通过 `FeishuService::requestJson()` 直接调用飞书 Open API

当前代码中 `requestJson()` 已自动路由：Bot 类调用优先走 CLI，User-token 调用始终走 HTTP。但这一边界未被文档化，新增功能时容易混淆。

## 决策

### 强制使用 CLI 的场景

| 场景 | 理由 |
|------|------|
| **所有 Bot 身份 API 调用**（消息发送、反应、卡片、组织同步） | CLI 自动管理 tenant_access_token 刷新，无需手动缓存；并发控制由文件锁保障 |
| **Device Flow 授权**（initiateDeviceFlow / completeDeviceFlow） | CLI 原生支持 `auth login --no-wait / --device-code`，无需实现轮询逻辑 |
| **文件下载**（downloadMessageResource） | CLI 的 `--output` flag 直接写文件，避免 PHP 内存峰值 |

### 保留 HTTP 直连的场景

| 场景 | 理由 |
|------|------|
| **OAuth token 交换 / 刷新**（exchangeUserAccessTokenByCode / refreshUserAccessToken） | 需要 client_secret，且是标准 OAuth2 流程，CLI 无原生支持 |
| **所有 User-token API 调用**（日程、任务、文档 CRUD） | CLI 的 `--as user` 需要用户完成登录流程后才可用，而 Mifrog 自行管理 user_access_token 生命周期 |
| **OAuth 授权 URL 构建**（buildOauthAuthorizeUrl） | 纯字符串拼接，无需 CLI |

### 自动路由层（已实现）

`FeishuService::requestJson()` 中的路由逻辑：



此路由层是 **fallback 安全网**：即使 CLI 不可用（二进制缺失、升级中），Bot 调用自动降级为 HTTP。

### 新功能接入规则

1. **新增 Bot 类 API** → 只需通过 `requestJson()` 调用，自动走 CLI
2. **新增 User-token 类 API** → 直接 HTTP，在对应的 `*TaskService` 中使用 `requestJson()` + `authHeaders()`
3. **新增平台技能（OpenClaw executor）** → 遵循 OpenClaw 模式（LLM 参数提取 → 平台技能 API 执行），执行层复用上述规则
4. **禁止**在 PlatformSkillExecutionService 或具体 TaskService 中直接调用 `FeishuCliClient`，统一通过 `FeishuService` 路由

## CLI 可用性保障

- `FeishuCliClient::isAvailable()` 结果缓存 5 分钟
- 二进制缺失/不可执行时自动清除缓存并降级
- 并发上限通过文件锁控制（默认 10，`FEISHU_CLI_MAX_CONCURRENT` 可配）
- health check 端点：`FeishuCliClient::healthCheck()`

## 影响

- 运维需确保 `/usr/local/bin/lark-cli` 在部署时存在且可执行
- CLI 版本升级需执行 `FeishuCliClient::clearBinaryCache()`
- 如需完全禁用 CLI，设 `FEISHU_CLI_ENABLED=false`，所有调用自动降级为 HTTP
