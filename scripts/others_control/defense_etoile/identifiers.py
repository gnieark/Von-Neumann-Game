"""Signatures stables et clés d'idempotence."""

from __future__ import annotations

import hashlib
import json
from typing import Any


def stable_signature(value: Any) -> str:
    return hashlib.sha256(
        json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode()
    ).hexdigest()


def event_key(kind: str, *parts: str) -> str:
    return command_idempotency_key(kind, *parts)


def command_idempotency_key(prefix: str, *parts: str) -> str:
    digest = hashlib.sha256("\0".join(parts).encode()).hexdigest()
    return f"{prefix}-{digest}"
