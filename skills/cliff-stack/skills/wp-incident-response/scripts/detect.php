<?php
/*
 * detect.php (skill wp-incident-response) : détecteur d'incident WordPress en LECTURE SEULE.
 * Principe : tout ce qu'aucune référence de confiance n'explique est signalé ; la malveillance
 * se juge sur les capacités (entrée non fiable qui atteint un puits), jamais sur une signature.
 * Ne charge, n'inclut ni n'exécute AUCUN fichier du site : jetons PHP, SQL direct ou dump .sql.
 * Versions PHP supportées par WordPress, sans dépendance. Aide : php detect.php --help
 *
 * Listes de référence embarquées et leur source (aucune liste de produits tiers) :
 * - fichiers racine, types et statuts de contenu, options, tables, hooks de mise à jour, drop-ins :
 *   code source de WordPress (wp-includes/post.php, wp-admin/includes/schema.php,
 *   wp-admin/includes/plugin.php _get_dropins(), wp-admin/includes/class-wp-automatic-updater.php) ;
 * - fonctions d'exécution PHP : manuel PHP (Program execution, eval, callbacks) ;
 * - domaines réservés : RFC 2606, RFC 6761, RFC 6762 ;
 * - agent d'indexation pour le test de cloaking : Google Search Central, « Google common crawlers ».
 */

const WD_VERSION = '1.0.0';

const WD_CONF  = 'CONFIRMÉ';
const WD_PISTE = 'PISTE';
const WD_DURC  = 'DURCISSEMENT';
const WD_HUMAN = 'NEEDS_HUMAN';
const WD_ILL   = 'ILLISIBLE';

