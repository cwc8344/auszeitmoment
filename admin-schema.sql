CREATE TABLE IF NOT EXISTS hours (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('weekly', 'date') NOT NULL,
    weekday TINYINT UNSIGNED,
    day DATE,
    start_time CHAR(5) NOT NULL,
    end_time CHAR(5) NOT NULL,
    is_open BOOLEAN NOT NULL DEFAULT TRUE,
    note VARCHAR(120) NOT NULL DEFAULT '',
    INDEX (kind, weekday, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bookings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL DEFAULT '',
    phone VARCHAR(50) NOT NULL DEFAULT '',
    street VARCHAR(200) NOT NULL DEFAULT '',
    city VARCHAR(200) NOT NULL DEFAULT '',
    massage VARCHAR(300) NOT NULL DEFAULT '',
    duration SMALLINT UNSIGNED NOT NULL,
    day DATE NOT NULL,
    start_time CHAR(5) NOT NULL,
    price INT UNSIGNED NOT NULL DEFAULT 0,
    notes TEXT NOT NULL,
    status ENUM('pending', 'confirmed', 'cancelled', 'completed') NOT NULL,
    source ENUM('admin', 'website') NOT NULL DEFAULT 'admin',
    INDEX (day, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_login_limits (
    address_hash CHAR(64) PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL,
    expires_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
