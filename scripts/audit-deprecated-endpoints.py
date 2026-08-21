#!/usr/bin/env python3
"""Audit OpenAPI-deprecated operations in current and rotated Nginx access logs.

Only Python's standard library is required. Client IP addresses are never emitted;
distinct clients are counted through an in-memory IP + User-Agent fingerprint.
"""

from __future__ import annotations

import argparse
import collections
import dataclasses
import datetime as dt
import glob
import gzip
import hashlib
import json
import pathlib
import re
import sys
from typing import Iterable, TextIO
from urllib.parse import urlsplit


HTTP_METHODS = {"get", "put", "post", "delete", "options", "head", "patch", "trace"}
PATH_LINE = re.compile(r"^  (?P<path>/.*):\s*$")
METHOD_LINE = re.compile(r"^    (?P<method>[a-zA-Z]+):\s*$")
PARAMETER = re.compile(r"\{[^/{}/]+\}")
LOG_LINE = re.compile(
    r'^(?P<ip>\S+) \S+ \S+ \[(?P<timestamp>[^\]]+)\] '
    r'"(?P<method>[A-Z]+) (?P<target>\S+)(?: [^"]+)?" '
    r'(?P<status>\d{3}) \S+ '
    r'"(?P<referer>(?:\\.|[^"])*)" '
    r'"(?P<agent>(?:\\.|[^"])*)"'
)


@dataclasses.dataclass(frozen=True)
class DeprecatedOperation:
    method: str
    path: str
    summary: str
    pattern: re.Pattern[str]


@dataclasses.dataclass
class OperationStats:
    requests: int = 0
    status_classes: collections.Counter[str] = dataclasses.field(default_factory=collections.Counter)
    statuses: collections.Counter[int] = dataclasses.field(default_factory=collections.Counter)
    clients: set[str] = dataclasses.field(default_factory=set)
    successful_clients: set[str] = dataclasses.field(default_factory=set)
    user_agents: collections.Counter[str] = dataclasses.field(default_factory=collections.Counter)
    first_seen: dt.datetime | None = None
    last_seen: dt.datetime | None = None

    def add(self, timestamp: dt.datetime, status: int, client: str, user_agent: str) -> None:
        self.requests += 1
        self.statuses[status] += 1
        self.status_classes[f"{status // 100}xx"] += 1
        self.clients.add(client)
        if 200 <= status < 300:
            self.successful_clients.add(client)
        self.user_agents[user_agent or "-"] += 1
        self.first_seen = timestamp if self.first_seen is None else min(self.first_seen, timestamp)
        self.last_seen = timestamp if self.last_seen is None else max(self.last_seen, timestamp)


def compile_path_template(template: str) -> re.Pattern[str]:
    chunks: list[str] = []
    cursor = 0
    for match in PARAMETER.finditer(template):
        chunks.append(re.escape(template[cursor : match.start()]))
        chunks.append("[^/]+")
        cursor = match.end()
    chunks.append(re.escape(template[cursor:]))

    return re.compile("^" + "".join(chunks) + "$")


def parse_deprecated_operations(openapi_path: pathlib.Path) -> tuple[list[DeprecatedOperation], int]:
    operations: list[DeprecatedOperation] = []
    deprecated_markers = 0
    in_paths = False
    current_path: str | None = None
    current: dict[str, object] | None = None

    def finish_operation() -> None:
        nonlocal current
        if current is not None and current["deprecated"]:
            path = str(current["path"])
            operations.append(
                DeprecatedOperation(
                    method=str(current["method"]).upper(),
                    path=path,
                    summary=str(current["summary"]),
                    pattern=compile_path_template(path),
                )
            )
        current = None

    with openapi_path.open("rt", encoding="utf-8") as stream:
        for raw_line in stream:
            line = raw_line.rstrip("\r\n")
            stripped = line.strip()
            if stripped == "deprecated: true":
                deprecated_markers += 1

            indentation = len(line) - len(line.lstrip(" "))
            if not in_paths:
                if line == "paths:":
                    in_paths = True
                continue
            if stripped and indentation == 0:
                finish_operation()
                in_paths = False
                current_path = None
                continue

            path_match = PATH_LINE.match(line)
            if path_match:
                finish_operation()
                current_path = path_match.group("path")
                continue

            method_match = METHOD_LINE.match(line)
            if method_match:
                finish_operation()
                method = method_match.group("method").lower()
                if current_path is not None and method in HTTP_METHODS:
                    current = {
                        "method": method,
                        "path": current_path,
                        "summary": "",
                        "deprecated": False,
                    }
                continue

            if current is None or indentation <= 4:
                continue
            if indentation == 6 and stripped == "deprecated: true":
                current["deprecated"] = True
            elif indentation == 6 and stripped.startswith("summary:"):
                current["summary"] = stripped.removeprefix("summary:").strip().strip("'\"")
        else:
            finish_operation()

    operations.sort(key=lambda operation: (operation.path, operation.method))
    return operations, deprecated_markers


