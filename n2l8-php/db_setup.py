#!/usr/bin/env python3
"""
N2L8Studio — MySQL Database Setup + Sync Script

  1. Creates all tables (idempotent — safe to run multiple times)
  2. Creates admin user
  3. Seeds default content blocks
  4. Syncs products + tracks from local SQLite → MySQL

Usage:  python db_setup.py

Run automatically by ftp_sync.py --upload after each deployment.
"""

import sys
import io

if sys.stdout.encoding and sys.stdout.encoding.lower() != 'utf-8':
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

# ── Install pymysql if needed ────────────────────────────────────────────────
try:
    import pymysql
except ImportError:
    import subprocess
    print("Installing pymysql...")
    subprocess.check_call([sys.executable, "-m", "pip", "install", "pymysql"])
    import pymysql

import configparser
from pathlib import Path

# ── Load credentials ─────────────────────────────────────────────────────────
CREDS_FILE = Path(__file__).parent / '.ftpcredentials'
cfg = configparser.ConfigParser()
cfg.read(CREDS_FILE)

DB_HOST = cfg['mysql']['host']
DB_PORT = int(cfg['mysql'].get('port', 3306))
DB_USER = cfg['mysql']['user']
DB_PASS = cfg['mysql']['password']
DB_NAME = cfg['mysql']['database']

print(f"\n{'='*60}")
print(f"  N2L8Studio — MySQL Setup")
print(f"  Host: {DB_HOST}:{DB_PORT}")
print(f"  DB:   {DB_NAME}")
print(f"{'='*60}\n")

# ── Connect ──────────────────────────────────────────────────────────────────
try:
    conn = pymysql.connect(
        host=DB_HOST, port=DB_PORT,
        user=DB_USER, password=DB_PASS,
        database=DB_NAME, charset='utf8mb4',
        connect_timeout=15,
    )
    print("✅ Connected to MySQL\n")
except Exception as e:
    print(f"❌ Connection failed: {e}")
    sys.exit(1)

cur = conn.cursor()

