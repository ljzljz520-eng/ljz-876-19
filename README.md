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
6. 断网续考保护：断网本地暂存、恢复自动续考、监考延时/收卷裁决（见下节）。

## 断网续考保护
- **学生端**：考试中每 10 秒心跳保活；答案、题目状态（已答/标记复查）、学生端本地时间实时写入
  `localStorage`（按“学生+试卷”维度）。网络中断时显示断网提示条，学生可继续作答；恢复后自动续传
  暂存答案，倒计时以服务器时间为准并自动纠偏。
- **超时处理**：超过试卷时长（含监考批准的延时，含 120 秒提交宽限）仍提交，答案先保存不评分，
  状态置为“超时待审核”，由监考老师决定。
- **监考端（教师/管理员，菜单“断网监考”）**：
  - 查看进行中/待审核考试，统计真实断网次数与累计时长、页面刷新/离开次数、换设备次数；
  - 查看完整事件链（心跳、断网、刷新/离开、恢复、换设备、超时提交）与最近答案快照；
  - **批准延时**：追加考试秒数，学生端轮询到结果后可继续作答；
  - **按时收卷**：按最近一次暂存答案立即自动评分。
- **三种情况严格区分，均不自动判定为作弊**：
  - 真实断网：前端 `offline` 事件上报 + 心跳缺口兜底识别，标记为 `network_lost`；
  - 刷新页面/关闭标签：`pagehide` 时 `fetch keepalive` 上报 `page_leave(reason=refresh)`，短时间回来记为正常；
  - 换设备登录：按设备 ID 识别 `device_switch`，旧设备继续心跳会被锁定，频繁换设备仅提高风险等级供老师研判。
- **数据结构**：`exam_records` 增加 `device_id / extra_time_seconds / time_check_status`（状态增加
  `pending_review`）；新增 `exam_connection_events`（事件审计）、`exam_time_decisions`（监考决定）。
  新库由 `docker-compose` 内联 SQL 初始化，旧库由 db-init 中的**幂等升级 SQL**自动加列建表；
  标准 Laravel 部署可执行迁移 `2024_01_01_000001_add_offline_exam_protection.php`。

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
当前初始化后包含 12 张核心表（含用户、题目、试卷、考试记录、答案记录、连接事件、监考决定等）。

详见：
- `docs/Database.sql`
- `docker-compose.yml` 中 `db-init` 初始化段

## 证据目录
测试与质检证据统一放在 `evidence/`（含 `evidence/run-slot*/`）目录。

---
如需进行质检修复闭环，请配合 `qa/qc-feedback-inbox.md`、`qa/qc-fix-send-template.md`、`qa/qc-fix-loop-template.md` 使用。

