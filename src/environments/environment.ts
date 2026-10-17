export const environment = {
  production: false,
  apiUrl: 'http://127.0.0.1:8001/api/',
  i18nVersion: '20260716-room-board-ux',
  reverb: {
    key: 'dvip5lsefhex5zisl8ie',
    host: '127.0.0.1',
    port: 8082,
    scheme: 'http' as 'http' | 'https',
    authEndpoint: 'http://127.0.0.1:8001/api/broadcasting/auth',
    debounceMs: 400,
    fallbackPollMs: 0,
  },
};
