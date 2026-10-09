import { createApp } from 'vue';
import App from './App.vue';

// Self-hosted fonts (incl. Cyrillic) so canvas exports never depend on a CDN.
import '@fontsource/inter/400.css';
import '@fontsource/inter/500.css';
import '@fontsource/inter/600.css';
import '@fontsource/inter/700.css';
import '@fontsource/inter/800.css';
import '@fontsource/space-grotesk/500.css';
import '@fontsource/space-grotesk/700.css';
import '@fontsource/montserrat/500.css';
import '@fontsource/montserrat/700.css';
import '@fontsource/montserrat/900.css';
import '@fontsource/playfair-display/700.css';
import '@fontsource/comfortaa/500.css';
import '@fontsource/comfortaa/700.css';

createApp(App).mount('#app');
