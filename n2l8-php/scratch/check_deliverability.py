import urllib.request
import json

def get_dns_records(domain, rtype):
    url = f"https://dns.google/resolve?name={domain}&type={rtype}"
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req) as response:
            data = json.loads(response.read().decode())
            if "Answer" in data:
                for answer in data["Answer"]:
                    print(f"  {answer['data']}")
            else:
                print(f"  (none)")
    except Exception as e:
        print(f"  Error: {e}")

print("=== Email Deliverability Report for n2l8studios.com ===\n")

print("SPF Record:")
get_dns_records("n2l8studios.com", "TXT")

print("\nDMARC Record (_dmarc.n2l8studios.com):")
get_dns_records("_dmarc.n2l8studios.com", "TXT")

print("\nDKIM (default._domainkey.n2l8studios.com):")
get_dns_records("default._domainkey.n2l8studios.com", "TXT")

print("\nDKIM (simply._domainkey.n2l8studios.com):")
get_dns_records("simply._domainkey.n2l8studios.com", "TXT")

print("\nMX Records:")
get_dns_records("n2l8studios.com", "MX")

print("\n=== Analysis ===")
print("- SPF: Should be present with include:spf.simply.com")
print("- DMARC: CRITICAL - Without a DMARC record, Hotmail/Outlook")
print("  may reject or spam-folder emails from this domain.")
print("- DKIM: Without DKIM signing, combined with no DMARC,")
print("  deliverability to Microsoft (Hotmail/Outlook/Live) is unreliable.")
