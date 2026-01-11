<?php
// Enable common theme supports
add_action('after_setup_theme', function (): void {
  add_theme_support('wp-block-styles');
  add_theme_support('editor-styles');
  add_theme_support('title-tag');
  add_theme_support('post-thumbnails');
  add_theme_support('custom-logo', [
    'height'      => 64,
    'width'       => 240,
    'flex-height' => true,
    'flex-width'  => true,
  ]);
  add_theme_support('menus');
  register_nav_menus([
    'primary' => __('Primary Menu', 'cryo'),
    'footer'  => __('Footer Menu', 'cryo'),
    'footer_legal' => __('Footer Legal Menu', 'cryo'),
  ]);
});

if (!function_exists('cryo_seed_custom_logo_if_missing')) {
  /**
   * Ensure the theme uses the shipped Figma `logo.png` as the WP Custom Logo.
   *
   * Why: if the theme was already active before we added logo seeding, `after_switch_theme`
   * won't run again, and the header's `site-logo` block will appear blank/wrong.
   *
   * This runs cheaply on `init` and only does work once.
   */
  function cryo_seed_custom_logo_if_missing(): void {
    if (get_theme_mod('custom_logo')) {
      return;
    }

    $seeded_id = (int) get_option('cryo_seeded_custom_logo', 0);
    if ($seeded_id > 0) {
      set_theme_mod('custom_logo', $seeded_id);
      return;
    }

    $source_path = get_template_directory() . '/assets/images/logo.png';
    if (!file_exists($source_path)) {
      return;
    }

    $contents = file_get_contents($source_path);
    if ($contents === false) {
      return;
    }

    $upload = wp_upload_bits('cryo-logo.png', null, $contents);
    if (!empty($upload['error']) || empty($upload['file'])) {
      return;
    }

    $filetype = wp_check_filetype($upload['file'], null);
    $attachment_id = wp_insert_attachment([
      'post_mime_type' => $filetype['type'] ?? 'image/png',
      'post_title'     => 'Cryo Logo',
      'post_content'   => '',
      'post_status'    => 'inherit',
    ], $upload['file']);

    if (is_wp_error($attachment_id) || !$attachment_id) {
      return;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attach_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
    wp_update_attachment_metadata($attachment_id, $attach_data);

    update_option('cryo_seeded_custom_logo', (int) $attachment_id, true);
    set_theme_mod('custom_logo', (int) $attachment_id);
  }
}

// Seed on activation + also on normal loads (covers cases where theme was already active).
add_action('after_switch_theme', 'cryo_seed_custom_logo_if_missing');
add_action('init', 'cryo_seed_custom_logo_if_missing');

// Attempt to auto-assign existing menus to theme locations if none are set or stale.
add_action('init', function (): void {
  $locations = get_theme_mod('nav_menu_locations', []);
  $menus = wp_get_nav_menus();
  if (empty($menus)) {
    return;
  }

  // Build a set of valid menu IDs for quick lookup.
  $valid_ids = [];
  foreach ($menus as $menu) {
    $valid_ids[(int) $menu->term_id] = true;
  }

  // Helper: check if a stored menu ID is still valid.
  $is_valid = static function ($id) use ($valid_ids): bool {
    return !empty($id) && isset($valid_ids[(int) $id]);
  };

  $pick_menu_id = static function (array $needles) use ($menus): int {
    foreach ($menus as $menu) {
      $name = strtolower((string) $menu->name);
      foreach ($needles as $n) {
        if ($n !== '' && str_contains($name, $n)) {
          return (int) $menu->term_id;
        }
      }
    }
    return 0;
  };

  // Primary — reassign if empty OR if stored ID no longer exists.
  if (empty($locations['primary']) || !$is_valid($locations['primary'])) {
    $id = $pick_menu_id(['primary', 'main', '主', '主要']);
    if ($id === 0) $id = (int) $menus[0]->term_id;
    $locations['primary'] = $id;
  }

  // Footer (middle column in Cryo footer) — reassign if empty OR stale.
  if (empty($locations['footer']) || !$is_valid($locations['footer'])) {
    $id = $pick_menu_id(['footer', '頁尾', '底', 'footer menu']);
    if ($id > 0) {
      $locations['footer'] = $id;
    }
  }

  // Footer legal (bottom row policy links) — reassign if empty OR stale.
  if (empty($locations['footer_legal']) || !$is_valid($locations['footer_legal'])) {
    $id = $pick_menu_id(['policy', 'legal', 'privacy', 'refund', '退款', '隱私', '政策', '條款', 'reference', '參考']);
    if ($id === 0) {
      // If menu names aren't descriptive, scan menu items once to find a likely legal/policy menu.
      foreach ($menus as $menu) {
        $items = wp_get_nav_menu_items($menu->term_id);
        if (empty($items) || is_wp_error($items)) {
          continue;
        }
        foreach ($items as $it) {
          $t = strtolower((string) ($it->title ?? ''));
          if (
            str_contains($t, 'privacy') ||
            str_contains($t, 'refund') ||
            str_contains($t, 'policy') ||
            str_contains($t, 'reference') ||
            str_contains($t, '隱私') ||
            str_contains($t, '退款') ||
            str_contains($t, '參考') ||
            str_contains($t, 'biolife')
          ) {
            $id = (int) $menu->term_id;
            break 2;
          }
        }
      }
    }
    if ($id > 0) {
      $locations['footer_legal'] = $id;
    }
  }

  set_theme_mod('nav_menu_locations', $locations);
});

/**
 * Footer menu safety:
 * Do NOT let core/navigation fall back to the primary menu for the footer legal strip.
 * If no `footer_legal` menu is assigned, render nothing instead of duplicating header-like nav items.
 */
add_filter('render_block', function (string $block_content, array $block): string {
  if (($block['blockName'] ?? null) !== 'core/navigation') {
    return $block_content;
  }

  $attrs = $block['attrs'] ?? [];
  $location = (string) ($attrs['__unstableLocation'] ?? '');
  $class = (string) ($attrs['className'] ?? '');

  $is_footer_legal = ($location === 'footer_legal') || str_contains($class, 'cryo-footer__legal');
  if (!$is_footer_legal) {
    return $block_content;
  }

  $locations = get_theme_mod('nav_menu_locations', []);
  $menu_id = (int) ($locations['footer_legal'] ?? 0);
  if ($menu_id <= 0) {
    return '';
  }
  return $block_content;
}, 15, 2);

/**
 * Shortcode to render classic WP menus inside block template parts.
 *
 * Why:
 * - Core Navigation block can fall back to the primary menu in some setups
 * - We want footer to respect Appearance → Menus location assignments exactly
 *
 * Usage:
 * - [cryo_footer_menu location="footer" class="cryo-footer__nav"]
 * - [cryo_footer_menu location="footer_legal" class="cryo-footer__legal"]
 */
add_shortcode('cryo_footer_menu', function ($atts): string {
  $atts = shortcode_atts([
    'location' => '',
    'class' => '',
  ], (array) $atts, 'cryo_footer_menu');

  $location = (string) $atts['location'];
  if ($location === '') {
    return '';
  }

  $locations = get_theme_mod('nav_menu_locations', []);
  $menu_id = (int) ($locations[$location] ?? 0);
  if ($menu_id <= 0) {
    return '';
  }

  $menu_html = wp_nav_menu([
    'theme_location' => $location,
    'container' => 'nav',
    'container_class' => trim((string) $atts['class']),
    'menu_class' => 'cryo-footerMenu__list',
    'fallback_cb' => false,
    'echo' => false,
  ]);

  return is_string($menu_html) ? $menu_html : '';
});

/**
 * Shortcode to render the primary header navigation using classic WP menus.
 *
 * Why:
 * - The Navigation block's `__unstableLocation` attribute is unreliable
 * - Database-stored template part overrides can interfere with the Navigation block
 * - Using `wp_nav_menu()` ensures the menu respects Appearance → Menus assignments
 *
 * Usage:
 * - [cryo_primary_menu class="cryo-nav__primaryNav"]
 *
 * This renders a proper block navigation structure that matches WordPress core styles.
 */
add_shortcode('cryo_primary_menu', function ($atts): string {
  $atts = shortcode_atts([
    'class' => 'cryo-nav__primaryNav',
  ], (array) $atts, 'cryo_primary_menu');

  $class = trim((string) ($atts['class'] ?? 'cryo-nav__primaryNav'));

  $locations = get_theme_mod('nav_menu_locations', []);
  $menu_id = (int) ($locations['primary'] ?? 0);
  if ($menu_id <= 0) {
    return '<!-- cryo_primary_menu: no menu assigned to "primary" location -->';
  }

  // Custom walker to output block-navigation compatible HTML structure.
  $menu_html = wp_nav_menu([
    'theme_location' => 'primary',
    'container' => 'nav',
    'container_class' => $class . ' wp-block-navigation is-layout-flex wp-block-navigation-is-layout-flex',
    'menu_class' => 'wp-block-navigation__container ' . $class . ' wp-block-navigation',
    'items_wrap' => '<ul id="%1$s" class="%2$s">%3$s</ul>',
    'fallback_cb' => false,
    'echo' => false,
    'depth' => 3,
    'walker' => new Cryo_Nav_Walker(),
  ]);

  return is_string($menu_html) ? $menu_html : '';
});

/**
 * Custom Walker for primary navigation.
 *
 * Outputs menu items in a structure compatible with WordPress block navigation CSS,
 * including proper submenu handling with interactive attributes.
 */
if (!class_exists('Cryo_Nav_Walker')) {
  class Cryo_Nav_Walker extends Walker_Nav_Menu {
    /**
     * Starts the list before the elements are added.
     */
    public function start_lvl(&$output, $depth = 0, $args = null) {
      $indent = str_repeat("\t", $depth);
      $output .= "\n{$indent}<ul data-wp-on--focus=\"actions.openMenuOnFocus\" class=\"wp-block-navigation__submenu-container\">\n";
    }

    /**
     * Ends the list after the elements are added.
     */
    public function end_lvl(&$output, $depth = 0, $args = null) {
      $indent = str_repeat("\t", $depth);
      $output .= "{$indent}</ul>\n";
    }

    /**
     * Starts the element output.
     */
    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0) {
      $indent = ($depth) ? str_repeat("\t", $depth) : '';
      $classes = empty($item->classes) ? [] : (array) $item->classes;

      // Add WordPress block navigation classes
      $classes[] = 'wp-block-navigation-item';
      $classes[] = 'menu-item';
      $classes[] = 'menu-item-' . $item->ID;

      // Check if this item has children
      $has_children = in_array('menu-item-has-children', $classes, true);

      if ($has_children) {
        $classes[] = 'has-child';
        $classes[] = 'open-on-hover-click';
        $classes[] = 'wp-block-navigation-submenu';
      } else {
        $classes[] = 'wp-block-navigation-link';
      }

      // Filter and join classes
      $classes = array_filter($classes);
      $class_names = implode(' ', array_unique($classes));
      $class_names = $class_names ? ' class="' . esc_attr($class_names) . '"' : '';

      // Build the li opening tag
      $li_attrs = '';
      if ($has_children) {
        // Add interactive attributes for submenu handling
        $li_attrs = ' data-wp-context=\'{ "submenuOpenedBy": { "click": false, "hover": false, "focus": false }, "type": "submenu", "modal": null, "previousFocus": null }\''
          . ' data-wp-interactive="core/navigation"'
          . ' data-wp-on--focusout="actions.handleMenuFocusout"'
          . ' data-wp-on--keydown="actions.handleMenuKeydown"'
          . ' data-wp-on--mouseenter="actions.openMenuOnHover"'
          . ' data-wp-on--mouseleave="actions.closeMenuOnHover"'
          . ' data-wp-watch="callbacks.initMenu"'
          . ' tabindex="-1"';
      }

      $output .= $indent . '<li' . $class_names . $li_attrs . '>';

      // Build the anchor
      $atts = [];
      $atts['title'] = !empty($item->attr_title) ? $item->attr_title : '';
      $atts['target'] = !empty($item->target) ? $item->target : '';
      $atts['rel'] = !empty($item->xfn) ? $item->xfn : '';
      $atts['href'] = !empty($item->url) ? $item->url : '';
      $atts['class'] = 'wp-block-navigation-item__content';

      // Filter for plugins
      $atts = apply_filters('nav_menu_link_attributes', $atts, $item, $args, $depth);

      $attributes = '';
      foreach ($atts as $attr => $value) {
        if (!empty($value)) {
          $value = ('href' === $attr) ? esc_url($value) : esc_attr($value);
          $attributes .= ' ' . $attr . '="' . $value . '"';
        }
      }

      $title = apply_filters('the_title', $item->title, $item->ID);
      $title = apply_filters('nav_menu_item_title', $title, $item, $args, $depth);

      $item_output = '';
      if (isset($args->before)) {
        $item_output .= $args->before;
      }
      $item_output .= '<a' . $attributes . '>';
      $item_output .= '<span class="wp-block-navigation-item__label">';
      if (isset($args->link_before)) {
        $item_output .= $args->link_before;
      }
      $item_output .= $title;
      if (isset($args->link_after)) {
        $item_output .= $args->link_after;
      }
      $item_output .= '</span>';
      $item_output .= '</a>';

      // Add submenu toggle button if has children
      if ($has_children) {
        $item_output .= '<button data-wp-bind--aria-expanded="state.isMenuOpen" data-wp-on--click="actions.toggleMenuOnClick" aria-label="' . esc_attr($item->title) . ' submenu" class="wp-block-navigation__submenu-icon wp-block-navigation-submenu__toggle" aria-expanded="false">'
          . '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true" focusable="false"><path d="M1.50002 4L6.00002 8L10.5 4" stroke-width="1.5"></path></svg>'
          . '</button>';
      }

      if (isset($args->after)) {
        $item_output .= $args->after;
      }

      $output .= apply_filters('walker_nav_menu_start_el', $item_output, $item, $depth, $args);
    }

    /**
     * Ends the element output.
     */
    public function end_el(&$output, $item, $depth = 0, $args = null) {
      $output .= "</li>\n";
    }
  }
}

