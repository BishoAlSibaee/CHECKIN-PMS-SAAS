export interface PlatformAdminAccount {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
  created_at: string;
}

export interface CreatePlatformAdminRequest {
  name: string;
  email: string;
  password: string;
}
