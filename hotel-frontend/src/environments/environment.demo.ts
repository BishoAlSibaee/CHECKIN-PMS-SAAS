// Trial/demo deployment on shared hosting — no Reverb (no persistent WebSocket
// process is possible there), so fallbackPollMs keeps notifications/Airbnb
// badges working via plain HTTP polling instead of realtime push.
export const environment = {
  production: true,
  apiUrl: 'https://CHANGE_ME-api.yourdomain.com/api/',
  i18nVersion: '20260716-room-board-ux',
  reverb: {
    key: '',
    host: 'CHANGE_ME-api.yourdomain.com',
    port: 443,
    scheme: 'https' as 'http' | 'https',
    authEndpoint: 'https://CHANGE_ME-api.yourdomain.com/api/broadcasting/auth',
    debounceMs: 400,
    fallbackPollMs: 15000,
  },
};
