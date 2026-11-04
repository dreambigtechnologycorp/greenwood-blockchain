// Greenwood ApplicationRegistryV1 system contract
// AssemblyScript with stub runtime (no GC)

// Memory layout
const INPUT: usize = 4096;    // input read buffer

// Scratch memory regions MUST NOT overlap.
//
// REC uses:
//   REC +   0..79   application record
//   REC +  80..117  release-header scratch
//
// Keep at least 128 bytes reserved for REC scratch before ID.
const REC: usize = 8192;
const ID: usize = 12288;     // caller / digest scratch (32 bytes)
const EP: usize = 12320;     // auxiliary scratch (4 bytes)
const KEY: usize = 16384;    // storage key buffer (256 bytes)

// Application record offsets (within REC)
const SCRATCH: usize = 8704; // separate from persistent application-record bytes
const OFF_VER: usize = 0;       // u32 version
const OFF_CTRL: usize = 4;      // 32 bytes controller
const OFF_PEND: usize = 36;     // 32 bytes pending_controller
const OFF_EP: usize = 68;       // u32 ep
const OFF_ACT: usize = 72;      // u32 active_release
const OFF_RCNT: usize = 76;     // u32 release_count

// Status codes
const OK: i32 = 0;
const ERR: i32 = -1;
const NOT_FOUND: i32 = -2;
const UNAUTHORIZED: i32 = -3;
const INVALID_ARG: i32 = -4;

// Capability bits
const CAP_PUB: u16 = 0x0001;
const CAP_DEP: u16 = 0x0002;
const CAP_OPE: u16 = 0x0004;
const CAP_ADM: u16 = 0x0008;
const CAP_ALL: u16 = 0x000F;

// Max services per release
const MAX_SVCS: i32 = 32;

// ─── Host imports ───────────────────────────────────────────────────────
@external("greenwood", "input_size")
declare function input_size(): i32;

@external("greenwood", "input_read")
declare function input_read(ptr: i32, len: i32): i32;

@external("greenwood", "caller")
declare function caller(ptr: i32): i32;

@external("greenwood", "storage_get")
declare function storage_get(key: i32, key_len: i32, buf: i32, buf_len: i32): i32;

@external("greenwood", "storage_set")
declare function storage_set(key: i32, key_len: i32, buf: i32, buf_len: i32): i32;

@external("greenwood", "storage_delete")
declare function storage_del(key: i32, key_len: i32): i32;

@external("greenwood", "return_value")
declare function return_value(ptr: i32, len: i32): i32;

// ─── Memory helpers ─────────────────────────────────────────────────────
function wu32(p: usize, v: i32): void {
  store<u8>(p, (v >> 24) as u8);
  store<u8>(p + 1, (v >> 16) as u8);
  store<u8>(p + 2, (v >> 8) as u8);
  store<u8>(p + 3, v as u8);
}

function wu16(p: usize, v: i32): void {
  store<u8>(p, (v >> 8) as u8);
  store<u8>(p + 1, v as u8);
}

function ru32(p: usize): i32 {
  return ((load<u8>(p) as i32) << 24)
    | ((load<u8>(p + 1) as i32) << 16)
    | ((load<u8>(p + 2) as i32) << 8)
    | (load<u8>(p + 3) as i32);
}

function ru16(p: usize): i32 {
  return ((load<u8>(p) as i32) << 8)
    | (load<u8>(p + 1) as i32);
}

function copy(dst: usize, src: usize, n: i32): void {
  for (let i: i32 = 0; i < n; i++) {
    store<u8>(dst + i, load<u8>(src + i));
  }
}

function zero(p: usize, n: i32): void {
  for (let i: i32 = 0; i < n; i++) {
    store<u8>(p + i, 0);
  }
}

function bcmp(a: usize, b: usize, n: i32): i32 {
  for (let i: i32 = 0; i < n; i++) {
    if (load<u8>(a + i) != load<u8>(b + i)) return 1;
  }
  return 0;
}

// ─── Storage key builders ───────────────────────────────────────────────
// Build 5-byte app key at KEY: 0x10 + app(u32)
function key_app(app: i32): void {
  store<u8>(KEY, 0x10);
  wu32(KEY + 1, app);
}

// Build delegate key at KEY: 0x20 + app(u32) + ep(u32) + account(32)
function key_del(app: i32, ep: i32): void {
  store<u8>(KEY, 0x20);
  wu32(KEY + 1, app);
  wu32(KEY + 5, ep);
  copy(KEY + 9, ID, 32);
}

// Build release header key at KEY: 0x30 + app(u32) + rel(u32)
function key_rel(app: i32, rel: i32): void {
  store<u8>(KEY, 0x30);
  wu32(KEY + 1, app);
  wu32(KEY + 5, rel);
}