const WD_CORE_ROOT_FILES = [ 'index.php', 'license.txt', 'readme.html', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php', 'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php' ];
const WD_DROPINS = [ 'advanced-cache.php', 'db.php', 'db-error.php', 'install.php', 'maintenance.php', 'object-cache.php', 'php-error.php', 'fatal-error-handler.php', 'sunrise.php', 'blog-deleted.php', 'blog-inactive.php', 'blog-suspended.php' ];
const WD_CORE_TABLES = [ 'options', 'users', 'usermeta', 'posts', 'postmeta', 'comments', 'commentmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'links', 'blogs', 'blogmeta', 'site', 'sitemeta', 'signups', 'registration_log' ];
const WD_CORE_POST_TYPES = [ 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' ];
const WD_CORE_STATUSES = [ 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit', 'request-pending', 'request-confirmed', 'request-failed', 'request-completed' ];
const WD_UPDATE_HOOKS = '/^(auto_update_(core|plugin|theme|translation)|automatic_updater_disabled|allow_(major|minor|dev)_auto_core_updates|file_mod_allowed|pre_site_transient_update_(core|plugins|themes)|pre_http_request|http_request_host_is_external|auto_core_update_send_email)$/';
const WD_UPDATE_CONSTS = [ 'AUTOMATIC_UPDATER_DISABLED', 'WP_AUTO_UPDATE_CORE', 'DISALLOW_FILE_MODS', 'FS_METHOD', 'WP_HTTP_BLOCK_EXTERNAL', 'DISALLOW_FILE_EDIT', 'WP_ACCESSIBLE_HOSTS' ];
const WD_SENSITIVE_OPTIONS = [ 'siteurl', 'home', 'active_plugins', 'users_can_register', 'default_role', 'template', 'stylesheet', 'admin_email', 'upload_path', 'upload_url_path' ];
const WD_RESERVED_DOMAIN = '/(^|\.)(test|example|invalid|localhost|local)$|^example\.(com|net|org)$/i';
// Dossiers de fichiers déposés ou de médias, désignés par leur nom générique : un projet PHP n'y attend pas de code.
const WD_DATA_DIRS = '#(^|/)(uploads?|medias?|images?|img|photos?|pictures|files|fichiers|documents?|downloads?)/#';
const WD_PHP_EXT ='/\.(php\d?|phtml|pht|phar|phps|inc|module)$/i';
const WD_CRAWLER_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
const WD_BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
const WD_SEARCH_REFERER = 'https://www.google.com/';

// Puits par capacité (manuel PHP). Indice de l'argument qui porte la donnée dangereuse.
const WD_SINKS = [
	'exec_code' => [ 'assert' => 0, 'create_function' => 1 ],
	'exec_cmd'  => [ 'system' => 0, 'exec' => 0, 'shell_exec' => 0, 'passthru' => 0, 'popen' => 0, 'proc_open' => 0, 'pcntl_exec' => 0, 'expect_popen' => 0 ],
	'callback'  => [ 'call_user_func' => 0, 'call_user_func_array' => 0, 'forward_static_call' => 0, 'forward_static_call_array' => 0, 'array_map' => 0, 'array_filter' => 1, 'array_walk' => 1, 'array_walk_recursive' => 1, 'array_reduce' => 1, 'usort' => 1, 'uasort' => 1, 'uksort' => 1, 'register_shutdown_function' => 0, 'register_tick_function' => 0, 'set_error_handler' => 0, 'set_exception_handler' => 0, 'ob_start' => 0, 'iterator_apply' => 1, 'preg_replace_callback' => 1, 'spl_autoload_register' => 0 ],
	'file_write' => [ 'file_put_contents' => 1, 'fwrite' => 1, 'fputs' => 1, 'move_uploaded_file' => 1, 'copy' => 0, 'symlink' => 0 ],
	'net_out'   => [ 'curl_init' => 0, 'curl_exec' => 0, 'fsockopen' => 0, 'pfsockopen' => 0, 'stream_socket_client' => 0, 'wp_remote_get' => 0, 'wp_remote_post' => 0, 'wp_remote_request' => 0, 'wp_safe_remote_get' => 0, 'wp_safe_remote_post' => 0, 'download_url' => 0 ],
	'mail'      => [ 'mail' => 0, 'wp_mail' => 0 ],
	'user_create' => [ 'wp_create_user' => 0, 'wp_insert_user' => 0, 'wp_update_user' => 0, 'add_role' => 0 ],
	'auth'      => [ 'wp_set_auth_cookie' => 0, 'wp_set_current_user' => 0, 'wp_signon' => 0 ],
	'option_write' => [ 'update_option' => 1, 'add_option' => 1, 'update_site_option' => 1 ],
	'timestomp' => [ 'touch' => 1 ],
	'rename'    => [ 'rename' => 1, 'link' => 1 ],
];
const WD_DECODERS = [ 'base64_decode', 'gzinflate', 'gzuncompress', 'gzdecode', 'str_rot13', 'strrev', 'hex2bin', 'convert_uudecode', 'urldecode', 'rawurldecode', 'pack', 'chr', 'html_entity_decode', 'htmlspecialchars_decode' ];
const WD_PURE = [ 'base64_decode', 'gzinflate', 'gzuncompress', 'gzdecode', 'str_rot13', 'strrev', 'hex2bin', 'convert_uudecode', 'urldecode', 'rawurldecode', 'pack', 'chr', 'strtolower', 'strtoupper', 'trim', 'ltrim', 'rtrim', 'str_replace', 'substr', 'strval', 'ucfirst', 'lcfirst', 'html_entity_decode', 'htmlspecialchars_decode', 'implode', 'join' ];
// Assainisseurs qui neutralisent l'entrée pour TOUS les puits (sortie numérique, hexadécimale, slug,
// ou échappement shell). Volontairement SANS esc_html/esc_attr/esc_url/basename : ils protègent le HTML
// ou un chemin, mais system(esc_attr($_GET['c'])) reste un webshell. Un assainisseur trop généreux crée
// des faux négatifs ; on préfère la prudence (le puits reste signalé si le doute demeure).
const WD_SANITIZERS = [ 'intval', 'absint', 'floatval', 'boolval', 'is_numeric', 'is_string', 'is_array', 'is_bool', 'is_int', 'count', 'strlen', 'sanitize_key', 'sanitize_title', 'sanitize_html_class', 'md5', 'sha1', 'hash', 'crc32', 'hash_equals', 'in_array', 'array_key_exists', 'preg_match', 'wp_verify_nonce', 'check_admin_referer', 'current_user_can', 'ctype_digit', 'ctype_alnum', 'escapeshellarg', 'escapeshellcmd' ];
const WD_REQ_FUNCS = [ 'getallheaders', 'apache_request_headers', 'filter_input', 'filter_input_array' ];
const WD_DB_FUNCS = [ 'get_option', 'get_site_option', 'get_transient', 'get_site_transient', 'get_theme_mod', 'get_theme_mods', 'get_post_meta', 'get_user_meta', 'get_term_meta', 'get_metadata', 'get_post_field', 'get_user_option' ];
const WD_DB_METHODS = [ 'get_var', 'get_row', 'get_results', 'get_col' ];
const WD_REMOTE_FUNCS = [ 'curl_exec', 'curl_multi_getcontent', 'wp_remote_retrieve_body', 'stream_get_contents' ];

// ============================================================ Rapport

final class WdReport {
	public $constats = [];
	public $erreurs = [];
	public $faites = [];
	public $non_faites = [];
	public $contexte = [];
	public $stats = [];
	public $evenements = [];
	public $incomplet = [];
	private $index = [];
	private $where = 'init';

	public function add( string $statut, string $code, string $cible, string $titre, array $preuves = [], string $action = '', array $extra = [] ): void {
		$key = $statut . '|' . $code . '|' . $cible . '|' . $titre;
		if ( isset( $this->index[ $key ] ) ) {
			$i = $this->index[ $key ];
			$this->constats[ $i ]['preuves'] = array_values( array_unique( array_merge( $this->constats[ $i ]['preuves'], array_map( 'wd_clean', $preuves ) ) ) );
			return;
		}
		$this->index[ $key ] = count( $this->constats );
		$this->constats[]    = [ 'statut' => $statut, 'code' => $code, 'cible' => $cible, 'titre' => $titre, 'preuves' => array_values( array_map( 'wd_clean', $preuves ) ), 'action' => $action ] + $extra;
	}

	public function done( string $check ): void {
		$this->faites[ $check ] = true;
	}

	/** Une vérification non faite n'est jamais silencieuse : elle devient un constat. */
	public function notDone( string $check, string $why, string $statut = WD_ILL ): void {
		$this->non_faites[] = [ 'verification' => $check, 'raison' => $why, 'statut' => $statut ];
		$this->add( $statut, 'verif.non_faite', $check, 'Vérification non faite : ' . $why );
	}

	public function error( string $msg ): void {
		if ( count( $this->erreurs ) < 500 ) {
			$this->erreurs[] = '[' . $this->where . '] ' . $msg;
		} elseif ( count( $this->erreurs ) === 500 ) {
			$this->erreurs[] = '[...] erreurs suivantes tronquées';
		}
	}

	public function at( string $where ): void {
		$this->where = $where;
	}

	public function event( string $date, string $kind, string $what, bool $strong ): void {
		if ( $date !== '' && $date !== '0000-00-00 00:00:00' ) {
			$this->evenements[] = [ 'date' => $date, 'type' => $kind, 'quoi' => wd_clean( $what ), 'fort' => $strong ];
		}
	}
}

// ============================================================ Utilitaires

function wd_has( string $h, string $n ): bool {
	return $n === '' || strpos( $h, $n ) !== false;
}
function wd_starts( string $h, string $n ): bool {
	return strncmp( $h, $n, strlen( $n ) ) === 0;
}
function wd_ends( string $h, string $n ): bool {
	return $n === '' || substr( $h, -strlen( $n ) ) === $n;
}
function wd_norm( string $p ): string {
	return rtrim( str_replace( '\\', '/', $p ), '/' );
}
function wd_clean( $s ): string {
	$s = wd_redact( (string) $s );
	$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '·', $s );
	return strlen( $s ) > 400 ? substr( $s, 0, 400 ) . '…' : $s;
}
/* Masque les secrets qui pourraient transiter dans une preuve (crontab, arguments de processus,
 * règles serveur, valeur décodée) : mots de passe en ligne de commande, clés/jetons en clé=valeur,
 * paires user:pass d'URL, blocs de clés PEM. Aucun secret ne doit apparaître dans le rapport. */
function wd_redact( string $s ): string {
	$s = preg_replace( '/(-p|--password[=\s]|--dbpass[=\s]|--pass[=\s])(?!\s|$)\S+/i', '$1«masqué»', $s );
	$s = preg_replace( '/\b(pass(word|wd)?|pwd|secret|api[_-]?key|apikey|token|auth|access[_-]?key|private[_-]?key|db[_-]?pass)\b(\s*[:=]\s*|\s+)([^\s&"\'<>]{3,})/i', '$1$3«masqué»', $s );
	$s = preg_replace( '#([A-Za-z][A-Za-z0-9+.\-]*://[^/:\s@]+):[^/@\s]+@#', '$1:«masqué»@', $s );
	$s = preg_replace( '/-----BEGIN [A-Z ]*(PRIVATE KEY|CERTIFICATE|RSA[^-]*)-----.*?-----END [^-]*-----/s', '«bloc de clé masqué»', $s );
	$s = preg_replace( '/\bSetEnv(If)?\s+(\S*(pass|secret|key|token)\S*)\s+\S+/i', 'SetEnv $2 «masqué»', $s );
	return $s;
}
function wd_entropy( string $s ): float {
	$len = strlen( $s );
	if ( $len === 0 ) {
		return 0.0;
	}
	$h = 0.0;
	foreach ( count_chars( $s, 1 ) as $c ) {
		$p  = $c / $len;
		$h -= $p * log( $p, 2 );
	}
	return $h;
}
function wd_printable( string $s ): bool {
	if ( $s === '' ) {
		return false;
	}
	$ctrl = preg_match_all( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $s );
	if ( preg_match( '//u', $s ) ) {
		return $ctrl / strlen( $s ) <= 0.02;
	}
	$bin = preg_match_all( '/[^\x09\x0A\x0D\x20-\x7E]/', $s );
	return $bin / strlen( $s ) <= 0.05;
}
/** Aucune empreinte : un hachage court d'un secret faible se retrouve par dictionnaire. */
function wd_mask( string $v ): string {
	return 'présent (' . strlen( $v ) . ' octets, valeur masquée)';
}
/** Exécute une sonde dont l'échec est un résultat attendu (ex. gzinflate sur une donnée non compressée) : les avertissements sont rendus à l'appelant, pas perdus. */
function wd_probe( callable $fn, &$warnings = null ) {
	$warnings = [];
	set_error_handler(
		function ( $no, $str ) use ( &$warnings ) {
			$warnings[] = $str;
			return true;
		}
	);
	try {
		return $fn();
	} catch ( Throwable $e ) {
		$warnings[] = $e->getMessage();
		return null;
	} finally {
		restore_error_handler();
	}
}
function wd_unserialize( string $v ) {
	if ( ! preg_match( '/^(a|O|s|i|d|b|N|C):/', $v ) ) {
		return null;
	}
	if ( $v === 'b:0;' ) {
		return false;
	}
	$r = wd_probe( fn() => unserialize( $v, [ 'allowed_classes' => false ] ) );
	return $r === false ? null : $r;
}
function wd_strings( $v, array &$out, int $depth = 0 ): void {
	if ( $depth > 8 || count( $out ) > 2000 ) {
		return;
	}
	if ( is_string( $v ) ) {
		$u = wd_unserialize( $v );
		if ( $u !== null && $u !== false ) {
			wd_strings( $u, $out, $depth + 1 );
		} else {
			$out[] = $v;
		}
	} elseif ( is_array( $v ) || is_object( $v ) ) {
		foreach ( (array) $v as $k => $x ) {
			if ( is_string( $k ) && strlen( $k ) > 20 ) {
				$out[] = $k;
			}
			wd_strings( $x, $out, $depth + 1 );
		}
	}
}
function wd_levenshtein( string $a, string $b ): int {
	return levenshtein( substr( strtolower( $a ), 0, 255 ), substr( strtolower( $b ), 0, 255 ) );
}
function wd_host( string $url ): string {
	$h = parse_url( trim( $url ), PHP_URL_HOST );
	return is_string( $h ) ? strtolower( preg_replace( '/^www\./i', '', $h ) ) : '';
}
function wd_shell_ok(): bool {
	$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
	return function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', $disabled, true );
}
function wd_read( WdReport $R, string $path, int $max = 0 ): ?string {
	$h = fopen( $path, 'rb' );
	if ( $h === false ) {
		return null;
	}
	$data = $max > 0 ? fread( $h, $max ) : stream_get_contents( $h );
	fclose( $h );
	return $data === false ? null : $data;
}
function wd_sniff( WdReport $R, string $path, int $size ): ?string {
	if ( $size <= 4194304 ) {
		return wd_read( $R, $path );
	}
	$h = fopen( $path, 'rb' );
	if ( $h === false ) {
		return null;
	}
	$head = fread( $h, 65536 );
	fseek( $h, -16384, SEEK_END );
	$tail = fread( $h, 16384 );
	fclose( $h );
	return $head . "\n" . $tail;
}

// ============================================================ Décodage générique (jusqu'au point fixe)

function wd_gz( string $s ): ?array {
	foreach ( [ 'gzinflate', 'gzuncompress', 'gzdecode' ] as $f ) {
		$r = wd_probe( fn() => $f( $s, 4194304 ) );
		if ( is_string( $r ) && $r !== '' ) {
			return [ $f, $r ];
		}
	}
	return null;
}
function wd_code_score( string $s ): int {
	return preg_match_all( '/\b(function|return|echo|eval|base64_decode|isset|array|if|foreach|preg_replace|str_replace|shell_exec|system|exec|\$_(GET|POST|REQUEST|COOKIE|SERVER))\b|<\?php/i', $s );
}
/** Retire les couches d'encodage l'une après l'autre ; s'arrête au point fixe. Retourne [schémas, texte final]. */
function wd_decode_layers( string $s, int $max = 10 ): array {
	$schemes = [];
	$cur     = $s;
	for ( $k = 0; $k < $max; $k++ ) {
		$t    = trim( $cur );
		$next = null;
		$sch  = null;
		$b64  = preg_replace( '/\s+/', '', $t );
		if ( strlen( $b64 ) >= 16 && preg_match( '/^[A-Za-z0-9+\/_-]+={0,2}$/', $b64 ) && ! preg_match( '/^[0-9a-f]+$/i', $b64 ) ) {
			$d = base64_decode( strtr( $b64, '-_', '+/' ), true );
			if ( is_string( $d ) && $d !== '' && ( wd_printable( $d ) || wd_gz( $d ) !== null ) ) {
				$next = $d;
				$sch  = 'base64';
			}
		}
		if ( $next === null && ! wd_printable( $t ) ) {
			$g = wd_gz( $cur );
			if ( $g !== null ) {
				[ $sch, $next ] = $g;
			}
		}
		if ( $next === null && preg_match( '/^([0-9a-fA-F]{2}){8,}$/', $t ) ) {
			$d = hex2bin( $t );
			if ( is_string( $d ) && wd_printable( $d ) ) {
				$next = $d;
				$sch  = 'hex';
			}
		}
		if ( $next === null && preg_match_all( '/\\\\x[0-9a-fA-F]{2}/', $t ) * 4 > strlen( $t ) * 0.5 ) {
			$next = stripcslashes( $t );
			$sch  = 'echappements_hex';
		}
		if ( $next === null && preg_match_all( '/%[0-9a-fA-F]{2}/', $t ) * 3 > strlen( $t ) * 0.3 ) {
			$next = rawurldecode( $t );
			$sch  = 'urlencode';
		}
		if ( $next === null && wd_code_score( str_rot13( $t ) ) > wd_code_score( $t ) + 1 ) {
			$next = str_rot13( $t );
			$sch  = 'rot13';
		}
		if ( $next === null && strlen( $b64 ) >= 16 && preg_match( '/^={0,2}[A-Za-z0-9+\/]+$/', $b64 ) ) {
			$d = base64_decode( strrev( $b64 ), true );
			if ( is_string( $d ) && $d !== '' && ( wd_printable( $d ) || wd_gz( $d ) !== null ) ) {
				$next = $d;
				$sch  = 'strrev+base64';
			}
		}
		if ( $next === null || $next === $cur ) {
			break;
		}
		$schemes[] = $sch;
		$cur       = $next;
	}
	return [ $schemes, $cur ];
}

// ============================================================ Analyse PHP par jetons (capacités et flux)

final class WdPhp {
	public $flows    = [];
	public $caps     = [];
	public $decoded  = [];
	public $urls     = [];
	public $markers  = [];
	public $hooks    = [];
	public $types    = [];
	public $literals = [];
	public $updateFilters = [];
	public $defines  = [];
	public $dbExec   = [];
	public $remoteOut = false;
	public $netDecoded = false;
	public $empty    = false;
	public $pureData = false;
	public $calls    = [];
	public $indexViolation = false;
}

function wd_tokens( string $code ): array {
	$raw = wd_probe( fn() => token_get_all( $code ) );
	$out = [];
	if ( ! is_array( $raw ) ) {
		return $out;
	}
	$line = 1;
	foreach ( $raw as $t ) {
		if ( is_array( $t ) ) {
			[ $id, $text, $line ] = $t;
			if ( $id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_OPEN_TAG || $id === T_NS_SEPARATOR ) {
				continue;
			}
			if ( $id === T_OPEN_TAG_WITH_ECHO ) {
				$out[] = [ T_ECHO, 'echo', $line ];
				continue;
			}
			if ( $id === T_CLOSE_TAG ) {
				$out[] = [ ';', ';', $line ];
				continue;
			}
			if ( ( defined( 'T_NAME_FULLY_QUALIFIED' ) && $id === T_NAME_FULLY_QUALIFIED ) || ( defined( 'T_NAME_QUALIFIED' ) && $id === T_NAME_QUALIFIED ) || ( defined( 'T_NAME_RELATIVE' ) && $id === T_NAME_RELATIVE ) ) {
				$parts = explode( '\\', $text );
				$id    = T_STRING;
				$text  = (string) end( $parts );
			}
			$out[] = [ $id, $text, $line ];
		} elseif ( $t !== '@' ) {
			$out[] = [ $t, $t, $line ];
		}
	}
	return $out;
}
function wd_unquote( string $lit ): ?string {
	if ( $lit === '' ) {
		return null;
	}
	$q = $lit[0];
	$b = substr( $lit, 1, -1 );
	if ( $q === "'" ) {
		return strtr( $b, [ '\\\\' => '\\', "\\'" => "'" ] );
	}
	if ( $q === '"' ) {
		$b = preg_replace_callback( '/\\\\u\{([0-9a-fA-F]+)\}/', fn( $m ) => mb_chr_compat( hexdec( $m[1] ) ), $b );
		return stripcslashes( str_replace( '\\$', '$', $b ) );
	}
	return null;
}
function mb_chr_compat( int $cp ): string {
	if ( $cp < 0x80 ) {
		return chr( $cp );
	}
	if ( $cp < 0x800 ) {
		return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
	}
	if ( $cp < 0x10000 ) {
		return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
	}
	return chr( 0xF0 | ( $cp >> 18 ) ) . chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
}
function wd_match( array $T, int $open ): int {
	$pairs = [ '(' => ')', '[' => ']', '{' => '}' ];
	$depth = 0;
	$n     = count( $T );
	for ( $i = $open; $i < $n; $i++ ) {
		$t = $T[ $i ][0];
		if ( $t === '(' || $t === '[' || $t === '{' || $t === T_CURLY_OPEN || $t === T_DOLLAR_OPEN_CURLY_BRACES ) {
			$depth++;
		} elseif ( $t === ')' || $t === ']' || $t === '}' ) {
			$depth--;
			if ( $depth === 0 ) {
				return $i;
			}
		}
	}
	return $n - 1;
}
function wd_args( array $T, int $open ): array {
	$close = wd_match( $T, $open );
	$args  = [];
	$start = $open + 1;
	$depth = 0;
	for ( $i = $open + 1; $i < $close; $i++ ) {
		$t = $T[ $i ][0];
		if ( $t === '(' || $t === '[' || $t === '{' || $t === T_CURLY_OPEN || $t === T_DOLLAR_OPEN_CURLY_BRACES ) {
			$depth++;
		} elseif ( $t === ')' || $t === ']' || $t === '}' ) {
			$depth--;
		} elseif ( $t === ',' && $depth === 0 ) {
			$args[] = [ $start, $i ];
			$start  = $i + 1;
		}
	}
	if ( $start < $close ) {
		$args[] = [ $start, $close ];
	}
	return [ $args, $close ];
}
function wd_text( array $T, int $a, int $b ): string {
	$s = '';
	for ( $i = $a; $i < $b; $i++ ) {
		$s .= $T[ $i ][1];
	}
	return $s;
}
function wd_is_call( array $T, int $i ): bool {
	if ( ! isset( $T[ $i + 1 ] ) || $T[ $i + 1 ][0] !== '(' ) {
		return false;
	}
	$p = $i > 0 ? $T[ $i - 1 ][0] : null;
	$bad = [ T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_CONST, T_CLASS, T_INTERFACE, T_TRAIT ];
	if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
		$bad[] = T_NULLSAFE_OBJECT_OPERATOR;
	}
	if ( $p === '&' && $i > 1 && $T[ $i - 2 ][0] === T_FUNCTION ) {
		return false;
	}
	return ! in_array( $p, $bad, true );
}

/** Évaluation statique d'une expression faite de littéraux, concaténations, variables connues et fonctions pures. */
final class WdEval {
	private $T;
	private $vals;
	public $schemes = [];
	// Budget d'octets matérialisés par fichier : borne le décodage statique pour qu'un fichier piégé
	// (des dizaines de gzinflate ou de concaténations géantes) ne fasse pas exploser la mémoire.
	public static $budget = 67108864;
	public static function reset(): void {
		self::$budget = 67108864;
	}
	public function __construct( array $T, array $vals ) {
		$this->T    = $T;
		$this->vals = $vals;
	}
	public function run( int $a, int $b ): ?string {
		if ( self::$budget <= 0 ) {
			return null;
		}
		$i = $a;
		$v = $this->concat( $i, $b );
		if ( is_string( $v ) ) {
			self::$budget -= strlen( $v );
		}
		return ( $v !== null && $i === $b ) ? $v : null;
	}
	private function concat( int &$i, int $b ): ?string {
		$v = $this->term( $i, $b );
		while ( $v !== null && $i < $b && $this->T[ $i ][0] === '.' ) {
			$i++;
			$w = $this->term( $i, $b );
			if ( $w === null ) {
				return null;
			}
			$v .= $w;
			if ( strlen( $v ) > 5242880 ) {
				return null;
			}
		}
		return $v;
	}
	private function term( int &$i, int $b ): ?string {
		if ( $i >= $b ) {
			return null;
		}
		[ $id, $text ] = $this->T[ $i ];
		if ( $id === T_CONSTANT_ENCAPSED_STRING ) {
			$i++;
			if ( $text[0] === '"' && preg_match( '/\\\\(x[0-9a-fA-F]|[0-7]{2})/', $text ) ) {
				$this->schemes[] = 'echappements';
			}
			return wd_unquote( $text );
		}
		if ( $id === T_LNUMBER || $id === T_DNUMBER ) {
			$i++;
			return (string) intval( $text, 0 );
		}
		if ( $id === T_VARIABLE && array_key_exists( $text, $this->vals ) && $this->vals[ $text ] !== null ) {
			$i++;
			return $this->vals[ $text ];
		}
		if ( $id === T_START_HEREDOC ) {
			$j = $i + 1;
			$s = '';
			while ( $j < $b && $this->T[ $j ][0] === T_ENCAPSED_AND_WHITESPACE ) {
				$s .= $this->T[ $j ][1];
				$j++;
			}
			if ( $j < $b && $this->T[ $j ][0] === T_END_HEREDOC ) {
				$i = $j + 1;
				return wd_has( $text, "'" ) ? $s : stripcslashes( $s );
			}
			return null;
		}
		if ( $id === '(' ) {
			$close = wd_match( $this->T, $i );
			$j     = $i + 1;
			$v     = $this->concat( $j, $close );
			if ( $v === null || $j !== $close ) {
				return null;
			}
			$i = $close + 1;
			return $v;
		}
		if ( $id === T_STRING && isset( $this->T[ $i + 1 ] ) && $this->T[ $i + 1 ][0] === '(' ) {
			$fn = strtolower( $text );
			if ( ! in_array( $fn, WD_PURE, true ) ) {
				return null;
			}
			[ $args, $close ] = wd_args( $this->T, $i + 1 );
			$vals = [];
			foreach ( $args as [ $x, $y ] ) {
				if ( $this->T[ $x ][0] === '[' || $this->T[ $x ][0] === T_ARRAY ) {
					$vals[] = $this->arr( $x, $y );
				} else {
					$vals[] = $this->run( $x, $y );
				}
				if ( end( $vals ) === null ) {
					return null;
				}
			}
			$r = $this->apply( $fn, $vals );
			if ( $r === null ) {
				return null;
			}
			if ( in_array( $fn, WD_DECODERS, true ) ) {
				$this->schemes[] = $fn;
			}
			$i = $close + 1;
			return $r;
		}
		return null;
	}
	private function arr( int $a, int $b ): ?array {
		$open = $this->T[ $a ][0] === '[' ? $a : $a + 1;
		[ $args ] = wd_args( $this->T, $open );
		$out = [];
		foreach ( $args as [ $x, $y ] ) {
			$v = $this->run( $x, $y );
			if ( $v === null ) {
				return null;
			}
			$out[] = $v;
		}
		return $out;
	}
	private function apply( string $fn, array $a ) {
		$s = isset( $a[0] ) && is_string( $a[0] ) ? $a[0] : '';
		switch ( $fn ) {
			case 'chr':
				return chr( ( (int) $s ) % 256 );
			case 'gzinflate':
			case 'gzuncompress':
			case 'gzdecode':
				$r = wd_probe( fn() => $fn( $s, 4194304 ) );
				return is_string( $r ) ? $r : null;
			case 'base64_decode':
				$r = base64_decode( $s );
				return is_string( $r ) ? $r : null;
			case 'hex2bin':
				$r = wd_probe( fn() => hex2bin( $s ) );
				return is_string( $r ) ? $r : null;
			case 'convert_uudecode':
				$r = wd_probe( fn() => convert_uudecode( $s ) );
				return is_string( $r ) ? $r : null;
			case 'pack':
				if ( ! isset( $a[1] ) || ! preg_match( '/^H\*?$/', $s ) ) {
					return null;
				}
				$r = wd_probe( fn() => pack( 'H*', $a[1] ) );
				return is_string( $r ) ? $r : null;
			case 'str_replace':
				return count( $a ) === 3 && ! is_array( $a[2] ) ? str_replace( $a[0], $a[1], $a[2] ) : null;
			case 'substr':
				return isset( $a[1] ) ? (string) substr( $s, (int) $a[1], isset( $a[2] ) ? (int) $a[2] : null ) : null;
			case 'implode':
			case 'join':
				if ( count( $a ) === 2 && is_array( $a[1] ) ) {
					return implode( $s, $a[1] );
				}
				return count( $a ) === 1 && is_array( $a[0] ) ? implode( '', $a[0] ) : null;
			case 'strval':
				return $s;
			default:
				return is_callable( $fn ) && ! is_array( $s ) ? (string) $fn( $s ) : null;
		}
	}
}

function wd_sink_index(): array {
	static $idx = null;
	if ( $idx === null ) {
		$idx = [];
		foreach ( WD_SINKS as $cap => $fns ) {
			foreach ( $fns as $fn => $arg ) {
				$idx[ $fn ] = [ $cap, $arg ];
			}
		}
	}
	return $idx;
}

function wd_dangerous_name( string $name ): bool {
	$n = strtolower( trim( $name ) );
	if ( $n === 'eval' ) {
		return true;
	}
	$idx = wd_sink_index();
	return ( isset( $idx[ $n ] ) && in_array( $idx[ $n ][0], [ 'exec_code', 'exec_cmd', 'file_write', 'callback' ], true ) ) || in_array( $n, WD_DECODERS, true );
}

function wd_php_analyze( string $code, int $depth = 0, bool $light = false ): WdPhp {
	if ( $depth === 0 ) {
		WdEval::reset();
	}
	$P = new WdPhp();
	$T = wd_tokens( $code );
	$n = count( $T );
	foreach ( $T as $t ) {
		if ( $t[0] === T_CONSTANT_ENCAPSED_STRING && strlen( $t[1] ) >= 5 && strlen( $t[1] ) <= 102 ) {
			$P->literals[ substr( $t[1], 1, -1 ) ] = true;
		}
	}
	$P->empty    = $n === 0 || ( $n === 1 && $T[0][0] === T_INLINE_HTML && trim( $T[0][1] ) === '' );
	$P->pureData = wd_pure_data( $T );
	if ( $n === 0 ) {
		return $P;
	}
	if ( $light ) {
		for ( $i = 0; $i < $n; $i++ ) {
			if ( $T[ $i ][0] === T_STRING && wd_is_call( $T, $i ) ) {
				wd_collect_decl( $P, $T, $i, strtolower( $T[ $i ][1] ) );
			}
		}
		return $P;
	}

	// Portées : une par corps de fonction ou de closure, avec héritage des variables « use ».
	$scope   = array_fill( 0, $n, 0 );
	$parent  = [ 0 => -1 ];
	$inherit = [ 0 => [] ];
	$stack   = [ [ 0, 0 ] ];
	$level   = 0;
	$pending = false;
	$uses    = [];
	$sid     = 0;
	$fnBody  = [];
	$fnName  = null;
	for ( $i = 0; $i < $n; $i++ ) {
		$t = $T[ $i ][0];
		if ( $t === T_FUNCTION ) {
			$pending = true;
			$uses    = [];
			$fnName  = ( isset( $T[ $i + 1 ] ) && $T[ $i + 1 ][0] === T_STRING ) ? strtolower( $T[ $i + 1 ][1] ) : null;
			if ( $i > 0 && in_array( $T[ $i - 1 ][0], [ T_USE ], true ) ) {
				$pending = false;
			}
		} elseif ( $pending && $t === T_USE ) {
			$j = $i + 1;
			while ( $j < $n && $T[ $j ][0] !== ')' ) {
				if ( $T[ $j ][0] === T_VARIABLE ) {
					$uses[] = $T[ $j ][1];
				}
				$j++;
			}
		} elseif ( $t === ';' && $pending ) {
			$pending = false;
		}
		if ( $t === '{' || $t === T_CURLY_OPEN || $t === T_DOLLAR_OPEN_CURLY_BRACES ) {
			$level++;
			if ( $pending && $t === '{' ) {
				$sid++;
				$parent[ $sid ]  = end( $stack )[0];
				$inherit[ $sid ] = $uses;
				$stack[]         = [ $sid, $level, $fnName, $i ];
				$pending         = false;
			}
		} elseif ( $t === '}' ) {
			$top = end( $stack );
			if ( count( $stack ) > 1 && $top[1] === $level ) {
				array_pop( $stack );
				if ( $top[2] !== null ) {
					$fnBody[ $top[2] ] = [ $top[3], $i ];
				}
			}
			$level--;
		}
		$scope[ $i ] = end( $stack )[0];
	}

	// Fonctions de l'utilisateur qui renvoient une réponse réseau ou un décodage.
	$remoteFns = [];
	$decFns    = [];
	foreach ( $fnBody as $name => [ $a, $b ] ) {
		for ( $i = $a; $i < $b; $i++ ) {
			if ( $T[ $i ][0] === T_STRING && wd_is_call( $T, $i ) ) {
				$f = strtolower( $T[ $i ][1] );
				if ( in_array( $f, WD_REMOTE_FUNCS, true ) ) {
					$remoteFns[ $name ] = true;
				}
				if ( in_array( $f, WD_DECODERS, true ) && $f !== 'chr' ) {
					$decFns[ $name ] = true;
				}
			}
		}
	}

	$kinds   = [];
	$statics = [];
	$extract = [];
	$lookup  = function ( int $s, string $var ) use ( &$kinds, $parent, $inherit ): array {
		$k = $kinds[ $s ][ $var ] ?? [];
		if ( $s > 0 && in_array( $var, $inherit[ $s ] ?? [], true ) && isset( $parent[ $s ] ) ) {
			$k += $kinds[ $parent[ $s ] ][ $var ] ?? [];
		}
		return $k;
	};
	$statOf  = function ( int $s ) use ( &$statics ): array {
		return $statics[ $s ] ?? [];
	};
	$kindsOf = function ( int $a, int $b ) use ( $T, $scope, $lookup, &$extract, $remoteFns, $decFns, &$P ): array {
		$k = [];
		for ( $j = $a; $j < $b; $j++ ) {
			[ $id, $text ] = $T[ $j ];
			if ( in_array( $id, [ T_INT_CAST, T_DOUBLE_CAST, T_BOOL_CAST ], true ) ) {
				$j++;
				if ( isset( $T[ $j ] ) && $T[ $j ][0] === '(' ) {
					$j = wd_match( $T, $j );
				}
				while ( isset( $T[ $j + 1 ] ) && $T[ $j + 1 ][0] === '[' ) {
					$j = wd_match( $T, $j + 1 );
				}
				continue;
			}
			// isset()/empty()/unset() sont des gardes : ce qu'ils testent n'est pas un flux de donnees.
			if ( in_array( $id, [ T_ISSET, T_EMPTY, T_UNSET ], true ) && isset( $T[ $j + 1 ] ) && $T[ $j + 1 ][0] === '(' ) {
				$j = wd_match( $T, $j + 1 );
				continue;
			}
			if ( $id === T_VARIABLE ) {
				if ( in_array( $text, [ '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_FILES', '$HTTP_RAW_POST_DATA' ], true ) ) {
					$k['req'] = true;
				} elseif ( $text === '$_SERVER' ) {
					$key = ( isset( $T[ $j + 2 ] ) && $T[ $j + 1 ][0] === '[' && $T[ $j + 2 ][0] === T_CONSTANT_ENCAPSED_STRING ) ? (string) wd_unquote( $T[ $j + 2 ][1] ) : '';
					if ( $key === '' || wd_starts( $key, 'HTTP_' ) || in_array( $key, [ 'REQUEST_URI', 'QUERY_STRING', 'PHP_SELF', 'PATH_INFO', 'argv' ], true ) ) {
						$k['req'] = true;
					}
				} elseif ( $text === '$GLOBALS' && isset( $T[ $j + 2 ] ) && $T[ $j + 2 ][0] === T_CONSTANT_ENCAPSED_STRING && preg_match( '/^_(GET|POST|REQUEST|COOKIE|FILES|SERVER)$/', (string) wd_unquote( $T[ $j + 2 ][1] ) ) ) {
					$k['req'] = true;
				} else {
					$k += $lookup( $scope[ $j ], $text );
					if ( ! empty( $extract[ $scope[ $j ] ] ) ) {
						$k['req'] = true;
					}
				}
				continue;
			}
			if ( $id === T_STRING && wd_is_call( $T, $j ) ) {
				$f = strtolower( $text );
				if ( in_array( $f, WD_SANITIZERS, true ) ) {
					$j = wd_match( $T, $j + 1 );
					continue;
				}
				if ( in_array( $f, WD_REQ_FUNCS, true ) ) {
					$k['req'] = true;
				} elseif ( $f === 'getenv' ) {
					// getenv('HTTP_...') / getenv('REQUEST_URI') sous FPM/CGI = en-tete controle par le client.
					$close = wd_match( $T, $j + 1 );
					$arg   = isset( $T[ $j + 2 ] ) && $T[ $j + 2 ][0] === T_CONSTANT_ENCAPSED_STRING ? strtoupper( (string) wd_unquote( $T[ $j + 2 ][1] ) ) : '';
					if ( $arg === '' || wd_starts( $arg, 'HTTP_' ) || in_array( $arg, [ 'REQUEST_URI', 'QUERY_STRING', 'PATH_INFO' ], true ) ) {
						$k['req'] = true;
					}
					$j = $close;
				} elseif ( in_array( $f, WD_DB_FUNCS, true ) ) {
					$k['db'] = true;
				} elseif ( in_array( $f, WD_REMOTE_FUNCS, true ) || isset( $remoteFns[ $f ] ) ) {
					$k['remote'] = true;
				} elseif ( in_array( $f, WD_DECODERS, true ) || isset( $decFns[ $f ] ) ) {
					if ( $f !== 'chr' ) {
						$k['dec'] = true;
					}
				} elseif ( in_array( $f, [ 'file_get_contents', 'fopen', 'file', 'readfile', 'stream_get_contents' ], true ) ) {
					$close = wd_match( $T, $j + 1 );
					$inner = wd_text( $T, $j + 2, $close );
					if ( wd_has( $inner, 'php://input' ) ) {
						$k['req'] = true;
					} elseif ( preg_match( '#[\'"](https?|ftp)://#i', $inner ) ) {
						$k['remote'] = true;
					}
				}
				continue;
			}
			if ( ( $id === T_OBJECT_OPERATOR || ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && $id === T_NULLSAFE_OBJECT_OPERATOR ) ) && isset( $T[ $j + 1 ] ) && $T[ $j + 1 ][0] === T_STRING && in_array( strtolower( $T[ $j + 1 ][1] ), WD_DB_METHODS, true ) ) {
				$k['db'] = true;
			}
		}
		return $k;
	};

	// Affectations (y compris foreach, parse_str, preg_match, extract), propagées jusqu'au point fixe.
	$assign = [];
	$ops    = [ '=', T_CONCAT_EQUAL, T_PLUS_EQUAL, T_COALESCE_EQUAL, T_OR_EQUAL, T_AND_EQUAL, T_XOR_EQUAL ];
	for ( $i = 0; $i < $n; $i++ ) {
		[ $id, $text ] = $T[ $i ];
		if ( $id === T_VARIABLE && ( $i === 0 || ! in_array( $T[ $i - 1 ][0], [ T_OBJECT_OPERATOR, T_DOUBLE_COLON, '$' ], true ) ) ) {
			$j = $i + 1;
			while ( isset( $T[ $j ] ) && ( $T[ $j ][0] === '[' || $T[ $j ][0] === '{' ) ) {
				$j = wd_match( $T, $j ) + 1;
			}
			if ( isset( $T[ $j ] ) && in_array( $T[ $j ][0], $ops, true ) ) {
				$end   = wd_expr_end( $T, $j + 1 );
				$whole = $j === $i + 1;
				$assign[] = [ $scope[ $i ], $text, $j + 1, $end, $whole && $T[ $j ][0] === '=' ];
			}
		} elseif ( $id === T_FOREACH && isset( $T[ $i + 1 ] ) && $T[ $i + 1 ][0] === '(' ) {
			$close = wd_match( $T, $i + 1 );
			for ( $j = $i + 2; $j < $close; $j++ ) {
				if ( $T[ $j ][0] === T_AS ) {
					for ( $v = $j + 1; $v < $close; $v++ ) {
						if ( $T[ $v ][0] === T_VARIABLE ) {
							$assign[] = [ $scope[ $i ], $T[ $v ][1], $i + 2, $j, false ];
						}
					}
					break;
				}
			}
		} elseif ( $id === T_STRING && wd_is_call( $T, $i ) ) {
			$f = strtolower( $text );
			if ( $f === 'extract' ) {
				[ $args ] = wd_args( $T, $i + 1 );
				$ek = $args ? $kindsOf( $args[0][0], $args[0][1] ) : [];
				if ( isset( $ek['req'] ) || isset( $ek['remote'] ) ) {
					$extract[ $scope[ $i ] ] = true;
					$P->markers['extract_entree'] = true;
				}
			} elseif ( in_array( $f, [ 'parse_str', 'preg_match', 'preg_match_all', 'mb_parse_str' ], true ) ) {
				[ $args ] = wd_args( $T, $i + 1 );
				$src = $f === 'parse_str' || $f === 'mb_parse_str' ? 0 : 1;
				$dst = $f === 'parse_str' || $f === 'mb_parse_str' ? 1 : 2;
				if ( isset( $args[ $dst ], $args[ $src ] ) && $T[ $args[ $dst ][0] ][0] === T_VARIABLE ) {
					$assign[] = [ $scope[ $i ], $T[ $args[ $dst ][0] ][1], $args[ $src ][0], $args[ $src ][1], false ];
				}
			}
		} elseif ( ( $id === '[' || $id === T_LIST ) ) {
			$open  = $id === T_LIST ? $i + 1 : $i;
			$close = isset( $T[ $open ] ) && $T[ $open ][0] === ( $id === T_LIST ? '(' : '[' ) ? wd_match( $T, $open ) : -1;
			if ( $close > 0 && isset( $T[ $close + 1 ] ) && $T[ $close + 1 ][0] === '=' && ( $i === 0 || in_array( $T[ $i - 1 ][0], [ ';', '{', '}', '(' ], true ) ) ) {
				$end = wd_expr_end( $T, $close + 2 );
				for ( $v = $open; $v < $close; $v++ ) {
					if ( $T[ $v ][0] === T_VARIABLE ) {
						$assign[] = [ $scope[ $i ], $T[ $v ][1], $close + 2, $end, false ];
					}
				}
			}
		}
	}
	$counts = [];
	foreach ( $assign as [ $s, $var ] ) {
		$counts[ $s ][ $var ] = ( $counts[ $s ][ $var ] ?? 0 ) + 1;
	}
	for ( $iter = 0; $iter < 10; $iter++ ) {
		$changed = false;
		foreach ( $assign as [ $s, $var, $a, $b, $plain ] ) {
			$k   = $kindsOf( $a, $b );
			$old = $kinds[ $s ][ $var ] ?? [];
			$new = $old + $k;
			if ( count( $new ) !== count( $old ) ) {
				$kinds[ $s ][ $var ] = $new;
				$changed             = true;
			}
			if ( $plain && $iter < 2 && ( $counts[ $s ][ $var ] ?? 0 ) === 1 ) {
				$ev = new WdEval( $T, $statOf( $s ) );
				$v  = $ev->run( $a, $b );
				if ( $v !== null && ( ! isset( $statics[ $s ][ $var ] ) || $statics[ $s ][ $var ] !== $v ) ) {
					$statics[ $s ][ $var ] = $v;
					$changed               = true;
					if ( $ev->schemes ) {
						$kinds[ $s ][ $var ]['dec'] = true;
					}
				}
			}
		}
		if ( ! $changed ) {
			break;
		}
	}

	$flow = function ( string $niveau, string $cap, int $line, string $detail, array $k = [] ) use ( &$P ): void {
		$P->flows[] = [ 'niveau' => $niveau, 'capacite' => $cap, 'ligne' => $line, 'detail' => $detail, 'sources' => array_keys( $k ) ];
	};
	$cap = function ( string $c, int $line ) use ( &$P ): void {
		$P->caps[ $c ][] = $line;
	};
	$sinkIdx = wd_sink_index();
	$fnNames = array_fill_keys( array_keys( $fnBody ), true );
	$recurse = function ( string $value, array $schemes, int $line, string $why ) use ( &$P, $depth, $flow ): void {
		[ $more, $final ] = wd_decode_layers( $value );
		$schemes = array_merge( $schemes, $more );
		$preview = wd_clean( substr( preg_replace( '/\s+/', ' ', $final ), 0, 160 ) );
		if ( preg_match( '#^\s*(https?|ftp)://#i', $final ) ) {
			$P->urls[] = trim( $final );
		}
		$P->decoded[] = [ 'ligne' => $line, 'schemas' => $schemes, 'apercu' => $preview, 'contexte' => $why ];
		if ( $depth < 6 && wd_code_score( $final ) >= 1 ) {
			$sub = wd_php_analyze( wd_starts( ltrim( $final ), '<?' ) ? $final : "<?php\n" . $final, $depth + 1 );
			foreach ( $sub->flows as $f ) {
				$f['ligne']  = $line;
				$f['detail'] = 'couche décodée (' . implode( '>', $schemes ) . ') : ' . $f['detail'];
				$P->flows[]  = $f;
			}
			foreach ( $sub->caps as $c => $lines ) {
				$P->caps[ $c ][] = $line;
			}
			$P->urls    = array_merge( $P->urls, $sub->urls );
			$P->decoded = array_merge( $P->decoded, $sub->decoded );
			$P->markers += $sub->markers;
			if ( $sub->remoteOut ) {
				$P->remoteOut = true;
			}
		}
	};

	$chr = 0;
	$varCalls = 0;
	$gotos = 0;
	$o0 = [];
	for ( $i = 0; $i < $n; $i++ ) {
		[ $id, $text, $line ] = $T[ $i ];
		$s = $scope[ $i ];
		if ( $id === T_VARIABLE && preg_match( '/^\$[O0_Il1]{5,}$/', $text ) && preg_match( '/[O0]/', $text ) ) {
			$o0[ $text ] = true;
		}
		if ( $id === T_GOTO ) {
			$gotos++;
		}
		if ( $id === T_CONSTANT_ENCAPSED_STRING && strlen( $text ) >= 1000 && ! preg_match( '/\s/', substr( $text, 1, -1 ) ) && wd_entropy( $text ) >= 4.8 ) {
			$P->markers['litteral_entropique'] = true;
			if ( ! $light ) {
				[ $sch, $final ] = wd_decode_layers( (string) wd_unquote( $text ) );
				if ( $sch && wd_code_score( $final ) >= 2 ) {
					$recurse( (string) wd_unquote( $text ), [], $line, 'littéral encodé' );
				}
			}
		}
		if ( $light ) {
			if ( $id === T_STRING && wd_is_call( $T, $i ) ) {
				wd_collect_decl( $P, $T, $i, strtolower( $text ) );
			}
			continue;
		}

		// eval, include/require
		if ( $id === T_EVAL || in_array( $id, [ T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ], true ) ) {
			$isEval = $id === T_EVAL;
			if ( $isEval && isset( $T[ $i + 1 ] ) && $T[ $i + 1 ][0] === '(' ) {
				$a = $i + 2;
				$b = wd_match( $T, $i + 1 );
			} else {
				$a = $i + 1;
				$b = wd_expr_end( $T, $a );
			}
			$k  = $kindsOf( $a, $b );
			$ev = new WdEval( $T, $statOf( $s ) );
			$sv = $ev->run( $a, $b );
			$c  = $isEval ? 'exec_code' : 'include';
			$cap( $c, $line );
			if ( isset( $k['req'] ) || isset( $k['remote'] ) ) {
				$flow( WD_CONF, $c, $line, ( $isEval ? 'eval' : strtolower( $text ) ) . ' reçoit une entrée ' . ( isset( $k['req'] ) ? 'HTTP' : 'réseau' ), $k );
			} elseif ( isset( $k['dec'] ) || ( $sv !== null && $ev->schemes ) ) {
				$flow( WD_CONF, $c, $line, ( $isEval ? 'eval' : strtolower( $text ) ) . ' d\'un contenu décodé', $k + [ 'dec' => true ] );
			} elseif ( isset( $k['db'] ) ) {
				$P->dbExec[] = [ $line, wd_db_keys( $T, $a, $b ) ];
				$flow( WD_PISTE, $c, $line, ( $isEval ? 'eval' : strtolower( $text ) ) . ' d\'un contenu lu en base', $k );
			} elseif ( ! $isEval && $sv !== null && ( preg_match( '#\.(ico|png|jpe?g|gif|svg|txt|log|css|js|json|dat|tmp|bak)$#i', $sv ) || preg_match( '#^/(tmp|dev/shm|var/tmp)/#', $sv ) ) ) {
				$flow( WD_PISTE, $c, $line, 'inclusion d\'un fichier non PHP : ' . $sv, $k );
			}
			if ( $isEval && $sv !== null ) {
				$recurse( $sv, $ev->schemes, $line, 'argument de eval' );
			}
			continue;
		}
		if ( $id === '`' ) {
			$close = $i + 1;
			while ( $close < $n && $T[ $close ][0] !== '`' ) {
				$close++;
			}
			$k = $kindsOf( $i + 1, $close );
			$cap( 'exec_cmd', $line );
			if ( isset( $k['req'] ) || isset( $k['remote'] ) || isset( $k['dec'] ) ) {
				$flow( WD_CONF, 'exec_cmd', $line, 'commande système (accents graves) sur une entrée non fiable', $k );
			}
			$i = $close;
			continue;
		}
		if ( in_array( $id, [ T_ECHO, T_PRINT, T_EXIT ], true ) ) {
			$b = wd_expr_end( $T, $i + 1 );
			$k = $kindsOf( $i + 1, $b );
			if ( isset( $k['remote'] ) ) {
				$P->remoteOut = true;
				$cap( 'output_remote', $line );
			}
			continue;
		}

		// Appels : nom littéral, variable, chaîne ou expression.
		$callName = null;
		$dynamic  = null;
		if ( $id === T_STRING && wd_is_call( $T, $i ) ) {
			$callName = strtolower( $text );
			$P->calls[ $callName ] = ( $P->calls[ $callName ] ?? 0 ) + 1;
			wd_collect_decl( $P, $T, $i, $callName );
			} elseif ( $id === T_VARIABLE && isset( $T[ $i + 1 ] ) && $T[ $i + 1 ][0] === '[' && ( $i === 0 || ! in_array( $T[ $i - 1 ][0], [ T_OBJECT_OPERATOR, T_DOUBLE_COLON, '&' ], true ) ) ) {
				// Appel dont le nom vient d'un acces tableau tainte : $_GET['a'](...), $arr[$k](...).
				$ce = $i + 1;
				while ( isset( $T[ $ce ] ) && $T[ $ce ][0] === '[' ) {
					$ce = wd_match( $T, $ce ) + 1;
				}
				if ( isset( $T[ $ce ] ) && $T[ $ce ][0] === '(' ) {
					$dynamic = [ $i, $ce - 1 ];
					$varCalls++;
				}
			} elseif ( $id === T_VARIABLE && isset( $T[ $i + 1 ] ) && $T[ $i + 1 ][0] === '(' && ( $i === 0 || ! in_array( $T[ $i - 1 ][0], [ T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, '&' ], true ) ) ) {
				$dynamic = [ $i, $i + 1 ];
				$varCalls++;
		} elseif ( $id === T_CONSTANT_ENCAPSED_STRING && isset( $T[ $i + 1 ] ) && $T[ $i + 1 ][0] === '(' ) {
			$dynamic = [ $i, $i + 1 ];
		} elseif ( $id === ')' && isset( $T[ $i + 1 ] ) && $T[ $i + 1 ][0] === '(' ) {
			for ( $o = $i - 1, $d = 0; $o >= 0; $o-- ) {
				if ( $T[ $o ][0] === ')' ) {
					$d++;
				} elseif ( $T[ $o ][0] === '(' ) {
					if ( $d === 0 ) {
						break;
					}
					$d--;
				}
			}
			if ( $o > 0 && ! in_array( $T[ $o - 1 ][0], [ T_STRING, T_VARIABLE, T_ARRAY, T_ISSET, T_EMPTY, T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_FUNCTION, T_USE, T_LIST, T_CATCH, T_DECLARE, ')' , ']' ], true ) ) {
				$dynamic = [ $o + 1, $i ];
			}
		}

		if ( $dynamic !== null ) {
			[ $da, $db ] = $dynamic;
			$k  = $kindsOf( $da, $db === $da + 1 ? $da + 1 : $db );
			$ev = new WdEval( $T, $statOf( $s ) );
			$sv = $ev->run( $da, $db === $da + 1 ? $da + 1 : $db );
			$open = $db === $da + 1 ? $db : $db + 1;
			[ $args ] = wd_args( $T, $open );
			$argK = [];
			foreach ( $args as [ $x, $y ] ) {
				$argK += $kindsOf( $x, $y );
			}
			$cap( 'callback', $line );
			if ( isset( $k['req'] ) || isset( $k['remote'] ) ) {
				$flow( WD_CONF, 'callback', $line, 'appel de fonction dont le nom vient d\'une entrée non fiable', $k );
			} elseif ( $sv !== null && wd_dangerous_name( $sv ) ) {
				$flow( WD_CONF, 'callback', $line, 'appel déguisé de ' . strtolower( $sv ) . '()' . ( $ev->schemes ? ' (nom décodé)' : '' ), $k + $argK );
			} elseif ( isset( $k['dec'] ) ) {
				$flow( WD_CONF, 'callback', $line, 'appel d\'une fonction au nom masqué', $k );
			} elseif ( isset( $argK['req'] ) || isset( $argK['remote'] ) || isset( $argK['dec'] ) ) {
				$flow( WD_PISTE, 'callback', $line, 'appel indirect (nom non resolu) recevant une entree non fiable', $argK );
			}
			continue;
		}
		if ( $callName === null ) {
			continue;
		}
		if ( $callName === 'chr' ) {
			$chr++;
		}
		[ $args, $close ] = wd_args( $T, $i + 1 );
		$argKinds = function ( int $ix ) use ( $args, $kindsOf ): array {
			return isset( $args[ $ix ] ) ? $kindsOf( $args[ $ix ][0], $args[ $ix ][1] ) : [];
		};
		$argStatic = function ( int $ix ) use ( $args, $T, $statOf, $s ): ?string {
			if ( ! isset( $args[ $ix ] ) ) {
				return null;
			}
			return ( new WdEval( $T, $statOf( $s ) ) )->run( $args[ $ix ][0], $args[ $ix ][1] );
		};

		// Décodeurs : on déroule toute chaîne statique décodable, où qu'elle soit.
		if ( in_array( $callName, WD_DECODERS, true ) && $callName !== 'chr' && ( $i === 0 || ! ( $T[ $i - 1 ][0] === '(' && $i > 1 && $T[ $i - 2 ][0] === T_STRING && in_array( strtolower( $T[ $i - 2 ][1] ), WD_DECODERS, true ) ) ) ) {
			$ev = new WdEval( $T, $statOf( $s ) );
			$sv = $ev->run( $i, $close + 1 );
			if ( $sv !== null && $ev->schemes ) {
				$recurse( $sv, $ev->schemes, $line, 'chaîne décodée' );
			}
			continue;
		}

		if ( $callName === 'header' || in_array( $callName, [ 'printf', 'print_r', 'vprintf', 'var_dump' ], true ) ) {
			if ( isset( $argKinds( 0 )['remote'] ) ) {
				$P->remoteOut = true;
				$cap( 'output_remote', $line );
			}
			continue;
		}
		if ( $callName === 'ignore_user_abort' ) {
			$P->markers['ignore_user_abort'] = true;
		}
		if ( $callName === 'set_time_limit' && ( $argStatic( 0 ) === '0' ) ) {
			$P->markers['sans_limite_de_temps'] = true;
		}
		if ( in_array( $callName, [ 'preg_replace', 'mb_ereg_replace', 'preg_filter' ], true ) ) {
			$pat = $argStatic( 0 );
			if ( $pat !== null && preg_match( '/^(.).*\1[a-zA-Z]*e[a-zA-Z]*$/s', $pat ) ) {
				$k = $argKinds( 1 ) + $argKinds( 2 );
				$cap( 'exec_code', $line );
				$flow( isset( $k['req'] ) || isset( $k['dec'] ) || isset( $k['remote'] ) ? WD_CONF : WD_PISTE, 'exec_code', $line, 'motif /e (exécution de code) dans ' . $callName, $k );
			}
			continue;
		}
		if ( $callName === 'define' ) {
			$name = $argStatic( 0 );
			if ( $name !== null && in_array( $name, WD_UPDATE_CONSTS, true ) ) {
				$P->defines[ $name ] = [ $args ? wd_text( $T, $args[1][0] ?? 0, $args[1][1] ?? 0 ) : '', $line ];
			}
			continue;
		}
		if ( in_array( $callName, [ 'add_filter', 'add_action' ], true ) ) {
			$hook = $argStatic( 0 );
			if ( $hook !== null && preg_match( WD_UPDATE_HOOKS, $hook ) ) {
				$P->updateFilters[] = [ $hook, isset( $args[1] ) ? wd_clean( wd_text( $T, $args[1][0], $args[1][1] ) ) : '', $line ];
			}
			continue;
		}
		if ( ! isset( $sinkIdx[ $callName ] ) || isset( $fnNames[ $callName ] ) ) {
			continue;
		}
		[ $c, $ai ] = $sinkIdx[ $callName ];
		$cap( $c, $line );
		$k   = $argKinds( $ai );
		$bad = isset( $k['req'] ) || isset( $k['remote'] );
		switch ( $c ) {
			case 'exec_code':
				if ( $bad || isset( $k['dec'] ) ) {
					$flow( WD_CONF, $c, $line, $callName . '() reçoit une entrée non fiable ou décodée', $k );
				} elseif ( isset( $k['db'] ) ) {
					$flow( WD_PISTE, $c, $line, $callName . '() exécute un contenu lu en base', $k );
				}
				$sv = $argStatic( $ai );
				if ( $sv !== null ) {
					$recurse( $sv, [], $line, 'argument de ' . $callName );
				}
				break;
			case 'exec_cmd':
				if ( $bad || isset( $k['dec'] ) ) {
					$flow( WD_CONF, $c, $line, 'commande système ' . $callName . '() sur une entrée non fiable', $k );
				} elseif ( isset( $k['db'] ) ) {
					$flow( WD_PISTE, $c, $line, 'commande système ' . $callName . '() construite depuis la base', $k );
				}
				break;
			case 'callback':
				$sv = $argStatic( $ai );
				$other = [];
				foreach ( array_keys( $args ) as $ix ) {
					if ( $ix !== $ai ) {
						$other += $argKinds( $ix );
					}
				}
				if ( $bad || isset( $k['dec'] ) ) {
					$flow( WD_CONF, $c, $line, $callName . '() avec un rappel issu d\'une entrée non fiable ou décodée', $k );
				} elseif ( $sv !== null && wd_dangerous_name( $sv ) ) {
					$flow( isset( $other['req'] ) || isset( $other['remote'] ) || isset( $other['dec'] ) ? WD_CONF : WD_PISTE, $c, $line, $callName . '() appelle ' . $sv . '()', $other );
				}
				break;
			case 'file_write':
				$pathIx = in_array( $callName, [ 'fwrite', 'fputs' ], true ) ? -1 : ( $callName === 'copy' || $callName === 'move_uploaded_file' || $callName === 'symlink' ? 1 : 0 );
				$pk = $pathIx >= 0 ? $argKinds( $pathIx ) : [];
				$pv = $pathIx >= 0 ? $argStatic( $pathIx ) : null;
				$dk = $callName === 'copy' ? $argKinds( 0 ) : $k;
				$reqRemote  = isset( $dk['req'] ) || isset( $dk['remote'] );
				$codeTarget = $pv !== null && preg_match( '/(\.(php\d?|phtml|phar|htaccess)|\.user\.ini)$/i', $pv );
				if ( $reqRemote && ( isset( $pk['req'] ) || $codeTarget ) ) {
					// Contenu attaquant vers un chemin controle ou un fichier de code : dropper.
					$flow( WD_CONF, $c, $line, 'écriture de fichier (' . $callName . ') : contenu non fiable vers du code', $dk + $pk );
				} elseif ( $reqRemote ) {
					// Chemin dynamique non-code : dropper possible, mais aussi un journal legitime.
					$flow( WD_PISTE, $c, $line, 'écriture de fichier (' . $callName . ') : contenu non fiable vers un chemin dynamique', $dk + $pk );
				} elseif ( isset( $dk['dec'] ) && $codeTarget ) {
					$flow( WD_CONF, $c, $line, 'écriture d’un fichier exécutable depuis un contenu décodé (dropper)', $dk + $pk );
				} elseif ( isset( $dk['dec'] ) ) {
					// Contenu décodé vers un chemin dynamique : suspect, mais aussi un cache légitime.
					$flow( WD_PISTE, $c, $line, $callName . '() : contenu décodé vers un chemin dynamique', $dk + $pk );
				} elseif ( isset( $pk['req'] ) ) {
					$flow( WD_PISTE, $c, $line, $callName . '() vers un chemin fourni par la requête', $pk );
				}
				break;
			case 'net_out':
				if ( isset( $k['req'] ) ) {
					$flow( WD_PISTE, $c, $line, 'requête sortante vers une adresse fournie par la requête', $k );
				} elseif ( isset( $k['dec'] ) ) {
					$P->netDecoded = true;
				}
				break;
			case 'mail':
				if ( isset( $k['req'] ) && isset( $argKinds( 2 )['req'] ) ) {
					$flow( WD_PISTE, $c, $line, 'envoi de courriel entièrement piloté par la requête', $k );
				}
				break;
			case 'user_create':
				$all = [];
				foreach ( array_keys( $args ) as $ix ) {
					$all += $argKinds( $ix );
				}
				if ( isset( $all['req'] ) || isset( $all['remote'] ) || isset( $all['dec'] ) ) {
					$flow( WD_CONF, $c, $line, 'création ou modification de compte (' . $callName . ') pilotée par une entrée non fiable', $all );
				}
				break;
			case 'option_write':
				$name = $argStatic( 0 );
				$nk   = $argKinds( 0 );
				if ( isset( $nk['req'] ) ) {
					$flow( WD_CONF, $c, $line, 'écriture d\'une option dont le nom vient de la requête', $nk );
				} elseif ( $name !== null && in_array( $name, WD_SENSITIVE_OPTIONS, true ) ) {
					$flow( $bad || isset( $k['dec'] ) ? WD_CONF : WD_PISTE, $c, $line, 'modification de l\'option sensible « ' . $name . ' »', $k );
				}
				break;
				case 'auth':
					$all = [];
					foreach ( array_keys( $args ) as $ix ) {
						$all += $argKinds( $ix );
					}
					if ( isset( $all['req'] ) || isset( $all['remote'] ) || isset( $all['dec'] ) ) {
						$flow( WD_CONF, $c, $line, 'connexion forcee : '.$callName.'() prend une identite depuis une entree non fiable', $all );
					}
					break;
				case 'rename':
					$pk = $argKinds( 1 );
					$pv = $argStatic( 1 );
					if ( ( isset( $pk['req'] ) || $pv === null ) && $pv !== null && preg_match( '/(\.(php\d?|phtml|phar|htaccess)|\.user\.ini)$/i', (string) $pv ) ) {
						$flow( WD_CONF, $c, $line, $callName.'() cree un fichier executable (renommage vers du code)', $pk );
					} elseif ( isset( $argKinds( 0 )['req'] ) || isset( $pk['req'] ) ) {
						$flow( WD_PISTE, $c, $line, $callName.'() avec un chemin fourni par la requete', $pk );
					}
					break;
			case 'timestomp':
				$P->markers['horodatage_force'] = true;
				break;
		}
	}
	if ( count( $o0 ) >= 3 ) {
		$P->markers['noms_O0'] = true;
	}
	if ( $chr >= 12 ) {
		$P->markers['chr_en_serie'] = true;
	}
	if ( $gotos >= 10 ) {
		$P->markers['goto_en_serie'] = true;
	}
	if ( $varCalls >= 6 ) {
		$P->markers['appels_dynamiques'] = true;
	}
	if ( isset( $P->markers['ignore_user_abort'], $P->markers['sans_limite_de_temps'] ) ) {
		$P->markers['processus_detache'] = true;
	}
	unset( $P->markers['ignore_user_abort'], $P->markers['sans_limite_de_temps'] );
	return $P;
}

function wd_collect_decl( WdPhp $P, array $T, int $i, string $fn ): void {
	$argIx = [ 'add_action' => 0, 'add_filter' => 0, 'has_action' => 0, 'has_filter' => 0, 'do_action' => 0, 'apply_filters' => 0, 'wp_schedule_event' => 2, 'wp_schedule_single_event' => 1, 'wp_next_scheduled' => 0, 'register_post_type' => 0 ];
	if ( ! isset( $argIx[ $fn ] ) ) {
		return;
	}
	[ $args ] = wd_args( $T, $i + 1 );
	$ix = $argIx[ $fn ];
	if ( isset( $args[ $ix ] ) && $args[ $ix ][1] - $args[ $ix ][0] === 1 && $T[ $args[ $ix ][0] ][0] === T_CONSTANT_ENCAPSED_STRING ) {
		$v = (string) wd_unquote( $T[ $args[ $ix ][0] ][1] );
		if ( $fn === 'register_post_type' ) {
			$P->types[ $v ] = true;
		} else {
			$P->hooks[ $v ] = true;
		}
	}
}
function wd_db_keys( array $T, int $a, int $b ): string {
	$keys = [];
	for ( $j = $a; $j < $b; $j++ ) {
		if ( $T[ $j ][0] === T_STRING && in_array( strtolower( $T[ $j ][1] ), WD_DB_FUNCS, true ) && isset( $T[ $j + 2 ] ) && $T[ $j + 2 ][0] === T_CONSTANT_ENCAPSED_STRING ) {
			$keys[] = strtolower( $T[ $j ][1] ) . '(' . wd_unquote( $T[ $j + 2 ][1] ) . ')';
		}
	}
	return implode( ', ', $keys );
}
function wd_expr_end( array $T, int $a ): int {
	$n     = count( $T );
	$depth = 0;
	for ( $j = $a; $j < $n; $j++ ) {
		$t = $T[ $j ][0];
		if ( $t === '(' || $t === '[' || $t === '{' || $t === T_CURLY_OPEN || $t === T_DOLLAR_OPEN_CURLY_BRACES ) {
			$depth++;
		} elseif ( $t === ')' || $t === ']' || $t === '}' ) {
			if ( $depth === 0 ) {
				return $j;
			}
			$depth--;
		} elseif ( ( $t === ';' || $t === ',' || $t === T_AS || $t === T_DOUBLE_ARROW ) && $depth === 0 ) {
			return $j;
		}
	}
	return $n;
}
/** Fichier « données » : vide, commentaires, `return [littéraux]`, ou exit/die en tête (fichiers de cache). */
function wd_pure_data( array $T ): bool {
	if ( ! $T ) {
		return true;
	}
	if ( in_array( $T[0][0], [ T_EXIT ], true ) ) {
		return true;
	}
	$ok = [ T_RETURN, T_ARRAY, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_DOUBLE_ARROW, '[', ']', '(', ')', ',', ';', '.', '-', '+' ];
	foreach ( $T as $t ) {
		if ( in_array( $t[0], $ok, true ) ) {
			continue;
		}
		if ( $t[0] === T_STRING && in_array( strtolower( $t[1] ), [ 'true', 'false', 'null' ], true ) ) {
			continue;
		}
		if ( $t[0] === T_INLINE_HTML && trim( $t[1] ) === '' ) {
			continue;
		}
		return false;
	}
	return true;
}

/** Balisage HTML/JS stocké (widgets, options, contenus). */
function wd_markup( string $v ): array {
	$issues = [];
	$lvl    = null;
	if ( preg_match_all( '#<script\b([^>]*)>(.*?)(</script>|$)#is', $v, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $s ) {
			$src = preg_match( '#\bsrc\s*=\s*["\']?([^"\'\s>]+)#i', $s[1], $sm ) ? $sm[1] : '';
			$body = $s[2];
			$obf  = preg_match_all( '/\b(eval|atob|unescape|escape)\s*\(|fromCharCode|\\\\x[0-9a-f]{2}|\\\\u00[0-9a-f]{2}/i', $body );
			$inj  = preg_match_all( '/document\.write|createElement\s*\(\s*[\'"]script|\.src\s*=|window\.location|location\.(href|replace|assign)|\.appendChild|insertBefore/i', $body );
			if ( $src !== '' ) {
				$issues[] = [ 'script_externe', wd_host( wd_starts( $src, '//' ) ? 'https:' . $src : $src ), $src, null ];
			}
			if ( $obf && $inj ) {
				$issues[] = [ 'script_obfusque_injecteur', '', wd_clean( substr( $body, 0, 160 ) ), WD_CONF ];
				$lvl      = WD_CONF;
			} elseif ( $obf || $inj ) {
				$issues[] = [ 'script_actif', '', wd_clean( substr( $body, 0, 160 ) ), null ];
			} elseif ( $src === '' && trim( $body ) !== '' ) {
				$issues[] = [ 'script_inline', '', wd_clean( substr( $body, 0, 120 ) ), null ];
			}
		}
	}
	if ( preg_match_all( '#<iframe\b[^>]*>#i', $v, $m ) ) {
		foreach ( $m[0] as $f ) {
			if ( preg_match( '#(width|height)\s*=\s*["\']?[01]\b|display\s*:\s*none|visibility\s*:\s*hidden#i', $f ) ) {
				$issues[] = [ 'iframe_cachee', preg_match( '#src\s*=\s*["\']?([^"\'\s>]+)#i', $f, $sm ) ? wd_host( $sm[1] ) : '', wd_clean( $f ), WD_CONF ];
				$lvl      = WD_CONF;
			}
		}
	}
	if ( preg_match( '#<meta[^>]+http-equiv\s*=\s*["\']?refresh[^>]*url\s*=\s*([^"\'>]+)#i', $v, $mm ) ) {
		$issues[] = [ 'redirection_meta', wd_host( $mm[1] ), wd_clean( $mm[1] ), WD_PISTE ];
	}
	if ( preg_match( '#\bon(error|load|mouseover)\s*=\s*["\'][^"\']*(eval|atob|fromCharCode|document\.|location)#i', $v, $mm ) ) {
		$issues[] = [ 'gestionnaire_evenement', '', wd_clean( $mm[0] ), WD_PISTE ];
		$lvl      = $lvl ?? WD_PISTE;
	}
	return [ $issues, $lvl ];
}

/** Analyse une valeur stockée (base, cron, widget) : désérialise, décode les blocs encodés, analyse le code et le balisage. */
function wd_value( string $v, int $depth = 0 ): array {
	$res = [ 'flows' => [], 'caps' => [], 'markup' => [], 'markupLvl' => null, 'decoded' => [], 'code' => false, 'urls' => [] ];
	if ( strlen( $v ) < 8 || $depth > 5 ) {
		return $res;
	}
	$strings = [];
	wd_strings( $v, $strings );
	foreach ( $strings as $s ) {
		if ( strlen( $s ) < 8 ) {
			continue;
		}
		$hasPhp = stripos( $s, '<?php' ) !== false;
		$looksCode = $hasPhp || ( ! preg_match( '/^\s*</', $s ) && preg_match( '/;\s*(\r?\n|$)/', $s ) && preg_match( '/(\$[A-Za-z_]\w*\s*(\[[^\]]*\]\s*)?=|\b(function|return|echo|if|add_action|add_filter)\b)/', $s ) );
		if ( $looksCode ) {
			$P = wd_php_analyze( $hasPhp ? $s : "<?php\n" . $s, 0 );
			if ( $P->calls || $P->flows ) {
				$res['code'] = true;
			}
			$res['flows']   = array_merge( $res['flows'], $P->flows );
			$res['caps']   += array_fill_keys( array_keys( $P->caps ), true );
			$res['decoded'] = array_merge( $res['decoded'], $P->decoded );
			$res['urls']    = array_merge( $res['urls'], $P->urls );
			if ( $P->remoteOut && ( $P->urls || $P->netDecoded ) ) {
				$res['flows'][] = [ 'niveau' => WD_CONF, 'capacite' => 'output_remote', 'ligne' => 0, 'detail' => 'relaie un contenu distant dont l\'adresse est masquée', 'sources' => [ 'remote', 'dec' ] ];
			}
		}
		if ( stripos( $s, '<script' ) !== false || stripos( $s, '<iframe' ) !== false || stripos( $s, 'http-equiv' ) !== false || preg_match( '/\bon(error|load|mouseover)\s*=/i', $s ) ) {
			[ $iss, $lvl ] = wd_markup( $s );
			$res['markup'] = array_merge( $res['markup'], $iss );
			if ( $lvl === WD_CONF || ( $lvl !== null && $res['markupLvl'] === null ) ) {
				$res['markupLvl'] = $lvl;
			}
		}
		if ( preg_match_all( '#[A-Za-z0-9+/]{40,}={0,2}#', $s, $m ) ) {
			foreach ( array_slice( array_unique( $m[0] ), 0, 20 ) as $blob ) {
				[ $sch, $final ] = wd_decode_layers( $blob );
				if ( ! $sch || ! wd_printable( $final ) ) {
					continue;
				}
				$vocab = preg_match_all( '/(exec|eval|shell|system|passthru|php_?code|cmd|command|upload|install|create_?user|add_?user|assert|base64)/i', $final, $vm );
				$res['decoded'][] = [ 'schemas' => $sch, 'apercu' => wd_clean( substr( $final, 0, 200 ) ), 'vocabulaire' => array_values( array_unique( array_map( 'strtolower', $vm[0] ) ) ) ];
				$sub = wd_value( $final, $depth + 1 );
				foreach ( $sub['flows'] as $f ) {
					$f['detail']    = 'couche décodée (' . implode( '>', $sch ) . ') : ' . $f['detail'];
					$res['flows'][] = $f;
				}
				$res['markup']  = array_merge( $res['markup'], $sub['markup'] );
				$res['decoded'] = array_merge( $res['decoded'], $sub['decoded'] );
				$res['code']    = $res['code'] || $sub['code'];
			}
		}
	}
	return $res;
}

// ============================================================ HTTP sortant (API officielles WordPress, URL du site)

function wd_http( WdReport $R, string $url, array $headers = [], int $timeout = 20 ): array {
	if ( function_exists( 'curl_init' ) ) {
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_HEADER         => true,
				CURLOPT_USERAGENT      => 'wp-incident-response/' . WD_VERSION,
			]
		);
		$resp = curl_exec( $ch );
		if ( $resp === false ) {
			$err = curl_error( $ch );
			curl_close( $ch );
			return [ 0, '', [], 'curl : ' . $err ];
		}
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$hs   = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		curl_close( $ch );
		return [ $code, (string) substr( $resp, $hs ), wd_parse_headers( substr( $resp, 0, $hs ) ), '' ];
	}
	if ( ! in_array( 'https', stream_get_wrappers(), true ) && wd_starts( $url, 'https' ) ) {
		return [ 0, '', [], 'ni curl ni flux https (extension openssl absente)' ];
	}
	$ua  = 'wp-incident-response/' . WD_VERSION;
	foreach ( $headers as $h ) {
		if ( stripos( $h, 'user-agent:' ) === 0 ) {
			$ua = trim( substr( $h, 11 ) );
		}
	}
	$ctx = stream_context_create( [ 'http' => [ 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0, 'protocol_version' => 1.1, 'header' => implode( "\r\n", array_merge( [ 'Connection: close' ], $headers ) ), 'user_agent' => $ua ] ] );
	$warn = [];
	$hdrs = [];
	// $http_response_header n'existe que dans la portée de l'appel : on le remonte explicitement.
	$body = wd_probe(
		function () use ( $url, $ctx, &$hdrs ) {
			$b    = file_get_contents( $url, false, $ctx );
			$hdrs = $http_response_header ?? [];
			return $b;
		},
		$warn
	);
	if ( ! is_string( $body ) ) {
		return [ 0, '', [], 'flux : ' . implode( ' ; ', $warn ) ];
	}
	$code = ( $hdrs && preg_match( '#HTTP/\S+\s+(\d{3})#', $hdrs[0], $m ) ) ? (int) $m[1] : 0;
	return [ $code, $body, wd_parse_headers( implode( "\r\n", $hdrs ) ), '' ];
}
function wd_parse_headers( string $raw ): array {
	$h = [];
	foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
		if ( strpos( $line, ':' ) !== false ) {
			[ $k, $v ] = explode( ':', $line, 2 );
			$h[ strtolower( trim( $k ) ) ] = trim( $v );
		}
	}
	return $h;
}
function wd_json_get( WdReport $R, string $url ): array {
	[ $code, $body, , $err ] = wd_http( $R, $url );
	if ( $code === 0 ) {
		return [ 'injoignable', null, $err ];
	}
	if ( $code === 404 ) {
		return [ 'absent', null, 'HTTP 404' ];
	}
	if ( $code !== 200 ) {
		return [ 'injoignable', null, 'HTTP ' . $code ];
	}
	$j = json_decode( $body, true );
	return is_array( $j ) ? [ 'ok', $j, '' ] : [ 'injoignable', null, 'réponse non JSON' ];
}

