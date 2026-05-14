import os
from flask import Flask, render_template, request, redirect, url_for, flash, session
from flask_sqlalchemy import SQLAlchemy
from werkzeug.security import generate_password_hash, check_password_hash
from werkzeug.utils import secure_filename
from functools import wraps

app = Flask(__name__)

# ─── CONFIGURATION ───────────────────────────────────────────────────────────
# Reads from environment variables. Set these on your host (simply.com).
# Falls back to SQLite for local development when DB_HOST is not set.

app.config['SECRET_KEY'] = os.environ.get('SECRET_KEY', 'n2l8studio_secret_key_1950s')

_db_host = os.environ.get('DB_HOST')
if _db_host:
    # ── MySQL (production) ──
    _db_user = os.environ.get('DB_USER', 'root')
    _db_pass = os.environ.get('DB_PASS', '')
    _db_name = os.environ.get('DB_NAME', 'n2l8studio')
    _db_port = os.environ.get('DB_PORT', '3306')
    app.config['SQLALCHEMY_DATABASE_URI'] = (
        f'mysql+pymysql://{_db_user}:{_db_pass}@{_db_host}:{_db_port}/{_db_name}'
        '?charset=utf8mb4'
    )
    # Keep connection alive on shared hosts that close idle connections
    app.config['SQLALCHEMY_ENGINE_OPTIONS'] = {
        'pool_recycle': 280,
        'pool_pre_ping': True,
    }
else:
    # ── SQLite (local dev) ──
    app.config['SQLALCHEMY_DATABASE_URI'] = 'sqlite:///database.db'

app.config['SQLALCHEMY_TRACK_MODIFICATIONS'] = False
app.config['UPLOAD_FOLDER'] = os.path.join(app.root_path, 'static', 'uploads')
app.config['MAX_CONTENT_LENGTH'] = 500 * 1024 * 1024  # 500 MB

ALLOWED_IMAGES = {'png', 'jpg', 'jpeg', 'webp'}
ALLOWED_FILES  = {'zip', 'rar', '7z', 'wav', 'mp3'}
ALLOWED_AUDIO  = {'mp3', 'wav', 'ogg', 'flac'}

db = SQLAlchemy(app)

# ─── MODELS ──────────────────────────────────────────────────────────────────

class User(db.Model):
    id       = db.Column(db.Integer, primary_key=True)
    username = db.Column(db.String(50), unique=True, nullable=False)
    password = db.Column(db.String(255), nullable=False)
    role     = db.Column(db.String(20), default='admin')

class Product(db.Model):
    id             = db.Column(db.Integer, primary_key=True)
    title          = db.Column(db.String(100), nullable=False)
    type           = db.Column(db.String(50), nullable=False)
    genre          = db.Column(db.String(50), nullable=False)
    price          = db.Column(db.Float, nullable=False)
    original_price = db.Column(db.Float, nullable=True)
    author         = db.Column(db.String(100), nullable=True)
    description    = db.Column(db.Text, nullable=True)
    bpm            = db.Column(db.String(20), nullable=True)
    key            = db.Column(db.String(20), nullable=True)
    cover_image    = db.Column(db.String(255), nullable=True)
    zip_file       = db.Column(db.String(255), nullable=True)
    is_active      = db.Column(db.Boolean, default=True)
    tracks         = db.relationship('ProductTrack', backref='product',
                                     cascade='all, delete-orphan',
                                     order_by='ProductTrack.position')

class ProductTrack(db.Model):
    id         = db.Column(db.Integer, primary_key=True)
    product_id = db.Column(db.Integer, db.ForeignKey('product.id'), nullable=False)
    title      = db.Column(db.String(150), nullable=False)
    filename   = db.Column(db.String(255), nullable=False)
    position   = db.Column(db.Integer, default=0)

class Order(db.Model):
    id             = db.Column(db.Integer, primary_key=True)
    customer_email = db.Column(db.String(100), nullable=False)
    product_id     = db.Column(db.Integer, db.ForeignKey('product.id'))
    status         = db.Column(db.String(50), default='completed')
    product        = db.relationship('Product', backref='orders')

