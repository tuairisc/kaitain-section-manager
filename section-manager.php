<?php 

/**
* Site Sections
* -----------------------------------------------------------------------------
 * @category   PHP Script
 * @package    Section Manager
 * @author     Mark Grealish <mark@bhalash.com>
 * @copyright  Copyright (c) 2014-2015, Mark Grealish
 * @license    https://www.gnu.org/copyleft/gpl.html The GNU GPL v3.0
 * @version    2.0
 * @link       https://github.com/bhalash/section-manager
 *
 * This file is part of Section Manager.
 * 
 * Section Manager is free software: you can redistribute it and/or modify it 
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version.
 * 
 * Section Manager is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
 * details.
 * 
 * You should have received a copy of the GNU General Public License along with 
 * Section Manager. If not, see <http://www.gnu.org/licenses/>.
 */ 

class Section_Manager {
    static $instantiated = false;

    // Current site section.
    public static $section;

    private static $keys = array(
        // Keys for saved options.
        'sections' => 'section_manager_sections',
        'menus' => 'section_manager_menus'
    );

    private static $errors = array(
        'instance' => 'Error: Section Manager can only be instantiated once.',
        'empty' => 'Error: An array of categories must be passed to Section Manager.',
        'not_category' => '"%s" is not a valid category and will be skipped',
        'is_child' => '"%s" is a child category and will be skipped'
    );

    public static $sections = array();
    public static $menus = array();

    public function __construct($categories) {
        if (self::$instantiated) {
            // More than one running instance can lead to strange things.
            throw new Exception(self::$errors['instance']);
        }
        
        self::$instantiated = true;

        if (!is_array($categories) || empty($categories)) {
            throw new Exception(self::$errors['empty']);
        }

        $sections = get_option(self::$keys['sections']);

        if ($categories['categories'] !== $sections['id'] 
        || $categories['home'] !== $sections['home'] || WP_DEBUG) {
            // Update generated options and menu if sctions have changed.
            $this->option_setup($categories);
            $this->menu_setup();
        }

        // Public variables for menu and section attributes.
        self::$sections = get_option(self::$keys['sections'])['section'];
        self::$menus = get_option(self::$keys['menus']);

        add_action('wp_head', array($this, 'set_current_section'));
        add_filter('body_class', array($this, 'set_section_body_class'));
    }

    /**
     * Determine Current Section
     * -------------------------------------------------------------------------
     * The site is segmented into n primary sections. This generates the
     * WordPress options for the site sections if the array of sections changes.
     * 
     * @param   array       $categories         Structured array of categories.
     * @return  array       $sections           Parsed array of sections.
     */

    private function option_setup($categories = null) {
        $sections = array(
            'id' => array(),
            'section' => array()
        );

        foreach ($categories['categories'] as $cat) {
            /* Passed int must resolve to a site category.
             * Category must be a top-level parent category. */
            $category = get_category($cat);

            if (!$category) {
                trigger_error(
                    sprintf(self::$errors['not_category'], $cat),
                    E_USER_WARNING
                );

                continue;
            }

            if ($category->category_parent) {
                trigger_error(sprintf(
                    self::$errors['is_child'], $cat),
                    E_USER_WARNING
                );

                continue;
            }

            $sections['id'][] = $category->cat_ID;
            $sections['section'][$category->cat_ID] = $category->slug;
        }

        if (isset($categories['home']) && get_category($categories['home'])) {
            // If home is set, use it, otherwise, pick first passed category.
            $home_id = $categories['home'];
            $home_slug = $this->get_section_slug($categories['home']);
        } else {
            $home_id = $sections['id'][0];
            $home_slug = $this->get_section_slug($sections['id'][0]);
        }

        $sections['home']['id'] = $home_id;
        $sections['home']['slug'] = $home_slug;

        update_option(self::$keys['sections'], $sections);
        return $sections;
    }

    /**
     * Determine Current Section on Page Load
     * -------------------------------------------------------------------------
     * Get the ID of the section of the site. The site is broken into sections 
     * defined by category-one each for major categories, with a final fallback 
     * section for everything else.
     * 
     * Explicit flag for 404: Modern browsers try several fallback methods if 
     * an asset fails to fetch, or so it appears to me. This can cause them to
     * spam and cause wp_head to trigger muptiple times.
     */

