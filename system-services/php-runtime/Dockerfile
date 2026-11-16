ARG GREENWOOD_PHP_BASE
FROM ${GREENWOOD_PHP_BASE}
ARG SOURCE_DATE_EPOCH
COPY inputs/ /tmp/greenwood-runtime-inputs/
RUN cd /tmp/greenwood-runtime-inputs && sha256sum -c SHA256SUMS \
    && dpkg -i libffi8_3.4.8-2_arm64.deb libffi-dev_3.4.8-2_arm64.deb \
    && docker-php-ext-install -j2 ffi pcntl \
    && mkdir -p /opt/greenwood/lib /usr/share/licenses/wasmtime \
    && cp WASMTIME-LICENSE /usr/share/licenses/wasmtime/LICENSE \
    && cp libwasmtime.so /opt/greenwood/lib/libwasmtime.so \
    && chmod 0555 /opt/greenwood/lib/libwasmtime.so \
    && printf 'ffi.enable=true\n' > /usr/local/etc/php/conf.d/greenwood-ffi.ini \
    && rm -rf /tmp/greenwood-runtime-inputs
ENV GREENWOOD_WASMTIME_LIB=/opt/greenwood/lib/libwasmtime.so
COPY verify.php /opt/greenwood/verify-runtime.php
RUN php /opt/greenwood/verify-runtime.php > /opt/greenwood/runtime-verification.json \
    && ldd /usr/local/bin/php > /opt/greenwood/php-native-dependencies.txt \
    && ldd /opt/greenwood/lib/libwasmtime.so > /opt/greenwood/wasmtime-native-dependencies.txt \
    && dpkg-query -W -f='${Package}\t${Version}\t${Architecture}\n' > /opt/greenwood/debian-packages.tsv
CMD ["php", "/opt/greenwood/verify-runtime.php"]
