import pymysql
import configparser

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
    
    print("--- BEATS IN DB ---")
    cur.execute("SELECT id, title, type FROM products WHERE type = 'beat'")
    beats = cur.fetchall()
    for b in beats:
        print(f"ID: {b[0]}, Title: {b[1]}")
        cur.execute("SELECT id, title FROM product_tracks WHERE product_id = %s", (b[0],))
        tracks = cur.fetchall()
        if not tracks:
            print("  !! NO TRACKS FOUND !!")
        for t in tracks:
            print(f"  Track: {t[1]}")
            
    db.close()
except Exception as e:
    print(f"Error: {e}")
