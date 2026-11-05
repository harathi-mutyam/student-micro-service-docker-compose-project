FROM python:3.12-slim
WORKDIR /app
COPY services/common/requirements.txt requirements.txt
RUN pip install --no-cache-dir -r requirements.txt
COPY services/common/ /app/common/
COPY services/attendance/main.py /app/main.py
CMD ["uvicorn","main:app","--host","0.0.0.0","--port","8000"]
