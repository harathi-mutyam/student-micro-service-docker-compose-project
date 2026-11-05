#!/bin/bash
set -euo pipefail

echo "Initializing Student Microservices databases..."

# Validate the passwords before using them in SQL
for var in AUTH_DB_PASSWORD STUDENT_DB_PASSWORD \
           ATTENDANCE_DB_PASSWORD MARKS_DB_PASSWORD; do
    value="${!var:-}"

    if [[ ! "$value" =~ ^[0-9a-fA-F]{32,}$ ]]; then
        echo "ERROR: $var must contain at least 32 hexadecimal characters"
        exit 1
    fi
done

mysql --protocol=socket -uroot \
    -p"$MYSQL_ROOT_PASSWORD" <<SQL

CREATE DATABASE IF NOT EXISTS auth_db;
CREATE DATABASE IF NOT EXISTS student_db;
CREATE DATABASE IF NOT EXISTS attendance_db;
CREATE DATABASE IF NOT EXISTS marks_db;

CREATE USER IF NOT EXISTS 'auth_user'@'%'
IDENTIFIED BY '${AUTH_DB_PASSWORD}';

CREATE USER IF NOT EXISTS 'student_user'@'%'
IDENTIFIED BY '${STUDENT_DB_PASSWORD}';

CREATE USER IF NOT EXISTS 'attendance_user'@'%'
IDENTIFIED BY '${ATTENDANCE_DB_PASSWORD}';

CREATE USER IF NOT EXISTS 'marks_user'@'%'
IDENTIFIED BY '${MARKS_DB_PASSWORD}';

GRANT ALL PRIVILEGES ON auth_db.* TO 'auth_user'@'%';
GRANT ALL PRIVILEGES ON student_db.* TO 'student_user'@'%';
GRANT ALL PRIVILEGES ON attendance_db.* TO 'attendance_user'@'%';
GRANT ALL PRIVILEGES ON marks_db.* TO 'marks_user'@'%';

SQL

echo "SUCCESS: Four databases and database users initialized"