// Build service key at KEY: 0x31 + app(u32) + rel(u32) + idx(u16)
function key_svc(app: i32, rel: i32, idx: i32): void {
  store<u8>(KEY, 0x31);
  wu32(KEY + 1, app);
  wu32(KEY + 5, rel);
  wu16(KEY + 9, idx);
}

// Build digest lookup key: start with 0x32 + app(u32) + rel(u32) at KEY
function key_dig(app: i32, rel: i32): void {
  store<u8>(KEY, 0x32);
  wu32(KEY + 1, app);
  wu32(KEY + 5, rel);
}

// ─── App record I/O (76 bytes at REC) ─────────────────────────────────
function app_read(app: i32): i32 {
  key_app(app);
  return storage_get(KEY as i32, 5, REC as i32, 80);
}

function app_write(app: i32): void {
  key_app(app);
  storage_set(KEY as i32, 5, REC as i32, 80);
}

// ─── Global counter (1-byte key 0x01, 4-byte value) ──────────────────
function cnt_read(): i32 {
  let v = storage_get(1, 1, REC as i32, 4);
  if (v < 4) return 0;
  return ru32(REC);
}

function cnt_write(v: i32): void {
  wu32(REC, v);
  storage_set(1, 1, REC as i32, 4);
}

// ─── Authority check ────────────────────────────────────────────────────
// Returns effective capability bits for the caller.
// Returns -2 (NOT_FOUND) if app doesn't exist.
function authority(app: i32): i32 {
  if (app_read(app) < 76) return reject();

  // Get caller identity into ID
  caller(ID as i32);

  // Check if caller == controller (REC + 4)
  if (bcmp(REC + OFF_CTRL, ID, 32) == 0) return CAP_ALL as i32;

  // Read ep
  let ep = ru32(REC + OFF_EP);

  // Build delegate key
  key_del(app, ep);

  // Read delegate capabilities (2 bytes)
  let len = storage_get(KEY as i32, 41, SCRATCH as i32, 2);
  if (len < 2) return 0;

  return ru16(SCRATCH);
}