class Content(db.Model):
    id          = db.Column(db.Integer, primary_key=True)
    section_key = db.Column(db.String(100), unique=True, nullable=False)
    label       = db.Column(db.String(150), nullable=False)
    text        = db.Column(db.Text, nullable=False)
    page        = db.Column(db.String(50), nullable=False, default='global')

class AuditLog(db.Model):
    id        = db.Column(db.Integer, primary_key=True)
    action    = db.Column(db.String(255), nullable=False)
    timestamp = db.Column(db.DateTime, default=db.func.current_timestamp())

# ─── HELPERS ─────────────────────────────────────────────────────────────────

def log_action(action):
    db.session.add(AuditLog(action=action))
    db.session.commit()

def allowed_image(filename):
    return '.' in filename and filename.rsplit('.', 1)[1].lower() in ALLOWED_IMAGES

def allowed_zip(filename):
    return '.' in filename and filename.rsplit('.', 1)[1].lower() in ALLOWED_FILES

def allowed_audio(filename):
    return '.' in filename and filename.rsplit('.', 1)[1].lower() in ALLOWED_AUDIO

def login_required(f):
    @wraps(f)
    def decorated(*args, **kwargs):
        if 'user_id' not in session:
            return redirect(url_for('login'))
        return f(*args, **kwargs)
    return decorated

def save_upload(file, allowed_fn):
    if file and file.filename and allowed_fn(file.filename):
        filename = secure_filename(file.filename)
        os.makedirs(app.config['UPLOAD_FOLDER'], exist_ok=True)
        file.save(os.path.join(app.config['UPLOAD_FOLDER'], filename))
        return filename
    return None

# ─── CONTEXT PROCESSOR: inject all Content rows into every template ───────────

@app.context_processor
def inject_content():
    rows = Content.query.all()
    content = {row.section_key: row.text for row in rows}
    return dict(site=content)

# ─── PUBLIC ROUTES ────────────────────────────────────────────────────────────

@app.route('/')
@app.route('/index.html')
def index():
    return render_template('index.html')

@app.route('/shop')
@app.route('/shop.html')
def shop():
    products = Product.query.filter_by(is_active=True).all()
    return render_template('shop.html', products=products)

@app.route('/pricing')
@app.route('/pricing.html')
def pricing():
    return render_template('pricing.html')

@app.route('/subscription')
@app.route('/subscription.html')
def subscription():
    return render_template('subscription.html')

# ─── ADMIN AUTH ───────────────────────────────────────────────────────────────

@app.route('/admin/login', methods=['GET', 'POST'])
def login():
    if request.method == 'POST':
        user = User.query.filter_by(username=request.form['username']).first()
        if user and check_password_hash(user.password, request.form['password']):
            session['user_id'] = user.id
            log_action(f"Admin logged in: {user.username}")
            return redirect(url_for('admin_dashboard'))
        flash('Invalid credentials — access denied.')
    return render_template('login.html')

@app.route('/admin/logout')
def logout():
    session.pop('user_id', None)
    return redirect(url_for('login'))

# ─── ADMIN DASHBOARD ─────────────────────────────────────────────────────────

@app.route('/admin')
@login_required
def admin_dashboard():
    products  = Product.query.order_by(Product.id.desc()).all()
    orders    = Order.query.order_by(Order.id.desc()).all()
    logs      = AuditLog.query.order_by(AuditLog.timestamp.desc()).limit(15).all()
    contents  = Content.query.order_by(Content.page, Content.section_key).all()
    pages     = sorted(set(c.page for c in contents))
    return render_template('admin.html',
                           products=products, orders=orders,
                           logs=logs, contents=contents, pages=pages)

# ─── PRODUCT MANAGEMENT ──────────────────────────────────────────────────────

