# N2L8Studio — Developer Guide

## Project Location
`C:\Users\Never\Documents\n2l8studios MAIN\n2l8studio\n2l8-php\`

## Stack
- **Frontend/Backend:** PHP (native, no framework)
- **Database:** MySQL on `mysql63.unoeuro.com` — credentials in `.ftpcredentials`
- **Hosting:** UnoEuro/Simply.com shared hosting (Apache + PHP)
- **Deploy:** FTP via `ftp_sync.py` (Python stdlib only)

## Credentials
All credentials live **only** in `.ftpcredentials` (never uploaded):
```ini
[ftp]
host     = linux60.unoeuro.com
user     = n2l8studio.dk
password = <see file>
remote   = /public_html/

[mysql]
host     = mysql63.unoeuro.com
user     = n2l8studio_dk
database = n2l8studio_dk_db
password = <see file>
```
`includes/config.php` holds the MySQL credentials for PHP and is also **never uploaded**.

## Deploy Workflow
Every change to the site: edit files locally → run one command:
```bash
cd "C:\Users\Never\Documents\n2l8studios MAIN\n2l8studio\n2l8-php"
python ftp_sync.py --upload
```
This automatically:
1. Uploads all files **except** those in `.ftpignore`
2. After FTP completes, runs `db_setup.py` which:
   - Creates any missing MySQL tables (idempotent)
   - Syncs products + tracks from local SQLite (`n2l8-app/instance/database.db`) → MySQL
   - Skips content/admin user if already seeded

For quick single-file pushes (CSS, one PHP file etc.):
```bash
python -c "
import ftplib, configparser
from pathlib import Path
cfg = configparser.ConfigParser(); cfg.read('.ftpcredentials')
ftp = ftplib.FTP(); ftp.connect(cfg['ftp']['host'], 21, timeout=30)
ftp.login(cfg['ftp']['user'], cfg['ftp']['password']); ftp.set_pasv(True)
with open('static/style.css','rb') as f: ftp.storbinary('STOR /public_html/static/style.css', f)
ftp.quit(); print('done')
"
```

## Files Never Uploaded (`.ftpignore`)
| File | Reason |
|---|---|
| `.ftpcredentials` | FTP + MySQL passwords |
| `includes/config.php` | PHP MySQL passwords |
| `ftp_sync.py` | Deploy tool, local only |
| `db_setup.py` | DB setup tool, local only |
| `db_setup.sql` | Schema reference, run manually |
| `setup.php` | One-time seeder, delete after use |

## Security Notes
- `includes/.htaccess` blocks direct HTTP access to `includes/` (Apache `Deny from all`)
- `includes/config.php` outputs nothing if visited — credentials never in HTML
- Admin auth: PHP `password_hash()` / `password_verify()` (bcrypt)
- `setup.php` should be **deleted from server** after first-time DB seed

## Project Structure
```
n2l8-php/
├── index.php           Homepage
├── shop.php            Shop + modal player
├── pricing.php         Pricing page
├── subscription.php    Subscription page
├── setup.php           ONE-TIME DB seeder (delete from server after use)
│
├── admin/
│   ├── index.php       Tabbed admin dashboard
│   ├── login.php / logout.php
│   ├── product_new/edit/delete/toggle.php
│   ├── track_add/delete/move.php
│   └── content_update.php
│
├── api/
│   └── product.php     JSON endpoint for shop modal (?id=X)
│
├── includes/
│   ├── config.php      DB credentials (LOCAL ONLY)
│   ├── config.example.php  Template (safe to upload)
│   ├── db.php          PDO singleton
│   ├── auth.php        Session helpers
│   ├── helpers.php     h(), flash(), save_upload() etc.
│   └── .htaccess       Deny all HTTP access
│
├── static/
│   ├── style.css       All CSS (retro-futuristic terminal theme)
│   ├── uploads/        Product files (WAV, ZIP, covers) — synced
│   └── *.png           Background images
│
├── .ftpcredentials     Credentials (LOCAL ONLY)
├── .ftpignore          FTP exclusion list
├── ftp_sync.py         Deploy script
├── db_setup.py         MySQL table creator + SQLite→MySQL product sync
└── db_setup.sql        Schema reference
```

## Admin Login
- URL: `https://n2l8studio.dk/admin/login.php`
- User: `N2L8studio` / `Rbnyccxp7`

## Site
- Live: `https://n2l8studio.dk/`
- Shop modal API: `https://n2l8studio.dk/api/product.php?id=1`

## Design System
Retro-futuristic Fallout terminal aesthetic:
- Fonts: `Righteous` (headings) + `VT323` (monospace/body) — Google Fonts
- Colors: `--text-main: #33ff99` (green phosphor), `--accent: #ffc25c` (amber), `--bg-dark: #070a07`
- Background images in `static/` referenced by CSS as relative paths from `static/style.css`

## Flask Backup
The original Flask app is preserved at:
`C:\Users\Never\Documents\n2l8studios MAIN\n2l8studio\n2l8-app\`
Do NOT modify it. It is cold-storage backup only.
