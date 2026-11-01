-- Organization registration is self-service; no administrator approval state is required.
ALTER TABLE organizations
 DROP FOREIGN KEY organizations_ibfk_2,
 DROP INDEX idx_organization_verification,
 DROP COLUMN verification_status,
 DROP COLUMN verified_by,
 DROP COLUMN verified_at;
