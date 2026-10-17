import { Login } from './login';

describe('Login', () => {
  it('should accept login payload shape', () => {
    const payload: Login = { job_number: '001', password: 'admin123' };
    expect(payload.job_number).toBe('001');
  });
});
