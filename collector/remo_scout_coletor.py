#!/usr/bin/env python3
"""
REMO SCOUT — coletor local da CBF.

Roda no Mac Mini (IP brasileiro), porque os sites da CBF recusam acesso de servidores fora do Brasil.
Só lê páginas públicas e repassa ao REMO SCOUT. Toda regra de negócio (lista branca, vínculo,
revisão) fica no servidor. CPF/documentos são removidos aqui E ignorados lá.

Configuração: arquivo ~/.config/remo-scout/coletor.env (chmod 600) com
    REMO_SCOUT_URL=https://...
    REMO_SCOUT_TOKEN=...
Uso: python3 remo_scout_coletor.py [--so atletas|intermediarios] [--paginas N] [--dry-run]
Só biblioteca padrão (Python 3.9+).
"""
import argparse
import hashlib
import html
import json
import os
import re
import socket
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

VERSION = "1.1.0"
UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36"
CBF_SITE = "https://www.cbf.com.br"
CBF_API = "https://www.cbf.com.br/api/cbf"
INTERMEDIARIOS = "https://intermediario.cbf.com.br/intermediarios-cadastrados"
SENSITIVE = re.compile(r"cpf|documento|^rg$|^rg_|passaporte|nome_m[aã]e|nome_pai|filiacao|endereco|telefone|celular|e_?mail", re.I)
CPF_LIKE = re.compile(r"\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b")


def log(msg):
    print(time.strftime("%Y-%m-%d %H:%M:%S"), msg, flush=True)


def load_config():
    path = os.path.expanduser(os.environ.get("REMO_SCOUT_ENV", "~/.config/remo-scout/coletor.env"))
    cfg = {}
    if os.path.exists(path):
        for line in open(path, encoding="utf-8"):
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, v = line.split("=", 1)
                cfg[k.strip()] = v.strip().strip('"').strip("'")
    cfg.update({k: v for k, v in os.environ.items() if k.startswith("REMO_SCOUT_")})
    if not cfg.get("REMO_SCOUT_URL") or not cfg.get("REMO_SCOUT_TOKEN"):
        sys.exit("Configuração ausente: defina REMO_SCOUT_URL e REMO_SCOUT_TOKEN em " + path)
    return cfg["REMO_SCOUT_URL"].rstrip("/"), cfg["REMO_SCOUT_TOKEN"]


def scrub(obj):
    """Remove recursivamente qualquer campo sensível (CPF, documentos, contatos pessoais)."""
    if isinstance(obj, dict):
        return {k: scrub(v) for k, v in obj.items() if not SENSITIVE.search(str(k))}
    if isinstance(obj, list):
        return [scrub(v) for v in obj]
    if isinstance(obj, str) and CPF_LIKE.search(obj) and not obj.startswith("data:image"):
        return CPF_LIKE.sub("[removido]", obj)
    return obj


class Http:
    def __init__(self, interval_ms):
        self.interval = max(0.8, interval_ms / 1000.0)
        self.last = 0.0
        self.requests = 0
        self.errors = 0

    def get(self, url, accept="application/json", retries=3):
        for attempt in range(retries):
            wait = self.interval - (time.time() - self.last)
            if wait > 0:
                time.sleep(wait)
            self.last = time.time()
            self.requests += 1
            req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": accept, "Accept-Language": "pt-BR"})
            try:
                with urllib.request.urlopen(req, timeout=40) as r:
                    return r.read().decode("utf-8", "replace")
            except urllib.error.HTTPError as e:
                if e.code in (404, 410):
                    return None
                log("CBF HTTP %s em %s (tentativa %d)" % (e.code, url, attempt + 1))
                if e.code == 429:  # limite de taxa: respeita Retry-After e desacelera o resto da execução
                    ra = e.headers.get("Retry-After") if e.headers else None
                    time.sleep(min(int(ra), 300) if ra and ra.isdigit() else 60)
                    self.interval = min(self.interval * 1.5, 10.0)
                    continue
            except (urllib.error.URLError, socket.timeout, ConnectionError) as e:
                log("CBF falha de conexão em %s: %s (tentativa %d)" % (url, e, attempt + 1))
            time.sleep(5 * (attempt + 1))
        self.errors += 1
        return None


