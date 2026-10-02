import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [ react() ],
  define: {
    'process.env.NODE_ENV': '"production"',
  },
  build: {
    // outDir es la carpeta assets/ común — cada patrón de salida abajo
    // decide su propia subcarpeta (Rollup no permite "../" en los patrones,
    // solo subcarpetas relativas a outDir).
    outDir: path.resolve( __dirname, '../assets' ),
    emptyOutDir: false,
    rollupOptions: {
      input: {
        'booking-widget': path.resolve( __dirname, 'src/booking-widget.jsx' ),
      },
      output: {
        entryFileNames: 'js/[name].js',
        chunkFileNames: 'js/[name]-[hash].js',
        // El plugin carga el CSS desde assets/css/ (ver class-shortcodes.php).
        assetFileNames: 'css/booking-widget[extname]',
      },
    },
  },
});
