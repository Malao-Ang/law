export type ObjectId = string;

export type DocumentVersionStatus = 'draft' | 'published' | 'scheduled' | 'repealed' | 'archived';

export type DocumentType = 'kotmai-phaainok' | 'rabiap' | 'kho-bangkhab' | 'prakat' | 'other';

export type PublicationScope = 'public' | 'private' | 'organization';

export interface DocumentVersion {
  _id: ObjectId;
  documentId: ObjectId;
  versionNo: number;
  status: DocumentVersionStatus;
  effectiveFrom?: Date;
  effectiveTo?: Date;
  isCurrent: boolean;
  metadata: {
    title: string;
    documentType: DocumentType;
    documentGroupId?: ObjectId;
    publicationScope: PublicationScope;
    summary?: string;
    announcementDate?: Date;
    effectiveDate?: Date;
    publishedDate?: Date;
    repealedDate?: Date;
    issueYear?: number;
    ownerAgencyId?: ObjectId;
    issuer?: string;
    keywords: string[];
  };
  changeSummary?: string;
  publishedBy?: ObjectId;
  publishedAt?: Date;
  supersededBy?: ObjectId;
  createdAt: Date;
  updatedAt: Date;
}
