-- Runs automatically on a FRESH mysql_data volume only (the official MySQL
-- image executes everything in /docker-entrypoint-initdb.d/ once, the first
-- time the data directory is empty — it will NOT run again against an
-- existing volume). See CLAUDE.md's Local environment section for the
-- manual equivalent if you already have a volume from before this existed.
CREATE DATABASE IF NOT EXISTS stampy_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON stampy_testing.* TO 'attendance'@'%';
FLUSH PRIVILEGES;
