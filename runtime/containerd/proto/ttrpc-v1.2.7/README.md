# TTRPC transport schemas

Unmodified upstream schemas from `containerd/ttrpc` v1.2.7, pinned by
containerd v2.2.2 (`301b2dac98f15c27117da5c8af12118a041a31d9`).

| File | SHA-256 |
| --- | --- |
| request.proto | f6923fffbedf0a5446d216a3c3f42e50235fd2aabb1b8e1ab2c619e80bcfa0f0 |
| proto/status.proto | 393b484cdd6abd8d06fefa8c546470e6b61a7495a8f5ba4da0fb0c8afd3322d7 |

The upstream status schema has no package and declares only `code`. PHP
therefore generates global `Status`, registered through Composer's classmap.
Unknown fields survive protobuf round trips. The separately generated
`Google\Rpc\Status` decodes error messages without handwritten protobuf parsing.

Google's status schema is vendored at `../googleapis/google/rpc/status.proto`
from googleapis commit `efc9e8f560a5f1b9b08b62823bfa7955c656cb74`, SHA-256
`f5bfd262e6705c7ae73f32e0ad8ee20ce8c0a2578df8c4f76ebf76b572f295ed`.

Regenerate with `bash scripts/generate-ttrpc-php.sh` from the project root.
Generation was verified with protoc 36.1 and google/protobuf 5.36.1.
Never modify generated vendor/composer files manually.

These transport bindings do not imply that containerd registers Transfer on
TTRPC. Stock containerd 2.2.2 registers that service on gRPC; the raw TTRPC
probe returns status 12 (unknown service).
