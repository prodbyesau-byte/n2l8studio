import pymysql
import configparser
import sys
from pathlib import Path

SCRIPT_DIR = Path(__file__).parent.parent.resolve()
CREDS_FILE = SCRIPT_DIR / '.ftpcredentials'

cfg = configparser.ConfigParser()
cfg.read(CREDS_FILE)

try:
    db = pymysql.connect(
        host=cfg['mysql']['host'],
        port=int(cfg['mysql'].get('port', 3306)),
        user=cfg['mysql']['user'],
        password=cfg['mysql']['password'],
        database=cfg['mysql']['database']
    )
    cur = db.cursor()
    
    print("Checking products table columns...")
    cur.execute("SHOW COLUMNS FROM products LIKE 'allow_download'")
    column_exists = cur.fetchone()
    
    if not column_exists:
        print("Adding allow_download column to products table...")
        cur.execute("ALTER TABLE products ADD COLUMN allow_download TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active")
        db.commit()
        print("[SUCCESS] Column 'allow_download' added successfully.")
    else:
        print("[INFO] Column 'allow_download' already exists.")
        
    db.close()
except Exception as e:
    print(f"[ERROR] Error: {e}")
    sys.exit(1)
