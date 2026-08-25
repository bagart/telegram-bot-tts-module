FROM python:3.12-slim

WORKDIR /app

RUN pip install --no-cache-dir "edge-tts>=7" fastapi "uvicorn>=0.30"

COPY app.py .

EXPOSE 55000

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "55000"]
