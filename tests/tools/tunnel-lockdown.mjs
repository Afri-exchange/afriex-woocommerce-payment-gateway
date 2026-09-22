#!/usr/bin/env node
/**
 * Adds a rule to the wp-env site's .htaccess so that, from anywhere but this
 * machine (i.e. through a public tunnel), only the Afriex webhook route is
 * reachable. Everything else returns 404.
 *
 * tests/mu-plugins/00-tunnel.php does the same for requests that reach PHP,
 * but Apache serves static files without running PHP at all: without this
 * rule the test signing key, .git and the source are downloadable through the
 * tunnel.
 *
 * The .htaccess belongs to wp-env's copy of WordPress, outside the repo, and
 * is recreated on `wp-env destroy`; `npm run env:setup` re-applies this.
 * The rule is written from inside the running WordPress container, where the
 * path is always /var/www/html/.htaccess, whatever the host-side layout. It
 * runs as the file's owner so WordPress's own permalink flushes can still
 * rewrite the file afterwards. Idempotent: re-running replaces the block.
 */

import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
const PROJECT = path.basename( ROOT ).toLowerCase();

// Must sit ABOVE the WordPress block: its "RewriteRule ^index\.php$ - [L]"
// would otherwise end processing on the internal index.php pass, and pretty
// URLs like /wp-admin/ or /some-page would slip through.
//
// The REDIRECT_STATUS condition limits the rule to the ORIGINAL request.
// WordPress internally rewrites /wp-json/... to /index.php, and on that second
// pass REQUEST_URI reads /index.php, so without it the webhook exemption stops
// matching and the webhook itself gets a 404.
const BLOCK = [
	'# BEGIN Afriex dev tunnel lockdown',
	'# Only requests from this machine reach the site. From anywhere else (a',
	'# public tunnel), the Afriex webhook route is the single exception.',
	'<IfModule mod_rewrite.c>',
	'RewriteEngine On',
	'RewriteCond %{ENV:REDIRECT_STATUS} ^$',
	'RewriteCond %{HTTP_HOST} !^(localhost|127\\.0\\.0\\.1)(:8888)?$ [NC]',
	'RewriteCond %{REQUEST_URI} !^/wp-json/afriex/v1/webhook/?$',
	'RewriteRule ^ - [R=404,L]',
	'</IfModule>',
	'# END Afriex dev tunnel lockdown',
	'',
].join( '\n' );

const docker = ( args, input ) =>
	execFileSync( 'docker', args, { encoding: 'utf8', input, stdio: [ input === undefined ? 'ignore' : 'pipe', 'pipe', 'pipe' ] } ).trim();

const container = docker( [ 'ps', '--format', '{{.Names}}' ] )
	.split( /\r?\n/ )
	.find( ( n ) => n.startsWith( `wp-env-${ PROJECT }-` ) && n.endsWith( '-wordpress-1' ) );

if ( ! container ) {
	console.error( `No running wp-env WordPress container for "${ PROJECT }". Run npm run env:start first.` );
	process.exit( 1 );
}

const owner = docker( [ 'exec', container, 'stat', '-c', '%u:%g', '/var/www/html' ] );

const php = `<?php
$file  = '/var/www/html/.htaccess';
$block = base64_decode( '${ Buffer.from( BLOCK ).toString( 'base64' ) }' );
$rest  = file_exists( $file ) ? (string) file_get_contents( $file ) : '';
$rest  = preg_replace( '/# BEGIN Afriex dev tunnel lockdown.*?# END Afriex dev tunnel lockdown\\R?/s', '', $rest );
if ( false === file_put_contents( $file, $block . ltrim( $rest ) ) ) { fwrite( STDERR, "could not write $file\\n" ); exit( 1 ); }
echo "ok";
`;

const result = docker( [ 'exec', '-i', '-u', owner, container, 'php' ], php );

if ( 'ok' !== result ) {
	console.error( `Lockdown failed in ${ container }: ${ result }` );
	process.exit( 1 );
}

console.log( `Tunnel lockdown applied to /var/www/html/.htaccess in ${ container }` );
