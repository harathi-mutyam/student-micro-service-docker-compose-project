from fastapi import FastAPI, Depends, HTTPException
from pydantic import BaseModel, Field
from sqlalchemy import Integer, String, select
from sqlalchemy.orm import Mapped, mapped_column
from common.core import Base, Session, initialize, identity, admin_only, own_or_admin
from fastapi import Header
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError

app=FastAPI(title='Student Service')
class Student(Base):
    __tablename__='students'
    id: Mapped[int]=mapped_column(Integer,primary_key=True,autoincrement=True)
    name: Mapped[str]=mapped_column(String(100))
    email: Mapped[str]=mapped_column(String(120),unique=True)
    course: Mapped[str]=mapped_column(String(100))

class StudentInput(BaseModel):
    name: str=Field(min_length=1,max_length=100)
    email: str=Field(min_length=3,max_length=120)
    course: str=Field(min_length=1,max_length=100)

def asdict(s):return {'id':s.id,'name':s.name,'email':s.email,'course':s.course}
@app.on_event('startup')
def start():
    initialize()
    with Session() as db:
        if not db.get(Student,1):
            db.add(Student(id=1,name='Demo Student',email='student1@example.test',course='Computer Science'))
            db.commit()
@app.get('/health')
def health():return {'status':'ok','service':'students'}
@app.get('/')
def list_students(user=Depends(identity)):
    with Session() as db:
        if user['role']=='admin': return [asdict(s) for s in db.scalars(select(Student).order_by(Student.id)).all()]
        s=db.get(Student,user.get('student_id'))
        return [asdict(s)] if s else []
@app.get('/{student_id}')
def get_student(student_id:int,user=Depends(identity)):
    own_or_admin(user,student_id)
    with Session() as db:
        s=db.get(Student,student_id)
        if not s:raise HTTPException(404,'Student not found')
        return asdict(s)
@app.post('/',status_code=201)
def create_student(body:StudentInput,user=Depends(identity)):
    admin_only(user)
    with Session() as db:
        if db.scalar(select(Student).where(Student.email==body.email)):raise HTTPException(409,'Email already exists')
        s=Student(**body.model_dump());db.add(s);db.commit();db.refresh(s)
        return asdict(s)
@app.put('/{student_id}')
def update_student(student_id:int,body:StudentInput,user=Depends(identity)):
    admin_only(user)
    with Session() as db:
        s=db.get(Student,student_id)
        if not s:raise HTTPException(404,'Student not found')
        for k,v in body.model_dump().items():setattr(s,k,v)
        db.commit();return asdict(s)
@app.delete('/{student_id}')
def delete_student(
    student_id: int,
    user=Depends(identity),
    authorization: str | None = Header(default=None)
):
    admin_only(user)

    with Session() as db:
        student = db.get(Student, student_id)

        if not student:
            raise HTTPException(404, 'Student not found')

        # Clean up attendance and marks before deleting student.
        services = [
            (
                "attendance",
                f"http://attendance:8000/student/{student_id}"
            ),
            (
                "marks",
                f"http://marks:8000/student/{student_id}"
            ),
            (
                "auth",
                f"http://auth:8000/users/student/{student_id}"
            )
        ]   
        results = {}

        for service_name, url in services:
            request = Request(
                url,
                method="DELETE",
                headers={"Authorization": authorization}
            )

            try:
                with urlopen(request, timeout=10) as response:
                    results[service_name] = response.status

            except (HTTPError, URLError, TimeoutError) as exc:
                raise HTTPException(
                    status_code=502,
                    detail=f"Failed to clean up {service_name}: {exc}"
                )

        db.delete(student)
        db.commit()

        return {
            "deleted_student": student_id,
            "related_records_cleanup": results
        }
