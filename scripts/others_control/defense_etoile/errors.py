"""Erreurs publiques du contrôleur Others."""

from __future__ import annotations

from typing import Any


class ConfigurationError(ValueError):
    """Configuration locale invalide."""


class ApiContractError(RuntimeError):
    """Réponse incompatible avec le contrat API canonique."""


class ApiRequestError(RuntimeError):
    """Erreur HTTP structurée renvoyée par l'API."""

    def __init__(
        self,
        status: int,
        code: str,
        message: str,
        *,
        details: dict[str, Any] | None = None,
        retry_after_seconds: float | None = None,
    ) -> None:
        super().__init__(f"HTTP {status} {code}: {message}")
        self.status = status
        self.code = code
        self.message = message
        self.details = details or {}
        self.retry_after_seconds = retry_after_seconds
