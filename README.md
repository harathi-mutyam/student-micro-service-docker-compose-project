# Student Management Microservices — Fresh AWS EC2 Deployment Guide

A beginner-friendly, reproducible deployment walkthrough for the [Student Management Microservices](https://github.com/harathi-mutyam/micro_svc_student_app_cicd) learning project.

## 1. Architecture and capabilities

```text
Windows browser
     |
     | SSH tunnel (recommended)
     v
AWS EC2 Ubuntu :8080
     |
NGINX gateway
     |--- PHP frontend (admin/student dashboards)
     |--- FastAPI auth service ------ auth_db
     |--- FastAPI students service -- student_db
     |--- FastAPI attendance service attendance_db
     |--- FastAPI marks service ----- marks_db
                                    |
                         MySQL 8.4 (one container)
                         Named persistent Docker volume
```

The `docker-compose.yml` defines **seven services**: `database`, `auth`, `students`, `attendance`, `marks`, `frontend`, `gateway`. The four databases are logical databases in a shared MySQL server.

Capabilities in the **updated version**, *provided the checks in Step 5 succeed*: admin login, student CRUD, separate student login provisioning, password change, attendance and marks, and deletion of linked attendance, marks and authentication records. Existing JWTs may remain valid until expiry; this is a learning project, **not production-ready**.

## 2. AWS EC2 prerequisites (AWS Console)

1. Select your preferred AWS Region (example `eu-north-1`).
2. EC2 → Launch instance; name it `student-server`.
3. Ubuntu Server **24.04 LTS**, 64-bit x86; `m7i-flex.large` or another instance with sufficient memory; 30–40 GiB `gp3` storage.
4. Create/select an SSH key pair (`.pem`) and download it safely.
5. Custom security group: inbound **TCP 22 from your own public IP /32** only. **Do not open 8080 publicly** if using the SSH-tunnel method below. No inbound MySQL port is needed.
6. Start EC2 and note its **Public IPv4 address**. Be aware of EC2/EBS/public IPv4 costs.

In **Windows Git Bash** (replace placeholders):

```bash
chmod 400 ~/Downloads/YOUR_KEY.pem
ssh -i ~/Downloads/YOUR_KEY.pem ubuntu@YOUR_EC2_PUBLIC_IP
```



## 3. Install prerequisites on fresh Ubuntu

```bash
sudo hostnamectl set-hostname student-server
exec bash
sudo apt update
sudo apt upgrade -y
sudo apt install -y git curl ca-certificates python3
```

Add Docker's official Ubuntu apt source:

```bash
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

sudo tee /etc/apt/sources.list.d/docker.sources >/dev/null <<EOF_DOCKER
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: $(. /etc/os-release && echo "$VERSION_CODENAME")
Components: stable
Signed-By: /etc/apt/keyrings/docker.asc
EOF_DOCKER

sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io \
  docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo usermod -aG docker "$USER"
exit
ssh -i ~/Downloads/sudent_key.pem ubuntu@<ec2 instance publicip>
```

**Log out and reconnect via SSH** to apply group membership. Be aware that membership in the `docker` group is effectively root-level access on the host.

```bash
docker --version
docker compose version
docker ps
docker run --rm hello-world
```

## 4. Clone from GitHub

```bash
cd ~
git clone https://github.com/harathi-mutyam/micro_svc_student_app_cicd.git
cd micro_svc_student_app_cicd
pwd
git status --short
git log -1 --oneline
ls -la
```

Expected directory: `/home/ubuntu/micro_svc_student_app_cicd`.

**Check the branch and commit: optional commands**

```bash
git branch --show-current
git rev-parse --short HEAD
git fetch origin
git log -1 --oneline origin/main
```

## 5. Verify latest feature code before running anything

```bash
# Must show the Fernet/vault implementation in the updated auth service
grep -n 'StudentPasswordVault' services/auth/main.py

# Must show auth cleanup linked to student deletion
grep -n 'users/student/' services/students/main.py

# Must show the UI action for student login provisioning
grep -n 'create_student_login' frontend/index.php

# Must pass the encryption key to the auth container
grep -n 'PASSWORD_ENCRYPTION_KEY' docker-compose.yml

# Database must initialize via shell script, not the old init.sql
ls -l database/init.sh
grep -n 'database/init.sh' docker-compose.yml

# Required Python dependency
grep -n 'cryptography' services/common/requirements.txt
```

**Stop and fix the GitHub source if any expected feature is missing.** Do not add the outdated `database/init.sql` editing procedure from the early trial notes. The updated deployment uses `database/init.sh` and `.env` variables.

## 6. Create secure `.env` credentials

`.env.example` is a template; `.env` should never be committed. The example may **not yet list `PASSWORD_ENCRYPTION_KEY`**, so the generator below adds it.

```bash
cp .env.example .env
chmod 600 .env
```

Generate *new* random secrets (works with Python's standard library):

```bash
python3 - <<'PY'
from pathlib import Path
import base64
import os
import secrets

values = {
    "MYSQL_ROOT_PASSWORD": secrets.token_hex(32),
    "JWT_SECRET": secrets.token_hex(32),
    "DEMO_ADMIN_PASSWORD": secrets.token_urlsafe(24),
    "DEMO_STUDENT_PASSWORD": secrets.token_urlsafe(24),
    "AUTH_DB_PASSWORD": secrets.token_hex(32),
    "STUDENT_DB_PASSWORD": secrets.token_hex(32),
    "ATTENDANCE_DB_PASSWORD": secrets.token_hex(32),
    "MARKS_DB_PASSWORD": secrets.token_hex(32),
    "PASSWORD_ENCRYPTION_KEY": base64.urlsafe_b64encode(secrets.token_bytes(32)).decode(),
}

path = Path('.env')
output = []
written = set()
for line in path.read_text().splitlines():
    if '=' in line and not line.lstrip().startswith('#'):
        key = line.split('=', 1)[0].strip()
        if key in values:
            if key not in written:
                output.append(f'{key}={values[key]}')
                written.add(key)
            continue
    output.append(line)
for key, value in values.items():
    if key not in written:
        output.append(f'{key}={value}')
path.write_text('\n'.join(output) + '\n')
os.chmod(path, 0o600)
print('Generated the nine required secrets in .env (values not displayed).')
PY
```

**Check without printing secrets:**

```bash
python3 - <<'PY'
from pathlib import Path
keys = ['MYSQL_ROOT_PASSWORD','JWT_SECRET','DEMO_ADMIN_PASSWORD',
        'DEMO_STUDENT_PASSWORD','AUTH_DB_PASSWORD','STUDENT_DB_PASSWORD',
        'ATTENDANCE_DB_PASSWORD','MARKS_DB_PASSWORD','PASSWORD_ENCRYPTION_KEY']
values = dict(line.split('=',1) for line in Path('.env').read_text().splitlines()
              if '=' in line and not line.lstrip().startswith('#'))
for key in keys:
    print(f'{key}:', 'OK' if values.get(key) else 'MISSING')
PY
```

Every key should report `OK`. The four DB passwords are 64-character hex strings, satisfying `database/init.sh`'s `>=32`-hex validation.

## 7. Validate configuration and initialization script

```bash
docker compose config --quiet
bash -n database/init.sh
git check-ignore .env
```

Expected: first two commands exit successfully; `git check-ignore .env` prints `.env`. Do not expose the expanded `docker compose config` output or post `.env` online, as it can contain credentials.

Check Docker's initialization mount:

```bash
grep -n 'docker-entrypoint-initdb.d' docker-compose.yml
```

Expected `./database/init.sh:/docker-entrypoint-initdb.d/init.sh:ro`.

## 8. First startup: database, then application

These steps assume a **new, empty Docker volume**.

```bash
docker compose up -d database
docker compose logs --tail=100 database
docker compose ps database
```

Look for successful initialization of the four logical databases and `healthy` status. Do **not** proceed if the database container repeatedly exits.

```bash
docker compose up -d --build
docker compose ps -a
docker compose logs --tail=80 auth students attendance marks frontend gateway
```

Expected **seven running containers**: `database`, `auth`, `students`, `attendance`, `marks`, `frontend`, `gateway` (the database should be healthy).

> `database/init.sh` runs automatically **only when the MySQL data directory is new/empty**. Avoid deleting the volume to solve ordinary errors; it destroys student records.

## 9. Verify databases and tables

```bash
docker compose exec database sh -c \
  'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SHOW DATABASES;"'
```

Expected logical DBs: `auth_db`, `student_db`, `attendance_db`, `marks_db`.

```bash
docker compose exec database sh -c \
  'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -D auth_db -e "SHOW TABLES;"'
```

With the **updated Auth service**, expected `users` and `student_password_vault`. If vault table is absent, verify Step 5 and Auth logs before testing student accounts.

MySQL may print a warning about passwords on its command line; that warning does not imply query failure.

## 10. Test health endpoints from Ubuntu

```bash
curl -fsS http://127.0.0.1:8080/api/auth/health; echo
curl -fsS http://127.0.0.1:8080/api/students/health; echo
curl -fsS http://127.0.0.1:8080/api/attendance/health; echo
curl -fsS http://127.0.0.1:8080/api/marks/health; echo
```

Expected each JSON response to have `"status":"ok"` and the corresponding `service` name. Note: HTTP 200 **alone** is not enough if the gateway accidentally routes a request to the wrong microservice.

For errors:

```bash
docker compose ps -a
docker compose logs --tail=100 database auth students attendance marks gateway
```

If API containers were rebuilt/recreated and the gateway still points at old upstream IPs:

```bash
docker compose restart gateway
```

## 11. Open the web application 

http://your server ec2_public_ip:8080

```bash
**Windows Git Bash — new terminal** (keep it open):its an optional way


ssh -i ~/Downloads/YOUR_KEY.pem -L 8080:127.0.0.1:8080 ubuntu@YOUR_EC2_PUBLIC_IP


Then open **http://localhost:8080** on Windows. The `localhost` connection is carried over SSH to EC2, so inbound port 8080 need not be publicly open.

If local 8080 is occupied, use `-L 8081:127.0.0.1:8080` and open `http://localhost:8081`.

**Security note:** The Compose gateway currently publishes `8080:80` (host all interfaces). Keeping inbound 8080 blocked in the EC2 Security Group limits external access; for stronger host-level isolation change the port mapping to `127.0.0.1:8080:80` if you only use SSH tunneling, then recreate the gateway.
```
## 12. Functional test plan (use disposable demo records)

### 12.1 Admin login

- Username: `admin`
- Password: the value of `DEMO_ADMIN_PASSWORD` in **your own** `.env` (do not share it)
- Confirm admin dashboard appears.

### 12.2 Demo student login

- Username: `student1`
- Password: the value of `DEMO_STUDENT_PASSWORD` in `.env`
- Confirm student sees only their authorized records.

### 12.3 Create test student

As admin create `Test Student` / `test.student@example.com` / `DevOps`. Note the **assigned ID** rather than assuming ID 8 or any other ID.

### 12.4 Provision a login (updated code only)

In **Create Student Login Account**, enter the newly assigned student ID and a username like `teststudent`. Username has no spaces or `@`; 3–80 permitted `[a-z0-9_.-]` characters. The updated Auth service strips whitespace at the ends and lowercases the username. Save the one-time temporary password securely. Student creation and login provisioning are **two separate actions**.

### 12.5 Student login and change password

Sign out, sign in as `teststudent` with the temporary password, change to a new password (at least 12 characters), sign out, and sign in again with the new password. The old password should no longer work.

### 12.6 Attendance and marks

As admin, create attendance (e.g., Present) and marks (e.g., Maths 85) for the test student. Refresh and check persistence and student-specific visibility.

### 12.7 Verify cascade deletion (updated code only)

First **confirm the test student's student, attendance, marks, auth-user, and vault records exist**. Delete the test student in the admin UI. Then verify that all linked records disappeared.

Replace the placeholder `TEST_ID` below with a real integer **before running**:

```bash
TEST_ID=8  # REPLACE with the actual student ID

docker compose exec database sh -c \
  'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -D auth_db -e "SELECT id,username,student_id FROM users WHERE student_id='$TEST_ID';"'

docker compose exec database sh -c \
  'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -D auth_db -e "
  SELECT v.user_id FROM student_password_vault v
  LEFT JOIN users u ON u.id=v.user_id WHERE u.id IS NULL;"'
```

Also verify in `student_db.students`, and identify actual table names for the others:

```bash
docker compose exec database sh -c \
  'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "
  SHOW TABLES FROM student_db;
  SHOW TABLES FROM attendance_db;
  SHOW TABLES FROM marks_db;"'
```

A query returning zero rows **after** deletion proves records are absent, not that cascade deletion worked; record existence must be verified **before** deletion too.

## 13. Daily operation commands

From `~/micro_svc_student_app_cicd`:

| Action | Command |
|---|---|
| View status | `docker compose ps -a` |
| Start stopped services | `docker compose up -d` |
| Rebuild after code changes | `docker compose up -d --build` |
| Follow logs | `docker compose logs -f --tail=100` |
| Inspect Auth logs | `docker compose logs --tail=100 auth` |
| Restart NGINX gateway | `docker compose restart gateway` |
| Stop without deleting data | `docker compose stop` |
| Remove containers, keep volumes | `docker compose down` |
| **Delete project data** | **`docker compose down -v` — destructive** |

### Pull later GitHub changes

```bash
cd ~/micro_svc_student_app_cicd
git status --short
git pull --ff-only origin main
docker compose up -d --build
docker compose ps -a
```



