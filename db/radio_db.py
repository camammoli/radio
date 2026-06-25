#!/usr/bin/env python3
"""
radio_db.py — conexión SQLite compartida para crawlers v2.

Uso:
    from db.radio_db import get_db
    db = get_db()          # sqlite3.Connection con WAL y row_factory
"""

import os
import sqlite3

_DEFAULT_DB = os.path.join(os.path.dirname(os.path.abspath(__file__)), "radio_v2.sqlite")


def get_db(path: str | None = None) -> sqlite3.Connection:
    db_path = path or os.environ.get("RADIO_DB", _DEFAULT_DB)
    conn = sqlite3.connect(db_path, timeout=10)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode = WAL")
    conn.execute("PRAGMA foreign_keys = ON")
    conn.execute("PRAGMA busy_timeout = 5000")
    return conn
