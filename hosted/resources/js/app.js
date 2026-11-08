import { createApp } from 'vue';
import App from './components/App.vue';
import '../css/app.css';
if (document.getElementById('app')) createApp(App).mount('#app');
