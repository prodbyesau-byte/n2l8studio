import urllib.request
import json

def get_dns_records(domain, type):
    url = f"https://dns.google/resolve?name={domain}&type={type}"
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req) as response:
            data = json.loads(response.read().decode())
            print(f"=== {type} records for {domain} ===")
            if "Answer" in data:
                for answer in data["Answer"]:
                    print(f"- Type {answer['type']}: {answer['data']}")
            else:
                print(f"No {type} records found.")
    except Exception as e:
        print(f"Error querying {domain} ({type}): {e}")

print("=== DOMAINS DNS LOOKUP ===")
get_dns_records("n2l8studios.com", "A")
print()
get_dns_records("www.n2l8studios.com", "A")
print()
get_dns_records("n2l8studios.com", "TXT")
print()
get_dns_records("_dmarc.n2l8studios.com", "TXT")
print()
get_dns_records("_dmarc.n2l8studios.com", "CNAME")
print()
get_dns_records("spf.simply.com", "TXT")
print()
get_dns_records("n2l8studios.com", "MX")
print()
