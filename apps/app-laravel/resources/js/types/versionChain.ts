export interface VersionChainItem {
  document_id: string;
  version_label: string;
  is_current: boolean;
  status: string;
  change_status: string;
  issuer: string;
  agency: string;
  promulgation_date: string;
  title: string;
}

export interface VersionChain {
  current_document_id: string;
  versions: VersionChainItem[];
}
