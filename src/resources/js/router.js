import { createRouter, createWebHistory } from 'vue-router';
import Home from './pages/Home.vue';
import Editor from './pages/Editor.vue';
import RegulationList from './pages/RegulationList.vue';
import RegulationView from './pages/RegulationView.vue';

const routes = [
    {
        path: '/',
        name: 'home',
        component: Home
    },
    {
        path: '/editor',
        name: 'editor',
        component: Editor
    },
    {
        path: '/regulations',
        name: 'regulations',
        component: RegulationList
    },
    {
        path: '/regulations/:id',
        name: 'regulation-view',
        component: RegulationView
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

export default router;
