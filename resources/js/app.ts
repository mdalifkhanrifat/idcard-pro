import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { router } from './router';
import { i18n } from './lib/i18n';
import { onUnauthorized } from './lib/http';
import { useAuthStore } from './stores/auth';

const app = createApp(App);
app.use(createPinia());
app.use(i18n);
app.use(router);

onUnauthorized(() => {
    const auth = useAuthStore();
    if (auth.isAuthenticated) {
        auth.clear();
        router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } });
    }
});

app.mount('#app');
