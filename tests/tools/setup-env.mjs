#!/usr/bin/env node
/**
 * One-step setup for the wp-env site.
 *
 * Generates a signing keypair if there isn't one, runs seed.php inside the
 * container through WP-CLI, then locks the site down so a public tunnel can
 * reach only the webhook route. The container path is derived from this
 * checkout's directory name, because wp-env mounts the project as a plugin
 * named after the folder — which is not necessarily the plugin slug.
 */

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const PROJECT_ROOT = path.resolve( HERE, '..', '..' );
const PUBLIC_KEY_PATH = path.join( HERE, '.keys', 'public.pem' );

function run( command, args ) {
	return execFileSync( command, args, {
		cwd: PROJECT_ROOT,
		stdio: 'inherit',
		shell: process.platform === 'win32',
	} );
}

if ( ! fs.existsSync( PUBLIC_KEY_PATH ) ) {
	console.log( 'No signing key yet — generating one.\n' );
	run( 'node', [ path.join( 'tests', 'tools', 'webhook.mjs' ), 'keygen' ] );
	console.log( '' );
}

const pluginDir = path.basename( PROJECT_ROOT );
const seedPath = `wp-content/plugins/${ pluginDir }/tests/tools/seed.php`;

console.log( `Seeding through ${ seedPath }\n` );

run( 'npx', [ 'wp-env', 'run', 'cli', 'wp', 'eval-file', seedPath ] );

// Always, not only on first setup: `wp-env destroy` recreates WordPress's
// .htaccess, and an unlocked site behind a tunnel is public with admin/password.
console.log( '' );
run( 'node', [ path.join( 'tests', 'tools', 'tunnel-lockdown.mjs' ) ] );
