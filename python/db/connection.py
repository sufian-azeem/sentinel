"""
db.connection — MySQL connection for the trading dashboard.

Reads credentials from Laravel's .env (two levels up from python/).
DB_HOST=mysql is a Docker-internal hostname; when running Python on the host
machine we connect to 127.0.0.1 instead. Override with PYTHON_DB_HOST.
"""

import os
from pathlib import Path

import pymysql
from dotenv import dotenv_values


def _load_env() -> dict:
    env_path = Path(__file__).resolve().parents[2] / ".env"
    return dotenv_values(env_path)


def get_persistent_connection() -> pymysql.Connection:
    """Return a connection whose lifecycle the caller fully manages (batch mode)."""
    return get_connection()


def get_connection() -> pymysql.Connection:
    """Return a new pymysql connection. Caller is responsible for closing it."""
    env = _load_env()

    raw_host = env.get("DB_HOST", "127.0.0.1")
    # PYTHON_DB_HOST overrides everything (useful for host-machine runs).
    # When running inside Docker the "mysql" hostname resolves correctly via the
    # container network, so we leave it as-is.
    # When running on the host machine, "mysql" doesn't resolve — use 127.0.0.1
    # (Sail forwards the MySQL port to the host).
    # Detection: if /proc/1/cgroup mentions "docker" we're inside a container.
    inside_docker = os.path.exists("/.dockerenv")
    if os.environ.get("PYTHON_DB_HOST"):
        host = os.environ["PYTHON_DB_HOST"]
    elif raw_host == "mysql" and not inside_docker:
        host = "127.0.0.1"
    else:
        host = raw_host

    tz_offset = env.get("APP_TIMEZONE_OFFSET", "+05:00")
    return pymysql.connect(
        host=host,
        port=int(env.get("DB_PORT", 3306)),
        database=env.get("DB_DATABASE", "crypto_signals"),
        user=env.get("DB_USERNAME", "sail"),
        password=env.get("DB_PASSWORD", ""),
        charset="utf8mb4",
        autocommit=False,
        init_command=f"SET time_zone='{tz_offset}'",
    )
