import { FullLoginResponse } from './full-login-response';

describe('FullLoginResponse', () => {
  it('should accept API response shape', () => {
    const res: FullLoginResponse = {
      success: true,
      message: 'OK',
      code: 200,
      data: { id: 1, email: 'a@b.com', token: 't', role: 'admin' },
    };
    expect(res.success).toBe(true);
    expect(res.data.token).toBe('t');
  });
});
