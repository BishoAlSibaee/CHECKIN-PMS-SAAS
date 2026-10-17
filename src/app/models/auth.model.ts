export interface LoginRequest {
  email: string;
  password: string;
}

export interface LoginResponseData {
  id: number;
  name: string;
  email: string;
  token: string;
}

export interface ApiEnvelope<T> {
  success: boolean;
  message: string;
  code: number;
  data: T;
}
