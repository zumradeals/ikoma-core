# LABORATOIRE UNIQUEMENT : candidate de sécurité, jamais une image à déployer.
# Recompile le code ORIGINAL gosu 1.19 depuis un commit upstream exact avec
# Go 1.27.3 corrigé, puis le substitue dans PostgreSQL Alpine officiel.
# Le pipeline CI doit encore qualifier le contenu, la provenance, Trivy et la restauration.
FROM golang:1.27.3-alpine3.24 AS gosu-build
ARG GOSU_SOURCE_SHA=6456aaa0f3c854d199d0f037f068eb97515b7513
RUN apk add --no-cache git ca-certificates
WORKDIR /src/gosu
RUN set -eux; \
    git init .; \
    git remote add origin https://github.com/tianon/gosu.git; \
    git fetch --depth=1 origin "$GOSU_SOURCE_SHA"; \
    git checkout --detach FETCH_HEAD; \
    test "$(git rev-parse HEAD)" = "$GOSU_SOURCE_SHA"; \
    grep -Fx 'const Version = "1.19"' version.go; \
    go mod download; \
    go mod verify; \
    CGO_ENABLED=0 go build -trimpath -buildvcs=false -ldflags="-d -w" -o /out/gosu .; \
    go version -m /out/gosu; \
    /out/gosu --version

FROM postgres:18.6-alpine3.24
RUN apk upgrade --no-cache
COPY --from=gosu-build --chmod=0755 /out/gosu /usr/local/bin/gosu
RUN set -eux; \
    gosu --version; \
    gosu nobody true; \
    gosu nobody id; \
    postgres --version
