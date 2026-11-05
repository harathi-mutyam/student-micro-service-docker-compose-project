# Migrating your original student-app-cicd

Source: https://github.com/harathi-mutyam/student-app-cicd

This is an independent proof-of-concept, **not a modified copy of your old PHP code**.

1. Back up old `admin/`, `student/`, `config/`, `sql/` and `python/` and MySQL data. Keep old project running.
2. Map old login and session logic to `services/auth` and adapt original PHP pages to use API calls.
3. Map old student create/read/update/delete queries to `services/students`.
4. Map attendance forms and reports to `services/attendance`.
5. Map marks creation and reports to `services/marks`.
6. Migrate legacy data with a separate, audited one-time script; do not assume table or column names match.
7. Implement admin-created student login provisioning, role-based authorization and consistency checks between services.
8. Add unit/integration tests, per-service CI, image registry, Argo CD and EKS only after Compose passes.

Existing repo folders include `admin/`, `student/`, `config/`, `sql/`, `python/`, `k8s/`, `argocd/`, `logging/`, `terraform/`. This starter does not overwrite them.
