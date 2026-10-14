<?php
/**
 * Migration engine: collects URLs, extracts and cleans content, creates posts and imports images.
 * UI-agnostic: used by both the admin screen and WP-CLI.
 */

defined( 'ABSPATH' ) || exit;

class Site_Migrator {

	const META_SOURCE = '_sm_source_url';
	const META_PATH   = '_sm_source_path';

	/** Tags that are kept, and the attributes allowed on each. */
	private $allowed = array(
		'p' => array(), 'h1' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(), 'h5' => array(), 'h6' => array(),
		'ul' => array(), 'ol' => array(), 'li' => array(),
		'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'u' => array(), 'br' => array(),
		'blockquote' => array(), 'figure' => array(), 'figcaption' => array(),
		'table' => array(), 'thead' => array(), 'tbody' => array(), 'tr' => array(),
		'th' => array( 'colspan', 'rowspan' ), 'td' => array( 'colspan', 'rowspan' ),
		'a' => array( 'href', 'title' ),
	);

	/** Tags removed together with all their content. */
	private $drop_tags = array( 'script', 'style', 'noscript', 'svg', 'form', 'button', 'input', 'select', 'textarea', 'label', 'nav', 'header', 'footer', 'canvas', 'template', 'object', 'embed', 'audio', 'link', 'meta' );

	/** Wrappers removed while keeping their content; loose text inside them is wrapped in <p>. */
	private $block_wrappers = array( 'div', 'section', 'article', 'main', 'aside', 'center' );

	/** Inline elements (grouped inside <p> when left loose). */
	private $inline_tags = array( 'a', 'strong', 'b', 'em', 'i', 'u', 'span', 'br', 'img' );

	private $opts;
	private $out;
	private $root;
	private $current_url = '';
	private $old_hosts   = array();
	private $warnings    = array();

