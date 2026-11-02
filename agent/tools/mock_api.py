"""Faux serveur API pour tester GescomAgent en local, sans Laravel.

Il implemente exactement le contrat decrit dans README.md (section API), y compris
la regle de regroupement des produits (code_key = TRIM + MAJUSCULES). Donnees en memoire.

    python -I tools\\mock_api.py                         -> http://127.0.0.1:8085/api
    python -I tools\\mock_api.py --port 9000 --codes AAA-111 BBB-222

Agent     : POST /api/agents/register   GET /api/agent/ping   POST /api/agent/sync
            GET  /api/agent/pending     POST /api/agent/result
Tableau   : POST /api/refresh {"code": "820AC"}   POST /api/branch-sync {"branch_id": 1}
            GET  /api/search?q=820   GET /api/product?code=820AC   GET /api/state
"""
import argparse
import hashlib
import json
import secrets
import sys
import threading
from datetime import datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

LOCK = threading.Lock()
INSTALL_CODES = set()
BRANCHES = {}   # id -> {id, name, hostname, token_hash, last_seen, last_sync, db_file_mtime, agent_version}
STOCK = {}      # branch_id -> {code_raw: {code, designation, stock, updated_at}}
JOBS = {}       # id -> {id, branch_id, code, status, created, answered, result}


def now():
    return datetime.now().astimezone().isoformat(timespec="seconds")


def code_key(code):
    return (code or "").strip().upper()


def token_hash(token):
    return hashlib.sha256(token.encode()).hexdigest()


