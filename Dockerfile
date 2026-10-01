FROM python:3.12-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 DATA_DIR=/data
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt && useradd --uid 10001 --create-home integra && mkdir -p /data && chown integra:integra /data
COPY --chown=integra:integra . .
RUN DEBUG=1 python manage.py collectstatic --noinput
USER integra
EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8000/health/')"
CMD ["sh", "entrypoint.sh"]