// ============================================================ Fichiers de configuration serveur

function wd_htaccess( WdReport $R, string $rel, string $content, array $ctx ): void {
	$dir     = dirname( $rel ) === '.' ? '' : dirname( $rel ) . '/';
	$isRoot  = $dir === '';
	$lines   = preg_split( '/\r?\n/', $content );
	$blocks  = [];
	$cur     = null;
	$markers = [];
	$marker  = null;
	$unexplained = [];
	$wpStd = '/^(RewriteEngine On|RewriteBase \/.*|RewriteRule \^index\\\\\.php\$ - \[L\]|RewriteCond %\{REQUEST_FILENAME\} !-[fd]|RewriteRule \. \/?(.*\/)?index\.php \[L\]|RewriteRule \.\* - \[E=HTTP_AUTHORIZATION:%\{HTTP:Authorization\}\]|<\/?IfModule[^>]*>)$/i';
	$conds     = [];
	$condStart = null;
	foreach ( $lines as $no => $raw ) {
		$l = trim( $raw );
		if ( preg_match( '/^#\s*BEGIN\s+(.+)$/i', $l, $m ) ) {
			$marker = trim( $m[1] );
			$markers[ $marker ] = true;
			continue;
		}
		if ( preg_match( '/^#\s*END\s+/i', $l ) ) {
			$marker = null;
			continue;
		}
		if ( $l === '' || $l[0] === '#' ) {
			continue;
		}
		$explained = $marker !== null && wd_marker_explained( $marker, $ctx );
		if ( ! $explained && ! preg_match( $wpStd, $l ) ) {
			$unexplained[] = 'L' . ( $no + 1 ) . ' ' . $l;
		}
		if ( preg_match( '/^<(FilesMatch|Files)\s+"?([^">]+)"?>/i', $l, $m ) ) {
			$cur = [ 'type' => strtolower( $m[1] ), 'pat' => $m[2], 'deny' => false, 'allow' => false, 'line' => $no + 1 ];
			continue;
		}
		if ( $cur !== null && preg_match( '/^<\/(FilesMatch|Files)>/i', $l ) ) {
			$cur['end'] = $no + 1;
			$blocks[]   = $cur;
			$cur        = null;
			continue;
		}
		if ( $cur !== null ) {
			if ( preg_match( '/^(Deny from all|Require all denied)$/i', $l ) ) {
				$cur['deny'] = true;
			}
			if ( preg_match( '/^(Allow from all|Require all granted)$/i', $l ) ) {
				$cur['allow'] = true;
			}
		}
		if ( preg_match( '/^php_(admin_)?value\s+(auto_prepend_file|auto_append_file)\s+(\S+)/i', $l, $m ) ) {
			wd_prepend( $R, $rel, $no + 1, $m[2], $m[3], $ctx );
		}
		if ( preg_match( '/^(AddHandler|SetHandler|AddType|ForceType)\s+(\S+)(.*)$/i', $l, $m ) && preg_match( '/php|x-httpd|cgi-script/i', $m[2] ) ) {
			$exts = strtolower( $m[3] );
			if ( preg_match( '/\.(jpe?g|png|gif|ico|txt|svg|webp|bmp|css|js|log|zip|pdf)\b/', $exts ) || ( strtolower( $m[1] ) === 'sethandler' && $cur !== null && ! preg_match( '/php/i', $cur['pat'] ) ) ) {
				$R->add( WD_CONF, 'serveur.handler', $rel, 'Des fichiers non PHP sont exécutés comme du code', [ 'L' . ( $no + 1 ) . ' ' . $l ], 'Retirer cette directive (retrait chirurgical, sauvegarde avant).', [ 'lignes' => [ [ $no + 1, $no + 1 ] ] ] );
			}
		}
		if ( preg_match( '/^ErrorDocument\s+\d{3}\s+(\S+\.php\S*)/i', $l, $m ) && ! in_array( ltrim( basename( $m[1] ), '/' ), WD_CORE_ROOT_FILES, true ) ) {
			$R->add( WD_PISTE, 'serveur.errordocument', $rel, 'Page d\'erreur servie par un script PHP non WordPress', [ 'L' . ( $no + 1 ) . ' ' . $l ], '', [ 'lignes' => [ [ $no + 1, $no + 1 ] ] ] );
		}
		if ( preg_match( '/^RewriteCond\s/i', $l ) ) {
			$condStart = $condStart ?? $no + 1;
		}
		if ( preg_match( '/^RewriteCond\s+%\{(HTTP_USER_AGENT|HTTP_REFERER|HTTP_ACCEPT_LANGUAGE|REMOTE_ADDR)\}/i', $l, $m ) ) {
			$conds[] = strtoupper( $m[1] );
		}
		if ( preg_match( '/^RewriteRule\s+(\S+)\s+(\S+)/i', $l, $m ) ) {
			$target = $m[2];
			if ( preg_match( '#^https?://#i', $target ) ) {
				$st = array_intersect( $conds, [ 'HTTP_USER_AGENT', 'HTTP_REFERER', 'HTTP_ACCEPT_LANGUAGE' ] ) ? WD_CONF : WD_PISTE;
				$R->add( $st, 'serveur.redirection', $rel, $st === WD_CONF ? 'Redirection externe conditionnée par le visiteur (cloaking)' : 'Redirection vers un domaine externe', [ 'L' . ( $no + 1 ) . ' ' . $l, 'conditions : ' . ( $conds ? implode( ', ', array_unique( $conds ) ) : 'aucune' ) ], '', [ 'lignes' => [ [ $condStart ?? $no + 1, $no + 1 ] ] ] );
			} elseif ( preg_match( '#([^\s?]+\.php)#i', $target, $tm ) && ! wd_rewrite_explained( $dir, $tm[1], $ctx ) ) {
				$R->add( WD_PISTE, 'serveur.reecriture', $rel, 'Réécriture vers un script PHP qu\'aucun composant installé n\'explique', [ 'L' . ( $no + 1 ) . ' ' . $l ], '', [ 'lignes' => [ [ $condStart ?? $no + 1, $no + 1 ] ] ] );
			}
			$conds     = [];
			$condStart = null;
		}
	}
	$probe = 'wd-sonde-' . substr( md5( $rel ), 0, 6 ) . '.php';
	$denyPhp = false;
	$allowNames = [];
	$lockLines  = [];
	foreach ( $blocks as $b ) {
		$re = '~' . str_replace( '~', '\~', $b['pat'] ) . '~';
		$matchesProbe = $b['type'] === 'filesmatch' ? (bool) wd_probe( fn() => preg_match( $re, $probe ) ) : false;
		if ( $b['deny'] && $matchesProbe ) {
			$denyPhp     = true;
			$lockLines[] = [ $b['line'], $b['end'] ];
		}
		if ( $b['allow'] && ! $matchesProbe && preg_match_all( '/[A-Za-z0-9_.\\\\-]+\\\\?\.php/i', $b['pat'], $nm ) ) {
			$lockLines[] = [ $b['line'], $b['end'] ];
			foreach ( $nm[0] as $name ) {
				$allowNames[] = str_replace( '\\', '', $name );
			}
		}
	}
	if ( $denyPhp && $allowNames ) {
		$foreign = array_values( array_diff( array_unique( $allowNames ), WD_CORE_ROOT_FILES ) );
		$R->add(
			$isRoot ? WD_CONF : WD_PISTE,
			'serveur.verrou',
			$rel,
			'Verrou .htaccess : tout PHP est interdit sauf une liste de noms' . ( $isRoot ? ' (bloque wp-admin, les mises à jour et tout nettoyage par script)' : '' ),
			[ 'noms autorisés : ' . implode( ', ', array_unique( $allowNames ) ), 'noms étrangers à WordPress : ' . ( $foreign ? implode( ', ', $foreign ) : 'aucun' ) ],
			'Retirer chirurgicalement les blocs FilesMatch concernés après sauvegarde ; chercher les fichiers de la liste et le processus qui réécrit ce fichier.',
			[ 'noms_autorises' => array_values( array_unique( $allowNames ) ), 'lignes' => $lockLines ]
		);
	} elseif ( $denyPhp && $isRoot ) {
		$R->add( WD_PISTE, 'serveur.verrou', $rel, 'Le .htaccess racine interdit l\'exécution de PHP', [], 'Vérifier que wp-admin et les mises à jour fonctionnent.' );
	}
	if ( $unexplained ) {
		$R->add( wd_is_wp( $ctx ) ? WD_PISTE : WD_HUMAN, 'serveur.regle_non_expliquee', $rel, 'Règles serveur qu\'aucune référence n\'explique (ni bloc WordPress standard, ni bloc balisé d\'un composant installé)', array_slice( $unexplained, 0, 25 ), 'Faire confirmer chaque règle par le client ou la retirer chirurgicalement.', [ 'lignes' => array_map( fn( $u ) => [ (int) substr( $u, 1 ), (int) substr( $u, 1 ) ], $unexplained ) ] );
	}
}
function wd_marker_explained( string $marker, array $ctx ): bool {
	if ( strcasecmp( $marker, 'WordPress' ) === 0 ) {
		return true;
	}
	$m = strtolower( preg_replace( '/[^a-z0-9]/i', '', $marker ) );
	if ( strlen( $m ) < 3 ) {
		// Un marqueur vide ou trop court (« # BEGIN --- ») n'explique rien : aiguille vide = piège.
		return false;
	}
	foreach ( $ctx['components'] as $c ) {
		$names = [ strtolower( preg_replace( '/[^a-z0-9]/i', '', $c['name'] ) ), strtolower( preg_replace( '/[^a-z0-9]/i', '', $c['slug'] ) ) ];
		foreach ( $names as $nm ) {
			if ( strlen( $nm ) >= 3 && ( wd_has( $m, $nm ) || wd_has( $nm, $m ) ) ) {
				return true;
			}
		}
	}
	return false;
}
function wd_rewrite_explained( string $dir, string $target, array $ctx ): bool {
	$t = ltrim( $target, '/' );
	if ( in_array( basename( $t ), WD_CORE_ROOT_FILES, true ) && strpos( $t, '/' ) === false ) {
		return true;
	}
	foreach ( $ctx['components'] as $c ) {
		if ( $c['dir'] !== '' && ( wd_has( $t, $c['dir'] . '/' ) || wd_has( $dir . $t, $c['dir'] . '/' ) ) ) {
			return true;
		}
	}
	return false;
}
function wd_prepend( WdReport $R, string $rel, int $line, string $what, string $target, array $ctx ): void {
	$t   = trim( $target, '"\'' );
	$bad = preg_match( '#^/(tmp|dev/shm|var/tmp)/|/uploads/|/\.[^/]+$|\.(ico|png|jpe?g|gif|txt|log|css|js)$#i', $t );
	$R->add( $bad ? WD_CONF : WD_PISTE, 'serveur.prepend', $rel, 'Directive ' . $what . ' : un fichier est exécuté avant chaque script', [ 'L' . $line . ' ' . $what . ' = ' . $t ], 'Lire le fichier ciblé, retirer la directive si elle n\'est pas justifiée (pare-feu applicatif documenté).', [ 'lignes' => [ [ $line, $line ] ] ] );
}
function wd_ini( WdReport $R, string $rel, string $content, array $ctx ): void {
	foreach ( preg_split( '/\r?\n/', $content ) as $no => $l ) {
		if ( preg_match( '/^\s*(auto_prepend_file|auto_append_file)\s*=\s*(\S+)/i', $l, $m ) && trim( $m[2], '"\'' ) !== '' && strtolower( trim( $m[2], '"\'' ) ) !== 'none' ) {
			wd_prepend( $R, $rel, $no + 1, $m[1], $m[2], $ctx );
		}
	}
}

