FROM ghcr.io/project-zot/zot@sha256:6b69512c00dceaad05b1144e6079aac6aa7309d7fd200f9947ecb1de09cf48c8

COPY system-services/registry/config.json /etc/zot/config.json

EXPOSE 5000

# Intentionally inherit the pinned Zot image ENTRYPOINT:
# /usr/local/bin/zot-linux-arm64
CMD ["serve", "/etc/zot/config.json"]
