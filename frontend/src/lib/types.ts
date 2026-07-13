export type UserRole = "admin" | "bidder";

export interface User {
  id: number;
  name: string;
  email: string;
  role: UserRole;
}

export type LeadStatus =
  | "new"
  | "scoring"
  | "ready"
  | "sent"
  | "replied"
  | "won"
  | "archived";

export type ClientStage =
  | "new"
  | "talking"
  | "negotiating"
  | "closing"
  | "won"
  | "lost";

export type MessageDirection = "in" | "out";

export interface Message {
  id: number;
  client_id: number;
  direction: MessageDirection;
  text: string;
  drafted_reply: string | null;
  needs_hassam: boolean;
  sent_at: string | null;
  created_at: string;
}

export interface Client {
  id: number;
  name: string;
  lead_id: number | null;
  lead_title?: string | null;
  budget_discussed: string | null;
  agreed_scope: string | null;
  stage: ClientStage;
  notes: string | null;
  messages?: Message[];
  created_at: string;
  updated_at: string;
}

export interface Lead {
  id: number;
  external_id: string;
  title: string;
  full_brief: string;
  url: string | null;
  budget: string | null;
  budget_min: number | null;
  budget_max: number | null;
  client_country: string | null;
  client_spend: string | null;
  client_spend_amount: number | null;
  client_hire_rate: string | null;
  client_rating: number | null;
  client_reviews: number | null;
  payment_verified: boolean;
  proposal_count: number;
  score: number | null;
  score_reason: string | null;
  proposal_text: string | null;
  status: LeadStatus;
  client_id: number | null;
  client?: Client | null;
  posted_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface LeadsResponse {
  data: Lead[];
  meta: PaginationMeta;
}

export interface SecretSetting {
  is_set: boolean;
  masked: string | null;
}

export interface SettingsResponse {
  ai: {
    claude_api_key: SecretSetting;
    claude_model: string;
  };
  vollna: {
    vollna_webhook_secret: SecretSetting;
  };
  openclaw: {
    openclaw_url: SecretSetting;
    openclaw_token: SecretSetting;
  };
  whatsapp: {
    whatsapp_token: SecretSetting;
    whatsapp_phone_id: SecretSetting;
    bidder_whatsapp: SecretSetting;
  };
  rules: {
    min_budget: number;
    max_proposals: number;
    score_cutoff: number;
    stack_keywords: string[];
    hourly_floor: number;
    zero_history_budget_floor: number;
    red_flag_words: string[];
    followup_days: number;
  };
}

export type SettingsGroup = keyof SettingsResponse;

export interface TestConnectionResult {
  success: boolean;
  message: string;
}

export interface AnalyticsSummary {
  total_leads: number;
  by_status: Record<LeadStatus, number>;
  proposals_sent: number;
  reply_rate: number;
  win_rate: number;
  avg_score: number;
  estimated_connects_spent: number;
}

export interface AnalyticsTrendPoint {
  date: string;
  received: number;
  sent: number;
  replied: number;
  won: number;
}

export interface AnalyticsJobType {
  keyword: string;
  count: number;
  won: number;
}

export interface AnalyticsHour {
  hour: number;
  count: number;
}

export interface ActivityLogEntry {
  id: number;
  type: string;
  subject_type: string | null;
  subject_id: number | null;
  meta: Record<string, unknown> | null;
  user: string | null;
  created_at: string;
}

export interface AnalyticsResponse {
  summary: AnalyticsSummary;
  trend: AnalyticsTrendPoint[];
  best_job_types: AnalyticsJobType[];
  best_hours: AnalyticsHour[];
  recent_activity: ActivityLogEntry[];
}

export interface ApiErrorBody {
  message: string;
  errors?: Record<string, string[]>;
}

export interface SavedFilterCriteria {
  include_keywords?: string[];
  exclude_keywords?: string[];
  budget_min?: number;
  budget_max?: number;
  payment_verified_only?: boolean;
  min_client_spend?: number;
  client_countries_include?: string[];
  client_countries_exclude?: string[];
  posted_within_minutes?: number;
}

export interface SavedFilter {
  id: number;
  name: string;
  is_default: boolean;
  is_pinned: boolean;
  criteria: SavedFilterCriteria;
  created_at: string;
  updated_at: string;
}