/**
 * Render footer certification image by attachment filename (WordPress-driven).
 *
 * Usage:
 * - [cryo_footer_certs filename="Website-Footer_2-1-2-300x41.png" alt="..."]
 *
 * Why:
 * - Keep file-based footer template part
 * - Avoid hardcoding /wp-content/uploads/ paths
 */
add_shortcode('cryo_footer_certs', function ($atts): string {
  $atts = shortcode_atts([
    'filename' => '',
    'alt' => '',
    'class' => 'cryo-footer__certImg',
    'attachment_id' => '',
  ], (array) $atts, 'cryo_footer_certs');

  $filename = trim((string) $atts['filename']);
  $attachment_id = (int) ($atts['attachment_id'] ?? 0);
  if ($attachment_id > 0) {
    $src = wp_get_attachment_image_url($attachment_id, 'full');
    if ($src) {
      $alt = (string) $atts['alt'];
      if ($alt === '') {
        $alt = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
      }
      $class = trim((string) $atts['class']);
      return '<img class="' . esc_attr($class) . '" src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '" loading="lazy" decoding="async" />';
    }
  }

  if ($filename === '') return '';

  // If user passes a generated size filename (e.g. *-300x41.png), try the base too.
  $filename_base = preg_replace('/-\\d+x\\d+(?=\\.[^.]+$)/', '', $filename) ?? $filename;

  // Find attachment by exact filename in GUID (common), or by post_name if editors renamed.
  global $wpdb;
  $find_attachment_id = static function (string $needle) use ($wpdb): int {
    $like = '%' . $wpdb->esc_like($needle) . '%';
    // 1) Most reliable: attached file path (uploads relative) or attachment metadata (serialized) contains filename.
    $id = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT p.ID
       FROM {$wpdb->posts} p
       INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
       WHERE p.post_type = 'attachment'
         AND p.post_status = 'inherit'
         AND pm.meta_key IN ('_wp_attached_file', '_wp_attachment_metadata')
         AND pm.meta_value LIKE %s
       ORDER BY p.ID DESC
       LIMIT 1",
      $like
    ));
    if ($id > 0) return $id;

    // 2) Fallback: GUID contains filename.
    $id = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT ID
       FROM {$wpdb->posts}
       WHERE post_type = 'attachment'
         AND post_status = 'inherit'
         AND guid LIKE %s
       ORDER BY ID DESC
       LIMIT 1",
      $like
    ));
    return $id;
  };

  $id = $find_attachment_id($filename);
  if ($id <= 0 && $filename_base !== $filename) {
    $id = $find_attachment_id($filename_base);
  }

  if ($id <= 0) {
    return '';
  }

  $src = wp_get_attachment_image_url($id, 'full');
  if (!$src) return '';

  $alt = (string) $atts['alt'];
  if ($alt === '') {
    $alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
  }

  $class = trim((string) $atts['class']);
  $html = '<img class="' . esc_attr($class) . '" src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '" loading="lazy" decoding="async" />';
  return $html;
});

/**
 * Breadcrumbs for the hero/banner overlay.
 *
 * Usage:
 * - [cryo_breadcrumbs]
 *
 * Output example:
 * - 首頁 / 我們的服務 / 臍帶血保存
 */
add_shortcode('cryo_breadcrumbs', function (): string {
  $post_id = get_queried_object_id();
  if (!$post_id) {
    return '';
  }
  $parts = [];
  $parts[] = '<a href="' . esc_url(home_url('/')) . '">首頁</a>';

  $ancestors = array_reverse(get_post_ancestors($post_id));
  foreach ($ancestors as $aid) {
    $parts[] = '<a href="' . esc_url(get_permalink($aid)) . '">' . esc_html(get_the_title($aid)) . '</a>';
  }
  $parts[] = '<span aria-current="page">' . esc_html(get_the_title($post_id)) . '</span>';

  return '<span class="cryo-breadcrumbs">' . implode('<span class="cryo-breadcrumbs__sep"> / </span>', $parts) . '</span>';
});

/**
 * Hero media renderer.
 *
 * If the site still has Uncode2 header settings (metabox/options), prefer rendering the same banner:
 * - When header type is `header_revslider`, render RevSlider via `[rev_slider <id>]`.
 *
 * Fallback: render Cryo's lightweight placeholder swiper markup.
 *
 * Usage:
 * - [cryo_hero_media]
 */
add_shortcode('cryo_hero_media', function (): string {
  $post_id = get_queried_object_id();
  $post_type = $post_id ? (get_post_type($post_id) ?: 'page') : 'page';

  $header_type = $post_id ? (string) get_post_meta($post_id, '_uncode_header_type', true) : '';
  $rev_id = $post_id ? (string) get_post_meta($post_id, '_uncode_revslider_list', true) : '';

  // Uncode2 "Header Content Block" (WPBakery content block) support.
  // This is commonly where `[uncode_slider ...]` lives for page headers.
  $has_uncode_block_hint = $post_id ? (get_post_meta($post_id, '_uncode_blocks_list', true) !== '') : false;
  if ($header_type === 'header_uncodeblock' || ($header_type === '' && $has_uncode_block_hint)) {
    $block_id = $post_id ? get_post_meta($post_id, '_uncode_blocks_list', true) : '';

    // Sometimes stored as a serialized/array-like meta; normalize to scalar.
    if (is_array($block_id)) {
      $block_id = $block_id[0] ?? '';
    }
    $block_id = (string) $block_id;

    // If OptionTree is present, fall back to Uncode2 default header block option.
    if (($block_id === '' || $block_id === 'none') && function_exists('ot_get_option')) {
      $block_id = (string) ot_get_option('_uncode_' . $post_type . '_blocks');
      if ($block_id === '') {
        $block_id = (string) ot_get_option('_uncode_blocks_list');
      }
    }

    $block_post_id = (int) $block_id;
    if ($block_post_id > 0 && get_post_status($block_post_id)) {
      // WPML compatibility (optional).
      if (function_exists('apply_filters')) {
        $maybe = apply_filters('wpml_object_id', $block_post_id, 'post', true);
        if (is_numeric($maybe)) {
          $block_post_id = (int) $maybe;
        }
      }

      $raw = (string) get_post_field('post_content', $block_post_id);
      if ($raw !== '') {
        // Mirror Uncode behavior: inject `is_header="yes"` so existing VC/Uncode blocks render correctly.
        $raw = str_replace('[vc_row ', '[vc_row is_header="yes" ', $raw);
        $raw = str_replace('[uncode_slider', '[uncode_slider is_header="yes"', $raw);

        /**
         * IMPORTANT:
         * Uncode "header content blocks" can contain lots of VC markup; in Cryo we must avoid rendering
         * unrelated content (e.g. footer fragments) into the hero.
         *
         * So we ONLY render the first `[uncode_slider ...]...[/uncode_slider]` region when present.
         */
        if (preg_match('/\\[uncode_slider[^\\]]*\\][\\s\\S]*?\\[\\/uncode_slider\\]/i', $raw, $m)) {
          $slider_shortcode = (string) ($m[0] ?? '');
          $slider_html = trim(do_shortcode($slider_shortcode));
          if ($slider_html !== '') {
            // `uncode_slider` compatibility shortcode already returns `.cryo-hero__viewport` markup.
            return $slider_html;
          }
        }
      }
    }
  }

  // Uncode2 stored this in OptionTree options; if OptionTree is still present, pull the default slider.
  if (($rev_id === '' || $rev_id === 'none') && function_exists('ot_get_option')) {
    $rev_id = (string) ot_get_option('_uncode_' . $post_type . '_revslider');
  }

  $can_rev = ($rev_id !== '' && $rev_id !== 'none') && (shortcode_exists('rev_slider') || function_exists('rev_slider_shortcode'));
  if ($header_type === 'header_revslider' && $can_rev) {
    return '<div class="cryo-hero__viewport cryo-hero__viewport--plugin" aria-label="首頁橫幅" role="region">'
      . do_shortcode('[rev_slider ' . esc_attr($rev_id) . ']')
      . '</div>';
  }

  // Fallback: lightweight hero (placeholder slides).
  return '<div class="cryo-hero__viewport" aria-label="首頁橫幅" role="region">'
    . '<div class="cryo-hero__track" data-cryo-hero-track>'
    . '<div class="cryo-hero__slide is-active" data-cryo-hero-slide style="--cryo-hero-bg: var(--cryo-hero-placeholder);"></div>'
    . '<div class="cryo-hero__slide" data-cryo-hero-slide style="--cryo-hero-bg: var(--cryo-hero-placeholder);"></div>'
    . '<div class="cryo-hero__slide" data-cryo-hero-slide style="--cryo-hero-bg: var(--cryo-hero-placeholder);"></div>'
    . '</div>'
    . '<div class="cryo-hero__dots" aria-label="輪播指示">'
    . '<button class="cryo-hero__dot is-active" type="button" data-cryo-hero-dot aria-label="第 1 張" aria-current="true"></button>'
    . '<button class="cryo-hero__dot" type="button" data-cryo-hero-dot aria-label="第 2 張"></button>'
    . '<button class="cryo-hero__dot" type="button" data-cryo-hero-dot aria-label="第 3 張"></button>'
    . '</div>'
    . '</div>';
});