class Scout:
    def __init__(self, base, token, dry=False):
        self.base, self.token, self.dry = base, token, dry

    def call(self, method, path, payload=None):
        if self.dry and method == "POST":
            return {"dry_run": True, "run_id": "dry"}
        data = json.dumps(payload).encode("utf-8") if payload is not None else None
        req = urllib.request.Request(self.base + "/api/coletor/" + path, data=data, method=method, headers={
            "Authorization": "Bearer " + self.token, "Accept": "application/json", "Content-Type": "application/json",
            "User-Agent": "remo-scout-coletor/" + VERSION})
        for attempt in range(3):
            try:
                with urllib.request.urlopen(req, timeout=120) as r:
                    return json.loads(r.read().decode("utf-8") or "{}")
            except urllib.error.HTTPError as e:
                body = e.read().decode("utf-8", "replace")[:300]
                if e.code in (401, 404, 409, 422, 423):
                    raise RuntimeError("REMO SCOUT recusou %s %s: HTTP %s %s" % (method, path, e.code, body))
                log("REMO SCOUT HTTP %s em %s (tentativa %d)" % (e.code, path, attempt + 1))
            except (urllib.error.URLError, socket.timeout, ConnectionError) as e:
                log("REMO SCOUT indisponível (%s), tentativa %d" % (e, attempt + 1))
            time.sleep(10 * (attempt + 1))
        raise RuntimeError("REMO SCOUT indisponível para %s %s" % (method, path))


def competition_id(http, slug, season):
    page = http.get("%s/futebol-brasileiro/atletas/%s/%s" % (CBF_SITE, slug, season), accept="text/html")
    if not page:
        return None
    m = re.search(r'competitionId\\?"\s*:\s*\\?"(\d+)', page)
    return m.group(1) if m else None


def start(scout, source):
    """Abre (ou retoma, se houver execução do dia inacabada) e devolve run_id e páginas já recebidas."""
    r = scout.call("POST", source + "/inicio", {"host": socket.gethostname(), "version": VERSION, "resume": True})
    if r.get("resumed"):
        log("%s: retomando execução %s" % (source, r["run_id"]))
    return r["run_id"], {k: set(v) for k, v in (r.get("received") or {}).items()}


