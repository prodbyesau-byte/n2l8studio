import urllib.request
import json

domain = "spf.simply.com"
url = f"https://dns.google/resolve?name={domain}&type=TXT"

try:
    req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
    with urllib.request.urlopen(req) as response:
        data = json.loads(response.read().decode())
        print(f"DNS query results for {domain} TXT records:")
        if "Answer" in data:
            for answer in data["Answer"]:
                print(f"- {answer['data']}")
        else:
            print("No TXT records found or DNS query failed.")
except Exception as e:
    print("Error querying DNS:", e)