/**
 * Cryo Swiper (simple) — editor-configurable via shortcode
 *
 * Usage:
 * - [cryo_swiper ids="123,456,789" interval="6500"]
 * - ids are Media Library attachment IDs.
 *
 * Notes:
 * - Uses Cryo's existing hero JS/CSS (dots + swipe).
 * - If ids are missing/invalid, falls back to placeholder slides.
 */
add_shortcode('cryo_swiper', function ($atts): string {
  $atts = shortcode_atts([
    'ids' => '',
    'interval' => '6500',
  ], (array) $atts, 'cryo_swiper');

  $ids = array_filter(array_map('trim', explode(',', (string) $atts['ids'])));
  $slides = [];
  foreach ($ids as $id) {
    $aid = (int) $id;
    if ($aid <= 0) continue;
    $src = wp_get_attachment_image_url($aid, 'full');
    if (!$src) continue;
    $slides[] = '<div class="cryo-hero__slide" data-cryo-hero-slide style="--cryo-hero-bg:url(' . esc_url($src) . ');"></div>';
  }

  if (count($slides) === 0) {
    $slides = [
      '<div class="cryo-hero__slide is-active" data-cryo-hero-slide style="--cryo-hero-bg: var(--cryo-hero-placeholder);"></div>',
      '<div class="cryo-hero__slide" data-cryo-hero-slide style="--cryo-hero-bg: var(--cryo-hero-placeholder);"></div>',
      '<div class="cryo-hero__slide" data-cryo-hero-slide style="--cryo-hero-bg: var(--cryo-hero-placeholder);"></div>',
    ];
  } else {
    $slides[0] = str_replace('class="cryo-hero__slide"', 'class="cryo-hero__slide is-active"', $slides[0]);
  }

  $dot_count = count($slides);
  $dots = [];
  for ($i = 0; $i < $dot_count; $i++) {
    $active = ($i === 0);
    $dots[] =
      '<button class="cryo-hero__dot' . ($active ? ' is-active' : '') . '" type="button" data-cryo-hero-dot aria-label="第 ' . ($i + 1) . ' 張"' . ($active ? ' aria-current="true"' : '') . '></button>';
  }

  return '<div class="cryo-hero__viewport" aria-label="首頁橫幅" role="region" data-cryo-hero data-cryo-hero-interval="' . esc_attr((string) $atts['interval']) . '">'
    . '<div class="cryo-hero__track" data-cryo-hero-track>'
    . implode('', $slides)
    . '</div>'
    . '<div class="cryo-hero__dots" aria-label="輪播指示">'
    . implode('', $dots)
    . '</div>'
    . '</div>';
});

/**
 * Single slide for `[cryo_hero]`
 *
 * Usage:
 * - [cryo_hero_slide id="123" kicker="我們的服務" title="..." subtitle="..."/]
 *
 * Note: This is intended to be used INSIDE `[cryo_hero]...[/cryo_hero]`.
 */
add_shortcode('cryo_hero_slide', function ($atts): string {
  $atts = shortcode_atts([
    'id' => '',
    'kicker' => '',
    'title' => '',
    'subtitle' => '',
  ], (array) $atts, 'cryo_hero_slide');

  $aid = (int) ($atts['id'] ?? 0);
  $src = $aid > 0 ? wp_get_attachment_image_url($aid, 'full') : '';
  if (!$src) {
    $src = 'var(--cryo-hero-placeholder)';
    $bg = 'style="--cryo-hero-bg:' . $src . ';"';
  } else {
    $bg = 'style="--cryo-hero-bg:url(' . esc_url($src) . ');"';
  }

  $kicker = trim((string) ($atts['kicker'] ?? ''));
  $title = trim((string) ($atts['title'] ?? ''));
  $subtitle = trim((string) ($atts['subtitle'] ?? ''));

  $content = '';
  if ($kicker !== '' || $title !== '' || $subtitle !== '') {
    $content .= '<div class="cryo-hero__slideContent">';
    if ($kicker !== '') $content .= '<p class="cryo-hero__slideKicker">' . esc_html($kicker) . '</p>';
    if ($title !== '') $content .= '<h2 class="cryo-hero__slideTitle">' . esc_html($title) . '</h2>';
    if ($subtitle !== '') $content .= '<p class="cryo-hero__slideSubtitle">' . esc_html($subtitle) . '</p>';
    $content .= '</div>';
  }

  return '<div class="cryo-hero__slide" data-cryo-hero-slide ' . $bg . '>' . $content . '</div>';
});

/**
 * Cryo Hero (full section) — intended to be pasted into page content.
 *
 * Usage:
 * - [cryo_hero] [cryo_hero_slide .../] [cryo_hero_slide .../] [/cryo_hero]
 * - (fallback) [cryo_hero ids="123,456,789"]
 *
 * Renders:
 * - full-bleed swiper
 * - top-left breadcrumbs
 * - per-slide text (kicker/title/subtitle)
 * - desktop bottom-right action buttons
 */
add_shortcode('cryo_hero', function ($atts, $content = ''): string {
  $atts = shortcode_atts([
    'ids' => '',
    'interval' => '6500',
    // Show the action buttons overlay (default true)
    'show_actions' => 'true',
  ], (array) $atts, 'cryo_hero');

  $show_actions_raw = strtolower(trim((string) ($atts['show_actions'] ?? 'true')));
  $show_actions = !in_array($show_actions_raw, ['0', 'false', 'no', 'off'], true);

  $slides_html = '';
  $content = is_string($content) ? trim($content) : '';
  if ($content !== '') {
    $slides_html = trim(do_shortcode($content));
  }

  // If no slide shortcodes were provided, fall back to `ids="1,2,3"` (no per-slide text).
  if ($slides_html === '' || !str_contains($slides_html, 'data-cryo-hero-slide')) {
    $ids = array_filter(array_map('trim', explode(',', (string) $atts['ids'])));
    $slides = [];
    foreach ($ids as $id) {
      $aid = (int) $id;
      if ($aid <= 0) continue;
      $src = wp_get_attachment_image_url($aid, 'full');
      if (!$src) continue;
      $slides[] = '<div class="cryo-hero__slide" data-cryo-hero-slide style="--cryo-hero-bg:url(' . esc_url($src) . ');"></div>';
    }
    if (count($slides) === 0) {
      $slides = [
        '<div class="cryo-hero__slide" data-cryo-hero-slide style="--cryo-hero-bg: var(--cryo-hero-placeholder);"></div>',
        '<div class="cryo-hero__slide" data-cryo-hero-slide style="--cryo-hero-bg: var(--cryo-hero-placeholder);"></div>',
        '<div class="cryo-hero__slide" data-cryo-hero-slide style="--cryo-hero-bg: var(--cryo-hero-placeholder);"></div>',
      ];
    }
    $slides_html = implode('', $slides);
  }

  // Ensure the first slide is marked active for initial paint (JS will also setActive(0)).
  if (!str_contains($slides_html, 'is-active')) {
    $slides_html = preg_replace('/class="cryo-hero__slide\\b/', 'class="cryo-hero__slide is-active', $slides_html, 1) ?? $slides_html;
  }

  preg_match_all('/data-cryo-hero-slide/', $slides_html, $m);
  $count = max(1, (int) count($m[0] ?? []));
  $dots = [];
  for ($i = 0; $i < $count; $i++) {
    $active = ($i === 0);
    $dots[] =
      '<button class="cryo-hero__dot' . ($active ? ' is-active' : '') . '" type="button" data-cryo-hero-dot aria-label="第 ' . ($i + 1) . ' 張"' . ($active ? ' aria-current="true"' : '') . '></button>';
  }

  $actions_html = '';
  if ($show_actions) {
    $actions_html =
      '<div class="cryo-hero__actions" aria-label="快速操作">'
      . '<a class="cryo-hero__action" href="tel:+85221102121"><span class="cryo-hero__actionIcon" aria-hidden="true"></span><span class="cryo-hero__actionText">電話查詢</span></a>'
      . '<a class="cryo-hero__action" href="#"><span class="cryo-hero__actionIcon cryo-hero__actionIcon--chat" aria-hidden="true"></span><span class="cryo-hero__actionText">AI 線上查詢</span></a>'
      . '</div>';
  }

  return '<section class="cryo-hero">'
    . '<div class="cryo-hero__viewport" aria-label="首頁橫幅" role="region" data-cryo-hero data-cryo-hero-interval="' . esc_attr((string) $atts['interval']) . '">'
    . '<div class="cryo-hero__track" data-cryo-hero-track>'
    . $slides_html
    . '</div>'
    . '<div class="cryo-hero__dots" aria-label="輪播指示">' . implode('', $dots) . '</div>'
    . '</div>'
    . '<div class="cryo-hero__overlay">'
    . do_shortcode('[cryo_breadcrumbs]')
    . $actions_html
    . '</div>'
    . '</section>';
});

/**
 * Uncode compatibility: `uncode_slider` shortcode
 *
 * Why:
 * - Old content / Uncode header blocks may contain `[uncode_slider ...]` (WPBakery element).
 * - Without Uncode/WPBakery active, WP prints the raw shortcode text ("leak").
 * - We provide a safe fallback so pages remain presentable during migration.
 *
 * Note:
 * - This is NOT a full Uncode slider implementation.
 * - It renders Cryo's lightweight hero swiper (placeholder images) and ignores complex VC child markup.
 */
