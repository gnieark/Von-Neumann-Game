#!/usr/bin/env python3

from __future__ import annotations

import contextlib
import gzip
import importlib.util
import io
import json
import pathlib
import sys
import tempfile
import unittest


sys.dont_write_bytecode = True
ROOT = pathlib.Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "audit-deprecated-endpoints.py"
SPEC = importlib.util.spec_from_file_location("deprecated_endpoint_audit", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError(f"Cannot import {SCRIPT}")
AUDIT = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = AUDIT
SPEC.loader.exec_module(AUDIT)


OPENAPI = """openapi: 3.0.3
paths:
  /api/legacy/{itemId}:
    post:
      summary: Legacy operation
      deprecated: true
      responses:
        '202':
          description: Accepted
  /api/current/{itemId}:
    post:
      summary: Current operation
      responses:
        '202':
          description: Accepted
components:
  schemas:
    Request:
      type: object
      properties:
        oldField:
          type: string
          deprecated: true
"""


class DeprecatedEndpointLogAuditTests(unittest.TestCase):
    def test_reads_plain_and_gzip_logs_and_matches_method_and_template(self) -> None:
        with tempfile.TemporaryDirectory(prefix="vng-deprecated-endpoint-audit-") as directory:
            root = pathlib.Path(directory)
            openapi = root / "openapi.yaml"
            openapi.write_text(OPENAPI, encoding="utf-8")
            plain_log = root / "access.log"
            plain_log.write_text(
                "\n".join(
                    [
                        '192.0.2.1 - - [21/Aug/2026:08:42:18 +0200] "POST /api/legacy/abc?detail=1 HTTP/2.0" 202 12 "-" "client/1.0"',
                        '192.0.2.1 - - [21/Aug/2026:08:42:19 +0200] "GET /api/legacy/abc HTTP/2.0" 200 12 "-" "client/1.0"',
                        '198.51.100.2 - - [21/Aug/2026:08:42:20 +0200] "POST /api/legacy/def HTTP/1.1" 401 77 "-" "client/2.0"',
                        '198.51.100.2 - - [21/Aug/2026:08:42:21 +0200] "POST /api/current/def HTTP/1.1" 202 12 "-" "client/2.0"',
                        "truncated line",
                    ]
                )
                + "\n",
                encoding="utf-8",
            )
            rotated_log = root / "access.log.1.gz"
            with gzip.open(rotated_log, "wt", encoding="utf-8") as stream:
                stream.write(
                    '192.0.2.1 - - [20/Aug/2026:23:59:59 +0200] "POST /api/legacy/ghi HTTP/1.1" 202 12 "-" "client/1.0"\n'
                )

            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                status = AUDIT.main(
                    [
                        "--openapi",
                        str(openapi),
                        "--json",
                        str(root / "access.log*"),
                    ]
                )

            self.assertEqual(0, status)
            report = json.loads(output.getvalue())
            self.assertEqual(1, report["deprecatedOperations"])
            self.assertEqual(1, report["otherDeprecatedMarkers"])
            self.assertEqual(2, report["logFilesRead"])
            self.assertEqual(6, report["lines"])
            self.assertEqual(1, report["malformedLines"])
            operation = report["operations"][0]
            self.assertEqual("POST", operation["method"])
            self.assertEqual("/api/legacy/{itemId}", operation["path"])
            self.assertEqual(3, operation["requests"])
            self.assertEqual(2, operation["successfulRequests"])
            self.assertEqual(2, operation["distinctClients"])
            self.assertEqual(1, operation["distinctSuccessfulClients"])
            self.assertEqual({"202": 2, "401": 1}, operation["statuses"])

    def test_date_filter_is_inclusive(self) -> None:
        with tempfile.TemporaryDirectory(prefix="vng-deprecated-endpoint-audit-date-") as directory:
            root = pathlib.Path(directory)
            openapi = root / "openapi.yaml"
            openapi.write_text(OPENAPI, encoding="utf-8")
            access_log = root / "access.log"
            access_log.write_text(
                "\n".join(
                    [
                        '192.0.2.1 - - [20/Aug/2026:23:59:59 +0200] "POST /api/legacy/a HTTP/1.1" 202 12 "-" "client/1.0"',
                        '192.0.2.1 - - [21/Aug/2026:00:00:00 +0200] "POST /api/legacy/b HTTP/1.1" 202 12 "-" "client/1.0"',
                    ]
                )
                + "\n",
                encoding="utf-8",
            )

            operations, _ = AUDIT.parse_deprecated_operations(openapi)
            stats, counters, errors = AUDIT.audit_logs(
                operations,
                [access_log],
                AUDIT.dt.date(2026, 8, 21),
                AUDIT.dt.date(2026, 8, 21),
            )

            self.assertEqual([], errors)
            self.assertEqual(1, counters["datedLines"])
            self.assertEqual(1, stats[("POST", "/api/legacy/{itemId}")].requests)


if __name__ == "__main__":
    unittest.main()