def run_athletes(http, scout, plan, season, max_pages):
    cfg = plan.get("cbf_atletas")
    if not cfg:
        log("cbf_atletas desligada no REMO SCOUT; pulando")
        return
    run, received = start(scout, "cbf_atletas")
    notes, manifest = [], {}
    errors_before = http.errors
    try:
        for comp in cfg["competitions"]:
            slug = comp["slug"]
            cid = competition_id(http, slug, comp.get("season", season))
            if not cid:
                notes.append("%s: competição não encontrada na CBF" % comp["label"])
                manifest[slug] = {"label": comp["label"], "found": False}
                continue
            done = received.get(slug, set())
            competition = {"slug": slug, "label": comp["label"], "season": comp.get("season", season), "cbf_competition_id": cid}
            total, sent, failed = 1, 0, []

            def fetch(page):
                nonlocal total, sent
                body = http.get("%s/atletas/campeonato/%s/pagina/%d" % (CBF_API, cid, page))
                if body is None:  # CBF instável (504)
                    return False
                data = json.loads(body)
                total = int(((data.get("meta") or [{}])[0]).get("total") or 1)
                rows = scrub(data.get("atletas") or [])
                if page not in done:  # retomada: página já recebida não é reenviada
                    scout.call("POST", "cbf_atletas/lote", {"run_id": run, "kind": "lista", "items": rows, "competition": competition,
                                                            "batch_key": "lista:%s:%d" % (slug, page), "page": page, "total_pages": total})
                    sent += len(rows)
                return True

            page = 1
            while page <= total and page <= max_pages:
                if page == 1 or page not in done:  # a página 1 é sempre lida para saber o total
                    if not fetch(page):
                        failed.append(page)
                page += 1
            if failed:  # segunda chance para páginas que falharam
                failed = [p for p in failed if not fetch(p)]
            manifest[slug] = {"label": comp["label"], "found": True, "total_pages": total, "limit": max_pages}
            if failed:
                notes.append("%s: páginas sem resposta da CBF: %s" % (comp["label"], ", ".join(map(str, failed))))
            log("%s (%s): %d atletas enviados, %d páginas%s%s" % (comp["label"], cid, sent, min(total, max_pages),
                                                               (" — já recebidas: %d" % len(done)) if done else "",
                                                               (" — falharam: %s" % failed) if failed else ""))
            notes.append("%s: %d atletas" % (comp["label"], sent))

        ids = scout.call("GET", "cbf_atletas/pendentes?limit=%d" % int(cfg.get("max_details", 150))).get("ids", []) if not scout.dry else []
        batch, done = [], 0
        for aid in ids:
            body = http.get("%s/atletas/%s" % (CBF_API, re.sub(r"\D", "", str(aid))))
            if body:
                d = scrub(json.loads(body))
                d.pop("jogos", None)  # súmulas por jogo não são usadas aqui
                batch.append(d)
            if len(batch) >= 10:
                send_details(scout, run, batch)
                done += len(batch)
                batch = []
        if batch:
            send_details(scout, run, batch)
            done += len(batch)
        log("detalhes enviados: %d de %d pedidos" % (done, len(ids)))
        notes.append("detalhes: %d de %d" % (done, len(ids)))
    finally:
        res = scout.call("POST", "cbf_atletas/fim", {"run_id": run, "requests_made": http.requests, "errors": http.errors - errors_before,
                                                     "messages": notes[:50], "manifest": manifest})
        log("cbf_atletas: %s" % json.dumps(res, ensure_ascii=False))


def send_details(scout, run, batch):
    ids = sorted(str(d.get("atleta_id") or d.get("id") or "") for d in batch)
    key = "detalhe:" + hashlib.sha1(",".join(ids).encode()).hexdigest()
    scout.call("POST", "cbf_atletas/lote", {"run_id": run, "kind": "detalhe", "items": batch, "batch_key": key})


ROW = re.compile(r"<tr>\s*<td[^>]*>(.*?)</td>\s*<td[^>]*>(.*?)</td>\s*<td[^>]*>(.*?)</td>\s*</tr>", re.S)


def run_intermediaries(http, scout, plan, max_pages):
    cfg = plan.get("cbf_intermediarios")
    if not cfg:
        log("cbf_intermediarios desligada no REMO SCOUT; pulando")
        return
    run, _ = start(scout, "cbf_intermediarios")
    errors_before, sent, page, notes = http.errors, 0, 1, []
    limit = min(int(cfg.get("max_pages", 120)), max_pages)
    try:
        while page <= limit:
            body = http.get("%s?page=%d" % (INTERMEDIARIOS, page), accept="text/html")
            if body is None:
                notes.append("falha na página %d" % page)
                break
            rows = [{"nome": html.unescape(re.sub(r"<[^>]+>", "", a)).strip(), "tipo": html.unescape(b).strip(), "publicacao": c.strip()}
                    for a, b, c in ROW.findall(body)]
            rows = scrub([r for r in rows if r["nome"] and r["nome"].upper() != "NOME"])
            if not rows:
                break
            scout.call("POST", "cbf_intermediarios/lote", {"run_id": run, "kind": "intermediarios", "items": rows, "batch_key": "intermediarios:%d" % page})
            sent += len(rows)
            if ("page=%d" % (page + 1)) not in body:
                break
            page += 1
        notes.append("%d intermediários em %d páginas" % (sent, page))
        log("intermediários enviados: %d (%d páginas)" % (sent, page))
    finally:
        res = scout.call("POST", "cbf_intermediarios/fim", {"run_id": run, "requests_made": http.requests, "errors": http.errors - errors_before, "messages": notes})
        log("cbf_intermediarios: %s" % json.dumps(res, ensure_ascii=False))


