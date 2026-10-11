-- Runs once, when the mysql-data volume is empty (the first start, or after `down -v`).
CREATE DATABASE IF NOT EXISTS `tenancey` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `tenancey_testing` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