@app.route('/admin/product/new', methods=['POST'])
@login_required
def new_product():
    title          = request.form.get('title', '').strip()
    p_type         = request.form.get('type', 'loopkit')
    genre          = request.form.get('genre', 'all')
    price          = float(request.form.get('price', 0) or 0)
    original_price = request.form.get('original_price', '').strip()
    author         = request.form.get('author', '').strip()
    description    = request.form.get('description', '').strip()
    bpm            = request.form.get('bpm', '').strip()
    key            = request.form.get('key', '').strip()
    is_active      = 'is_active' in request.form

    cover_file = request.files.get('cover_image')
    zip_file   = request.files.get('zip_file')

    cover_filename = save_upload(cover_file, allowed_image)
    zip_filename   = save_upload(zip_file, allowed_zip)

    product = Product(
        title=title, type=p_type, genre=genre,
        price=price,
        original_price=float(original_price) if original_price else None,
        author=author or None,
        description=description or None,
        bpm=bpm or None,
        key=key or None,
        cover_image=cover_filename,
        zip_file=zip_filename,
        is_active=is_active
    )
    db.session.add(product)
    db.session.flush() # Get ID for tracks

    # Handle multiple preview tracks during initial creation
    audio_files = request.files.getlist('audio_files')
    track_count = 0
    for audio_file in audio_files:
        filename = save_upload(audio_file, allowed_audio)
        if filename:
            track_title = audio_file.filename.rsplit('.', 1)[0].replace('_', ' ').replace('-', ' ').title()
            track = ProductTrack(product_id=product.id, title=track_title,
                                 filename=filename, position=track_count)
            db.session.add(track)
            track_count += 1

    db.session.commit()
    log_action(f"Created product: '{title}' with {track_count} tracks")
    flash(f"Product '{title}' created with {track_count} preview tracks.")
    return redirect(url_for('admin_dashboard'))

@app.route('/admin/product/edit/<int:id>', methods=['GET', 'POST'])
@login_required
def edit_product(id):
    p = Product.query.get_or_404(id)
    if request.method == 'POST':
        p.title          = request.form.get('title', p.title).strip()
        p.type           = request.form.get('type', p.type)
        p.genre          = request.form.get('genre', p.genre)
        p.price          = float(request.form.get('price', p.price) or 0)
        op               = request.form.get('original_price', '').strip()
        p.original_price = float(op) if op else None
        p.author         = request.form.get('author', '').strip() or None
        p.description    = request.form.get('description', '').strip() or None
        p.bpm            = request.form.get('bpm', '').strip() or None
        p.key            = request.form.get('key', '').strip() or None
        p.is_active      = 'is_active' in request.form

        cover_file = request.files.get('cover_image')
        zip_file   = request.files.get('zip_file')
        new_cover  = save_upload(cover_file, allowed_image)
        new_zip    = save_upload(zip_file, allowed_zip)
        if new_cover: p.cover_image = new_cover
        if new_zip:   p.zip_file    = new_zip

        db.session.commit()
        log_action(f"Edited product: '{p.title}'")
        flash(f"Product '{p.title}' updated.")
        return redirect(url_for('admin_dashboard'))
    return render_template('edit_product.html', product=p)

@app.route('/admin/product/toggle/<int:id>', methods=['POST'])
@login_required
def toggle_product(id):
    p = Product.query.get_or_404(id)
    p.is_active = not p.is_active
    db.session.commit()
    state = "enabled" if p.is_active else "disabled"
    log_action(f"Product '{p.title}' {state}")
    return redirect(url_for('admin_dashboard'))

@app.route('/admin/product/delete/<int:id>', methods=['POST'])
@login_required
def delete_product(id):
    p = Product.query.get_or_404(id)
    title = p.title
    db.session.delete(p)
    db.session.commit()
    log_action(f"Deleted product: '{title}'")
    flash(f"Product '{title}' deleted.")
    return redirect(url_for('admin_dashboard'))

# ─── TRACK MANAGEMENT ────────────────────────────────────────────────────────

@app.route('/admin/product/<int:id>/track/add', methods=['POST'])
@login_required
def add_track(id):
    product = Product.query.get_or_404(id)
    audio_files = request.files.getlist('audio_files')
    
    if not audio_files or audio_files[0].filename == '':
        flash('No files selected.')
        return redirect(url_for('edit_product', id=id))

    count = 0
    for audio_file in audio_files:
        filename = save_upload(audio_file, allowed_audio)
        if filename:
            # Use filename as default title if none provided (strip extension)
            track_title = audio_file.filename.rsplit('.', 1)[0].replace('_', ' ').replace('-', ' ').title()
            position = len(product.tracks)
            track = ProductTrack(product_id=id, title=track_title,
                                 filename=filename, position=position)
            db.session.add(track)
            count += 1
    
    if count > 0:
        db.session.commit()
        log_action(f"Added {count} tracks to '{product.title}'")
        flash(f"Successfully uploaded {count} preview tracks.")
    else:
        flash('Upload failed — please ensure files are WAV, MP3, OGG, or FLAC.')
        
    return redirect(url_for('edit_product', id=id))