// ============================================================ Composants et références

function wd_header( string $file, string $key ): string {
	$h = fopen( $file, 'rb' );
	if ( $h === false ) {
		return '';
	}
	$data = (string) fread( $h, 8192 );
	fclose( $h );
	return preg_match( '/^[ \t\/*#@]*' . preg_quote( $key, '/' ) . ':(.*)$/mi', $data, $m ) ? trim( $m[1] ) : '';
}
function wd_components( WdReport $R, string $root ): array {
	$out = [];
	foreach ( [ 'plugins', 'themes' ] as $kind ) {
		$base = $root . '/wp-content/' . $kind;
		if ( ! is_dir( $base ) ) {
			continue;
		}
		$entries = scandir( $base );
		if ( $entries === false ) {
			$R->add( WD_ILL, 'fichier.illisible', 'wp-content/' . $kind, 'Dossier illisible' );
			continue;
		}
		foreach ( $entries as $e ) {
			if ( $e === '.' || $e === '..' ) {
				continue;
			}
			$p = $base . '/' . $e;
			if ( $kind === 'plugins' && is_file( $p ) && preg_match( '/\.php$/', $e ) ) {
				$name = wd_header( $p, 'Plugin Name' );
				if ( $name !== '' ) {
					$out[] = [ 'kind' => 'plugin', 'slug' => substr( $e, 0, -4 ), 'name' => $name, 'version' => wd_header( $p, 'Version' ), 'dir' => 'wp-content/plugins/' . $e, 'single' => true ];
				}
				continue;
			}
			if ( ! is_dir( $p ) ) {
				continue;
			}
			$name = '';
			$ver  = '';
			if ( $kind === 'plugins' ) {
				foreach ( glob( $p . '/*.php' ) ?: [] as $f ) {
					$name = wd_header( $f, 'Plugin Name' );
					if ( $name !== '' ) {
						$ver = wd_header( $f, 'Version' );
						break;
					}
				}
			} elseif ( is_file( $p . '/style.css' ) ) {
				$name = wd_header( $p . '/style.css', 'Theme Name' );
				$ver  = wd_header( $p . '/style.css', 'Version' );
			}
			$c = [ 'kind' => $kind === 'plugins' ? 'plugin' : 'theme', 'slug' => $e, 'name' => $name, 'version' => $ver, 'dir' => 'wp-content/' . $kind . '/' . $e, 'single' => false ];
			if ( $name === '' ) {
				$R->add( WD_PISTE, 'composant.sans_entete', $c['dir'], 'Dossier d\'extension sans en-tête valide (aucun composant ne l\'explique)', [ 'entropie du nom : ' . round( wd_entropy( $e ), 2 ) ], 'Identifier l\'origine de ce dossier avant toute suppression.' );
			}
			$out[] = $c;
		}
	}
	return $out;
}
/** Référence de confiance : manifeste officiel, archive de la même version, copie saine fournie, inventaire antérieur. */
final class WdRefs {
	public $files = [];
	public $covered = [];
	public $sources = [];
	public $dirs = [];
	public $baseline = null;

	public function add( string $prefix, array $md5ByRel, string $source ): void {
		foreach ( $md5ByRel as $rel => $md5 ) {
			$this->files[ $rel ] = [ 'md5' => (array) $md5, 'src' => $source ];
		}
		$this->covered[ $prefix ] = $source;
	}
	public function coverage( string $rel ): ?string {
		foreach ( $this->dirs as $prefix => $d ) {
			if ( $prefix === '' || wd_starts( $rel, $prefix . '/' ) || $rel === $prefix ) {
				return 'copie de référence ' . $d;
			}
		}
		if ( $this->baseline !== null ) {
			return 'inventaire antérieur';
		}
		foreach ( $this->covered as $prefix => $src ) {
			if ( $prefix === '' ? strpos( $rel, '/' ) === false : wd_starts( $rel, $prefix . '/' ) ) {
				return $src;
			}
		}
		return null;
	}
	/** @return array{0:string,1:string} [état, source] état ∈ identique|differe|orphelin|sans_reference */
	/** md5 brut ET md5 du contenu normalisé en LF : un transfert FTP en mode ASCII change les fins de
	 * ligne sans altérer le code, il ne doit pas faire passer tout un arbre en « diffère ». */
	private static function variants( string $path, string $algo = 'md5' ): array {
		$raw = @file_get_contents( $path );
		if ( $raw === false ) {
			return [];
		}
		$norm = str_replace( "\r\n", "\n", $raw );
		$out  = [ hash( $algo, $raw ) ];
		if ( $norm !== $raw ) {
			$out[] = hash( $algo, $norm );
		}
		return $out;
	}
	public function check( string $root, string $rel, string $path ): array {
		foreach ( $this->dirs as $prefix => $d ) {
			if ( $prefix === '' || wd_starts( $rel, $prefix . '/' ) ) {
				$sub = $prefix === '' ? $rel : substr( $rel, strlen( $prefix ) + 1 );
				$ref = $d . '/' . $sub;
				if ( ! is_file( $ref ) ) {
					return [ 'orphelin', 'copie de référence' ];
				}
				return [ array_intersect( self::variants( $ref ), self::variants( $path ) ) ? 'identique' : 'differe', 'copie de référence' ];
			}
		}
		if ( isset( $this->files[ $rel ] ) ) {
			return [ array_intersect( self::variants( $path ), $this->files[ $rel ]['md5'] ) ? 'identique' : 'differe', $this->files[ $rel ]['src'] ];
		}
		if ( $this->baseline !== null ) {
			if ( ! isset( $this->baseline[ $rel ] ) ) {
				return [ 'orphelin', 'inventaire antérieur' ];
			}
			return [ in_array( $this->baseline[ $rel ], self::variants( $path, 'sha256' ), true ) ? 'identique' : 'differe', 'inventaire antérieur' ];
		}
		$cov = $this->coverage( $rel );
		return $cov !== null ? [ 'orphelin', $cov ] : [ 'sans_reference', '' ];
	}
}
function wd_build_refs( WdReport $R, string $root, array &$ctx, array $opt ): WdRefs {
	$refs = new WdRefs();
	foreach ( (array) ( $opt['ref'] ?? [] ) as $spec ) {
		if ( wd_has( $spec, '=' ) && ! preg_match( '#^[A-Za-z]:[\\\\/]#', $spec ) ) {
			[ $prefix, $dir ] = explode( '=', $spec, 2 );
		} else {
			[ $prefix, $dir ] = [ '', $spec ];
		}
		$dir = wd_norm( $dir );
		if ( ! is_dir( $dir ) ) {
			$R->add( WD_ILL, 'reference.illisible', $dir, 'Copie de référence introuvable' );
			continue;
		}
		$refs->dirs[ trim( wd_norm( $prefix ), '/' ) ] = $dir;
	}
	if ( ! empty( $opt['baseline'] ) ) {
		$raw = wd_read( $R, $opt['baseline'] );
		$j   = $raw !== null ? json_decode( $raw, true ) : null;
		if ( ! is_array( $j ) || ! isset( $j['fichiers'] ) ) {
			$R->add( WD_ILL, 'reference.illisible', $opt['baseline'], 'Inventaire antérieur illisible' );
		} else {
			$refs->baseline = $j['fichiers'];
		}
	}
	if ( ! wd_is_wp( $ctx ) ) {
		return $refs;
	}
	if ( ! empty( $opt['no-network'] ) ) {
		$R->notDone( 'manifestes officiels (cœur, extensions)', 'réseau désactivé (--no-network) : sans référence, les fichiers ne peuvent pas être expliqués', WD_HUMAN );
		return $refs;
	}
	$v = $ctx['wp_version'];
	$l = $ctx['locale'] ?: 'en_US';
	if ( $v === '' ) {
		$R->notDone( 'manifeste du cœur', 'version de WordPress illisible' );
	} else {
		[ $st, $j, $err ] = wd_json_get( $R, 'https://api.wordpress.org/core/checksums/1.0/?version=' . rawurlencode( $v ) . '&locale=' . rawurlencode( $l ) );
		if ( $st === 'ok' && empty( $j['checksums'] ) && $l !== 'en_US' ) {
			[ $st, $j, $err ] = wd_json_get( $R, 'https://api.wordpress.org/core/checksums/1.0/?version=' . rawurlencode( $v ) . '&locale=en_US' );
		}
		if ( $st === 'ok' && ! empty( $j['checksums'] ) && is_array( $j['checksums'] ) ) {
			$core = [];
			foreach ( $j['checksums'] as $rel => $md5 ) {
				if ( ! wd_starts( $rel, 'wp-content/' ) ) {
					$core[ $rel ] = $md5;
				}
			}
			$refs->add( '', array_filter( $core, fn( $r ) => strpos( $r, '/' ) === false, ARRAY_FILTER_USE_KEY ), 'manifeste officiel du cœur ' . $v );
			$refs->add( 'wp-admin', array_filter( $core, fn( $r ) => wd_starts( $r, 'wp-admin/' ), ARRAY_FILTER_USE_KEY ), 'manifeste officiel du cœur ' . $v );
			$refs->add( 'wp-includes', array_filter( $core, fn( $r ) => wd_starts( $r, 'wp-includes/' ), ARRAY_FILTER_USE_KEY ), 'manifeste officiel du cœur ' . $v );
			$ctx['core_root_files'] = array_keys( array_filter( $core, fn( $r ) => strpos( $r, '/' ) === false, ARRAY_FILTER_USE_KEY ) );
			$R->done( 'manifeste du cœur' );
		} elseif ( $st === 'injoignable' ) {
			$R->notDone( 'manifeste du cœur', 'API officielle injoignable (' . $err . ') : ce n\'est PAS une absence d\'écart' );
		} else {
			$R->notDone( 'manifeste du cœur', 'aucun manifeste publié pour ' . $v . ' (' . $l . ')', WD_HUMAN );
		}
	}
	foreach ( $ctx['components'] as $c ) {
		if ( $c['version'] === '' || isset( $refs->dirs[ $c['dir'] ] ) ) {
			continue;
		}
		if ( $c['kind'] === 'plugin' && ! $c['single'] ) {
			[ $st, $j, $err ] = wd_json_get( $R, 'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode( $c['slug'] ) . '/' . rawurlencode( $c['version'] ) . '.json' );
			if ( $st === 'ok' && ! empty( $j['files'] ) ) {
				$map = [];
				foreach ( $j['files'] as $f => $h ) {
					$map[ $c['dir'] . '/' . $f ] = $h['md5'] ?? [];
				}
				$refs->add( $c['dir'], $map, 'manifeste officiel ' . $c['slug'] . ' ' . $c['version'] );
				continue;
			}
			if ( $st === 'injoignable' ) {
				$R->notDone( 'manifeste ' . $c['dir'], 'API officielle injoignable (' . $err . ')' );
				continue;
			}
		}
		$zipUrl = 'https://downloads.wordpress.org/' . ( $c['kind'] === 'plugin' ? 'plugin' : 'theme' ) . '/' . rawurlencode( $c['slug'] ) . '.' . rawurlencode( $c['version'] ) . '.zip';
		$map    = $c['single'] ? null : wd_zip_reference( $R, $zipUrl, $c['dir'] );
		if ( is_array( $map ) ) {
			$refs->add( $c['dir'], $map, 'archive officielle ' . $c['slug'] . ' ' . $c['version'] );
		}
	}
	return $refs;
}
/** Référence dérivée : l'archive officielle de la MÊME version, téléchargée hors du site puis supprimée. */
function wd_zip_reference( WdReport $R, string $url, string $dir ): ?array {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return null;
	}
	[ $code, $body, , $err ] = wd_http( $R, $url, [], 60 );
	if ( $code !== 200 || $body === '' ) {
		return null;
	}
	$tmp = tempnam( sys_get_temp_dir(), 'wdref' );
	if ( $tmp === false || file_put_contents( $tmp, $body ) === false ) {
		$R->error( 'archive de référence non écrite en zone temporaire : ' . $url );
		return null;
	}
	$zip = new ZipArchive();
	$map = [];
	if ( $zip->open( $tmp ) === true ) {
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( substr( $name, -1 ) === '/' ) {
				continue;
			}
			$sub = preg_replace( '#^[^/]+/#', '', $name );
			$map[ $dir . '/' . $sub ] = md5( (string) $zip->getFromIndex( $i ) );
		}
		$zip->close();
	}
	if ( ! unlink( $tmp ) ) {
		$R->error( 'fichier temporaire non supprimé : ' . $tmp );
	}
	return $map ?: null;
}

// ============================================================ Analyse d'une racine WordPress (fichiers)

