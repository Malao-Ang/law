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
  source_type: string;   // e.g. 'pdf', 'docx', 'pdf_scan'
  has_file: boolean;     // true when the physical file exists on storage
  source_file: string;   // original filename for download naming
}

export interface VersionChain {
  current_document_id: string;
  versions: VersionChainItem[];
}
