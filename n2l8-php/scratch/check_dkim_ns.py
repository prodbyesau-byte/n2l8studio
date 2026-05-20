import urllib.request
import json

def get_dns(domain, rtype):
    url = f"https://dns.google/resolve?name={domain}&type={rtype}"
    req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
    with urllib.request.urlopen(req) as response:
        data = json.loads(response.read().decode())
        return data.get("Answer", [])

print("=== Nameserver Check ===")
ns = get_dns("n2l8studios.com", "NS")
for r in ns:
    print(f"  NS: {r['data']}")

print("\n=== DKIM Check (Simply.com style) ===")

# Check old style
print("\nsimplycom1._domainkey.n2l8studios.com:")
r = get_dns("simplycom1._domainkey.n2l8studios.com", "CNAME")
if r:
    for a in r: print(f"  CNAME: {a['data']}")
else:
    r2 = get_dns("simplycom1._domainkey.n2l8studios.com", "TXT")
    if r2:
        for a in r2: print(f"  TXT: {a['data']}")
    else:
        print("  (none)")

print("\nsimplycom2._domainkey.n2l8studios.com:")
r = get_dns("simplycom2._domainkey.n2l8studios.com", "CNAME")
if r:
    for a in r: print(f"  CNAME: {a['data']}")
else:
    r2 = get_dns("simplycom2._domainkey.n2l8studios.com", "TXT")
    if r2:
        for a in r2: print(f"  TXT: {a['data']}")
    else:
        print("  (none)")

# Check old unoeuro style
print("\nunoeuro._domainkey.n2l8studios.com:")
r = get_dns("unoeuro._domainkey.n2l8studios.com", "CNAME")
if r:
    for a in r: print(f"  CNAME: {a['data']}")
else:
    r2 = get_dns("unoeuro._domainkey.n2l8studios.com", "TXT")
    if r2:
        for a in r2: print(f"  TXT: {a['data']}")
    else:
        print("  (none)")

print("\n=== DMARC Check ===")
r = get_dns("_dmarc.n2l8studios.com", "TXT")
if r:
    for a in r: print(f"  TXT: {a['data']}")
else:
    print("  (none)")

print("\n=== Conclusion ===")
simply_ns = any("simply" in a.get('data','').lower() or "unoeuro" in a.get('data','').lower() for a in ns)
if simply_ns:
    print("Domain uses Simply.com nameservers -> DKIM should be automatic")
else:
    print("Domain does NOT use Simply.com nameservers -> DKIM CNAME records needed manually")
