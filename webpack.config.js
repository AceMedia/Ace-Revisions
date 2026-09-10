/**
 * Extends the wp-scripts default so the block entries (from block.json) and the
 * plugin's standalone admin/front-end scripts all build into /build.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const fs = require( 'fs' );
const path = require( 'path' );

const base = Array.isArray( defaultConfig ) ? defaultConfig[ 0 ] : defaultConfig;
const defaultEntry = typeof base.entry === 'function' ? base.entry() : base.entry || {};

const extra = {};
for ( const name of [ 'admin', 'batch-editor', 'ad-editor', 'view' ] ) {
	const file = path.resolve( __dirname, `src/${ name }.js` );
	if ( fs.existsSync( file ) ) {
		extra[ name ] = file;
	}
}

module.exports = { ...base, entry: { ...defaultEntry, ...extra } };
