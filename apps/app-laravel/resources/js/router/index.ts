import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';

// Use dynamic imports (route-level code splitting)
const PublicHomePage = () => import('../pages/public/PublicHomePage.vue');
const LawDatabasePage = () => import('../pages/public/LawDatabasePage.vue');
const LoginPage = () => import('../pages/auth/LoginPage.vue');
const AdminDashboardPage = () => import('../pages/admin/AdminDashboardPage.vue');
const AdminLawListPage = () => import('../pages/admin/AdminLawListPage.vue');
const AdminUploadPage = () => import('../pages/admin/AdminUploadPage.vue');
const AdminReportPage = () => import('../pages/admin/AdminReportPage.vue');
const AdminOcrQueuePage = () => import('../pages/admin/AdminOcrQueuePage.vue');
const AdminRelationsHubPage = () => import('../pages/admin/AdminRelationsHubPage.vue');
const UploadPage = () => import('../pages/UploadPage.vue');
const ReviewPage = () => import('../pages/review/ReviewPage.vue');
const ComposePage = () => import('../pages/compose/ComposePage.vue');
const PreviewPage = () => import('../pages/preview/PreviewPage.vue');
const RagPage = () => import('../pages/rag/RagPage.vue');
const ResultPage = () => import('../pages/result/ResultPage.vue');
const LawInfoPage = () => import('../pages/law-info/LawInfoPage.vue');
const LawRelationsPage = () => import('../pages/law-relations/LawRelationsPage.vue');
const PermissionAccessPage = () => import('../pages/permissions/PermissionAccessPage.vue');
const ESignPage = () => import('../pages/esign/ESignPage.vue');
const ESignPreviewPage = () => import('../pages/esign/ESignPreviewPage.vue');
const ESignStatusPage = () => import('../pages/esign/ESignStatusPage.vue');
const LawPage = () => import('../pages/law/LawPage.vue');

const routes: RouteRecordRaw[] = [
  { path: '/', name: 'home', component: PublicHomePage, meta: { bareLayout: true } },
  { path: '/login', name: 'login', component: LoginPage, meta: { bareLayout: true } },
  { path: '/database', name: 'law-database', component: LawDatabasePage, meta: { bareLayout: true } },
  { path: '/admin', name: 'admin', component: AdminDashboardPage, meta: { bareLayout: true } },
  { path: '/admin/laws', name: 'admin-laws', component: AdminLawListPage, meta: { bareLayout: true } },
  { path: '/admin/reports', name: 'admin-reports', component: AdminReportPage, meta: { bareLayout: true } },
  { path: '/admin/upload', name: 'admin-upload', component: AdminUploadPage, meta: { bareLayout: true } },
  { path: '/admin/ocr-queue', name: 'admin-ocr-queue', component: AdminOcrQueuePage, meta: { bareLayout: true } },
  { path: '/admin/relations', name: 'admin-relations', component: AdminRelationsHubPage, meta: { bareLayout: true } },
  { path: '/upload', name: 'upload-legacy', component: UploadPage },
  { path: '/documents/:documentId/review', name: 'review', component: ReviewPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/compose', name: 'compose', component: ComposePage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/law-info', name: 'law-info', component: LawInfoPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/relations', name: 'law-relations', component: LawRelationsPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/permissions', name: 'document-permissions', component: PermissionAccessPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/esign', name: 'esign', component: ESignPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/esign/preview', name: 'esign-preview', component: ESignPreviewPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/esign/status', name: 'esign-status', component: ESignStatusPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/rag', name: 'rag', component: RagPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/result', name: 'result', component: ResultPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/preview', name: 'preview', component: PreviewPage, props: true, meta: { bareLayout: true } },
  { path: '/law/:documentId', name: 'law', component: LawPage, props: true, meta: { bareLayout: true } },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
});