def prices(item):
    """Prix envoyes par l'agent 1.0.5 : detail (price), gros (price_wholesale), achat (price_buy). None avant."""
    return {k: item.get(k) for k in ("price", "price_wholesale", "price_buy")}


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        pass

    def reply(self, status, obj):
        body = json.dumps(obj, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def json_body(self):
        n = int(self.headers.get("Content-Length") or 0)
        return json.loads(self.rfile.read(n).decode("utf-8")) if n else {}

    def auth_branch(self):
        auth = self.headers.get("Authorization", "")
        if not auth.startswith("Bearer "):
            return None
        th = token_hash(auth[7:])
        for b in BRANCHES.values():
            if b["token_hash"] == th:
                b["last_seen"] = now()
                return b
        return None

    def do_GET(self):
        self.route("GET")

    def do_POST(self):
        self.route("POST")

    def route(self, method):
        url = urlparse(self.path)
        q = parse_qs(url.query)
        path = url.path.rstrip("/")
        try:
            with LOCK:
                if path == "/api/agents/register" and method == "POST":
                    return self.register()
                if path.startswith("/api/agent/"):
                    b = self.auth_branch()
                    if not b:
                        return self.reply(401, {"error": "token invalide"})
                    return self.agent(method, path, b)
                if path == "/api/refresh" and method == "POST":
                    return self.refresh()
                if path == "/api/branch-sync" and method == "POST":
                    bid = int(self.json_body().get("branch_id") or 0)
                    if bid not in BRANCHES:
                        return self.reply(404, {"error": "branche inconnue"})
                    jid = len(JOBS) + 1
                    JOBS[jid] = {"id": jid, "type": "sync", "branch_id": bid, "code": None, "status": "pending",
                                 "created": now(), "answered": None, "result": None}
                    return self.reply(200, {"job": jid})
                if path == "/api/search":
                    return self.search((q.get("q") or [""])[0])
                if path == "/api/product":
                    return self.product((q.get("code") or [""])[0])
                if path == "/api/state":
                    return self.reply(200, {
                        "branches": [{k: v for k, v in b.items() if k != "token_hash"} for b in BRANCHES.values()],
                        "stock_rows": {bid: len(rows) for bid, rows in STOCK.items()},
                        "jobs": list(JOBS.values())[-20:],
                    })
                return self.reply(404, {"error": "route inconnue"})
        except Exception as e:  # mock : on renvoie l'erreur telle quelle
            return self.reply(500, {"error": repr(e)})

    # ---------- agent ----------

    def register(self):
        d = self.json_body()
        code = (d.get("install_code") or "").strip()
        if code not in INSTALL_CODES:
            return self.reply(422, {"error": "code d'installation invalide ou deja utilise"})
        INSTALL_CODES.discard(code)
        bid = len(BRANCHES) + 1
        token = secrets.token_urlsafe(32)
        name = (d.get("branch_name") or "").strip() or f"Branche {bid}"
        BRANCHES[bid] = {"id": bid, "name": name, "hostname": d.get("hostname"), "token_hash": token_hash(token),
                         "last_seen": now(), "last_sync": None, "db_file_mtime": None,
                         "agent_version": d.get("agent_version")}
        print(f"[register] branche {bid} '{name}' ({d.get('hostname')})")
        return self.reply(201, {"branch_id": bid, "branch_name": name, "token": token})

    def agent(self, method, path, b):
        if path == "/api/agent/ping":
            return self.reply(200, {"ok": True, "branch_id": b["id"], "branch_name": b["name"]})

        if path == "/api/agent/sync" and method == "POST":
            d = self.json_body()
            items = d.get("items") or []
            ts = now()
            # remplace tout le stock de la branche : les articles absents ont ete supprimes dans Gescomm
            STOCK[b["id"]] = {it["code"]: {"code": it["code"], "designation": it.get("designation"),
                                           "stock": it.get("stock"), **prices(it), "updated_at": ts} for it in items}
            b.update(last_sync=ts, db_file_mtime=d.get("db_file_mtime"), agent_version=d.get("agent_version"))
            print(f"[sync] '{b['name']}' : {len(items)} articles")
            return self.reply(200, {"ok": True, "count": len(items)})

        if path == "/api/agent/pending" and method == "GET":
            jobs = [j for j in JOBS.values() if j["branch_id"] == b["id"] and j["status"] == "pending"]
            for j in jobs:
                j["status"] = "taken"
            return self.reply(200, {"jobs": [{"id": j["id"], "type": j.get("type", "code"), "code": j["code"]} for j in jobs]})

        if path == "/api/agent/result" and method == "POST":
            d = self.json_body()
            j = JOBS.get(d.get("job_id"))
            if not j or j["branch_id"] != b["id"]:
                return self.reply(404, {"error": "demande inconnue"})
            if j.get("type") == "sync":
                j.update(status="error" if d.get("error") else "done", answered=now(), result=d)
                print(f"[result] '{b['name']}' synchro {j['id']} -> {j['status']} ({d.get('count')} articles)")
                return self.reply(200, {"ok": True})
            j["status"] = "error" if d.get("error") else ("done" if d.get("found") else "not_found")
            j["answered"] = now()
            j["result"] = d
            rows = STOCK.setdefault(b["id"], {})
            for it in d.get("items") or []:
                rows[it["code"]] = {"code": it["code"], "designation": it.get("designation"),
                                    "stock": it.get("stock"), **prices(it), "updated_at": j["answered"]}
            print(f"[result] '{b['name']}' demande {j['id']} '{j['code']}' -> {j['status']} {d.get('items')}")
            return self.reply(200, {"ok": True})

        return self.reply(404, {"error": "route agent inconnue"})

    # ---------- tableau de bord ----------

    def refresh(self):
        k = code_key(self.json_body().get("code"))
        if not k:
            return self.reply(422, {"error": "code vide"})
        ids = []
        for b in BRANCHES.values():
            jid = len(JOBS) + 1
            JOBS[jid] = {"id": jid, "branch_id": b["id"], "code": k, "status": "pending",
                         "created": now(), "answered": None, "result": None}
            ids.append(jid)
        print(f"[refresh] '{k}' -> demandes {ids}")
        return self.reply(200, {"code": k, "jobs": ids})

    def search(self, term):
        t = term.strip().upper()
        all_rows = [(bid, r) for bid, rows in STOCK.items() for r in rows.values()]
        keys = sorted({code_key(r["code"]) for _, r in all_rows
                       if t in code_key(r["code"]) or t in (r["designation"] or "").upper()})[:50]
        results = []
        for k in keys:
            rows = [(bid, r) for bid, r in all_rows if code_key(r["code"]) == k]
            best = max(rows, key=lambda x: x[1]["stock"] or 0)[1]
            results.append({"code": k, "designation": best["designation"],
                            "total": round(sum(r["stock"] or 0 for _, r in rows), 2),
                            "branches": len({bid for bid, _ in rows}), "branches_total": len(BRANCHES)})
        return self.reply(200, {"results": results})

    def product(self, code):
        k = code_key(code)
        out = []
        for b in BRANCHES.values():
            rows = [r for r in STOCK.get(b["id"], {}).values() if code_key(r["code"]) == k]
            job = max((j for j in JOBS.values() if j["branch_id"] == b["id"] and j["code"] == k),
                      key=lambda j: j["id"], default=None)
            base = {"branch": b["name"], "last_seen": b["last_seen"],
                    "refresh_status": job["status"] if job else None}
            if rows:
                out += [dict(base, code=r["code"], designation=r["designation"], stock=r["stock"], **prices(r),
                             updated_at=r["updated_at"]) for r in rows]
            else:
                out.append(dict(base, code=None, designation=None, stock=None, **prices({}), updated_at=None))
        out.sort(key=lambda r: (r["stock"] is None, -(r["stock"] or 0)))
        return self.reply(200, {"code": k, "branches": out})


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int, default=8085)
    ap.add_argument("--codes", nargs="*", default=["TEST-1234"], help="codes d'installation acceptes")
    args = ap.parse_args()
    INSTALL_CODES.update(args.codes)
    sys.stdout.reconfigure(errors="replace", line_buffering=True)
    print(f"mock API sur http://127.0.0.1:{args.port}/api - codes d'installation : {', '.join(args.codes)}")
    ThreadingHTTPServer(("127.0.0.1", args.port), Handler).serve_forever()


if __name__ == "__main__":
    main()