SOFA_ALLOWED = re.compile(r"^https://api\.sofascore\.com/api/v1/[A-Za-z0-9/_\-]+$")


def fetch_status(url, interval, last):
    """GET simples devolvendo (status, corpo); respeita o intervalo mínimo entre chamadas."""
    wait = interval - (time.time() - last[0])
    if wait > 0:
        time.sleep(wait)
    last[0] = time.time()
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "application/json", "Accept-Language": "pt-BR"})
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, None
    except (urllib.error.URLError, socket.timeout, ConnectionError):
        return 0, None


def run_tasks(scout, max_rounds=12):
    """Tarefas sob demanda (Sofascore): busca só os endereços que o servidor pediu e devolve; o servidor decide tudo."""
    res = scout.call("GET", "tarefas")
    tasks, interval, last, n = res.get("tarefas") or [], max(2.5, int(res.get("interval_ms", 2500)) / 1000.0), [0.0], 0
    for task in tasks:
        reqs = task.get("requests") or []
        for _ in range(max_rounds):
            reqs = [r for r in reqs if SOFA_ALLOWED.match(r.get("url", ""))]
            if not reqs:
                break
            answers = []
            for r in reqs:
                q = r.get("query") or {}
                url = r["url"] + ("?" + urllib.parse.urlencode(q) if q else "")
                status, body = fetch_status(url, interval, last)
                n += 1
                answers.append({"url": r["url"], "query": q, "status": status, "body": body})
            out = scout.call("POST", "tarefas/" + task["id"], {"responses": answers})
            if out.get("status") != "aguardando_coletor":
                log("tarefa %s: %s — %s" % (task["id"], out.get("status"), out.get("result")))
                break
            reqs = out.get("requests") or []
    if tasks:
        log("tarefas: %d atendidas, %d chamadas ao Sofascore" % (len(tasks), n))


def main():
    ap = argparse.ArgumentParser(description="Coletor local da CBF para o REMO SCOUT")
    ap.add_argument("--so", choices=["atletas", "intermediarios", "tarefas"])
    ap.add_argument("--paginas", type=int, default=1000, help="limite de páginas por lista (teste)")
    ap.add_argument("--dry-run", action="store_true", help="coleta mas não envia")
    args = ap.parse_args()
    base, token = load_config()
    scout = Scout(base, token, args.dry_run)
    if args.so == "tarefas":  # modo leve (a cada 15 min): só tarefas sob demanda, sem plano da CBF
        try:
            run_tasks(scout)
            return 0
        except Exception as e:
            log("ERRO em tarefas: %s" % e)
            return 1
    plan = scout.call("GET", "plano")
    http = Http(int(plan.get("interval_ms", 1500)))
    season = str(plan.get("season"))
    log("início — coletor %s, temporada %s" % (VERSION, season))
    ok = True
    for name, fn in (("tarefas", lambda: run_tasks(scout)),
                     ("atletas", lambda: run_athletes(http, scout, plan, season, args.paginas)),
                     ("intermediarios", lambda: run_intermediaries(http, scout, plan, args.paginas))):
        if args.so and args.so != name:
            continue
        try:
            fn()
        except Exception as e:  # uma fonte falhar não impede a outra
            ok = False
            log("ERRO em %s: %s" % (name, e))
    log("fim — %d requisições à CBF, %d falhas" % (http.requests, http.errors))
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
