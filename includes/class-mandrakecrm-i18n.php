<?php
/**
 * MandrakeCRM I18n
 *
 * Fills translated formats without ever breaking the request: a translation whose placeholders do not match the
 * original text (a language pack or a .mo with one more %s, a stray % or %d in place of %s) makes sprintf() throw
 * in PHP 8 (ArgumentCountError, ValueError).
 *
 * @package    MandrakeCRM
 * @subpackage MandrakeCRM/includes
 * @author     MandrakeCRM <hello@mandrakecrm.com>
 * @copyright  2024-2026 MandrakeCRM
 * @license    GPL-2.0-or-later
 * @link       https://www.mandrakecrm.com
 * @since      3.32
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'MandrakeCRM_I18n' ) ) :

	/**
	 * Safe sprintf() for translated formats, and the translation bundled with the plugin.
	 *
	 * @since 3.32
	 * @since 3.33 bundled(), translate() and with_bundled(): the .mo of the plugin's own languages/ folder (moved here from the checkout).
	 */
	class MandrakeCRM_I18n {

		/**
		 * A text translated by the language pack or, when the pack does not have it yet, by the .mo bundled with the plugin.
		 *
		 * For plain texts (no placeholders) newer than the language pack installed from WordPress.org.
		 *
		 * @since 3.33
		 * @param string $translated Text returned by __().
		 * @param string $original   Original text (msgid).
		 * @return string
		 */
		public static function translate( $translated, $original ) {
			if ( is_string( $translated ) && '' !== trim( $translated ) && $translated !== $original ) {
				return $translated;
			}
			return self::bundled( $original );
		}

		/**
		 * Run a callback with the translation bundled with the plugin as the fallback of every __() of the mandrakecrm domain:
		 * a text the language pack does not have yet (gettext returns it untranslated) is looked up in the bundled .mo.
		 *
		 * @since 3.33
		 * @param callable $callback Callback (no arguments).
		 * @return mixed What the callback returns.
		 */
		public static function with_bundled( $callback ) {
			$filter = function ( $translation, $text ) {
				return ( $translation === $text && is_string( $text ) ) ? self::bundled( $text ) : $translation;
			};
			add_filter( 'gettext_mandrakecrm', $filter, 10, 2 );
			try {
				return call_user_func( $callback );
			} finally {
				remove_filter( 'gettext_mandrakecrm', $filter, 10 );
			}
		}

		/**
		 * Translation of a text from the .mo bundled with the plugin, for the current locale.
		 *
		 * Fallback for texts newer than the language pack installed from WordPress.org: WordPress loads only that
		 * pack, so a text added in a version not published there yet would show in English. Tries the exact locale,
		 * then es_ES for any Spanish and pt_BR for any Portuguese. Returns the text itself when nothing matches.
		 * Only that text is looked up (no load_textdomain() of the whole file): no other translation changes.
		 *
		 * @since 3.32 (MandrakeCRM_Checkout::bundled_translation())
		 * @since 3.33 Here, public.
		 * @param string $text Original text (msgid).
		 * @return string
		 */
		public static function bundled( $text ) {
			static $files = array();

			$locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : (string) get_locale();
			if ( '' === $locale || 0 === strpos( $locale, 'en' ) || ! preg_match( '/^[A-Za-z]{2,3}(_[A-Za-z0-9]{2,8})*$/', $locale ) ) {
				return $text;
			}

			if ( ! array_key_exists( $locale, $files ) ) {
				$files[ $locale ] = null;
				$candidates       = array( $locale );
				$language         = strtok( $locale, '_' );
				if ( 'es' === $language ) {
					$candidates[] = 'es_ES';
				} elseif ( 'pt' === $language ) {
					$candidates[] = 'pt_BR';
				}
				foreach ( $candidates as $candidate ) {
					$path = MANDRAKECRM_PLUGIN_DIR . 'languages/mandrakecrm-' . $candidate . '.mo';
					if ( ! is_readable( $path ) ) {
						continue;
					}
					if ( ! class_exists( 'MO' ) && defined( 'ABSPATH' ) && defined( 'WPINC' ) && is_readable( ABSPATH . WPINC . '/pomo/mo.php' ) ) {
						require_once ABSPATH . WPINC . '/pomo/mo.php';
					}
					if ( ! class_exists( 'MO' ) ) {
						break;
					}
					$mo = new MO();
					if ( $mo->import_from_file( $path ) ) {
						$files[ $locale ] = $mo;
						break;
					}
				}
			}

			if ( null === $files[ $locale ] ) {
				return $text;
			}
			$translated = $files[ $locale ]->translate( $text );
			return ( is_string( $translated ) && '' !== $translated ) ? $translated : $text;
		}

		/**
		 * Fill a translated format with its arguments.
		 *
		 * The translation is used only when it has exactly the conversions of the original text (positional ones may
		 * be reordered) and sprintf() does not fail; otherwise the original English text is used.
		 *
		 * @since 3.32
		 * @param string $translated Format returned by __().
		 * @param string $original   Original format (msgid).
		 * @param mixed  ...$args    Arguments.
		 * @return string
		 */
		public static function format( $translated, $original, ...$args ) {
			$text = ( $translated !== $original ) ? self::try_format( $translated, $original, $args ) : null;
			if ( null === $text ) {
				$text = self::try_format( $original, $original, $args );
			}
			return null === $text ? (string) $original : $text;
		}

		/**
		 * Run sprintf() when the format has the conversions of the original; null otherwise or when it fails.
		 *
		 * @since 3.32
		 * @param mixed  $format   Format.
		 * @param string $original Original format.
		 * @param array  $args     Arguments.
		 * @return string|null
		 */
		private static function try_format( $format, $original, array $args ) {
			if ( ! is_string( $format ) || '' === trim( $format ) ) {
				return null;
			}
			$expected = self::conversions( $original );
			if ( null === $expected || self::conversions( $format ) !== $expected ) {
				return null;
			}
			try {
				$text = sprintf( $format, ...$args );
			} catch ( \Throwable $e ) {
				return null;
			}
			return ( is_string( $text ) && '' !== trim( $text ) ) ? $text : null;
		}

		/**
		 * Conversions of a format, as argument number => specifier; null when the format has a stray %.
		 *
		 * @since 3.32
		 * @param string $format Format.
		 * @return array|null
		 */
		private static function conversions( $format ) {
			$rest = str_replace( '%%', '', (string) $format );
			$n    = preg_match_all( "/%(?:(\d+)\\$)?(?:[-+ 0]|'.)*\d*(?:\.\d+)?([bcdeEfFgGosuxX])/", $rest, $matches, PREG_SET_ORDER );
			if ( false === $n || substr_count( $rest, '%' ) !== $n ) {
				return null;
			}
			$map  = array();
			$next = 1;
			foreach ( $matches as $m ) {
				$arg = '' !== $m[1] ? (int) $m[1] : $next++;
				if ( $arg < 1 || ( isset( $map[ $arg ] ) && $map[ $arg ] !== $m[2] ) ) {
					return null;
				}
				$map[ $arg ] = $m[2];
			}
			ksort( $map );
			return $map;
		}
	}

endif;