# ── SQL statements ───────────────────────────────────────────────────────────
SCHEMA = [
    ("users", """
    CREATE TABLE IF NOT EXISTS `users` (
      `id`       INT AUTO_INCREMENT PRIMARY KEY,
      `username` VARCHAR(50)  UNIQUE NOT NULL,
      `email`    VARCHAR(100) UNIQUE NULL,
      `password` VARCHAR(255) NOT NULL,
      `role`     VARCHAR(20)  NOT NULL DEFAULT 'admin',
      `profile_picture` VARCHAR(255) NULL,
      `is_approved` TINYINT(1) NOT NULL DEFAULT 0,
      `is_private` TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """),
    ("products", """
    CREATE TABLE IF NOT EXISTS `products` (
      `id`             INT AUTO_INCREMENT PRIMARY KEY,
      `title`          VARCHAR(100)  NOT NULL,
      `type`           VARCHAR(50)   NOT NULL,
      `genre`          VARCHAR(50)   NOT NULL,
      `price`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      `original_price` DECIMAL(10,2) NULL,
      `author`         VARCHAR(100)  NULL,
      `description`    TEXT          NULL,
      `bpm`            VARCHAR(20)   NULL,
      `key`            VARCHAR(20)   NULL,
      `cover_image`    VARCHAR(255)  NULL,
      `zip_file`       VARCHAR(255)  NULL,
      `is_active`      TINYINT(1)    NOT NULL DEFAULT 1,
      `allow_download` TINYINT(1)    NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """),
    ("product_tracks", """
    CREATE TABLE IF NOT EXISTS `product_tracks` (
      `id`         INT AUTO_INCREMENT PRIMARY KEY,
      `product_id` INT          NOT NULL,
      `title`      VARCHAR(150) NOT NULL,
      `filename`   VARCHAR(255) NOT NULL,
      `preview_start` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
      `preview_end`   DECIMAL(8,2) NULL,
      `position`   INT          NOT NULL DEFAULT 0,
      FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """),
    ("orders", """
    CREATE TABLE IF NOT EXISTS `orders` (
      `id`             INT AUTO_INCREMENT PRIMARY KEY,
      `customer_email` VARCHAR(100) NOT NULL,
      `product_id`     INT          NULL,
      `status`         VARCHAR(50)  NOT NULL DEFAULT 'completed',
      FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """),
    ("content", """
    CREATE TABLE IF NOT EXISTS `content` (
      `id`          INT AUTO_INCREMENT PRIMARY KEY,
      `section_key` VARCHAR(100) UNIQUE NOT NULL,
      `label`       VARCHAR(150) NOT NULL,
      `page`        VARCHAR(50)  NOT NULL DEFAULT 'global',
      `text`        TEXT         NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """),
    ("audit_log", """
    CREATE TABLE IF NOT EXISTS `audit_log` (
      `id`         INT AUTO_INCREMENT PRIMARY KEY,
      `action`     VARCHAR(255) NOT NULL,
      `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """),
    ("visitor_log", """
    CREATE TABLE IF NOT EXISTS `visitor_log` (
      `id`           INT AUTO_INCREMENT PRIMARY KEY,
      `ip`           VARCHAR(45)  NOT NULL,
      `country`      VARCHAR(100) NOT NULL DEFAULT '',
      `country_code` VARCHAR(5)   NOT NULL DEFAULT '',
      `city`         VARCHAR(100) NOT NULL DEFAULT '',
      `page`         VARCHAR(255) NOT NULL DEFAULT '',
      `action`       VARCHAR(255) NOT NULL DEFAULT '',
      `user_agent`   VARCHAR(500) NOT NULL DEFAULT '',
      `referrer`     VARCHAR(500) NOT NULL DEFAULT '',
      `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_ip` (`ip`),
      INDEX `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """),
    ("user_saved_products", """
    CREATE TABLE IF NOT EXISTS `user_saved_products` (
      `id`         INT AUTO_INCREMENT PRIMARY KEY,
      `user_id`    INT NOT NULL,
      `product_id` INT NOT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY `uniq_user_product` (`user_id`, `product_id`),
      INDEX `idx_user_saved` (`user_id`),
      FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """),
    ("user_activity", """
    CREATE TABLE IF NOT EXISTS `user_activity` (
      `id`         INT AUTO_INCREMENT PRIMARY KEY,
      `user_id`    INT NOT NULL,
      `product_id` INT NULL,
      `action`     VARCHAR(80) NOT NULL,
      `metadata`   VARCHAR(255) NOT NULL DEFAULT '',
      `page`       VARCHAR(255) NOT NULL DEFAULT '',
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_user_activity` (`user_id`, `created_at`),
      FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """),
    ("messages", """
    CREATE TABLE IF NOT EXISTS `messages` (
      `id`           INT AUTO_INCREMENT PRIMARY KEY,
      `sender_id`    INT NULL,
      `recipient_id` INT NOT NULL,
      `subject`      VARCHAR(255) NOT NULL,
      `message`      TEXT NOT NULL,
      `is_read`      TINYINT(1) NOT NULL DEFAULT 0,
      `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`recipient_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """),
    ("friendships", """
    CREATE TABLE IF NOT EXISTS `friendships` (
      `id`             INT AUTO_INCREMENT PRIMARY KEY,
      `user_id1`       INT NOT NULL,
      `user_id2`       INT NOT NULL,
      `status`         VARCHAR(20) NOT NULL DEFAULT 'pending',
      `action_user_id` INT NOT NULL,
      `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY `uniq_users` (`user_id1`, `user_id2`),
      FOREIGN KEY (`user_id1`) REFERENCES `users`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`user_id2`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """),
    ("forum_categories", """
    CREATE TABLE IF NOT EXISTS `forum_categories` (
      `id`          INT AUTO_INCREMENT PRIMARY KEY,
      `name`        VARCHAR(100) NOT NULL,
      `description` VARCHAR(255) NOT NULL,
      `position`    INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """),
    ("forum_threads", """
    CREATE TABLE IF NOT EXISTS `forum_threads` (
      `id`          INT AUTO_INCREMENT PRIMARY KEY,
      `category_id` INT NOT NULL,
      `user_id`     INT NOT NULL,
      `title`       VARCHAR(255) NOT NULL,
      `content`     TEXT NOT NULL,
      `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      FOREIGN KEY (`category_id`) REFERENCES `forum_categories`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """),
    ("forum_replies", """
    CREATE TABLE IF NOT EXISTS `forum_replies` (
      `id`          INT AUTO_INCREMENT PRIMARY KEY,
      `thread_id`   INT NOT NULL,
      `user_id`     INT NOT NULL,
      `content`     TEXT NOT NULL,
      `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`thread_id`) REFERENCES `forum_threads`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """),
]

