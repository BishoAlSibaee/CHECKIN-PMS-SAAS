/** Points at the isolated PMS staging backend (hotel #3, "GRMS Link Test
 * Hotel") used to test the real GRMS v1 link end to end. That backend has
 * no Reverb process (BROADCAST_DRIVER=log), so fallbackPollMs keeps the
 * UI refreshing via plain HTTP polling instead of a WebSocket that would
 * never connect. */
export const environment = {
  production: false,
  apiUrl: 'http://127.0.0.1:8010/api/',
  i18nVersion: '20260716-room-board-ux',
  reverb: {
    key: '',
    host: '127.0.0.1',
    port: 8081,
    scheme: 'http' as 'http' | 'https',
    authEndpoint: 'http://127.0.0.1:8010/api/broadcasting/auth',
    debounceMs: 400,
    fallbackPollMs: 15000,
  },
};
