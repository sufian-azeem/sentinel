"""
screener.loader — fetch or load raw Orion Terminal screener data.

All HTTP I/O and file I/O lives here. No scoring or display logic.
"""

import json

try:
    from curl_cffi import requests as cffi_requests
    _CURL_CFFI = True
except ImportError:
    cffi_requests = None
    _CURL_CFFI = False

try:
    import requests as _requests
    _REQUESTS = True
except ImportError:
    _requests = None
    _REQUESTS = False


SCREENER_URL = "https://screener.orionterminal.com/api/screener"

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/124.0.0.0 Safari/537.36"
    ),
    "Accept": "application/json, text/plain, */*",
    "Accept-Language": "en-US,en;q=0.9",
    "Referer": "https://screener.orionterminal.com/",
    "Origin": "https://screener.orionterminal.com",
}


def _parse_response(data: dict | list) -> list[dict]:
    """Extract tickers list from API response."""
    return data.get("tickers", data) if isinstance(data, dict) else data


def fetch_screener_data() -> list[dict]:
    """
    Fetch tickers from the Orion Terminal screener API.

    Attempts in order:
    1. curl_cffi — Chrome TLS impersonation (fast, no browser needed)
    2. requests  — plain HTTP with browser headers (last resort)
    """
    if _CURL_CFFI:
        try:
            resp = cffi_requests.get(SCREENER_URL, impersonate="chrome124", timeout=20)
            resp.raise_for_status()
            return _parse_response(resp.json())
        except Exception as e:
            print(f"curl_cffi attempt failed ({e}), trying requests...")

    if _REQUESTS:
        resp = _requests.get(SCREENER_URL, headers=HEADERS, timeout=15)
        resp.raise_for_status()
        return _parse_response(resp.json())

    raise RuntimeError(
        "No HTTP library available. Install curl_cffi or requests:\n"
        "  pip install curl_cffi"
    )


def load_screener_data(path: str) -> list[dict]:
    """Load tickers from a local JSON file."""
    with open(path) as f:
        data = json.load(f)
    return data.get("tickers", data if isinstance(data, list) else [])
