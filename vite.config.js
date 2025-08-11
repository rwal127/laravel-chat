import { defineConfig, loadEnv } from 'vite'
import laravel from 'laravel-vite-plugin'
import fs from 'fs'

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '')
    const APP_HOST = env.VITE_APP_HOST || 'test-chat.dev' // set in .env
    const HTTPS_CERT = env.VITE_HTTPS_CERT || '' // absolute path to cert (e.g., Valet: ~/.config/valet/Certificates/test-chat.dev.crt)
    const HTTPS_KEY = env.VITE_HTTPS_KEY || ''   // absolute path to key  (e.g., Valet: ~/.config/valet/Certificates/test-chat.dev.key)

    return {
        server: {
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            https: HTTPS_CERT && HTTPS_KEY ? {
                cert: fs.readFileSync(HTTPS_CERT),
                key: fs.readFileSync(HTTPS_KEY),
            } : true, // allow system default HTTPS if available
            // Allow cross-origin requests from the app origin (and others if needed)
            cors: true,
            // Explicit origin helps set correct Access-Control-Allow-Origin
            origin: `https://${APP_HOST}:5173`,
            headers: {
                'Access-Control-Allow-Origin': `https://${APP_HOST}`,
                'Access-Control-Allow-Methods': 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
                'Access-Control-Allow-Headers': 'Content-Type, Authorization',
                'Access-Control-Allow-Credentials': 'true',
            },
            hmr: {
                host: APP_HOST,
                protocol: 'wss',
                clientPort: 5173,
            },
        },
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
            }),
        ],
    }
})