@app.route('/admin/product/<int:pid>/track/delete/<int:tid>', methods=['POST'])
@login_required
def delete_track(pid, tid):
    track = ProductTrack.query.get_or_404(tid)
    name = track.title
    filename = track.filename
    
    # Also delete the physical file
    try:
        file_path = os.path.join(app.config['UPLOAD_FOLDER'], filename)
        if os.path.exists(file_path):
            os.remove(file_path)
    except Exception as e:
        print(f"Error deleting file {filename}: {e}")
        
    db.session.delete(track)
    db.session.commit()
    log_action(f"Deleted track '{name}' and its file")
    flash(f"Track '{name}' deleted.")
    return redirect(url_for('edit_product', id=pid))

@app.route('/admin/product/<int:pid>/track/edit/<int:tid>', methods=['POST'])
@login_required
def edit_track_title(pid, tid):
    track = ProductTrack.query.get_or_404(tid)
    new_title = request.form.get('title', '').strip()
    if new_title:
        old_title = track.title
        track.title = new_title
        db.session.commit()
        log_action(f"Renamed track '{old_title}' to '{new_title}'")
        flash(f"Track renamed to '{new_title}'.")
    return redirect(url_for('edit_product', id=pid))

@app.route('/admin/product/<int:pid>/track/move/<int:tid>/<direction>', methods=['POST'])
@login_required
def move_track(pid, tid, direction):
    product = Product.query.get_or_404(pid)
    track = ProductTrack.query.get_or_404(tid)
    tracks = sorted(product.tracks, key=lambda x: x.position)
    idx = tracks.index(track)
    
    if direction == 'up' and idx > 0:
        other = tracks[idx-1]
        track.position, other.position = other.position, track.position
    elif direction == 'down' and idx < len(tracks) - 1:
        other = tracks[idx+1]
        track.position, other.position = other.position, track.position
        
    db.session.commit()
    return redirect(url_for('edit_product', id=pid))

# ─── PUBLIC JSON API (for modal) ──────────────────────────────────────────────

@app.route('/api/product/<int:id>')
def product_api(id):
    from flask import jsonify
    p = Product.query.get_or_404(id)
    return jsonify({
        'id':             p.id,
        'title':          p.title,
        'type':           p.type,
        'genre':          p.genre,
        'price':          p.price,
        'original_price': p.original_price,
        'author':         p.author,
        'description':    p.description,
        'bpm':            p.bpm,
        'key':            p.key,
        'cover_image':    url_for('static', filename='uploads/' + p.cover_image, _external=False) if p.cover_image else None,
        'zip_file':       url_for('static', filename='uploads/' + p.zip_file, _external=False) if p.zip_file else None,
        'tracks': [
            {'id': t.id, 'title': t.title,
             'url': url_for('static', filename='uploads/' + t.filename, _external=False)}
            for t in p.tracks
        ]
    })

# ─── CONTENT MANAGEMENT ──────────────────────────────────────────────────────

@app.route('/admin/content/update', methods=['POST'])
@login_required
def update_content():
    section_key = request.form.get('section_key')
    text        = request.form.get('text', '').strip()
    row = Content.query.filter_by(section_key=section_key).first()
    if row:
        row.text = text
        db.session.commit()
        log_action(f"Updated content: '{section_key}'")
        flash(f"Content block '{section_key}' saved.")
    return redirect(url_for('admin_dashboard') + '#content')

# ─── SEED DEFAULTS ────────────────────────────────────────────────────────────

