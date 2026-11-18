#!/usr/bin/env python3
"""
scripts/simulate_traffic.py
Traffic simulator for CasaLivre AI crawler and agent evaluation (Architecture §8).
"""
import argparse
import asyncio
import json
import sys
import time
from typing import Optional
import httpx

BOT_UAS = [
    'OAI-SearchBot',
    'ClaudeBot',
    'Google-Extended',
    'PerplexityBot',
]

HUMAN_UA = 'Mozilla/5.0 (compatible; CasaLivreHumanSim/1.0)'
MCP_UA = 'CasaLivre-MCP-Sim'

async def get_test_hash(client: httpx.AsyncClient, base_url: str) -> str:
    res = await client.get(f"{base_url}/api/v1/agent/search?localidade=Florianópolis")
    if res.status_code == 200:
        data = res.json()
        if data.get('items'):
            return data['items'][0]['id']
    # Fallback search without locality
    res2 = await client.get(f"{base_url}/api/v1/agent/search")
    if res2.status_code == 200:
        data = res2.json()
        if data.get('items'):
            return data['items'][0]['id']
    return '0123456789abcdef'

async def run_search_bots(base_url: str, public_hash: Optional[str], requests: int, concurrency: int) -> bool:
    print(f"[*] Running profile: search_bots ({requests} requests, concurrency {concurrency})")
    async with httpx.AsyncClient(timeout=10.0) as client:
        if not public_hash:
            public_hash = await get_test_hash(client, base_url)
            print(f"[*] Discovered listing hash: {public_hash}")

        sem = asyncio.Semaphore(concurrency)
        successes = 0
        total = 0

        async def worker(idx: int):
            nonlocal successes, total
            ua = BOT_UAS[idx % len(BOT_UAS)]
            headers = {'User-Agent': ua}
            headers_md = {'User-Agent': ua, 'Accept': 'text/markdown'}

            async with sem:
                r1 = await client.get(f"{base_url}/robots.txt", headers=headers)
                r2 = await client.get(f"{base_url}/llms.txt", headers=headers)
                r3 = await client.get(f"{base_url}/api/v1/agent/search?localidade=Florianópolis&hospedes=2", headers=headers)
                r4 = await client.get(f"{base_url}/imoveis/{public_hash}", headers=headers_md)

                total += 4
                if r1.status_code == 200:
                    successes += 1
                if r2.status_code == 200:
                    successes += 1
                if r3.status_code == 200:
                    successes += 1
                if r4.status_code == 200 and 'text/markdown' in r4.headers.get('content-type', ''):
                    successes += 1

        tasks = [worker(i) for i in range(requests // 4 or 1)]
        await asyncio.gather(*tasks)

        print(f"[+] search_bots: {successes}/{total} requests succeeded (200 OK)")
        return successes == total

async def run_mcp_agents(base_url: str, public_hash: Optional[str], requests: int) -> bool:
    print(f"[*] Running profile: mcp_agents")
    async with httpx.AsyncClient(timeout=10.0) as client:
        if not public_hash:
            public_hash = await get_test_hash(client, base_url)
            print(f"[*] Discovered listing hash: {public_hash}")

        headers = {'User-Agent': MCP_UA, 'Content-Type': 'application/json'}

        # 1. Initialize
        init_payload = {
            'jsonrpc': '2.0',
            'id': 1,
            'method': 'initialize',
            'params': {}
        }
        r_init = await client.post(f"{base_url}/mcp", headers=headers, json=init_payload)
        assert r_init.status_code == 200, f"mcp initialize failed: {r_init.text}"

        # 2. Tools list
        list_payload = {
            'jsonrpc': '2.0',
            'id': 2,
            'method': 'tools/list',
            'params': {}
        }
        r_list = await client.post(f"{base_url}/mcp", headers=headers, json=list_payload)
        assert r_list.status_code == 200

        # 3. Call search_properties
        call_search = {
            'jsonrpc': '2.0',
            'id': 3,
            'method': 'tools/call',
            'params': {
                'name': 'search_properties',
                'arguments': {'localidade': 'Florianópolis', 'hospedes': 2}
            }
        }
        r_search = await client.post(f"{base_url}/mcp", headers=headers, json=call_search)
        assert r_search.status_code == 200

        # 4. Call get_property_details
        call_prop = {
            'jsonrpc': '2.0',
            'id': 4,
            'method': 'tools/call',
            'params': {
                'name': 'get_property_details',
                'arguments': {'public_hash': public_hash}
            }
        }
        r_prop = await client.post(f"{base_url}/mcp", headers=headers, json=call_prop)
        assert r_prop.status_code == 200

        # 5. Call check_availability_and_quote
        call_quote = {
            'jsonrpc': '2.0',
            'id': 5,
            'method': 'tools/call',
            'params': {
                'name': 'check_availability_and_quote',
                'arguments': {
                    'public_hash': public_hash,
                    'checkin': '2028-06-01',
                    'checkout': '2028-06-05',
                    'hospedes': 2
                }
            }
        }
        r_quote = await client.post(f"{base_url}/mcp", headers=headers, json=call_quote)
        assert r_quote.status_code == 200

        # 6. Call create_booking_intent
        call_intent = {
            'jsonrpc': '2.0',
            'id': 6,
            'method': 'tools/call',
            'params': {
                'name': 'create_booking_intent',
                'arguments': {
                    'public_hash': public_hash,
                    'checkin': '2028-06-01',
                    'checkout': '2028-06-05',
                    'hospedes': 2,
                    'plan': 'pix_a_vista'
                }
            }
        }
        r_intent = await client.post(f"{base_url}/mcp", headers=headers, json=call_intent)
        assert r_intent.status_code == 200
        data = r_intent.json()
        text_content = data['result']['content'][0]['text']
        intent_res = json.loads(text_content)
        assert 'checkout_url' in intent_res, f"Expected checkout_url in intent result: {intent_res}"
        assert '/pagamento/intencao/' in intent_res['checkout_url']

        print(f"[+] mcp_agents: All tools/calls executed successfully! Intent checkout_url: {intent_res['checkout_url']}")
        return True

async def run_human_mix(base_url: str, requests: int) -> bool:
    print(f"[*] Running profile: human_mix ({requests} requests)")
    async with httpx.AsyncClient(timeout=10.0) as client:
        headers = {'User-Agent': HUMAN_UA}
        successes = 0
        total = 0
        for _ in range(requests // 2 or 1):
            r1 = await client.get(f"{base_url}/", headers=headers)
            r2 = await client.get(f"{base_url}/api/busca?localidade=Florianópolis", headers=headers)
            total += 2
            if r1.status_code == 200:
                successes += 1
            if r2.status_code == 200:
                successes += 1
        print(f"[+] human_mix: {successes}/{total} requests succeeded (200 OK)")
        return successes == total

async def run_spike_abuse(base_url: str, public_hash: Optional[str]) -> bool:
    print(f"[*] Running profile: spike_abuse (30 rapid intent requests)")
    async with httpx.AsyncClient(timeout=10.0) as client:
        if not public_hash:
            public_hash = await get_test_hash(client, base_url)
            print(f"[*] Discovered listing hash: {public_hash}")

        headers = {'User-Agent': 'OAI-SearchBot', 'Content-Type': 'application/json'}
        payload = {
            'checkin': '2028-07-01',
            'checkout': '2028-07-05',
            'hospedes': 2,
            'plan': 'pix_a_vista'
        }

        statuses = []
        for i in range(30):
            res = await client.post(f"{base_url}/api/v1/agent/properties/{public_hash}/intent", headers=headers, json=payload)
            statuses.append(res.status_code)

        count_201 = statuses.count(201)
        count_429 = statuses.count(429)
        print(f"[+] spike_abuse results: 201 Created = {count_201}, 429 Too Many Requests = {count_429}, Total = {len(statuses)}")
        assert count_429 > 0, f"Expected 429 rate limit or 3-hold cap to trigger, but got {statuses}"
        return True

async def main():
    parser = argparse.ArgumentParser(description="CasaLivre Traffic Simulator")
    parser.add_argument('--profile', '--scenario', dest='profile', choices=['search_bots', 'mcp_agents', 'human_mix', 'spike_abuse'], default='search_bots')
    parser.add_argument('--base-url', default='http://127.0.0.1:8080')
    parser.add_argument('--public-hash', default=None)
    parser.add_argument('--concurrency', type=int, default=5)
    parser.add_argument('--requests', type=int, default=20)
    parser.add_argument('--duration', type=int, default=30)
    args = parser.parse_args()

    base_url = args.base_url.rstrip('/')

    if args.profile == 'search_bots':
        ok = await run_search_bots(base_url, args.public_hash, args.requests, args.concurrency)
    elif args.profile == 'mcp_agents':
        ok = await run_mcp_agents(base_url, args.public_hash, args.requests)
    elif args.profile == 'human_mix':
        ok = await run_human_mix(base_url, args.requests)
    elif args.profile == 'spike_abuse':
        ok = await run_spike_abuse(base_url, args.public_hash)
    else:
        print(f"Unknown profile: {args.profile}")
        sys.exit(1)

    if ok:
        print(f"[SUCCESS] Profile {args.profile} passed all assertions.")
        sys.exit(0)
    else:
        print(f"[FAILURE] Profile {args.profile} failed assertions.")
        sys.exit(1)

if __name__ == '__main__':
    asyncio.run(main())
