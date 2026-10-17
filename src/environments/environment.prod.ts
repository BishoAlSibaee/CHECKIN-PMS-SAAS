export const environment = {
  production: true,
  apiUrl: 'http://checkin-booking.duckdns.org/api/',
  i18nVersion: '20260716-room-board-ux',
  reverb: {
    key: '06b6aac4b13209facfd1',
    host: 'checkin-booking.duckdns.org',
    port: 8080,
    scheme: 'http' as 'http' | 'https',
    authEndpoint: 'http://checkin-booking.duckdns.org/api/broadcasting/auth',
    debounceMs: 400,
    fallbackPollMs: 0,
  },
};
