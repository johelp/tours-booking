import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [ react() ],
  define: {
    'process.env.NODE_ENV': '"production"',
  },
  build: {
    outDir: path.resolve( __dirname, '../assets/js' ),
    emptyOutDir: false,
    rollupOptions: {
      input: {
        'booking-widget': path.resolve( __dirname, 'src/booking-widget.jsx' ),
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: '[name]-[hash].js',
        assetFileNames: 'booking-widget[extname]',
      },
    },
  },
});
