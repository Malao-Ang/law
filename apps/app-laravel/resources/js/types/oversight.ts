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

export interface OversightItem {
  document_id: string;
  title: string;
  law_type: string;
  agencies: string[];
  tracked: boolean;
  status: string | null;
  closed: boolean;
  publish_due_date: string | null;
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

export interface OversightUpdatePayload {
  status: string;
  publish_due_date: string | null;
  steps: { key: string; due_date: string | null; completed_on: string | null }[];
  primary: OversightContact;
  backup: OversightContact;
}
