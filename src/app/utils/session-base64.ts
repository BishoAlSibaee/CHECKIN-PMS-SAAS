/**
 * btoa()/atob() only handle Latin-1. Login payloads carry Arabic text (user
 * name, role, hotel name), so encoding them directly throws
 * `InvalidCharacterError` and silently aborts login right before the
 * post-login navigate() call. These wrap the UTF-8 bytes first.
 */
export function encodeSessionString(value: string): string {
  const utf8Bytes = encodeURIComponent(value).replace(/%([0-9A-F]{2})/g, (_, hex) =>
    String.fromCharCode(parseInt(hex, 16))
  );
  return btoa(utf8Bytes);
}

export function decodeSessionString(value: string): string {
  const binary = atob(value);
  const percentEncoded = binary
    .split('')
    .map((c) => '%' + c.charCodeAt(0).toString(16).padStart(2, '0'))
    .join('');
  return decodeURIComponent(percentEncoded);
}
