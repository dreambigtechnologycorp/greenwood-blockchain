export function hex32(hex) {
  if (typeof hex !== 'string' || !/^[a-f0-9]{64}$/.test(hex)) throw new TypeError('Expected lowercase 32-byte hex');
  return Uint8Array.from(hex.match(/../g), x => parseInt(x, 16));
}
export function frame(method, data) {
  if (typeof method !== 'string' || !/^[a-z_]{1,32}$/.test(method) || !(data instanceof Uint8Array) || data.length + method.length + 8 > 2048) throw new TypeError('Invalid invocation');
  const name = Buffer.from(method), prefix = Buffer.alloc(4), size = Buffer.alloc(4);
  prefix.writeUInt32BE(name.length); size.writeUInt32BE(data.length);
  return Buffer.concat([prefix, name, size, data]);
}