def expand_log_paths(specifications: Iterable[str]) -> tuple[list[pathlib.Path], list[str]]:
    paths: dict[str, pathlib.Path] = {}
    unmatched: list[str] = []
    for specification in specifications:
        matches = glob.glob(specification)
        if not matches:
            unmatched.append(specification)
            continue
        for match in matches:
            path = pathlib.Path(match)
            if path.is_file():
                paths[str(path.resolve())] = path

    return sorted(paths.values(), key=lambda path: str(path)), unmatched


def open_log(path: pathlib.Path) -> TextIO:
    if path.suffix == ".gz":
        return gzip.open(path, "rt", encoding="utf-8", errors="replace")
    return path.open("rt", encoding="utf-8", errors="replace")


def parse_log_timestamp(value: str) -> dt.datetime:
    return dt.datetime.strptime(value, "%d/%b/%Y:%H:%M:%S %z")


def client_fingerprint(ip_address: str, user_agent: str) -> str:
    return hashlib.sha256(f"{ip_address}\0{user_agent}".encode("utf-8", errors="replace")).hexdigest()


def audit_logs(
    operations: list[DeprecatedOperation],
    log_paths: list[pathlib.Path],
    since: dt.date | None,
    until: dt.date | None,
) -> tuple[dict[tuple[str, str], OperationStats], dict[str, int], list[str]]:
    stats = {(operation.method, operation.path): OperationStats() for operation in operations}
    counters = {"lines": 0, "parsedLines": 0, "malformedLines": 0, "datedLines": 0}
    errors: list[str] = []

    for log_path in log_paths:
        try:
            with open_log(log_path) as stream:
                for line in stream:
                    counters["lines"] += 1
                    match = LOG_LINE.match(line)
                    if match is None:
                        counters["malformedLines"] += 1
                        continue
                    counters["parsedLines"] += 1
                    try:
                        timestamp = parse_log_timestamp(match.group("timestamp"))
                    except ValueError:
                        counters["malformedLines"] += 1
                        continue
                    if since is not None and timestamp.date() < since:
                        continue
                    if until is not None and timestamp.date() > until:
                        continue
                    counters["datedLines"] += 1

                    method = match.group("method")
                    path = urlsplit(match.group("target")).path
                    status = int(match.group("status"))
                    user_agent = match.group("agent")
                    fingerprint = client_fingerprint(match.group("ip"), user_agent)
                    for operation in operations:
                        if method == operation.method and operation.pattern.fullmatch(path):
                            stats[(operation.method, operation.path)].add(
                                timestamp,
                                status,
                                fingerprint,
                                user_agent,
                            )
                            break
        except (OSError, EOFError) as error:
            errors.append(f"{log_path}: {error}")

    return stats, counters, errors


def operation_payload(
    operation: DeprecatedOperation,
    stats: OperationStats,
    top_user_agents: int,
) -> dict[str, object]:
    return {
        "method": operation.method,
        "path": operation.path,
        "summary": operation.summary,
        "requests": stats.requests,
        "successfulRequests": stats.status_classes["2xx"],
        "statusClasses": {
            status_class: stats.status_classes[status_class]
            for status_class in ["2xx", "3xx", "4xx", "5xx"]
        },
        "statuses": dict(sorted(stats.statuses.items())),
        "distinctClients": len(stats.clients),
        "distinctSuccessfulClients": len(stats.successful_clients),
        "firstSeen": stats.first_seen.isoformat() if stats.first_seen else None,
        "lastSeen": stats.last_seen.isoformat() if stats.last_seen else None,
        "topUserAgents": [
            {"userAgent": user_agent, "requests": requests}
            for user_agent, requests in stats.user_agents.most_common(top_user_agents)
        ],
    }