	public function __construct( $opts = array() ) {
		$this->opts = self::sanitize_options( $opts );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/* ------------------------------------------------------------------ */
	/*  Options                                                           */
	/* ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'sitemap'             => '',
			'urls'                => '',
			'selector'            => 'body',
			'xpath'               => '',
			'exclude_selector'    => '',
			'include_urls'        => '',
			'exclude_urls'        => '',
			'post_type'           => 'page',
			'status'              => 'draft',
			'seo'                 => 'none',
			'keep_span'           => false,
			'keep_h1'             => false,
			'keep_absolute_links' => false,
			'featured'            => false,
			'update'              => false,
			'mode'                => 'all',       // all | content | images
			'content_images'      => 'remove',    // content mode: remove | keep (pointing to the original site)
			'image_scope'         => 'container', // images mode: container | page
			'backgrounds'         => true,        // images mode: also import CSS background images
		);
	}

	public static function sanitize_options( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$o   = array();

		foreach ( self::defaults() as $key => $default ) {
			$value = array_key_exists( $key, $raw ) ? $raw[ $key ] : $default;
			if ( is_bool( $default ) ) {
				$o[ $key ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			} elseif ( 'urls' === $key ) {
				$o[ $key ] = sanitize_textarea_field( (string) $value );
			} elseif ( 'sitemap' === $key ) {
				$o[ $key ] = esc_url_raw( trim( (string) $value ) );
			} else {
				$o[ $key ] = trim( sanitize_text_field( (string) $value ) );
			}
		}

		if ( ! post_type_exists( $o['post_type'] ) ) {
			$o['post_type'] = 'page';
		}
		if ( ! in_array( $o['status'], array( 'draft', 'pending', 'private', 'publish' ), true ) ) {
			$o['status'] = 'draft';
		}
		if ( ! in_array( $o['seo'], array( 'none', 'yoast', 'rankmath' ), true ) ) {
			$o['seo'] = 'none';
		}
		if ( '' === $o['selector'] ) {
			$o['selector'] = 'body';
		}
		if ( ! in_array( $o['mode'], array( 'all', 'content', 'images' ), true ) ) {
			$o['mode'] = 'all';
		}
		if ( ! in_array( $o['content_images'], array( 'remove', 'keep' ), true ) ) {
			$o['content_images'] = 'remove';
		}
		if ( ! in_array( $o['image_scope'], array( 'container', 'page' ), true ) ) {
			$o['image_scope'] = 'container';
		}
		return $o;
	}

	public function get_options() {
		return $this->opts;
	}

	public function get_warnings() {
		return $this->warnings;
	}

	/* ------------------------------------------------------------------ */
	/*  Public API                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Returns the list of URLs to migrate (filtered, parents before children).
	 *
	 * @throws Exception If there is no source or a regular expression is invalid.
	 */
	public function collect_urls() {
		$this->warnings = array();

		if ( '' !== trim( $this->opts['urls'] ) ) {
			$urls = preg_split( '/\R+/', $this->opts['urls'] );
		} elseif ( $this->opts['sitemap'] ) {
			$urls = $this->read_sitemap( $this->opts['sitemap'] );
		} else {
			throw new Exception( __( 'Enter a sitemap URL or a list of URLs.', 'site-migrator' ) );
		}

		$include = $this->build_regex( $this->opts['include_urls'] );
		$exclude = $this->build_regex( $this->opts['exclude_urls'] );

		$urls = array_map( 'trim', $urls );
		$urls = array_filter( $urls, function ( $u ) use ( $include, $exclude ) {
			if ( ! preg_match( '#^https?://#i', $u ) ) {
				return false;
			}
			$path = (string) wp_parse_url( $u, PHP_URL_PATH );
			if ( preg_match( '/\.(pdf|jpe?g|png|gif|webp|svg|zip|rar|docx?|xlsx?|pptx?|mp4|mp3)$/i', $path ) ) {
				return false;
			}
			if ( $include && ! preg_match( $include, $u ) ) {
				return false;
			}
			if ( $exclude && preg_match( $exclude, $u ) ) {
				return false;
			}
			return true;
		} );

		$urls = array_values( array_unique( $urls ) );

		usort( $urls, function ( $a, $b ) {
			return substr_count( self::normalize_path( $a ), '/' ) - substr_count( self::normalize_path( $b ), '/' );
		} );

		return $urls;
	}

	/**
	 * Downloads and cleans a page without writing anything to the database.
	 *
	 * @throws Exception If the page cannot be downloaded or the selector finds no content.
	 */
	public function parse( $url ) {
		$this->current_url = $url;
		$this->warnings    = array();

		$host            = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$bare            = preg_replace( '/^www\./', '', $host );
		$this->old_hosts = array( $bare, 'www.' . $bare );

		$html = $this->fetch( $url );
		if ( ! mb_check_encoding( $html, 'UTF-8' ) ) {
			$html = mb_convert_encoding( $html, 'UTF-8', 'ISO-8859-1' );
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		$xp = new DOMXPath( $dom );

		$meta = array(
			'title'       => $this->xp_value( $xp, '//title' ),
			'description' => $this->xp_value( $xp, '//meta[@name="description"]/@content' ),
			'og_title'    => $this->xp_value( $xp, '//meta[@property="og:title"]/@content' ),
			'og_image'    => $this->xp_value( $xp, '//meta[@property="og:image"]/@content' ),
		);

		if ( $this->opts['exclude_selector'] ) {
			$excluded = @$xp->query( $this->css_to_xpath( $this->opts['exclude_selector'] ) );
			if ( false === $excluded ) {
				throw new Exception( __( 'The exclusion selector is not valid.', 'site-migrator' ) );
			}
			foreach ( iterator_to_array( $excluded ) as $n ) {
				if ( $n->parentNode ) {
					$n->parentNode->removeChild( $n );
				}
			}
		}

		$this->out  = new DOMDocument( '1.0', 'UTF-8' );
		$this->root = $this->out->createElement( 'div' );
		$this->out->appendChild( $this->root );

		// "Images only" + "whole page" does not need the content container.
		$whole_page = 'images' === $this->opts['mode'] && 'page' === $this->opts['image_scope'];
		$picked     = array();

		if ( ! $whole_page ) {
			$query = $this->opts['xpath'] ? $this->opts['xpath'] : $this->css_to_xpath( $this->opts['selector'] );
			$nodes = @$xp->query( $query );
			if ( false === $nodes ) {
				throw new Exception( __( 'The selector or XPath is not valid.', 'site-migrator' ) );
			}
			if ( ! $nodes->length ) {
				throw new Exception( __( 'The selector found no content on this page. Check it; if it is correct, the page is probably rendered with JavaScript.', 'site-migrator' ) );
			}

			// If the selector matches nested elements, only the outermost is used to avoid duplicates.
			foreach ( $nodes as $n ) {
				foreach ( $picked as $p ) {
					if ( $this->is_descendant( $n, $p ) ) {
						continue 2;
					}
				}
				$picked[] = $n;
			}

			$tmp = $this->out->createElement( 'div' );
			foreach ( $picked as $node ) {
				$this->clean_children( $node, $tmp );
			}
			$this->flush_wrapped( $tmp, $this->root );
			$this->remove_empty( $this->out, $this->root );
		}

		// Title: first H1 in the content → og:title → <title>.
		$title = '';
		$h1    = $this->root->getElementsByTagName( 'h1' )->item( 0 );
		if ( $h1 ) {
			$title = trim( preg_replace( '/\s+/u', ' ', $h1->textContent ) );
			if ( ! $this->opts['keep_h1'] ) {
				$h1->parentNode->removeChild( $h1 );
			}
		}
		if ( ! $title ) {
			$title = $meta['og_title'] ? $meta['og_title'] : $meta['title'];
		}

		$path = self::normalize_path( $url );
		if ( ! $title ) {
			$title = $path ? $path : __( 'Home', 'site-migrator' );
		}

		$images = array();
		$seen   = array();
		if ( $whole_page ) {
			foreach ( $xp->query( '//body//img[not(ancestor::noscript)]' ) as $img ) {
				$src = $this->image_source( $img );
				if ( ! $src || isset( $seen[ $src ] ) ) {
					continue;
				}
				$seen[ $src ] = true;
				$images[]     = array( 'src' => $src, 'alt' => trim( preg_replace( '/\s+/u', ' ', $img->getAttribute( 'alt' ) ) ) );
			}
		} else {
			foreach ( $this->root->getElementsByTagName( 'img' ) as $img ) {
				$src = $img->getAttribute( 'src' );
				if ( isset( $seen[ $src ] ) ) {
					continue;
				}
				$seen[ $src ] = true;
				$images[]     = array( 'src' => $src, 'alt' => $img->getAttribute( 'alt' ) );
			}
		}

		if ( 'images' === $this->opts['mode'] && $this->opts['backgrounds'] ) {
			$scopes = $whole_page ? array( $dom->documentElement ) : $picked;
			foreach ( $this->background_sources( $xp, $scopes, $whole_page ) as $src ) {
				if ( isset( $seen[ $src ] ) ) {
					continue;
				}
				$seen[ $src ] = true;
				$images[]     = array( 'src' => $src, 'alt' => '', 'type' => 'background' );
			}
		}

		return array(
			'url'       => $url,
			'key'       => self::url_key( $url ),
			'path'      => $path,
			'slug'      => $path ? sanitize_title( basename( $path ) ) : sanitize_title( _x( 'home', 'slug for the homepage', 'site-migrator' ) ),
			'parent_id' => $this->find_parent( $path ),
			'title'     => $title,
			'meta'      => $meta,
			'content'   => $this->serialize( $this->out, $this->root ),
			'images'    => $images,
			'featured'  => $meta['og_image'] ? esc_url_raw( $this->absolutize( $meta['og_image'] ) ) : '',
			'warnings'  => $this->warnings,
		);
	}

	/**
	 * Creates or updates the post for a URL. Images are imported afterwards with import_image().
	 *
	 * @throws Exception If downloading, parsing or inserting fails.
	 */
	public function import_page( $url ) {
		// Images only: no post is created or changed; the caller imports each image unattached.
		if ( 'images' === $this->opts['mode'] ) {
			$page = $this->parse( $url );
			return array(
				'status'   => 'processed',
				'post_id'  => 0,
				'url'      => $url,
				'old_path' => '/' . $page['path'],
				'new_path' => '',
				'title'    => $page['title'],
				'images'   => $page['images'],
				'featured' => '',
				'warnings' => $page['warnings'],
			);
		}

		$key      = self::url_key( $url );
		$path     = self::normalize_path( $url );
		$existing = $this->find_by_meta( $this->opts['post_type'], self::META_SOURCE, $key );

		if ( $existing && ! $this->opts['update'] ) {
			return array(
				'status'   => 'skipped',
				'post_id'  => $existing,
				'url'      => $url,
				'old_path' => '/' . $path,
				'new_path' => $this->new_path( $existing ),
				'title'    => get_the_title( $existing ),
				'images'   => array(),
				'featured' => '',
				'warnings' => array( __( 'Already migrated. Enable "Update already migrated" to overwrite it.', 'site-migrator' ) ),
			);
		}

		$page = $this->parse( $url );

		$postarr = array(
			'post_type'    => $this->opts['post_type'],
			'post_status'  => $this->opts['status'],
			'post_title'   => $page['title'],
			'post_name'    => $page['slug'],
			'post_content' => $page['content'],
			'post_author'  => get_current_user_id() ? get_current_user_id() : 1,
			'post_parent'  => $page['parent_id'],
		);
		if ( $existing ) {
			$postarr['ID'] = $existing;
		}

		$post_id = $this->save_post( $postarr );
		if ( is_wp_error( $post_id ) ) {
			throw new Exception( $post_id->get_error_message() );
		}

		update_post_meta( $post_id, self::META_SOURCE, $key );
		update_post_meta( $post_id, self::META_PATH, $page['path'] );
		$this->save_seo( $post_id, $page['meta'] );

		$with_images = 'all' === $this->opts['mode'];
		if ( ! $with_images && $page['images'] ) {
			/* translators: %d: number of images. */
			$page['warnings'][] = sprintf( __( 'Images left pointing to the original site: %d', 'site-migrator' ), count( $page['images'] ) );
		}

		return array(
			'status'   => $existing ? 'updated' : 'created',
			'post_id'  => $post_id,
			'url'      => $url,
			'old_path' => '/' . $page['path'],
			'new_path' => $this->new_path( $post_id ),
			'title'    => $page['title'],
			'images'   => $with_images ? $page['images'] : array(),
			'featured' => ( $with_images && $this->opts['featured'] ) ? $page['featured'] : '',
			'warnings' => $page['warnings'],
		);
	}

	/**
	 * Uploads an image to the Media Library and replaces its URL in the post content
	 * (or sets it as the featured image). With $post_id = 0 it is only uploaded, unattached.
	 *
	 * @return int|WP_Error Attachment ID.
	 */
	public function import_image( $post_id, $src, $alt = '', $as_featured = false ) {
		if ( ! $post_id ) {
			return $this->sideload( $src, 0, $alt ); // Images only: unattached upload.
		}
		$att = $this->sideload( $src, $post_id, $alt );
		if ( is_wp_error( $att ) ) {
			return $att;
		}
		if ( $as_featured ) {
			set_post_thumbnail( $post_id, $att );
		} else {
			$this->replace_image_src( $post_id, $src, $att );
		}
		return $att;
	}

	/* ------------------------------------------------------------------ */
	/*  URLs                                                               */
	/* ------------------------------------------------------------------ */

	private function read_sitemap( $url, $depth = 0 ) {
		if ( $depth > 3 ) {
			return array();
		}
		try {
			$body = $this->fetch( $url );
		} catch ( Exception $e ) {
			/* translators: 1: sitemap URL, 2: error message. */
			$this->warnings[] = sprintf( __( 'Could not read the sitemap %1$s: %2$s', 'site-migrator' ), $url, $e->getMessage() );
			return array();
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );
		libxml_clear_errors();
		if ( ! $xml ) {
			/* translators: %s: sitemap URL. */
			$this->warnings[] = sprintf( __( 'The sitemap %s is not valid XML.', 'site-migrator' ), $url );
			return array();
		}

		$urls = array();
		foreach ( $xml->sitemap as $sm ) {
			$urls = array_merge( $urls, $this->read_sitemap( trim( (string) $sm->loc ), $depth + 1 ) );
		}
		foreach ( $xml->url as $u ) {
			$urls[] = trim( (string) $u->loc );
		}
		return $urls;
	}

	private function build_regex( $pattern ) {
		if ( '' === $pattern ) {
			return '';
		}
		$regex = '#' . str_replace( '#', '\#', $pattern ) . '#i';
		if ( false === @preg_match( $regex, '' ) ) {
			/* translators: %s: regular expression. */
			throw new Exception( sprintf( __( 'The regular expression "%s" is not valid.', 'site-migrator' ), $pattern ) );
		}
		return $regex;
	}

	/** Normalized path without extension or index: "services/local-seo". */
	public static function normalize_path( $url ) {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$path = preg_replace( '/\.(html?|php|aspx?|jsp)$/i', '', $path );
		$path = preg_replace( '#(^|/)index$#i', '', $path );
		return trim( $path, '/' );
	}

	public static function url_key( $url ) {
		return untrailingslashit( strtok( $url, '#' ) );
	}

	/* ------------------------------------------------------------------ */
	/*  HTML cleanup                                                   */
	/* ------------------------------------------------------------------ */

	private function clean_children( DOMNode $src, DOMNode $dest ) {
		foreach ( iterator_to_array( $src->childNodes ) as $child ) {

			if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
				// Collapse line breaks; otherwise wpautop turns them into <br>.
				$text = preg_replace( '/\s+/u', ' ', $child->nodeValue );
				if ( '' !== $text ) {
					$dest->appendChild( $this->out->createTextNode( $text ) );
				}
				continue;
			}
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );

			if ( 'img' === $tag ) {
				$this->add_img( $child, $dest );
				continue;
			}
			if ( 'iframe' === $tag || 'video' === $tag ) {
				$this->add_embed( $child, $dest );
				continue;
			}
			if ( in_array( $tag, $this->drop_tags, true ) ) {
				continue;
			}

			$keep = isset( $this->allowed[ $tag ] ) || ( 'span' === $tag && $this->opts['keep_span'] );
			$href = '';
			if ( 'a' === $tag ) {
				$href = $this->fix_href( $child->getAttribute( 'href' ) );
				if ( '' === $href ) {
					$keep = false; // Empty or javascript: link, keep only the text.
				}
			}

			if ( $keep ) {
				$el    = $this->out->createElement( $tag );
				$attrs = isset( $this->allowed[ $tag ] ) ? $this->allowed[ $tag ] : array();
				foreach ( $attrs as $attr ) {
					if ( $child->hasAttribute( $attr ) ) {
						$el->setAttribute( $attr, 'href' === $attr ? $href : $child->getAttribute( $attr ) );
					}
				}
				$this->clean_children( $child, $el );
				$dest->appendChild( $el );
			} elseif ( in_array( $tag, $this->block_wrappers, true ) && 'div' === $dest->nodeName ) {
				$tmp = $this->out->createElement( 'div' );
				$this->clean_children( $child, $tmp );
				$this->flush_wrapped( $tmp, $dest );
			} else {
				$this->clean_children( $child, $dest ); // Drop the tag, keep its content.
			}
		}
	}

