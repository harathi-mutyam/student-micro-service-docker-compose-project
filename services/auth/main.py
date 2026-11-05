import os
import re
import secrets
from datetime import datetime, timedelta, timezone

import jwt
from pwdlib import PasswordHash
from fastapi import FastAPI, HTTPException, Depends
from pydantic import BaseModel
from sqlalchemy import Integer, String, select
from sqlalchemy.orm import Mapped, mapped_column

from common.core import Base, Session, initialize, SECRET, identity, admin_only
from cryptography.fernet import Fernet
from sqlalchemy import ForeignKey
app = FastAPI(title='Authentication Service')
password_hash = PasswordHash.recommended()
class User(Base):
    __tablename__ = 'users'
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    username: Mapped[str] = mapped_column(String(80), unique=True)
    hashed_password: Mapped[str] = mapped_column(String(255))
    role: Mapped[str] = mapped_column(String(20))
    student_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
# Encryption key loaded from .env through Docker Compose
cipher = Fernet(os.environ["PASSWORD_ENCRYPTION_KEY"].encode())


# Stores encrypted student passwords
class StudentPasswordVault(Base):
    __tablename__ = "student_password_vault"

    user_id: Mapped[int] = mapped_column(
        Integer,
        ForeignKey("users.id", ondelete="CASCADE"),
        primary_key=True
    )

    encrypted_password: Mapped[str] = mapped_column(String(512))
@app.on_event('startup')
def start():
    initialize()
    with Session() as db:
        for username, password, role, sid in [('admin',os.environ['DEMO_ADMIN_PASSWORD'],'admin',None),('student1',os.environ['DEMO_STUDENT_PASSWORD'],'student',1)]:
            if not db.scalar(select(User).where(User.username==username)):
                db.add(User(username=username,hashed_password=password_hash.hash(password),role=role,student_id=sid))
        db.commit()

class Login(BaseModel):
    username: str
    password: str

@app.post('/login')
def login(body: Login):
    with Session() as db:
        user=db.scalar(select(User).where(User.username==body.username))
        if not user or not password_hash.verify(body.password,user.hashed_password):
            raise HTTPException(401,'Invalid username or password')
        claims={'sub':user.username,'role':user.role,'student_id':user.student_id,'exp':datetime.now(timezone.utc)+timedelta(hours=8)}
        token=jwt.encode(claims,SECRET,algorithm='HS256')
        return {'access_token':token,'role':user.role,'username':user.username,'student_id':user.student_id}

@app.get('/health')
def health(): return {'status':'ok','service':'auth'}
class StudentAccountInput(BaseModel):
    student_id: int
    username: str


@app.post("/users/student", status_code=201)
def create_student_account(
    body: StudentAccountInput,
    claims=Depends(identity)
):
    admin_only(claims)

    username = body.username.strip().lower()

    if body.student_id < 1:
        raise HTTPException(400, "Invalid student ID")

    if not re.fullmatch(r"[a-z0-9_.-]{3,80}", username):
        raise HTTPException(400, "Invalid username")

    with Session() as db:
        if db.scalar(select(User).where(User.username == username)):
            raise HTTPException(409, "Username already exists")

        if db.scalar(
            select(User).where(
                User.student_id == body.student_id,
                User.role == "student"
            )
        ):
            raise HTTPException(
                409,
                "This student already has a login account"
            )

        temporary_password = secrets.token_urlsafe(18)

        user = User(
            username=username,
            hashed_password=password_hash.hash(temporary_password),
            role="student",
            student_id=body.student_id
        )
        db.add(user)
        db.flush()

        db.add(
           StudentPasswordVault(
               user_id=user.id,
               encrypted_password=cipher.encrypt(
                  temporary_password.encode()
               ).decode()
           )
        )

        db.commit()

        return {
            "username": username,
            "student_id": body.student_id,
            "temporary_password": temporary_password
        }


class ChangePasswordInput(BaseModel):
    current_password: str
    new_password: str


@app.post("/change-password")
def change_password(
    body: ChangePasswordInput,
    claims=Depends(identity)
):
    if len(body.new_password) < 12:
        raise HTTPException(
            400,
            "New password must contain at least 12 characters"
        )

    with Session() as db:
        user = db.scalar(
            select(User).where(User.username == claims["sub"])
        )

        if not user:
            raise HTTPException(404, "User not found")

        if not password_hash.verify(
            body.current_password,
            user.hashed_password
        ):
            raise HTTPException(401, "Incorrect current password")

        if body.current_password == body.new_password:
            raise HTTPException(400, "Choose a different password")


        user.hashed_password = password_hash.hash(body.new_password)

        vault = db.get(StudentPasswordVault, user.id)

        if vault:
           vault.encrypted_password = cipher.encrypt(
              body.new_password.encode()
           ).decode()
        else:
            db.add(
                StudentPasswordVault(
                user_id=user.id,
                encrypted_password=cipher.encrypt(
                    body.new_password.encode()
                ).decode()
            )
        )

        db.commit()

        return {"message": "Password changed successfully"}
@app.get("/users/students")
def list_student_credentials(claims=Depends(identity)):
    admin_only(claims)

    with Session() as db:
        users = db.scalars(
            select(User).where(User.role == "student")
        ).all()

        result = []

        for user in users:
            vault = db.get(StudentPasswordVault, user.id)

            result.append({
                "student_id": user.student_id,
                "username": user.username,
                "has_saved_password": vault is not None
            })

        return result
@app.delete("/users/student/{student_id}")
def delete_student_login(
    student_id: int,
    claims=Depends(identity)
):
    admin_only(claims)

    with Session() as db:
        user = db.scalar(
            select(User).where(
                User.student_id == student_id,
                User.role == "student"
            )
        )

        if not user:
            return {
                "student_id": student_id,
                "message": "No student login exists"
            }

        username = user.username

        # Delete encrypted password record first
        vault = db.get(StudentPasswordVault, user.id)

        if vault:
            db.delete(vault)

        # Delete Auth account
        db.delete(user)

        db.commit()

        return {
            "student_id": student_id,
            "username": username,
            "message": "Student login and password vault deleted"
        }
