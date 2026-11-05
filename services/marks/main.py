from fastapi import FastAPI, Depends, HTTPException
from pydantic import BaseModel
from sqlalchemy import Integer, String, Float, select, delete as sql_delete
from sqlalchemy.orm import Mapped, mapped_column
from common.core import Base, Session, initialize, identity, admin_only, own_or_admin

app=FastAPI(title='Mark Service')
class Record(Base):
    __tablename__='marks'
    id: Mapped[int] = mapped_column(Integer,primary_key=True)
    student_id: Mapped[int] = mapped_column(Integer,index=True)
    subject: Mapped[str] = mapped_column(String(100))
    score: Mapped[float] = mapped_column(Float)
class Input(BaseModel):
    student_id: int
    subject: str
    score: float
def out(r):return {'id':r.id,'student_id':r.student_id,'subject':r.subject,'score':r.score}
@app.on_event('startup')
def start():initialize()
@app.get('/health')
def health():return {'status':'ok','service':'marks'}
@app.get('/')
def list_records(student_id:int|None=None,user=Depends(identity)):
    with Session() as db:
        if user['role']=='student':student_id=user['student_id']
        statement=select(Record).order_by(Record.id.desc())
        if student_id is not None:statement=statement.where(Record.student_id==student_id)
        return [out(r) for r in db.scalars(statement).all()]
@app.post('/',status_code=201)
def create(body:Input,user=Depends(identity)):
    admin_only(user)
    with Session() as db:
        r=Record(**body.model_dump());db.add(r);db.commit();db.refresh(r);return out(r)
@app.put('/{record_id}')
def update(record_id:int,body:Input,user=Depends(identity)):
    admin_only(user)
    with Session() as db:
        r=db.get(Record,record_id)
        if not r:raise HTTPException(404,'Record not found')
        for k,v in body.model_dump().items():setattr(r,k,v)
        db.commit();return out(r)
@app.delete('/{record_id}')
def delete(record_id:int,user=Depends(identity)):
    admin_only(user)
    with Session() as db:
        r=db.get(Record,record_id)
        if not r:raise HTTPException(404,'Record not found')
        db.delete(r);db.commit();return {'deleted':record_id}
@app.delete('/student/{student_id}')
def delete_student_marks(student_id: int, user=Depends(identity)):
    admin_only(user)

    with Session() as db:
        result = db.execute(
            sql_delete(Record).where(Record.student_id == student_id)
        )
        db.commit()

        return {
            "student_id": student_id,
            "deleted_marks": result.rowcount
        }