    public function set_current_section() {
        global $post;
        $sections =& get_option(self::$keys['sections']);

        /* Temporary (probably). The client has not decided how to handle
         * non-section parts of the site, so it is all home for now. */
        $current_id = $sections['home']['id'];

        if (!isset(self::$section) || !self::$section && !is_404()) {
            if (is_home() || is_front_page()) {
                // 1. If home page or main index, default to first item.
                $current_id = $sections['home']['id'];
            } else if (is_category() || is_single()) {

                // 2. Else set categroy by the parent category.
                if (is_category()) {
                    $category = get_query_var('cat');
                } else if (is_single()) {
                    $category = get_the_category()[0]->cat_ID;
                }

                $category = $this->category_parent_id($category);

                if ($this->is_section_category($category)) {
                    $current_id = $category;
                }
            } 

            // 3. Add more sections here if they need to be evaluated.
        }

        self::$section = array(
            'id' => $current_id,
            'slug' => $this->get_section_slug($current_id)
        );
    }

    /**
     * Is Current Category a Section?
     * -------------------------------------------------------------------------
     * Only matches top-level categories, as it is used in circumstances where 
     * parent has been already determined. Matches category ID against stored
     * section IDs.
     * 
     * @param   object/id       $category       Category ID or object.
     * @return  bool                            Category is section, true/false.
     */

    private function is_section_category($category) {
        $category = get_category($category);
        $key =& self::$keys['sections'];

        if (!$category) {
            return false;
        }
        
        return (in_array($category->cat_ID, get_option($key)['id']));
    }

    /**
     * Get ID of Category's Ultimate Parent
     * -------------------------------------------------------------------------
     * The site is sectioned into several parent categories with children and 
     * grandchildren beneath. Recursively ascend through parent categories
     * until you hit the top.
     * 
     * @param   int     $cat_id          Category ID
     * @param   int     $cat_id          ID of original category's parent.
     */

    private function category_parent_id($cat_id = null) {
        $category = get_category($cat_id);

        if ($category && $category->category_parent) {
            $cat_id = $this->category_parent_id($category->category_parent);
        }

        return $cat_id;
    }

    /**
     * Get Category Children IDs
     * -------------------------------------------------------------------------
     * Return array of all category childen categories.
     *
     * @param   int/object      $category      The category.
     * @return  array           $children      Array of category children IDs.
     */

    private function category_children($category) {
        $category = get_category($category);
        $children = array();

        $categories = get_categories(array(
            'child_of' => $category->cat_ID)
        );

        if ($categories) {
            foreach($categories as $cat) {
                $children[] = $cat->cat_ID;
            }
        }

        return $children;
    }

    /**
     * Get Section Slug
     * -------------------------------------------------------------------------
     * @param   object/id       $category       Category ID or object.
     * @return  string          $slog           Slug for current section.
     */

    public function get_section_slug($category) {
        $slug = 'other';
        $category = get_category($category); 

        if ($category) {
            $category = $this->category_parent_id($category->cat_ID);
        }

        /* Since '999' doesn't exist as a section, going to a tag or search
         * would cause a warning to be thrown since it comes up '999'. */

        if ($category && array_key_exists($category, self::$sections)) {
            $option = get_option(self::$keys['sections'])['section'][$category];

            if ($option) {
                $slug = $option;
            }
        }

        return $slug;
    }

    /**
     * Set Body Classes
     * -------------------------------------------------------------------------
     * Attacch current section to body_class. I think it's a bit mad like that 
     * WordPress doesn't have an action for body_id.
     e
     * @param   array       $classes        Array of body classes.
     * @return  array       $classes        Array of body classes.
     */

    public function set_section_body_class($classes) {
        $section_class = array();

        $section_class[] = 'section-';
        $section_class[] = self::$section['slug'];
        $classes[] = implode('', $section_class);

        return $classes;
    }

    /**
     * Setup Section Menus
     * -------------------------------------------------------------------------
     * Save generated sections menu to site options. Sections aren't expected
     * to change on a frequent basis, so run one, save, and only call again is
     * sections have changed.
     */

    private function menu_setup() {
        $sections = get_option(self::$keys['sections']);
        $menu = array();

        foreach ($sections['section'] as $id => $slug) {
            $menu['primary'][$slug] = $this->generate_menu_item($id);
            
            foreach ($this->category_children($id) as $child) {
                $menu['secondary'][$slug][] = $this->generate_menu_item($child);
            }
        }

        update_option(self::$keys['menus'], $menu);
        return $menu;
    }

    /**
     * Generate HTML Category List Item
     * -------------------------------------------------------------------------
     * Generate the actual HTML for the menu item based on the passed category.
     * 
     * @param   object/int  $category       Category which needs a link.
     * @return  string  $ln                 Generated menu item.
     */

    private function generate_menu_item($category) {
        $category = get_category($category);

        if (!$category) {
            return;
        }
        
        // Handled through an array because it is stored and retrived later.
        $item = array();

        $item[] = '<li%s>';
        $item[] = $this->generate_cat_link($category);
        $item[] = '</li>';

        return implode('', $item);
    }