	/** Moves nodes from $from to $to, grouping loose text and inline elements inside <p>. */
	private function flush_wrapped( DOMNode $from, DOMNode $to ) {
		$p = null;
		foreach ( iterator_to_array( $from->childNodes ) as $node ) {
			$is_text = XML_TEXT_NODE === $node->nodeType;
			$inline  = $is_text || in_array( strtolower( $node->nodeName ), $this->inline_tags, true );
			if ( $inline ) {
				if ( ! $p ) {
					if ( $is_text && '' === trim( $node->nodeValue ) ) {
						continue;
					}
					$p = $this->out->createElement( 'p' );
					$to->appendChild( $p );
				}
				$p->appendChild( $node );
			} else {
				$p = null;
				$to->appendChild( $node );
			}
		}
	}

	private function remove_empty( DOMDocument $doc, DOMNode $root ) {
		$xp    = new DOMXPath( $doc );
		$nbsp  = "\xC2\xA0";
		$query = ".//*[not(self::img or self::br or self::td or self::th)][not(.//img)][normalize-space(translate(., '$nbsp', ' '))='']";
		foreach ( iterator_to_array( $xp->query( $query, $root ) ) as $n ) {
			if ( $n->parentNode ) {
				$n->parentNode->removeChild( $n );
			}
		}
	}

