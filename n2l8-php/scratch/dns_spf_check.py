import urllib.request
import json

def get_txt_records(domain):
    url = f"https://dns.google/resolve?name={domain}&type=TXT"
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req) as response:
            data = json.loads(response.read().decode())
            print(f"=== TXT records for {domain} ===")
            if "Answer" in data:
                for answer in data["Answer"]:
                    print(f"- {answer['data']}")
            else:
                print("No TXT records found.")
    except Exception as e:
        print(f"Error querying {domain}: {e}")

get_txt_records("spf.simply.com")
