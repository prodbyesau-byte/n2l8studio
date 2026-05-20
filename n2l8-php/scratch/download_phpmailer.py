import urllib.request
import os

def download_file(url, dest_path):
    print(f"Downloading {url}...")
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req) as response:
            with open(dest_path, 'wb') as f:
                f.write(response.read())
        print(f"Saved to {dest_path}")
    except Exception as e:
        print(f"Error downloading {url}: {e}")

dest_dir = "includes/PHPMailer"
os.makedirs(dest_dir, exist_ok=True)

base_url = "https://raw.githubusercontent.com/PHPMailer/PHPMailer/master/src/"
files = ["PHPMailer.php", "SMTP.php", "Exception.php"]

for filename in files:
    download_file(base_url + filename, os.path.join(dest_dir, filename))

print("PHPMailer download complete!")
