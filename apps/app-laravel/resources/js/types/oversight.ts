export interface OversightContact {
  name: string;
  email: string;
}

export interface OversightStep {
  key: string;
  label: string;
  due_date: string | null;
  completed_on: string | null;
}

export interface OversightDueDate {
  date: string;
  label: string;
  display_date: string;
}

export interface OversightItem {
  document_id: string;
  title: string;
  law_type: string;
  agencies: string[];
  tracked: boolean;
  status: string | null;
  closed: boolean;
  publish_due_date: string | null;
  effective_date: string | null;
  is_draft: boolean;
  due_date: string | null;
  due_label: string | null;
  due_dates: OversightDueDate[];
  days_left: number | null;
  overdue: boolean;
  in_next_month: boolean;
  next_action_date: string | null;
  next_action_label: string | null;
  primary: OversightContact;
  backup: OversightContact;
  steps: OversightStep[];
}

export interface OversightStatusOption {
  key: string;
  label: string;
}

export interface OversightSummary {
  as_of: string;
  next_month_label: string;
  reminder_days: number[];
  statuses: OversightStatusOption[];
  totals: {
    tracked: number;
    overdue_publication: number;
    on_time: number;
    late: number;
    compliance_rate: number | null;
  };
  by_status: { key: string; label: string; count: number }[];
  next_month: OversightItem[];
  items: OversightItem[];
}

export type OversightMailChannel = 'deadline' | 'proclamation';

export interface OversightMailTemplate {
  subject: string;
  body: string;
  link_label: string;
  reminder_days: number[];
  channel?: OversightMailChannel;
}

export interface OversightMailHit {
  kind?: string;
  label: string;
  date: string;
  display_date: string;
  days_left: number;
  sent_key?: string;
}

export interface OversightMailItem {
  document_id: string;
  title: string;
  emails: string[];
  channel: OversightMailChannel;
  due_now: boolean;
  already_sent: boolean;
  hits: OversightMailHit[];
}

export interface OversightMailPlaceholder {
  token: string;
  label: string;
  fields: string;
}

export interface OversightMailChannelInfo {
  key: OversightMailChannel;
  label: string;
}

export interface OversightMailSettings {
  templates: Record<OversightMailChannel, OversightMailTemplate>;
  /** @deprecated Prefer templates.deadline */
  template?: OversightMailTemplate;
  defaults: Record<OversightMailChannel, OversightMailTemplate>;
  channels: OversightMailChannelInfo[];
  placeholders: OversightMailPlaceholder[];
  reminder_days: number[];
  pending_count: number;
  items: OversightMailItem[];
}

export interface OversightMailSavePayload {
  deadline: Pick<OversightMailTemplate, 'subject' | 'body' | 'link_label'>;
  proclamation: Pick<OversightMailTemplate, 'subject' | 'body' | 'link_label'>;
  reminder_days: number[];
}

export interface OversightMailSendResult {
  sent: number;
  failed: { document_id: string; title: string; message: string }[];
  document_id?: string;
  title?: string;
  emails?: string[];
}

export interface OversightUpdatePayload {
  primary: OversightContact;
  backup: OversightContact;
}
