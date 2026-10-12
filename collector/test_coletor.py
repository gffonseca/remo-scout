"""Testes do coletor (sem rede): retomada, segunda tentativa, chave por lote e manifesto. Rodar: python3 collector/test_coletor.py"""
import json
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(__file__))
import remo_scout_coletor as c  # noqa: E402

SLUG = "campeonato-brasileiro/serie-d"


class FakeHttp:
    def __init__(self, fail_once=()):
        self.calls, self.errors, self.requests = [], 0, 0
        self.fail_once = set(fail_once)

    def get(self, url, accept="application/json", retries=3):
        self.calls.append(url)
        self.requests += 1
        if "futebol-brasileiro/atletas" in url:
            return 'x competitionId\\":\\"555\\" x'
        page = int(url.rsplit("/", 1)[1])
        if page in self.fail_once:
            self.fail_once.discard(page)
            return None
        return json.dumps({"meta": [{"total": 3}], "atletas": [{"atleta_id": str(page), "atleta_cpf": "123.456.789-09"}]})


class FakeScout:
    dry = False

    def __init__(self, received=None):
        self.posts, self.received = [], received or {}

    def call(self, method, path, payload=None):
        self.posts.append((method, path, payload))
        if path.endswith("/inicio"):
            return {"run_id": "r1", "resumed": bool(self.received), "received": self.received}
        if path.startswith("cbf_atletas/pendentes"):
            return {"ids": []}
        return {"status": "ok"}


PLAN = {"cbf_atletas": {"competitions": [{"slug": SLUG, "label": "Série D", "season": "2026"}], "max_details": 0}}


class CollectorTest(unittest.TestCase):
    def lotes(self, scout):
        return [p for (_, path, p) in scout.posts if path == "cbf_atletas/lote"]

    def test_full_run_sends_keys_scrubs_cpf_and_manifest(self):
        http, scout = FakeHttp(), FakeScout()
        c.run_athletes(http, scout, PLAN, "2026", 1000)
        lotes = self.lotes(scout)
        self.assertEqual([l["batch_key"] for l in lotes], ["lista:%s:%d" % (SLUG, p) for p in (1, 2, 3)])
        self.assertTrue(all("atleta_cpf" not in i for l in lotes for i in l["items"]))
        fim = scout.posts[-1][2]
        self.assertEqual(fim["manifest"][SLUG], {"label": "Série D", "found": True, "total_pages": 3, "limit": 1000})

    def test_resume_skips_received_pages_but_reads_page_one_for_total(self):
        http, scout = FakeHttp(), FakeScout(received={SLUG: [1, 2]})
        c.run_athletes(http, scout, PLAN, "2026", 1000)
        self.assertEqual([l["page"] for l in self.lotes(scout)], [3])
        self.assertFalse(any(u.endswith("/pagina/2") for u in http.calls))
        self.assertTrue(any(u.endswith("/pagina/1") for u in http.calls))

    def test_failed_page_gets_second_chance(self):
        http, scout = FakeHttp(fail_once=[2]), FakeScout()
        c.run_athletes(http, scout, PLAN, "2026", 1000)
        self.assertEqual(sorted(l["page"] for l in self.lotes(scout)), [1, 2, 3])
        self.assertNotIn("páginas sem resposta", json.dumps(scout.posts[-1][2]["messages"], ensure_ascii=False))

    def test_competition_not_found_goes_to_manifest(self):
        http, scout = FakeHttp(), FakeScout()
        http.get = lambda url, **k: None
        c.run_athletes(http, scout, PLAN, "2026", 1000)
        self.assertEqual(scout.posts[-1][2]["manifest"][SLUG], {"label": "Série D", "found": False})


class TasksTest(unittest.TestCase):
    def test_fetches_only_allowed_urls_and_follows_server_rounds(self):
        fetched = []

        class S(FakeScout):
            def call(self, method, path, payload=None):
                self.posts.append((method, path, payload))
                if path == "tarefas":
                    return {"interval_ms": 2500, "tarefas": [{"id": "t1", "requests": [
                        {"url": "https://api.sofascore.com/api/v1/search/all", "query": {"q": "João"}},
                        {"url": "https://evil.test/api/v1/x", "query": {}}]}]}
                rnd = len([p for p in self.posts if p[1] == "tarefas/t1"])
                return {"status": "aguardando_coletor", "requests": [{"url": "https://api.sofascore.com/api/v1/player/9", "query": {}}]} if rnd == 1 \
                    else {"status": "concluida", "result": "ok"}

        orig = c.fetch_status
        c.fetch_status = lambda url, interval, last: (fetched.append(url), (200, "{}"))[1]
        try:
            scout = S()
            c.run_tasks(scout)
        finally:
            c.fetch_status = orig
        self.assertEqual(fetched, ["https://api.sofascore.com/api/v1/search/all?q=Jo%C3%A3o", "https://api.sofascore.com/api/v1/player/9"])
        self.assertEqual(len([p for p in scout.posts if p[1] == "tarefas/t1"]), 2)


if __name__ == "__main__":
    unittest.main()
