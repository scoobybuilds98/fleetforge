-- Migration 99 (S-BACKUP-2): extend acc_oauth_states.provider ENUM to include 'dropbox'
-- Required so StateManager::generate/verifyAndConsume accept 'dropbox' as a valid provider.
ALTER TABLE `acc_oauth_states`
  MODIFY COLUMN `provider` ENUM('quickbooks','dropbox')
    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'quickbooks';
