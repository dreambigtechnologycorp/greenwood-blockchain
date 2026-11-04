FROM php:8.5.12-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv/greenwood-registry-auth

COPY LICENSE NOTICE TRADEMARKS.md THIRD_PARTY_NOTICES.md ./
COPY LICENSES ./LICENSES

COPY system-services/registry-auth/autoload.php ./autoload.php
COPY system-services/registry-auth/public ./public
COPY system-services/registry-auth/src ./src

# Reuse Greenwood's canonical protocol implementation.
COPY src ./greenwood-src
COPY system-services/deployment-controller/src/Registry ./deployment-controller-src/Registry
COPY system-services/deployment-controller/src/Auth/DeploymentIdentity.php ./deployment-controller-src/Auth/DeploymentIdentity.php

RUN mkdir -p /var/lib/greenwood/registry-auth

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
