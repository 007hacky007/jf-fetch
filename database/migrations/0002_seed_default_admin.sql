INSERT INTO users (name, email, password_hash, role, created_at, updated_at)
SELECT 'Administrator', 'admin@example.com', '$2y$12$/RK1QfnEYJ/byGnwdDtUvuUHvCWCfglzfn7CU5LhWr7b3bZoxzcwu', 'admin', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1 FROM users WHERE role = 'admin'
);
