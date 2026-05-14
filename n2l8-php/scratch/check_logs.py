import pymysql
import configparser
from pathlib import Path

cfg = configparser.ConfigParser()
cfg.read('.ftpcredentials')

try:
    db = pymysql.connect(
        host=cfg['mysql']['host'],
        port=int(cfg['mysql'].get('port', 3306)),
        user=cfg['mysql']['user'],
        password=cfg['mysql']['password'],
        database=cfg['mysql']['database']
    )
    cur = db.cursor()
    
    print("--- ALL play_beat ACTIONS ---")
    cur.execute("SELECT action, COUNT(*) FROM visitor_log WHERE action LIKE 'play_beat%' GROUP BY action")
    for r in cur:
        print(f"{r[0]}: {r[1]}")
        
    print("\n--- RECENT ACTIONS ---")
    cur.execute("SELECT action, created_at FROM visitor_log ORDER BY created_at DESC LIMIT 20")
    for r in cur:
        print(f"{r[0]} @ {r[1]}")
        
    db.close()
except Exception as e:
    print(f"Error: {e}")
