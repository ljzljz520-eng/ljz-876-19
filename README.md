# 在线考试题库系统（876）

## 项目类型
- 全栈 Web 项目（`frontend` + `backend`）

## 项目简介
本项目是一个基于 Vue 3 + Laravel 12 的在线考试与题库管理系统，支持多角色登录、题库管理、试卷管理、在线考试与成绩统计。

## 技术栈
### 前端
- Vue 3
- Vite
- Pinia
- Vue Router
- Axios
- TailwindCSS

### 后端
- Laravel 12（PHP 8.2）
- Laravel Sanctum（Token 鉴权）
- MySQL 8.0

### 运行方式
- Docker Compose（推荐，当前项目默认方式）

## 目录结构
```text
876/
├── docker-compose.yml
├── README.md
├── frontend/
│   ├── Dockerfile
│   ├── nginx.conf
│   ├── package.json
│   └── src/
├── backend/
│   ├── Dockerfile
│   ├── composer.json
│   ├── app/
│   └── routes/
├── docs/
│   ├── ARCHITECTURE.md
│   └── Database.sql
├── scripts/
└── evidence/
```

说明：`node_modules/`、`vendor/` 等依赖目录由 Docker 构建时自动安装，不需要打包提交。

## 启动与重建
在仓库根目录执行：

```bash
docker compose down
docker compose up -d --build
docker compose ps
```

## 服务地址
| 服务 | 地址 | 说明 |
|---|---|---|
| 前端 | http://localhost:8080 | 用户界面 |
| 后端 API | http://localhost:9000/api | Laravel API |
| MySQL | localhost:3307 | 数据库端口映射 |

## 测试账号
| 角色 | 邮箱 | 密码 |
|------|-------|----------|
| Admin | admin@example.com | password |
| Teacher | teacher@example.com | password |
| Student | student1@example.com | password |

> 登录页已移除快捷测试账号模块，请手动输入账号密码。

## README 与测试账号清单同步（必跑）
在截图前、提交前执行以下命令：

```bash
node scripts/sync-readme-test-credentials.mjs --manifest qa/.runtime/test-credentials.current.json --readme README.md
node scripts/verify-readme-test-credentials.mjs --manifest qa/.runtime/test-credentials.current.json --readme README.md
```

阻断规则：任一命令失败都应视为 `README_TEST_CREDENTIALS_MISMATCH`，不得继续提交流程。

## 核心功能
1. 用户认证：注册、登录、退出。
2. 题库管理：题目增删改查、分类管理。
3. 试卷管理：试卷创建、编辑、题目关联。
4. 在线考试：开始考试、提交答卷、自动评分。
5. 成绩统计：个人成绩与管理端统计数据。
6. **断网续考保护**：考试中断网可继续作答，恢复后自动同步；超时由监考老师决定是否延时。

## 断网续考保护说明
设计目标：学生断网不丢答案、不误判作弊；后台能区分「真实断网 / 刷新页面 / 换设备登录」。

### 学生端
- 考试中答案、题目状态、本地/服务端时间锚点实时暂存到浏览器 `localStorage`，断网或崩溃后仍可继续。
- 每 15s 心跳、每 20s 自动保存；倒计时以**服务端截止时间**为准（防止改本地时钟）。
- 断网时显示黄色横幅，可继续作答；恢复网络后自动 `resume`：补报离线事件 → 批量同步答案 → 继续考试。
- 到点断网未提交：答案暂存本机，恢复后自动补交。
- 超过允许时长（含 120 秒宽限期）：进入「待监考处理」页，每 10s 轮询老师审批结果；批准延时后自动续考，被收卷则跳转成绩页。
- 刷新页面或同一考试重新进入，均通过 `/resume` 恢复现场，不重复开考。

