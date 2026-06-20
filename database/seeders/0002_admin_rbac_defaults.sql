INSERT INTO admin_roles (name, description) VALUES
    ('super_admin', 'Full access to every admin capability'),
    ('compliance_officer', 'KYC review, freezes, dispute resolution'),
    ('finance_approver', 'Withdrawal approval, transaction reversal'),
    ('support_agent', 'Support ticket handling, read-only account view')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO admin_permissions (`key`, description) VALUES
    ('users.view', 'View user accounts and balances'),
    ('users.suspend', 'Suspend or unsuspend a user account'),
    ('ledger.freeze', 'Freeze or unfreeze a specific ledger'),
    ('ledger.adjust', 'Post a manual ledger adjustment'),
    ('kyc.approve', 'Approve or reject KYC submissions'),
    ('withdrawal.approve', 'Approve or reject withdrawal requests'),
    ('transaction.reverse', 'Reverse a completed transaction'),
    ('investment.plan_manage', 'Create/edit/retire investment plans'),
    ('forex.instrument_manage', 'Manage tradeable forex/crypto/indices/commodities instruments'),
    ('realestate.listing_moderate', 'Approve or reject property listings'),
    ('disputes.manage', 'Handle support tickets and disputes'),
    ('admin.roles_manage', 'Manage admin roles and permissions')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO admin_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM admin_roles r CROSS JOIN admin_permissions p
WHERE r.name = 'super_admin'
ON DUPLICATE KEY UPDATE role_id = role_id;

INSERT INTO admin_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM admin_roles r JOIN admin_permissions p
    ON p.`key` IN ('users.view', 'users.suspend', 'ledger.freeze', 'kyc.approve', 'disputes.manage')
WHERE r.name = 'compliance_officer'
ON DUPLICATE KEY UPDATE role_id = role_id;

INSERT INTO admin_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM admin_roles r JOIN admin_permissions p
    ON p.`key` IN ('users.view', 'withdrawal.approve', 'transaction.reverse', 'ledger.adjust')
WHERE r.name = 'finance_approver'
ON DUPLICATE KEY UPDATE role_id = role_id;

INSERT INTO admin_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM admin_roles r JOIN admin_permissions p
    ON p.`key` IN ('users.view', 'disputes.manage')
WHERE r.name = 'support_agent'
ON DUPLICATE KEY UPDATE role_id = role_id;
