-- 为员工整改二维码 token 增加过期时间（可重复执行）
-- 执行示例：
-- docker compose exec -T db mysql -uroot -proot hygiene_audit < backend/database/migrate_add_qr_expiry.sql
SET NAMES utf8mb4;
USE hygiene_audit;

SET @db = DATABASE();

-- NULL 表示尚未生成过二维码（或刚重置），token 不参与二维码校验
-- 有值时：当前时间超过该时间即判定二维码过期，需管理员重新生成
SET @sql = (
  SELECT IF(
    (SELECT COUNT(*)
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db
       AND TABLE_NAME = 'users'
       AND COLUMN_NAME = 'qr_token_expires') = 0,
    'ALTER TABLE `users` ADD COLUMN `qr_token_expires` datetime DEFAULT NULL AFTER `token`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 为 token 补充索引（按 token 查用户是高频操作；若已是唯一键则跳过）
SET @sql = (
  SELECT IF(
    (SELECT COUNT(*)
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db
       AND TABLE_NAME = 'users'
       AND INDEX_NAME = 'token') = 0,
    'ALTER TABLE `users` ADD KEY `token` (`token`)',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