### 后台甄别（不自动判作弊）
- `exam_events` 事件表记录：心跳、断网开始、网络恢复、页面隐藏/可见、刷新、设备变更、答案同步、交卷、超时、监考审批等。
- 通过持久化设备指纹（`device_id`）+ 心跳缺口 + 事件对，系统给出参考分类：
  - `network_outage` 真实断网：`offline_detected/reconnect` 成对，时长与心跳缺口吻合；
  - `page_refresh` 刷新页面：设备指纹不变、短时心跳缺失；
  - `device_switch` 换设备登录：设备指纹变化；
  - `suspicious` 心跳超时且无断网/刷新事件可解释。
- 监考中心（教师/管理员）：查看进行中与待处理考试、事件时间线；可**批准延时**（1–300 分钟）或**终止收卷**（按已保存答卷判分）。教师仅能处理本人创建试卷的考试。

### 关键接口
| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/api/exams/{paper}/resume` | 刷新/断网恢复/换设备后恢复考试现场 |
| POST | `/api/exams/{paper}/heartbeat` | 在线心跳（约 15s） |
| POST | `/api/exams/{paper}/events` | 事件上报（离线事件缓存后补报） |
| POST | `/api/exams/{paper}/sync-answers` | 在线自动保存 / 恢复后答案同步 |
| POST | `/api/exams/{paper}/submit` | 交卷（超时返回 202 待监考处理） |
| GET | `/api/monitoring/records` | 监考列表（含甄别摘要） |
| GET | `/api/monitoring/records/{record}` | 考试事件时间线 |
| POST | `/api/monitoring/records/{record}/extend` | 监考批准延时 |
| POST | `/api/monitoring/records/{record}/terminate` | 监考终止并收卷判分 |

## 角色权限
| 角色 | 可访问模块 |
|---|---|
| Student | 在线考试、我的成绩 |
| Teacher | 在线考试、我的成绩、题库管理、试卷管理 |
| Admin | 全部功能（含数据统计） |

## 人工验证步骤（建议）
1. 打开登录页：`http://localhost:8080/login`。
2. 使用测试账号手动登录，确认菜单与角色权限一致。
3. 进入题库管理，验证新增/编辑/删除流程。
4. 进入试卷管理，验证题目关联与试卷删除流程。
5. 学生账号完成一次在线考试并查看成绩。
6. Admin 查看统计页数据。
7. API 冒烟：

```bash
docker compose exec backend sh -lc "curl -s -o /tmp/unauth.txt -w '%{http_code}\n' http://localhost:8080/api/exams"
docker compose exec backend sh -lc "curl -s -X POST http://localhost:8080/api/auth/login -H 'Content-Type: application/json' -d '{\"email\":\"admin@example.com\",\"password\":\"password\"}'"
```

预期：未登录访问受保护接口返回 `401`；登录接口返回包含 `token` 的 JSON。

## 安全与质量说明
- 密码为哈希存储（bcrypt）。
- API 使用 Sanctum Token 鉴权。
- 接口包含输入校验与错误处理。
- CORS 与基础限流已配置。

## 数据库说明
当前初始化后包含 11 张核心表（含用户、题目、试卷、考试记录、答案记录、考试事件等）。

断网续考相关结构：
- `exam_records` 新增 `deadline_at / last_heartbeat_at / offline_total / device_id / grace_until / review_reason / reviewed_by / reviewed_at / review_remark`，`status` 增加 `awaiting_review`、`terminated`。
- `exam_record_answers` 新增 `client_updated_at / synced_at / meta`，并对 `(exam_record_id, question_id)` 加唯一键以支持 upsert。
- 新增 `exam_events` 考试过程事件表。

> 已部署环境升级：后端容器启动时会自动执行 `php artisan migrate --force`（迁移幂等）；
> 全新环境则由 `docker-compose.yml` 内联 SQL 直接建出最新结构。

详见：
- `docs/Database.sql`
- `docker-compose.yml` 中 `db-init` 初始化段

## 证据目录
测试与质检证据统一放在 `evidence/`（含 `evidence/run-slot*/`）目录。

---
如需进行质检修复闭环，请配合 `qa/qc-feedback-inbox.md`、`qa/qc-fix-send-template.md`、`qa/qc-fix-loop-template.md` 使用。

