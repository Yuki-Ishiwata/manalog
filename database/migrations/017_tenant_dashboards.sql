CREATE TABLE organizations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(100) NOT NULL UNIQUE,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO organizations (id, name, slug, status)
VALUES (1, '石渡塾', 'ishiwata-juku', 'active');

ALTER TABLE users
  ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  ADD COLUMN admin_scope ENUM('tenant','service') NULL AFTER auth_source,
  ADD INDEX idx_users_organization_role_status (organization_id, role, status),
  ADD CONSTRAINT fk_users_organization FOREIGN KEY (organization_id) REFERENCES organizations(id);

UPDATE users SET admin_scope = 'tenant' WHERE role = 'admin';

ALTER TABLE parent_student_links
  ADD INDEX idx_parent_student_org (parent_user_id, student_user_id);
