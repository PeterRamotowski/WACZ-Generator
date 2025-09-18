import { defineConfig } from 'vite';
import symfonyPlugin from 'vite-plugin-symfony';

export default defineConfig({
  plugins: [
    symfonyPlugin(),
  ],
  base: '/build/',
  build: {
    outDir: 'public/build',
    manifest: true,
    rollupOptions: {
      input: {
        app: './assets/app.js',
      },
    },
  },
  server: {
    host: '0.0.0.0',
    port: 3000,
  },
});