	private function add_img( DOMElement $img, DOMNode $dest ) {
		if ( 'content' === $this->opts['mode'] && 'remove' === $this->opts['content_images'] ) {
			return;
		}
		$src = $this->image_source( $img );
		if ( ! $src ) {
			return;
		}
		$el = $this->out->createElement( 'img' );
		$el->setAttribute( 'src', $src );
		$el->setAttribute( 'alt', trim( preg_replace( '/\s+/u', ' ', $img->getAttribute( 'alt' ) ) ) );
		$dest->appendChild( $el );
	}

	/** Best source of an <img>: largest srcset candidate, then lazy-load attributes, then src. */
	private function image_source( DOMElement $img ) {
		$src = '';
		foreach ( array( 'data-srcset', 'srcset' ) as $a ) {
			if ( $img->hasAttribute( $a ) ) {
				$src = $this->largest_from_srcset( $img->getAttribute( $a ) );
				if ( $src ) {
					break;
				}
			}
		}
		if ( ! $src ) {
			foreach ( array( 'data-src', 'data-lazy-src', 'data-original', 'src' ) as $a ) {
				$v = trim( $img->getAttribute( $a ) );
				if ( $v && 0 !== strpos( $v, 'data:' ) ) {
					$src = $v;
					break;
				}
			}
		}
		// esc_url_raw here ensures the stored URL matches the one replaced later.
		return $src ? esc_url_raw( $this->absolutize( $src ) ) : '';
	}

