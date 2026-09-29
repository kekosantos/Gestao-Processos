#!/usr/bin/env python3
"""HTTP integration smoke test. Creates uniquely named demo tenants and cleans them up."""
from __future__ import annotations

import http.cookiejar
import json
import secrets
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path

BASE = (sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:3000").rstrip("/")
ROOT = Path(__file__).resolve().parents[1]
TOKEN = secrets.token_hex(5)
TENANTS: list[int] = []
CHECKS: list[str] = []


class Client:
    def __init__(self) -> None:
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))
        self.csrf = ""
        self.user: dict = {}

    def request(self, method: str, path: str, body=None, *, raw: bytes | None = None,
                content_type: str | None = None, csrf: bool = True, expected=(200,)):
        headers = {"Accept": "application/json"}
        data = raw
        if body is not None:
            data = json.dumps(body).encode()
            headers["Content-Type"] = "application/json"
        elif content_type:
            headers["Content-Type"] = content_type
        if csrf and method.upper() != "GET" and self.csrf:
            headers["X-CSRF-Token"] = self.csrf
        req = urllib.request.Request(BASE + path, data=data, headers=headers, method=method.upper())
        try:
            resp = self.opener.open(req, timeout=20)
            code, resp_headers, payload = resp.status, resp.headers, resp.read()
        except urllib.error.HTTPError as error:
            code, resp_headers, payload = error.code, error.headers, error.read()
        if code not in expected:
            raise AssertionError(f"{method} {path}: expected {expected}, got {code}: {payload[:300]!r}")
        if "application/json" in resp_headers.get("Content-Type", ""):
            parsed = json.loads(payload or b"{}")
            if parsed.get("csrf"):
                self.csrf = parsed["csrf"]
            if parsed.get("user"):
                self.user = parsed["user"]
            return code, parsed, resp_headers, payload
        return code, {}, resp_headers, payload

    def start(self):
        self.request("GET", "/api/auth/session")

    def register(self, office: str, email: str, password: str):
        self.start()
        _, result, _, _ = self.request("POST", "/api/auth/register", {
            "name": "Pessoa de teste", "office_name": office,
            "email": email, "password": password,
        }, expected=(201,))
        tenant_id = int(result["user"]["tenant_id"])
        TENANTS.append(tenant_id)
        return result


def check(name: str):
    CHECKS.append(name)
    print(f"PASS {name}")


def multipart(fields: dict[str, str], file_name: str, content: bytes):
    boundary = "----LexCloud" + secrets.token_hex(12)
    chunks: list[bytes] = []
    for key, value in fields.items():
        chunks.extend([f"--{boundary}\r\n".encode(),
                       f'Content-Disposition: form-data; name="{key}"\r\n\r\n'.encode(),
                       value.encode(), b"\r\n"])
    chunks.extend([f"--{boundary}\r\n".encode(),
                   f'Content-Disposition: form-data; name="file"; filename="{file_name}"\r\n'.encode(),
                   b"Content-Type: text/plain\r\n\r\n", content, b"\r\n",
                   f"--{boundary}--\r\n".encode()])
    return boundary, b"".join(chunks)


