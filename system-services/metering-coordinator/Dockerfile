ARG GREENWOOD_PHP_RUNTIME
FROM ${GREENWOOD_PHP_RUNTIME}
WORKDIR /app
COPY src ./src
COPY system-services/runtime/bootstrap.php system-services/runtime/resource-workflow.php system-services/runtime/resource-worker.php ./system-services/runtime/
USER 65532:65532
ENTRYPOINT ["php", "/app/system-services/runtime/resource-worker.php", "/var/lib/greenwood/coordinator"]
