-- Tournament System Database Migration
-- Created: 2026-05-05
-- Description: Creates all tournament-related tables and indexes

-- ============================================
-- TOURNAMENTS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `tournaments` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `bracket_type` ENUM('single_elimination', 'double_elimination', 'round_robin', 'swiss') DEFAULT 'single_elimination',
    `status` ENUM('draft', 'registration_open', 'registration_closed', 'active', 'completed', 'cancelled') DEFAULT 'draft',
    `max_players` INT,
    `current_players` INT DEFAULT 0,
    `min_players` INT DEFAULT 2,
    `start_date` DATETIME,
    `end_date` DATETIME,
    `registration_close_date` DATETIME,
    `location` VARCHAR(255),
    `featured` BOOLEAN DEFAULT FALSE,
    `point_multiplier` DECIMAL(2,1) DEFAULT 1.0,
    `created_by` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_bracket_type` (`bracket_type`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TOURNAMENT PLAYERS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `tournament_players` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `tournament_id` INT NOT NULL,
    `player_id` INT NOT NULL,
    `seed` INT,
    `current_round` INT DEFAULT 0,
    `status` ENUM('registered', 'active', 'eliminated', 'withdrawn', 'disqualified') DEFAULT 'registered',
    `joined_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_player_tournament` (`tournament_id`, `player_id`),
    INDEX `idx_player_id` (`player_id`),
    INDEX `idx_tournament_id` (`tournament_id`),
    FOREIGN KEY (`tournament_id`) REFERENCES `tournaments`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`player_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TOURNAMENT MATCHES TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `tournament_matches` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `tournament_id` INT NOT NULL,
    `round` INT NOT NULL,
    `bracket_position` INT,
    `player1_id` INT,
    `player2_id` INT,
    `winner_id` INT,
    `loser_id` INT,
    `score1` INT,
    `score2` INT,
    `status` ENUM('pending', 'in_progress', 'completed', 'disputed', 'cancelled') DEFAULT 'pending',
    `match_type` ENUM('winners', 'losers', 'grand_final', 'third_place') DEFAULT 'winners',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_tournament_id` (`tournament_id`),
    INDEX `idx_round` (`round`),
    INDEX `idx_winner_id` (`winner_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_player1_id` (`player1_id`),
    INDEX `idx_player2_id` (`player2_id`),
    FOREIGN KEY (`tournament_id`) REFERENCES `tournaments`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`player1_id`) REFERENCES `users`(`id`),
    FOREIGN KEY (`player2_id`) REFERENCES `users`(`id`),
    FOREIGN KEY (`winner_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- LEADERBOARD TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `leaderboard` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `player_id` INT NOT NULL,
    `season` DATE NOT NULL,
    `ranking` INT,
    `points` INT DEFAULT 0,
    `tournaments_played` INT DEFAULT 0,
    `wins` INT DEFAULT 0,
    `losses` INT DEFAULT 0,
    `win_percentage` DECIMAL(5,2) DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_player_season` (`player_id`, `season`),
    INDEX `idx_player_id` (`player_id`),
    INDEX `idx_ranking` (`ranking`),
    INDEX `idx_season` (`season`),
    INDEX `idx_points` (`points` DESC),
    FOREIGN KEY (`player_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TOURNAMENT SCORES TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `tournament_scores` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `tournament_id` INT NOT NULL,
    `player_id` INT NOT NULL,
    `placement` INT,
    `points_earned` INT DEFAULT 0,
    `matches_won` INT DEFAULT 0,
    `matches_lost` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_tournament_id` (`tournament_id`),
    INDEX `idx_player_id` (`player_id`),
    INDEX `idx_placement` (`placement`),
    UNIQUE KEY `unique_tournament_player` (`tournament_id`, `player_id`),
    FOREIGN KEY (`tournament_id`) REFERENCES `tournaments`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`player_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PLAYER ACHIEVEMENTS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `player_achievements` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `player_id` INT NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `unlocked_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `tournament_id` INT,
    INDEX `idx_player_id` (`player_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_unlocked_at` (`unlocked_at`),
    UNIQUE KEY `unique_player_achievement` (`player_id`, `type`),
    FOREIGN KEY (`player_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`tournament_id`) REFERENCES `tournaments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- AUDIT LOG TABLE (for all admin actions)
-- ============================================
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `admin_id` INT,
    `action` VARCHAR(100),
    `entity_type` VARCHAR(50),
    `entity_id` INT,
    `old_values` JSON,
    `new_values` JSON,
    `reason` TEXT,
    `ip_address` VARCHAR(45),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_admin_id` (`admin_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_entity_type` (`entity_type`),
    INDEX `idx_created_at` (`created_at`),
    FOREIGN KEY (`admin_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TOURNAMENT DISPUTES TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `tournament_disputes` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `match_id` INT NOT NULL,
    `tournament_id` INT NOT NULL,
    `reported_by` INT NOT NULL,
    `original_winner` INT,
    `claimed_winner` INT,
    `original_score1` INT,
    `original_score2` INT,
    `claimed_score1` INT,
    `claimed_score2` INT,
    `reason` TEXT,
    `status` ENUM('open', 'reviewing', 'resolved', 'rejected') DEFAULT 'open',
    `resolution` TEXT,
    `resolved_by` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `resolved_at` DATETIME,
    INDEX `idx_tournament_id` (`tournament_id`),
    INDEX `idx_match_id` (`match_id`),
    INDEX `idx_status` (`status`),
    FOREIGN KEY (`match_id`) REFERENCES `tournament_matches`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`tournament_id`) REFERENCES `tournaments`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`reported_by`) REFERENCES `users`(`id`),
    FOREIGN KEY (`resolved_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
