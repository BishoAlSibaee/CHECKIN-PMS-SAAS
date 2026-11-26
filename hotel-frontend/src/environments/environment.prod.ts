export const environment = {
  production: true,
  apiUrl: 'https://api.checkin-pms.com/api/',
  i18nVersion: '20260716-room-board-ux',
  reverb: {
    key: '2b599b33a70e05b7c3cbcd309dfd999bbca6cc17',
    host: 'api.checkin-pms.com',
    port: 443,
    scheme: 'https' as 'http' | 'https',
    authEndpoint: 'https://api.checkin-pms.com/api/broadcasting/auth',
    debounceMs: 400,
    fallbackPollMs: 0,
  },
};
