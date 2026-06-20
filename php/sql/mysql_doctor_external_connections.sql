-- Manual migration for MySQL (also applied automatically via Database::migrateExternalConnectionsSchema)
CREATE TABLE IF NOT EXISTS doctor_external_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id VARCHAR(64) NOT NULL,
    provider VARCHAR(32) NOT NULL,
    access_token TEXT NOT NULL,
    refresh_token TEXT NULL,
    expires_at BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_doctor_provider (doctor_id, provider),
    INDEX idx_doctor_external_connections_doctor (doctor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
