import { fetchPreview, fetchReview } from '../api/client';
import type { PreviewData, ReviewDocument } from '../types/document';

const reviewCache = new Map<string, ReviewDocument>();
const reviewInFlight = new Map<string, { version: number; promise: Promise<ReviewDocument> }>();
const reviewVersion = new Map<string, number>();
const previewCache = new Map<string, PreviewData>();
const previewInFlight = new Map<string, { version: number; promise: Promise<PreviewData> }>();
const previewVersion = new Map<string, number>();

function currentVersion(versions: Map<string, number>, documentId: string): number {
  return versions.get(documentId) ?? 0;
}

function bumpVersion(versions: Map<string, number>, documentId: string): number {
  const next = currentVersion(versions, documentId) + 1;
  versions.set(documentId, next);

  return next;
}

export async function getReviewCached(documentId: string, force = false): Promise<ReviewDocument> {
  if (force) {
    reviewCache.delete(documentId);
    bumpVersion(reviewVersion, documentId);
  } else {
    const cached = reviewCache.get(documentId);
    if (cached) {
      return cached;
    }
  }

  const inFlight = reviewInFlight.get(documentId);
  const version = currentVersion(reviewVersion, documentId);
  if (inFlight && inFlight.version === version) {
    return inFlight.promise;
  }

  const pending = fetchReview(documentId)
    .then((review) => {
      if (currentVersion(reviewVersion, documentId) === version) {
        reviewCache.set(documentId, review);
      }
      if (reviewInFlight.get(documentId)?.version === version) {
        reviewInFlight.delete(documentId);
      }

      return review;
    })
    .catch((error: unknown) => {
      if (reviewInFlight.get(documentId)?.version === version) {
        reviewInFlight.delete(documentId);
      }
      throw error;
    });

  reviewInFlight.set(documentId, { version, promise: pending });

  return pending;
}

export async function getPreviewCached(documentId: string, force = false): Promise<PreviewData> {
  if (force) {
    previewCache.delete(documentId);
    bumpVersion(previewVersion, documentId);
  } else {
    const cached = previewCache.get(documentId);
    if (cached) {
      return cached;
    }
  }

  const inFlight = previewInFlight.get(documentId);
  const version = currentVersion(previewVersion, documentId);
  if (inFlight && inFlight.version === version) {
    return inFlight.promise;
  }

  const pending = fetchPreview(documentId)
    .then((preview) => {
      if (currentVersion(previewVersion, documentId) === version) {
        previewCache.set(documentId, preview);
      }
      if (previewInFlight.get(documentId)?.version === version) {
        previewInFlight.delete(documentId);
      }

      return preview;
    })
    .catch((error: unknown) => {
      if (previewInFlight.get(documentId)?.version === version) {
        previewInFlight.delete(documentId);
      }
      throw error;
    });

  previewInFlight.set(documentId, { version, promise: pending });

  return pending;
}

export function setReview(documentId: string, review: ReviewDocument): void {
  reviewCache.set(documentId, review);
  previewCache.delete(documentId);
  bumpVersion(previewVersion, documentId);
  previewInFlight.delete(documentId);
}

export function invalidateReview(documentId: string): void {
  reviewCache.delete(documentId);
  bumpVersion(reviewVersion, documentId);
  reviewInFlight.delete(documentId);
  previewCache.delete(documentId);
  bumpVersion(previewVersion, documentId);
  previewInFlight.delete(documentId);
}
