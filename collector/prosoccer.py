#!/usr/bin/env python3
"""
CIFUT Remo — coletor Pro Soccer (portal de gestão do próprio Remo).

Roda no Mac Mini com a conta do clube. A senha fica no Chaves (Keychain) do macOS, nunca em arquivo, log ou repositório:
    security add-generic-password -s cifut-prosoccer -a <email-da-conta> -w
(o comando pede a senha sem mostrá-la). Configuração em ~/.config/remo-scout/coletor.env:
    PROSOCCER_EMAIL=<email-da-conta>

Escopo aprovado pelo Gustavo (12/10/2026): cadastro do elenco, jogos oficiais, minutagem, notas de jogo, súmula/suspensão,
dados físicos e técnicos dos relatórios. NUNCA (nem na descoberta): cadastro/resumo do atleta (CPF, contatos), documentos (CPF/RG), Saúde e Performance (lesão, CK, gordura, massa magra,
suplementos), financeiro, segurança, usuários, cláusulas e valores de contrato.

Etapa atual: --descobrir. Faz login, lê só as páginas da lista branca e grava uma amostra da estrutura (HTML sem scripts,
sem atributos de URL/sessão) em ~/remo-scout-coletor/prosoccer-amostras (chmod 700), para escrever os leitores de cada página.
Nada é enviado ao servidor nesta etapa.
Só biblioteca padrão (Python 3.9+).
"""
import argparse
import html.parser
import http.cookiejar
import os
import re
import subprocess
import sys
import time
import urllib.parse
import urllib.request

BASE = "https://portal.prosoccerapp.com.br"
UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36"
KEYCHAIN_SERVICE = "cifut-prosoccer"

# Lista branca de páginas (somente leitura). Qualquer outro caminho é recusado antes da requisição.
ALLOW = [
    "/BancoDeAtletas/Index",
    "/Minutagem/Index",
    "/JogosOficiais/Index",
    "/Notas/Index",
    "/NotasPeriodoPosicao/Index",
    "/Sumula/Index",
    "/Suspensao/Index",
    "/Temporada/Index",
]
# Nunca acessar, mesmo que um link apareça na página.
DENY = re.compile(r"Documento|ResumoAtleta|/Atletas/|Lesao|/CK/|Gordura|MassaMagra|Suplemento|Financeiro|Seguranca|Usuarios|Clausula|Hotel|Logout|Save|Excluir|Delete|Salvar|Enviar",
                  re.I)


# Redação de conteúdo na amostra: CPF/RG/telefones/e-mails nunca saem da página (mesmo em páginas permitidas).
REDACT = [
    (re.compile(r"\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b"), "[cpf]"),
    (re.compile(r"[\w.+-]+@[\w-]+\.[\w.]+"), "[email]"),
    (re.compile(r"\(?\d{2}\)?\s?9?\d{4}-?\d{4}"), "[telefone]"),
    (re.compile(r"\d{6,}"), "[n]"),
]


def redact(text):
    for rx, rep in REDACT:
        text = rx.sub(rep, text)
    return text


