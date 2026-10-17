import { LoginResponse } from './login-response';

export interface FullLoginResponse {
  success: boolean;
  message: string;
  code: number;
  data: LoginResponse;
}