// ─── greenwood_call — dispatch ──────────────────────────────────────────
export function greenwood_call(): i32 {
  // Read input
  let inLen = input_size();
  if (inLen < 11 || inLen > 4096) return reject();
  input_read(INPUT as i32, inLen);

  // Canonical ABI:
  // [u32 method_len][method][u32 calldata_len][calldata]
  let methodLen = ru32(INPUT);
  if (methodLen != 3) return reject();

  // Read the fixed 3-byte registry method tag.
  let m: i32 =
    ((load<u8>(INPUT + 4) as i32) << 16)
    | ((load<u8>(INPUT + 5) as i32) << 8)
    | (load<u8>(INPUT + 6) as i32);

  // Skip method length + method bytes + calldata length.
  let calldata = INPUT + 4 + methodLen + 4;
  let app: i32, v: i32, cnt: i32, pos: i32, nl: i32, ep: i32, cap: i32, i: i32;
  let size = ru32(INPUT + 7);
  if (size != inLen - 11) return reject();
  if (m == 0x637265) { if (size != 0) return reject(); }
  else if (m == 0x70726f || m == 0x726576 || m == 0x716465 || m == 0x716175) { if (size != 36) return reject(); }
  else if (m == 0x677261) { if (size != 38) return reject(); }
  else if (m == 0x616363 || m == 0x716170 || m == 0x716163) { if (size != 4) return reject(); }
  else if (m == 0x616374 || m == 0x717265) { if (size != 8) return reject(); }
  else if (m == 0x717273) {
    if (size < 10 || size > 73 || load<u8>(calldata + size - 1) != 0) return reject();
    for (let j = 8; j < size - 1; j++) if (load<u8>(calldata + j) == 0) return reject();
  }
  else if (m == 0x707562) {
    // Validate every entry before the first storage write: failures cannot leave partial releases.
    if (size < 6) return reject();
    cnt = ru16(calldata + 4); if (cnt < 1 || cnt > MAX_SVCS) return reject();
    pos = 6;
    for (let j = 0; j < cnt; j++) {
      if (pos >= size) return reject();
      nl = load<u8>(calldata + pos);
      if (nl < 1 || nl > 63 || pos + 1 + nl + 32 > size) return reject();
      let previous = 6;
      for (let k = 0; k < j; k++) {
        let oldLen = load<u8>(calldata + previous);
        if (oldLen == nl && bcmp(calldata + previous + 1, calldata + pos + 1, nl) == 0) return reject();
        previous += 1 + oldLen + 32;
      }
      for (let k = 0; k < nl; k++) {
        let ch = load<u8>(calldata + pos + 1 + k);
        if (!((ch >= 97 && ch <= 122) || (k > 0 && ((ch >= 48 && ch <= 57) || ch == 45)))) return reject();
      }
      pos += 1 + nl + 32;
    }
    if (pos != size) return reject();
  } else return reject();


  // ═══════ cre = create_application ═══════════════════════════════════
  if (m == 0x637265) {
    caller(ID as i32);
    v = cnt_read(); if (v == -1) return reject(); v++;
    cnt_write(v);
    // Build record
    wu32(REC + OFF_VER, 1);
    copy(REC + OFF_CTRL, ID, 32);
    zero(REC + OFF_PEND, 32);
    wu32(REC + OFF_EP, 1);
    wu32(REC + OFF_ACT, 0);
    wu32(REC + OFF_RCNT, 0);
    app_write(v);

    // Finalized receipts preserve return_value(), not greenwood_call()'s
    // raw i32 result. Return the assigned application ID as u32 BE.
    wu32(REC, v);
    return_value(REC as i32, 4);
    return OK;
  }

  // ═══════ pro = propose_controller ═══════════════════════════════════
  if (m == 0x70726F) {
    app = ru32(calldata);
    cap = authority(app);
    if (cap == NOT_FOUND) return reject();
    if (bcmp(REC + OFF_CTRL, ID, 32) != 0) return reject(); // delegates cannot transfer ownership
    copy(REC + OFF_PEND, calldata + 4, 32);  // store new controller
    app_write(app);
    return OK;
  }

  // ═══════ acc = accept_controller ════════════════════════════════════
  if (m == 0x616363) {
    app = ru32(calldata);
    if (app_read(app) < 76) return reject();
    caller(ID as i32);
    if (bcmp(REC + OFF_PEND, ID, 32) != 0) return reject();
    copy(REC + OFF_CTRL, ID, 32);
    zero(REC + OFF_PEND, 32);
    ru32(REC + OFF_EP);  // just for side-effect of reading
    ep = ru32(REC + OFF_EP); if (ep == -1) return reject(); ep++;
    wu32(REC + OFF_EP, ep);
    app_write(app);
    return OK;
  }

  // ═══════ gra = grant_delegate ═══════════════════════════════════════
  if (m == 0x677261) {
    app = ru32(calldata);
    cap = authority(app);
    if (cap == NOT_FOUND) return reject();
    if (cap != CAP_ALL && (cap & CAP_ADM) == 0) return reject();

    // Read capabilities as canonical big-endian u16.
    v = ru16(calldata + 36);
    if (v == 0) return reject();
    if ((v & ~CAP_ALL) != 0) return reject();

    ep = ru32(REC + OFF_EP);

    // Build delegate key:
    // 0x20 + app(u32) + epoch(u32) + delegate(32) = 41 bytes.
    store<u8>(KEY, 0x20);
    wu32(KEY + 1, app);
    wu32(KEY + 5, ep);
    copy(KEY + 9, calldata + 4, 32);

    // Capability is the value, not part of the key.
    wu16(KEY + 41, v);
    storage_set(KEY as i32, 41, KEY as i32 + 41, 2);
    return OK;
  }

  // ═══════ rev = revoke_delegate ══════════════════════════════════════
  if (m == 0x726576) {
    app = ru32(calldata);
    cap = authority(app);
    if (cap == NOT_FOUND) return reject();
    if (cap != CAP_ALL && (cap & CAP_ADM) == 0) return reject();

    ep = ru32(REC + OFF_EP);
    store<u8>(KEY, 0x20);
    wu32(KEY + 1, app);
    wu32(KEY + 5, ep);
    copy(KEY + 9, calldata + 4, 32);
    storage_del(KEY as i32, 41);
    return OK;
  }

  // ═══════ pub = publish_release ══════════════════════════════════════
  if (m == 0x707562) {
    app = ru32(calldata);
    cap = authority(app);
    if (cap == NOT_FOUND) return reject();
    if (cap != CAP_ALL && (cap & CAP_PUB) == 0) return reject();

    // Read service count from calldata+4
    cnt = (load<u8>(calldata + 4) << 8) | load<u8>(calldata + 5);
    if (cnt == 0) return reject();
    if (cnt > MAX_SVCS) return reject();

    v = ru32(REC + OFF_RCNT); if (v == -1) return reject(); v++;  // new release number

    // Write release header at REC+80: rel_num(4) + publisher(32) + count(2) = 38 bytes
    wu32(REC + 80, v);
    copy(REC + 84, ID, 32);
    wu16(REC + 116, cnt);

    key_rel(app, v);
    storage_set(KEY as i32, 9, REC as i32 + 80, 38);

    pos = 6;  // calldata offset after app_id(4) + count(2) = 6
    for (i = 0; i < cnt; i++) {
      nl = load<u8>(calldata + pos);
      if (nl == 0 || nl > 64) return reject();
      pos++;

      // Write service entry
      key_svc(app, v, i);
      store<u8>(KEY + 11, nl);  // name_len
      copy(KEY + 12, calldata + pos, nl);
      copy(KEY + 12 + nl, calldata + pos + nl, 32);
      storage_set(KEY as i32, 11, KEY as i32 + 11, 33 + nl);

      // Write digest lookup:
      // key   = 0x32 + app(u32) + release(u32) + service_name
      // value = digest(32)
      copy(ID, calldata + pos + nl, 32);
      key_dig(app, v);
      copy(KEY + 9, calldata + pos, nl);
      storage_set(KEY as i32, 9 + nl, ID as i32, 32);

      pos += nl + 32;
    }

    wu32(REC + OFF_RCNT, v);
    app_write(app);

    // Return the finalized release number through the receipt-visible ABI.
    wu32(REC, v);
    return_value(REC as i32, 4);
    return OK;
  }

  // ═══════ act = activate_release ═════════════════════════════════════
  if (m == 0x616374) {
    app = ru32(calldata);
    cap = authority(app);
    if (cap == NOT_FOUND) return reject();
    if (cap != CAP_ALL && (cap & CAP_DEP) == 0) return reject();

    v = ru32(calldata + 4);  // release number
    key_rel(app, v);
    if (storage_get(KEY as i32, 9, SCRATCH as i32, 38) < 38) return reject();

    wu32(REC + OFF_ACT, v);
    app_write(app);
    return OK;
  }

  // ═══════ qap = query_application ════════════════════════════════════
  if (m == 0x716170) {
    app = ru32(calldata);
    if (app_read(app) < 76) return reject();
    return_value(REC as i32, 80);
    return OK;
  }

  // ═══════ qde = query_delegate ═══════════════════════════════════════
  if (m == 0x716465) {
    app = ru32(calldata);
    if (app_read(app) < 76) return reject();

    ep = ru32(REC + OFF_EP);
    store<u8>(KEY, 0x20);
    wu32(KEY + 1, app);
    wu32(KEY + 5, ep);
    copy(KEY + 9, calldata + 4, 32);

    if (storage_get(KEY as i32, 41, REC as i32, 2) < 2) return reject();
    return_value(REC as i32, 2);
    return OK;
  }

  // ═══════ qau = query_authority ══════════════════════════════════════
  if (m == 0x716175) {
    app = ru32(calldata);
    if (app_read(app) < 76) return reject();

    // Check if account matches controller
    if (bcmp(REC + OFF_CTRL, calldata + 4, 32) == 0) {
      v = CAP_ALL as i32;
    } else {
      // Check current-ep delegate
      ep = ru32(REC + OFF_EP);
      store<u8>(KEY, 0x20);
      wu32(KEY + 1, app);
      wu32(KEY + 5, ep);
      copy(KEY + 9, calldata + 4, 32);

      if (storage_get(KEY as i32, 41, REC as i32, 2) >= 2) {
        v = ru16(REC);
      } else {
        v = 0;
      }
    }

    wu16(REC, v);
    return_value(REC as i32, 2);
    return OK;
  }

  // ═══════ qre = query_release ════════════════════════════════════════
  if (m == 0x717265) {
    app = ru32(calldata);
    if (app_read(app) < 76) return reject();
    v = ru32(calldata + 4);
    key_rel(app, v);
    if (storage_get(KEY as i32, 9, REC as i32, 38) < 38) return reject();
    return_value(REC as i32, 38);
    return OK;
  }

  // ═══════ qrs = query_release_service ════════════════════════════════
  if (m == 0x717273) {
    app = ru32(calldata);
    if (app_read(app) < 76) return reject();
    v = ru32(calldata + 4);

    // Find service name length (null-terminated at calldata + 8)
    nl = 0;
    for (let j: i32 = 0; j < 64; j++) {
      if (load<u8>(calldata + 8 + j) == 0) { nl = j; break; }
    }
    if (nl == 0) return reject();

    // Build lookup key
    key_dig(app, v);
    copy(KEY + 9, calldata + 8, nl);

    if (storage_get(KEY as i32, 9 + nl, REC as i32, 32) < 32) return reject();
    return_value(REC as i32, 32);
    return OK;
  }

  // ═══════ qac = query_active_release ═════════════════════════════════
  if (m == 0x716163) {
    app = ru32(calldata);
    if (app_read(app) < 76) return reject();
    v = ru32(REC + OFF_ACT);
    wu32(REC, v);
    return_value(REC as i32, 4);
    return OK;
  }

  return reject();
}

function reject(): i32 { unreachable(); return -1; }
