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
- **员工管理**：登录后打开 `/employees`，查看每名员工的 ID、token、整改链接与二维码（可点击「生成/刷新二维码」）。
- **员工端**：扫码（或打开带 `uid` 与 `token` 的整改链接，如 `http://localhost:3000/fix?uid=2&token=xxxx`）进入，无需登录，查看待整改项（图片对按 #key 从小到大排序）并上传整改图。链接中的员工 ID 与 token 由后端绑定校验，篡改任何一个参数都无法看到他人内容；二维码过期/无效/账号停用时页面提示联系管理员重新生成。
- **汇总看板**：登录后打开 `/summary`，查看各员工整改进度与对比图；问题图与整改图成对展示，同一徽章（检查项+分值）共用。

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

为支持员工整改二维码的过期机制（`users.qr_token_expires`），可执行：

```bash
docker exec -i <mysql_container_name> mysql -uroot -proot hygiene_audit < backend/database/migrate_add_qr_expiry.sql
```

二维码默认有效期为 **7 天**，可通过后端环境变量 `QR_TOKEN_TTL`（秒，`0` 表示长期有效）调整。

### 二维码安全机制

- 每名员工的整改二维码内容为 `{前端地址}/fix?uid={员工ID}&token={32位随机token}`，由管理员在「员工管理」页逐个生成，员工间互不相同。
- 后端对记录查看、整改提交、图片上传三个入口统一校验 `uid + token`：必须同时匹配同一员工、账号启用且二维码未过期，且整改记录归属必须与该员工一致——员工改任何参数都无法看到或操作他人内容。
- token 被管理员重置后旧二维码立即失效；过期或无效时，员工页面阻断并提示「请联系管理员重新生成您的专属整改二维码」。

## Docker 说明

- 数据库使用 `utf8mb4` 字符集，连接时指定 charset。
- 前端构建时通过 `VITE_API_BASE=http://localhost:8080` 指定后端地址，浏览器直接请求后端 API。
- 后端通过服务名 `db` 连接 MySQL，不依赖本地环境。