	/**
	 * Background images declared in CSS: style attributes and lazy-load data attributes inside
	 * $scopes and, when $style_tags is true, every <style> block on the page.
	 * Fonts, data: URIs and fragment references are ignored; SVG files are skipped with a warning.
	 */
	private function background_sources( DOMXPath $xp, $scopes, $style_tags ) {
		$css = array();
		foreach ( $scopes as $scope ) {
			if ( ! $scope ) {
				continue;
			}
			foreach ( $xp->query( './@style | .//@style', $scope ) as $attr ) {
				$css[] = $attr->value;
			}
			foreach ( array( 'data-bg', 'data-background', 'data-background-image' ) as $name ) {
				foreach ( $xp->query( "./@{$name} | .//@{$name}", $scope ) as $attr ) {
					$value = trim( $attr->value );
					if ( '' !== $value ) {
						$css[] = false !== stripos( $value, 'url(' ) ? $value : 'url(' . $value . ')';
					}
				}
			}
		}
		if ( $style_tags ) {
			foreach ( $xp->query( '//style' ) as $tag ) {
				$css[] = $tag->textContent;
			}
		}

		$found = array();
		$svg   = 0;
		foreach ( $css as $text ) {
			if ( ! preg_match_all( '/url\(\s*([\'"]?)(.+?)\1\s*\)/i', $text, $m ) ) {
				continue;
			}
			foreach ( $m[2] as $raw ) {
				$raw = trim( $raw );
				if ( '' === $raw || 0 === strpos( $raw, 'data:' ) || 0 === strpos( $raw, '#' ) ) {
					continue;
				}
				$path = (string) wp_parse_url( $raw, PHP_URL_PATH );
				if ( preg_match( '/\.(woff2?|ttf|otf|eot|css)$/i', $path ) ) {
					continue;
				}
				if ( preg_match( '/\.svg$/i', $path ) ) {
					$svg++;
					continue;
				}
				$found[] = esc_url_raw( $this->absolutize( $raw ) );
			}
		}

		if ( $svg ) {
			/* translators: %d: number of SVG files. */
			$this->warnings[] = sprintf( __( 'SVG background images skipped (WordPress does not accept them): %d', 'site-migrator' ), $svg );
		}
		return array_values( array_unique( array_filter( $found ) ) );
	}