# ── Create tables ─────────────────────────────────────────────────────────────
print("Creating tables...")
for name, sql in SCHEMA:
    try:
        cur.execute(sql)
        print(f"  ✅ {name}")
    except Exception as e:
        print(f"  ❌ {name}: {e}")

conn.commit()

print("\nApplying schema upgrades...")
UPGRADES = [
    ("product_tracks.preview_start", "ALTER TABLE product_tracks ADD COLUMN preview_start DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER filename"),
    ("product_tracks.preview_end", "ALTER TABLE product_tracks ADD COLUMN preview_end DECIMAL(8,2) NULL AFTER preview_start"),
    ("users.profile_picture", "ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL AFTER email"),
    ("users.is_approved", "ALTER TABLE users ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 1"),
    ("messages.deleted_by_sender", "ALTER TABLE messages ADD COLUMN deleted_by_sender TINYINT(1) NOT NULL DEFAULT 0"),
    ("messages.deleted_by_recipient", "ALTER TABLE messages ADD COLUMN deleted_by_recipient TINYINT(1) NOT NULL DEFAULT 0"),
    ("messages.is_flagged_by_sender", "ALTER TABLE messages ADD COLUMN is_flagged_by_sender TINYINT(1) NOT NULL DEFAULT 0"),
    ("messages.is_flagged_by_recipient", "ALTER TABLE messages ADD COLUMN is_flagged_by_recipient TINYINT(1) NOT NULL DEFAULT 0"),
    ("users.is_private", "ALTER TABLE users ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0"),
]
for label, sql in UPGRADES:
    try:
        cur.execute(sql)
        print(f"  ✅ {label}")
    except Exception as e:
        if "Duplicate column" in str(e) or "1060" in str(e):
            print(f"  ⚠️  {label} already exists — skipped.")
        else:
            print(f"  ❌ {label}: {e}")

conn.commit()

