import os, time
import jwt
from fastapi import HTTPException, Header
from sqlalchemy import create_engine
from sqlalchemy.orm import DeclarativeBase, sessionmaker

SECRET = os.environ['JWT_SECRET']
engine = create_engine(os.environ['DATABASE_URL'], pool_pre_ping=True)
Session = sessionmaker(bind=engine)
class Base(DeclarativeBase): pass

def initialize():
    for attempt in range(30):
        try:
            Base.metadata.create_all(engine)
            return
        except Exception:
            if attempt == 29: raise
            time.sleep(2)

def identity(authorization: str | None = Header(default=None)):
    if not authorization or not authorization.startswith('Bearer '):
        raise HTTPException(401, 'Bearer token required')
    try:
        claims = jwt.decode(authorization[7:], SECRET, algorithms=['HS256'], options={'require':['sub','role','exp']})
        if claims['role'] not in ('admin','student'): raise ValueError('Invalid role')
        return claims
    except Exception:
        raise HTTPException(401, 'Invalid or expired token')

def admin_only(claims):
    if claims['role'] != 'admin': raise HTTPException(403, 'Admin access only')

def own_or_admin(claims, student_id):
    if claims['role'] != 'admin' and str(claims.get('student_id')) != str(student_id):
        raise HTTPException(403, 'You can access only your own records')
