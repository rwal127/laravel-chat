import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

// Import new chat component
import chatApp from './chat/index.js';
window.chatApp = chatApp;

Alpine.start();