	private function largest_from_srcset( $srcset ) {
		$best = '';
		$max  = -1;
		foreach ( preg_split( '/,\s+/', trim( $srcset ) ) as $item ) {
			$parts = preg_split( '/\s+/', trim( $item ) );
			if ( empty( $parts[0] ) || 0 === strpos( $parts[0], 'data:' ) ) {
				continue;
			}
			$w = isset( $parts[1] ) ? (float) $parts[1] : 1;
			if ( $w > $max ) {
				$max  = $w;
				$best = $parts[0];
			}
		}
		return $best;
	}

	/** YouTube and Vimeo become a bare URL in a paragraph; WordPress embeds it automatically. */
	private function add_embed( DOMElement $el, DOMNode $dest ) {
		$src = $el->getAttribute( 'src' ) ? $el->getAttribute( 'src' ) : $el->getAttribute( 'data-src' );
		$url = '';
		if ( preg_match( '#youtube(?:-nocookie)?\.com/embed/([\w-]{6,})#', $src, $m ) ) {
			$url = 'https://www.youtube.com/watch?v=' . $m[1];
		} elseif ( preg_match( '#player\.vimeo\.com/video/(\d+)#', $src, $m ) ) {
			$url = 'https://vimeo.com/' . $m[1];
		}
		if ( ! $url ) {
			$this->warnings[] = $src
				/* translators: 1: HTML tag name, 2: source URL. */
				? sprintf( __( 'Skipped an unsupported <%1$s>: %2$s', 'site-migrator' ), $el->nodeName, $src )
				/* translators: %s: HTML tag name. */
				: sprintf( __( 'Skipped an unsupported <%s>.', 'site-migrator' ), $el->nodeName );
			return;
		}
		$p = $this->out->createElement( 'p' );
		$p->appendChild( $this->out->createTextNode( $url ) );
		$dest->appendChild( $p );
	}

