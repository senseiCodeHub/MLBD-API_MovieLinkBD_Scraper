"""MovieLinkBD Stream Scraper — single-file web app with modern UI.

Run:
    pip install -r requirements.txt
    playwright install chromium
    python app.py

Then open http://localhost:8000 in your browser.
"""

from __future__ import annotations

import asyncio
from datetime import datetime, timezone
from typing import Literal

from fastapi import FastAPI
from fastapi.responses import HTMLResponse, JSONResponse
from pydantic import BaseModel
import uvicorn

from scraper.scraper import scrape_streams
from scraper.browser import browser_context
from scraper.exceptions import CloudflareBlocked, NoStreamFound, BrowserError, ScraperError


class StreamVariant(BaseModel):
    quality: str
    bandwidth: int | None = None
    url: str


class StreamSource(BaseModel):
    server_name: str | None = None
    type: Literal["hls", "mp4"]
    master_url: str
    variants: list[StreamVariant] = []


class SearchRequest(BaseModel):
    q: str


class ScrapeRequest(BaseModel):
    url: str


class ScrapeResult(BaseModel):
    page_url: str
    title: str | None = None
    sources: list[StreamSource] = []
    scraped_at: str = datetime.now(timezone.utc).isoformat()


# TODO: verify these selectors on the live movielinkbd.tv site.
SEARCH_URL_TEMPLATE = "https://movielinkbd.tv/search?q={query}"
SEARCH_RESULT_LINK_SELECTORS = [
    'a[href*="/movie/"]',
    'a[href*="/anime/"]',
    'a[href*="/drama/"]',
    'a[href*="/southIndian/"]',
    '.post-title a',
    '.entry-title a',
]
TITLE_SELECTOR = "h1, .entry-title, .post-title"

# TODO: verify these on the live site too.
PLAY_BUTTON_SELECTORS = [
    'button:has-text("Play")',
    'button:has-text("Watch Now")',
    'a:has-text("Play")',
]
SERVER_BUTTON_SELECTORS = [
    'button:has-text("Server")',
    '.server-list li a',
]
IFRAME_SELECTOR = (
    'iframe[src*="player"], iframe[src*="embed"], iframe[src*="stream"]'
)


