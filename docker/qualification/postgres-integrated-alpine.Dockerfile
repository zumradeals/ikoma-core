# CANDIDAT CI UNIQUEMENT — ne pas déployer ni substituer une base client.
FROM postgres:18.6-alpine3.24
RUN apk upgrade --no-cache