def render_text(report: dict[str, object]) -> str:
    operations = report["operations"]
    assert isinstance(operations, list)
    lines = [
        f"OpenAPI deprecated operations: {report['deprecatedOperations']}",
        f"Other deprecated markers not detectable from access logs: {report['otherDeprecatedMarkers']}",
        f"Log files read: {report['logFilesRead']}",
        (
            "Log lines: "
            f"total={report['lines']} parsed={report['parsedLines']} "
            f"in_date_range={report['datedLines']} malformed={report['malformedLines']}"
        ),
        "",
    ]
    header = (
        f"{'METHOD':<7} {'ENDPOINT':<62} {'ALL':>7} {'2XX':>7} {'4XX':>7} "
        f"{'5XX':>7} {'CLIENTS':>8} {'OK CLIENTS':>10} {'LAST SEEN':<25}"
    )
    lines.extend([header, "-" * len(header)])
    for item in operations:
        status_classes = item["statusClasses"]
        lines.append(
            f"{item['method']:<7} {item['path']:<62} {item['requests']:>7} "
            f"{status_classes['2xx']:>7} {status_classes['4xx']:>7} {status_classes['5xx']:>7} "
            f"{item['distinctClients']:>8} {item['distinctSuccessfulClients']:>10} "
            f"{item['lastSeen'] or '-':<25}"
        )
        for agent in item["topUserAgents"]:
            lines.append(f"        user-agent ({agent['requests']}): {agent['userAgent']}")

    errors = report["errors"]
    if errors:
        lines.extend(["", "Read errors:"])
        lines.extend(f"  - {error}" for error in errors)
    lines.extend(
        [
            "",
            "Interpretation: a 2xx request proves that the deprecated operation was accepted.",
            "Distinct clients are anonymous IP + User-Agent fingerprints, not authenticated player IDs.",
        ]
    )
    return "\n".join(lines)


def parse_date(value: str) -> dt.date:
    try:
        return dt.date.fromisoformat(value)
    except ValueError as error:
        raise argparse.ArgumentTypeError("expected YYYY-MM-DD") from error


def build_argument_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Detect uses of OpenAPI operations marked deprecated in Nginx access logs.",
    )
    parser.add_argument("logs", nargs="+", help="Access-log files or quoted glob patterns; .gz is supported.")
    parser.add_argument("--openapi", required=True, type=pathlib.Path, help="Path to openapi.yaml.")
    parser.add_argument("--since", type=parse_date, help="Keep requests on or after YYYY-MM-DD.")
    parser.add_argument("--until", type=parse_date, help="Keep requests on or before YYYY-MM-DD.")
    parser.add_argument("--json", action="store_true", help="Emit machine-readable JSON.")
    parser.add_argument(
        "--top-user-agents",
        type=int,
        default=3,
        metavar="N",
        help="Show the N most frequent User-Agents per endpoint (default: 3; 0 disables).",
    )
    return parser


def main(arguments: list[str] | None = None) -> int:
    parser = build_argument_parser()
    options = parser.parse_args(arguments)
    if options.since is not None and options.until is not None and options.since > options.until:
        parser.error("--since must be earlier than or equal to --until")
    if options.top_user_agents < 0:
        parser.error("--top-user-agents must be zero or positive")
    if not options.openapi.is_file():
        parser.error(f"OpenAPI file not found: {options.openapi}")

    operations, deprecated_markers = parse_deprecated_operations(options.openapi)
    if not operations:
        parser.error("no OpenAPI operation marked deprecated: true was found")
    log_paths, unmatched = expand_log_paths(options.logs)
    if not log_paths:
        parser.error("no access-log file matched the supplied paths")
    for specification in unmatched:
        print(f"Warning: no log matched {specification}", file=sys.stderr)

    stats, counters, errors = audit_logs(operations, log_paths, options.since, options.until)
    report: dict[str, object] = {
        "openapi": str(options.openapi),
        "deprecatedOperations": len(operations),
        "otherDeprecatedMarkers": max(0, deprecated_markers - len(operations)),
        "logFilesRead": len(log_paths) - len(errors),
        **counters,
        "operations": [
            operation_payload(operation, stats[(operation.method, operation.path)], options.top_user_agents)
            for operation in operations
        ],
        "errors": errors,
    }
    if options.json:
        print(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True))
    else:
        print(render_text(report))

    return 1 if errors else 0


if __name__ == "__main__":
    raise SystemExit(main())
