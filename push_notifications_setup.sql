CREATE TABLE IF NOT EXISTS `ML_PushSubscriptions` (
    `PushSubscriptionID` INT NOT NULL AUTO_INCREMENT,
    `UserID` INT NOT NULL,
    `Endpoint` VARCHAR(2048) NOT NULL,
    `EndpointHash` CHAR(64) NOT NULL,
    `PublicKey` VARCHAR(255) NOT NULL,
    `AuthToken` VARCHAR(255) NOT NULL,
    `ContentEncoding` VARCHAR(32) NOT NULL DEFAULT 'aes128gcm',
    `UserAgent` VARCHAR(500) NULL,
    `CreatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `LastSeenAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `DisabledAt` DATETIME NULL,
    PRIMARY KEY (`PushSubscriptionID`),
    UNIQUE KEY `uq_push_subscription_endpoint` (`EndpointHash`),
    KEY `idx_push_subscription_user_active` (`UserID`, `DisabledAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ML_PushDeliveryLog` (
    `PushDeliveryID` BIGINT NOT NULL AUTO_INCREMENT,
    `PushSubscriptionID` INT NOT NULL,
    `UserID` INT NOT NULL,
    `SeasonRoundID` INT NOT NULL,
    `ReminderKey` VARCHAR(100) NOT NULL,
    `Status` VARCHAR(20) NOT NULL,
    `FailureCount` INT NOT NULL DEFAULT 0,
    `LastError` VARCHAR(500) NULL,
    `LastAttemptAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `SentAt` DATETIME NULL,
    PRIMARY KEY (`PushDeliveryID`),
    UNIQUE KEY `uq_push_delivery_reminder` (`PushSubscriptionID`, `SeasonRoundID`, `ReminderKey`),
    KEY `idx_push_delivery_user_round` (`UserID`, `SeasonRoundID`),
    KEY `idx_push_delivery_status` (`Status`, `LastAttemptAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `QA_ML_PushSubscriptions` LIKE `ML_PushSubscriptions`;
CREATE TABLE IF NOT EXISTS `QA_ML_PushDeliveryLog` LIKE `ML_PushDeliveryLog`;

-- QA subscriptions intentionally begin empty. Do not copy live device endpoints into QA.
