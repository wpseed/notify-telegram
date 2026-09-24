import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

/**
 * The React sources live in admin-ui/ and are built into assets/admin/.
 *
 * WordPress serves files from the plugin directory directly, so there is no asset-publishing step
 * to run after a build (unlike a CMS that compiles theme assets) — the PHP side only reads the
 * generated manifest to know which hashed file to enqueue.
 */
export default defineConfig( {
	plugins: [ react() ],

	// Relative asset URLs: the bundle is served from the plugin directory, not from the site root.
	base: './',

	build: {
		outDir: 'assets/admin',
		emptyOutDir: true,

		// Flat output: assets/admin/main-<hash>.js instead of a second nested assets/ level.
		assetsDir: '',

		// The manifest lands at assets/<dir>/manifest.json, at the top of the output directory and without
		// a dot in its name. Vite's own default is assets/<dir>/.vite/manifest.json, and PHP-Scoper —
		// which the archive build runs the tree through — collects its files with Symfony Finder, whose
		// default is to skip dot-files: the manifest disappeared from the shipped plugin silently.
		manifest: 'manifest.json',

		rollupOptions: {
			input: 'admin-ui/main.jsx',

			// wp_enqueue_script() prints a classic <script>, which cannot parse ES module syntax, so
			// the bundle has to be an IIFE. (The alternative is wp_enqueue_script_module(), i.e. one
			// more WordPress version to depend on.)
			output: {
				format: 'iife',
				name: 'NotifyTelegramAdmin',
			},
		},
	},
} );
