#!/usr/bin/env bash
set -e

cd "$(dirname "$0")"

DB_DIR="/tmp/minicloud_mysql"
SOCK="$DB_DIR/run/mysqld.sock"
PORT="${1:-8080}"

mkdir -p "$DB_DIR/data" "$DB_DIR/run"

# 1. Lokale MariaDB initialisieren, falls noch nicht vorhanden
if [ ! -d "$DB_DIR/data/mysql" ]; then
    echo "[MiniCloud] Initialisiere lokale MariaDB-Datenbank..."
    mariadb-install-db --no-defaults --datadir="$DB_DIR/data" --auth-root-authentication-method=normal >/dev/null 2>&1
fi

# 2. MariaDB starten, falls sie noch nicht läuft
if ! mysql --protocol=SOCKET --socket="$SOCK" -u root -e "SELECT 1" >/dev/null 2>&1; then
    echo "[MiniCloud] Starte lokalen MariaDB-Server..."
    /usr/sbin/mariadbd --no-defaults \
        --datadir="$DB_DIR/data" \
        --socket="$SOCK" \
        --pid-file="$DB_DIR/run/mysqld.pid" \
        --skip-networking=0 \
        --port=13306 \
        --bind-address=127.0.0.1 \
        >"$DB_DIR/mysqld.log" 2>&1 &

    for i in $(seq 1 30); do
        if mysql --protocol=SOCKET --socket="$SOCK" -u root -e "SELECT 1" >/dev/null 2>&1; then
            break
        fi
        sleep 0.3
    done
fi

# 3. Datenbank, Benutzer und Tabellen anlegen
mysql --protocol=SOCKET --socket="$SOCK" -u root -e "
CREATE DATABASE IF NOT EXISTS minicloud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'minicloud_user'@'127.0.0.1' IDENTIFIED BY 'minicloud_secret';
CREATE USER IF NOT EXISTS 'minicloud_user'@'localhost' IDENTIFIED BY 'minicloud_secret';
GRANT ALL PRIVILEGES ON minicloud.* TO 'minicloud_user'@'127.0.0.1';
GRANT ALL PRIVILEGES ON minicloud.* TO 'minicloud_user'@'localhost';
FLUSH PRIVILEGES;
"
mysql --protocol=SOCKET --socket="$SOCK" -u root minicloud < sql/schema.sql

LAN_IP=$(hostname -I 2>/dev/null | awk '{print $1}')

echo "=========================================================="
echo " MiniCloud läuft:"
echo "   Lokal auf diesem PC:   http://localhost:${PORT}"
if [ -n "$LAN_IP" ]; then
echo "   Im lokalen Netzwerk:   http://${LAN_IP}:${PORT}"
fi
echo " Drücken Sie Strg+C zum Beenden."
echo "=========================================================="

export MINICLOUD_DB_SOCKET="$SOCK"
exec php -d upload_max_filesize=1G -d post_max_size=1G -d memory_limit=512M -d max_execution_time=600 -d max_input_time=600 -S "0.0.0.0:${PORT}"