	private function fix_href( $href ) {
		$href = trim( $href );
		if ( '' === $href || 0 === stripos( $href, 'javascript:' ) ) {
			return '';
		}
		if ( preg_match( '/^(#|mailto:|tel:)/i', $href ) ) {
			return $href;
		}
		$abs = $this->absolutize( $href );
		if ( $this->opts['keep_absolute_links'] ) {
			return $abs;
		}
		$parts = wp_parse_url( $abs );
		if ( isset( $parts['host'] ) && in_array( strtolower( $parts['host'] ), $this->old_hosts, true ) ) {
			return ( isset( $parts['path'] ) ? $parts['path'] : '/' )
				. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' )
				. ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
		}
		return $abs;
	}

	private function absolutize( $url ) {
		$url = trim( $url );
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		$page   = wp_parse_url( $this->current_url );
		$scheme = isset( $page['scheme'] ) ? $page['scheme'] : 'https';
		if ( 0 === strpos( $url, '//' ) ) {
			return $scheme . ':' . $url;
		}
		$origin = $scheme . '://' . $page['host'] . ( isset( $page['port'] ) ? ':' . $page['port'] : '' );
		if ( 0 === strpos( $url, '/' ) ) {
			return $origin . $url;
		}
		$dir = isset( $page['path'] ) ? preg_replace( '#/[^/]*$#', '/', $page['path'] ) : '/';
		return $origin . $dir . $url;
	}

	/* ------------------------------------------------------------------ */
	/*  WordPress                                                          */
	/* ------------------------------------------------------------------ */

	/** Inserts or updates without creating a revision for every change. */
	private function save_post( $postarr ) {
		$priority = has_action( 'post_updated', 'wp_save_post_revision' );
		if ( false !== $priority ) {
			remove_action( 'post_updated', 'wp_save_post_revision', $priority );
		}

		$result = isset( $postarr['ID'] )
			? wp_update_post( wp_slash( $postarr ), true )
			: wp_insert_post( wp_slash( $postarr ), true );

		if ( false !== $priority ) {
			add_action( 'post_updated', 'wp_save_post_revision', $priority );
		}
		return $result;
	}

