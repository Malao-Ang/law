import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import PublicHomePage from '../pages/public/PublicHomePage.vue';
import LawDatabasePage from '../pages/public/LawDatabasePage.vue';
import AdminDashboardPage from '../pages/admin/AdminDashboardPage.vue';
import AdminLawListPage from '../pages/admin/AdminLawListPage.vue';
import AdminUploadPage from '../pages/admin/AdminUploadPage.vue';
import AdminReportPage from '../pages/admin/AdminReportPage.vue';
import UploadPage from '../pages/UploadPage.vue';
import ReviewPage from '../pages/review/ReviewPage.vue';
import ComposePage from '../pages/compose/ComposePage.vue';
import PreviewPage from '../pages/preview/PreviewPage.vue';
import RagPage from '../pages/rag/RagPage.vue';
import LawInfoPage from '../pages/law-info/LawInfoPage.vue';
import LawRelationsPage from '../pages/law-relations/LawRelationsPage.vue';
import PermissionAccessPage from '../pages/permissions/PermissionAccessPage.vue';
import LawPage from '../pages/law/LawPage.vue';

const routes: RouteRecordRaw[] = [
  { path: '/', name: 'home', component: PublicHomePage, meta: { bareLayout: true } },
  { path: '/database', name: 'law-database', component: LawDatabasePage, meta: { bareLayout: true } },
  { path: '/admin', name: 'admin', component: AdminDashboardPage, meta: { bareLayout: true } },
  { path: '/admin/laws', name: 'admin-laws', component: AdminLawListPage, meta: { bareLayout: true } },
  { path: '/admin/reports', name: 'admin-reports', component: AdminReportPage, meta: { bareLayout: true } },
  { path: '/admin/upload', name: 'admin-upload', component: AdminUploadPage, meta: { bareLayout: true } },
  { path: '/upload', name: 'upload-legacy', component: UploadPage },
  { path: '/documents/:documentId/review', name: 'review', component: ReviewPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/compose', name: 'compose', component: ComposePage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/law-info', name: 'law-info', component: LawInfoPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/relations', name: 'law-relations', component: LawRelationsPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/permissions', name: 'document-permissions', component: PermissionAccessPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/rag', name: 'rag', component: RagPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/preview', name: 'preview', component: PreviewPage, props: true, meta: { bareLayout: true } },
  { path: '/law/:documentId', name: 'law', component: LawPage, props: true, meta: { bareLayout: true } },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
});