function wd_parse_config( WdReport $R, string $root ): array {
	$cfg = [ 'path' => '', 'consts' => [], 'prefix' => null, 'db' => [] ];
	$candidates = [ $root . '/wp-config.php' ];
	if ( ! is_file( $root . '/../wp-settings.php' ) ) {
		$candidates[] = dirname( $root ) . '/wp-config.php';
	}
	foreach ( $candidates as $p ) {
		if ( is_file( $p ) ) {
			$cfg['path'] = $p;
			break;
		}
	}
	if ( $cfg['path'] === '' ) {
		$R->notDone( 'lecture de wp-config.php', 'fichier introuvable', WD_HUMAN );
		return $cfg;
	}
	$code = wd_read( $R, $cfg['path'] );
	if ( $code === null ) {
		$R->notDone( 'lecture de wp-config.php', 'fichier illisible' );
		return $cfg;
	}
	$T = wd_tokens( $code );
	$n = count( $T );
	for ( $i = 0; $i < $n; $i++ ) {
		if ( $T[ $i ][0] === T_STRING && strtolower( $T[ $i ][1] ) === 'define' && wd_is_call( $T, $i ) ) {
			[ $args ] = wd_args( $T, $i + 1 );
			if ( count( $args ) >= 2 ) {
				$ev   = new WdEval( $T, [] );
				$name = $ev->run( $args[0][0], $args[0][1] );
				$raw  = wd_text( $T, $args[1][0], $args[1][1] );
				$val  = ( new WdEval( $T, [] ) )->run( $args[1][0], $args[1][1] );
				if ( $name !== null ) {
					$cfg['consts'][ $name ] = [ 'valeur' => $val ?? $raw, 'ligne' => $T[ $i ][2] ];
				}
			}
		}
		if ( $T[ $i ][0] === T_VARIABLE && $T[ $i ][1] === '$table_prefix' && isset( $T[ $i + 2 ] ) && $T[ $i + 1 ][0] === '=' ) {
			$v = ( new WdEval( $T, [] ) )->run( $i + 2, wd_expr_end( $T, $i + 2 ) );
			if ( $v !== null ) {
				$cfg['prefix'] = $v;
			} else {
				$R->add( WD_ILL, 'config.prefixe', 'wp-config.php', 'Préfixe de table non statique : fournir --prefix', [ wd_text( $T, $i, wd_expr_end( $T, $i + 2 ) ) ] );
			}
		}
	}
	foreach ( [ 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST' ] as $k ) {
		$cfg['db'][ $k ] = isset( $cfg['consts'][ $k ] ) ? (string) $cfg['consts'][ $k ]['valeur'] : null;
	}
	if ( $cfg['prefix'] === null ) {
		$R->add( WD_ILL, 'config.prefixe', 'wp-config.php', '$table_prefix introuvable : aucun préfixe par défaut n\'est supposé' );
	}
	return $cfg;
}
function wd_walk( WdReport $R, string $root, array &$ctx ): array {
	$files = [];
	$stack = [ $root ];
	while ( $stack ) {
		$dir = array_pop( $stack );
		$h   = opendir( $dir );
		if ( $h === false ) {
			$R->add( WD_ILL, 'fichier.illisible', wd_rel( $root, $dir ), 'Dossier illisible (droits ou open_basedir)' );
			continue;
		}
		$entries = [];
		while ( ( $e = readdir( $h ) ) !== false ) {
			if ( $e !== '.' && $e !== '..' ) {
				$entries[] = $e;
			}
		}
		closedir( $h );
		sort( $entries, SORT_STRING );
		$subs = [];
		foreach ( $entries as $e ) {
			$p = $dir . '/' . $e;
			if ( isset( $ctx['exclude'][ wd_norm( $p ) ] ) ) {
				continue;
			}
			if ( is_link( $p ) ) {
				$target = readlink( $p );
				$real   = realpath( $p );
				if ( $real === false || ! wd_starts( wd_norm( $real ), $root ) ) {
					$R->add( WD_PISTE, 'fichier.lien_externe', wd_rel( $root, $p ), 'Lien symbolique vers l\'extérieur de la racine', [ 'cible : ' . ( $target === false ? '?' : $target ) ] );
				}
				continue;
			}
			if ( is_dir( $p ) ) {
				if ( $e === '.git' || $e === '.svn' ) {
					// Signale, mais on descend quand meme : un .php cache dans un faux depot est un classique.
					$R->add( WD_DURC, 'fichier.depot_expose', wd_rel( $root, $p ), 'Dépôt de versions présent dans l\'arborescence servie', [], 'Interdire l\'accès HTTP ou le retirer du docroot.' );
				}
				$subs[] = $p;
			} elseif ( is_file( $p ) ) {
				$files[] = $p;
			} else {
				// Ni dossier ni fichier régulier : FIFO, socket, périphérique. Ne pas ouvrir (bloquerait).
				$R->add( WD_PISTE, 'fichier.non_regulier', wd_rel( $root, $p ), 'Entrée qui n\'est ni un dossier ni un fichier régulier', [ 'type : ' . ( @filetype( $p ) ?: '?' ) ], 'Un tube nommé ou un socket dans le docroot est anormal : identifier son origine.' );
			}
		}
		foreach ( array_reverse( $subs ) as $s ) {
			$stack[] = $s;
		}
	}
	return $files;
}
/** Chemins exclus du parcours (l'outil déposé et son dossier de travail) : annoncés dans le rapport, jamais silencieux. */
function wd_excludes( WdReport $R, array $opt ): array {
	$out = [];
	foreach ( (array) ( $opt['exclude'] ?? [] ) as $list ) {
		foreach ( explode( ',', (string) $list ) as $p ) {
			$real = realpath( trim( $p ) );
			if ( $real !== false ) {
				$out[ wd_norm( $real ) ] = true;
			}
		}
	}
	if ( $out ) {
		$R->contexte['exclus'] = array_keys( $out );
	}
	return $out;
}
function wd_rel( string $root, string $path ): string {
	$p = wd_norm( $path );
	return $p === $root ? '.' : ltrim( substr( $p, strlen( $root ) ), '/' );
}
/** Où l'architecture WordPress attend-elle du code exécutable ? */
function wd_location( string $rel, array $ctx ): string {
	$l = strtolower( $rel );
	if ( ! wd_is_wp( $ctx ) ) {
		return preg_match( WD_DATA_DIRS, $l ) ? 'donnees_projet' : 'projet';
	}
	if ( strpos( $l, '/' ) === false ) {
		return in_array( $rel, $ctx['core_root_files'], true ) || $l === 'wp-config.php' ? 'coeur' : 'racine_non_wp';
	}
	if ( wd_starts( $l, 'wp-admin/' ) || wd_starts( $l, 'wp-includes/' ) ) {
		return 'coeur';
	}
	if ( preg_match( '#^wp-content/(plugins|themes|mu-plugins)/#', $l ) ) {
		return 'extension';
	}
	if ( preg_match( '#^wp-content/[^/]+$#', $l ) ) {
		return in_array( basename( $l ), WD_DROPINS, true ) ? 'dropin' : ( basename( $l ) === 'index.php' ? 'silence' : 'wpcontent_racine' );
	}
	if ( wd_starts( $l, 'wp-content/uploads/' ) ) {
		return 'uploads';
	}
	if ( wd_starts( $l, 'wp-content/languages/' ) ) {
		return 'langues';
	}
	if ( wd_starts( $l, 'wp-content/' ) ) {
		return 'donnees';
	}
	return 'hors_wp';
}
function wd_scan_root( WdReport $R, string $root, array $opt, array &$ctx ): void {
	$scan = wd_scan_prepare( $R, $root, $opt, $ctx );
	wd_scan_files( $R, $root, $opt, $ctx, $scan );
	wd_scan_finish( $R, $root, $opt, $ctx, $scan );
}
/** Projet WordPress (cœur reconnu) ou projet PHP quelconque : l'architecture attendue en dépend. */
function wd_project_type( string $root ): string {
	return is_file( $root . '/wp-includes/version.php' ) ? 'wordpress' : 'php';
}
function wd_is_wp( array $ctx ): bool {
	return ( $ctx['type'] ?? 'wordpress' ) === 'wordpress';
}
function wd_scan_prepare( WdReport $R, string $root, array $opt, array &$ctx ): array {
	$R->at( 'racine ' . $root );
	$ctx['root']            = $root;
	$ctx['type']            = wd_project_type( $root );
	$ctx['core_root_files'] = WD_CORE_ROOT_FILES;
	$ctx['hooks']           = [];
	$ctx['types']           = [];
	$ctx['literals']        = [];
	$ctx['flagged']         = [];
	$ctx['db_exec']         = [];
	$ctx['code_complete']   = false;
	$ctx['exclude']         = wd_excludes( $R, $opt );
	$vp  = $root . '/wp-includes/version.php';
	$ver = is_file( $vp ) ? (string) wd_read( $R, $vp ) : '';
	$ctx['wp_version'] = preg_match( '/\$wp_version\s*=\s*[\'"]([^\'"]+)/', $ver, $m ) ? $m[1] : '';
	$ctx['locale']     = preg_match( '/\$wp_local_package\s*=\s*[\'"]([^\'"]+)/', $ver, $m ) ? $m[1] : '';
	if ( wd_is_wp( $ctx ) ) {
		$ctx['config']     = wd_parse_config( $R, $root );
		$ctx['components'] = wd_components( $R, $root );
	} else {
		$ctx['config']     = [ 'path' => '', 'consts' => [], 'prefix' => null, 'db' => [] ];
		$ctx['components'] = [];
		$R->add( WD_HUMAN, 'projet.non_wordpress', '.', 'Projet PHP sans cœur WordPress : l\'architecture attendue, l\'intégrité par provenance et la base ne sont pas vérifiables sans référence', [], 'Fournir une copie saine ou le dépôt du projet à la même version (--ref=CHEMIN) ; exporter la base à part si elle doit être relue.' );
	}
	$R->contexte['racines'][ $root ] = [ 'type' => $ctx['type'], 'wordpress' => $ctx['wp_version'] ?: '?', 'langue' => $ctx['locale'] ?: 'en_US (par défaut)', 'prefixe' => $ctx['config']['prefix'], 'wp_config' => $ctx['config']['path'] ];
	$refs = wd_build_refs( $R, $root, $ctx, $opt );
	if ( wd_is_wp( $ctx ) ) {
		wd_config_checks( $R, $ctx );
	}
	$files = wd_walk( $R, $root, $ctx );
	$R->stats['fichiers'] = ( $R->stats['fichiers'] ?? 0 ) + count( $files );
	$offset = (int) ( $opt['offset'] ?? 0 );
	return [ 'refs' => $refs, 'files' => $files, 'offset' => $offset, 'fi' => $offset, 'start' => null, 'byComponent' => [], 'pluginMd5' => null, 'inventory' => [], 'execCount' => 0 ];
}
/**
 * Analyse les fichiers à partir de $scan['fi']. Rend false quand l'échéance $deadline (reprise dans une
 * requête suivante) est atteinte ; --max-seconds, lui, clôt l'analyse comme incomplète.
 */
function wd_scan_files( WdReport $R, string $root, array $opt, array &$ctx, array &$scan, float $deadline = 0.0 ): bool {
	$R->at( 'racine ' . $root );
	$files  = $scan['files'];
	$refs   = $scan['refs'];
	$budget = (int) ( $opt['max-seconds'] ?? 0 );
	$scan['start'] = $scan['start'] ?? microtime( true );
	$start  = $scan['start'];
	$maxPhp = (int) ( $opt['max-file-size'] ?? 8388608 );
	$byComponent = &$scan['byComponent'];
	$pluginMd5   = &$scan['pluginMd5'];
	$inventory   = &$scan['inventory'];
	$execCount   = &$scan['execCount'];
	$wantInv   = ! empty( $opt['inventory-out'] );
	$total     = count( $files );
	for ( $fi = $scan['fi']; $fi < $total; $fi++ ) {
		$scan['fi'] = $fi;
		if ( $deadline > 0 && microtime( true ) > $deadline ) {
			return false;
		}
		if ( $budget > 0 && microtime( true ) - $start > $budget ) {
			$R->incomplet[] = [ 'racine' => $root, 'offset_suivant' => $fi, 'total' => $total ];
			$R->add( WD_HUMAN, 'analyse.incomplete', $root, 'Analyse des fichiers interrompue par le budget de temps : relancer avec --offset=' . $fi, [ $fi . ' / ' . $total . ' fichiers traités' ] );
			break;
		}
		$path = $files[ $fi ];
		$rel  = wd_rel( $root, $path );
		$base = basename( $rel );
		$size = filesize( $path );
		if ( $size === false ) {
			$R->add( WD_ILL, 'fichier.illisible', $rel, 'Taille illisible' );
			continue;
		}
		if ( $wantInv ) {
			$h = hash_file( 'sha256', $path );
			if ( $h === false ) {
				$R->add( WD_ILL, 'fichier.illisible', $rel, 'Fichier illisible (inventaire)' );
			} else {
				$inventory[ $rel ] = $h;
			}
		}
		if ( $base === '.htaccess' ) {
			$c = wd_read( $R, $path );
			$c === null ? $R->add( WD_ILL, 'fichier.illisible', $rel, 'Fichier illisible' ) : wd_htaccess( $R, $rel, $c, $ctx );
			continue;
		}
		if ( $base === '.user.ini' || $base === 'php.ini' ) {
			$c = wd_read( $R, $path );
			$c === null ? $R->add( WD_ILL, 'fichier.illisible', $rel, 'Fichier illisible' ) : wd_ini( $R, $rel, $c, $ctx );
			continue;
		}
		$loc     = wd_location( $rel, $ctx );
		$phpExt  = (bool) preg_match( WD_PHP_EXT, $base );
		$content = null;
		if ( ! $phpExt ) {
			if ( preg_match( '/\.(sql|sql\.gz|gz|zip|tar|tgz|7z|rar|bak|old|orig|save|swp|backup)$|~$/i', $base ) && $loc !== 'extension' ) {
				$R->add( WD_PISTE, 'fichier.oublie', $rel, 'Archive, dump ou sauvegarde exposé dans l\'arborescence', [ $size . ' octets' ], 'Vérifier son contenu (secrets, base), puis le sortir du docroot.' );
			} elseif ( $loc === 'racine_non_wp' ) {
				$ctx['root_misc'][] = $rel;
			}
			if ( $size === 0 ) {
				continue;
			}
			$content = wd_sniff( $R, $path, $size );
			if ( $content === null ) {
				$R->add( WD_ILL, 'fichier.illisible', $rel, 'Fichier illisible' );
				continue;
			}
			$isElf = wd_starts( $content, "\x7fELF" );
			$isPe  = wd_starts( $content, 'MZ' ) && strlen( $content ) > 64 && ( $pe = unpack( 'V', substr( $content, 60, 4 ) )[1] ) < strlen( $content ) - 4 && substr( $content, $pe, 4 ) === "PE\0\0";
			if ( ( $isElf || $isPe ) && $loc !== 'extension' ) {
				$R->add( WD_PISTE, 'emplacement.binaire', $rel, 'Binaire exécutable hors de toute extension', [ $isElf ? 'format ELF' : 'format PE', $size . ' octets' ], 'Identifier sa provenance ; un binaire n\'a rien à faire dans le docroot.' );
			}
			if ( stripos( $content, '<?php' ) === false ) {
				continue;
			}
			$content = substr( $content, (int) stripos( $content, '<?php' ) );
		}
		$execCount++;
		[ $state, $src ] = $refs->check( $root, $rel, $path );
		if ( $state === 'identique' ) {
			if ( $size <= $maxPhp && ( $c = wd_read( $R, $path ) ) !== null ) {
				wd_absorb_decl( $ctx, wd_php_analyze( $c, 0, true ) );
			}
			continue;
		}
		if ( $content === null ) {
			if ( $size > $maxPhp ) {
				$R->add( WD_HUMAN, 'fichier.trop_gros', $rel, 'Fichier PHP trop volumineux pour l\'analyse par jetons', [ $size . ' octets' ], 'Relire à la main ou relancer avec --max-file-size.' );
				continue;
			}
			$content = wd_read( $R, $path );
			if ( $content === null ) {
				$R->add( WD_ILL, 'fichier.illisible', $rel, 'Fichier illisible' );
				continue;
			}
		}
		$t0 = microtime( true );
		$P  = wd_php_analyze( $content );
		$dt = microtime( true ) - $t0;
		if ( $dt > 2 ) {
			$R->stats['analyses_lentes'][ $rel ] = round( $dt, 1 );
		}
		wd_absorb_decl( $ctx, $P );
		wd_file_findings( $R, $rel, $loc, $state, $src, $P, $content, $ctx, $pluginMd5, $root );
		if ( $state === 'sans_reference' ) {
			$comp = wd_component_of( $rel );
			$byComponent[ $comp ] = ( $byComponent[ $comp ] ?? 0 ) + 1;
		}
		if ( $P->dbExec ) {
			foreach ( $P->dbExec as [ $line, $keys ] ) {
				$ctx['db_exec'][] = [ $rel, $line, $keys ];
			}
		}
	}
	$scan['fi'] = $total;
	return true;
}
function wd_scan_finish( WdReport $R, string $root, array $opt, array &$ctx, array $scan ): void {
	$R->at( 'racine ' . $root );
	// Complet seulement si tout a été lu : ni budget dépassé, ni tranche sautée (--offset).
	$ctx['code_complete'] = ! $R->incomplet && $scan['offset'] === 0;
	$R->stats['fichiers_executables'] = ( $R->stats['fichiers_executables'] ?? 0 ) + $scan['execCount'];
	if ( ! wd_is_wp( $ctx ) && $scan['byComponent'] ) {
		$R->add( WD_HUMAN, 'integrite.sans_reference', '.', 'Aucune référence de confiance pour ce projet : ' . array_sum( $scan['byComponent'] ) . ' fichier(s) exécutable(s) non expliqué(s)', array_slice( array_map( fn( $d, $n ) => $d . ' : ' . $n, array_keys( $scan['byComponent'] ), $scan['byComponent'] ), 0, 30 ), 'Fournir une copie saine ou le dépôt du projet à la même version via --ref=CHEMIN ; sans elle, statut NEEDS_HUMAN.' );
	}
	foreach ( wd_is_wp( $ctx ) ? $scan['byComponent'] : [] as $comp => $count ) {
		if ( in_array( $comp, [ 'coeur', 'racine' ], true ) ) {
			continue;
		}
		$R->add( WD_HUMAN, 'integrite.sans_reference', $comp, 'Aucune référence de confiance pour ce composant : ' . $count . ' fichier(s) exécutable(s) non expliqué(s)', [], 'Fournir une copie de la MÊME version (compte éditeur ou sauvegarde saine) via --ref=' . $comp . '=CHEMIN ; sans elle, statut NEEDS_HUMAN.' );
	}
	if ( ! empty( $ctx['root_misc'] ) && wd_is_wp( $ctx ) ) {
		$R->add( WD_DURC, 'racine.fichiers_non_wp', '.', 'Fichiers non WordPress à la racine servie', array_slice( $ctx['root_misc'], 0, 30 ), 'Identifier chacun, sortir du docroot ce qui n\'est pas servi volontairement.' );
	}
	$mu = glob( $root . '/wp-content/mu-plugins/*' ) ?: [];
	if ( $mu ) {
		$R->add( WD_HUMAN, 'composant.mu_plugins', 'wp-content/mu-plugins', 'Extensions obligatoires (mu-plugins) : chargées sans activation, sans manifeste public', array_map( 'basename', $mu ), 'Faire confirmer chaque fichier par le client ou le comparer à sa source.' );
	}
	$drop = [];
	foreach ( WD_DROPINS as $d ) {
		if ( is_file( $root . '/wp-content/' . $d ) ) {
			$drop[] = $d;
		}
	}
	if ( $drop ) {
		$R->add( WD_HUMAN, 'composant.dropins', 'wp-content', 'Drop-ins présents (chargés par le cœur avant les extensions)', $drop, 'Comparer chacun au fichier fourni par l\'extension qui l\'a posé.' );
	}
	if ( is_file( $root . '/.maintenance' ) ) {
		$age = time() - (int) filemtime( $root . '/.maintenance' );
		$R->add( WD_PISTE, 'maj.maintenance', '.maintenance', 'Fichier de maintenance présent : bloque le site et les mises à jour', [ 'âge apparent ' . round( $age / 60 ) . ' min (mtime non fiable)' ], 'Supprimer s\'il n\'y a pas de mise à jour en cours.' );
	}
	if ( ! empty( $opt['inventory-out'] ) ) {
		$ctx['inventory'] = $scan['inventory'];
	}
	$R->done( 'analyse des fichiers ' . $root );
}
function wd_component_of( string $rel ): string {
	if ( preg_match( '#^(wp-content/(plugins|themes)/[^/]+)#', $rel, $m ) ) {
		return $m[1];
	}
	if ( wd_starts( $rel, 'wp-content/mu-plugins/' ) ) {
		return 'wp-content/mu-plugins';
	}
	if ( wd_starts( $rel, 'wp-admin/' ) || wd_starts( $rel, 'wp-includes/' ) ) {
		return 'coeur';
	}
	return strpos( $rel, '/' ) === false ? 'racine' : dirname( $rel );
}
function wd_absorb_decl( array &$ctx, WdPhp $P ): void {
	$ctx['hooks']    += $P->hooks;
	$ctx['types']    += $P->types;
	$ctx['literals'] += $P->literals;
	foreach ( $P->updateFilters as $f ) {
		$ctx['update_filters'][] = $f;
	}
}
function wd_file_findings( WdReport $R, string $rel, string $loc, string $state, string $src, WdPhp $P, string $content, array &$ctx, ?array &$pluginMd5, string $root ): void {
	$preuves = [];
	$conf    = false;
	foreach ( $P->flows as $f ) {
		$preuves[] = 'L' . $f['ligne'] . ' [' . $f['capacite'] . '] ' . $f['detail'] . ( $f['sources'] ? ' (sources : ' . implode( ', ', $f['sources'] ) . ')' : '' );
		if ( $f['niveau'] === WD_CONF ) {
			$conf = true;
		}
	}
	$urls = array_values( array_unique( $P->urls ) );
	if ( $P->remoteOut && ( $urls || $P->netDecoded ) ) {
		$conf      = true;
		$preuves[] = 'relaie vers le visiteur un contenu distant dont l\'adresse est masquée';
	}
	foreach ( array_slice( $urls, 0, 5 ) as $u ) {
		$preuves[] = 'adresse décodée : ' . $u;
	}
	if ( $rel === 'index.php' && wd_is_wp( $ctx ) && ! $P->empty && wd_index_violation( $content ) ) {
		$conf      = true;
		$preuves[] = 'index.php racine : du code s\'ajoute au chargeur standard (define + require de wp-blog-header.php)';
	}
	$markers = array_keys( $P->markers );
	$capsTxt = $P->caps ? 'capacités : ' . implode( ', ', array_keys( $P->caps ) ) : 'aucune capacité sensible repérée';
	foreach ( array_slice( $P->decoded, 0, 3 ) as $d ) {
		$preuves[] = 'décodé (' . implode( '>', (array) ( $d['schemas'] ?? [] ) ) . ') : ' . $d['apercu'];
	}
	$flowPiste = ! $conf && $P->flows;
	if ( $conf ) {
		$ctx['flagged'][ $rel ] = WD_CONF;
		$R->add( WD_CONF, 'code.capacite', $rel, 'Code qui exécute ou relaie une entrée non fiable', $preuves, 'Mettre en quarantaine hors docroot après copie de preuve ; chercher comment il a été déposé (journaux, dates ctime).', [ 'etat_reference' => $state, 'marqueurs' => $markers ] );
	} elseif ( $flowPiste ) {
		$ctx['flagged'][ $rel ] = WD_PISTE;
		$R->add( WD_PISTE, 'code.capacite', $rel, 'Capacité sensible alimentée par une source indirecte', $preuves, 'Vérifier l\'origine de la donnée (option, requête) et si le fichier est expliqué par sa référence.', [ 'etat_reference' => $state, 'marqueurs' => $markers ] );
	}
	if ( $state === 'differe' ) {
		$ctx['flagged'][ $rel ] = $ctx['flagged'][ $rel ] ?? WD_PISTE;
		$st = $loc === 'coeur' ? WD_CONF : WD_PISTE;
		$R->add( $st, 'integrite.differe', $rel, 'Diffère de sa référence (' . $src . ')', [ $capsTxt ], 'Remplacer par la version de référence, puis comparer les deux (diff --strip-trailing-cr) pour comprendre l\'ajout.' );
	} elseif ( $state === 'orphelin' ) {
		$ctx['flagged'][ $rel ] = $ctx['flagged'][ $rel ] ?? WD_PISTE;
		$R->add( WD_PISTE, 'integrite.orphelin', $rel, 'Fichier exécutable absent de sa référence (' . $src . ')', [ $capsTxt ], 'Un composant officiel ne l\'a pas livré : le lire, puis le retirer s\'il n\'est pas expliqué.' );
	}
	$explainedCopy = false;
	if ( in_array( $loc, [ 'donnees', 'wpcontent_racine', 'uploads' ], true ) && ! $P->empty && ! $P->pureData ) {
		if ( $pluginMd5 === null ) {
			$pluginMd5 = [];
			foreach ( $ctx['components'] as $c ) {
				if ( $c['kind'] === 'plugin' && ! $c['single'] ) {
					$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $c['dir'], FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD );
					foreach ( $it as $f ) {
						if ( preg_match( WD_PHP_EXT, $f->getFilename() ) ) {
							$pluginMd5[ md5_file( $f->getPathname() ) ] = $c['dir'];
						}
					}
				}
			}
		}
		$h = md5( $content );
		$explainedCopy = isset( $pluginMd5[ $h ] ) || isset( $pluginMd5[ md5_file( $root . '/' . $rel ) ] );
	}
	$unexpected = [
		'racine_non_wp'    => 'PHP à la racine servie qui n\'appartient pas au cœur',
		'uploads'          => 'Code exécutable dans le dossier des médias',
		'donnees'          => 'Code exécutable dans un dossier de données de wp-content',
		'wpcontent_racine' => 'Code exécutable à la racine de wp-content hors drop-in',
		'langues'          => 'Code exécutable dans les traductions (attendu : données pures)',
		'hors_wp'          => 'Code exécutable hors de l\'arborescence WordPress',
		'donnees_projet'   => 'Code exécutable dans un dossier de fichiers déposés ou de médias',
	];
	if ( isset( $unexpected[ $loc ] ) && ! $P->empty && ! ( $loc !== 'racine_non_wp' && $loc !== 'hors_wp' && $P->pureData ) && ! $explainedCopy ) {
		$ctx['flagged'][ $rel ] = $ctx['flagged'][ $rel ] ?? WD_PISTE;
		$R->add( WD_PISTE, 'emplacement.inattendu', $rel, $unexpected[ $loc ], [ $capsTxt ], 'L\'architecture n\'attend pas de code ici : l\'expliquer (qui l\'a posé, pourquoi) ou le retirer.' );
	}
	if ( ! $conf && count( $markers ) >= 2 && ! in_array( $markers, [ [ 'horodatage_force' ] ], true ) ) {
		$R->add( WD_PISTE, 'code.obfusque', $rel, 'Code dont la forme cherche à masquer le sens', [ 'marqueurs : ' . implode( ', ', $markers ), $capsTxt ], 'Décoder et lire ; ces marqueurs servent à prioriser, pas à conclure.' );
	} elseif ( ! $conf && isset( $P->markers['processus_detache'] ) && ( $state !== 'sans_reference' || isset( $unexpected[ $loc ] ) ) ) {
		$R->add( WD_PISTE, 'code.processus_detache', $rel, 'Script conçu pour continuer après la fin de la requête', [ $capsTxt ] );
	}
	foreach ( $P->defines as $name => [ $val, $line ] ) {
		if ( $rel !== 'wp-config.php' ) {
			$ctx['update_consts'][] = [ $name, $val, $rel . ':' . $line ];
		}
	}
}
function wd_index_violation( string $code ): bool {
	$T = wd_tokens( $code );
	foreach ( $T as $i => $t ) {
		if ( $t[0] === T_STRING && wd_is_call( $T, $i ) && ! in_array( strtolower( $t[1] ), [ 'define', 'dirname' ], true ) ) {
			return true;
		}
		if ( in_array( $t[0], [ T_EVAL, T_ECHO, T_PRINT, T_FUNCTION, T_VARIABLE, T_EXIT, T_INLINE_HTML ], true ) && ! ( $t[0] === T_INLINE_HTML && trim( $t[1] ) === '' ) ) {
			return true;
		}
	}
	return ! preg_match( '/wp-blog-header\.php/', $code );
}
function wd_config_checks( WdReport $R, array $ctx ): void {
	$c = $ctx['config']['consts'];
	$bool = fn( $k ) => isset( $c[ $k ] ) && in_array( strtolower( trim( (string) $c[ $k ]['valeur'] ) ), [ '1', 'true' ], true );
	if ( ! $bool( 'DISALLOW_FILE_EDIT' ) ) {
		$R->add( WD_DURC, 'config.durcissement', 'wp-config.php', 'DISALLOW_FILE_EDIT absent : l\'éditeur de fichiers de l\'admin reste un vecteur d\'écriture de code' );
	}
	foreach ( WD_UPDATE_CONSTS as $k ) {
		if ( ! isset( $c[ $k ] ) || $k === 'DISALLOW_FILE_EDIT' ) {
			continue;
		}
		$v = strtolower( trim( (string) $c[ $k ]['valeur'] ) );
		$blocks = ( $k === 'AUTOMATIC_UPDATER_DISABLED' && in_array( $v, [ '1', 'true' ], true ) ) || ( $k === 'WP_AUTO_UPDATE_CORE' && in_array( $v, [ '0', 'false', '' ], true ) ) || ( $k === 'DISALLOW_FILE_MODS' && in_array( $v, [ '1', 'true' ], true ) ) || ( $k === 'WP_HTTP_BLOCK_EXTERNAL' && in_array( $v, [ '1', 'true' ], true ) ) || $k === 'FS_METHOD';
		if ( $blocks ) {
			$R->add( WD_DURC, 'maj.constante', 'wp-config.php', 'Constante qui agit sur les mises à jour : ' . $k, [ 'L' . $c[ $k ]['ligne'] . ' = ' . $c[ $k ]['valeur'] ], 'Vérifier qu\'un processus de mise à jour existe ailleurs ; sinon c\'est un facilitateur d\'intrusion.' );
		}
	}
	$salts = [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ];
	$weak  = [];
	foreach ( $salts as $s ) {
		if ( ! isset( $c[ $s ] ) || strlen( (string) $c[ $s ]['valeur'] ) < 32 ) {
			$weak[] = $s;
		}
	}
	if ( $weak ) {
		$R->add( WD_DURC, 'config.cles', 'wp-config.php', 'Clés ou sels absents ou courts', $weak, 'Régénérer toutes les clés après l\'incident (invalide les cookies forgés).' );
	}
}

// ============================================================ Base de données (mysqli ou dump hors ligne)

final class WdDump {
	private $file;
	private $R;
	public $creates = [];
	public $autoinc = [];
	public function __construct( string $file, WdReport $R ) {
		$this->file = $file;
		$this->R    = $R;
	}
	public function prescan(): bool {
		$h = fopen( $this->file, 'rb' );
		if ( $h === false ) {
			return false;
		}
		$cur = null;
		while ( ( $line = fgets( $h ) ) !== false ) {
			if ( $cur === null && preg_match( '/^CREATE TABLE\s+`?([^`\s(]+)`?/i', $line, $m ) ) {
				$cur = $m[1];
				$this->creates[ $cur ] = [];
				continue;
			}
			if ( $cur !== null ) {
				if ( preg_match( '/^\s*`([^`]+)`\s/', $line, $m ) ) {
					$this->creates[ $cur ][] = $m[1];
				} elseif ( preg_match( '/^\)/', $line ) ) {
					if ( preg_match( '/AUTO_INCREMENT=(\d+)/i', $line, $m ) ) {
						$this->autoinc[ $cur ] = (int) $m[1];
					}
					$cur = null;
				}
			}
		}
		fclose( $h );
		return true;
	}
	public function scan( callable $onRow ): bool {
		$h = fopen( $this->file, 'rb' );
		if ( $h === false ) {
			return false;
		}
		$buf   = '';
		$quote = '';
		$esc   = false;
		while ( ( $line = fgets( $h ) ) !== false ) {
			if ( $buf === '' ) {
				if ( ! preg_match( '/^INSERT\s/i', $line ) ) {
					continue;
				}
			}
			$buf .= $line;
			$len = strlen( $line );
			for ( $i = 0; $i < $len; ) {
				if ( $quote === '' ) {
					$j = strcspn( $line, "'\"`", $i );
					$i += $j;
					if ( $i >= $len ) {
						break;
					}
					$quote = $line[ $i ];
					$i++;
				} else {
					$j = strcspn( $line, $quote . '\\', $i );
					$i += $j;
					if ( $i >= $len ) {
						break;
					}
					if ( $line[ $i ] === '\\' ) {
						$i += 2;
						continue;
					}
					$quote = '';
					$i++;
				}
			}
			if ( $quote === '' && preg_match( '/;\s*$/', $line ) ) {
				$this->insert( $buf, $onRow );
				$buf = '';
			}
		}
		fclose( $h );
		if ( $buf !== '' ) {
			$this->R->error( 'dump : instruction INSERT tronquée en fin de fichier' );
		}
		return true;
	}
	private function insert( string $sql, callable $onRow ): void {
		if ( ! preg_match( '/^INSERT\s+(?:IGNORE\s+)?INTO\s+`?([^`\s(]+)`?\s*(?:\(([^)]*)\))?\s*VALUES\s*/is', $sql, $m, PREG_OFFSET_CAPTURE ) ) {
			return;
		}
		$table = $m[1][0];
		$cols  = isset( $m[2] ) && $m[2][0] !== '' ? array_map( fn( $c ) => trim( $c, " `\n\r\t" ), explode( ',', $m[2][0] ) ) : ( $this->creates[ $table ] ?? [] );
		$i     = strlen( $m[0][0] );
		$len   = strlen( $sql );
		while ( $i < $len ) {
			while ( $i < $len && $sql[ $i ] !== '(' ) {
				if ( $sql[ $i ] === ';' ) {
					return;
				}
				$i++;
			}
			$i++;
			$row = [];
			while ( $i < $len ) {
				while ( $i < $len && ( $sql[ $i ] === ' ' || $sql[ $i ] === "\n" || $sql[ $i ] === "\r" || $sql[ $i ] === "\t" ) ) {
					$i++;
				}
				$ch = $sql[ $i ] ?? '';
				if ( $ch === "'" ) {
					$i++;
					$v = '';
					while ( $i < $len ) {
						$j  = strcspn( $sql, "'\\", $i );
						$v .= substr( $sql, $i, $j );
						$i += $j;
						if ( $i >= $len ) {
							break;
						}
						if ( $sql[ $i ] === '\\' ) {
							$n  = $sql[ $i + 1 ] ?? '';
							$v .= [ '0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'Z' => "\x1a", '%' => '\\%', '_' => '\\_' ][ $n ] ?? $n;
							$i += 2;
						} elseif ( ( $sql[ $i + 1 ] ?? '' ) === "'" ) {
							$v .= "'";
							$i += 2;
						} else {
							$i++;
							break;
						}
					}
					$row[] = $v;
				} else {
					$j   = strcspn( $sql, ',)', $i );
					$tok = trim( substr( $sql, $i, $j ) );
					$i  += $j;
					if ( strcasecmp( $tok, 'NULL' ) === 0 ) {
						$row[] = null;
					} elseif ( preg_match( '/^0x([0-9a-f]*)$/i', $tok, $hm ) ) {
						$row[] = (string) hex2bin( strlen( $hm[1] ) % 2 ? '0' . $hm[1] : $hm[1] );
					} else {
						$row[] = $tok;
					}
				}
				while ( $i < $len && $sql[ $i ] !== ',' && $sql[ $i ] !== ')' ) {
					$i++;
				}
				if ( ( $sql[ $i ] ?? '' ) === ')' ) {
					$i++;
					break;
				}
				$i++;
			}
			$assoc = count( $cols ) === count( $row ) ? array_combine( $cols, $row ) : $row;
			$onRow( $table, $assoc );
			while ( $i < $len && $sql[ $i ] !== ',' && $sql[ $i ] !== ';' ) {
				$i++;
			}
			if ( ( $sql[ $i ] ?? ';' ) === ';' ) {
				return;
			}
			$i++;
		}
	}
}

final class WdDb {
	private $R;
	private $prefix;
	private $ctx;
	private $opt;
	public $site = '';
	private $users = [];
	private $roles = [];
	private $sessions = [];
	private $apppw = [];
	private $posts = [];
	private $postmetaIds = [];
	private $termRel = [];
	private $links = [];
	private $extRefs = [];
	private $extDates = [];
	private $cols = [];
	private $autoinc = [];
	private $codeStores = [];
	private $secrets = [];
	private $cron = null;
	private $options = [];
	private $optionNames = [];
	private $markupHits = [];
	private $evidenceDates = [];
	private $tablesSeen = [];

