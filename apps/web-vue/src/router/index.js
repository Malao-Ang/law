import { createRouter, createWebHistory } from 'vue-router';
import Home from '../views/Home.vue';
import Editor from '../views/Editor.vue';
import RegulationList from '../views/RegulationList.vue';
import RegulationView from '../views/RegulationView.vue';

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