def main():
    a, b = Client(), Client()
    pass_a = f"TestA-{TOKEN}-Strong#2026"
    pass_b = f"TestB-{TOKEN}-Strong#2026"
    owner_a = f"owner.{TOKEN}@example.com"
    owner_b = f"second.{TOKEN}@example.com"
    viewer_email = f"viewer.{TOKEN}@example.com"
    staff_email = f"staff.{TOKEN}@example.com"
    tenant_a = tenant_b = None
    try:
        _, health, _, _ = a.request("GET", "/api/health")
        assert health.get("status") == "ok"
        check("health endpoint and managed MySQL connection")

        result_a = a.register(f"LexCloud TEST-{TOKEN}-A", owner_a, pass_a)
        tenant_a = int(result_a["user"]["tenant_id"])
        check("tenant registration and owner session")

        _, client_result, _, _ = a.request("POST", "/api/clients", {
            "name": f"Cliente fictício {TOKEN}", "kind": "pessoa_fisica",
            "email": f"client.{TOKEN}@example.com", "city": "São Paulo",
        }, expected=(201,))
        client_id = int(client_result["id"])
        check("client creation")

        _, case_result, _, _ = a.request("POST", "/api/cases", {
            "client_id": client_id, "subject": f"Caso fictício {TOKEN}", "area": "Cível",
            "priority": "normal", "owner_id": a.user["id"],
        }, expected=(201,))
        case_id = int(case_result["id"])
        check("case creation and activity log")

        _, task_result, _, _ = a.request("POST", "/api/tasks", {
            "case_id": case_id, "title": f"Tarefa fictícia {TOKEN}",
            "assignee_id": a.user["id"], "due_at": "2026-10-02T11:45",
        }, expected=(201,))
        task_id = int(task_result["id"])
        _, task_items, _, _ = a.request("GET", "/api/tasks")
        assert any(int(t["id"]) == task_id for t in task_items["items"])
        check("task creation, date serialization and listing")

        _, event_result, _, _ = a.request("POST", "/api/events", {
            "title": f"Reunião fictícia {TOKEN}", "event_type": "reuniao",
            "starts_at": "2026-10-03T10:30", "case_id": case_id,
        }, expected=(201,))
        assert event_result["id"]
        check("calendar event creation")

        _, finance_result, _, _ = a.request("POST", "/api/finance", {
            "description": f"Honorários fictícios {TOKEN}", "entry_type": "receita",
            "amount": "125.50", "case_id": case_id, "status": "pendente",
        }, expected=(201,))
        assert finance_result["id"]
        check("finance entry creation")

        boundary, body = multipart({"case_id": str(case_id)}, f"nota-{TOKEN}.txt", b"Fictional test file only.")
        _, doc_result, _, _ = a.request("POST", "/api/documents", raw=body,
                                        content_type=f"multipart/form-data; boundary={boundary}", expected=(201,))
        doc_id = int(doc_result["id"])
        _, docs, _, _ = a.request("GET", "/api/documents")
        assert any(int(d["id"]) == doc_id for d in docs["items"])
        _, _, download_headers, downloaded = a.request("GET", f"/api/documents/{doc_id}/download", expected=(200,))
        assert downloaded == b"Fictional test file only."
        check("private document upload, list and authenticated download")

        _, invited, _, _ = a.request("POST", "/api/team", {
            "name": "Pessoa de teste", "email": viewer_email, "role": "viewer",
        }, expected=(201,))
        temp_password = invited["temporary_password"]
        check("team invitation with one-time temporary password")

        viewer = Client()
        viewer.start()
        _, login, _, _ = viewer.request("POST", "/api/auth/login", {
            "email": viewer_email, "password": temp_password,
        })
        assert int(login["user"].get("must_change_password", 0)) == 1
        viewer.request("GET", "/api/clients", expected=(403,))
        viewer.request("GET", f"/api/documents/{doc_id}/download", expected=(403,))
        _, changed, _, _ = viewer.request("POST", "/api/auth/change-password", {
            "current_password": temp_password,
            "new_password": f"Viewer-{TOKEN}-Personal#2026",
            "confirm_password": f"Viewer-{TOKEN}-Personal#2026",
        })
        assert int(changed["user"].get("must_change_password", 1)) == 0
        viewer.request("GET", "/api/clients")
        _, _, _, viewer_download = viewer.request("GET", f"/api/documents/{doc_id}/download", expected=(200,))
        assert viewer_download == b"Fictional test file only."
        _, viewer_reports, _, _ = viewer.request("GET", "/api/reports")
        assert viewer_reports["finance"] == []
        viewer.request("GET", "/api/finance", expected=(403,))
        viewer.request("GET", "/api/team", expected=(403,))
        _, viewer_dashboard, _, _ = viewer.request("GET", "/api/dashboard")
        assert viewer_dashboard["stats"]["receivable"] is None
        viewer.request("POST", "/api/cases", {"client_id": client_id, "subject": "No permission", "area": "Cível"}, expected=(403,))
        check("forced password change, protected download and viewer financial-data restriction")
        _, staff_invite, _, _ = a.request("POST", "/api/team", {
            "name": "Pessoa de apoio teste", "email": staff_email, "role": "staff",
        }, expected=(201,))
        staff = Client()
        staff.start()
        staff.request("POST", "/api/auth/login", {
            "email": staff_email, "password": staff_invite["temporary_password"],
        })
        staff.request("POST", "/api/tasks", {"title": "Não liberar antes da troca"}, expected=(403,))
        staff.request("POST", "/api/auth/change-password", {
            "current_password": staff_invite["temporary_password"],
            "new_password": f"Staff-{TOKEN}-Personal#2026",
            "confirm_password": f"Staff-{TOKEN}-Personal#2026",
        })
        staff.request("GET", "/api/team", expected=(403,))
        _, staff_task, _, _ = staff.request("POST", "/api/tasks", {
            "title": f"Tarefa autoatribuída {TOKEN}", "assignee_id": staff.user["id"],
        }, expected=(201,))
        assert staff_task["id"]
        staff.request("GET", "/api/finance", expected=(403,))
        check("staff self-assignment without team access and finance denial")

        result_b = b.register(f"LexCloud TEST-{TOKEN}-B", owner_b, pass_b)
        tenant_b = int(result_b["user"]["tenant_id"])
        assert tenant_a != tenant_b
        _, b_cases, _, _ = b.request("GET", "/api/cases")
        assert all(int(item["id"]) != case_id for item in b_cases["items"])
        b.request("GET", f"/api/cases/{case_id}", expected=(404,))
        b.request("GET", f"/api/documents/{doc_id}/download", expected=(404,))
        b.request("POST", "/api/cases", {"client_id": client_id, "subject": "Cross tenant", "area": "Cível"}, expected=(404,))
        check("cross-tenant list, record, file and foreign-key denial")

        b.request("POST", "/api/clients", {"name": "Missing CSRF"}, csrf=False, expected=(419,))
        check("CSRF rejection for mutation without token")

        print(f"Integration checks passed: {len(CHECKS)}")
    finally:
        cleanup_ids = sorted(set(x for x in TENANTS if x))
        if cleanup_ids:
            args = ["php", str(ROOT / "tests" / "cleanup.php"), *map(str, cleanup_ids)]
            cleaned = subprocess.run(args, cwd=ROOT, text=True, capture_output=True)
            if cleaned.returncode:
                sys.stderr.write(cleaned.stdout + cleaned.stderr)
                raise RuntimeError("Test fixtures could not be safely cleaned; inspect the listed tenant IDs.")
            print(cleaned.stdout.strip())


if __name__ == "__main__":
    main()
