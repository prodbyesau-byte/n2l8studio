import pymysql
import configparser

cfg = configparser.ConfigParser()
cfg.read('.ftpcredentials')

conn = pymysql.connect(
    host=cfg['mysql']['host'],
    port=int(cfg['mysql'].get('port', 3306)),
    user=cfg['mysql']['user'],
    password=cfg['mysql']['password'],
    database=cfg['mysql']['database']
)
cur = conn.cursor(pymysql.cursors.DictCursor)

print("--- TESTING LIST_FRIENDS FOR USER 6 ---")
cur.execute("""
    SELECT u.id, u.username, u.profile_picture
    FROM users u
    JOIN friendships f ON (
        (f.user_id1 = u.id AND f.user_id2 = 6) OR
        (f.user_id2 = u.id AND f.user_id1 = 6)
    )
    WHERE f.status = 'accepted'
    ORDER BY u.username ASC
""")
print(cur.fetchall())

print("--- TESTING DISCOVER_MEMBERS FOR USER 6 ---")
cur.execute("""
    SELECT 
        u.id, 
        u.username, 
        u.profile_picture, 
        u.role,
        f.status AS friendship_status,
        f.action_user_id
    FROM users u
    LEFT JOIN friendships f ON (
        (f.user_id1 = u.id AND f.user_id2 = 6) OR
        (f.user_id1 = 6 AND f.user_id2 = u.id)
    )
    WHERE u.is_approved = 1 
      AND u.role != 'admin' 
      AND u.id != 6 
      AND u.is_private = 0
    ORDER BY u.username ASC
""")
print(cur.fetchall())
