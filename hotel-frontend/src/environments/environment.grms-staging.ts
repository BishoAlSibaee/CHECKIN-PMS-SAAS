/** Points at the isolated PMS staging backend (hotel #3, "GRMS Link Test
 * Hotel") used to test the real GRMS v1 link end to end. Now has its own
 * real Reverb process on port 8081 (separate from the main dev one on
 * 8080), so updates — including GRMS-driven ones like housekeeping/
 * occupancy — push instantly instead of waiting on fallbackPollMs. */
export const environment = {
  production: false,
  apiUrl: 'http://127.0.0.1:8010/api/',
  i18nVersion: '20260716-room-board-ux',
  reverb: {
    key: 'stg7a1b2c3d4e5f6',
    host: '127.0.0.1',
    port: 8081,
    scheme: 'http' as 'http' | 'https',
    authEndpoint: 'http://127.0.0.1:8010/api/broadcasting/auth',
    debounceMs: 400,
    fallbackPollMs: 15000,
  },
};
