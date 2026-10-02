# 员工卫生考核系统 (Hygiene Audit System)

## 技术栈

- **Frontend**: Vue 3 + Vite + Tailwind CSS + Element Plus
- **Backend**: PHP 8 + ThinkPHP 8
- **Database**: MySQL 8.0（utf8mb4）

## 启动指南 (How to Run)

1. 确保 Docker Desktop 已启动。
2. 在项目根目录执行：`docker compose up --build`
3. 等待容器启动完成（数据库健康检查通过、后端与前端构建完成）。
4. 浏览器访问前端地址即可使用。

## 服务地址 (Services)

- **Frontend（Docker，推荐）**: **http://localhost:3000** — 执行 `docker compose up --build` 后访问此地址即可，无需再开 5173。
- **Frontend（本地 Vite 开发）**: http://localhost:5173 — 仅当需要热更新时使用，需**单独**在终端执行 `cd frontend && npm run dev`（Docker 不会启动 5173）。
- **Backend API**: http://localhost:8080
- **Database**: localhost:3306（user: root / pass: root）

### 访问不了 5173 时

- 若你只运行了 `docker compose up`：请改用 **http://localhost:3000** 访问前端，Docker 前端在 3000 端口。
- 若确实要用 5173（热更新开发）：在项目根目录新开一个终端，执行：
  ```bash
  cd frontend && npm run dev
  ```
  等终端出现 “Local: http://localhost:5173/” 后再用浏览器打开。此时需保证后端已启动（如 `docker compose up -d db backend`）。

## 测试账号与数据

- 系统通过 Seed 预置演示数据。
- **登录**：管理员端需先登录。默认账号：`admin` / `admin123`（首次登录会自动初始化密码）。
- **管理员-检查上传**：登录后打开 `/admin`，选择员工、上传问题图片（每张显示 key #1、#2… 与检查项、扣分值）、可删除单张（删除后序号自动连续）、保存后获得整改链接与二维码。
- **员工管理**：登录后打开 `/employees`，查看每名员工的 ID、token、有效期、整改链接与二维码（可点击「生成/刷新二维码」）。
- **员工端**：通过链接 `http://localhost:3000/fix?uid=2&token=<随机token>` 进入（或扫描专属二维码），无需登录，查看本人待整改项（图片对按 #key 从小到大排序）并上传整改图。
- **汇总看板**：登录后打开 `/summary`，查看各员工整改进度与对比图；问题图与整改图成对展示，同一徽章（检查项+分值）共用。

### 员工整改二维码安全机制

- 二维码链接包含 **员工 ID（uid）+ 随机 token** 两个参数，每名员工唯一；每次「生成/刷新二维码」或「重置 token」都会换发新 token（有效期 90 天），旧二维码立即失效。
- 后端按 uid + token 双键校验：篡改 URL 上的 `uid` 或 `token` 均无法通过，员工只能查看并上传**本人的**整改项；整改图必须落在本人上传目录、记录归属由服务端强制比对，无法替换成他人图片。
- token 缺失 / 无效 / 已过期 / 账号被禁用时，员工端整页提示「无法访问整改页面，请联系管理员重新生成您的专属二维码」，不展示任何他人内容。
- token 过期后：管理员在「员工管理」页点击「生成/刷新二维码」即可给员工新码。

### 已有数据库升级

若数据库已存在且缺少登录相关字段，可执行迁移脚本：

```bash
docker exec -i <mysql_container_name> mysql -uroot -proot hygiene_audit < backend/database/migrate_add_auth.sql
```

若需要为历史记录补充「检查日期」字段（用于按天编号与筛选），可执行：

```bash
docker exec -i <mysql_container_name> mysql -uroot -proot hygiene_audit < backend/database/migrate_add_check_date.sql
```

脚本会为 `records.check_date` 赋值：优先取 `created_at` 的日期部分，缺失时使用当前日期。

若已有数据库需要为员工整改 token 增加过期机制（`users.token_expires_at` 字段），可执行：

```bash
docker exec -i <mysql_container_name> mysql -uroot -proot hygiene_audit < backend/database/migrate_add_token_expiry.sql
```

脚本会为历史员工 token 补充 30 天宽限期；之后由管理员重新生成二维码换发 90 天有效的新 token。

## Docker 说明

- 数据库使用 `utf8mb4` 字符集，连接时指定 charset。
- 前端构建时通过 `VITE_API_BASE=http://localhost:8080` 指定后端地址，浏览器直接请求后端 API。
- 后端通过服务名 `db` 连接 MySQL，不依赖本地环境。
