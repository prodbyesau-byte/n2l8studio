import configparser
import pymysql
from pathlib import Path

CREDS_FILE = Path(__file__).parent.parent / '.ftpcredentials'
cfg = configparser.ConfigParser()
cfg.read(CREDS_FILE)

DB_HOST = cfg['mysql']['host']
DB_PORT = int(cfg['mysql'].get('port', 3306))
DB_USER = cfg['mysql']['user']
DB_PASS = cfg['mysql']['password']
DB_NAME = cfg['mysql']['database']

try:
    conn = pymysql.connect(
        host=DB_HOST, port=DB_PORT,
        user=DB_USER, password=DB_PASS,
        database=DB_NAME, charset='utf8mb4',
    )
    cur = conn.cursor(pymysql.cursors.DictCursor)
    cur.execute("SELECT id, action, created_at FROM audit_log ORDER BY id DESC LIMIT 50")
    logs = cur.fetchall()
    
    print("\n--- RECENT AUDIT LOGS ---")
    for l in logs:
        print(f"[{l['created_at']}] ID: {l['id']} | Action: {l['action']}")
        
    cur.close()
    conn.close()
except Exception as e:
    print(f"Error querying live DB: {e}")
