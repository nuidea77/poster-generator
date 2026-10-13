// SPA-ийн эхлэх цэг: Vue апп үүсгэж, router холбоод #app руу mount хийнэ.
import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';

// UI-д ашиглах фонтууд (Inter, Space Grotesk) — локал багцаас ачаална.
import '@fontsource/inter/400.css';
import '@fontsource/inter/500.css';
import '@fontsource/inter/600.css';
import '@fontsource/inter/700.css';
import '@fontsource/space-grotesk/500.css';
import '@fontsource/space-grotesk/700.css';

// Аппыг router-тэй нь хамт эхлүүлнэ.
createApp(App).use(router).mount('#app');