if (!shortcode_exists('uncode_slider')) {
add_shortcode('uncode_slider', function ($atts, $content = ''): string {
  $atts = shortcode_atts([
    'slider_interval' => '',
    'slider_navspeed' => '',
    'slider_loop' => '',
    'slider_type' => '',
    'is_header' => '',
  ], (array) $atts, 'uncode_slider');

  $inner = (string) $content;

  // Uncode slider content typically nests VC rows with `back_image="<attachmentId>"`.
  // Extract those IDs and render them as real slides (so we get the actual images).
  $ids = [];
  if ($inner !== '') {
    if (preg_match_all('/\\bback_image\\s*=\\s*["\\\'](\\d+)["\\\']/i', $inner, $m)) {
      foreach ($m[1] as $id) {
        $id = (int) $id;
        if ($id > 0) $ids[] = $id;
      }
    }
    // Some variants use background-image / back_image_id
    if (preg_match_all('/\\b(background-image|back_image_id)\\s*=\\s*["\\\'](\\d+)["\\\']/i', $inner, $m2)) {
      foreach ($m2[2] as $id) {
        $id = (int) $id;
        if ($id > 0) $ids[] = $id;
      }
    }
  }
  // Keep order, remove duplicates
  $ids = array_values(array_unique($ids));

  $slides = [];
  foreach ($ids as $id) {
    $src = wp_get_attachment_image_url($id, 'full');
    if (!$src) continue;
    $slides[] = '<div class="cryo-hero__slide" data-cryo-hero-slide style="--cryo-hero-bg:url(' . esc_url($src) . ');"></div>';
  }

  // If no images found, fall back to placeholder slides.
  if (count($slides) === 0) {
    $slides = [
      '<div class="cryo-hero__slide is-active" data-cryo-hero-slide style="--cryo-hero-bg: var(--cryo-hero-placeholder);"></div>',
      '<div class="cryo-hero__slide" data-cryo-hero-slide style="--cryo-hero-bg: var(--cryo-hero-placeholder);"></div>',
      '<div class="cryo-hero__slide" data-cryo-hero-slide style="--cryo-hero-bg: var(--cryo-hero-placeholder);"></div>',
    ];
  } else {
    // Mark the first as active
    $slides[0] = str_replace('class="cryo-hero__slide"', 'class="cryo-hero__slide is-active"', $slides[0]);
  }

  $dot_count = count($slides);
  $dots = [];
  for ($i = 0; $i < $dot_count; $i++) {
    $active = ($i === 0);
    $dots[] =
      '<button class="cryo-hero__dot' . ($active ? ' is-active' : '') . '" type="button" data-cryo-hero-dot aria-label="第 ' . ($i + 1) . ' 張"' . ($active ? ' aria-current="true"' : '') . '></button>';
  }

  // Fallback swiper (same structure as Cryo hero, so existing JS/CSS works).
  return '<div class="cryo-hero__viewport cryo-hero__viewport--uncode" data-cryo-hero>'
    . '<div class="cryo-hero__track" data-cryo-hero-track>'
    . implode('', $slides)
    . '</div>'
    . '<div class="cryo-hero__dots" aria-label="輪播指示">'
    . implode('', $dots)
    . '</div>'
    . '</div>';
});
}

// Enqueue theme assets with cache-busting in dev
add_action('wp_enqueue_scripts', function (): void {
  $theme_dir = get_template_directory();
  $theme_uri = get_template_directory_uri();

  // Google fonts (dev-friendly). For production, prefer self-hosted subsets.
  wp_enqueue_style(
    'cryo-fonts',
    'https://fonts.googleapis.com/css2?family=Inter:wght@600;700&family=Noto+Sans+TC:wght@400;500&display=swap',
    [],
    null
  );

  // Icon font used for the header search icon (and other small UI icons if needed).
  wp_enqueue_style('dashicons');

  $states_css     = $theme_dir . '/assets/css/states.css';
  $utilities_css  = $theme_dir . '/assets/css/utilities.css';
  $main_js        = $theme_dir . '/assets/js/main.js';

  if (file_exists($states_css)) {
    wp_enqueue_style('cryo-states', $theme_uri . '/assets/css/states.css', [], (string) filemtime($states_css));
  }
  if (file_exists($utilities_css)) {
    wp_enqueue_style('cryo-utilities', $theme_uri . '/assets/css/utilities.css', [], (string) filemtime($utilities_css));
  }
  if (file_exists($main_js)) {
    wp_enqueue_script('cryo-main', $theme_uri . '/assets/js/main.js', [], (string) filemtime($main_js), true);
  }
});

/**
 * Block themes can store edited template parts in the DB (Site Editor), which override theme files.
 * Your captured HTML shows extra blocks (WooCommerce account/cart) and multiple nav containers being
 * injected into the header — that only happens when the header template part is overridden in DB.
 *
 * We reset Cryo header/footer overrides once in dev/local so file-based template parts are used.
 *
 * To force a re-check, delete the option:
 *   DELETE FROM wp_options WHERE option_name = 'cryo_reset_template_parts_overrides_done';
 *
 * Or add `?cryo_reset_templates=1` to any URL while logged in as admin.
 */
add_action('init', function (): void {
  // Allow admin to force-reset template part overrides via query param.
  if (
    isset($_GET['cryo_reset_templates']) &&
    $_GET['cryo_reset_templates'] === '1' &&
    current_user_can('manage_options')
  ) {
    delete_option('cryo_reset_template_parts_overrides_done');
    // Redirect to remove the query param
    wp_safe_redirect(remove_query_arg('cryo_reset_templates'));
    exit;
  }

  if (!post_type_exists('wp_template_part')) {
    return;
  }
  if (get_option('cryo_reset_template_parts_overrides_done')) {
    return;
  }

  $candidates = get_posts([
    'post_type'      => 'wp_template_part',
    'post_status'    => ['publish', 'draft', 'auto-draft', 'inherit'],
    'numberposts'    => -1,
    'suppress_filters' => false,
  ]);

  $deleted_any = false;
  foreach ($candidates as $post) {
    $content = (string) $post->post_content;
    // Only touch overrides that clearly belong to Cryo template parts and look "wrong" (missing/extra structures).
    $looks_like_cryo_header =
      str_contains($content, 'cryo-topbar') ||
      str_contains($content, 'cryo-nav') ||
      str_contains($content, '/wp-content/themes/cryo/assets/images/logo.png');

    $looks_like_cryo_footer =
      str_contains($content, 'cryo-footer') ||
      str_contains($content, 'cryo-footer__bottom') ||
      str_contains($content, 'cryo-footer__certs');

    $has_injected_blocks =
      str_contains($content, 'woocommerce/mini-cart') ||
      str_contains($content, 'woocommerce/customer-account') ||
      // multiple navigation containers / unexpected nav structure
      (substr_count($content, 'wp-block-navigation__container') >= 2);

    // Footer-specific "wrong" signals: missing bottom row info OR contains injected language/login menu fragments.
    $footer_missing_expected =
      $looks_like_cryo_footer && (
        !str_contains($content, 'cryo-footer__bottom') ||
        !str_contains($content, 'cryo-footer__certs')
      );
    $footer_has_unexpected_menu =
      $looks_like_cryo_footer && (
        str_contains($content, 'Language Menu') ||
        str_contains($content, 'qtranxs') ||
        str_contains($content, '登入') ||
        str_contains($content, '立即登記')
      );

    if (($looks_like_cryo_header && $has_injected_blocks) || $footer_missing_expected || $footer_has_unexpected_menu) {
      wp_delete_post($post->ID, true);
      $deleted_any = true;
    }
  }

  // Mark done (even if nothing deleted) to avoid scanning on every request.
  update_option('cryo_reset_template_parts_overrides_done', 1, true);

  // If we deleted an override, a refresh will now use file-based header.
  if ($deleted_any) {
    // no-op; just ensuring the option is set.
  }
});

/**
 * Render only ONE of Login / My Account (no unused link in DOM).
 * We do this by filtering the `core/html` block in the header.
 */
add_filter('render_block', function (string $block_content, array $block): string {
  if (($block['blockName'] ?? null) !== 'core/html') {
    return $block_content;
  }
  if (!str_contains($block_content, 'cryo-nav__login') && !str_contains($block_content, 'cryo-nav__myaccount')) {
    return $block_content;
  }

  $logged_in = is_user_logged_in();
  if ($logged_in) {
    // Remove login link entirely
    $block_content = preg_replace('#<a[^>]*class=\"cryo-nav__login\"[^>]*>.*?</a>#s', '', $block_content) ?? $block_content;
  } else {
    // Remove my account link entirely
    $block_content = preg_replace('#<a[^>]*class=\"cryo-nav__myaccount\"[^>]*>.*?</a>#s', '', $block_content) ?? $block_content;
  }
  return $block_content;
}, 20, 2);

/**
 * Locale switcher compatibility (old Uncode2 uses qTranslate-X / qTranslate-XT).
 * We mirror that behavior for Cryo mobile drawer language buttons.
 */
if (!function_exists('cryo_get_locale_switcher_links')) {
  /**
   * @return array{zh?:array{url:string,active:bool,code:string},en?:array{url:string,active:bool,code:string}}
   */
  function cryo_get_locale_switcher_links(): array {
    // Current URL (including query string) so language switch stays on the same page.
    $scheme = is_ssl() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $current_url = $scheme . '://' . $host . $uri;

    // Prefer qTranslate if present (matches old theme behavior).
    $q = $GLOBALS['q_config'] ?? null;
    $enabled = is_array($q) ? ($q['enabled_languages'] ?? []) : [];
    $current_lang = is_array($q) ? (string) ($q['language'] ?? '') : '';

    // Common qTranslate codes used on this site: zh / cn / en
    $zh_code = in_array('zh', $enabled, true) ? 'zh' : (in_array('cn', $enabled, true) ? 'cn' : 'zh');
    $en_code = in_array('en', $enabled, true) ? 'en' : 'en';

    $make_url = static function (string $lang) use ($current_url): string {
      if (function_exists('qtranxf_convertURL')) {
        // qTranslate-XT canonical way.
        return (string) qtranxf_convertURL($current_url, $lang, false, true);
      }
      // Fallback: common qTranslate query param.
      return (string) add_query_arg('lang', $lang, $current_url);
    };

    // If qTranslate isn't active, return empty so template stays as-is (#).
    if ($current_lang === '' && !function_exists('qtranxf_convertURL')) {
      return [];
    }

    $is_zh = in_array($current_lang, ['zh', 'cn'], true);
    $is_en = ($current_lang === 'en');

    return [
      'zh' => ['url' => esc_url($make_url($zh_code)), 'active' => $is_zh, 'code' => $zh_code],
      'en' => ['url' => esc_url($make_url($en_code)), 'active' => $is_en, 'code' => $en_code],
    ];
  }
}

