import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import UploadPage from '../pages/UploadPage.vue';
import ReviewPage from '../pages/ReviewPage.vue';

const routes: RouteRecordRaw[] = [
  { path: '/', name: 'upload', component: UploadPage },
  { path: '/documents/:documentId/review', name: 'review', component: ReviewPage, props: true },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
});
