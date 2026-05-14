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
    
    # 1. Add pricing columns to products
    print("Updating products table...")
    try:
        cur.execute("ALTER TABLE products ADD COLUMN price_premium DECIMAL(10,2) DEFAULT NULL AFTER price")
        cur.execute("ALTER TABLE products ADD COLUMN price_exclusive DECIMAL(10,2) DEFAULT NULL AFTER price_premium")
        print("  Added price columns.")
    except:
        print("  Price columns already exist.")

    # 2. Add PayPal and Licensing keys to content table
    print("Seeding content table with shop settings...")
    settings = [
        ('paypal_client_id_sandbox', 'PayPal Sandbox Client ID', 'shop', 'test'),
        ('paypal_client_id_live', 'PayPal Live Client ID', 'shop', ''),
        ('paypal_mode', 'PayPal Mode (sandbox/live)', 'shop', 'sandbox'),
        
        ('license_basic_features', 'Basic License Benefits (one per line)', 'shop', '✓ MP3 & WAV Files\n✓ Non-Profit Use\n✓ 50,000 Streams'),
        ('license_premium_features', 'Premium License Benefits (one per line)', 'shop', '✓ Track Stems\n✓ Commercial Use\n✓ 500,000 Streams'),
        ('license_exclusive_features', 'Exclusive License Benefits (one per line)', 'shop', '✓ Full Ownership\n✓ Unlimited Everything\n✓ Contract Included'),
    ]
    
    for key, label, page, text in settings:
        cur.execute("INSERT IGNORE INTO content (section_key, label, page, text) VALUES (%s, %s, %s, %s)", (key, label, page, text))
    
    db.commit()
    db.close()
    print("Database update complete.")
except Exception as e:
    print(f"Error: {e}")