	private function sideload( $src, $post_id, $alt ) {
		$found = $this->find_by_meta( 'attachment', self::META_SOURCE, $src );
		if ( $found ) {
			return $found; // Already imported (e.g. a logo repeated across pages).
		}

		$tmp = download_url( $src, 60 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$mime = wp_get_image_mime( $tmp );
		$exts = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif' );
		if ( ! $mime || ! isset( $exts[ $mime ] ) ) {
			@unlink( $tmp );
			return new WP_Error( 'sm_format', __( 'Unsupported format (probably SVG or a file that is not an image).', 'site-migrator' ) );
		}

		// Ensure a correct extension: CDNs like Wix or Cloudinary often omit it.
		$name = sanitize_file_name( wp_basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) );
		$ext  = $exts[ $mime ];
		if ( ! preg_match( '/\.' . $ext . '$/i', $name ) ) {
			$base = pathinfo( $name, PATHINFO_FILENAME );
			$name = ( $base ? $base : 'image' ) . '.' . $ext;
		}

		$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $post_id, $alt ? $alt : null );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			return $id;
		}

		update_post_meta( $id, self::META_SOURCE, $src );
		if ( $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		return $id;
	}

	private function replace_image_src( $post_id, $old_src, $att_id ) {
		$content = get_post_field( 'post_content', $post_id, 'raw' );
		if ( '' === $content ) {
			return;
		}

		$doc = new DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div id="sm-root">' . $content . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();

		$xp   = new DOMXPath( $doc );
		$root = $xp->query( '//div[@id="sm-root"]' )->item( 0 );
		if ( ! $root ) {
			return;
		}

		$new     = wp_get_attachment_url( $att_id );
		$changed = false;
		foreach ( $root->getElementsByTagName( 'img' ) as $img ) {
			if ( $img->getAttribute( 'src' ) === $old_src ) {
				$img->setAttribute( 'src', $new );
				$img->setAttribute( 'class', 'wp-image-' . $att_id );
				$changed = true;
			}
		}

		if ( $changed ) {
			$this->save_post( array(
				'ID'           => $post_id,
				'post_content' => $this->serialize( $doc, $root ),
			) );
		}
	}

	private function save_seo( $post_id, $meta ) {
		$keys = array(
			'yoast'    => array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc' ),
			'rankmath' => array( 'rank_math_title', 'rank_math_description' ),
		);
		if ( ! isset( $keys[ $this->opts['seo'] ] ) ) {
			return;
		}
		list( $title_key, $desc_key ) = $keys[ $this->opts['seo'] ];
		if ( $meta['title'] ) {
			update_post_meta( $post_id, $title_key, $meta['title'] );
		}
		if ( $meta['description'] ) {
			update_post_meta( $post_id, $desc_key, $meta['description'] );
		}
	}

	private function find_parent( $path ) {
		if ( ! is_post_type_hierarchical( $this->opts['post_type'] ) || false === strpos( $path, '/' ) ) {
			return 0;
		}
		$parent_path = substr( $path, 0, strrpos( $path, '/' ) );
		return $this->find_by_meta( $this->opts['post_type'], self::META_PATH, $parent_path );
	}

	private function find_by_meta( $post_type, $key, $value ) {
		$found = get_posts( array(
			'post_type'        => $post_type,
			'post_status'      => 'attachment' === $post_type ? 'inherit' : 'any',
			'meta_key'         => $key,
			'meta_value'       => $value,
			'fields'           => 'ids',
			'numberposts'      => 1,
			'suppress_filters' => true,
		) );
		return $found ? (int) $found[0] : 0;
	}

	private function new_path( $post_id ) {
		if ( is_post_type_hierarchical( get_post_type( $post_id ) ) ) {
			return '/' . get_page_uri( $post_id ) . '/';
		}
		return '/' . get_post_field( 'post_name', $post_id ) . '/';
	}

	/* ------------------------------------------------------------------ */
	/*  Utilities                                                         */
	/* ------------------------------------------------------------------ */

	private function fetch( $url ) {
		$res = wp_remote_get( $url, array(
			'timeout'     => 30,
			'redirection' => 5,
			'user-agent'  => 'Mozilla/5.0 (compatible; SiteMigrator/' . SITE_MIGRATOR_VERSION . ')',
		) );
		if ( is_wp_error( $res ) ) {
			throw new Exception( $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			throw new Exception( sprintf( __( 'The server responded with HTTP %d.', 'site-migrator' ), $code ) );
		}
		return wp_remote_retrieve_body( $res );
	}

	private function serialize( DOMDocument $doc, DOMNode $root ) {
		$parts = array();
		foreach ( $root->childNodes as $n ) {
			$html = trim( $doc->saveHTML( $n ) );
			if ( '' !== $html ) {
				$parts[] = $html;
			}
		}
		return implode( "\n\n", $parts );
	}

	private function xp_value( DOMXPath $xp, $query ) {
		$n = $xp->query( $query );
		return ( $n && $n->length ) ? trim( preg_replace( '/\s+/u', ' ', $n->item( 0 )->nodeValue ) ) : '';
	}

	private function is_descendant( DOMNode $node, DOMNode $ancestor ) {
		for ( $p = $node->parentNode; $p; $p = $p->parentNode ) {
			if ( $p->isSameNode( $ancestor ) ) {
				return true;
			}
		}
		return false;
	}

	/** CSS → XPath converter for simple selectors: tag, #id, .class, [attr], [attr=value], descendant, > and commas. */
	private function css_to_xpath( $selector ) {
		$groups = array();
		foreach ( explode( ',', $selector ) as $group ) {
			$group = trim( preg_replace( '/\s*>\s*/', ' > ', trim( $group ) ) );
			if ( '' === $group ) {
				continue;
			}
			$xpath = '';
			$axis  = '//';
			foreach ( preg_split( '/\s+/', $group ) as $part ) {
				if ( '>' === $part ) {
					$axis = '/';
					continue;
				}
				preg_match( '/^([a-z0-9*]*)(.*)$/i', $part, $m );
				$tag   = $m[1] ? strtolower( $m[1] ) : '*';
				$conds = array();
				preg_match_all( '/([#.])([\w-]+)|\[([\w-]+)(?:=["\']?([^"\'\]]+)["\']?)?\]/', $m[2], $mm, PREG_SET_ORDER );
				foreach ( $mm as $c ) {
					if ( '#' === $c[1] ) {
						$conds[] = "@id='{$c[2]}'";
					} elseif ( '.' === $c[1] ) {
						$conds[] = "contains(concat(' ',normalize-space(@class),' '),' {$c[2]} ')";
					} elseif ( isset( $c[4] ) && '' !== $c[4] ) {
						$conds[] = "@{$c[3]}='{$c[4]}'";
					} else {
						$conds[] = "@{$c[3]}";
					}
				}
				$xpath .= $axis . $tag . ( $conds ? '[' . implode( ' and ', $conds ) . ']' : '' );
				$axis   = '//';
			}
			$groups[] = $xpath;
		}
		return implode( ' | ', $groups );
	}
}
