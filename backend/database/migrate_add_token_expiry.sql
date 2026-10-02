-- 为员工整改 token 增加过期时间（可重复执行，缺啥补啥）
-- 执行: docker exec -i <mysql_container> mysql -uroot -proot hygiene_audit < backend/database/migrate_add_token_expiry.sql
SET NAMES utf8mb4;
USE hygiene_audit;

SET @db = DATABASE();

-- token_expires_at: 员工整改 token 的过期时间；NULL 表示历史数据（由业务按宽限期处理）
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'token_expires_at') = 0,
  'ALTER TABLE `users` ADD COLUMN `token_expires_at` datetime DEFAULT NULL AFTER `token`',
  'SELECT 1'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 历史数据：给没有过期时间的员工 token 一个宽限期（30 天），到期后需管理员重新生成
UPDATE `users`
SET `token_expires_at` = DATE_ADD(NOW(), INTERVAL 30 DAY)
WHERE `role` = 'employee' AND `token_expires_at` IS NULL;
