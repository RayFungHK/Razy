-- ============================================================
-- Benchmark Database Schema & Seed Data
-- Shared between Razy and Laravel benchmark targets.
-- ============================================================

CREATE DATABASE IF NOT EXISTS benchmark
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE benchmark;

-- ── Posts table (Scenario 3 & 5: read) ──────────────────────
CREATE TABLE IF NOT EXISTS benchmark_posts (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title      VARCHAR(255) NOT NULL,
    body       TEXT         NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Logs table (Scenario 4: write) ──────────────────────────
CREATE TABLE IF NOT EXISTS benchmark_logs (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message    VARCHAR(500)  NOT NULL,
    level      VARCHAR(20)   NOT NULL DEFAULT 'info',
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_level (level),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed 1 000 posts ────────────────────────────────────────
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS seed_benchmark_posts()
BEGIN
    DECLARE i INT DEFAULT 1;
    WHILE i <= 1000 DO
        INSERT INTO benchmark_posts (title, body, created_at)
        VALUES (
            CONCAT('Benchmark Post #', i),
            CONCAT('This is the body content for post number ', i, '. ',
                   REPEAT('Lorem ipsum dolor sit amet. ', 10)),
            DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 365) DAY)
        );
        SET i = i + 1;
    END WHILE;
END //
DELIMITER ;

-- Only seed if table is empty
SELECT COUNT(*) INTO @post_count FROM benchmark_posts;
SET @seed_needed = IF(@post_count = 0, 'CALL seed_benchmark_posts();', 'SELECT "Posts already seeded" AS status;');
PREPARE seed_stmt FROM @seed_needed;
EXECUTE seed_stmt;
DEALLOCATE PREPARE seed_stmt;

-- ── Grant access ────────────────────────────────────────────
CREATE USER IF NOT EXISTS 'benchmark'@'%' IDENTIFIED BY 'benchmark';
GRANT ALL PRIVILEGES ON benchmark.* TO 'benchmark'@'%';
FLUSH PRIVILEGES;