class SafeRedirect(urllib.request.HTTPRedirectHandler):
    """Só segue redirecionamento https no mesmo host e para caminho não proibido."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        u = urllib.parse.urlparse(newurl)
        if u.scheme != "https" or u.netloc != urllib.parse.urlparse(BASE).netloc or DENY.search(u.path):
            raise RuntimeError("Redirecionamento recusado para " + u.scheme + "://" + u.netloc + u.path)
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def log(msg):
    print(time.strftime("%Y-%m-%d %H:%M:%S"), "[prosoccer]", msg, flush=True)


def config():
    path = os.path.expanduser(os.environ.get("REMO_SCOUT_ENV", "~/.config/remo-scout/coletor.env"))
    cfg = {}
    if os.path.exists(path):
        for line in open(path, encoding="utf-8"):
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, v = line.split("=", 1)
                cfg[k.strip()] = v.strip().strip('"').strip("'")
    email = os.environ.get("PROSOCCER_EMAIL") or cfg.get("PROSOCCER_EMAIL")
    if not email:
        sys.exit("Defina PROSOCCER_EMAIL em " + path)
    return email


def password(email):
    """Senha do Chaves do macOS. Nunca é impressa nem gravada."""
    try:
        out = subprocess.run(["security", "find-generic-password", "-s", KEYCHAIN_SERVICE, "-a", email, "-w"],
                             capture_output=True, text=True, check=True, timeout=30)
    except (subprocess.CalledProcessError, FileNotFoundError, subprocess.TimeoutExpired):
        sys.exit("Senha não encontrada no Chaves. Rode: security add-generic-password -s %s -a %s -w" % (KEYCHAIN_SERVICE, email))
    return out.stdout.rstrip("\n")


class FormFinder(html.parser.HTMLParser):
    """Acha o formulário que tem campo de senha: ação e campos ocultos (token antifalsificação)."""

    def __init__(self):
        super().__init__()
        self.forms, self.cur = [], None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "form":
            self.cur = {"action": a.get("action") or "", "method": (a.get("method") or "post").lower(), "inputs": []}
            self.forms.append(self.cur)
        elif tag == "input" and self.cur is not None:
            self.cur["inputs"].append({"name": a.get("name"), "type": (a.get("type") or "text").lower(), "value": a.get("value") or ""})

    def handle_endtag(self, tag):
        if tag == "form":
            self.cur = None


class Session:
    def __init__(self, interval=2.0):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), SafeRedirect())
        self.interval, self.last, self.requests = interval, 0.0, 0

    def _open(self, url, data=None):
        wait = self.interval - (time.time() - self.last)
        if wait > 0:
            time.sleep(wait)
        self.last = time.time()
        self.requests += 1
        req = urllib.request.Request(url, data=data, headers={"User-Agent": UA, "Accept-Language": "pt-BR",
                                                              "Accept": "text/html,application/json;q=0.9,*/*;q=0.8"})
        with self.op.open(req, timeout=60) as r:
            return r.geturl(), r.read().decode("utf-8", "replace")

    def login(self, email, pwd):
        url, page = self._open(BASE + "/")
        ff = FormFinder()
        ff.feed(page)
        form = next((f for f in ff.forms if any(i["type"] == "password" for i in f["inputs"])), None)
        if not form:
            raise RuntimeError("Formulário de login não encontrado (a página mudou?)")
        fields = {}
        for i in form["inputs"]:
            if not i["name"]:
                continue
            if i["type"] == "password":
                fields[i["name"]] = pwd
            elif i["type"] in ("email", "text") and not i["value"]:
                fields[i["name"]] = email
            elif i["type"] in ("hidden", "checkbox") or i["value"]:
                fields[i["name"]] = i["value"]
        action = urllib.parse.urljoin(url, form["action"] or url)
        a = urllib.parse.urlparse(action)
        if a.scheme != "https" or a.netloc != urllib.parse.urlparse(BASE).netloc:
            raise RuntimeError("Login apontaria para outro domínio ou sem https; abortado")
        final, body = self._open(action, urllib.parse.urlencode(fields).encode())
        if any(i["type"] == "password" for f in self._forms(body) for i in f["inputs"]):
            raise RuntimeError("Login recusado (conta ou senha). Nada foi coletado.")
        # confirma a sessão numa página permitida (um 2º fator ou tela intermediária não conta como login)
        final, probe = self._open(BASE + ALLOW[0])
        if urllib.parse.urlparse(final).path != ALLOW[0] or any(i["type"] == "password" for f in self._forms(probe) for i in f["inputs"]):
            raise RuntimeError("Sessão não confirmada (2º fator ou tela intermediária?). Nada foi coletado.")
        log("login ok (%d cookies de sessão)" % len(self.jar))

    @staticmethod
    def _forms(body):
        ff = FormFinder()
        ff.feed(body)
        return ff.forms

    def get(self, path):
        if path not in ALLOW or DENY.search(path):
            raise RuntimeError("Caminho fora da lista branca: " + path)
        return self._open(BASE + path)[1]


class Skeleton(html.parser.HTMLParser):
    """Estrutura da página sem scripts/estilos e sem valores de atributos que possam carregar sessão ou URL assinada."""
    KEEP = {"id", "class", "title", "colspan", "rowspan", "data-label", "name", "type"}

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.out, self.skip = [], 0

    def handle_starttag(self, tag, attrs):
        if tag in ("script", "style", "svg", "noscript"):
            self.skip += 1
            return
        if self.skip:
            return
        kept = []
        for k, v in attrs:
            if k in self.KEEP:
                kept.append('%s="%s"' % (k, redact(v or "")[:80]))
            elif k in ("onclick", "onchange", "href", "src", "action"):
                # só o "formato" (nome da função / caminho), sem parâmetros
                shape = re.sub(r"[?#].*$", "", v or "")
                shape = re.sub(r"'[^']*'|\"[^\"]*\"", "'S'", shape)  # textos viram S; números longos (IDs) viram N
                shape = re.sub(r"\d{3,}", "N", shape)
                kept.append('%s="%s"' % (k, shape[:80]))
        self.out.append("<%s%s>" % (tag, (" " + " ".join(kept)) if kept else ""))

    def handle_endtag(self, tag):
        if tag in ("script", "style", "svg", "noscript"):
            self.skip = max(0, self.skip - 1)
            return
        if not self.skip:
            self.out.append("</%s>" % tag)

    def handle_data(self, data):
        if not self.skip and data.strip():
            self.out.append(redact(re.sub(r"\s+", " ", data.strip()))[:120])


def discover(sess, outdir):
    os.makedirs(outdir, mode=0o700, exist_ok=True)
    os.chmod(outdir, 0o700)
    for path in ALLOW:
        try:
            body = sess.get(path)
        except Exception as e:  # noqa: BLE001 — página indisponível não interrompe a descoberta
            log("%s: falhou (%s)" % (path, e))
            continue
        sk = Skeleton()
        sk.feed(body)
        name = path.strip("/").replace("/", "_") + ".txt"
        fn = os.path.join(outdir, name)
        with open(fn, "w", encoding="utf-8") as f:
            f.write("\n".join(sk.out))
        os.chmod(fn, 0o600)
        log("%s: %d KB → %s" % (path, len(body) // 1024, name))


def main():
    ap = argparse.ArgumentParser(description="Coletor Pro Soccer do CIFUT Remo")
    ap.add_argument("--descobrir", action="store_true", help="grava a estrutura das páginas da lista branca (não envia nada)")
    ap.add_argument("--saida", default="~/remo-scout-coletor/prosoccer-amostras")
    args = ap.parse_args()
    if not args.descobrir:
        sys.exit("Por enquanto só --descobrir: os leitores de cada página são escritos a partir da amostra.")
    email = config()
    sess = Session()
    sess.login(email, password(email))
    discover(sess, os.path.expanduser(args.saida))
    log("concluído: %d requisições" % sess.requests)


if __name__ == "__main__":
    main()