	public function __construct( WdReport $R, string $prefix, array $ctx, array $opt ) {
		$this->R      = $R;
		$this->prefix = $prefix;
		$this->ctx    = $ctx;
		$this->opt    = $opt;
	}
	public function create( string $table, array $cols, ?int $ai ): void {
		$this->cols[ $table ]    = $cols;
		$this->autoinc[ $table ] = $ai;
	}
	private function short( string $table ): ?string {
		return wd_starts( $table, $this->prefix ) ? substr( $table, strlen( $this->prefix ) ) : null;
	}
	public function row( string $table, array $row ): void {
		$this->tablesSeen[ $table ] = ( $this->tablesSeen[ $table ] ?? 0 ) + 1;
		$t = $this->short( $table );
		switch ( $t ) {
			case 'options':
				$this->option( (string) ( $row['option_name'] ?? '' ), (string) ( $row['option_value'] ?? '' ), (string) ( $row['autoload'] ?? '' ) );
				return;
			case 'users':
				$pass = (string) ( $row['user_pass'] ?? '' );
				$fmt  = wd_starts( $pass, '$wp$' ) ? 'bcrypt-wp' : ( wd_starts( $pass, '$P$' ) ? 'phpass' : ( preg_match( '/^[0-9a-f]{32}$/i', $pass ) ? 'md5-brut' : ( wd_starts( $pass, '$2y$' ) ? 'bcrypt' : 'autre' ) ) );
				$this->users[ (int) $row['ID'] ] = [ 'id' => (int) $row['ID'], 'login' => (string) $row['user_login'], 'email' => (string) $row['user_email'], 'registered' => (string) $row['user_registered'], 'pass' => $fmt, 'activation' => (string) ( $row['user_activation_key'] ?? '' ) !== '', 'display' => (string) ( $row['display_name'] ?? '' ) ];
				return;
			case 'usermeta':
				$k = (string) $row['meta_key'];
				$v = (string) $row['meta_value'];
				$u = (int) $row['user_id'];
				if ( $k === $this->prefix . 'capabilities' ) {
					$caps = wd_unserialize( $v );
					$this->roles[ $u ] = is_array( $caps ) ? array_keys( array_filter( $caps ) ) : [ 'illisible' ];
				} elseif ( $k === 'session_tokens' ) {
					$s = wd_unserialize( $v );
					$this->sessions[ $u ] = is_array( $s ) ? array_values( array_unique( array_filter( array_map( fn( $x ) => is_array( $x ) ? (string) ( $x['ip'] ?? '' ) : '', $s ) ) ) ) : [];
				} elseif ( $k === '_application_passwords' ) {
					$a = wd_unserialize( $v );
					if ( is_array( $a ) && $a ) {
						$this->apppw[ $u ] = array_map( fn( $x ) => is_array( $x ) ? ( ( $x['name'] ?? '?' ) . ' créé ' . ( isset( $x['created'] ) ? gmdate( 'Y-m-d H:i', (int) $x['created'] ) : '?' ) ) : '?', $a );
					}
				} else {
					$this->cell( $table, 'umeta_id', $row, [ 'meta_value' ] );
				}
				return;
			case 'posts':
				$id = (int) $row['ID'];
				$this->posts[ $id ] = [ (string) $row['post_type'], (string) $row['post_status'], (string) $row['post_date'], (int) $row['post_author'], substr( (string) $row['post_title'], 0, 90 ), (string) $row['post_name'] ];
				$this->cell( $table, 'ID', $row, [ 'post_content', 'post_title', 'post_excerpt', 'post_content_filtered' ], (string) $row['post_type'] === 'post' || (string) $row['post_type'] === 'page' ? 'contenu' : 'technique' );
				return;
			case 'postmeta':
				$pid = (int) $row['post_id'];
				$this->postmetaIds[ $pid ] = ( $this->postmetaIds[ $pid ] ?? 0 ) + 1;
				$this->cell( $table, 'meta_id', $row, [ 'meta_value' ] );
				return;
			case 'term_relationships':
				$oid = (int) $row['object_id'];
				$this->termRel[ $oid ] = ( $this->termRel[ $oid ] ?? 0 ) + 1;
				return;
			case 'links':
				$this->links[ (int) $row['link_id'] ] = true;
				return;
			case 'comments':
				$this->cell( $table, 'comment_ID', $row, [ 'comment_content', 'comment_author_url' ], 'contenu' );
				return;
		}
		$cols = array_keys( $row );
		foreach ( [ 'object_id', 'post_id' ] as $ref ) {
			if ( in_array( $ref, $cols, true ) && ( ! in_array( 'object_type', $cols, true ) || in_array( (string) $row['object_type'], [ 'post', 'page' ], true ) ) ) {
				$this->extRefs[ $table ][ (int) $row[ $ref ] ] = true;
				break;
			}
		}
		foreach ( [ 'created_at', 'created', 'date_created', 'modified', 'updated_at' ] as $dc ) {
			if ( isset( $row[ $dc ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $row[ $dc ] ) ) {
				$this->extDates[ $table ][] = (string) $row[ $dc ];
				break;
			}
		}
		$pk = $cols[0] ?? 'id';
		$this->cell( $table, $pk, $row, array_keys( array_filter( $row, fn( $v ) => is_string( $v ) && strlen( $v ) >= 12 && ! is_numeric( $v ) ) ), 'extension' );
	}

	private function option( string $name, string $value, string $autoload ): void {
		$this->optionNames[ $name ] = [ strlen( $value ), $autoload ];
		if ( in_array( $name, array_merge( WD_SENSITIVE_OPTIONS, [ 'cron', 'core_updater.lock', 'auto_updater.lock', 'auto_update_plugins', 'auto_update_themes', 'auto_update_core_major', 'auto_update_core_minor', 'auto_update_core_dev', $this->prefix . 'user_roles', 'recently_activated' ] ), true ) ) {
			$this->options[ $name ] = $value;
		}
		if ( $name === 'siteurl' ) {
			$this->site = wd_host( $value );
		}
		$isTransient = wd_starts( $name, '_transient_' ) || wd_starts( $name, '_site_transient_' );
		if ( ! $isTransient && $value !== '' && preg_match( '/(^|_)(key|keys|token|secret|pass|password|salt|nonce|auth|api)(_|$)/i', $name ) && ! in_array( $value, [ '0', '1', 'a:0:{}', 'yes', 'no' ], true ) ) {
			$this->secrets[] = $name . ' : ' . wd_mask( $value );
		} elseif ( ! $isTransient && preg_match( '/-----BEGIN [A-Z ]*(KEY|CERTIFICATE)-----/', $value ) ) {
			$this->secrets[] = $name . ' : clé cryptographique ' . wd_mask( $value );
		}
		$this->cell( $this->prefix . 'options', 'option_name', [ 'option_name' => $name, 'option_value' => $value ], [ 'option_value' ], wd_starts( $name, 'widget_' ) || wd_starts( $name, 'theme_mods_' ) ? 'widget' : ( $isTransient ? 'transient' : 'option' ) );
	}

	/** Analyse de cellules : code stocké, balisage actif, blocs encodés. */
	private function cell( string $table, string $pkCol, array $row, array $cols, string $kind = 'option' ): void {
		foreach ( $cols as $col ) {
			$v = $row[ $col ] ?? null;
			if ( ! is_string( $v ) || strlen( $v ) < 12 ) {
				continue;
			}
			if ( ! preg_match( '/<\?php|<script|<iframe|http-equiv|javascript:|\bon(error|load)\s*=|\b(eval|assert|create_function|base64_decode|gzinflate|gzuncompress|str_rot13|shell_exec|passthru|proc_open|popen|system|exec|file_put_contents|fromCharCode|atob|document\.write|add_action|add_filter|function)\s*\(|[A-Za-z0-9+\/]{40,}={0,2}|;\s*\r?\n/i', $v ) ) {
				continue;
			}
			$res = wd_value( $v );
			$id  = $table . '#' . ( $row[ $pkCol ] ?? '?' ) . ':' . $col;
			$ref = [ 'ligne' => [ 'table' => $table, 'cle' => $pkCol, 'valeur' => (string) ( $row[ $pkCol ] ?? '' ), 'colonne' => $col ] ];
			$date = '';
			foreach ( [ 'modified', 'post_modified', 'post_date', 'created', 'created_at' ] as $dc ) {
				if ( isset( $row[ $dc ] ) && preg_match( '/^\d{4}-/', (string) $row[ $dc ] ) ) {
					$date = (string) $row[ $dc ];
					break;
				}
			}
			$conf = false;
			$pr   = [];
			foreach ( $res['flows'] as $f ) {
				$pr[] = '[' . $f['capacite'] . '] ' . $f['detail'];
				$conf = $conf || $f['niveau'] === WD_CONF;
			}
			if ( $res['code'] && $kind !== 'transient' ) {
				$store = $table . ':' . $col;
				$this->codeStores[ $store ]['n']      = ( $this->codeStores[ $store ]['n'] ?? 0 ) + 1;
				$this->codeStores[ $store ]['ids'][]  = (string) ( $row[ $pkCol ] ?? '?' );
			}
			if ( $conf ) {
				$this->R->add( WD_CONF, 'base.code_execute', $id, 'Code stocké en base qui exécute une entrée non fiable ou décodée', array_merge( $pr, $date ? [ 'date de la ligne : ' . $date ] : [], array_map( fn( $d ) => 'décodé : ' . $d['apercu'], array_slice( $res['decoded'], 0, 2 ) ) ), 'Neutraliser la ligne (désactiver puis supprimer) APRÈS avoir fermé le vecteur et tué les processus persistants.', $ref );
				if ( $date ) {
					$this->evidenceDates[] = [ $date, $id ];
					$this->R->event( $date, 'code_en_base', $id, true );
				}
			} elseif ( $res['flows'] && $kind !== 'transient' ) {
				$this->R->add( WD_PISTE, 'base.code_capacite', $id, 'Code stocké en base avec une capacité sensible', $pr, '', $ref );
			}
			foreach ( $res['markup'] as [ $what, $host, $detail, $ilvl ] ) {
				$this->markupHits[] = [ $id, $what, $host, $detail, $ilvl, $kind, $date, $ref ];
			}
			foreach ( $res['decoded'] as $d ) {
				if ( ! empty( $d['vocabulaire'] ) && ! $conf && $kind !== 'transient' ) {
					$this->R->add( WD_PISTE, 'base.valeur_decodee', $id, 'Valeur encodée dont le contenu décodé parle d\'exécution', [ 'vocabulaire : ' . implode( ', ', $d['vocabulaire'] ), 'décodé (' . implode( '>', $d['schemas'] ) . ') : ' . $d['apercu'] ], 'Identifier l\'extension qui écrit cette valeur ; un journal d\'agent distant ou une file de commandes révèle des tentatives.', $ref );
				}
			}
		}
	}

	public function finish(): void {
		$R = $this->R;
		$R->at( 'base' );
		foreach ( [ 'options', 'users', 'usermeta', 'posts' ] as $t ) {
			if ( ! isset( $this->tablesSeen[ $this->prefix . $t ] ) && ! isset( $this->cols[ $this->prefix . $t ] ) ) {
				$R->add( WD_ILL, 'base.table_absente', $this->prefix . $t, 'Table attendue introuvable avec le préfixe « ' . $this->prefix . ' »' );
			}
		}
		$this->checkOptions();
		$window = $this->window();
		$suspects = $this->checkUsers( $window );
		$this->checkPosts( $window, $suspects );
		$this->checkOrphans( $window );
		$this->checkTables();
		$this->checkMarkup( $window );
		foreach ( $this->codeStores as $store => $info ) {
			$R->add( WD_HUMAN, 'base.stockage_de_code', $store, 'Colonne qui stocke du code exécutable (' . $info['n'] . ' ligne(s))', [ 'lignes : ' . implode( ', ', array_slice( $info['ids'], 0, 40 ) ) ], 'Relire chaque ligne active ; l\'extension qui l\'exécute doit être expliquée (installée par qui, quand).' );
		}
		if ( $this->secrets ) {
			$R->add( WD_DURC, 'base.secrets', $this->prefix . 'options', 'Secrets stockés en base (valeurs masquées) : à faire tourner si la base a pu être lue', array_slice( $this->secrets, 0, 60 ), 'Régénérer chaque clé côté service (reconnecter les agents distants, clés d\'API).' );
		}
		foreach ( $this->ctx['db_exec'] ?? [] as [ $file, $line, $keys ] ) {
			$R->add( WD_PISTE, 'graphe.base_vers_puits', $file . ':' . $line, 'Un puits d\'exécution lit la base' . ( $keys ? ' (' . $keys . ')' : '' ), [], 'Vérifier le contenu de la valeur lue : c\'est un point de réinjection.' );
		}
		$R->done( 'analyse de la base' );
	}

	private function window(): array {
		$since = $this->opt['since'] ?? '';
		$src   = '--since (preuve la plus ancienne fournie)';
		if ( $since === '' ) {
			$cands = [];
			foreach ( $this->users as $u ) {
				if ( $this->strongSignals( $u ) ) {
					$cands[] = [ $u['registered'], 'compte ' . $u['login'] ];
				}
			}
			foreach ( $this->evidenceDates as $e ) {
				$cands[] = $e;
			}
			usort( $cands, fn( $a, $b ) => strcmp( $a[0], $b[0] ) );
			if ( $cands ) {
				[ $since, $what ] = $cands[0];
				$src = 'preuve forte la plus ancienne : ' . $what;
			}
		}
		$this->R->contexte['fenetre'] = $since === '' ? 'inconnue (fournir --since=AAAA-MM-JJ, date de la preuve la plus ancienne)' : [ 'debut' => $since, 'source' => $src ];
		if ( $since === '' ) {
			$this->R->notDone( 'fenêtre d\'incident', 'aucune preuve datée : les contrôles par fenêtre (comptes, contenus) sont sautés', WD_HUMAN );
		}
		return [ $since ];
	}
	private function strongSignals( array $u ): array {
		$s = [];
		$domain = strtolower( substr( strrchr( $u['email'], '@' ) ?: '', 1 ) );
		if ( $domain !== '' && preg_match( WD_RESERVED_DOMAIN, $domain ) ) {
			$s[] = 'domaine réservé (RFC 2606/6761/6762) : ' . $domain;
		}
		if ( $u['pass'] === 'md5-brut' ) {
			$s[] = 'mot de passe en md5 brut : inséré directement en base';
		}
		foreach ( $this->users as $o ) {
			if ( $o['id'] === $u['id'] || strcmp( $o['registered'], $u['registered'] ) >= 0 || ! $this->isAdmin( $o['id'] ) ) {
				continue;
			}
			$a = strtolower( $u['login'] );
			$b = strtolower( $o['login'] );
			if ( strlen( $b ) >= 4 && wd_starts( $a, $b ) && preg_match( '/^[a-z0-9]{4,}$/', substr( $a, strlen( $b ) ) ) && preg_match( '/\d/', substr( $a, strlen( $b ) ) ) ) {
				$s[] = 'sosie de « ' . $o['login'] . ' » (préfixe + suffixe aléatoire)';
				break;
			}
			if ( strlen( $b ) >= 5 && $a !== $b && wd_levenshtein( $a, $b ) <= 2 ) {
				$s[] = 'sosie de « ' . $o['login'] . ' » (distance ' . wd_levenshtein( $a, $b ) . ')';
				break;
			}
		}
		return $s;
	}
	private function isAdmin( int $id ): bool {
		return in_array( 'administrator', $this->roles[ $id ] ?? [], true );
	}
	private function checkUsers( array $window ): array {
		$R = $this->R;
		[ $since ] = $window;
		$legit = array_filter( array_map( 'trim', explode( ',', (string) ( $this->opt['legit-admins'] ?? '' ) ) ) );
		$suspects = [];
		$unconfirmed = [];
		$inWindowOthers = 0;
		foreach ( $this->users as $u ) {
			$admin  = $this->isAdmin( $u['id'] );
			$roles  = $this->roles[ $u['id'] ] ?? [];
			$strong = $this->strongSignals( $u );
			$inWin  = $since !== '' && strcmp( $u['registered'], $since ) >= 0;
			$reasons = $strong;
			if ( $inWin ) {
				$reasons[] = 'créé dans la fenêtre d\'incident';
			}
			if ( $legit && $admin && ! in_array( $u['login'], $legit, true ) ) {
				$reasons[] = 'administrateur absent de la liste fournie par le client';
			}
			if ( $legit && in_array( $u['login'], $legit, true ) && ! $strong ) {
				continue;
			}
			$elevated = $admin || array_intersect( $roles, [ 'editor', 'shop_manager' ] );
			if ( $strong || ( $inWin && $elevated ) || ( $legit && $admin && ! in_array( $u['login'], $legit, true ) ) ) {
				$suspects[ $u['id'] ] = [
					'id'        => $u['id'],
					'login'     => $u['login'],
					'domaine'   => strtolower( substr( strrchr( $u['email'], '@' ) ?: '', 1 ) ),
					'cree'      => $u['registered'],
					'roles'     => $roles,
					'hash'      => $u['pass'],
					'statut'    => $strong ? WD_CONF : WD_PISTE,
					'raisons'   => $reasons,
					'sessions_ip' => $this->sessions[ $u['id'] ] ?? [],
				];
				$R->event( $u['registered'], 'compte', $u['login'], (bool) $strong );
			} elseif ( $inWin ) {
				$inWindowOthers++;
			} elseif ( $admin && ! $legit ) {
				$unconfirmed[] = $u['login'] . ' (créé ' . $u['registered'] . ', hash ' . $u['pass'] . ')';
			}
			if ( $admin && $u['activation'] ) {
				$R->add( WD_PISTE, 'comptes.reinitialisation', 'user#' . $u['id'], 'Réinitialisation de mot de passe en cours sur un administrateur', [ $u['login'] ] );
			}
			if ( isset( $this->apppw[ $u['id'] ] ) ) {
				$R->add( isset( $suspects[ $u['id'] ] ) ? WD_CONF : WD_HUMAN, 'comptes.mot_de_passe_application', 'user#' . $u['id'], 'Mots de passe d\'application (survivent au changement de mot de passe)', array_merge( [ $u['login'] ], $this->apppw[ $u['id'] ] ), 'Révoquer tous ceux que le client ne reconnaît pas.', [ 'ids' => [ $u['id'] ] ] );
			}
		}
		$conf  = array_filter( $suspects, fn( $s ) => $s['statut'] === WD_CONF );
		$piste = array_filter( $suspects, fn( $s ) => $s['statut'] === WD_PISTE );
		$fmt   = fn( $s ) => '#' . $s['id'] . ' ' . $s['login'] . ' @' . $s['domaine'] . ' créé ' . $s['cree'] . ' : ' . implode( ' ; ', $s['raisons'] );
		if ( $conf ) {
			$R->add( WD_CONF, 'comptes.suspects', $this->prefix . 'users', count( $conf ) . ' compte(s) aux signaux forts', array_map( $fmt, array_slice( array_values( $conf ), 0, 25 ) ), 'Simulation puis validation humaine ligne à ligne avant suppression (et réattribution des contenus).', [ 'ids' => array_keys( $conf ) ] );
		}
		if ( $piste ) {
			$R->add( WD_PISTE, 'comptes.fenetre', $this->prefix . 'users', count( $piste ) . ' compte(s) à privilèges créés dans la fenêtre ou non reconnus', array_map( $fmt, array_slice( array_values( $piste ), 0, 25 ) ), 'Faire confirmer par le client ; la fenêtre part de la preuve la plus ancienne, pas de la date de la faille publique.', [ 'ids' => array_keys( $piste ) ] );
		}
		if ( $inWindowOthers ) {
			$R->add( WD_HUMAN, 'comptes.fenetre_sans_privilege', $this->prefix . 'users', $inWindowOthers . ' compte(s) sans privilège créés dans la fenêtre' );
		}
		if ( $unconfirmed ) {
			$R->add( WD_HUMAN, 'comptes.a_confirmer', $this->prefix . 'users', 'Administrateurs antérieurs à la fenêtre, à faire confirmer par le client (fournir --legit-admins)', $unconfirmed );
		}
		$R->contexte['comptes'] = [ 'total' => count( $this->users ), 'suspects' => count( $suspects ) ];
		$this->roleChecks();
		return $suspects;
	}
	private function roleChecks(): void {
		$roles = wd_unserialize( $this->options[ $this->prefix . 'user_roles' ] ?? '' );
		if ( ! is_array( $roles ) ) {
			$this->R->add( WD_ILL, 'base.roles', $this->prefix . 'user_roles', 'Définition des rôles illisible ou absente' );
			return;
		}
		$power = [ 'manage_options', 'edit_plugins', 'install_plugins', 'activate_plugins', 'edit_users', 'create_users', 'promote_users', 'edit_themes', 'update_core', 'unfiltered_upload' ];
		foreach ( $roles as $slug => $def ) {
			if ( $slug === 'administrator' || ! is_array( $def ) ) {
				continue;
			}
			$caps = array_keys( array_filter( (array) ( $def['capabilities'] ?? [] ) ) );
			$bad  = array_intersect( $caps, $power );
			if ( $bad ) {
				$this->R->add( WD_PISTE, 'base.role_eleve', 'rôle ' . $slug, 'Rôle non administrateur doté de capacités d\'administration', [ implode( ', ', $bad ) ], 'Vérifier que ce réglage est voulu par le client.' );
			}
		}
	}
	private function checkPosts( array $window, array $suspects ): void {
		$R = $this->R;
		[ $since ] = $window;
		$types = [];
		$byAuthor = [];
		$deface = [];
		$statusOdd = [];
		$frontier = null;
		$antidated = [];
		$declared = $this->ctx['types'] ?? [];
		$complete = ! empty( $this->ctx['code_complete'] );
		ksort( $this->posts );
		$maxBefore = 0;
		foreach ( $this->posts as $id => [ $type, $status, $date, $author, $title, $name ] ) {
			$inWin = $since !== '' && strcmp( $date, $since ) >= 0;
			$types[ $type ]['total'] = ( $types[ $type ]['total'] ?? 0 ) + 1;
			$types[ $type ]['statuts'][ $status ] = ( $types[ $type ]['statuts'][ $status ] ?? 0 ) + 1;
			if ( $since !== '' ) {
				if ( $inWin ) {
					$types[ $type ]['fenetre'] = ( $types[ $type ]['fenetre'] ?? 0 ) + 1;
					$types[ $type ]['ids'][]   = $id;
					if ( $frontier === null ) {
						$frontier = $id;
					}
				} else {
					$types[ $type ]['avant'][] = $date;
					if ( $frontier !== null && $id > $frontier && $type !== 'revision' && $status !== 'auto-draft' ) {
						$antidated[] = '#' . $id . ' ' . $type . ' daté ' . $date;
					}
				}
			}
			if ( isset( $suspects[ $author ] ) ) {
				$byAuthor[ $id ] = '#' . $id . ' ' . $type . '/' . $status . ' « ' . $title . ' » /' . $name . '/';
				$R->event( $date, 'contenu_suspect', '#' . $id . ' ' . $type, true );
			}
			if ( $status === 'publish' && preg_match( '/hack(ed)?[\s_-]*by|owned[\s_-]*by|defaced|pwned/i', $title . ' ' . $name ) ) {
				$deface[ $id ] = '#' . $id . ' ' . $type . ' « ' . $title . ' »';
			}
			if ( in_array( $type, WD_CORE_POST_TYPES, true ) && ! in_array( $status, WD_CORE_STATUSES, true ) ) {
				$statusOdd[ $type . '/' . $status ] = ( $statusOdd[ $type . '/' . $status ] ?? 0 ) + 1;
			}
		}
		if ( $byAuthor ) {
			$R->add( WD_CONF, 'contenus.auteur_suspect', $this->prefix . 'posts', count( $byAuthor ) . ' contenu(s) créés par des comptes suspects', array_slice( array_values( $byAuthor ), 0, 30 ), 'Supprimer après validation (défacement, pages de spam) et purger les caches.', [ 'ids' => array_keys( $byAuthor ) ] );
		}
		if ( $deface ) {
			$R->add( WD_CONF, 'contenus.defacement', $this->prefix . 'posts', 'Contenu public de défacement (titre explicite, même sans script)', array_slice( array_values( $deface ), 0, 30 ), '', [ 'ids' => array_keys( $deface ) ] );
		}
		foreach ( $statusOdd as $k => $count ) {
			$R->add( WD_PISTE, 'contenus.statut_anormal', $this->prefix . 'posts', 'Statut que le cœur ne définit pas : ' . $k, [ $count . ' ligne(s)' ] );
		}
		foreach ( $types as $type => $info ) {
			if ( in_array( $type, WD_CORE_POST_TYPES, true ) ) {
				continue;
			}
			if ( ! isset( $declared[ $type ] ) ) {
				$st = $complete ? WD_PISTE : WD_HUMAN;
				$R->add( $st, 'contenus.type_non_explique', $this->prefix . 'posts', 'Type de contenu qu\'aucun code installé ne déclare : ' . $type, [ $info['total'] . ' ligne(s) ; statuts : ' . json_encode( $info['statuts'], JSON_UNESCAPED_UNICODE ), 'dans la fenêtre : ' . ( $info['fenetre'] ?? 0 ) ], $complete ? 'Artefact d\'exploitation probable : vérifier dates et auteurs.' : 'Code non analysé en entier : impossible de dire si un composant le déclare.' );
			}
		}
		if ( $since !== '' ) {
			$sinceTs = strtotime( $since );
			$winDays = max( 1, ( time() - $sinceTs ) / 86400 );
			foreach ( $types as $type => $info ) {
				$inWin = $info['fenetre'] ?? 0;
				if ( $inWin < 10 ) {
					continue;
				}
				$before = array_filter( $info['avant'] ?? [], fn( $d ) => strtotime( $d ) >= $sinceTs - 365 * 86400 );
				$rateBefore = count( $before ) / 365;
				$rateIn     = $inWin / $winDays;
				if ( $rateIn >= 5 * max( $rateBefore, 0.01 ) ) {
					$ids = $info['ids'];
					$R->add( WD_PISTE, 'contenus.pic_fenetre', $this->prefix . 'posts', 'Pic de créations « ' . $type . ' » pendant la fenêtre', [ $inWin . ' ligne(s) en ' . round( $winDays ) . ' j contre ' . count( $before ) . ' sur les 365 j précédents', 'ID ' . min( $ids ) . ' à ' . max( $ids ) ], 'Comparer au rythme normal du site ; un pic concomitant de l\'intrusion signale un artefact d\'exploitation.', [ 'type' => $type, 'nombre' => $inWin ] );
				}
			}
			$R->contexte['frontiere_id_contenus'] = $frontier;
			if ( $antidated ) {
				$R->add( WD_PISTE, 'contenus.antidates', $this->prefix . 'posts', 'Contenus d\'ID postérieur à la frontière mais datés d\'avant la fenêtre (antidatage possible)', array_slice( $antidated, 0, 20 ) );
			}
		}
	}
	private function checkOrphans( array $window ): void {
		$R    = $this->R;
		$ids  = $this->posts;
		if ( ! $ids ) {
			return;
		}
		$orph = array_diff_key( $this->postmetaIds, $ids );
		if ( $orph ) {
			$R->add( WD_PISTE, 'base.postmeta_orphelines', $this->prefix . 'postmeta', array_sum( $orph ) . ' métadonnée(s) rattachée(s) à des contenus inexistants', [ count( $orph ) . ' post_id fantôme(s), de ' . min( array_keys( $orph ) ) . ' à ' . max( array_keys( $orph ) ) ], 'Souvent le reste d\'un nettoyage partiel ou d\'une injection : vérifier avant purge.', [ 'nombre' => array_sum( $orph ) ] );
		}
		$tr = array_diff_key( $this->termRel, $ids, $this->links );
		if ( $tr ) {
			$R->add( WD_PISTE, 'base.relations_orphelines', $this->prefix . 'term_relationships', array_sum( $tr ) . ' relation(s) de termes vers des objets inexistants', [], '', [ 'nombre' => array_sum( $tr ) ] );
		}
		[ $since ] = $window;
		foreach ( $this->extRefs as $table => $refs ) {
			$o = array_diff_key( $refs, $ids );
			if ( $o ) {
				$R->add( WD_PISTE, 'base.table_extension_orphelines', $table, count( $o ) . ' ligne(s) d\'une table d\'extension pointent vers des contenus inexistants', [ 'ID fantômes de ' . min( array_keys( $o ) ) . ' à ' . max( array_keys( $o ) ) ], 'Purger avec l\'outil de l\'extension ou après validation.', [ 'nombre' => count( $o ) ] );
			}
		}
		if ( $since !== '' ) {
			foreach ( $this->extDates as $table => $dates ) {
				$in = count( array_filter( $dates, fn( $d ) => strcmp( $d, $since ) >= 0 ) );
				if ( $in > 0 ) {
					$this->R->stats['lignes_extension_dans_fenetre'][ $table ] = $in;
				}
			}
		}
	}
	private function checkTables(): void {
		$lit = $this->ctx['literals'] ?? [];
		$complete = ! empty( $this->ctx['code_complete'] );
		$orphans  = [];
		foreach ( array_keys( $this->cols + $this->tablesSeen ) as $table ) {
			$short = $this->short( $table );
			if ( $short === null ) {
				$this->R->stats['tables_autre_prefixe'][] = $table;
				continue;
			}
			if ( in_array( $short, WD_CORE_TABLES, true ) ) {
				continue;
			}
			$explained = false;
			foreach ( [ $short, $table ] as $cand ) {
				if ( isset( $lit[ $cand ] ) ) {
					$explained = true;
					break;
				}
			}
			if ( ! $explained && $complete ) {
				foreach ( array_keys( $lit ) as $l ) {
					if ( strlen( $l ) >= 4 && wd_ends( $short, (string) $l ) && strlen( (string) $l ) * 2 >= strlen( $short ) ) {
						$explained = true;
						break;
					}
				}
			}
			if ( ! $explained ) {
				$orphans[] = $table . ' (' . ( $this->tablesSeen[ $table ] ?? 0 ) . ' ligne(s))';
			}
		}
		if ( $orphans ) {
			$this->R->add( $complete ? WD_PISTE : WD_HUMAN, 'base.table_non_expliquee', $this->prefix . '*', count( $orphans ) . ' table(s) qu\'aucun code installé ne référence', $orphans, $complete ? 'Reste d\'une extension supprimée ou table posée par un tiers : identifier avant toute purge.' : 'Code du site non analysé : relancer avec --root pour expliquer ces tables.', [ 'tables' => $orphans, 'noms' => array_map( fn( $o ) => substr( $o, 0, (int) strpos( $o, ' (' ) ), $orphans ) ] );
		}
		foreach ( $this->autoinc as $table => $ai ) {
			if ( $ai && isset( $this->tablesSeen[ $table ] ) && $ai > 1000 && $this->tablesSeen[ $table ] * 20 < $ai ) {
				$this->R->stats['ecarts_auto_increment'][ $table ] = [ 'auto_increment' => $ai, 'lignes' => $this->tablesSeen[ $table ] ];
			}
		}
	}
	private function checkMarkup( array $window ): void {
		[ $since ] = $window;
		foreach ( $this->markupHits as [ $id, $what, $host, $detail, $lvl, $kind, $date, $ref ] ) {
			$sameSite = $host !== '' && $this->site !== '' && ( $host === $this->site || wd_ends( $host, '.' . $this->site ) );
			if ( ( $what === 'script_externe' && ( $host === '' || $sameSite ) ) || ( $what === 'iframe_cachee' && $sameSite ) ) {
				continue;
			}
			$recent = $since !== '' && $date !== '' && strcmp( $date, $since ) >= 0;
			if ( $kind === 'contenu' && $lvl !== WD_CONF && ! $recent ) {
				continue;
			}
			if ( $kind === 'transient' && $lvl !== WD_CONF ) {
				continue;
			}
			$st = $lvl === WD_CONF ? WD_CONF : WD_PISTE;
			$this->R->add( $st, 'base.balisage_actif', $id, 'Balisage actif stocké en base (' . $what . ')', [ ( $host !== '' ? 'hôte : ' . $host . ' ; ' : '' ) . $detail ], 'Vérifier que le client l\'a ajouté ; sinon le retirer et purger les caches.', $ref );
		}
	}
	private function checkOptions(): void {
		$R = $this->R;
		$o = $this->options;
		foreach ( [ 'siteurl', 'home' ] as $k ) {
			if ( ! isset( $o[ $k ] ) ) {
				$R->add( WD_ILL, 'base.option', $k, 'Option absente' );
				continue;
			}
			if ( ! preg_match( '#^https?://[^\s"\'<>]+$#i', $o[ $k ] ) ) {
				$R->add( WD_CONF, 'base.option', $k, 'Adresse du site altérée', [ wd_clean( $o[ $k ] ) ] );
			}
		}
		if ( isset( $o['siteurl'], $o['home'] ) && wd_host( $o['siteurl'] ) !== wd_host( $o['home'] ) && ! wd_ends( wd_host( $o['home'] ), wd_host( $o['siteurl'] ) ) && ! wd_ends( wd_host( $o['siteurl'] ), wd_host( $o['home'] ) ) ) {
			$R->add( WD_PISTE, 'base.option', 'home', 'siteurl et home pointent vers des hôtes différents', [ wd_host( $o['siteurl'] ) . ' / ' . wd_host( $o['home'] ) ] );
		}
		$root = $this->ctx['root'] ?? '';
		$ap = wd_unserialize( $o['active_plugins'] ?? '' );
		if ( is_array( $ap ) ) {
			foreach ( $ap as $p ) {
				$p = (string) $p;
				if ( wd_has( $p, '..' ) || wd_starts( $p, '/' ) || preg_match( '#^[a-z]:#i', $p ) ) {
					$R->add( WD_CONF, 'base.extension_active', 'active_plugins', 'Extension active hors du dossier des extensions', [ $p ], '', [ 'extension' => $p ] );
				} elseif ( $root !== '' && ! is_file( $root . '/wp-content/plugins/' . $p ) ) {
					$R->add( WD_PISTE, 'base.extension_active', 'active_plugins', 'Extension déclarée active mais absente du disque', [ $p ], '', [ 'extension' => $p ] );
				}
			}
			$R->contexte['extensions_actives'] = array_values( array_map( 'strval', $ap ) );
		}
		foreach ( [ 'template', 'stylesheet' ] as $k ) {
			if ( isset( $o[ $k ] ) && $root !== '' && ! is_dir( $root . '/wp-content/themes/' . $o[ $k ] ) ) {
				$R->add( WD_PISTE, 'base.option', $k, 'Thème actif introuvable sur le disque', [ wd_clean( $o[ $k ] ) ] );
			}
		}
		if ( ( $o['users_can_register'] ?? '0' ) === '1' && in_array( $o['default_role'] ?? '', [ 'administrator', 'editor' ], true ) ) {
			$R->add( WD_CONF, 'base.option', 'default_role', 'Inscription ouverte avec un rôle par défaut privilégié', [ 'default_role = ' . $o['default_role'] ] );
		}
		foreach ( [ 'auto_update_core_major', 'auto_update_core_minor', 'auto_update_core_dev' ] as $k ) {
			if ( ( $o[ $k ] ?? '' ) === 'disabled' ) {
				$R->add( WD_DURC, 'maj.option', $k, 'Mises à jour automatiques du cœur désactivées en base' );
			}
		}
		foreach ( [ 'core_updater.lock', 'auto_updater.lock' ] as $k ) {
			if ( isset( $o[ $k ] ) ) {
				$age = time() - (int) $o[ $k ];
				$R->add( $age > 900 ? WD_PISTE : WD_HUMAN, 'maj.verrou', $k, 'Verrou de mise à jour présent en base', [ 'posé il y a ' . round( $age / 60 ) . ' min' ], 'Au-delà de 15 min sans mise à jour en cours, le supprimer débloque les mises à jour.', [ 'ligne' => [ 'table' => $this->prefix . 'options', 'cle' => 'option_name', 'valeur' => $k, 'colonne' => 'option_value' ] ] );
			}
		}
		$cron = wd_unserialize( $o['cron'] ?? '' );
		if ( is_array( $cron ) ) {
			$hooks  = $this->ctx['hooks'] ?? [];
			$lit    = $this->ctx['literals'] ?? [];
			$complete = ! empty( $this->ctx['code_complete'] );
			$unexpl = [];
			foreach ( $cron as $ts => $entries ) {
				if ( ! is_array( $entries ) ) {
					continue;
				}
				foreach ( $entries as $hook => $calls ) {
					$hook = (string) $hook;
					foreach ( (array) $calls as $call ) {
						$args = serialize( $call['args'] ?? [] );
						$res  = wd_value( $args );
						foreach ( $res['flows'] as $f ) {
							$R->add( $f['niveau'], 'base.cron_code', 'cron:' . $hook, 'Tâche planifiée qui transporte du code', [ '[' . $f['capacite'] . '] ' . $f['detail'] ], '', [ 'hooks' => [ $hook ] ] );
						}
						if ( $res['decoded'] || $res['code'] ) {
							$R->add( WD_PISTE, 'base.cron_code', 'cron:' . $hook, 'Arguments de tâche planifiée encodés ou exécutables', array_map( fn( $d ) => 'décodé : ' . $d['apercu'], $res['decoded'] ), '', [ 'hooks' => [ $hook ] ] );
						}
					}
					if ( ! isset( $hooks[ $hook ] ) && ! isset( $lit[ $hook ] ) ) {
						$unexpl[ $hook ] = true;
					}
				}
			}
			if ( $unexpl && $complete ) {
				$R->add( WD_PISTE, 'base.cron_non_explique', 'cron', 'Tâches planifiées qu\'aucun code installé ne déclare', array_slice( array_keys( $unexpl ), 0, 40 ), 'Une tâche orpheline vient d\'une extension retirée ou d\'une persistance : identifier.', [ 'hooks' => array_map( 'strval', array_keys( $unexpl ) ) ] );
			} elseif ( $unexpl ) {
				$R->notDone( 'explication des tâches planifiées', 'code du site non analysé en entier (--root requis, sans budget dépassé)', WD_HUMAN );
			}
		} elseif ( isset( $o['cron'] ) ) {
			$R->add( WD_ILL, 'base.cron', 'cron', 'Option cron illisible (sérialisation invalide : altération possible)' );
		}
	}
}

/**
 * Lit la base (dump ou MySQL en SELECT seul). $st porte la reprise : avec une échéance $deadline, la
 * lecture s'arrête entre deux pages et rend false ; l'appel suivant reprend à la même table.
 */
function wd_db_phase( WdReport $R, array $opt, array $ctx, ?array &$st = null, float $deadline = 0.0 ): bool {
	$R->at( 'base' );
	if ( ! empty( $st['db'] ) ) {
		return wd_db_read( $R, $opt, $ctx, $st, $deadline );
	}
	$prefix = $opt['prefix'] ?? ( $ctx['config']['prefix'] ?? null );
	if ( empty( $opt['sql'] ) && ! empty( $ctx['root'] ) && ! wd_is_wp( $ctx ) ) {
		$R->notDone( 'analyse de la base', 'projet non WordPress : schéma et identifiants inconnus, la base n\'est pas lue (l\'exporter et la faire relire)', WD_HUMAN );
		return true;
	}
	if ( ! empty( $opt['sql'] ) ) {
		$dump = new WdDump( $opt['sql'], $R );
		if ( ! is_file( $opt['sql'] ) || ! $dump->prescan() ) {
			$R->notDone( 'analyse de la base', 'dump illisible : ' . $opt['sql'] );
			return true;
		}
		$cands = [];
		foreach ( $dump->creates as $t => $cols ) {
			if ( wd_ends( $t, 'options' ) && in_array( 'option_name', $cols, true ) ) {
				$cands[] = substr( $t, 0, -7 );
			}
		}
		if ( $prefix === null ) {
			if ( count( $cands ) === 1 ) {
				$prefix = $cands[0];
				$R->contexte['prefixe_deduit_du_dump'] = $prefix;
			} else {
				$R->notDone( 'analyse de la base', count( $cands ) ? 'plusieurs préfixes dans le dump (' . implode( ', ', $cands ) . ') : fournir --prefix' : 'aucune table d\'options dans le dump', count( $cands ) ? WD_HUMAN : WD_ILL );
				return true;
			}
		} elseif ( ! in_array( $prefix, $cands, true ) ) {
			$R->notDone( 'analyse de la base', 'le préfixe « ' . $prefix . ' » n\'existe pas dans le dump (présents : ' . implode( ', ', $cands ) . ')' );
			return true;
		}
		$db = new WdDb( $R, $prefix, $ctx, $opt );
		foreach ( $dump->creates as $t => $cols ) {
			$db->create( $t, $cols, $dump->autoinc[ $t ] ?? null );
		}
		$dump->scan( [ $db, 'row' ] );
		$R->contexte['source_base'] = 'dump hors ligne ' . basename( $opt['sql'] );
		$db->finish();
		return true;
	}
	if ( ! empty( $opt['no-db'] ) ) {
		$R->notDone( 'analyse de la base', 'désactivée (--no-db)', WD_HUMAN );
		return true;
	}
	if ( $prefix === null || empty( $ctx['config']['db']['DB_NAME'] ) ) {
		$R->notDone( 'analyse de la base', 'identifiants ou préfixe introuvables dans wp-config.php' );
		return true;
	}
	[ $my, $why ] = wd_db_connect( $ctx );
	if ( $my === null ) {
		$R->notDone( 'analyse de la base', $why );
		return true;
	}
	$db   = new WdDb( $R, $prefix, $ctx, $opt );
	$like = str_replace( [ '\\', '_', '%' ], [ '\\\\', '\\_', '\\%' ], $prefix ) . '%';
	$res  = $my->query( 'SHOW TABLE STATUS LIKE \'' . $my->real_escape_string( $like ) . '\'' );
	if ( ! $res ) {
		$R->notDone( 'analyse de la base', 'SHOW TABLE STATUS refusé : ' . $my->error );
		return true;
	}
	$tables = [];
	while ( $r = $res->fetch_assoc() ) {
		$tables[ $r['Name'] ] = $r['Auto_increment'] !== null ? (int) $r['Auto_increment'] : null;
	}
	$res->free();
	$st = [ 'db' => $db, 'tables' => $tables, 'ti' => 0, 'last' => null, 'my' => $my ];
	return wd_db_read( $R, $opt, $ctx, $st, $deadline );
}
/** Connexion MySQL avec les identifiants lus dans wp-config.php : [mysqli|null, raison de l'échec]. */
function wd_db_connect( array $ctx ): array {
	if ( ! class_exists( 'mysqli' ) ) {
		return [ null, 'extension mysqli absente : exporter la base et relancer avec --sql=dump.sql' ];
	}
	$c    = $ctx['config']['db'] ?? [];
	$host = (string) ( $c['DB_HOST'] ?? '' );
	$port = null;
	$sock = null;
	if ( preg_match( '/^(.*):(\d+)$/', $host, $m ) ) {
		[ $host, $port ] = [ $m[1], (int) $m[2] ];
	} elseif ( preg_match( '/^(.*):(\/.+)$/', $host, $m ) ) {
		[ $host, $sock ] = [ $m[1] ?: 'localhost', $m[2] ];
	}
	mysqli_report( MYSQLI_REPORT_OFF );
	$my = mysqli_init();
	$my->options( MYSQLI_OPT_CONNECT_TIMEOUT, 10 );
	$ok = wd_probe( fn() => $my->real_connect( $host, (string) ( $c['DB_USER'] ?? '' ), (string) ( $c['DB_PASSWORD'] ?? '' ), (string) ( $c['DB_NAME'] ?? '' ), $port, $sock ), $warn );
	if ( ! $ok ) {
		return [ null, 'connexion MySQL refusée (' . ( $my->connect_error ?: implode( ' ; ', $warn ) ) . ')' ];
	}
	$my->set_charset( 'utf8mb4' );
	return [ $my, '' ];
}
function wd_sql_name( string $name ): string {
	return '`' . str_replace( '`', '``', $name ) . '`';
}
/** Colonne de clé primaire simple d'une table, ou null (clé composée ou absente). */
function wd_db_pk( mysqli $my, string $table ): ?string {
	$q = $my->query( 'SHOW KEYS FROM ' . wd_sql_name( $table ) . ' WHERE Key_name = \'PRIMARY\'' );
	$cols = [];
	while ( $q && ( $r = $q->fetch_assoc() ) ) {
		$cols[] = (string) $r['Column_name'];
	}
	return count( $cols ) === 1 ? $cols[0] : null;
}
/** Lecture des tables ; avec échéance, par pages sur la clé primaire (reprise exacte, sans OFFSET). */
function wd_db_read( WdReport $R, array $opt, array $ctx, array &$st, float $deadline ): bool {
	$my = $st['my'] ?? null;
	if ( ! $my instanceof mysqli ) {
		[ $my, $why ] = wd_db_connect( $ctx );
		if ( $my === null ) {
			$R->notDone( 'analyse de la base', 'reconnexion impossible : ' . $why );
			return true;
		}
	}
	$db     = $st['db'];
	$tables = array_keys( $st['tables'] );
	for ( ; $st['ti'] < count( $tables ); $st['ti']++, $st['last'] = null ) {
		$t = $tables[ $st['ti'] ];
		if ( $deadline > 0 && microtime( true ) > $deadline ) {
			$st['my'] = null;
			$my->close();
			return false;
		}
		if ( $st['last'] === null ) {
			$cr = $my->query( 'SHOW COLUMNS FROM ' . wd_sql_name( $t ) );
			$cols = [];
			while ( $cr && ( $r = $cr->fetch_assoc() ) ) {
				$cols[] = $r['Field'];
			}
			$db->create( $t, $cols, $st['tables'][ $t ] );
		}
		$pk = $deadline > 0 ? wd_db_pk( $my, $t ) : null;
		if ( $pk === null ) {
			$q = $my->query( 'SELECT * FROM ' . wd_sql_name( $t ), MYSQLI_USE_RESULT );
			if ( ! $q ) {
				$R->add( WD_ILL, 'base.table_illisible', $t, 'Lecture refusée : ' . $my->error );
				continue;
			}
			while ( $r = $q->fetch_assoc() ) {
				$db->row( $t, $r );
			}
			$q->free();
			continue;
		}
		while ( true ) {
			if ( microtime( true ) > $deadline ) {
				$st['my'] = null;
				$my->close();
				return false;
			}
			$where = $st['last'] === null ? '' : ' WHERE ' . wd_sql_name( $pk ) . ' > \'' . $my->real_escape_string( (string) $st['last'] ) . '\'';
			$q = $my->query( 'SELECT * FROM ' . wd_sql_name( $t ) . $where . ' ORDER BY ' . wd_sql_name( $pk ) . ' LIMIT 2000' );
			if ( ! $q ) {
				$R->add( WD_ILL, 'base.table_illisible', $t, 'Lecture refusée : ' . $my->error );
				break;
			}
			$n = 0;
			while ( $r = $q->fetch_assoc() ) {
				$db->row( $t, $r );
				$st['last'] = $r[ $pk ];
				$n++;
			}
			$q->free();
			if ( $n < 2000 ) {
				break;
			}
		}
	}
	$my->close();
	$st['my'] = null;
	$R->contexte['source_base'] = 'MySQL direct (lecture seule, SELECT uniquement)';
	$db->finish();
	return true;
}

// ============================================================ Mises à jour, système, site en ligne, journaux

function wd_updates_phase( WdReport $R, array $opt, array $ctx ): void {
	if ( ! empty( $ctx['update_filters_by_file'] ) ) {
		foreach ( $ctx['update_filters_by_file'] as $file => $list ) {
			$R->add( WD_DURC, 'maj.filtre', $file, 'Filtre qui agit sur les mises à jour (facilitateur d\'intrusion s\'il les coupe)', $list, 'Retirer le filtre ou documenter le processus de mise à jour qui le remplace.' );
		}
	}
	foreach ( $ctx['update_consts'] ?? [] as [ $name, $val, $where ] ) {
		$R->add( WD_DURC, 'maj.constante', $where, 'Constante de mise à jour définie hors wp-config.php : ' . $name, [ '= ' . $val ] );
	}
	$root = $ctx['root'] ?? '';
	if ( $root !== '' && empty( $opt['offline'] ) ) {
		$free = disk_free_space( $root );
		if ( $free !== false && $free < 209715200 ) {
			$R->add( WD_PISTE, 'maj.disque', $root, 'Moins de 200 Mo libres : les mises à jour échouent', [ round( $free / 1048576 ) . ' Mo' ] );
		}
		foreach ( [ 'wp-includes', 'wp-admin', 'wp-content/plugins', 'wp-content/themes', 'wp-content/upgrade' ] as $d ) {
			if ( is_dir( $root . '/' . $d ) && ! is_writable( $root . '/' . $d ) ) {
				$R->add( WD_PISTE, 'maj.ecriture', $d, 'Dossier non inscriptible par l\'utilisateur PHP : les mises à jour depuis l\'admin échouent' );
			}
		}
	}
	if ( ! empty( $opt['no-network'] ) || ( $ctx['wp_version'] ?? '' ) === '' ) {
		return;
	}
	[ $st, $j, $err ] = wd_json_get( $R, 'https://api.wordpress.org/core/version-check/1.7/?version=' . rawurlencode( $ctx['wp_version'] ) . '&locale=' . rawurlencode( $ctx['locale'] ?: 'en_US' ) );
	if ( $st !== 'ok' ) {
		$R->notDone( 'version du cœur', 'API des versions injoignable (' . $err . ') : cela ne veut PAS dire « pas de correctif »' );
	} else {
		$latest = $j['offers'][0]['current'] ?? '';
		if ( $latest !== '' && version_compare( $ctx['wp_version'], $latest, '<' ) ) {
			$R->add( WD_DURC, 'maj.coeur', 'wp-includes/version.php', 'Cœur non à jour : ' . $ctx['wp_version'] . ' → ' . $latest, [], 'Mettre à jour (réinstallation propre de la même langue) après fermeture du vecteur.' );
		}
		$R->done( 'version du cœur' );
	}
	foreach ( $ctx['components'] as $c ) {
		if ( $c['version'] === '' ) {
			continue;
		}
		$url = $c['kind'] === 'plugin'
			? 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=' . rawurlencode( $c['slug'] )
			: 'https://api.wordpress.org/themes/info/1.2/?action=theme_information&request%5Bslug%5D=' . rawurlencode( $c['slug'] );
		[ $st, $j, $err ] = wd_json_get( $R, $url );
		if ( $st === 'injoignable' ) {
			$R->notDone( 'version de ' . $c['dir'], 'API injoignable (' . $err . ')' );
			continue;
		}
		$latest = is_array( $j ) ? (string) ( $j['version'] ?? '' ) : '';
		if ( $latest === '' ) {
			$R->add( WD_HUMAN, 'maj.hors_catalogue', $c['dir'], 'Composant hors du catalogue officiel : versions et correctifs à vérifier chez l\'éditeur', [ 'version installée ' . $c['version'] ] );
		} elseif ( version_compare( $c['version'], $latest, '<' ) ) {
			$R->add( WD_DURC, 'maj.composant', $c['dir'], 'Composant non à jour : ' . $c['version'] . ' → ' . $latest, [], 'Mettre à jour ; vérifier aussi les avis de sécurité de cette version.' );
		}
	}
}

/** Sortie de `ps -u USER -o pid=,ppid=,etimes=,args=` (tolère une colonne lstart intercalée et un en-tête). */
function wd_parse_ps( string $out ): array {
	$rows = [];
	foreach ( preg_split( '/\r?\n/', $out ) as $line ) {
		if ( ! preg_match( '/^\s*(\d+)\s+(\d+)\s+(\d+)\s+(.*)$/', $line, $m ) ) {
			continue;
		}
		$args = preg_replace( '/^[A-Z][a-z]{2} [A-Z][a-z]{2} +\d{1,2} \d{2}:\d{2}:\d{2} \d{4} /', '', $m[4] );
		$rows[ (int) $m[1] ] = [ 'pid' => (int) $m[1], 'ppid' => (int) $m[2], 'age' => (int) $m[3], 'args' => trim( $args ) ];
	}
	return $rows;
}
function wd_ps_command( string $user ): string {
	return 'ps -u ' . escapeshellarg( $user ) . ' -o pid=,ppid=,etimes=,args= 2>&1';
}
/** Signaux forts uniquement pour proposer un kill ; l'âge (etimes) n'est jamais un critère : c'est l'âge du processus, pas de la requête. */
function wd_ps_suspects( array $rows, array $info, int $self, array $roots ): array {
	$exclude = [ $self => true ];
	$p = $rows[ $self ]['ppid'] ?? 0;
	while ( $p > 1 && isset( $rows[ $p ] ) && ! isset( $exclude[ $p ] ) ) {
		$exclude[ $p ] = true;
		$p = $rows[ $p ]['ppid'];
	}
	$changed = true;
	while ( $changed ) {
		$changed = false;
		foreach ( $rows as $pid => $r ) {
			if ( ! isset( $exclude[ $pid ] ) && isset( $exclude[ $r['ppid'] ] ) && $r['ppid'] !== 1 && ( $r['ppid'] === $self || isset( $rows[ $self ] ) && $r['ppid'] !== ( $rows[ $self ]['ppid'] ?? -1 ) ) ) {
				$exclude[ $pid ] = true;
				$changed = true;
			}
		}
	}
	$out = [];
	foreach ( $rows as $pid => $r ) {
		if ( isset( $exclude[ $pid ] ) || preg_match( '/^php-fpm: (pool|master)/', $r['args'] ) ) {
			continue;
		}
		$strong = [];
		$weak   = [];
		$cwd = $info[ $pid ]['cwd'] ?? '';
		$exe = $info[ $pid ]['exe'] ?? '';
		foreach ( [ 'cwd' => $cwd, 'exe' => $exe ] as $k => $v ) {
			if ( wd_ends( $v, '(deleted)' ) ) {
				$strong[] = $k . ' supprimé du disque : ' . $v;
			}
			if ( preg_match( '#^/(tmp|dev/shm|var/tmp)/#', $v ) ) {
				$strong[] = $k . ' dans un dossier temporaire : ' . $v;
			}
		}
		if ( preg_match( '#(^|\s)/(tmp|dev/shm|var/tmp)/\S+#', $r['args'] ) ) {
			$strong[] = 'exécute un fichier temporaire';
		}
		$script = preg_match( '#\b(php[\d.]*|perl|python[\d.]*|ruby|node|sh|bash)\b\S*\s+(\S+)#', $r['args'], $m ) ? $m[2] : '';
		if ( $r['ppid'] === 1 && $script !== '' ) {
			$strong[] = 'rattaché à init (PPID 1) : détaché de toute requête ou tâche';
		}
		foreach ( $roots as $root ) {
			if ( $script !== '' && wd_starts( $script, $root . '/' ) ) {
				$weak[] = 'exécute un script du site en ligne de commande : ' . $script;
			}
		}
		if ( $strong || $weak ) {
			$out[ $pid ] = [ 'row' => $r, 'forts' => $strong, 'faibles' => $weak, 'cwd' => $cwd, 'exe' => $exe ];
		}
	}
	return $out;
}
function wd_system_phase( WdReport $R, array $opt, array $roots ): void {
	$R->at( 'système' );
	if ( ! empty( $opt['offline'] ) ) {
		$R->notDone( 'processus, tâches planifiées, fichiers temporaires, dates ctime', 'mode hors ligne : ces contrôles se font SUR le serveur (mode SSH ou tâche planifiée)', WD_HUMAN );
		return;
	}
	if ( DIRECTORY_SEPARATOR !== '/' || ! is_dir( '/proc' ) ) {
		$R->notDone( 'processus et fichiers temporaires', 'système non Linux ou /proc absent', WD_HUMAN );
		return;
	}
	$uid  = function_exists( 'posix_geteuid' ) ? posix_geteuid() : ( is_readable( '/proc/self/status' ) && preg_match( '/^Uid:\s+(\d+)/m', (string) file_get_contents( '/proc/self/status' ), $m ) ? (int) $m[1] : -1 );
	$user = function_exists( 'posix_getpwuid' ) && $uid >= 0 ? ( posix_getpwuid( $uid )['name'] ?? '' ) : '';
	if ( $user === '' && is_readable( '/etc/passwd' ) ) {
		foreach ( file( '/etc/passwd' ) ?: [] as $l ) {
			$f = explode( ':', $l );
			if ( isset( $f[2] ) && (int) $f[2] === $uid ) {
				$user = $f[0];
			}
		}
	}
	$R->contexte['utilisateur_systeme'] = $user ?: '(inconnu)';
	$rows = [];
	if ( $user !== '' && wd_shell_ok() ) {
		$out  = (string) shell_exec( wd_ps_command( $user ) );
		$rows = wd_parse_ps( $out );
		if ( ! $rows ) {
			$R->error( 'ps sans résultat exploitable : ' . wd_clean( $out ) );
		}
	}
	if ( ! $rows ) {
		$pids = scandir( '/proc' );
		if ( $pids === false ) {
			$R->notDone( 'processus', '/proc illisible (open_basedir ou hidepid)' );
		} else {
			$uptime = (float) explode( ' ', (string) file_get_contents( '/proc/uptime' ) )[0];
			foreach ( $pids as $pid ) {
				if ( ! ctype_digit( $pid ) || fileowner( '/proc/' . $pid ) !== $uid ) {
					continue;
				}
				$stat = (string) file_get_contents( '/proc/' . $pid . '/stat' );
				$after = substr( $stat, (int) strrpos( $stat, ')' ) + 2 );
				$f = explode( ' ', $after );
				$cmd = str_replace( "\0", ' ', (string) file_get_contents( '/proc/' . $pid . '/cmdline' ) );
				$rows[ (int) $pid ] = [ 'pid' => (int) $pid, 'ppid' => (int) ( $f[1] ?? 0 ), 'age' => (int) ( $uptime - ( (int) ( $f[19] ?? 0 ) ) / 100 ), 'args' => trim( $cmd ) ];
			}
		}
	}
	$info = [];
	foreach ( array_keys( $rows ) as $pid ) {
		$cwd = is_link( "/proc/$pid/cwd" ) ? readlink( "/proc/$pid/cwd" ) : false;
		$exe = is_link( "/proc/$pid/exe" ) ? readlink( "/proc/$pid/exe" ) : false;
		$info[ $pid ] = [ 'cwd' => $cwd === false ? '' : $cwd, 'exe' => $exe === false ? '' : $exe ];
	}
	if ( $rows ) {
		$R->done( 'processus' );
		foreach ( wd_ps_suspects( $rows, $info, getmypid(), $roots ) as $pid => $s ) {
			$st = $s['forts'] ? WD_CONF : WD_PISTE;
			$R->add( $st, 'systeme.processus', 'pid ' . $pid, $st === WD_CONF ? 'Processus persistant (signaux forts)' : 'Processus non expliqué', array_merge( [ 'ppid ' . $s['row']['ppid'] . ' ; âge ' . $s['row']['age'] . ' s (âge du processus, pas d\'une requête)', 'commande : ' . $s['row']['args'], 'cwd : ' . ( $s['cwd'] ?: 'illisible' ), 'exe : ' . ( $s['exe'] ?: 'illisible' ) ], $s['forts'], $s['faibles'] ), $st === WD_CONF ? 'Copier /proc/' . $pid . '/cmdline et environ, puis kill -9 ' . $pid . ' APRÈS confirmation humaine du PID, avant tout nettoyage.' : 'Identifier ce processus avant d\'agir.', [ 'pid' => $pid, 'commande' => $s['row']['args'] ] );
		}
	}
	if ( wd_shell_ok() ) {
		$cron = (string) shell_exec( 'crontab -l 2>&1' );
		$lines = array_values( array_filter( preg_split( '/\r?\n/', $cron ), fn( $l ) => trim( $l ) !== '' && $l[0] !== '#' && ! preg_match( '/^no crontab/i', $l ) ) );
		foreach ( $lines as $l ) {
			if ( preg_match( '#(curl|wget)\s.*\|\s*(sh|bash|php)|base64|/(tmp|dev/shm|var/tmp)/|/\.[^/\s]+\.php|nohup|\bnc\b|/uploads/#i', $l ) ) {
				$R->add( WD_CONF, 'systeme.crontab', 'crontab', 'Tâche planifiée qui télécharge, décode ou exécute depuis un emplacement inattendu', [ $l ] );
			}
		}
		if ( $lines ) {
			$R->add( WD_HUMAN, 'systeme.crontab', 'crontab', count( $lines ) . ' tâche(s) planifiée(s) à faire confirmer', $lines );
		}
		$R->done( 'crontab' );
	} else {
		$R->notDone( 'crontab', 'shell_exec désactivé : relire les tâches planifiées dans le panneau d\'hébergement', WD_HUMAN );
	}
	foreach ( array_unique( [ sys_get_temp_dir(), '/tmp', '/var/tmp', '/dev/shm' ] ) as $dir ) {
		$list = is_dir( $dir ) ? scandir( $dir ) : false;
		if ( $list === false ) {
			$R->notDone( 'dossier temporaire ' . $dir, 'illisible (open_basedir ?)' );
			continue;
		}
		foreach ( $list as $e ) {
			$p = $dir . '/' . $e;
			if ( $e === '.' || $e === '..' || ! is_file( $p ) || fileowner( $p ) !== $uid ) {
				continue;
			}
			$head = (string) wd_read( $R, $p, 65536 );
			$why  = wd_starts( $head, "\x7fELF" ) ? 'binaire ELF' : ( wd_starts( $head, '#!' ) ? 'script (shebang)' : ( stripos( $head, '<?php' ) !== false ? 'code PHP' : ( preg_match( '#[A-Za-z0-9+/]{400,}#', $head ) && ! wd_has( $head, '-----BEGIN' ) ? 'gros bloc encodé' : '' ) ) );
			if ( $why !== '' ) {
				$st = WD_PISTE;
				if ( $why === 'code PHP' ) {
					$P = wd_php_analyze( substr( $head, (int) stripos( $head, '<?php' ) ) );
					foreach ( $P->flows as $f ) {
						$st = $f['niveau'] === WD_CONF ? WD_CONF : $st;
					}
				}
				$R->add( $st, 'systeme.temporaire', $p, 'Fichier temporaire de l\'utilisateur contenant du code (' . $why . ')', [ filesize( $p ) . ' octets' ], 'Copier comme preuve (hachée), puis supprimer après avoir tué les processus liés.' );
			}
		}
	}
	$R->done( 'fichiers temporaires' );
}
function wd_live_phase( WdReport $R, array $opt ): void {
	$url = $opt['url'] ?? '';
	if ( $url === '' ) {
		$R->notDone( 'comportement du site en ligne (cloaking, verrou)', 'aucune --url fournie', WD_HUMAN );
		return;
	}
	if ( ! empty( $opt['no-network'] ) ) {
		$R->notDone( 'comportement du site en ligne', 'réseau désactivé' );
		return;
	}
	$R->at( 'site en ligne' );
	$cb = 'wdcb=' . bin2hex( random_bytes( 4 ) );
	$u  = $url . ( wd_has( $url, '?' ) ? '&' : '?' ) . $cb;
	$profiles = [
		'navigateur'          => [ 'User-Agent: ' . WD_BROWSER_UA ],
		'robot_indexation'    => [ 'User-Agent: ' . WD_CRAWLER_UA ],
		'referent_recherche'  => [ 'User-Agent: ' . WD_BROWSER_UA, 'Referer: ' . WD_SEARCH_REFERER ],
	];
	$res = [];
	foreach ( $profiles as $k => $h ) {
		[ $code, $body, $hdr, $err ] = wd_http( $R, $u, $h );
		if ( $code === 0 ) {
			$R->notDone( 'cloaking (' . $k . ')', 'site injoignable : ' . $err );
			return;
		}
		preg_match_all( '#(?:href|src)\s*=\s*["\']https?://([^/"\']+)#i', $body, $m );
		$hosts = array_values( array_unique( array_map( fn( $x ) => strtolower( preg_replace( '/^www\./', '', $x ) ), $m[1] ) ) );
		$res[ $k ] = [ 'code' => $code, 'location' => $hdr['location'] ?? '', 'taille' => strlen( $body ), 'hotes' => $hosts, 'cache' => $hdr['x-cache-status'] ?? ( $hdr['x-cache'] ?? ( $hdr['cf-cache-status'] ?? '' ) ) ];
	}
	$R->contexte['cloaking'] = $res;
	$base = $res['navigateur'];
	foreach ( [ 'robot_indexation', 'referent_recherche' ] as $k ) {
		$r = $res[ $k ];
		$diff = [];
		if ( $r['code'] !== $base['code'] || $r['location'] !== $base['location'] ) {
			$diff[] = 'statut/redirection : ' . $base['code'] . ' ' . $base['location'] . ' contre ' . $r['code'] . ' ' . $r['location'];
		}
		$extra = array_diff( $r['hotes'], $base['hotes'] );
		if ( $extra ) {
			$diff[] = 'hôtes de liens en plus : ' . implode( ', ', array_slice( $extra, 0, 10 ) );
		}
		if ( $base['taille'] > 0 && abs( $r['taille'] - $base['taille'] ) / $base['taille'] > 0.3 ) {
			$diff[] = 'taille ' . $base['taille'] . ' contre ' . $r['taille'] . ' octets';
		}
		if ( $diff ) {
			$R->add( WD_PISTE, 'site.cloaking', $url, 'La réponse change selon le visiteur (' . $k . ')', $diff, 'Rejouer sans cache ; un contenu différent pour les robots ou les visiteurs venus d\'un moteur est la marque du spam SEO.' );
		}
	}
	$probe = rtrim( preg_replace( '#\?.*$#', '', $url ), '/' ) . '/wd-inexistant-' . bin2hex( random_bytes( 3 ) ) . '.php';
	[ $code ] = wd_http( $R, $probe, [ 'User-Agent: ' . WD_BROWSER_UA ] );
	if ( $code === 403 ) {
		$R->add( WD_PISTE, 'site.verrou', $probe, 'Un .php inexistant renvoie 403 : une règle serveur filtre les scripts par nom (verrou possible)' );
	} elseif ( $code === 200 ) {
		$R->add( WD_PISTE, 'site.attrape_tout', $probe, 'Un .php inexistant renvoie 200 : une règle ou un script capte toutes les requêtes' );
	}
	$R->done( 'comportement du site en ligne' );
}
function wd_logs_phase( WdReport $R, array $opt, array $roots, array $flagged ): void {
	$files = array_filter( array_map( 'trim', explode( ',', (string) ( $opt['access-log'] ?? '' ) ) ) );
	if ( ! $files ) {
		$R->notDone( 'journaux d\'accès (vecteur)', 'aucun --access-log fourni : localiser les journaux dans le panneau (souvent un dossier logs voisin du docroot)', WD_HUMAN );
		return;
	}
	$R->at( 'journaux' );
	$targets = [];
	foreach ( $flagged as $rel => $st ) {
		$targets[ '/' . ltrim( $rel, '/' ) ] = [ 'hits' => 0, 'post' => 0, 'premier' => '', 'dernier' => '', 'ips' => [] ];
	}
	$posts = [];
	$re = '/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+)[^"]*" (\d{3})/';
	foreach ( $files as $f ) {
		$h = preg_match( '/\.gz$/', $f ) ? gzopen( $f, 'rb' ) : fopen( $f, 'rb' );
		if ( $h === false ) {
			$R->add( WD_ILL, 'journaux.illisible', $f, 'Journal illisible' );
			continue;
		}
		while ( ( $line = ( preg_match( '/\.gz$/', $f ) ? gzgets( $h ) : fgets( $h ) ) ) !== false ) {
			if ( ! preg_match( $re, $line, $m ) ) {
				continue;
			}
			$path = (string) preg_replace( '/\?.*$/', '', $m[4] );
			$date = DateTime::createFromFormat( 'd/M/Y:H:i:s O', $m[2] );
			$iso  = $date ? $date->format( 'Y-m-d H:i:s' ) : $m[2];
			if ( isset( $targets[ $path ] ) ) {
				$t = &$targets[ $path ];
				$t['hits']++;
				$t['post']   += $m[3] === 'POST' ? 1 : 0;
				$t['premier'] = $t['premier'] === '' || strcmp( $iso, $t['premier'] ) < 0 ? $iso : $t['premier'];
				$t['dernier'] = strcmp( $iso, $t['dernier'] ) > 0 ? $iso : $t['dernier'];
				$t['ips'][ $m[1] ] = ( $t['ips'][ $m[1] ] ?? 0 ) + 1;
				unset( $t );
			}
			if ( $m[3] === 'POST' && preg_match( '/\.php$/i', $path ) && ! wd_starts( $path, '/wp-admin/' ) && ! in_array( ltrim( $path, '/' ), WD_CORE_ROOT_FILES, true ) ) {
				$posts[ $path ] = ( $posts[ $path ] ?? 0 ) + 1;
			}
		}
		preg_match( '/\.gz$/', $f ) ? gzclose( $h ) : fclose( $h );
	}
	foreach ( $targets as $path => $t ) {
		if ( $t['hits'] ) {
			arsort( $t['ips'] );
			$R->add( ( $flagged[ ltrim( $path, '/' ) ] ?? WD_PISTE ) === WD_CONF ? WD_CONF : WD_PISTE, 'journaux.acces_fichier_signale', $path, 'Accès HTTP à un fichier signalé', [ $t['hits'] . ' requête(s) dont ' . $t['post'] . ' POST', 'du ' . $t['premier'] . ' au ' . $t['dernier'], 'IP : ' . implode( ', ', array_slice( array_keys( $t['ips'] ), 0, 5 ) ) ] );
			$R->event( $t['premier'], 'premier_acces', $path, true );
		}
	}
	if ( $posts ) {
		arsort( $posts );
		$R->add( WD_PISTE, 'journaux.post_non_coeur', 'journaux', 'Requêtes POST vers des scripts hors cœur', array_map( fn( $k, $v ) => $v . ' × ' . $k, array_keys( array_slice( $posts, 0, 20 ) ), array_slice( $posts, 0, 20 ) ) );
	}
	$R->done( 'journaux d\'accès' );
}
function wd_watch_phase( WdReport $R, array $opt, array $roots, array $flagged ): void {
	$sec = (int) ( $opt['watch'] ?? 0 );
	if ( $sec <= 0 ) {
		$R->notDone( 'surveillance de réécriture', 'non demandée (--watch=60 recommandé après nettoyage)', WD_HUMAN );
		return;
	}
	$paths = [];
	foreach ( $roots as $root ) {
		foreach ( array_merge( [ 'index.php', '.htaccess', 'wp-config.php' ], array_keys( $flagged ) ) as $rel ) {
			if ( is_file( $root . '/' . $rel ) ) {
				$paths[ $root . '/' . $rel ] = md5_file( $root . '/' . $rel );
			}
		}
	}
	sleep( $sec );
	foreach ( $paths as $p => $h ) {
		clearstatcache( true, $p );
		$now = is_file( $p ) ? md5_file( $p ) : 'supprimé';
		if ( $now !== $h ) {
			$R->add( WD_CONF, 'surveillance.reecriture', $p, 'Fichier modifié pendant ' . $sec . ' s de surveillance : une persistance active le réécrit', [ $h . ' → ' . $now ], 'Chercher et tuer le processus (voir systeme.processus) avant de nettoyer à nouveau.' );
		}
	}
	$R->done( 'surveillance de réécriture (' . $sec . ' s)' );
}
/** Grappes d'événements forts de natures différentes dans une fenêtre serrée. */
function wd_correlate( WdReport $R ): void {
	$ev = $R->evenements;
	usort( $ev, fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );
	$n = count( $ev );
	$clusters = [];
	for ( $i = 0; $i < $n; $i++ ) {
		$t0 = strtotime( $ev[ $i ]['date'] );
		$group = [ $ev[ $i ] ];
		for ( $j = $i + 1; $j < $n && strtotime( $ev[ $j ]['date'] ) - $t0 <= 900; $j++ ) {
			$group[] = $ev[ $j ];
		}
		$kinds = array_unique( array_column( $group, 'type' ) );
		if ( count( $kinds ) >= 2 && in_array( true, array_column( $group, 'fort' ), true ) ) {
			$clusters[] = array_map( fn( $e ) => $e['date'] . ' ' . $e['type'] . ' ' . $e['quoi'], $group );
			$i = $j - 1;
		}
	}
	if ( $clusters ) {
		$R->add( WD_PISTE, 'correlation.grappes', 'chronologie', count( $clusters ) . ' grappe(s) d\'événements de natures différentes à moins de 15 min', array_map( fn( $c ) => implode( ' | ', array_slice( $c, 0, 4 ) ), array_slice( $clusters, 0, 20 ) ), 'Chaque grappe relie un compte, un code ou un accès : c\'est la chronologie de l\'attaque.' );
	}
}

// ============================================================ Sortie

function wd_order( string $s ): int {
	return [ WD_CONF => 0, WD_PISTE => 1, WD_ILL => 2, WD_HUMAN => 3, WD_DURC => 4 ][ $s ] ?? 9;
}
function wd_output( WdReport $R, array $opt, string $mode ): array {
	usort( $R->constats, fn( $a, $b ) => wd_order( $a['statut'] ) <=> wd_order( $b['statut'] ) ?: strcmp( $a['code'], $b['code'] ) );
	$count = [];
	foreach ( $R->constats as $c ) {
		$count[ $c['statut'] ] = ( $count[ $c['statut'] ] ?? 0 ) + 1;
	}
	$doc = [
		'outil'          => 'wp-incident-response/detect.php',
		'version'        => WD_VERSION,
		'lecture_seule'  => true,
		'mode'           => $mode,
		'genere_le'      => gmdate( 'c' ),
		'php'            => PHP_VERSION,
		'contexte'       => $R->contexte,
		'verifications'  => [ 'faites' => array_keys( $R->faites ), 'non_faites' => $R->non_faites ],
		'comptes_par_statut' => $count,
		'constats'       => $R->constats,
		'stats'          => $R->stats,
		'chronologie'    => array_slice( $R->evenements, 0, 500 ),
		'incomplet'      => $R->incomplet,
		'erreurs'        => $R->erreurs,
		'avertissement'  => 'Aucun statut « ok » n\'existe : l\'absence de constat ne vaut que pour les vérifications faites.',
	];
	return $doc;
}
function wd_text_summary( array $doc ): string {
	$o   = [];
	$o[] = '== wp-incident-response / detect.php ' . $doc['version'] . ' (lecture seule, mode ' . $doc['mode'] . ') ==';
	foreach ( $doc['contexte']['racines'] ?? [] as $root => $r ) {
		$o[] = 'Racine : ' . $root . ' | ' . ( ( $r['type'] ?? 'wordpress' ) === 'wordpress' ? 'WordPress ' . $r['wordpress'] . ' (' . $r['langue'] . ') | préfixe ' . ( $r['prefixe'] ?? '?' ) : 'projet PHP non WordPress' );
	}
	if ( isset( $doc['contexte']['source_base'] ) ) {
		$o[] = 'Base : ' . $doc['contexte']['source_base'];
	}
	$f = $doc['contexte']['fenetre'] ?? null;
	$o[] = 'Fenêtre d\'incident : ' . ( is_array( $f ) ? $f['debut'] . ' (' . $f['source'] . ')' : ( $f ?? 'non déterminée (base non lue)' ) );
	$o[] = 'Comptes par statut : ' . json_encode( $doc['comptes_par_statut'], JSON_UNESCAPED_UNICODE );
	$cur = '';
	foreach ( $doc['constats'] as $c ) {
		if ( $c['statut'] !== $cur ) {
			$cur = $c['statut'];
			$o[] = '';
			$o[] = '### ' . $cur;
		}
		$o[] = '- [' . $c['code'] . '] ' . $c['cible'] . ' : ' . $c['titre'];
		foreach ( array_slice( $c['preuves'], 0, 6 ) as $p ) {
			$o[] = '    · ' . $p;
		}
		if ( count( $c['preuves'] ) > 6 ) {
			$o[] = '    · … ' . ( count( $c['preuves'] ) - 6 ) . ' de plus (voir JSON)';
		}
	}
	if ( $doc['erreurs'] ) {
		$o[] = '';
		$o[] = '### Erreurs rencontrées (' . count( $doc['erreurs'] ) . ')';
		foreach ( array_slice( $doc['erreurs'], 0, 20 ) as $e ) {
			$o[] = '- ' . $e;
		}
	}
	$o[] = '';
	$o[] = $doc['avertissement'];
	return implode( "\n", $o ) . "\n";
}

function wd_help(): string {
	return <<<TXT
detect.php : détecteur d'incident WordPress en lecture seule (aucun fichier du site n'est chargé).

