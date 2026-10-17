export interface GuestClassification {
  id: string;
  name: string;
  name_ar?: string;
  name_en?: string;
  description?: string;
  color?: string;
  discount_id?: string;
  active?: number;
}

export interface GuestFeature {
  id: string;
  name: string;
  name_ar?: string;
  name_en?: string;
  feature_description?: string;
  description?: string; // For compatibility
  icon?: string;
}

export interface GuestClassificationFeature {
  id: string;
  guest_classification_id: string;
  guest_feature_id: string;
  feature?: GuestFeature;
}

export interface ClientClassification {
  id: string;
  client_id: string;
  classifications_id: string; // Changed from guest_classification_id to match backend
  client?: any;
  classification?: GuestClassification;
  created_at: any;
}