// Render the locale switcher URLs/active state into the Cryo mobile drawer language links.
add_filter('render_block', function (string $block_content, array $block): string {
  if (($block['blockName'] ?? null) !== 'core/html') {
    return $block_content;
  }
  if (
    !str_contains($block_content, 'cryo-mobileMenu__langLink') &&
    !str_contains($block_content, 'cryo-nav__langLink')
  ) {
    return $block_content;
  }

  $links = cryo_get_locale_switcher_links();
  if (empty($links)) {
    return $block_content;
  }

  $replace = function (array $m) use ($links): string {
      $before = $m[1] ?? '';
      $class = $m[2] ?? '';
      $mid = $m[3] ?? '';
      $lang = $m[4] ?? '';
      $after = $m[5] ?? '';
      $label = $m[6] ?? '';

      $cfg = $links[$lang] ?? null;
      if (!$cfg) return $m[0];

      // Remove any existing is-active + aria-current, then re-add based on active state.
      $class = preg_replace('#\bis-active\b#', '', $class) ?? $class;
      $class = trim(preg_replace('#\s+#', ' ', $class) ?? $class);
      $aria = '';
      if (!empty($cfg['active'])) {
        $class = trim($class . ' is-active');
        $aria = ' aria-current="true"';
      }

      // Replace href (strip any existing href attribute from fragments).
      $attrs = $before . 'class="' . esc_attr($class) . '"' . $mid . 'data-cryo-lang="' . esc_attr($lang) . '"' . $after;
      $attrs = preg_replace('#\shref="[^"]*"#', '', $attrs) ?? $attrs;
      $attrs = trim($attrs);

      return '<a ' . $attrs . ' href="' . esc_url($cfg['url']) . '"' . $aria . '>' . $label . '</a>';
  };

  // Mobile drawer links
  $block_content = preg_replace_callback(
    '#<a([^>]*?)class="([^"]*?\bcryo-mobileMenu__langLink\b[^"]*?)"([^>]*?)data-cryo-lang="(zh|en)"([^>]*?)>(.*?)</a>#s',
    $replace,
    $block_content
  ) ?? $block_content;

  // Desktop header popup links
  $block_content = preg_replace_callback(
    '#<a([^>]*?)class="([^"]*?\bcryo-nav__langLink\b[^"]*?)"([^>]*?)data-cryo-lang="(zh|en)"([^>]*?)>(.*?)</a>#s',
    $replace,
    $block_content
  ) ?? $block_content;

  return $block_content;
}, 25, 2);

/**
 * Uncode2 compatibility: the old theme renders the announcement marquee from a specific page (ID 3976),
 * and uses ACF fields `hide_marquee` + `marquee_link` to control visibility/link.
 *
 * Cryo theme uses the same data source when available, but renders it into the block header topbar.
 */
if (!function_exists('cryo_get_announcement_config')) {
  /**
   * @return array{text:string,link:string,hidden:bool,source:string}
   */
  function cryo_get_announcement_config(): array {
    $page_id = (int) apply_filters('cryo_marquee_page_id', 3976);

    // 1) Prefer old Uncode2 source (page + ACF) for continuity.
    if ($page_id > 0 && get_post_status($page_id)) {
      $hide = null;
      $link = '';

      if (function_exists('get_field')) {
        $hide = get_field('hide_marquee', $page_id);
        $link = (string) (get_field('marquee_link', $page_id) ?? '');
      }

      // Match old logic: null/true => hidden
      $hidden = ($hide === null || (bool) $hide);
      if ($hidden) {
        return ['text' => '', 'link' => '', 'hidden' => true, 'source' => 'acf'];
      }

      $raw = (string) get_post_field('post_content', $page_id);
      $text = trim(wp_strip_all_tags(apply_filters('the_content', $raw)));
      if ($text !== '') {
        return ['text' => $text, 'link' => $link, 'hidden' => false, 'source' => 'page'];
      }
    }

    // 2) Fallback: WP settings-based announcement (editable in wp-admin → Settings → General)
    $hidden_opt = (bool) get_option('cryo_announcement_hide', false);
    if ($hidden_opt) {
      return ['text' => '', 'link' => '', 'hidden' => true, 'source' => 'options'];
    }

    $text_opt = trim((string) get_option('cryo_announcement_text', ''));
    $link_opt = trim((string) get_option('cryo_announcement_link', ''));
    if ($text_opt !== '') {
      return ['text' => $text_opt, 'link' => $link_opt, 'hidden' => false, 'source' => 'options'];
    }

    // 3) No config found → keep whatever is in the template file (acts as placeholder)
    return ['text' => '', 'link' => '', 'hidden' => false, 'source' => 'template'];
  }
}

// Provide a "no-marquee" body class when hidden (matches old theme behavior).
add_filter('body_class', function (array $classes): array {
  $cfg = cryo_get_announcement_config();
  if (!empty($cfg['hidden'])) {
    $classes[] = 'no-marquee';
  }
  return $classes;
});

// Render the announcement into the Cryo topbar paragraph (so it is not hardcoded in header.html).
add_filter('render_block', function (string $block_content, array $block): string {
  if (($block['blockName'] ?? null) !== 'core/paragraph') {
    return $block_content;
  }
  if (!str_contains($block_content, 'cryo-topbar__text')) {
    return $block_content;
  }

  $cfg = cryo_get_announcement_config();
  if (!empty($cfg['hidden'])) {
    return '';
  }

  // If we don't have a dynamic value, keep the template text.
  if (empty($cfg['text'])) {
    return $block_content;
  }

  $text = esc_html($cfg['text']);
  $link = trim((string) ($cfg['link'] ?? ''));
  $inner = $text;
  if ($link !== '') {
    $inner = '<a class="cryo-topbar__link" href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer">' . $text . '</a>';
  }

  // Replace paragraph inner HTML while preserving attributes/classes.
  $block_content = preg_replace(
    '#(<p[^>]*class=\"[^\"]*cryo-topbar__text[^\"]*\"[^>]*>)(.*?)(</p>)#s',
    '$1' . $inner . '$3',
    $block_content
  ) ?? $block_content;

  return $block_content;
}, 30, 2);

// Settings → General fields for announcement (fallback when Uncode2 ACF source is not present).
add_action('admin_init', function (): void {
  register_setting('general', 'cryo_announcement_text', [
    'type'              => 'string',
    'sanitize_callback' => 'sanitize_text_field',
    'default'           => '',
  ]);
  register_setting('general', 'cryo_announcement_link', [
    'type'              => 'string',
    'sanitize_callback' => 'esc_url_raw',
    'default'           => '',
  ]);
  register_setting('general', 'cryo_announcement_hide', [
    'type'              => 'boolean',
    'sanitize_callback' => static fn($v) => (int) (bool) $v,
    'default'           => 0,
  ]);

  add_settings_field(
    'cryo_announcement_text',
    __('Cryo Announcement Text', 'cryo'),
    function (): void {
      $val = esc_attr((string) get_option('cryo_announcement_text', ''));
      echo '<input type="text" id="cryo_announcement_text" name="cryo_announcement_text" value="' . $val . '" class="regular-text" />';
      echo '<p class="description">Fallback announcement text for the top bar when Uncode2 marquee page is not used.</p>';
    },
    'general'
  );
  add_settings_field(
    'cryo_announcement_link',
    __('Cryo Announcement Link (optional)', 'cryo'),
    function (): void {
      $val = esc_attr((string) get_option('cryo_announcement_link', ''));
      echo '<input type="url" id="cryo_announcement_link" name="cryo_announcement_link" value="' . $val . '" class="regular-text ltr" />';
    },
    'general'
  );
  add_settings_field(
    'cryo_announcement_hide',
    __('Hide Cryo Announcement', 'cryo'),
    function (): void {
      $checked = (bool) get_option('cryo_announcement_hide', 0);
      echo '<label><input type="checkbox" name="cryo_announcement_hide" value="1" ' . checked(true, $checked, false) . ' /> Hide the top bar announcement</label>';
    },
    'general'
  );
});


/**
 * Cryo Cards section + Card component (homepage services)
 *
 * Design reference:
 * - `screens/figma/cryolife/desktop/Homepage.png`
 * - `screens/figma/cryolife/mobile/Homepage.png`
 *
 * Usage (paste into page content):
 * - [cryo_cards kicker="我們的服務" title="..." body="..." stage_height="760px"]
 *     [cryo_card category="services" offset="0" x="0px" y="0px" /]
 *     [cryo_card category="services" offset="1" x="0px" y="360px" /]
 *   [/cryo_cards]
 *
 * Notes:
 * - Desktop positions are driven by CSS variables from shortcode attributes (x/y).
 * - Mobile ignores x/y and stacks cards with side gutters.
 * - Card content is sourced from posts (by id/slug or category+offset).
 */
add_shortcode('cryo_cards', function ($atts, $content = ''): string {
  $atts = shortcode_atts([
    'kicker' => '',
    'title' => '',
    'body' => '',
    // CSS length, e.g. 760px. Used to size the positioning "stage" on desktop.
    'stage_height' => '760px',
    'class' => '',
  ], (array) $atts, 'cryo_cards');

  $kicker = trim((string) ($atts['kicker'] ?? ''));
  $title = trim((string) ($atts['title'] ?? ''));
  $body = trim((string) ($atts['body'] ?? ''));
  $class = trim((string) ($atts['class'] ?? ''));
  $stage_height = trim((string) ($atts['stage_height'] ?? '760px'));
  if ($stage_height === '') $stage_height = '760px';

  $cards_html = '';
  if (is_string($content) && trim($content) !== '') {
    $cards_html = trim(do_shortcode($content));
  }

  // Allow body to contain basic markup when pasted from editor, but keep it safe.
  $body_html = '';
  if ($body !== '') {
    $body_html = wp_kses_post(wpautop($body));
  }

  return
    '<section class="cryo-cardsSection' . ($class !== '' ? ' ' . esc_attr($class) : '') . '">'
    . '<div class="cryo-cards">'
      . '<div class="cryo-cards__copy">'
        . ($kicker !== '' ? '<p class="cryo-cards__kicker">' . esc_html($kicker) . '</p>' : '')
        . ($title !== '' ? '<h2 class="cryo-cards__title">' . esc_html($title) . '</h2>' : '')
        . ($body_html !== '' ? '<div class="cryo-cards__body">' . $body_html . '</div>' : '')
      . '</div>'
      . '<div class="cryo-cards__stage" style="--cryo-cards-stage-height:' . esc_attr($stage_height) . '">'
        . $cards_html
      . '</div>'
    . '</div>'
    . '</section>';
});

/**
 * Cryo Carousel — horizontal scrolling wrapper for child components
 *
 * Design reference:
 * - `screens/figma/cryolife/desktop/相關資訊及活動.png`
 *
 * Usage:
 * - [cryo_carousel title="最新資訊及活動"]
 *     [cryo_post_card post_id="123" /]
 *     [cryo_post_card category="news" offset="0" /]
 *   [/cryo_carousel]
 */
