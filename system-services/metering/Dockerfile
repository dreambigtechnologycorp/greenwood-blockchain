# Candidate build only. Builder must supply a reviewed, digest-pinned prebuilt PHP+SQLite runtime.
ARG GREENWOOD_PHP_RUNTIME
FROM ${GREENWOOD_PHP_RUNTIME}
WORKDIR /app
COPY src ./src
COPY system-services/runtime/bootstrap.php ./system-services/runtime/bootstrap.php
COPY system-services/metering/meter.php ./system-services/metering/meter.php
RUN mkdir -p /var/lib/greenwood/metering && chown 65532:65532 /var/lib/greenwood/metering && chmod 0700 /var/lib/greenwood/metering
USER 65532:65532
ENTRYPOINT ["php", "/app/system-services/metering/meter.php", "/var/lib/greenwood/metering", "--unix"]