  php detect.php --root=DOCROOT [options]            mode SSH ou tâche planifiée du panneau (PHP CLI)
  php detect.php --root=COPIE --sql=dump.sql         mode hors ligne (fichiers copiés + dump)
  php detect.php --sql=dump.sql [--prefix=P]         base seule, hors ligne

Options :
  --scope=DIR           abonnement entier : analyse chaque installation trouvée (recettes, *.old)
  --ref=DIR | --ref=CHEMIN_REL=DIR   copie de confiance (sauvegarde saine, archive éditeur de la même version)
  --baseline=inv.json   inventaire antérieur sain (produit par --inventory-out)
  --inventory-out=F     écrit l'empreinte sha256 de chaque fichier (surveillance 24-72 h)
  --since=AAAA-MM-JJ    début de fenêtre = date de la preuve la plus ancienne
  --legit-admins=a,b    identifiants administrateurs confirmés par le client
  --url=https://site    contrôles en ligne (cloaking à 3 profils, verrou 403)
  --access-log=f1,f2    journaux d'accès (texte ou .gz)
  --watch=SECONDES      surveille la réécriture d'index.php, .htaccess, wp-config et des fichiers signalés
  --offline             force le mode hors ligne (pas de contrôles système)
  --no-network          aucun appel réseau (manifestes officiels non consultés : NEEDS_HUMAN)
  --no-db               ne lit pas la base
  --json=FICHIER        rapport JSON (refusé à l'intérieur d'une racine analysée)
  --format=text|json    sortie standard (défaut text)
  --offset=N --max-seconds=S   analyse découpée et reprenable
  --max-file-size=OCTETS       plafond d'analyse par fichier PHP (défaut 8 Mo)
  --exclude=CHEMIN,…    chemins absolus à ne pas parcourir (listés dans le rapport)
Code retour : 2 si CONFIRMÉ, 1 sinon, 3 erreur fatale.
TXT;
}

function wd_parse_opts( array $argv ): array {
	$o = [];
	foreach ( array_slice( $argv, 1 ) as $a ) {
		if ( ! preg_match( '/^--([a-z-]+)(?:=(.*))?$/s', $a, $m ) ) {
			fwrite( STDERR, "Argument inconnu : $a\n" );
			exit( 3 );
		}
		$k = $m[1];
		$v = $m[2] ?? true;
		if ( $k === 'ref' ) {
			$o['ref'][] = $v;
		} else {
			$o[ $k ] = $v;
		}
	}
	return $o;
}
function wd_find_roots( WdReport $R, string $scope ): array {
	$roots = [];
	$queue = [ [ $scope, 0 ] ];
	while ( $queue ) {
		[ $dir, $d ] = array_shift( $queue );
		if ( is_file( $dir . '/wp-includes/version.php' ) ) {
			$roots[] = $dir;
			continue;
		}
		if ( $d >= 4 ) {
			continue;
		}
		$list = scandir( $dir );
		if ( $list === false ) {
			$R->add( WD_ILL, 'perimetre.illisible', $dir, 'Dossier du périmètre illisible' );
			continue;
		}
		foreach ( $list as $e ) {
			if ( $e !== '.' && $e !== '..' && is_dir( $dir . '/' . $e ) && ! is_link( $dir . '/' . $e ) ) {
				$queue[] = [ $dir . '/' . $e, $d + 1 ];
				if ( preg_match( '/(\.old|old[-_.]?\d|backup|\.bak|copy|copie)/i', $e ) ) {
					$R->add( WD_PISTE, 'perimetre.ancien_docroot', $dir . '/' . $e, 'Ancien docroot ou copie dans l\'abonnement : même utilisateur système, même exposition', [], 'L\'analyser comme le site principal, puis le supprimer.' );
				}
			}
		}
	}
	return $roots;
}

/** Exécute une phase ; toute erreur fatale devient un constat ILLISIBLE au lieu de tout perdre. */
function wd_guard( WdReport $R, string $phase, callable $fn ): void {
	try {
		$fn();
	} catch ( Throwable $e ) {
		$R->notDone( $phase, 'interrompue par une erreur (' . get_class( $e ) . ' : ' . $e->getMessage() . ')' );
	}
}
function wd_run( array $opt, string $mode ): array {
	$S = wd_steps_init( $opt, $mode );
	while ( ! wd_step( $S ) ) {
	}
	return wd_steps_result( $S );
}
/**
 * Analyse découpée en phases rejouables. $S est sérialisable (aucune ressource entre deux appels) :
 * un appelant contraint par max_execution_time appelle wd_step() avec une échéance et reprend plus tard.
 */
function wd_steps_init( array $opt, string $mode ): array {
	$R = new WdReport();
	$R->contexte['mode_acces'] = $mode;
	$disabled = trim( (string) ini_get( 'disable_functions' ) );
	$R->contexte['environnement'] = [ 'open_basedir' => (string) ini_get( 'open_basedir' ) ?: 'aucun', 'fonctions_desactivees' => $disabled === '' ? 'aucune' : $disabled, 'max_execution_time' => ini_get( 'max_execution_time' ), 'extensions_manquantes' => array_values( array_filter( [ 'tokenizer', 'mysqli', 'curl', 'openssl', 'zip', 'zlib' ], fn( $e ) => ! extension_loaded( $e ) ) ) ];
	if ( ! extension_loaded( 'tokenizer' ) ) {
		$R->notDone( 'analyse du code', 'extension tokenizer absente : impossible d\'analyser le PHP' );
	}
	$roots = [];
	if ( ! empty( $opt['scope'] ) ) {
		$roots = wd_find_roots( $R, wd_norm( (string) $opt['scope'] ) );
	}
	if ( ! empty( $opt['root'] ) ) {
		$real = realpath( (string) $opt['root'] );
		if ( $real === false ) {
			$R->notDone( 'analyse des fichiers', 'racine introuvable : ' . $opt['root'] );
		} else {
			$roots[] = wd_norm( $real );
		}
	}
	$roots  = array_values( array_unique( $roots ) );
	$phases = [];
	foreach ( array_keys( $roots ) as $ix ) {
		array_push( $phases, [ 'fichiers_preparer', $ix ], [ 'fichiers', $ix ], [ 'fichiers_terminer', $ix ] );
	}
	if ( ! $roots && empty( $opt['sql'] ) ) {
		$R->notDone( 'analyse', 'ni --root, ni --scope, ni --sql fourni' );
	}
	if ( ! empty( $opt['sql'] ) || $roots ) {
		$phases[] = [ 'base', 0 ];
	}
	if ( ! $roots ) {
		$R->notDone( 'analyse des fichiers', 'aucune racine fournie : le code n\'est pas analysé, rien ne peut être « expliqué »', WD_HUMAN );
	}
	foreach ( [ 'systeme', 'en_ligne', 'journaux', 'surveillance', 'correlation', 'inventaire' ] as $p ) {
		$phases[] = [ $p, 0 ];
	}
	$ctx = [ 'components' => [], 'config' => [ 'prefix' => null, 'db' => [] ], 'root' => '', 'wp_version' => '', 'locale' => '', 'hooks' => [], 'types' => [], 'literals' => [], 'flagged' => [], 'db_exec' => [] ];
	return [ 'opt' => $opt, 'mode' => $mode, 'R' => $R, 'roots' => $roots, 'phases' => $phases, 'pi' => 0, 'ctx' => $ctx, 'ctxMain' => $ctx, 'scan' => null, 'dbst' => null, 'allFlagged' => [], 'inventories' => [] ];
}
/** Exécute les phases jusqu'à la fin (true) ou jusqu'à l'échéance (false, reprise possible). */
function wd_step( array &$S, float $deadline = 0.0 ): bool {
	$R = $S['R'];
	set_error_handler(
		function ( $no, $str, $file = '', $line = 0 ) use ( $R ) {
			$R->error( $str . ' (detect.php:' . $line . ')' );
			return true;
		}
	);
	try {
		while ( $S['pi'] < count( $S['phases'] ) ) {
			if ( $deadline > 0 && microtime( true ) > $deadline ) {
				return false;
			}
			[ $ph, $ix ] = $S['phases'][ $S['pi'] ];
			if ( ! wd_phase( $S, $ph, $ix, $deadline ) ) {
				return false;
			}
			$S['pi']++;
		}
		return true;
	} finally {
		if ( isset( $S['dbst']['my'] ) ) {
			$S['dbst']['my'] = null;
		}
		restore_error_handler();
	}
}
function wd_phase( array &$S, string $ph, int $ix, float $deadline ): bool {
	$R    = $S['R'];
	$opt  = $S['opt'];
	$root = $S['roots'][ $ix ] ?? '';
	$done = true;
	switch ( $ph ) {
		case 'fichiers_preparer':
			$S['ctx']  = $S['ctxMain'];
			$S['scan'] = null;
			wd_guard( $R, 'analyse des fichiers ' . $root, function () use ( &$S, $R, $root, $opt ) {
				$S['scan'] = wd_scan_prepare( $R, $root, $opt, $S['ctx'] );
			} );
			break;
		case 'fichiers':
			if ( $S['scan'] !== null ) {
				$ok = false;
				wd_guard( $R, 'analyse des fichiers ' . $root, function () use ( &$S, &$done, &$ok, $R, $root, $opt, $deadline ) {
					$done = wd_scan_files( $R, $root, $opt, $S['ctx'], $S['scan'], $deadline );
					$ok   = true;
				} );
				if ( ! $ok ) {
					// Analyse interrompue : le code n'est pas lu en entier, rien ne doit s'en déduire.
					$S['scan'] = null;
					$done      = true;
				}
			}
			break;
		case 'fichiers_terminer':
			// Capture par référence : ces phases enrichissent le contexte (composants, littéraux, code_complete).
			if ( $S['scan'] !== null ) {
				wd_guard( $R, 'analyse des fichiers ' . $root, function () use ( &$S, $R, $root, $opt ) {
					wd_scan_finish( $R, $root, $opt, $S['ctx'], $S['scan'] );
				} );
			}
			$S['scan'] = null;
			$S['ctx']['update_filters_by_file'] = [];
			wd_guard( $R, 'localisation des filtres de mise à jour', function () use ( &$S, $R, $root ) {
				wd_update_filters_locate( $R, $root, $S['ctx'] );
			} );
			$ctx = $S['ctx'];
			wd_guard( $R, 'mises à jour', fn() => wd_updates_phase( $R, $opt, $ctx ) );
			foreach ( $ctx['flagged'] as $rel => $st ) {
				$S['allFlagged'][ $rel ] = $st;
			}
			if ( isset( $ctx['inventory'] ) ) {
				$S['inventories'][ $root ] = $ctx['inventory'];
			}
			if ( $ix === 0 ) {
				$S['ctxMain'] = $ctx;
			} elseif ( empty( $opt['sql'] ) ) {
				wd_guard( $R, 'base ' . $root, fn() => wd_db_phase( $R, $opt, $ctx ) );
			}
			break;
		case 'base':
			wd_guard( $R, 'base', function () use ( &$S, &$done, $R, $opt, $deadline ) {
				$done = wd_db_phase( $R, $opt, $S['ctxMain'], $S['dbst'], $deadline );
			} );
			if ( $done ) {
				$S['dbst'] = null;
			}
			break;
		case 'systeme':
			wd_guard( $R, 'système', fn() => wd_system_phase( $R, $opt, $S['roots'] ) );
			break;
		case 'en_ligne':
			wd_guard( $R, 'site en ligne', fn() => wd_live_phase( $R, $opt ) );
			break;
		case 'journaux':
			wd_guard( $R, 'journaux', fn() => wd_logs_phase( $R, $opt, $S['roots'], $S['allFlagged'] ) );
			break;
		case 'surveillance':
			wd_guard( $R, 'surveillance', fn() => wd_watch_phase( $R, $opt, $S['roots'], $S['allFlagged'] ) );
			break;
		case 'correlation':
			wd_guard( $R, 'corrélation', fn() => wd_correlate( $R ) );
			break;
		case 'inventaire':
			if ( ! empty( $opt['inventory-out'] ) ) {
				$inv = $S['inventories'];
				wd_write_out( $R, (string) $opt['inventory-out'], json_encode( [ 'genere_le' => gmdate( 'c' ), 'fichiers' => count( $inv ) === 1 ? reset( $inv ) : $inv ], JSON_UNESCAPED_SLASHES ), $S['roots'] );
			}
			break;
	}
	return $done;
}
/** Avancement lisible : phase courante, fichiers ou tables lus. */
function wd_steps_progress( array $S ): array {
	[ $ph ] = $S['phases'][ $S['pi'] ] ?? [ 'terminee' ];
	$p = [ 'phase' => $ph, 'etape' => $S['pi'], 'etapes' => count( $S['phases'] ) ];
	if ( $ph === 'fichiers' && $S['scan'] !== null ) {
		$p['fichiers'] = [ $S['scan']['fi'], count( $S['scan']['files'] ) ];
	}
	if ( $ph === 'base' && ! empty( $S['dbst']['tables'] ) ) {
		$p['tables'] = [ $S['dbst']['ti'], count( $S['dbst']['tables'] ) ];
	}
	return $p;
}
function wd_steps_result( array $S ): array {
	return [ wd_output( $S['R'], $S['opt'], $S['mode'] ), $S['roots'], $S['R'] ];
}
/** Localise les filtres de mise à jour par fichier (seconde passe légère, seulement si le code en contient). */
function wd_update_filters_locate( WdReport $R, string $root, array &$ctx ): void {
	if ( empty( $ctx['update_filters'] ) || ! is_dir( $root . '/wp-content' ) ) {
		return;
	}
	$want = [];
	foreach ( $ctx['update_filters'] as $f ) {
		$want[ $f[0] ] = true;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/wp-content', FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD );
	foreach ( $it as $file ) {
		if ( ! preg_match( '/\.php$/', $file->getFilename() ) || $file->getSize() > 2097152 ) {
			continue;
		}
		$c = (string) file_get_contents( $file->getPathname() );
		if ( ! preg_match( '/auto_update|automatic_updater|file_mod_allowed|pre_http_request|update_(core|plugins|themes)/', $c ) ) {
			continue;
		}
		$P = wd_php_analyze( $c, 0, false );
		foreach ( $P->updateFilters as [ $hook, $cb, $line ] ) {
			if ( preg_match( '/__return_(false|null|zero|empty_array|true)/', $cb ) ) {
				$ctx['update_filters_by_file'][ wd_rel( $root, $file->getPathname() ) ][] = 'L' . $line . ' ' . $hook . ' → ' . $cb;
			}
		}
	}
}
function wd_write_out( WdReport $R, string $file, string $data, array $roots ): bool {
	$dir = realpath( dirname( $file ) );
	if ( $dir === false ) {
		fwrite( STDERR, "Dossier de sortie introuvable : $file\n" );
		return false;
	}
	foreach ( $roots as $root ) {
		if ( wd_starts( wd_norm( $dir ) . '/', $root . '/' ) ) {
			fwrite( STDERR, "Refus : la sortie $file est dans une racine analysée ($root). Écrire hors du docroot.\n" );
			return false;
		}
	}
	if ( file_put_contents( $file, $data ) === false ) {
		fwrite( STDERR, "Écriture impossible : $file\n" );
		return false;
	}
	return true;
}

// ============================================================ Points d'entrée

function wd_cli( array $argv ): int {
	umask( 0077 );
	$opt = wd_parse_opts( $argv );
	if ( isset( $opt['help'] ) || count( $argv ) === 1 ) {
		echo wd_help(), "\n";
		return 0;
	}
	if ( ! empty( $opt['sql'] ) && ! isset( $opt['offline'] ) ) {
		$opt['offline'] = true;
	}
	$mode = ! empty( $opt['offline'] ) ? 'hors_ligne' : 'serveur_cli';
	[ $doc, $roots ] = wd_run( $opt, $mode );
	$json = json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
	if ( $json === false ) {
		fwrite( STDERR, 'Encodage JSON impossible : ' . json_last_error_msg() . "\n" );
		return 3;
	}
	if ( ! empty( $opt['json'] ) && ! wd_write_out( new WdReport(), (string) $opt['json'], $json, $roots ) ) {
		return 3;
	}
	echo ( ( $opt['format'] ?? 'text' ) === 'json' ) ? $json . "\n" : wd_text_summary( $doc );
	return isset( $doc['comptes_par_statut'][ WD_CONF ] ) ? 2 : 1;
}

// Ligne de commande = pas de requête HTTP entrante (couvre cli, cli-server, cgi-fcgi lancé par une
// tâche planifiée du panneau). Par le web, seul le fichier généré par build-drop.php répond (WD_EMBEDDED).
$wd_is_http = isset( $_SERVER['REQUEST_METHOD'] ) || isset( $_SERVER['GATEWAY_INTERFACE'] ) && ! isset( $GLOBALS['argv'] );
if ( ! defined( 'WD_EMBEDDED' ) ) {
	if ( $wd_is_http ) {
		http_response_code( 404 );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	} elseif ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
		// Uniquement quand detect.php EST le script lancé ; jamais quand un outil l'inclut pour ses fonctions.
		exit( wd_cli( $GLOBALS['argv'] ?? [ 'detect.php' ] ) );
	}
}