add_shortcode('cryo_carousel', function ($atts, $content = ''): string {
  $atts = shortcode_atts([
    'title' => '',
    'kicker' => '',
    'gap' => '24px',
    'class' => '',
  ], (array) $atts, 'cryo_carousel');

  $title = trim((string) ($atts['title'] ?? ''));
  $kicker = trim((string) ($atts['kicker'] ?? ''));
  $gap = trim((string) ($atts['gap'] ?? '24px'));
  $class = trim((string) ($atts['class'] ?? ''));

  $cards_html = '';
  $raw_content = is_string($content) ? $content : '';
  if (trim($raw_content) !== '') {
    $cards_html = do_shortcode($raw_content);
  }

  $header_html = '';
  if ($kicker !== '' || $title !== '') {
    $header_html = '<div class="cryo-posts__header">';
    if ($kicker !== '') {
      $header_html .= '<h6>' . esc_html($kicker) . '</h6>';
    }
    if ($title !== '') {
      $header_html .= '<h2 class="cryo-posts__title">' . esc_html($title) . '</h2>';
    }
    $header_html .= '</div>';
  }

  return
    '<section class="cryo-postsSection' . ($class !== '' ? ' ' . esc_attr($class) : '') . '">'
    . '<div class="cryo-posts">'
    . $header_html
    . '<div class="cryo-carousel" data-cryo-carousel style="--cryo-carousel-gap:' . esc_attr($gap) . '">'
    . '<div class="cryo-carousel__track">'
    . $cards_html
    . '</div>'
    . '</div>'
    . '</div>'
    . '</section>';
});

/**
 * Cryo Post Card — compact post preview for carousel/grid
 *
 * Design reference:
 * - `screens/figma/cryolife/desktop/相關資訊及活動.png`
 *
 * Usage:
 * - [cryo_post_card post_id="123" /]
 *
 * Renders:
 * - Featured image from post as cover
 * - Category tag with color
 * - Post date
 * - Post title
 */
add_shortcode('cryo_post_card', function ($atts): string {
  $atts = shortcode_atts([
    'post_id' => '',           // Required: the post ID to render
    'date_format' => 'j M, Y', // PHP date format
    'class' => '',             // Additional CSS classes
  ], (array) $atts, 'cryo_post_card');

  $post_id = (int) ($atts['post_id'] ?? 0);
  $class = trim((string) ($atts['class'] ?? ''));
  $date_format = trim((string) ($atts['date_format'] ?? 'j M, Y'));

  // Require post_id
  if ($post_id <= 0) {
    return '<!-- cryo_post_card: post_id required -->';
  }

  // Get the post (allow any status that's viewable - publish, private, etc.)
  $post = get_post($post_id);
  if (!$post) {
    return '<!-- cryo_post_card: post ' . esc_html($post_id) . ' not found -->';
  }

  // Check if post is viewable (published or user can view it)
  $viewable_statuses = ['publish', 'private', 'inherit'];
  if (!in_array($post->post_status, $viewable_statuses, true)) {
    return '<!-- cryo_post_card: post ' . esc_html($post_id) . ' status is "' . esc_html($post->post_status) . '" (not published) -->';
  }

  // Title from post
  $title = (string) get_the_title($post);

  // Permalink from post
  $permalink = (string) get_permalink($post);

  // Date from post
  $date_display = get_the_date($date_format, $post);

  // Category tag from post
  $tag_label = '';
  $tag_modifier = '';
  $terms = get_the_terms($post, 'category');
  if (!is_wp_error($terms) && !empty($terms)) {
    $term = $terms[0];
    $tag_label = (string) ($term->name ?? '');
    $tag_modifier = cryo_get_tag_modifier($tag_label);
  }

  // Featured image from post
  $img_html = '';
  $thumb_id = (int) get_post_thumbnail_id($post);

  // Fallback: check Uncode2 featured media meta
  if ($thumb_id <= 0) {
    $uncode_featured = trim((string) get_post_meta($post->ID, '_uncode_featured_media', true));
    if ($uncode_featured !== '') {
      $ids = array_filter(array_map('trim', explode(',', $uncode_featured)));
      foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0 && wp_attachment_is_image($id)) {
          $thumb_id = $id;
          break;
        }
      }
    }
  }

  // Render featured image
  if ($thumb_id > 0 && wp_attachment_is_image($thumb_id)) {
    $src = wp_get_attachment_image_url($thumb_id, 'medium_large');
    $alt = (string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true);
    if ($alt === '') $alt = $title;
    if ($src) {
      $img_html = '<img class="cryo-postCard__img" src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '" loading="lazy" decoding="async" />';
    }
  }

  // Placeholder if no image
  if ($img_html === '') {
    $img_html = '<div class="cryo-postCard__img cryo-postCard__img--placeholder" role="img" aria-label="' . esc_attr($title) . '"></div>';
  }

  // Tag HTML
  $tag_html = '';
  if ($tag_label !== '') {
    $tag_class = 'cryo-postCard__tag';
    if ($tag_modifier !== '') {
      $tag_class .= ' cryo-postCard__tag--' . esc_attr($tag_modifier);
    }
    $tag_html = '<span class="' . $tag_class . '">' . esc_html($tag_label) . '</span>';
  }

  // Meta row (tag + date)
  $meta_html = '<div class="cryo-postCard__meta">' . $tag_html;
  if ($date_display !== '') {
    $meta_html .= '<span class="cryo-postCard__date">' . esc_html($date_display) . '</span>';
  }
  $meta_html .= '</div>';

  return
    '<a class="cryo-postCard' . ($class !== '' ? ' ' . esc_attr($class) : '') . '" href="' . esc_url($permalink) . '">'
    . '<div class="cryo-postCard__media">'
    . $img_html
    . '</div>'
    . $meta_html
    . '<h3 class="cryo-postCard__title">' . esc_html($title) . '</h3>'
    . '</a>';
});

/**
 * Cryo Testimonials — user stories slider section
 *
 * Design reference:
 * - 用戶分享 section with quote, author, and video thumbnail
 *
 * Usage:
 * - [cryo_testimonials title="用戶分享"]
 *     [cryo_testimonial
 *       text="從沒想過真的會用上..."
 *       name="李太太"
 *       remark="2023年儲存客戶"
 *       url="https://youtube.com/..."
 *       thumbnail_id="123"
 *     /]
 *   [/cryo_testimonials]
 */
add_shortcode('cryo_testimonials', function ($atts, $content = ''): string {
  $atts = shortcode_atts([
    'title' => '用戶分享',
    'class' => '',
  ], (array) $atts, 'cryo_testimonials');

  $title = trim((string) ($atts['title'] ?? '用戶分享'));
  $class = trim((string) ($atts['class'] ?? ''));

  // Process nested testimonial shortcodes
  $slides_html = '';
  $raw_content = is_string($content) ? $content : '';
  if (trim($raw_content) !== '') {
    $slides_html = do_shortcode($raw_content);
  }

  // Mark first slide as active for initial render
  $slides_html = preg_replace(
    '/class="cryo-testimonial"/',
    'class="cryo-testimonial is-active"',
    $slides_html,
    1 // Only replace the first occurrence
  );

  // Count slides for navigation
  preg_match_all('/data-cryo-testimonial-slide/', $slides_html, $matches);
  $slide_count = count($matches[0] ?? []);
  $show_nav = $slide_count > 1;

  // Navigation arrows
  $nav_html = '';
  if ($show_nav) {
    $nav_html = '<div class="cryo-testimonials__nav">'
      . '<button class="cryo-testimonials__arrow cryo-testimonials__arrow--prev" type="button" data-cryo-testimonial-prev aria-label="上一則">'
      . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>'
      . '</button>'
      . '<button class="cryo-testimonials__arrow cryo-testimonials__arrow--next" type="button" data-cryo-testimonial-next aria-label="下一則">'
      . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>'
      . '</button>'
      . '</div>';
  }

  return
    '<section class="cryo-testimonialsSection' . ($class !== '' ? ' ' . esc_attr($class) : '') . '" data-cryo-testimonials>'
    . '<div class="cryo-testimonials">'
    . '<div class="cryo-testimonials__track" data-cryo-testimonials-track>'
    . $slides_html
    . '</div>'
    . '</div>'
    . '</section>';
});

/**
 * Single testimonial slide
 *
 * Usage:
 * - [cryo_testimonial
 *     title="用戶分享"
 *     text="從沒想過真的會用上..."
 *     name="李太太"
 *     remark="2023年儲存客戶"
 *     url="https://youtube.com/..."
 *     thumbnail_id="123"
 *   /]
 */
add_shortcode('cryo_testimonial', function ($atts): string {
  $atts = shortcode_atts([
    'title' => '用戶分享',       // Section title
    'text' => '',                // Quote/testimonial text
    'name' => '',                // Author name
    'remark' => '',              // Subtitle (e.g., "2023年儲存客戶")
    'url' => '',                 // Video URL (YouTube, etc.)
    'thumbnail_id' => '',        // Thumbnail image attachment ID
    'thumbnail_url' => '',       // Direct thumbnail URL (fallback)
  ], (array) $atts, 'cryo_testimonial');

  $title = trim((string) ($atts['title'] ?? '用戶分享'));
  $text = trim((string) ($atts['text'] ?? ''));
  $name = trim((string) ($atts['name'] ?? ''));
  $remark = trim((string) ($atts['remark'] ?? ''));
  $url = trim((string) ($atts['url'] ?? ''));
  $thumbnail_id = (int) ($atts['thumbnail_id'] ?? 0);
  $thumbnail_url = trim((string) ($atts['thumbnail_url'] ?? ''));

  // Build thumbnail HTML
  $thumb_html = '';
  if ($thumbnail_id > 0 && wp_attachment_is_image($thumbnail_id)) {
    $src = wp_get_attachment_image_url($thumbnail_id, 'large');
    if ($src) {
      $thumb_html = '<img class="cryo-testimonial__thumb" src="' . esc_url($src) . '" alt="' . esc_attr($name) . '" loading="lazy" />';
    }
  } elseif ($thumbnail_url !== '') {
    $thumb_html = '<img class="cryo-testimonial__thumb" src="' . esc_url($thumbnail_url) . '" alt="' . esc_attr($name) . '" loading="lazy" />';
  } else {
    // Placeholder
    $thumb_html = '<div class="cryo-testimonial__thumb cryo-testimonial__thumb--placeholder"></div>';
  }

  // Play button overlay if URL is provided
  $play_html = '';
  if ($url !== '') {
    $play_html = '<a class="cryo-testimonial__play" href="' . esc_url($url) . '" target="_blank" rel="noopener" aria-label="播放影片">'
      . '<svg viewBox="0 0 64 64" fill="none"><circle cx="32" cy="32" r="30" fill="currentColor" opacity="0.9"/><path d="M26 20l20 12-20 12V20z" fill="#fff"/></svg>'
      . '</a>';
  }

  // Navigation arrows (inside each slide for positioning)
  $nav_html = '<div class="cryo-testimonial__nav">'
    . '<button class="cryo-testimonial__arrow cryo-testimonial__arrow--prev" type="button" data-cryo-testimonial-prev aria-label="上一則">'
    . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>'
    . '</button>'
    . '<button class="cryo-testimonial__arrow cryo-testimonial__arrow--next" type="button" data-cryo-testimonial-next aria-label="下一則">'
    . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>'
    . '</button>'
    . '</div>';

  return
    '<div class="cryo-testimonial" data-cryo-testimonial-slide>'
    . '<div class="cryo-testimonial__content">'
    . '<h2 class="cryo-testimonial__title">' . esc_html($title) . '</h2>'
    . ($text !== '' ? '<blockquote class="cryo-testimonial__quote">"' . esc_html($text) . '"</blockquote>' : '')
    . '<div class="cryo-testimonial__author">'
    . ($name !== '' ? '<div class="cryo-testimonial__name">' . esc_html($name) . '</div>' : '')
    . ($remark !== '' ? '<div class="cryo-testimonial__remark">' . esc_html($remark) . '</div>' : '')
    . '</div>'
    . $nav_html
    . '</div>'
    . '<div class="cryo-testimonial__media">'
    . $thumb_html
    . $play_html
    . '</div>'
    . '</div>';
});