# ── Create admin user ─────────────────────────────────────────────────────────
print("\nCreating admin user...")
try:
    import subprocess, json
    # Use PHP to generate bcrypt hash (PHP is on server, not local)
    # Instead, use passlib if available, else use a precomputed hash
    try:
        from passlib.hash import bcrypt as passlib_bcrypt
        pw_hash = passlib_bcrypt.hash('Rbnyccxp7')
    except ImportError:
        # Fallback: use bcrypt package
        try:
            import bcrypt as bcrypt_lib
            pw_hash = bcrypt_lib.hashpw(b'Rbnyccxp7', bcrypt_lib.gensalt()).decode()
        except ImportError:
            # Last resort: install bcrypt
            subprocess.check_call([sys.executable, "-m", "pip", "install", "bcrypt"], 
                                  stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            import bcrypt as bcrypt_lib
            pw_hash = bcrypt_lib.hashpw(b'Rbnyccxp7', bcrypt_lib.gensalt()).decode()

    cur.execute("SELECT COUNT(*) FROM users WHERE username='N2L8studio'")
    if cur.fetchone()[0] == 0:
        cur.execute(
            "INSERT INTO users (username, password, role) VALUES (%s, %s, 'admin')",
            ('N2L8studio', pw_hash)
        )
        conn.commit()
        print("  ✅ Admin user created: N2L8studio / Rbnyccxp7")
    else:
        print("  ⚠️  Admin user already exists — skipped.")
except Exception as e:
    print(f"  ❌ Admin user error: {e}")

# ── Seed content ──────────────────────────────────────────────────────────────
print("\nSeeding content blocks...")
CONTENT = [
    ('home_hero_h1',        'Home',         'Hero Heading',              'n2l8studio'),
    ('home_hero_sub',       'Home',         'Hero Sub-heading',          'A creative music community where passion, talent, and sound unite.'),
    ('home_contact_h2',     'Home',         'Contact Section Heading',   'Join n2l8studio'),
    ('home_contact_p',      'Home',         'Contact Section Paragraph', 'Are you ready to take your music to the next level? Contact us to learn more about how you can become a part of our growing community.'),
    ('shop_h2',             'Shop',         'Shop Heading',              'Sample Packs & Drumkits'),
    ('shop_desc',           'Shop',         'Shop Description',          'Industry-quality sounds built for producers who want a harder, cleaner, and more modern sound.'),
    ('pricing_h2',          'Pricing',      'Pricing Heading',           'Mixing & Mastering'),
    ('pricing_desc',        'Pricing',      'Pricing Description',       'Professional audio engineering to bring your tracks to industry standards.'),
    ('pricing_mix_title',   'Pricing',      'Mixing Card Title',         'Mixing'),
    ('pricing_mix_price',   'Pricing',      'Mixing Price',              '$150'),
    ('pricing_mix_unit',    'Pricing',      'Mixing Price Unit',         '/track'),
    ('pricing_mix_f1',      'Pricing',      'Mixing Feature 1',          'Full vocal & instrumental mix'),
    ('pricing_mix_f2',      'Pricing',      'Mixing Feature 2',          'Industry-standard plugins'),
    ('pricing_mix_f3',      'Pricing',      'Mixing Feature 3',          '3 free revisions'),
    ('pricing_master_title','Pricing',      'Mastering Card Title',      'Mastering'),
    ('pricing_master_price','Pricing',      'Mastering Price',           '$50'),
    ('pricing_master_unit', 'Pricing',      'Mastering Price Unit',      '/track'),
    ('pricing_master_f1',   'Pricing',      'Mastering Feature 1',       'Volume optimization'),
    ('pricing_master_f2',   'Pricing',      'Mastering Feature 2',       'EQ & compression'),
    ('pricing_master_f3',   'Pricing',      'Mastering Feature 3',       'Streaming platform ready'),
    ('sub_h2',              'Subscription', 'Subscription Heading',      'Monthly Subscription'),
    ('sub_desc',            'Subscription', 'Subscription Description',  'Sign up for a monthly plan to claim free loopkits of your choice from our shop.'),
    ('sub_pro_price',       'Subscription', 'Pro Plan Price',            '$19'),
    ('sub_pro_unit',        'Subscription', 'Pro Plan Price Unit',       '.99/mo'),
    ('sub_pro_f1',          'Subscription', 'Pro Feature 1',             '3 Free Loopkits per month'),
    ('sub_pro_f2',          'Subscription', 'Pro Feature 2',             'Access to hidden exclusive loopkits'),
    ('sub_pro_f3',          'Subscription', 'Pro Feature 3',             'High quality WAV files & Stems'),
    ('sub_pro_f4',          'Subscription', 'Pro Feature 4',             'Cancel anytime'),
    ('nav_shop',            'Global',       'Nav: Shop Link',            'Shop'),
    ('nav_pricing',         'Global',       'Nav: Pricing Link',         'Mixing & Mastering'),
    ('nav_sub',             'Global',       'Nav: Subscription Link',    'Subscription Plan'),
    ('footer_text',         'Global',       'Footer Copyright Text',     '© 2026 n2l8studio. All rights reserved.'),
]

seeded = 0
for key, page, label, text in CONTENT:
    try:
        cur.execute(
            "INSERT IGNORE INTO content (section_key, label, page, text) VALUES (%s, %s, %s, %s)",
            (key, label, page, text)
        )
        if cur.rowcount:
            seeded += 1
    except Exception as e:
        print(f"  ❌ Content '{key}': {e}")

conn.commit()
print(f"  ✅ {seeded} content blocks seeded.")

# ── Seed Forum Categories ────────────────────────────────────────────────────
print("\nSeeding forum categories...")
FORUM_CATEGORIES = [
    ('General Discussion', 'Talk about anything related to music, community or life.', 1),
    ('Music Production', 'Share tips, tricks, plugins, tutorials, and discuss DAWs.', 2),
    ('Collabs & Feedback', 'Find other producers/artists to collaborate with or get feedback on your tracks.', 3),
    ('Showcase', 'Show off your finished beats, songs, artwork, or graphics.', 4)
]
seeded_cats = 0
for name, desc, pos in FORUM_CATEGORIES:
    try:
        cur.execute("SELECT COUNT(*) FROM forum_categories WHERE name = %s", (name,))
        if cur.fetchone()[0] == 0:
            cur.execute(
                "INSERT INTO forum_categories (name, description, position) VALUES (%s, %s, %s)",
                (name, desc, pos)
            )
            seeded_cats += 1
    except Exception as e:
        print(f"  ❌ Forum Category '{name}': {e}")
conn.commit()
print(f"  ✅ {seeded_cats} forum categories seeded.")


# ── Sync products from local SQLite ──────────────────────────────────────────
# Product sync from local SQLite is disabled so that products deleted via the
# Admin panel do not get automatically re-seeded/re-created.
print("\nProduct sync from local SQLite DB is disabled (Products are now dynamically managed via the Admin panel).")

cur.close()
conn.close()

print(f"\n{'='*60}")
print("  ✅ Database setup + sync complete!")
print("  Site: https://www.n2l8studios.com/")
print(f"{'='*60}\n")
