import urllib.request
import json

def check_domain(domain):
    url = f"https://dns.google/resolve?name={domain}&type=A"
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req) as response:
            data = json.loads(response.read().decode())
            print(f"DNS A-records for {domain}:")
            if "Answer" in data:
                for answer in data["Answer"]:
                    print(f"- {answer['data']}")
            else:
                print("No A records found.")
    except Exception as e:
        print("Error:", e)

check_domain("n2l8studio.dk")
print()
check_domain("n2l8studios.com")
print()
check_domain("www.n2l8studios.com")