/**
 * Helper: determine tag color modifier from label or explicit color name.
 */
if (!function_exists('cryo_get_tag_modifier')) {
  function cryo_get_tag_modifier(string $input): string {
    $input = strtolower(trim($input));
    // Explicit color names
    if (in_array($input, ['news', 'event', 'info'], true)) {
      return $input;
    }
    // Chinese category names
    if (str_contains($input, '新聞') || str_contains($input, 'news')) {
      return 'news';
    }
    if (str_contains($input, '活動') || str_contains($input, 'event')) {
      return 'event';
    }
    if (str_contains($input, '消息') || str_contains($input, 'info')) {
      return 'info';
    }
    // Default to news style (orange)
    return 'news';
  }
}


add_shortcode('cryo_card', function ($atts): string {
  $atts = shortcode_atts([
    // Post selection
    'post_id' => '',
    'slug' => '',
    'post_type' => 'post',
    'category' => '', // category slug (WP "category_name") when post_type=post
    'offset' => '0',

    // Optional overrides (when you want to control parts via the tag)
    // - When provided, these override post-derived fields.
    'link' => '',          // href override (defaults to post permalink)
    'image_id' => '',      // attachment ID override (preferred)
    'image_url' => '',     // direct URL override
    'chips' => '',         // comma-separated chip labels, e.g. "舒緩化療,代謝性疾病"
    'title' => '',         // title override
    'excerpt' => '',       // excerpt override

    // Visual / layout
    'x' => '0px',
    'y' => '0px',
    'lines' => '3', // excerpt line clamp
    'chips_tax' => 'category',
    'chips_limit' => '2',
    'class' => '',
  ], (array) $atts, 'cryo_card');

  $post = null;
  $post_id = (int) ($atts['post_id'] ?? 0);
  $slug = trim((string) ($atts['slug'] ?? ''));
  $post_type = trim((string) ($atts['post_type'] ?? 'post'));
  if ($post_type === '') $post_type = 'post';
  $category = trim((string) ($atts['category'] ?? ''));
  $offset = max(0, (int) ($atts['offset'] ?? 0));

  if ($post_id > 0) {
    $p = get_post($post_id);
    if ($p && $p->post_status === 'publish') {
      $post = $p;
    }
  } elseif ($slug !== '') {
    $q = new WP_Query([
      'post_type' => $post_type,
      'post_status' => 'publish',
      'name' => $slug,
      'posts_per_page' => 1,
      'no_found_rows' => true,
      'ignore_sticky_posts' => true,
    ]);
    if (!empty($q->posts[0])) {
      $post = $q->posts[0];
    }
    wp_reset_postdata();
  } else {
    $args = [
      'post_type' => $post_type,
      'post_status' => 'publish',
      'posts_per_page' => 1,
      'offset' => $offset,
      'no_found_rows' => true,
      'ignore_sticky_posts' => true,
    ];
    if ($category !== '' && $post_type === 'post') {
      $args['category_name'] = $category;
    }
    $q = new WP_Query($args);
    if (!empty($q->posts[0])) {
      $post = $q->posts[0];
    }
    wp_reset_postdata();
  }

  $class = trim((string) ($atts['class'] ?? ''));
  $x = trim((string) ($atts['x'] ?? '0px'));
  $y = trim((string) ($atts['y'] ?? '0px'));
  $lines = max(1, (int) ($atts['lines'] ?? 3));
  $chips_tax = trim((string) ($atts['chips_tax'] ?? 'category'));
  if ($chips_tax === '') $chips_tax = 'category';
  $chips_limit = max(0, (int) ($atts['chips_limit'] ?? 2));

  // Fallback content if the query did not find a post (keeps layout intact during migration).
  $title_override = trim((string) ($atts['title'] ?? ''));
  $excerpt_override = trim((string) ($atts['excerpt'] ?? ''));
  $chips_override = trim((string) ($atts['chips'] ?? ''));
  $link_override = trim((string) ($atts['link'] ?? ''));
  $image_id_override = (int) ($atts['image_id'] ?? 0);
  $image_url_override = trim((string) ($atts['image_url'] ?? ''));

  $title = $title_override !== '' ? $title_override : ($post ? (string) get_the_title($post) : '保存臍帶血');
  $permalink = '#';
  if ($link_override !== '') {
    $permalink = $link_override;
  } elseif ($post) {
    $permalink = (string) get_permalink($post);
  }

  $excerpt = '';
  if ($excerpt_override !== '') {
    $excerpt = $excerpt_override;
  } elseif ($post) {
    $excerpt = trim((string) get_the_excerpt($post));
    if ($excerpt === '') {
      $excerpt = trim((string) wp_strip_all_tags((string) $post->post_content));
    }
  }
  if ($excerpt === '') {
    $excerpt = '臍帶血的幹細胞有著修復及替換因疾病、化療或其他醫療條件而受損的血細胞，並能夠有效治療血液、免疫、代謝性疾病，為孩子未來健康增添保障。';
  }
  // Keep the excerpt as text; line-clamp will do the visual truncation.
  $excerpt = wp_trim_words(wp_strip_all_tags($excerpt), 60, '…');

  // Chips from taxonomy terms (default: category).
  $chips_html = '';
  if ($chips_override !== '' && $chips_limit > 0) {
    $raw = array_filter(array_map('trim', preg_split('/[，,]/u', $chips_override) ?: []));
    $raw = array_slice($raw, 0, $chips_limit);
    if (!empty($raw)) {
      $chips = [];
      foreach ($raw as $label) {
        $chips[] = '<span class="cryo-card__chip">' . esc_html($label) . '</span>';
      }
      $chips_html = '<div class="cryo-card__chips" aria-label="標籤">' . implode('', $chips) . '</div>';
    }
  } elseif ($post && $chips_limit > 0) {
    $terms = get_the_terms($post, $chips_tax);
    if (!is_wp_error($terms) && !empty($terms)) {
      $terms = array_slice(array_values($terms), 0, $chips_limit);
      $chips = [];
      foreach ($terms as $t) {
        $chips[] = '<span class="cryo-card__chip">' . esc_html((string) ($t->name ?? '')) . '</span>';
      }
      if (!empty($chips)) {
        $chips_html = '<div class="cryo-card__chips" aria-label="標籤">' . implode('', $chips) . '</div>';
      }
    }
  }

  // Image
  // Uncode2 convention (observed in `uncode_bak20171215/content-portfolio.php`):
  // - prefers `_uncode_featured_media` (can be a comma-separated list)
  // - falls back to the normal featured image (post thumbnail)
  $img_html = '';

  // 1) Tag override (best for editor-controlled cover images)
  if ($image_id_override > 0 && wp_attachment_is_image($image_id_override)) {
    $src = wp_get_attachment_image_url($image_id_override, 'large');
    $alt = (string) get_post_meta($image_id_override, '_wp_attachment_image_alt', true);
    if ($alt === '') $alt = $title;
    if ($src) {
      $img_html = '<img class="cryo-card__img" src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '" loading="lazy" decoding="async" />';
    }
  } elseif ($image_url_override !== '') {
    // Allow direct URL override (useful during migration/testing)
    $img_html = '<img class="cryo-card__img" src="' . esc_url($image_url_override) . '" alt="' . esc_attr($title) . '" loading="lazy" decoding="async" />';
  }

  // 2) Post-derived media (Uncode2 + WP featured image)
  if ($img_html === '' && $post) {
    $thumb_id = 0;

    $uncode_featured = trim((string) get_post_meta($post->ID, '_uncode_featured_media', true));
    if ($uncode_featured !== '') {
      // `_uncode_featured_media` is often "123" or "123,456". Pick the first usable image.
      $ids = array_filter(array_map('trim', explode(',', $uncode_featured)));
      foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0 && wp_attachment_is_image($id)) {
          $thumb_id = $id;
          break;
        }
      }
      // If the first ID isn't an image, keep falling back to WP featured image.
    }

    if ($thumb_id <= 0) {
      $thumb_id = (int) get_post_thumbnail_id($post);
    }
    if ($thumb_id <= 0) {
      // Extra compatibility: some importers store `_thumbnail_id` explicitly.
      $thumb_id = (int) get_post_meta($post->ID, '_thumbnail_id', true);
    }

    if ($thumb_id > 0 && wp_attachment_is_image($thumb_id)) {
      $src = wp_get_attachment_image_url($thumb_id, 'large');
      $alt = (string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true);
      if ($alt === '') $alt = $title;
      if ($src) {
        $img_html = '<img class="cryo-card__img" src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '" loading="lazy" decoding="async" />';
      }
    }
  }
  if ($img_html === '') {
    // Use a CSS-driven placeholder so the card still looks intentional without media.
    $img_html = '<div class="cryo-card__img cryo-card__img--placeholder" role="img" aria-label="' . esc_attr($title) . '"></div>';
  }

  $aria = '查看 ' . $title;

  // Single clickable target (entire card). Avoid nested links for valid HTML and predictable click behavior.
  return
    '<div class="cryo-cardPos' . ($class !== '' ? ' ' . esc_attr($class) : '') . '" style="--cryo-card-x:' . esc_attr($x) . ';--cryo-card-y:' . esc_attr($y) . ';--cryo-card-lines:' . esc_attr((string) $lines) . ';">'
      . '<a class="cryo-card" href="' . esc_url($permalink) . '" aria-label="' . esc_attr($aria) . '">'
        . '<div class="cryo-card__media">'
          . $img_html
        . '</div>'
        . '<div class="cryo-card__content">'
          . $chips_html
          . '<h3 class="cryo-card__heading">' . esc_html($title) . '</h3>'
          . '<p class="cryo-card__excerpt">' . esc_html($excerpt) . '</p>'
          . '<span class="cryo-card__cta" aria-hidden="true"></span>'
        . '</div>'
      . '</a>'
    . '</div>';
});


/**
 * Cryo Tech Carousel — horizontal scrolling wrapper for tech advantage cards
 *
 * Design reference:
 * - `screens/figma/cryolife/desktop/臍帶血保存.png` (技術優勢 section)
 *
 * Usage:
 * - [cryo_tech_carousel title="技術優勢"]
 *     [cryo_tech_card post_id="123" /]
 *     [cryo_tech_card image_id="456" title="國際標準品管評測" excerpt="..." /]
 *   [/cryo_tech_carousel]
 */