    /*
     * Output Section Menu
     * -------------------------------------------------------------------------
     * @param   string      $menu_type          Type of menu.
     * @param   array       $classes            Menu item classes.
     */

    public function sections_menu($menu_type = 'primary', $classes = array()) {
        $section =& self::$section;

        // Get menu from saved menu, and reduce secondary if called.
        $menu = get_option(self::$keys['menus'])[$menu_type];

        if ($menu_type === 'secondary') {
            $menu = $menu[$section['slug']];
        }

        if (!empty($menu)) {
            foreach ($menu as $key => $item) {
                $menu_class = $classes;
                $class_attr = '';

                if ($menu_type === 'primary') {
                    /* Primary menu items have hover and section classes.
                     * Uncurrent-section only show section colour on hover. */
                    $uncurrent = '-hover';

                    if ($key === $section['slug']) {
                        $uncurrent = '';
                        $menu_class[] = 'current-section-menu-item';
                    }
                        
                    $menu_class[] = sprintf('section-%s-background%s', 
                        $key,
                        $uncurrent
                    );
                }

                if ($menu_type === 'secondary') {
                    $menu_class[] = sprintf('section-%s-text-hover', $key);
                }
                
                $menu_class = implode(' ', $menu_class);
                $menu_class = $this->item_class_attribute($menu_class);

                // Put it all together and output.
                printf($item, $menu_class);
            }
        }
    }

    /*
     * Section Cavalcade
     * -------------------------------------------------------------------------
     * Generate a cavalcade list of sections and child categories output as 
     * 
     * container
     *      ul
     *         section
     *         child
     *         child
     *         child
     *
     * @param   array   $args      Arguments for menu output (type and classes).
     */

    public function section_cavalcade($args = array()) {
        $count = 1;

        $defaults = array(
            'sections_per_column' => 3,
            'wrap_container' => true,
            'container_type' => 'nav',
            'container_class' => 'footer-site-sections',
            'menu_class' => 'footer-section-menu',
            'menu_item_class' => 'footer-section-item',
            'anchor_class' => 'footer-section-link'
        );

        $args = wp_parse_args($defaults, $args);

        $last_section = end(self::$sections);

        foreach (self::$sections as $id => $slug) {
            $parent = get_category($id);

            $children = get_categories(array(
                'child_of' => $id
            ));

            if ($args['wrap_container']) {
                if ($count % $args['sections_per_column'] === 1) {
                    printf('<%s%s>',
                        $args['container_type'],
                        $this->item_class_attribute($args['container_class'])
                    );
                }
            }

            printf('<ul%s id="section-footer-menu-%s">', 
                $this->item_class_attribute($args['menu_class']),
                $slug
            );

            printf('<li%s>%s</li>',
                $this->item_class_attribute($args['menu_item_class']),
                $this->generate_cat_link($parent, $args['anchor_class'])
            );

            if ($children) {
                // Some sections may not have children categories.
                foreach ($children as $child) {
                    printf('<li%s>%s</li>',
                        $this->item_class_attribute($args['menu_item_class']),
                        $this->generate_cat_link($child)
                    );
                }
            }

            printf('</ul>');

            if ($args['wrap_container']) {
                if ($count > 0 && $count % $args['sections_per_column'] === 0 || $slug === $last_section) {
                    printf('</%s>', $args['container_type']);
                }
            }

            $count++;
        }
    }

    /**
     * Add Class Attribute
     * -------------------------------------------------------------------------
     * Returns ' class="$foo_class"' if $class isn't empty.
     *
     * @param   string    $class       class attribute.
     * @return  string    $class       class attribute html. 
     */

    private function item_class_attribute($class = null) {
        return sprintf($class ? ' class="%s"' : '%s', $class); 
    }

    /**
     * Generate Category Link Text
     * -------------------------------------------------------------------------
     * 
     * @param   int/object      $category       Category that needs to be linked.
     * @param   string/array    $class          Class(es) to be added to link.
     */

    private function generate_cat_link($category, $class = null) {
        $link = '<a%s title="%s" href="%s">%s</a>';
        $category = get_category($category);

        if (!$category)  {
            return false;
        }

        if (is_array($class)) {
            $class = implode(' ', $class);
        }
        
        $class = $this->item_class_attribute($class);
        $title = esc_attr($category->cat_name);
        $href = esc_url(get_category_link($category->cat_ID));
        $text = $category->cat_name;

        return sprintf($link, $class, $title, $href, $text);
    }
}

?>