HTML_PAGE = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MLBD Stream Scraper</title>
<style>
:root{
  --primary:#3b82f6;
  --primary-hover:#2563eb;
  --success:#10b981;
  --danger:#ef4444;
  --bg:#0f172a;
  --card:#1e293b;
  --card-hover:#334155;
  --text:#f8fafc;
  --muted:#94a3b8;
  --border:#334155;
  --shadow:0 10px 15px -3px rgb(0 0 0 / 0.3), 0 4px 6px -4px rgb(0 0 0 / 0.2);
}
* { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  background: var(--bg); color: var(--text); line-height: 1.5; padding: 0;
}
.container { max-width: 1100px; margin: 0 auto; padding: 32px 20px; }
header { margin-bottom: 30px; }
.brand {
  display: flex; align-items: center; gap: 12px; margin-bottom: 8px;
}
.brand .dot {
  width: 10px; height: 10px; background: var(--primary); border-radius: 50%;
  box-shadow: 0 0 0 4px rgba(37,99,235,0.15);
}
h1 { font-size: 1.75rem; font-weight: 700; letter-spacing: -0.025em; }
.subtitle { color: var(--muted); font-size: 0.95rem; }
.tabs {
  display: flex; gap: 6px; background: var(--border); border-radius: 10px; padding: 4px; margin-bottom: 26px;
}
.tab {
  flex: 1; padding: 10px 16px; border: none; border-radius: 8px; background: transparent;
  color: var(--muted); font-size: 0.95rem; font-weight: 500; cursor: pointer; transition: all .2s;
}
.tab.active { background: var(--primary); color: #fff; }
.tab:hover:not(.active) { background: #f1f5f9; color: var(--text); }
.panel {
  background: var(--card); border: 1px solid var(--border); border-radius: 14px;
  padding: 24px; box-shadow: var(--shadow); margin-bottom: 26px;
}
.search-area { display: flex; gap: 10px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
input {
  flex: 1; min-width: 240px; padding: 12px 16px; border: 1px solid var(--border);
  border-radius: 10px; font-size: 1rem; color: var(--text); outline: none;
  transition: border-color .2s, box-shadow .2s;
}
input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
.btn {
  padding: 12px 22px; border: none; border-radius: 10px; font-size: 1rem; font-weight: 600;
  cursor: pointer; transition: all .2s; white-space: nowrap;
}
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-hover); }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-outline { background: #334155; color: var(--text); border: 1px solid var(--border); }
.btn-outline:hover { border-color: var(--primary); color: #93c5fd; }
.btn-success { background: var(--success); color: #fff; }
.btn-success:hover { background: #047857; }
.btn-success:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-copy {
  background: #334155; color: var(--text); border: 1px solid var(--border);
  padding: 6px 12px; border-radius: 8px; font-size: 0.85rem; cursor: pointer;
}
.btn-copy:hover { background: #475569; }
.status {
  padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; display: none;
  font-size: 0.9rem; font-weight: 500; animation: slideIn .3s ease;
}
@keyframes slideIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
.status.ok { background: #064e3b; border: 1px solid #10b981; color: #6ee7b7; }
.status.err { background: #7f1d1d; border: 1px solid #ef4444; color: #fca5a5; }
.results-header {
  display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;
}
.results-count { font-size: 0.85rem; color: var(--muted); }
.result-card {
  border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px;
  margin-bottom: 10px; display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;
  transition: box-shadow .2s, border-color .2s;
}
.result-card:hover { box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.4); border-color: var(--primary); }
.result-info { flex: 1; min-width: 0; }
.result-title {
  font-weight: 700; color: var(--text); margin-bottom: 2px; font-size: 1rem;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.result-meta { font-size: 0.78rem; color: var(--muted); margin-bottom: 6px; }
.result-url {
  font-size: 0.75rem; color: #60a5fa; word-break: break-all; background: #0f172a;
  padding: 4px 8px; border-radius: 6px; border: 1px dashed var(--border); display: block; margin-top: 4px;
}
.json-area {
  margin-top: 16px; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; background: #020617;
}
.json-header {
  background: #0f172a; padding: 10px 14px; border-bottom: 1px solid var(--border);
  display: flex; justify-content: space-between; align-items: center;
}
.json-label {
  font-size: 0.75rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em;
}
pre.json {
  margin: 0; padding: 16px; overflow: auto; font-size: 0.8rem; line-height: 1.4;
  color: #e2e8f0; font-family: "SF Mono", "Monaco", "Consolas", "Courier New", monospace;
}
.empty-state {
  text-align: center; padding: 48px 20px; color: var(--muted);
}
.empty-state .emoji { font-size: 2.5rem; margin-bottom: 12px; display: block; }
.empty-state .title { font-weight: 600; color: var(--text); margin-bottom: 6px; font-size: 1rem; }
.debug-box {
  background: #451a03; border: 1px solid #b45309; border-radius: 8px; padding: 12px;
  margin-top: 14px; font-size: 0.8rem; color: #fbbf24;
}
.debug-box .debug-title { font-weight: 600; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
.debug-box pre { margin: 0; white-space: pre-wrap; word-break: break-all; color: #fdba74; }
.nav-link {
  margin-left: auto; color: #60a5fa; text-decoration: none; font-size: 0.85rem; font-weight: 500;
}
.nav-link:hover { text-decoration: underline; }
.footer { color: var(--muted); font-size: 0.8rem; margin-top: 30px; border-top: 1px solid var(--border); padding-top: 20px; }
@media (max-width: 640px) {
  .container { padding: 16px; }
  .search-area { flex-direction: column; align-items: stretch; }
  .search-area input, .search-area .btn { width: 100%; }
  .result-card { flex-direction: column; align-items: stretch; gap: 10px; }
}
</style>
</head>
<body>
<div class="container">
  <header>
    <div class="brand">
      <div class="dot"></div>
      <div>
        <h1>MLBD Stream Scraper</h1>
        <div class="subtitle">Search movielinkbd.tv · Get stream sources as JSON</div>
      </div>
      <a class="nav-link" href="/docs" target="_blank">API Docs</a>
    </div>
  </header>

  <div class="tabs">
    <div class="tab active" onclick="showTab('by-name')">Search by Name</div>
    <div class="tab" onclick="showTab('by-url')">By Detail URL</div>
  </div>

  <div class="panel">
    <div id="tab-by-name" class="tab-content active">
      <div class="search-area">
        <input type="text" id="q" placeholder="e.g. Avengers, Naruto, Demon Slayer">
        <button class="btn btn-primary" id="btn-name">Search</button>
      </div>
      <div id="name-status" class="status"></div>
      <div id="name-results"></div>
      <div id="name-json-container"></div>
    </div>

    <div id="tab-by-url" class="tab-content" style="display:none">
      <div class="search-area">
        <input type="url" id="url" placeholder="https://movielinkbd.tv/movie/xyz">
        <button class="btn btn-primary" id="btn-url">Scrape</button>
      </div>
      <div id="url-status" class="status"></div>
      <div id="url-json-container"></div>
    </div>
  </div>

  <div class="footer">
    MLBD Stream Scraper &mdash; returns scraped stream metadata as JSON. No video playback.
  </div>
</div>

<script>
function showTab(name){
  document.querySelectorAll('.tab-content').forEach(el=>el.style.display='none');
  document.querySelectorAll('.tab').forEach(el=>el.classList.remove('active'));
  document.getElementById('tab-'+name).style.display='block';
  event.target.classList.add('active');
}

function escapeHtml(t){
  if(!t)return '';
  return t.replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}
function escapeJs(t){
  if(!t)return '';
  return t.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
}

function setStatus(id,text,ok){
  const el=document.getElementById(id);
  el.style.display='block';
  el.className='status '+(ok?'ok':'err');
  el.textContent=text;
}

function jsonEl(id,label,data){
  const container=document.getElementById(id);
  container.innerHTML='';
  const area=document.createElement('div');
  area.className='json-area';
  const header=document.createElement('div');
  header.className='json-header';
  const labelEl=document.createElement('div');
  labelEl.className='json-label';
  labelEl.textContent=label;
  const copy=document.createElement('button');
  copy.className='btn-copy';
  copy.textContent='Copy JSON';
  copy.onclick=()=>{
    navigator.clipboard.writeText(JSON.stringify(data,null,2));
    const old=copy.textContent; copy.textContent='Copied!';
    setTimeout(()=>copy.textContent=old,1200);
  };
  header.appendChild(labelEl);
  header.appendChild(copy);
  const pre=document.createElement('pre');
  pre.className='json';
  pre.textContent=JSON.stringify(data,null,2);
  area.appendChild(header);
  area.appendChild(pre);
  container.appendChild(area);
  return area;
}

function renderResults(kind,data){
  const resultsId=kind==='by-name'?'name-results':null;
  const jsonId=kind==='by-name'?'name-json-container':'url-json-container';
  const statusId=kind==='by-name'?'name-status':'url-status';
  const resList=document.getElementById(resultsId);
  if(resList)resList.innerHTML='';
  jsonEl(jsonId,'Scraped Result',data);

  if(kind==='by-name' && data.data){
    const results=data.data.results||[];
    if(results.length===0){
      const empty=document.createElement('div');
      empty.className='empty-state';
      empty.innerHTML='<span class="emoji">🔍</span><div class="title">No results found</div>'+(data.data.message?'<div style="margin-top:6px;font-size:0.85rem;color:var(--muted)">'+escapeHtml(data.data.message)+'</div>':'');
      if(resList)resList.appendChild(empty);
    } else {
      results.forEach(function(r){
        const card=document.createElement('div');
        card.className='result-card';
        const info=document.createElement('div');
        info.className='result-info';
        const title=document.createElement('div');
        title.className='result-title';
        title.textContent=r.title||'(no title)';
        const meta=document.createElement('div');
        meta.className='result-meta';
        meta.textContent=(r.type||'')+'  ·  '+escapeHtml(r.url||'');
        info.appendChild(title);
        info.appendChild(meta);
        const urlDiv=document.createElement('div');
        urlDiv.className='result-url';
        urlDiv.textContent=r.url||'';
        const btn=document.createElement('button');
        btn.className='btn btn-success';
        btn.textContent='Scrape streams';
        btn.onclick=function(){ scrapeUrl(r.url); };
        card.appendChild(info);
        card.appendChild(urlDiv);
        card.appendChild(btn);
        if(resList)resList.appendChild(card);
      });
      if(data.data.debug_links && data.data.debug_links.length>0){
        const dbg=document.createElement('div');
        dbg.className='debug-box';
        const t=document.createElement('div');
        t.className='debug-title';
        t.innerHTML='🔗 All links found on search page (first '+data.data.debug_links.length+')';
        const pre=document.createElement('pre');
        pre.textContent=data.data.debug_links.map(function(l){return escapeHtml(l.text||'(no text)')+' → '+escapeHtml(l.url);}).join('\\n');
        dbg.appendChild(t);
        dbg.appendChild(pre);
        const parent=resList.parentNode;
        parent.insertBefore(dbg,parent.nextSibling);
      }
      if(data.data.hint){
        const h=document.createElement('div');
        h.style.marginTop='10px'; h.style.fontSize='0.8rem'; h.style.color=varGet('--muted','#64748b');
        h.textContent=data.data.hint;
        const parent=resList.parentNode;
        parent.insertBefore(h,parent.nextSibling);
      }
    }
  }
}

function varGet(name,fallback){
  try{return getComputedStyle(document.documentElement).getPropertyValue(name).trim()||fallback;}catch(e){return fallback;}
}

async function run(kind){
  const btn=document.getElementById('btn-'+kind);
  const inp=document.getElementById(kind==='by-name'?'q':'url');
  const val=inp.value.trim();
  if(!val)return;
  btn.disabled=true;
  btn.textContent='Working...';
  const statusId=kind==='by-name'?'name-status':'url-status';
  const jsonId=kind==='by-name'?'name-json-container':'url-json-container';
  document.getElementById(jsonId).innerHTML='';
  setStatus(statusId,'',true);
  try{
    const body=kind==='by-name'?{q:val}:{url:val};
    const res=await fetch('/'+kind,{
      method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)
    });
    const data=await res.json();
    if(data.ok){
      const cnt=data.data&&data.data.count!==undefined?' — '+data.data.count+' result(s)':'';
      setStatus(statusId,'Success'+cnt,true);
      renderResults(kind,data);
    }else{
      setStatus(statusId,'Error: '+(data.error||'Unknown')+(data.error_type?' ('+data.error_type+')':'' ),false);
      jsonEl(jsonId,'Error Response',data);
      if(data.debug){
        const dbg=document.createElement('div');
        dbg.className='debug-box';
        const t=document.createElement('div');
        t.className='debug-title'; t.textContent='Debug info';
        const pre=document.createElement('pre');
        pre.textContent=JSON.stringify(data.debug,null,2);
        dbg.appendChild(t); dbg.appendChild(pre);
        const target=document.getElementById(jsonId);
        target.parentNode.insertBefore(dbg,target.nextSibling);
      }
    }
  }catch(e){
    setStatus(statusId,'Request failed: '+e.message,false);
    jsonEl(jsonId,'Error Response',{error:e.message});
  }finally{
    btn.disabled=false;
    btn.textContent=kind==='by-name'?'Search':'Scrape';
  }
}

async function scrapeUrl(url){
  const btns=document.querySelectorAll('.btn-success');
  btns.forEach(function(b){if(b.textContent==='Scrape streams'){b.disabled=true;b.textContent='Working...';}});
  const urlStatus=document.getElementById('url-status');
  const urlJson=document.getElementById('url-json-container');
  urlStatus.style.display='block';urlStatus.className='status ok';urlStatus.textContent='Scraping...';
  urlJson.innerHTML='';
  try{
    const res=await fetch('/by-url',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({url:url})});
    const data=await res.json();
    if(data.ok){
      urlStatus.className='status ok';
      urlStatus.textContent='Scraped — '+(data.data&&data.data.sources?data.data.sources.length:0)+' source(s)';
      jsonEl('url-json-container','Stream Sources',data);
    }else{
      urlStatus.className='status err';
      urlStatus.textContent='Error: '+(data.error||'Unknown')+(data.error_type?' ('+data.error_type+')':'');
      jsonEl('url-json-container','Error Response',data);
      if(data.debug){
        const dbg=document.createElement('div');
        dbg.className='debug-box';
        const t=document.createElement('div');
        t.className='debug-title'; t.textContent='Debug info';
        const pre=document.createElement('pre');
        pre.textContent=JSON.stringify(data.debug,null,2);
        dbg.appendChild(t); dbg.appendChild(pre);
        urlJson.parentNode.insertBefore(dbg,urlJson.nextSibling);
      }
    }
  }catch(e){
    urlStatus.className='status err';
    urlStatus.textContent='Request failed: '+e.message;
    jsonEl('url-json-container','Error Response',{error:e.message});
  }finally{
    btns.forEach(function(b){b.disabled=false;b.textContent='Scrape streams';});
  }
}

document.getElementById('btn-name').addEventListener('click',function(){run('by-name');});
document.getElementById('btn-url').addEventListener('click',function(){run('by-url');});
document.getElementById('q').addEventListener('keypress',function(e){if(e.key==='Enter')run('by-name');});
document.getElementById('url').addEventListener('keypress',function(e){if(e.key==='Enter')run('by-url');});
</script>
</body>
</html>"""

app = FastAPI(title="MLBD Stream Scraper")


@app.get("/", response_class=HTMLResponse)
async def home():
    return HTMLResponse(HTML_PAGE)


@app.post("/by-name")
async def by_name(req: SearchRequest):
    """Search movielinkbd.tv by content name and return ALL results as JSON."""
    q = req.q
    try:
        search_data = await _search_all(q)
    except CloudflareBlocked as exc:
        return JSONResponse({"ok": False, "error": str(exc), "error_type": "CloudflareBlocked"}, status_code=200)
    except ScraperError as exc:
        return JSONResponse({"ok": False, "error": str(exc), "error_type": "ScraperError"}, status_code=200)

    results = search_data["results"]
    debug_links = search_data.get("debug_links", [])

    if not results:
        return JSONResponse({
            "ok": True,
            "data": {
                "query": q,
                "results": [],
                "message": f'No detail-page links matched selectors for "{q}"',
                "debug_links": debug_links,
                "hint": "Check the browser DevTools on movielinkbd.tv/search?q=" + q +
                        " to see what the result <a> hrefs look like, then update SEARCH_RESULT_LINK_SELECTORS in app.py",
            },
        }, status_code=200)

    return JSONResponse({
        "ok": True,
        "data": {"query": q, "results": results, "count": len(results), "debug_links": debug_links},
    }, status_code=200)


@app.post("/by-url")
async def by_url(req: ScrapeRequest):
    """Scrape a direct detail page URL for stream sources."""
    url = req.url
    try:
        result = await asyncio.wait_for(scrape_streams(url, timeout=30), timeout=60)
        return JSONResponse({"ok": True, "data": result.model_dump()}, status_code=200)
    except CloudflareBlocked as exc:
        return JSONResponse({"ok": False, "error": str(exc), "error_type": "CloudflareBlocked"}, status_code=200)
    except NoStreamFound as exc:
        return JSONResponse(
            {
                "ok": False,
                "error": str(exc),
                "error_type": "NoStreamFound",
                "debug": exc.debug,
            },
            status_code=200,
        )
    except BrowserError as exc:
        return JSONResponse({"ok": False, "error": str(exc), "error_type": "BrowserError"}, status_code=200)
    except asyncio.TimeoutError:
        return JSONResponse({"ok": False, "error": "Scrape timed out", "error_type": "Timeout"}, status_code=200)
    except ScraperError as exc:
        return JSONResponse({"ok": False, "error": str(exc), "error_type": "ScraperError"}, status_code=200)


async def _search_all(query: str) -> dict:
    """Search movielinkbd.tv by name and return ALL detail-page results + debug info."""
    search_url = SEARCH_URL_TEMPLATE.format(query=query.replace(" ", "+"))

    async with browser_context(headless=True) as context:
        page = await context.new_page()
        try:
            await page.goto(search_url, wait_until="networkidle", timeout=30000)
        except Exception as exc:  # noqa: BLE001
            raise ScraperError(f"Failed to load search page: {exc}") from exc

        if await _detect_cloudflare(page):
            raise CloudflareBlocked(search_url)

        await asyncio.sleep(2)

        results: list[dict] = []
        seen: set[str] = set()
        all_links: list[dict] = []

        # First, collect ALL links on the page for debugging.
        try:
            link_els = await page.query_selector_all("a[href]")
            for le in link_els:
                try:
                    href = await le.get_attribute("href")
                    txt = (await le.inner_text()).strip()[:60]
                    if href:
                        full = href if href.startswith("http") else "https://movielinkbd.tv" + href
                        all_links.append({"text": txt, "url": full})
                except Exception:  # noqa: BLE001
                    continue
        except Exception:  # noqa: BLE001
            pass

        # Then try specific selectors for detail pages.
        for selector in SEARCH_RESULT_LINK_SELECTORS:
            try:
                links = await page.query_selector_all(selector)
                for link in links:
                    try:
                        href = await link.get_attribute("href")
                        # Title may be in the link itself, a child element, or an img alt.
                        title = ""
                        try:
                            title = (await link.inner_text()).strip()
                        except Exception:
                            title = ""
                        if not title:
                            try:
                                child = await link.query_selector(":scope > *:first-child")
                                if child:
                                    title = (await child.inner_text()).strip()
                            except Exception:
                                pass
                        if not title:
                            try:
                                img = await link.query_selector("img")
                                if img:
                                    title = (await img.get_attribute("alt") or "").strip()
                            except Exception:
                                pass
                        if not title:
                            try:
                                title = (await link.get_attribute("title") or "").strip()
                            except Exception:
                                pass
                        title = title or "(no title)"
                        if not href:
                            continue
                        full = href if href.startswith("http") else "https://movielinkbd.tv" + href
                        if full in seen:
                            continue
                        seen.add(full)
                        ctype = "movie" if "/movie/" in full else "anime" if "/anime/" in full else "other"
                        results.append({"title": title, "url": full, "type": ctype})
                    except Exception:  # noqa: BLE001
                        continue
            except Exception:  # noqa: BLE001
                continue

        # Fallback: if specific selectors found nothing, use any link that looks like a detail page.
        if not results:
            for lk in all_links:
                u = lk["url"]
                if any(p in u for p in ("/movie/", "/anime/", "/drama/", "/southIndian/")):
                    if u not in seen:
                        seen.add(u)
                        ctype = "movie" if "/movie/" in u else "anime" if "/anime/" in u else "other"
                        results.append({"title": lk["text"] or "(no title)", "url": u, "type": ctype})

        return {
            "results": results,
            "count": len(results),
            "debug_links": all_links[:30],
        }


async def _detect_cloudflare(page) -> bool:
    try:
        title = await page.title()
    except Exception:  # noqa: BLE001
        return False
    return any(k in title.lower() for k in ("just a moment", "attention required"))


if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8000)
