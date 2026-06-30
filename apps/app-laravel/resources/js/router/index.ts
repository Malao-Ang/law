import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import PublicHomePage from '../pages/public/PublicHomePage.vue';
import AdminDashboardPage from '../pages/admin/AdminDashboardPage.vue';
import AdminUploadPage from '../pages/admin/AdminUploadPage.vue';
import UploadPage from '../pages/UploadPage.vue';
import ReviewPage from '../pages/review/ReviewPage.vue';
import ComposePage from '../pages/compose/ComposePage.vue';
import PreviewPage from '../pages/preview/PreviewPage.vue';
import RagPage from '../pages/rag/RagPage.vue';
import LawPage from '../pages/law/LawPage.vue';

const routes: RouteRecordRaw[] = [
  { path: '/', name: 'home', component: PublicHomePage, meta: { bareLayout: true } },
  { path: '/admin', name: 'admin', component: AdminDashboardPage, meta: { bareLayout: true } },
  { path: '/admin/upload', name: 'admin-upload', component: AdminUploadPage, meta: { bareLayout: true } },
  { path: '/upload', name: 'upload-legacy', component: UploadPage },
  { path: '/documents/:documentId/review', name: 'review', component: ReviewPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/compose', name: 'compose', component: ComposePage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/rag', name: 'rag', component: RagPage, props: true, meta: { bareLayout: true } },
  { path: '/documents/:documentId/preview', name: 'preview', component: PreviewPage, props: true, meta: { bareLayout: true } },
  { path: '/law/:documentId', name: 'law', component: LawPage, props: true, meta: { bareLayout: true } },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
});