DEFAULT_CONTENT = [
    # HOME PAGE
    ('home_hero_h1',        'Home', 'Hero Heading',              'n2l8studio'),
    ('home_hero_sub',       'Home', 'Hero Sub-heading',          'A creative music community where passion, talent, and sound unite.'),
    ('home_contact_h2',     'Home', 'Contact Section Heading',   'Join n2l8studio'),
    ('home_contact_p',      'Home', 'Contact Section Paragraph', 'Are you ready to take your music to the next level? Contact us to learn more about how you can become a part of our growing community.'),
    # SHOP PAGE
    ('shop_h2',             'Shop',         'Shop Heading',       'Sample Packs & Drumkits'),
    ('shop_desc',           'Shop',         'Shop Description',   'Industry-quality sounds built for producers who want a harder, cleaner, and more modern sound.'),
    # PRICING PAGE
    ('pricing_h2',          'Pricing',      'Pricing Heading',    'Mixing & Mastering'),
    ('pricing_desc',        'Pricing',      'Pricing Description','Professional audio engineering to bring your tracks to industry standards.'),
    ('pricing_mix_title',   'Pricing',      'Mixing Card Title',  'Mixing'),
    ('pricing_mix_price',   'Pricing',      'Mixing Price',       '$150'),
    ('pricing_mix_unit',    'Pricing',      'Mixing Price Unit',  '/track'),
    ('pricing_mix_f1',      'Pricing',      'Mixing Feature 1',   'Full vocal & instrumental mix'),
    ('pricing_mix_f2',      'Pricing',      'Mixing Feature 2',   'Industry-standard plugins'),
    ('pricing_mix_f3',      'Pricing',      'Mixing Feature 3',   '3 free revisions'),
    ('pricing_master_title','Pricing',      'Mastering Card Title','Mastering'),
    ('pricing_master_price','Pricing',      'Mastering Price',    '$50'),
    ('pricing_master_unit', 'Pricing',      'Mastering Price Unit','/track'),
    ('pricing_master_f1',   'Pricing',      'Mastering Feature 1','Volume optimization'),
    ('pricing_master_f2',   'Pricing',      'Mastering Feature 2','EQ & compression'),
    ('pricing_master_f3',   'Pricing',      'Mastering Feature 3','Streaming platform ready'),
    # SUBSCRIPTION PAGE
    ('sub_h2',              'Subscription', 'Subscription Heading',     'Monthly Rations'),
    ('sub_desc',            'Subscription', 'Subscription Description', 'Sign up for a monthly supply drop to claim free loopkits of your choice from the wasteland shop.'),
    ('sub_pro_price',       'Subscription', 'Pro Plan Price',            '$19'),
    ('sub_pro_unit',        'Subscription', 'Pro Plan Price Unit',       '.99/mo'),
    ('sub_pro_f1',          'Subscription', 'Pro Feature 1',             '3 Free Loopkits per month'),
    ('sub_pro_f2',          'Subscription', 'Pro Feature 2',             'Access to hidden exclusive loopkits'),
    ('sub_pro_f3',          'Subscription', 'Pro Feature 3',             'High quality WAV files & Stems'),
    ('sub_pro_f4',          'Subscription', 'Pro Feature 4',             'Cancel anytime'),
    # GLOBAL
    ('nav_shop',            'Global',       'Nav: Shop Link',            'Shop'),
    ('nav_pricing',         'Global',       'Nav: Pricing Link',         'Mixing & Mastering'),
    ('nav_sub',             'Global',       'Nav: Subscription Link',    'Subscription Plan'),
    ('nav_contact',         'Global',       'Nav: Contact Link',         'Contact'),
    ('footer_text',         'Global',       'Footer Copyright Text',     '© 2026 n2l8studio. All rights reserved.'),
]

def seed_content():
    for key, page, label, default_text in DEFAULT_CONTENT:
        if not Content.query.filter_by(section_key=key).first():
            db.session.add(Content(section_key=key, label=label, text=default_text, page=page))
    db.session.commit()

if __name__ == '__main__':
    with app.app_context():
        db.create_all()
        if not User.query.filter_by(username='admin').first():
            db.session.add(User(username='admin', password=generate_password_hash('password')))
            db.session.commit()
        seed_content()
        # Seed OBSIDIAN if no products exist
        if not Product.query.first():
            db.session.add(Product(
                title='OBSIDIAN', type='loopkit', genre='trap',
                price=0.0, zip_file='OBSIDIAN.zip',
                cover_image='OBSIDIAN_COVER.jpg', is_active=True
            ))
            db.session.commit()
    app.run(debug=True, port=5000)
