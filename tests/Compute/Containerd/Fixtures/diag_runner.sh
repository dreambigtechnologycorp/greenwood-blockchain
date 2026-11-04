#!/usr/bin/env bash
# Pass 4A concurrency diagnostics runner. Diagnostic-only (uses ctr manually,
# which is allowed for diagnostics per the plan). Runs on greenwood-dev.
set -u
cd "$HOME/greenwood-php"
NS=${GREENWOOD_NS:-greenwood}
W=${WORKERS:-5}
REPS=${REPS:-3}
IMG=docker.io/library/alpine:latest
OUT=$HOME/diag-out
mkdir -p "$OUT"

clean_gw() {
  # Remove Greenwood-owned resources between tests (diagnostic ctr use).
  for t in $(sudo ctr -n "$NS" tasks ls -q 2>/dev/null); do sudo ctr -n "$NS" tasks kill -s 9 "$t" >/dev/null 2>&1; sudo ctr -n "$NS" tasks rm "$t" >/dev/null 2>&1; done
  for c in $(sudo ctr -n "$NS" c ls -q 2>/dev/null); do sudo ctr -n "$NS" c rm "$c" >/dev/null 2>&1; done
  for s in $(sudo ctr -n "$NS" -a snapshots ls 2>/dev/null | awk '{print $1}' | grep '^gw-snapshot-'); do sudo ctr -n "$NS" snapshots rm "$s" >/dev/null 2>&1; done
}

make_cold() {
  clean_gw
  # Remove image metadata + content + committed rootfs snapshot => fully cold.
  sudo ctr -n "$NS" images rm "$IMG" >/dev/null 2>&1
  sudo ctr -n "$NS" content ls -q 2>/dev/null | while read -r d; do sudo ctr -n "$NS" content rm "$d" >/dev/null 2>&1; done
  for s in $(sudo ctr -n "$NS" snapshots ls 2>/dev/null | awk '{print $1}' | grep '^sha256:'); do sudo ctr -n "$NS" snapshots rm "$s" >/dev/null 2>&1; done
}

prewarm() {
  php -r '
    require "vendor/autoload.php";
    use Greenwood\Compute\Containerd\{ContainerdClientSet, ContainerdConnection};
    use Greenwood\Compute\Containerd\Service\ImagePreparationService;
    (new ImagePreparationService(new ContainerdClientSet(new ContainerdConnection())))
      ->ensureReady("docker.io/library/alpine:latest");
    echo "prewarmed\n";
  '
}

residue() {
  echo "tasks: $(sudo ctr -n "$NS" tasks ls -q 2>/dev/null | wc -l)"
  echo "containers: $(sudo ctr -n "$NS" c ls -q 2>/dev/null | wc -l)"
  echo "active snapshots: $(sudo ctr -n "$NS" snapshots ls 2>/dev/null | grep -c ACTIVE)"
  echo "gw fifo dirs: $(ls -d /tmp/gw-exec-* 2>/dev/null | wc -l)"
}

run_wave() {
  local mode=$1 tag=$2
  local log="$OUT/${tag}.jsonl"
  : > "$log"
  local pids=()
  for i in $(seq 1 "$W"); do
    php tests/Compute/Containerd/Fixtures/diag_worker.php "$log" "$mode" "$IMG" > "$OUT/${tag}.w${i}.json" 2>"$OUT/${tag}.w${i}.err" &
    pids+=($!)
  done
  local rc=0
  for p in "${pids[@]}"; do wait "$p" || rc=1; done
  return $rc
}

echo "=== Test C: prewarmed image, workers do NOT call ensureReady (pure execution concurrency) ==="
for r in $(seq 1 "$REPS"); do
  clean_gw; prewarm >/dev/null
  run_wave noxfer "C-r${r}"
  echo "C run $r rc=$? failures=$(grep -c '"FAILURE"' "$OUT/C-r${r}.jsonl")"
  residue
  sudo ctr -n "$NS" images rm "$IMG" >/dev/null 2>&1 || true
done

echo "=== Test A: prewarmed image, workers DO call ensureReady (concurrent Transfer of present image) ==="
for r in $(seq 1 "$REPS"); do
  clean_gw; prewarm >/dev/null
  run_wave transfer "A-r${r}"
  echo "A run $r rc=$? failures=$(grep -c '"FAILURE"' "$OUT/A-r${r}.jsonl")"
  residue
done

echo "=== Test B: fully cold, all workers ensureReady simultaneously ==="
for r in $(seq 1 "$REPS"); do
  make_cold
  run_wave transfer "B-r${r}"
  echo "B run $r rc=$? failures=$(grep -c '"FAILURE"' "$OUT/B-r${r}.jsonl")"
  residue
done

echo "done. logs in $OUT"
