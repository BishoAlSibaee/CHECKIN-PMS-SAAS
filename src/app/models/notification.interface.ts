export interface AppNotification {
  id: number;
  hotel_id: number | null;
  type: string;
  title_key: string;
  params: Record<string, string | number> | null;
  link: string | null;
  required_permission: string | null;
  created_at: string;
}
