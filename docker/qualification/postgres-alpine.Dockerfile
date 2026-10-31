# EXPERIMENTATION UNIQUEMENT : ne jamais employer comme image PostgreSQL de production.
FROM postgres:18.6-alpine3.24
RUN apk upgrade --no-cache