add_shortcode('cryo_tech_carousel', function ($atts, $content = ''): string {
  $atts = shortcode_atts([
    'title' => '',
    'class' => '',
  ], (array) $atts, 'cryo_tech_carousel');

  $title = trim((string) ($atts['title'] ?? ''));
  $class = trim((string) ($atts['class'] ?? ''));

  $cards_html = '';
  $raw_content = is_string($content) ? $content : '';
  if (trim($raw_content) !== '') {
    // Process shortcodes
    $cards_html = do_shortcode($raw_content);
    // Remove <br> and <p> tags added by wpautop()
    $cards_html = preg_replace('/<br\s*\/?>/i', '', $cards_html);
    $cards_html = preg_replace('/<\/?p[^>]*>/i', '', $cards_html);
    // Remove whitespace between closing and opening div/a tags (cards)
    $cards_html = preg_replace('/>\s+</', '><', $cards_html);
    $cards_html = trim($cards_html);
  }

  $header_html = '';
  if ($title !== '') {
    $header_html = '<div class="cryo-techCarousel__header">'
      . '<h2 class="cryo-techCarousel__title">' . esc_html($title) . '</h2>'
      . '</div>';
  }

  // Navigation arrows
  $nav_html = '<div class="cryo-techCarousel__nav" aria-label="導覽">'
    . '<button class="cryo-techCarousel__arrow cryo-techCarousel__arrow--prev" type="button" aria-label="上一頁" data-cryo-tech-prev>'
    . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>'
    . '</button>'
    . '<button class="cryo-techCarousel__arrow cryo-techCarousel__arrow--next" type="button" aria-label="下一頁" data-cryo-tech-next>'
    . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>'
    . '</button>'
    . '</div>';

  return
    '<section class="cryo-techCarouselSection' . ($class !== '' ? ' ' . esc_attr($class) : '') . '">'
    . '<div class="cryo-techCarousel" data-cryo-tech-carousel>'
    . $header_html
    . '<div class="cryo-techCarousel__track">'
    . $cards_html
    . '</div>'
    . $nav_html
    . '</div>'
    . '</section>';
});

/**
 * Cryo Tech Card — feature card for technical advantages carousel
 *
 * Design reference:
 * - `screens/figma/cryolife/desktop/臍帶血保存.png` (技術優勢 section cards)
 *
 * Usage:
 * - [cryo_tech_card post_id="123" /]  (loads from post)
 * - [cryo_tech_card image_id="456" title="..." excerpt="..." /]  (manual)
 * - [cryo_tech_card image_id="456" title="..." excerpt="..." link="/path/" /]  (with link)
 */
add_shortcode('cryo_tech_card', function ($atts): string {
  $atts = shortcode_atts([
    // Post selection (optional)
    'post_id' => '',
    // Manual overrides
    'image_id' => '',
    'image_url' => '',
    'title' => '',
    'excerpt' => '',
    'link' => '',
    'class' => '',
  ], (array) $atts, 'cryo_tech_card');

  $post_id = (int) ($atts['post_id'] ?? 0);
  $image_id = (int) ($atts['image_id'] ?? 0);
  $image_url = trim((string) ($atts['image_url'] ?? ''));
  $title = trim((string) ($atts['title'] ?? ''));
  $excerpt = trim((string) ($atts['excerpt'] ?? ''));
  $link = trim((string) ($atts['link'] ?? ''));
  $class = trim((string) ($atts['class'] ?? ''));

  // If post_id provided, get data from post
  if ($post_id > 0) {
    $post = get_post($post_id);
    if ($post) {
      if ($title === '') {
        $title = (string) get_the_title($post);
      }
      if ($excerpt === '') {
        $excerpt = (string) get_the_excerpt($post);
        if ($excerpt === '') {
          $excerpt = wp_trim_words(strip_shortcodes($post->post_content), 30, '...');
        }
      }
      if ($link === '') {
        $link = (string) get_permalink($post);
      }
      if ($image_id <= 0) {
        $image_id = (int) get_post_thumbnail_id($post);
        // Fallback: Uncode2 featured media
        if ($image_id <= 0) {
          $uncode_featured = trim((string) get_post_meta($post->ID, '_uncode_featured_media', true));
          if ($uncode_featured !== '' && is_numeric($uncode_featured)) {
            $image_id = (int) $uncode_featured;
          }
        }
      }
    }
  }

  // Generate image HTML
  $img_html = '';
  if ($image_id > 0) {
    $img_html = wp_get_attachment_image($image_id, 'large', false, [
      'class' => 'cryo-techCard__img',
      'loading' => 'lazy',
    ]);
  } elseif ($image_url !== '') {
    $img_html = '<img class="cryo-techCard__img" src="' . esc_url($image_url) . '" alt="' . esc_attr($title) . '" loading="lazy" />';
  } else {
    // Placeholder
    $img_html = '<div class="cryo-techCard__img cryo-techCard__img--placeholder"></div>';
  }

  // Build card HTML
  $card_inner = '<div class="cryo-techCard__media">' . $img_html . '</div>'
    . '<div class="cryo-techCard__content">'
    . '<h3 class="cryo-techCard__title">' . esc_html($title) . '</h3>'
    . ($excerpt !== '' ? '<p class="cryo-techCard__desc">' . esc_html($excerpt) . '</p>' : '')
    . '</div>';

  // Wrap in link if provided
  if ($link !== '') {
    return '<a class="cryo-techCard' . ($class !== '' ? ' ' . esc_attr($class) : '') . '" href="' . esc_url($link) . '">'
      . $card_inner
      . '</a>';
  }

  return '<div class="cryo-techCard' . ($class !== '' ? ' ' . esc_attr($class) : '') . '">'
    . $card_inner
    . '</div>';
});

/**
 * Cryo History Carousel — two-column carousel (image left, content right)
 *
 * Design reference:
 * - About Us page: 我們的過去、現在和未來 section
 *
 * Usage:
 * - [cryo_history_carousel title="我們的過去、現在和未來"]
 *     [cryo_history_slide image_id="123" heading="標題" content="內容..." /]
 *     [cryo_history_slide image_id="456" heading="標題2" content="內容2..." /]
 *   [/cryo_history_carousel]
 *
 * Parameters:
 * - title: Section title (optional)
 */
add_shortcode('cryo_history_carousel', function ($atts, $content = ''): string {
  $atts = shortcode_atts([
    'title' => '我們的過去、現在和未來',
  ], (array) $atts, 'cryo_history_carousel');

  $title = trim((string) ($atts['title'] ?? ''));

  // Process child shortcodes
  $slides_html = '';
  $raw_content = is_string($content) ? $content : '';
  if (trim($raw_content) !== '') {
    $slides_html = do_shortcode($raw_content);
    // Clean up wpautop artifacts
    $slides_html = preg_replace('/<br\s*\/?>/i', '', $slides_html);
    $slides_html = preg_replace('/<\/?p[^>]*>/i', '', $slides_html);
    $slides_html = preg_replace('/>\s+</', '><', $slides_html);
    $slides_html = trim($slides_html);
  }

  // If no slides, show placeholder
  if ($slides_html === '' || !str_contains($slides_html, 'data-cryo-history-slide')) {
    $slides_html = '<div class="cryo-history__slide cryo-history__slide--active" data-cryo-history-slide="0">'
      . '<div class="cryo-history__media">'
      . '<img class="cryo-history__img" src="https://placehold.co/600x500/E8E4DE/666?text=Placeholder" alt="歷史照片" loading="lazy" />'
      . '</div>'
      . '<div class="cryo-history__panel">'
      . '<div class="cryo-history__content">'
      . '<h3 class="cryo-history__heading">標題</h3>'
      . '<p class="cryo-history__desc">內容描述...</p>'
      . '</div>'
      . '<div class="cryo-history__nav">'
      . '<button class="cryo-history__arrow cryo-history__arrow--prev" type="button" aria-label="上一張" data-cryo-history-prev></button>'
      . '<button class="cryo-history__arrow cryo-history__arrow--next" type="button" aria-label="下一張" data-cryo-history-next></button>'
      . '</div>'
      . '</div>'
      . '</div>';
  }

  // Ensure first slide is active
  if (!str_contains($slides_html, 'cryo-history__slide--active')) {
    $slides_html = preg_replace('/class="cryo-history__slide\b/', 'class="cryo-history__slide cryo-history__slide--active', $slides_html, 1) ?? $slides_html;
  }

  // Build section HTML
  $title_html = $title !== '' ? '<h2 class="cryo-history__title">' . esc_html($title) . '</h2>' : '';

  return '<section class="cryo-historySection" aria-label="' . esc_attr($title) . '">'
    . '<div class="cryo-history">'
    . $title_html
    . '<div class="cryo-history__carousel" data-cryo-history-carousel>'
    . $slides_html
    . '</div>'
    . '</div>'
    . '</section>';
});

/**
 * Cryo History Slide — individual slide for history carousel
 *
 * Usage:
 * - [cryo_history_slide image_id="123" heading="標題" content="內容..." /]
 *
 * Parameters:
 * - image_id: Attachment ID for the left-side image
 * - heading: Slide heading (orange title)
 * - content: Slide description text
 */
add_shortcode('cryo_history_slide', function ($atts): string {
  static $slide_index = 0;

  $atts = shortcode_atts([
    'image_id' => '',
    'heading' => '',
    'content' => '',
  ], (array) $atts, 'cryo_history_slide');

  $image_id = (int) ($atts['image_id'] ?? 0);
  $heading = trim((string) ($atts['heading'] ?? ''));
  $content = trim((string) ($atts['content'] ?? ''));

  // Get image
  $img_html = '';
  if ($image_id > 0) {
    $img_html = wp_get_attachment_image($image_id, 'large', false, [
      'class' => 'cryo-history__img',
      'loading' => 'lazy',
    ]);
  }
  if ($img_html === '') {
    $img_html = '<img class="cryo-history__img" src="https://placehold.co/600x500/E8E4DE/666?text=Placeholder" alt="' . esc_attr($heading) . '" loading="lazy" />';
  }

  $index = $slide_index++;

  return '<div class="cryo-history__slide" data-cryo-history-slide="' . $index . '">'
    . '<div class="cryo-history__media">' . $img_html . '</div>'
    . '<div class="cryo-history__panel">'
    . '<div class="cryo-history__content">'
    . '<h3 class="cryo-history__heading">' . esc_html($heading) . '</h3>'
    . '<p class="cryo-history__desc">' . esc_html($content) . '</p>'
    . '</div>'
    . '<div class="cryo-history__nav">'
    . '<button class="cryo-history__arrow cryo-history__arrow--prev" type="button" aria-label="上一張" data-cryo-history-prev></button>'
    . '<button class="cryo-history__arrow cryo-history__arrow--next" type="button" aria-label="下一張" data-cryo-history-next></button>'
    . '</div>'
    . '</div>'
    . '</div>';
});

